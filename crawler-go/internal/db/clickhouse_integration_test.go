package db

import (
	"context"
	"os"
	"testing"
)

// TestCHIntegration exercises the ClickHouse HTTP client against a real server.
// Opt-in: skipped unless CLICKHOUSE_URL is set (so CI without CH stays green).
//
//	CLICKHOUSE_URL=http://localhost:18123 CLICKHOUSE_USER=scouter \
//	CLICKHOUSE_PASSWORD=scouter go test ./internal/db -run TestCHIntegration -v
func TestCHIntegration(t *testing.T) {
	if os.Getenv("CLICKHOUSE_URL") == "" {
		t.Skip("CLICKHOUSE_URL not set; skipping ClickHouse integration test")
	}
	ctx := context.Background()
	ch, err := NewCHFromEnv(ctx)
	if err != nil {
		t.Fatalf("NewCHFromEnv: %v", err)
	}
	if ch == nil {
		t.Fatal("expected a CH client")
	}

	const cid = 424242
	_ = ch.DropPartition(ctx, ch.DB()+".pages", cid)
	_ = ch.DropPartition(ctx, ch.DB()+".links", cid)

	sim := int64(123456789)
	pages := []any{
		map[string]any{
			"crawl_id": cid, "id": "zzzzzzz1", "domain": "t.com", "url": "http://t.com/",
			"depth": 0, "code": 200, "response_time": 10.0, "outlinks": 1, "content_type": "text/html",
			"redirect_to": "", "crawled": 1, "compliant": 1, "noindex": 0, "nofollow": 0, "canonical": 1,
			"canonical_value": "", "external": 0, "blocked": 0, "title": "T", "h1": "T", "metadesc": "d",
			"extracts": map[string]string{"k": "v"}, "simhash": &sim, "is_html": 1, "h1_multiple": 0,
			"headings_missing": 0, "schemas": []string{"WebPage"}, "word_count": 5,
		},
		map[string]any{
			"crawl_id": cid, "id": "zzzzzzz2", "domain": "t.com", "url": "http://t.com/a",
			"depth": 1, "code": 200, "response_time": 12.0, "outlinks": 0, "content_type": "text/html",
			"redirect_to": "", "crawled": 1, "compliant": 1, "noindex": 0, "nofollow": 0, "canonical": 1,
			"canonical_value": "", "external": 0, "blocked": 0, "title": "T", "h1": "A", "metadesc": "d",
			"extracts": map[string]string{}, "simhash": &sim, "is_html": 1, "h1_multiple": 0,
			"headings_missing": 0, "schemas": []string{}, "word_count": 5,
		},
	}
	if err := ch.InsertJSONEachRow(ctx, ch.DB()+".pages", pages); err != nil {
		t.Fatalf("insert pages: %v", err)
	}
	links := []any{
		map[string]any{"crawl_id": cid, "src": "zzzzzzz1", "target": "zzzzzzz2", "anchor": "a",
			"external": 0, "nofollow": 0, "type": "ahref", "xpath": nil, "position": "Content"},
	}
	if err := ch.InsertJSONEachRow(ctx, ch.DB()+".links", links); err != nil {
		t.Fatalf("insert links: %v", err)
	}

	got, err := ch.QueryScalar(ctx, "SELECT count() FROM "+ch.DB()+".pages WHERE crawl_id="+itoa(cid))
	if err != nil {
		t.Fatalf("count: %v", err)
	}
	if got != "2" {
		t.Fatalf("expected 2 pages, got %q", got)
	}

	// extracts Map round-trips
	v, err := ch.QueryScalar(ctx, "SELECT extracts['k'] FROM "+ch.DB()+".pages WHERE crawl_id="+itoa(cid)+" AND id='zzzzzzz1'")
	if err != nil || v != "v" {
		t.Fatalf("extracts map: got %q err %v", v, err)
	}

	// TSV multi-row read (the shape the post-processing uses)
	rows, err := ch.QueryTSV(ctx, "SELECT toString(src), toString(target) FROM "+ch.DB()+".links WHERE crawl_id="+itoa(cid))
	if err != nil || len(rows) != 1 || rows[0][0] != "zzzzzzz1" || rows[0][1] != "zzzzzzz2" {
		t.Fatalf("links TSV: got %v err %v", rows, err)
	}

	// DROP PARTITION cleans up
	if err := ch.DropPartition(ctx, ch.DB()+".pages", cid); err != nil {
		t.Fatalf("drop partition: %v", err)
	}
	got, _ = ch.QueryScalar(ctx, "SELECT count() FROM "+ch.DB()+".pages WHERE crawl_id="+itoa(cid))
	if got != "0" && got != "" {
		t.Fatalf("expected 0 pages after drop, got %q", got)
	}
	_ = ch.DropPartition(ctx, ch.DB()+".links", cid)
}
