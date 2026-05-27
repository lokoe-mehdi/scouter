package db

import (
	"context"
	"encoding/json"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
)

const (
	maxURLLength    = 2083
	maxAnchorLength = 500
)

// CrawlDB is the per-crawl handle. Mirrors app/Database/CrawlDatabase.php.
type CrawlDB struct {
	pool    *Pool
	CrawlID int
}

func NewCrawlDB(pool *Pool, crawlID int) *CrawlDB {
	return &CrawlDB{pool: pool, CrawlID: crawlID}
}

// PageRow is an insert into the pages table (frontier rows).
type PageRow struct {
	ID       string
	Domain   string
	URL      string
	Depth    int
	Code     int
	Crawled  bool
	External bool
	Blocked  bool
	InCrawl  bool
}

// LinkRow is an insert into the links table.
type LinkRow struct {
	Src      string
	Target   string
	Anchor   string
	Type     string
	External bool
	Nofollow bool
	XPath    *string
	Position string
}

// CreatePartitions calls create_crawl_partitions, serialized by the same
// advisory lock 12345 PHP uses. The worker normally creates partitions before
// launching the crawl (PARTITIONS_CREATED), but this is safe to call again.
func (c *CrawlDB) CreatePartitions(ctx context.Context) error {
	return withRetry(ctx, func() error {
		_, err := c.pool.Exec(ctx, "SELECT pg_advisory_lock(12345)")
		if err != nil {
			return err
		}
		_, execErr := c.pool.Exec(ctx, "SELECT create_crawl_partitions($1)", c.CrawlID)
		_, _ = c.pool.Exec(ctx, "SELECT pg_advisory_unlock(12345)")
		return execErr
	})
}

func (c *CrawlDB) GetCrawlStatus(ctx context.Context) (string, error) {
	var status string
	err := c.pool.QueryRow(ctx, "SELECT status FROM crawls WHERE id=$1", c.CrawlID).Scan(&status)
	return status, err
}

func (c *CrawlDB) IsNewCrawl(ctx context.Context) (bool, error) {
	var n int
	err := c.pool.QueryRow(ctx,
		"SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND in_crawl=TRUE", c.CrawlID).Scan(&n)
	return n == 0, err
}

// InsertPage inserts a single frontier row (ON CONFLICT DO NOTHING) — used for
// the start URL(s). Mirrors CrawlDatabase::insertPage.
func (c *CrawlDB) InsertPage(ctx context.Context, p PageRow) error {
	return withRetry(ctx, func() error {
		_, err := c.pool.Exec(ctx, `
			INSERT INTO pages (crawl_id, id, domain, url, depth, code, crawled, external, blocked, date)
			VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)
			ON CONFLICT (crawl_id, id) DO NOTHING`,
			c.CrawlID, p.ID, p.Domain, truncate(p.URL, maxURLLength), p.Depth, p.Code,
			p.Crawled, p.External, p.Blocked, time.Now())
		return err
	})
}

// InsertPages batch-inserts frontier rows with the monotonic in_crawl promotion
// of CrawlDatabase::insertPages: dedup by id (OR-ing in_crawl), then
// ON CONFLICT DO UPDATE SET in_crawl=true WHERE was false, not sitemap-only, and
// the new row is followable.
func (c *CrawlDB) InsertPages(ctx context.Context, pages []PageRow) error {
	if len(pages) == 0 {
		return nil
	}
	// dedup by id, OR-ing in_crawl
	order := []string{}
	uniq := map[string]PageRow{}
	for _, p := range pages {
		if cur, ok := uniq[p.ID]; ok {
			cur.InCrawl = cur.InCrawl || p.InCrawl
			uniq[p.ID] = cur
		} else {
			uniq[p.ID] = p
			order = append(order, p.ID)
		}
	}

	// Sort ids so every concurrent upsert acquires row locks in the same global
	// order. Without this, two workers upserting overlapping target rows (shared
	// nav/footer/lang links) in different orders deadlock (40P01).
	sort.Strings(order)

	now := time.Now()
	for _, chunk := range chunkIDs(order, 100) {
		var sb strings.Builder
		sb.WriteString("INSERT INTO pages (crawl_id, id, domain, url, depth, code, crawled, external, blocked, date, in_crawl) VALUES ")
		args := make([]any, 0, len(chunk)*11)
		for i, id := range chunk {
			p := uniq[id]
			if i > 0 {
				sb.WriteByte(',')
			}
			b := i * 11
			sb.WriteString("($" + itoa(b+1) + ",$" + itoa(b+2) + ",$" + itoa(b+3) + ",$" + itoa(b+4) + ",$" + itoa(b+5) + ",$" + itoa(b+6) + ",$" + itoa(b+7) + ",$" + itoa(b+8) + ",$" + itoa(b+9) + ",$" + itoa(b+10) + ",$" + itoa(b+11) + ")")
			args = append(args, c.CrawlID, p.ID, p.Domain, truncate(p.URL, maxURLLength),
				p.Depth, p.Code, p.Crawled, p.External, p.Blocked, now, p.InCrawl)
		}
		sb.WriteString(` ON CONFLICT (crawl_id, id) DO UPDATE SET in_crawl = true
			WHERE pages.in_crawl = false AND pages.in_sitemap = false AND EXCLUDED.in_crawl = true`)
		sql := sb.String()
		if err := withRetry(ctx, func() error {
			_, err := c.pool.Exec(ctx, sql, args...)
			return err
		}); err != nil {
			return err
		}
	}
	return nil
}

// InsertLink inserts one link (used for redirect/canonical edges).
func (c *CrawlDB) InsertLink(ctx context.Context, l LinkRow) error {
	return c.InsertLinks(ctx, []LinkRow{l})
}

// InsertLinks batch-inserts link rows (no PK; duplicates intended).
func (c *CrawlDB) InsertLinks(ctx context.Context, links []LinkRow) error {
	if len(links) == 0 {
		return nil
	}
	for _, chunk := range chunkLinks(links, 100) {
		var sb strings.Builder
		sb.WriteString("INSERT INTO links (crawl_id, src, target, anchor, type, external, nofollow, xpath, position) VALUES ")
		args := make([]any, 0, len(chunk)*9)
		for i, l := range chunk {
			if i > 0 {
				sb.WriteByte(',')
			}
			b := i * 9
			sb.WriteString("($" + itoa(b+1) + ",$" + itoa(b+2) + ",$" + itoa(b+3) + ",$" + itoa(b+4) + ",$" + itoa(b+5) + ",$" + itoa(b+6) + ",$" + itoa(b+7) + ",$" + itoa(b+8) + ",$" + itoa(b+9) + ")")
			args = append(args, c.CrawlID, l.Src, l.Target, truncate(l.Anchor, maxAnchorLength),
				l.Type, l.External, l.Nofollow, l.XPath, l.Position)
		}
		sql := sb.String()
		if err := withRetry(ctx, func() error {
			_, err := c.pool.Exec(ctx, sql, args...)
			return err
		}); err != nil {
			return err
		}
	}
	return nil
}

// InsertHTML stores raw HTML (1MB cap with marker), ON CONFLICT DO NOTHING.
func (c *CrawlDB) InsertHTML(ctx context.Context, pageID, htmlStr string) error {
	const maxSize = 1 << 20
	if len(htmlStr) > maxSize {
		// Rune-safe cut: slicing at a raw byte offset can split a multibyte char
		// and leave a dangling byte that Postgres rejects as invalid UTF-8 (22021).
		htmlStr = truncate(htmlStr, maxSize) + "\n<!-- TRUNCATED -->"
	}
	return withRetry(ctx, func() error {
		_, err := c.pool.Exec(ctx, `
			INSERT INTO html (crawl_id, id, html) VALUES ($1,$2,$3)
			ON CONFLICT (crawl_id, id) DO NOTHING`, c.CrawlID, pageID, htmlStr)
		return err
	})
}

// InsertPageSchemas inserts schema types for a page (ON CONFLICT DO NOTHING).
func (c *CrawlDB) InsertPageSchemas(ctx context.Context, pageID string, schemas []string) error {
	if len(schemas) == 0 {
		return nil
	}
	var sb strings.Builder
	sb.WriteString("INSERT INTO page_schemas (crawl_id, page_id, schema_type) VALUES ")
	args := make([]any, 0, len(schemas)*3)
	for i, s := range schemas {
		if i > 0 {
			sb.WriteByte(',')
		}
		b := i * 3
		sb.WriteString("($" + itoa(b+1) + ",$" + itoa(b+2) + ",$" + itoa(b+3) + ")")
		args = append(args, c.CrawlID, pageID, s)
	}
	sb.WriteString(" ON CONFLICT (crawl_id, page_id, schema_type) DO NOTHING")
	sql := sb.String()
	return withRetry(ctx, func() error {
		_, err := c.pool.Exec(ctx, sql, args...)
		return err
	})
}

// UpdatePage sets the provided columns of a crawled page. Mirrors
// CrawlDatabase::updatePage (dynamic SET list; extracts→jsonb, schemas→text[]).
func (c *CrawlDB) UpdatePage(ctx context.Context, pageID string, sets map[string]any) error {
	if len(sets) == 0 {
		return nil
	}
	var cols []string
	args := []any{}
	idx := 1
	for col, val := range sets {
		switch col {
		case "extracts":
			b, _ := json.Marshal(val)
			cols = append(cols, "extracts = $"+itoa(idx)+"::jsonb")
			args = append(args, string(b))
		default:
			if col == "canonical_value" || col == "redirect_to" {
				if s, ok := val.(string); ok {
					val = truncate(s, maxURLLength)
				}
			}
			cols = append(cols, col+" = $"+itoa(idx))
			args = append(args, val)
		}
		idx++
	}
	args = append(args, c.CrawlID, pageID)
	sql := "UPDATE pages SET " + strings.Join(cols, ", ") +
		" WHERE crawl_id = $" + itoa(idx) + " AND id = $" + itoa(idx+1)
	return withRetry(ctx, func() error {
		_, err := c.pool.Exec(ctx, sql, args...)
		return err
	})
}

// GetUrlsToCrawl mirrors CrawlDatabase::getUrlsToCrawl.
func (c *CrawlDB) GetUrlsToCrawl(ctx context.Context, respectRobots bool, limit, maxDepth int) ([]string, error) {
	sql := "SELECT url FROM pages WHERE crawl_id=$1 AND crawled=false AND external=false AND in_crawl=TRUE"
	if respectRobots {
		sql += " AND blocked = false"
	}
	if maxDepth >= 0 {
		sql += " AND depth = " + strconv.Itoa(maxDepth)
	}
	sql += " ORDER BY id"
	if limit > 0 {
		sql += " LIMIT " + strconv.Itoa(limit)
	}
	rows, err := c.pool.Query(ctx, sql, c.CrawlID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var urls []string
	for rows.Next() {
		var u string
		if err := rows.Scan(&u); err != nil {
			return nil, err
		}
		urls = append(urls, u)
	}
	return urls, rows.Err()
}

func (c *CrawlDB) CountUrlsToCrawl(ctx context.Context, respectRobots bool, maxDepth int) (int, error) {
	sql := "SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND crawled=false AND external=false AND in_crawl=TRUE"
	if respectRobots {
		sql += " AND blocked = false"
	}
	if maxDepth >= 0 {
		sql += " AND depth = " + strconv.Itoa(maxDepth)
	}
	var n int
	err := c.pool.QueryRow(ctx, sql, c.CrawlID).Scan(&n)
	return n, err
}

// GetCrawledCount returns crawls.crawled (pages actually fetched, in_crawl=true) —
// used to report the average URLs/sec at the end of the walk.
func (c *CrawlDB) GetCrawledCount(ctx context.Context) int {
	var n int
	_ = c.pool.QueryRow(ctx, "SELECT crawled FROM crawls WHERE id=$1", c.CrawlID).Scan(&n)
	return n
}

func (c *CrawlDB) GetCurrentDepth(ctx context.Context) (int, error) {
	var depth int
	// Resume must continue from the LOWEST unfinished depth so the walk stays
	// breadth-first. Without ORDER BY, PG returns an arbitrary uncrawled row, so
	// a resume could jump to depth N+1 while depth N still had pending URLs.
	err := c.pool.QueryRow(ctx, `
		SELECT depth FROM pages
		WHERE crawl_id=$1 AND crawled=false AND external=false AND blocked=false AND in_crawl=TRUE
		ORDER BY depth ASC
		LIMIT 1`, c.CrawlID).Scan(&depth)
	if err == pgx.ErrNoRows {
		return 0, nil
	}
	return depth, err
}

// UpdateCrawlStats recomputes the aggregate stats on the crawls row.
func (c *CrawlDB) UpdateCrawlStats(ctx context.Context) error {
	return withRetry(ctx, func() error {
		_, err := c.pool.Exec(ctx, `
			UPDATE crawls SET
				urls = (SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND in_crawl=TRUE),
				crawled = (SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND crawled=true AND in_crawl=TRUE),
				compliant = (SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND compliant=true AND in_crawl=TRUE),
				duplicates = (SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND canonical=false AND in_crawl=TRUE),
				critical_errors = (SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND code>=400 AND crawled=true AND in_crawl=TRUE),
				response_time = ROUND(COALESCE((SELECT AVG(response_time) FROM pages WHERE crawl_id=$1 AND code=200 AND response_time>0 AND in_crawl=TRUE),0)::numeric, 2),
				depth_max = COALESCE((SELECT MAX(depth) FROM pages WHERE crawl_id=$1 AND crawled=true AND in_crawl=TRUE),0),
				in_progress = (SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND crawled=false AND external=false AND in_crawl=TRUE)
			WHERE id=$1`, c.CrawlID)
		return err
	})
}

// FinishCrawl updates stats then marks the crawl finished.
func (c *CrawlDB) FinishCrawl(ctx context.Context) error {
	if err := c.UpdateCrawlStats(ctx); err != nil {
		return err
	}
	_, err := c.pool.Exec(ctx, `
		UPDATE crawls SET status='finished', finished_at=CURRENT_TIMESTAMP, in_progress=0
		WHERE id=$1`, c.CrawlID)
	return err
}

// SetStopped marks a user-stopped crawl as stopped (keeps stats).
func (c *CrawlDB) SetStopped(ctx context.Context) error {
	_, err := c.pool.Exec(ctx, `
		UPDATE crawls SET status='stopped', in_progress=0, finished_at=CURRENT_TIMESTAMP WHERE id=$1`, c.CrawlID)
	return err
}

// SetDataStore records where this crawl's data lives ('pg' | 'clickhouse'), so
// the read layer can route queries. Set to 'clickhouse' at crawl start when CH
// dual-write is active. Best-effort (the column exists after its migration).
func (c *CrawlDB) SetDataStore(ctx context.Context, store string) {
	_, _ = c.pool.Exec(ctx, "UPDATE crawls SET data_store=$1 WHERE id=$2", store, c.CrawlID)
}

// Pool exposes the underlying pool (used by postprocess which runs raw SQL).
func (c *CrawlDB) Pool() *Pool { return c.pool }

// --- small helpers ---

func truncate(s string, max int) string {
	if len(s) <= max {
		return s
	}
	// byte-safe truncation on a rune boundary
	for max > 0 && !utf8Start(s[max]) {
		max--
	}
	return s[:max]
}

func utf8Start(b byte) bool { return b&0xC0 != 0x80 }

func itoa(i int) string { return strconv.Itoa(i) }

func chunkIDs(ids []string, size int) [][]string {
	var out [][]string
	for i := 0; i < len(ids); i += size {
		end := i + size
		if end > len(ids) {
			end = len(ids)
		}
		out = append(out, ids[i:end])
	}
	return out
}

func chunkLinks(links []LinkRow, size int) [][]LinkRow {
	var out [][]LinkRow
	for i := 0; i < len(links); i += size {
		end := i + size
		if end > len(links) {
			end = len(links)
		}
		out = append(out, links[i:end])
	}
	return out
}
