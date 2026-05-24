package crawl

import (
	"bytes"
	"compress/flate"
	"context"
	"encoding/base64"
	"io"
	"sync"

	"scouter-crawler/internal/db"
)

// chBatch is how many rows accumulate per table before an async flush to CH.
// Crawling is network-bound on fetching, so even 1000-row HTTP inserts are cheap.
const chBatch = 1000

// CHStore mirrors the crawl-data writes (pages/links/html/page_schemas) into
// ClickHouse, in addition to the PostgreSQL frontier/data writes. It is the
// dual-write half of the PG->CH migration: enabled only when a *db.CH is wired
// (CLICKHOUSE_URL set). Append-only — never updates. Thread-safe: storePage runs
// from many fetch goroutines at once.
//
// A CH failure never aborts the crawl: errors are logged and the buffer dropped,
// because PostgreSQL remains the source of truth during the transition.
type CHStore struct {
	ch      *db.CH
	crawlID int
	logf    func(string, ...any)

	mu      sync.Mutex
	pages   []any
	links   []any
	schemas []any
	htmls   []any
	seenExt map[string]struct{} // external page ids already appended (dedup)
}

// NewCHStore returns a store, or nil if ch is nil (CH disabled → no-op everywhere).
func NewCHStore(ch *db.CH, crawlID int, logf func(string, ...any)) *CHStore {
	if ch == nil {
		return nil
	}
	if logf == nil {
		logf = func(string, ...any) {}
	}
	return &CHStore{ch: ch, crawlID: crawlID, logf: logf}
}

type chPageRow struct {
	CrawlID         int               `json:"crawl_id"`
	ID              string            `json:"id"`
	Domain          string            `json:"domain"`
	URL             string            `json:"url"`
	Depth           int               `json:"depth"`
	Code            int               `json:"code"`
	ResponseTime    float64           `json:"response_time"`
	Outlinks        int               `json:"outlinks"`
	ContentType     string            `json:"content_type"`
	RedirectTo      string            `json:"redirect_to"`
	Crawled         int               `json:"crawled"`
	Compliant       int               `json:"compliant"`
	Noindex         int               `json:"noindex"`
	Nofollow        int               `json:"nofollow"`
	Canonical       int               `json:"canonical"`
	CanonicalValue  string            `json:"canonical_value"`
	External        int               `json:"external"`
	Blocked         int               `json:"blocked"`
	Title           string            `json:"title"`
	H1              string            `json:"h1"`
	MetaDesc        string            `json:"metadesc"`
	Extracts        map[string]string `json:"extracts"`
	Simhash         *int64            `json:"simhash"`
	IsHTML          int               `json:"is_html"`
	H1Multiple      int               `json:"h1_multiple"`
	HeadingsMissing int               `json:"headings_missing"`
	Schemas         []string          `json:"schemas"`
	WordCount       int               `json:"word_count"`
}

type chLinkRow struct {
	CrawlID  int     `json:"crawl_id"`
	Src      string  `json:"src"`
	Target   string  `json:"target"`
	Anchor   string  `json:"anchor"`
	External int     `json:"external"`
	Nofollow int     `json:"nofollow"`
	Type     string  `json:"type"`
	XPath    *string `json:"xpath"`
	Position string  `json:"position"`
}

type chSchemaRow struct {
	CrawlID    int    `json:"crawl_id"`
	PageID     string `json:"page_id"`
	SchemaType string `json:"schema_type"`
}

type chHTMLRow struct {
	CrawlID int    `json:"crawl_id"`
	ID      string `json:"id"`
	HTML    string `json:"html"`
}

func b2i(b bool) int {
	if b {
		return 1
	}
	return 0
}

// AddPage enqueues a crawled page (the observed signals; derived fields like
// inlinks/pri/*_status are computed later by the CH post-processing).
func (s *CHStore) AddPage(row chPageRow) {
	if s == nil {
		return
	}
	if row.Extracts == nil {
		row.Extracts = map[string]string{}
	}
	if row.Schemas == nil {
		row.Schemas = []string{}
	}
	s.mu.Lock()
	s.pages = append(s.pages, row)
	flush := len(s.pages) >= chBatch
	s.mu.Unlock()
	if flush {
		s.flushPages(context.Background())
	}
}

// AddExternalPage enqueues an external (uncrawled) link target as a `pages` row
// — external=1, crawled=0, no observed signals. The PG frontier stores these so
// PageRank can leak toward them and reports (pagerank-leak "top external
// domains", outlinks…) can aggregate by domain; CH needs them too. Deduped by id
// (an external URL is the target of many inbound links → inserted once). A row
// later crawled (e.g. list-mode forced-external) is re-appended by AddPage with a
// newer date, so ReplacingMergeTree(date) keeps the crawled version.
func (s *CHStore) AddExternalPage(id, domain, url string, depth int, blocked bool) {
	if s == nil || id == "" {
		return
	}
	s.mu.Lock()
	if s.seenExt == nil {
		s.seenExt = make(map[string]struct{})
	}
	if _, ok := s.seenExt[id]; ok {
		s.mu.Unlock()
		return
	}
	s.seenExt[id] = struct{}{}
	s.pages = append(s.pages, chPageRow{
		CrawlID: s.crawlID, ID: id, Domain: domain, URL: url, Depth: depth,
		Crawled: 0, External: 1, Blocked: b2i(blocked),
		Extracts: map[string]string{}, Schemas: []string{},
	})
	flush := len(s.pages) >= chBatch
	s.mu.Unlock()
	if flush {
		s.flushPages(context.Background())
	}
}

// AddLinks enqueues link edges (mirrors what goes into PG `links`).
func (s *CHStore) AddLinks(rows []chLinkRow) {
	if s == nil || len(rows) == 0 {
		return
	}
	s.mu.Lock()
	for _, r := range rows {
		s.links = append(s.links, r)
	}
	flush := len(s.links) >= chBatch
	s.mu.Unlock()
	if flush {
		s.flushLinks(context.Background())
	}
}

// AddSchemas enqueues structured-data types for a page.
func (s *CHStore) AddSchemas(pageID string, types []string) {
	if s == nil || len(types) == 0 {
		return
	}
	s.mu.Lock()
	for _, t := range types {
		s.schemas = append(s.schemas, chSchemaRow{CrawlID: s.crawlID, PageID: pageID, SchemaType: t})
	}
	flush := len(s.schemas) >= chBatch
	s.mu.Unlock()
	if flush {
		s.flushSchemas(context.Background())
	}
}

// AddHTMLZipped enqueues a page's HTML, decoding the base64+flate DomZip into the
// raw HTML stored (ZSTD-compressed) in ClickHouse.
func (s *CHStore) AddHTMLZipped(id, domZip string) {
	if s == nil || domZip == "" {
		return
	}
	raw := unzipDom(domZip)
	if raw == "" {
		return
	}
	s.mu.Lock()
	s.htmls = append(s.htmls, chHTMLRow{CrawlID: s.crawlID, ID: id, HTML: raw})
	flush := len(s.htmls) >= chBatch/4 // HTML rows are large; flush sooner
	s.mu.Unlock()
	if flush {
		s.flushHTML(context.Background())
	}
}

// Flush drains all buffers. Called at crawl/sitemap-pass end before post-processing.
func (s *CHStore) Flush(ctx context.Context) {
	if s == nil {
		return
	}
	s.flushPages(ctx)
	s.flushLinks(ctx)
	s.flushSchemas(ctx)
	s.flushHTML(ctx)
}

func (s *CHStore) flushPages(ctx context.Context) {
	s.mu.Lock()
	batch := s.pages
	s.pages = nil
	s.mu.Unlock()
	if len(batch) == 0 {
		return
	}
	if err := s.ch.InsertJSONEachRow(ctx, s.ch.DB()+".pages", batch); err != nil {
		s.logf("clickhouse pages insert failed (%d rows): %v", len(batch), err)
	}
}

func (s *CHStore) flushLinks(ctx context.Context) {
	s.mu.Lock()
	batch := s.links
	s.links = nil
	s.mu.Unlock()
	if len(batch) == 0 {
		return
	}
	if err := s.ch.InsertJSONEachRow(ctx, s.ch.DB()+".links", batch); err != nil {
		s.logf("clickhouse links insert failed (%d rows): %v", len(batch), err)
	}
}

func (s *CHStore) flushSchemas(ctx context.Context) {
	s.mu.Lock()
	batch := s.schemas
	s.schemas = nil
	s.mu.Unlock()
	if len(batch) == 0 {
		return
	}
	if err := s.ch.InsertJSONEachRow(ctx, s.ch.DB()+".page_schemas", batch); err != nil {
		s.logf("clickhouse page_schemas insert failed (%d rows): %v", len(batch), err)
	}
}

func (s *CHStore) flushHTML(ctx context.Context) {
	s.mu.Lock()
	batch := s.htmls
	s.htmls = nil
	s.mu.Unlock()
	if len(batch) == 0 {
		return
	}
	if err := s.ch.InsertJSONEachRow(ctx, s.ch.DB()+".html", batch); err != nil {
		s.logf("clickhouse html insert failed (%d rows): %v", len(batch), err)
	}
}

// unzipDom reverses page.zipDom (base64 → raw flate → DOM string).
func unzipDom(domZip string) string {
	data, err := base64.StdEncoding.DecodeString(domZip)
	if err != nil {
		return ""
	}
	r := flate.NewReader(bytes.NewReader(data))
	defer r.Close()
	out, err := io.ReadAll(r)
	if err != nil {
		return ""
	}
	return string(out)
}
