// Package postprocess ports app/Analysis/PostProcessor.php: the 7 post-crawl SQL
// steps. The heavy lifting is PostgreSQL (same queries as PHP); Go only drives
// them and does the union-find / chain building that PHP did in-process.
package postprocess

import (
	"context"
	"fmt"

	"scouter-crawler/internal/db"
)

// Runner executes the post-processing pipeline for one crawl.
type Runner struct {
	pool    *db.Pool
	crawlID int
	logf    func(string, ...any)

	// SitemapFetch, if set, fetches the new in-scope sitemap-only URLs through a
	// skip-link-extraction crawl pass (wired by the worker to avoid an import cycle).
	SitemapFetch func(ctx context.Context, urls, domains []string) error
}

func New(pool *db.Pool, crawlID int, logf func(string, ...any)) *Runner {
	if logf == nil {
		logf = func(string, ...any) {}
	}
	return &Runner{pool: pool, crawlID: crawlID, logf: logf}
}

// Run executes all steps under an advisory lock with statement_timeout disabled,
// isolating each step so one failure never fails the whole crawl.
func (r *Runner) Run(ctx context.Context) error {
	lockID := r.crawlID + 200000
	var acquired bool
	if err := r.pool.QueryRow(ctx, "SELECT pg_try_advisory_lock($1)", lockID).Scan(&acquired); err != nil {
		return err
	}
	if !acquired {
		r.logf("post-processing skipped (another process holds the lock)")
		return nil
	}
	defer r.pool.Exec(ctx, "SELECT pg_advisory_unlock($1)", lockID)

	_, _ = r.pool.Exec(ctx, "SET statement_timeout = '0'")
	defer r.pool.Exec(ctx, "SET statement_timeout = '120s'")

	steps := []struct {
		name string
		fn   func(context.Context) error
	}{
		{"inlinks", r.calculateInlinks},
		{"pagerank", r.calculatePagerank},
		{"semantic", r.semanticAnalysis},
		{"categorize", r.categorize},
		{"duplicate", r.duplicateAnalysis},
		{"redirect-chains", r.redirectChainAnalysis},
		{"sitemap", r.sitemapAnalysis},
	}
	for _, s := range steps {
		if r.interrupted(ctx) {
			r.logf("post-processing interrupted (crawl failed)")
			break
		}
		if err := s.fn(ctx); err != nil {
			r.logf("post-processing error in %s: %v", s.name, err)
		} else {
			r.logf("post-processing %s done", s.name)
		}
	}
	return nil
}

// interrupted mirrors PostProcessor::isCrawlInterrupted (only 'failed' aborts).
func (r *Runner) interrupted(ctx context.Context) bool {
	var status string
	if err := r.pool.QueryRow(ctx, "SELECT status FROM crawls WHERE id=$1", r.crawlID).Scan(&status); err != nil {
		return false
	}
	return status == "failed"
}

func (r *Runner) calculateInlinks(ctx context.Context) error {
	_, err := r.pool.Exec(ctx, `
		UPDATE pages p SET inlinks = COALESCE(sub.cnt, 0)
		FROM (
			SELECT p2.id, lc.cnt
			FROM pages p2
			LEFT JOIN (
				SELECT target, COUNT(*) AS cnt FROM links WHERE crawl_id = $1 GROUP BY target
			) lc ON p2.id = lc.target
			WHERE p2.crawl_id = $1 AND p2.in_crawl = TRUE
		) sub
		WHERE p.crawl_id = $1 AND p.id = sub.id AND p.in_crawl = TRUE`, r.crawlID)
	return err
}

func (r *Runner) semanticAnalysis(ctx context.Context) error {
	_, err := r.pool.Exec(ctx, `
		UPDATE pages p SET
			title_status = s.title_st, h1_status = s.h1_st, metadesc_status = s.metadesc_st
		FROM (
			SELECT id,
				CASE WHEN title IS NULL OR title = '' THEN 'empty'
				     WHEN COUNT(*) OVER (PARTITION BY title) > 1 THEN 'duplicate' ELSE 'unique' END AS title_st,
				CASE WHEN h1 IS NULL OR h1 = '' THEN 'empty'
				     WHEN COUNT(*) OVER (PARTITION BY h1) > 1 THEN 'duplicate' ELSE 'unique' END AS h1_st,
				CASE WHEN metadesc IS NULL OR metadesc = '' THEN 'empty'
				     WHEN COUNT(*) OVER (PARTITION BY metadesc) > 1 THEN 'duplicate' ELSE 'unique' END AS metadesc_st
			FROM pages
			WHERE crawl_id = $1 AND compliant = true AND in_crawl = TRUE
		) s
		WHERE p.crawl_id = $1 AND p.id = s.id AND p.in_crawl = TRUE`, r.crawlID)
	return err
}

// calculatePagerank ports PostProcessor::calculatePagerank: 30 SQL iterations over
// a TEMP TABLE, damping 0.85, dead-end redistribution, nofollow clause.
func (r *Runner) calculatePagerank(ctx context.Context) error {
	const iterations, damping = 30, 0.85

	respectNofollow := r.pagerankRespectNofollow(ctx)
	nofollowClause := ""
	if respectNofollow {
		nofollowClause = " AND l.nofollow = false"
	}

	conn, err := r.pool.Acquire(ctx)
	if err != nil {
		return err
	}
	defer conn.Release()

	var pagesCount int
	if err := conn.QueryRow(ctx, "SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND in_crawl=TRUE", r.crawlID).Scan(&pagesCount); err != nil {
		return err
	}
	if pagesCount == 0 {
		return nil
	}
	var hasLinks bool
	if err := conn.QueryRow(ctx, "SELECT EXISTS(SELECT 1 FROM links WHERE crawl_id=$1)", r.crawlID).Scan(&hasLinks); err != nil {
		return err
	}
	if !hasLinks {
		return nil
	}

	initPR := 1.0 / float64(pagesCount)
	bonus := (1 - damping) / float64(pagesCount)

	if _, err := conn.Exec(ctx, "SET work_mem = '128MB'"); err != nil {
		return err
	}
	_, _ = conn.Exec(ctx, "DROP TABLE IF EXISTS tmp_pr")
	if _, err := conn.Exec(ctx, `CREATE TEMP TABLE tmp_pr (id char(8) PRIMARY KEY, pr float8 NOT NULL, outlinks int NOT NULL DEFAULT 0)`); err != nil {
		return err
	}
	if _, err := conn.Exec(ctx, `
		INSERT INTO tmp_pr (id, pr, outlinks)
		SELECT p.id, $1, COALESCE(ol.cnt, 0)
		FROM pages p
		LEFT JOIN (SELECT src, COUNT(*) cnt FROM links WHERE crawl_id=$2 GROUP BY src) ol ON p.id = ol.src
		WHERE p.crawl_id=$2 AND p.in_crawl = TRUE`, initPR, r.crawlID); err != nil {
		return err
	}

	updateSQL := fmt.Sprintf(`
		UPDATE tmp_pr t SET pr = $1 + $2 * COALESCE(inc.incoming_pr, 0)
		FROM (
			SELECT t2.id, i.incoming_pr FROM tmp_pr t2
			LEFT JOIN (
				SELECT l.target, SUM(tp.pr / tp.outlinks) incoming_pr
				FROM links l JOIN tmp_pr tp ON tp.id = l.src AND tp.outlinks > 0
				WHERE l.crawl_id = $3%s GROUP BY l.target
			) i ON t2.id = i.target
		) inc WHERE t.id = inc.id`, nofollowClause)

	for i := 0; i < iterations; i++ {
		var deadEndPR float64
		if err := conn.QueryRow(ctx, "SELECT COALESCE(SUM(pr),0) FROM tmp_pr WHERE outlinks = 0").Scan(&deadEndPR); err != nil {
			return err
		}
		iterBonus := bonus + damping*deadEndPR/float64(pagesCount)
		if _, err := conn.Exec(ctx, updateSQL, iterBonus, damping, r.crawlID); err != nil {
			return err
		}
	}

	if _, err := conn.Exec(ctx, `
		UPDATE pages p SET pri = t.pr FROM tmp_pr t
		WHERE p.crawl_id=$1 AND p.id = t.id AND p.in_crawl = TRUE`, r.crawlID); err != nil {
		return err
	}
	_, _ = conn.Exec(ctx, "DROP TABLE IF EXISTS tmp_pr")
	return nil
}

func (r *Runner) pagerankRespectNofollow(ctx context.Context) bool {
	var raw []byte
	if err := r.pool.QueryRow(ctx, "SELECT config FROM crawls WHERE id=$1", r.crawlID).Scan(&raw); err != nil {
		return true // default
	}
	return advancedBool(raw, "respect_nofollow", true)
}
