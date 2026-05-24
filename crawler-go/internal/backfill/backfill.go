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
	for _, id := range ids {
		if err := b.Crawl(ctx, id); err != nil {
			b.logf("backfill crawl %d FAILED: %v", id, err)
		}
	}
	return nil
}

// Crawl backfills one crawl PG -> CH and flips data_store.
func (b *Backfiller) Crawl(ctx context.Context, crawlID int) error {
	cid := strconv.Itoa(crawlID)
	b.logf("backfill crawl %d: starting", crawlID)

	// Idempotent: clear any prior CH data for this crawl.
	for _, t := range []string{"pages", "links", "html", "page_schemas", "page_metrics", "duplicate_clusters", "redirect_chains"} {
		_ = b.ch.DropPartition(ctx, b.ch.DB()+"."+t, crawlID)
	}

	if err := b.pages(ctx, crawlID); err != nil {
		return fmt.Errorf("pages: %w", err)
	}
	if err := b.links(ctx, crawlID); err != nil {
		return fmt.Errorf("links: %w", err)
	}
	if err := b.html(ctx, crawlID); err != nil {
		return fmt.Errorf("html: %w", err)
	}
	if err := b.schemas(ctx, crawlID); err != nil {
		return fmt.Errorf("page_schemas: %w", err)
	}

	// Post-processing in ClickHouse (page_metrics, duplicate, redirect).
	var cfg []byte
	_ = b.pool.QueryRow(ctx, "SELECT config FROM crawls WHERE id=$1", crawlID).Scan(&cfg)
	postprocess.NewCHRunner(b.ch, crawlID, postprocess.RespectNofollowFromConfig(cfg), b.logf).Run(ctx)

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
	b.logf("backfill crawl %d: done (%d pages in CH)", crawlID, chCount)
	return nil
}

func (b *Backfiller) pages(ctx context.Context, crawlID int) error {
	rows, err := b.pool.Query(ctx, `
		SELECT id, domain, url, depth, code, response_time, outlinks, content_type, redirect_to,
		       crawled, compliant, noindex, nofollow, canonical, canonical_value, external, blocked,
		       title, h1, metadesc, extracts, simhash, is_html, h1_multiple, headings_missing, schemas, word_count
		FROM pages WHERE crawl_id=$1 AND crawled=true AND in_crawl=true`, crawlID)
	if err != nil {
		return err
	}
	defer rows.Close()
	batch := make([]any, 0, batchSize)
	for rows.Next() {
		var (
			id, domain, url, contentType, redirectTo, canonicalValue, title, h1, metadesc string
			depth, code, outlinks, wordCount                                              int
			responseTime                                                                  float64
			crawled, compliant, noindex, nofollow, canonical, external, blocked           bool
			isHTML, h1Multiple, headingsMissing                                           *bool
			extracts                                                                      []byte
			simhash                                                                       *int64
			schemas                                                                       []string
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
		batch = append(batch, map[string]any{
			"crawl_id": crawlID, "id": strings.TrimSpace(id), "domain": domain, "url": url, "depth": depth,
			"code": code, "response_time": responseTime, "outlinks": outlinks, "content_type": contentType,
			"redirect_to": redirectTo, "crawled": 1, "compliant": b2i(compliant), "noindex": b2i(noindex),
			"nofollow": b2i(nofollow), "canonical": b2i(canonical), "canonical_value": canonicalValue,
			"external": b2i(external), "blocked": b2i(blocked), "title": title, "h1": h1, "metadesc": metadesc,
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
		var src, target, anchor, typ, position string
		var external, nofollow bool
		var xpath *string
		if err := rows.Scan(&src, &target, &anchor, &external, &nofollow, &typ, &xpath, &position); err != nil {
			return err
		}
		batch = append(batch, map[string]any{
			"crawl_id": crawlID, "src": strings.TrimSpace(src), "target": strings.TrimSpace(target),
			"anchor": anchor, "external": b2i(external), "nofollow": b2i(nofollow), "type": typ,
			"xpath": xpath, "position": position,
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
