// Package model holds the shared data structures produced by page parsing and
// consumed by the DB layer. They mirror the shape of the PHP Page::getPage()
// object so the database semantics stay identical.
package model

// Link is one extracted <a> (or canonical/redirect) edge of the crawl graph.
// Mirrors the objects produced by Page::parse() / HtmlParser::extractLinksWithPosition.
type Link struct {
	Target   string // absolute URL of the target
	TargetID string // crc32-bzip2 hex of Target (CHAR(8))
	External bool
	Anchor   string
	Nofollow bool
	Blocked  bool // blocked by robots.txt (internal links only)
	XPath    string
	Position string // Navigation | Header | Footer | Aside | Content
}

// Headers carries the response metadata the parser needs, mirroring the PHP
// $headers object built from cURL getResponseInfo().
type Headers struct {
	HTTPCode     int
	RedirectURL  string  // empty when not a redirect
	ResponseTime float64 // seconds (starttransfer_time, fallback total_time)
	Size         int
	ContentType  string
	XRobotsTag   string
}

// Page is the fully parsed result for one crawled URL. Field names map 1:1 to
// the columns written by PageCrawler::storePageComplete / storeLinks / storeRaw.
type Page struct {
	ID       string // crc32-bzip2 hex of URL (CHAR(8))
	URL      string
	Domain   string
	DomainID string

	HTTPCode     int
	RedirectTo   string
	ResponseTime float64 // seconds
	Size         int
	ContentType  string

	// robots/canonical config flags (Page::configuration)
	Nofollow  bool
	Noindex   bool
	Canonical bool // true = page is self-canonical (or has no canonical)

	// extracts
	Title         string
	H1            string
	MetaDesc      string
	CanonicalURL  string            // absolute canonical href ("" if none)
	CustomExtract map[string]string // xPath + regex extractors (excludes the 4 builtins)

	// content / structure
	IsHTML          bool
	Simhash         *int64 // nil when not HTML; stored as BIGINT
	H1Multiple      bool
	HeadingsMissing bool
	Schemas         []string
	WordCount       int

	// raw
	DomHash string // sha1 of decoded DOM ("" when not HTML/200)
	DomZip  string // base64(flate(dom)) — stored in html table

	Links []Link
}
