// Package backfill migrates a crawl's data from PostgreSQL into ClickHouse:
// reads the PG pages/links/html/page_schemas partitions, bulk-inserts them into
// CH, recomputes the post-processing IN ClickHouse, then flips
// crawls.data_store='clickhouse' so the reports read CH. Idempotent (drops the
// crawl's CH partitions first).
package backfill

import (
	"bytes"
	"compress/flate"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"strconv"
	"strings"

	"scouter-crawler/internal/db"
	"scouter-crawler/internal/postprocess"
)

type Backfiller struct {
	pool *db.Pool
	ch   *db.CH
	logf func(string, ...any)
}

func New(pool *db.Pool, ch *db.CH, logf func(string, ...any)) *Backfiller {
	if logf == nil {
		logf = func(string, ...any) {}
	}
	return &Backfiller{pool: pool, ch: ch, logf: logf}
}

const batchSize = 2000

// All backfills every crawl that still has PG data and isn't yet on ClickHouse.
func (b *Backfiller) All(ctx context.Context) error {
	rows, err := b.pool.Query(ctx,
		`SELECT id FROM crawls WHERE COALESCE(data_store,'pg') <> 'clickhouse' ORDER BY id`)
	if err != nil {
		return err
	}
	var ids []int
	for rows.Next() {
		var id int
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return err
		}
		ids = append(ids, id)
	}
	rows.Close()
	b.logf("backfill: %d crawl(s) to migrate", len(ids))
	// Phase 1: pages/links/schemas + post-processing + flip data_store. This is
	// what makes the REPORTS work, so every crawl becomes "migrated" fast — the
	// heavy HTML (view-source only) is deferred to phase 2 so one huge crawl's
	// HTML doesn't block all the others.
	var done []int
	for _, id := range ids {
		if err := b.migrate(ctx, id, false); err != nil {
			b.logf("backfill crawl %d FAILED: %v", id, err)
			continue
		}
		done = append(done, id)
	}
	// Phase 2: backfill HTML for the migrated crawls (best-effort, non-blocking
	// for the reports which already read ClickHouse).
	b.logf("backfill: phase 2 — HTML for %d crawl(s)", len(done))
	for _, id := range done {
		if err := b.html(ctx, id); err != nil {
			b.logf("backfill crawl %d: html partial (%v)", id, err)
		}
	}
	return nil
}

// resumeWindow is how long a user-stopped crawl keeps its PostgreSQL data so it
// can still be resumed (the frontier — uncrawled pages rows — lives in PG). Past
// this window the stopped crawl's PG partitions become purgeable like any other.
const resumeWindow = "7 days"

// PurgePG drops the PostgreSQL crawl-data partitions for crawls already on
// ClickHouse, freeing disk. Re-verifies CH completeness before each destructive
// drop (never purges PG if CH is missing data). Stopped crawls newer than
// resumeWindow are kept so they can still be resumed. target = "all" or a crawl id.
func (b *Backfiller) PurgePG(ctx context.Context, target string) error {
	var ids []int
	if target == "all" {
		rows, err := b.pool.Query(ctx, `SELECT id FROM crawls
			WHERE data_store='clickhouse'
			  AND NOT (status='stopped' AND finished_at > now() - interval '`+resumeWindow+`')
			ORDER BY id`)
		if err != nil {
			return err
		}
		for rows.Next() {
			var id int
			if err := rows.Scan(&id); err != nil {
				rows.Close()
				return err
			}
			ids = append(ids, id)
		}
		rows.Close()
	} else {
		id, err := strconv.Atoi(target)
		if err != nil {
			return fmt.Errorf("invalid crawl id %q", target)
		}
		ids = []int{id}
	}

	b.logf("purge-pg: %d crawl(s) to purge", len(ids))
	for _, id := range ids {
		var store string
		_ = b.pool.QueryRow(ctx, "SELECT COALESCE(data_store,'pg') FROM crawls WHERE id=$1", id).Scan(&store)
		if store != "clickhouse" {
			b.logf("purge-pg crawl %d: skipped (data_store=%s, not migrated)", id, store)
			continue
		}
		// Keep a recently user-stopped crawl's PG data so it can still be resumed.
		var inResumeWindow bool
		_ = b.pool.QueryRow(ctx, `SELECT status='stopped' AND finished_at > now() - interval '`+resumeWindow+`'
			FROM crawls WHERE id=$1`, id).Scan(&inResumeWindow)
		if inResumeWindow {
			b.logf("purge-pg crawl %d: skipped (stopped < %s ago — kept for resume)", id, resumeWindow)
			continue
		}
		b.SyncStats(ctx, id) // refresh scorecard stats from CH (fixes crawls migrated before this)
		// Re-verify ClickHouse has the data before the destructive PG drop.
		var pgCount int
		_ = b.pool.QueryRow(ctx, "SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND crawled=true AND in_crawl=true", id).Scan(&pgCount)
		chStr, err := b.ch.QueryScalar(ctx, fmt.Sprintf("SELECT uniqExact(id) FROM %s.pages WHERE crawl_id=%d", b.ch.DB(), id))
		if err != nil {
			b.logf("purge-pg crawl %d: skipped (CH check failed: %v)", id, err)
			continue
		}
		chCount, _ := strconv.Atoi(strings.TrimSpace(chStr))
		if pgCount > 0 && chCount < pgCount*9/10 {
			b.logf("purge-pg crawl %d: SKIPPED — CH has %d pages vs %d in PG", id, chCount, pgCount)
			continue
		}
		if _, err := b.pool.Exec(ctx, "SELECT drop_crawl_partitions($1)", id); err != nil {
			b.logf("purge-pg crawl %d: drop failed: %v", id, err)
			continue
		}
		b.logf("purge-pg crawl %d: PostgreSQL data dropped (%d pages live in CH)", id, chCount)
	}
	b.logf("purge-pg: done")
	return nil
}

// SweepOrphans drops leftover per-crawl PostgreSQL partition tables
// (pages_<id>, links_<id>, html_<id>, …) whose crawl no longer has a row in
// `crawls`. These are reliquats of deleted crawls: the FK cascade emptied the
// rows but the partition tables (and their bloated indexes/TOAST) were never
// dropped, so they hold disk forever. Safe by definition — no crawl row means
// nothing to migrate and nothing to resume. Meant to run LAST, after migration
// and PurgePG, so a not-yet-migrated crawl is never swept.
func (b *Backfiller) SweepOrphans(ctx context.Context) error {
	rows, err := b.pool.Query(ctx, `
		SELECT DISTINCT (regexp_replace(c.relname,
			'^(pages|links|html|page_schemas|duplicate_clusters|redirect_chains)_', ''))::int AS cid
		FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
		WHERE n.nspname = 'public' AND c.relkind = 'r'
		  AND c.relname ~ '^(pages|links|html|page_schemas|duplicate_clusters|redirect_chains)_[0-9]+$'
		  AND NOT EXISTS (SELECT 1 FROM crawls cr WHERE cr.id = (regexp_replace(c.relname,
			'^(pages|links|html|page_schemas|duplicate_clusters|redirect_chains)_', ''))::int)
		ORDER BY cid`)
	if err != nil {
		return err
	}
	var ids []int
	for rows.Next() {
		var id int
		if err := rows.Scan(&id); err != nil {
			rows.Close()
			return err
		}
		ids = append(ids, id)
	}
	rows.Close()

	if len(ids) == 0 {
		b.logf("sweep-orphans: none")
		return nil
	}
	b.logf("sweep-orphans: %d orphan crawl(s) to drop (deleted crawls with leftover PG tables)", len(ids))
	dropped := 0
	for _, id := range ids {
		if _, err := b.pool.Exec(ctx, "SELECT drop_crawl_partitions($1)", id); err != nil {
			b.logf("sweep-orphans crawl %d: drop failed: %v", id, err)
			continue
		}
		dropped++
	}
	b.logf("sweep-orphans: done (%d/%d dropped)", dropped, len(ids))
	return nil
}

// Crawl backfills one crawl PG -> CH, including HTML (single/manual use).
func (b *Backfiller) Crawl(ctx context.Context, crawlID int) error {
	return b.migrate(ctx, crawlID, true)
}

// SyncStats writes back the duplicate/redirect SCORECARD stats on the crawls row
// from the ClickHouse derived tables. The CH post-processing computes
// duplicate_clusters/redirect_chains but (unlike the PG post-processor) doesn't
// touch crawls.* — so without this the reports' scorecards (e.g. "N clusters")
// read stale PG zeros while the lists show the real CH rows. Idempotent.
func (b *Backfiller) SyncStats(ctx context.Context, crawlID int) {
	d := b.ch.DB()
	cid := strconv.Itoa(crawlID)
	n := func(q string) int {
		s, _ := b.ch.QueryScalar(ctx, q)
		v, _ := strconv.Atoi(strings.TrimSpace(s))
		return v
	}
	clusters := n("SELECT count() FROM " + d + ".duplicate_clusters WHERE crawl_id=" + cid)
	dupPages := n("SELECT toInt64(ifNull(sum(page_count),0)) FROM " + d + ".duplicate_clusters WHERE crawl_id=" + cid)
	redirTotal := n("SELECT count() FROM (SELECT code FROM " + d + ".pages WHERE crawl_id=" + cid + " LIMIT 1 BY id) WHERE code >= 300 AND code < 400")
	redirChains := n("SELECT count() FROM " + d + ".redirect_chains WHERE crawl_id=" + cid)
	redirErrors := n("SELECT countIf(is_loop = 1 OR (final_code != 200 AND final_id != '')) FROM " + d + ".redirect_chains WHERE crawl_id=" + cid)
	_, _ = b.pool.Exec(ctx, `UPDATE crawls SET clusters_duplicate=$1, compliant_duplicate=$2,
		redirect_total=$3, redirect_chains_count=$4, redirect_chains_errors=$5 WHERE id=$6`,
		clusters, dupPages, redirTotal, redirChains, redirErrors, crawlID)
}

// migrate does the PG->CH migration of one crawl. withHTML controls whether the
// heavy HTML (view-source only) is migrated inline; All() defers it to phase 2.
func (b *Backfiller) migrate(ctx context.Context, crawlID int, withHTML bool) error {
	cid := strconv.Itoa(crawlID)
	b.logf("backfill crawl %d: starting", crawlID)

	// Idempotent: clear any prior CH data for this crawl (except HTML, which is
	// dropped+rebuilt in its own step so a re-run doesn't wipe a good HTML copy).
	tables := []string{"pages", "links", "page_schemas", "page_metrics", "duplicate_clusters", "redirect_chains"}
	for _, t := range tables {
		_ = b.ch.DropPartition(ctx, b.ch.DB()+"."+t, crawlID)
	}

	if err := b.pages(ctx, crawlID); err != nil {
		return fmt.Errorf("pages: %w", err)
	}
	if err := b.links(ctx, crawlID); err != nil {
		return fmt.Errorf("links: %w", err)
	}
	if err := b.schemas(ctx, crawlID); err != nil {
		return fmt.Errorf("page_schemas: %w", err)
	}

	// Post-processing in ClickHouse (page_metrics, duplicate, redirect).
	var cfg []byte
	_ = b.pool.QueryRow(ctx, "SELECT config FROM crawls WHERE id=$1", crawlID).Scan(&cfg)
	postprocess.NewCHRunner(b.ch, crawlID, postprocess.RespectNofollowFromConfig(cfg), b.logf).Run(ctx)
	b.SyncStats(ctx, crawlID) // write back duplicate/redirect scorecard stats

	// Completeness check before flipping the read store.
	var pgCount int
	_ = b.pool.QueryRow(ctx, "SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND crawled=true AND in_crawl=true", crawlID).Scan(&pgCount)
	chStr, _ := b.ch.QueryScalar(ctx, "SELECT uniqExact(id) FROM "+b.ch.DB()+".pages WHERE crawl_id="+cid)
	chCount, _ := strconv.Atoi(strings.TrimSpace(chStr))
	if pgCount > 0 && chCount < pgCount*9/10 {
		return fmt.Errorf("completeness check failed: CH has %d pages vs %d in PG", chCount, pgCount)
	}

	if _, err := b.pool.Exec(ctx, "UPDATE crawls SET data_store='clickhouse' WHERE id=$1", crawlID); err != nil {
		return fmt.Errorf("set data_store: %w", err)
	}
	b.logf("backfill crawl %d: done (%d pages in CH) — reports now on ClickHouse", crawlID, chCount)

	// HTML last (view-source only). All() defers this to phase 2 (withHTML=false).
	if withHTML {
		if err := b.html(ctx, crawlID); err != nil {
			b.logf("backfill crawl %d: html partial (%v)", crawlID, err)
		}
	}
	return nil
}

func (b *Backfiller) pages(ctx context.Context, crawlID int) error {
	rows, err := b.pool.Query(ctx, `
		SELECT id, domain, url, depth, code, response_time, outlinks, content_type, redirect_to,
		       crawled, compliant, noindex, nofollow, canonical, canonical_value, external, blocked,
		       title, h1, metadesc, extracts, simhash, is_html, h1_multiple, headings_missing, schemas, word_count
		FROM pages WHERE crawl_id=$1 AND in_crawl=true AND (crawled=true OR external=true)`, crawlID)
	if err != nil {
		return err
	}
	defer rows.Close()
	batch := make([]any, 0, batchSize)
	for rows.Next() {
		// Nullable columns (TEXT/INT that PG may store as NULL — e.g. title/h1 on
		// non-HTML pages, code on uncrawled redirect targets) are scanned into
		// pointers and coalesced to ''/0 for ClickHouse (no NULLs in those CH cols).
		var (
			id                                                                  string
			domain, url, contentType, redirectTo, canonicalValue, title, h1, metadesc *string
			depth, outlinks, wordCount                                          int
			code                                                                *int
			responseTime                                                        *float64
			crawled, compliant, noindex, nofollow, canonical, external, blocked bool
			isHTML, h1Multiple, headingsMissing                                 *bool
			extracts                                                            []byte
			simhash                                                             *int64
			schemas                                                             []string
		)
		if err := rows.Scan(&id, &domain, &url, &depth, &code, &responseTime, &outlinks, &contentType, &redirectTo,
			&crawled, &compliant, &noindex, &nofollow, &canonical, &canonicalValue, &external, &blocked,
			&title, &h1, &metadesc, &extracts, &simhash, &isHTML, &h1Multiple, &headingsMissing, &schemas, &wordCount); err != nil {
			return err
		}
		extractsMap := map[string]string{}
		if len(extracts) > 0 {
			_ = json.Unmarshal(extracts, &extractsMap)
		}
		if schemas == nil {
			schemas = []string{}
		}
		codeV := 0
		if code != nil {
			codeV = *code
		}
		rtV := 0.0
		if responseTime != nil {
			rtV = *responseTime
		}
		batch = append(batch, map[string]any{
			"crawl_id": crawlID, "id": strings.TrimSpace(id), "domain": ps(domain), "url": ps(url), "depth": depth,
			"code": codeV, "response_time": rtV, "outlinks": outlinks, "content_type": ps(contentType),
			"redirect_to": ps(redirectTo), "crawled": b2i(crawled), "compliant": b2i(compliant), "noindex": b2i(noindex),
			"nofollow": b2i(nofollow), "canonical": b2i(canonical), "canonical_value": ps(canonicalValue),
			"external": b2i(external), "blocked": b2i(blocked), "title": ps(title), "h1": ps(h1), "metadesc": ps(metadesc),
			"extracts": extractsMap, "simhash": simhash, "is_html": pb2i(isHTML), "h1_multiple": pb2i(h1Multiple),
			"headings_missing": pb2i(headingsMissing), "schemas": schemas, "word_count": wordCount,
		})
		if len(batch) >= batchSize {
			if err := b.ch.InsertJSONEachRow(ctx, b.ch.DB()+".pages", batch); err != nil {
				return err
			}
			batch = batch[:0]
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	return b.ch.InsertJSONEachRow(ctx, b.ch.DB()+".pages", batch)
}

func (b *Backfiller) links(ctx context.Context, crawlID int) error {
	rows, err := b.pool.Query(ctx,
		`SELECT src, target, anchor, external, nofollow, type, xpath, position FROM links WHERE crawl_id=$1`, crawlID)
	if err != nil {
		return err
	}
	defer rows.Close()
	batch := make([]any, 0, batchSize)
	for rows.Next() {
		var src, target string
		var anchor, typ, position, xpath *string
		var external, nofollow bool
		if err := rows.Scan(&src, &target, &anchor, &external, &nofollow, &typ, &xpath, &position); err != nil {
			return err
		}
		pos := ps(position)
		if pos == "" {
			pos = "Content"
		}
		batch = append(batch, map[string]any{
			"crawl_id": crawlID, "src": strings.TrimSpace(src), "target": strings.TrimSpace(target),
			"anchor": ps(anchor), "external": b2i(external), "nofollow": b2i(nofollow), "type": ps(typ),
			"xpath": xpath, "position": pos,
		})
		if len(batch) >= batchSize {
			if err := b.ch.InsertJSONEachRow(ctx, b.ch.DB()+".links", batch); err != nil {
				return err
			}
			batch = batch[:0]
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	return b.ch.InsertJSONEachRow(ctx, b.ch.DB()+".links", batch)
}

func (b *Backfiller) html(ctx context.Context, crawlID int) error {
	// Own the html partition lifecycle (it's migrated separately from the rest).
	_ = b.ch.DropPartition(ctx, b.ch.DB()+".html", crawlID)
	rows, err := b.pool.Query(ctx, `SELECT id, html FROM html WHERE crawl_id=$1`, crawlID)
	if err != nil {
		return err
	}
	defer rows.Close()
	batch := make([]any, 0, batchSize/4)
	for rows.Next() {
		var id, htmlZip string
		if err := rows.Scan(&id, &htmlZip); err != nil {
			return err
		}
		raw := unzip(htmlZip)
		if raw == "" {
			continue
		}
		batch = append(batch, map[string]any{"crawl_id": crawlID, "id": strings.TrimSpace(id), "html": raw})
		if len(batch) >= batchSize/4 {
			if err := b.ch.InsertJSONEachRow(ctx, b.ch.DB()+".html", batch); err != nil {
				return err
			}
			batch = batch[:0]
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	return b.ch.InsertJSONEachRow(ctx, b.ch.DB()+".html", batch)
}

func (b *Backfiller) schemas(ctx context.Context, crawlID int) error {
	rows, err := b.pool.Query(ctx, `SELECT page_id, schema_type FROM page_schemas WHERE crawl_id=$1`, crawlID)
	if err != nil {
		return err
	}
	defer rows.Close()
	batch := make([]any, 0, batchSize)
	for rows.Next() {
		var pageID, schemaType string
		if err := rows.Scan(&pageID, &schemaType); err != nil {
			return err
		}
		batch = append(batch, map[string]any{"crawl_id": crawlID, "page_id": strings.TrimSpace(pageID), "schema_type": schemaType})
		if len(batch) >= batchSize {
			if err := b.ch.InsertJSONEachRow(ctx, b.ch.DB()+".page_schemas", batch); err != nil {
				return err
			}
			batch = batch[:0]
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	return b.ch.InsertJSONEachRow(ctx, b.ch.DB()+".page_schemas", batch)
}

func b2i(v bool) int {
	if v {
		return 1
	}
	return 0
}

func pb2i(v *bool) int {
	if v != nil && *v {
		return 1
	}
	return 0
}

// ps derefs a nullable string column to "" when NULL.
func ps(v *string) string {
	if v == nil {
		return ""
	}
	return *v
}

// unzip reverses PG's stored DomZip (base64 + raw flate) to raw HTML. The
// "<!-- TRUNCATED -->" marker on >1MB blobs is harmless (decodes the prefix).
func unzip(s string) string {
	s = strings.TrimSuffix(s, "\n<!-- TRUNCATED -->")
	data, err := base64.StdEncoding.DecodeString(s)
	if err != nil {
		return ""
	}
	r := flate.NewReader(bytes.NewReader(data))
	defer r.Close()
	out, err := io.ReadAll(r)
	if err != nil && len(out) == 0 {
		return ""
	}
	return string(out)
}
