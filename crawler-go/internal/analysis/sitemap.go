package analysis

import (
	"bytes"
	"compress/gzip"
	"encoding/xml"
	"io"
	"net/http"
	"regexp"
	"strings"
	"time"
)

// SitemapParser ports app/Sitemap/SitemapParser.php with identical safety limits.
const (
	maxIndexDepth    = 2
	maxChildSitemaps = 50
	maxSitemapURLs   = 50000
	sitemapTimeout   = 30 * time.Second
	maxBodyBytes     = 50 * 1024 * 1024
)

// SitemapResult mirrors App\Sitemap\SitemapResult.
type SitemapResult struct {
	URLs            []string
	Errors          []string
	Truncated       bool
	SitemapsVisited []string
}

type SitemapParser struct {
	client          *http.Client
	result          *SitemapResult
	sitemapsFetched int
	urlSet          map[string]bool
	urlOrder        []string
}

func NewSitemapParser() *SitemapParser {
	return &SitemapParser{client: &http.Client{Timeout: sitemapTimeout}}
}

// Parse fetches the given sitemap URLs (recursing into indexes) and returns the
// discovered URLs in first-seen order.
func (p *SitemapParser) Parse(sitemapURLs []string) *SitemapResult {
	p.result = &SitemapResult{}
	p.sitemapsFetched = 0
	p.urlSet = make(map[string]bool)
	p.urlOrder = nil

	seen := map[string]bool{}
	for _, u := range sitemapURLs {
		u = strings.TrimSpace(u)
		if u == "" || seen[u] {
			continue
		}
		seen[u] = true
		p.visit(u, 0)
	}
	p.result.URLs = p.urlOrder
	return p.result
}

func (p *SitemapParser) addURL(u string) bool {
	if !p.urlSet[u] {
		p.urlSet[u] = true
		p.urlOrder = append(p.urlOrder, u)
	}
	if len(p.urlSet) >= maxSitemapURLs {
		p.result.Truncated = true
		return false
	}
	return true
}

var (
	httpPrefixRe = regexp.MustCompile(`(?i)^https?://`)
	txtExtRe     = regexp.MustCompile(`(?i)\.txt(\?|$)`)
)

func (p *SitemapParser) visit(url string, depth int) {
	if p.sitemapsFetched >= maxChildSitemaps || len(p.urlSet) >= maxSitemapURLs {
		p.result.Truncated = true
		return
	}
	p.sitemapsFetched++

	body := p.fetch(url)
	if body == nil {
		return
	}
	if len(body) >= 2 && body[0] == 0x1f && body[1] == 0x8b {
		dec, err := gzipDecode(body)
		if err != nil {
			p.result.Errors = append(p.result.Errors, url+" (gzip decode failed)")
			return
		}
		body = dec
	}
	p.result.SitemapsVisited = append(p.result.SitemapsVisited, url)

	if looksLikeText(url, body) {
		for _, line := range regexp.MustCompile(`\r?\n`).Split(string(body), -1) {
			line = strings.TrimSpace(line)
			if line == "" || line[0] == '#' || !httpPrefixRe.MatchString(line) {
				continue
			}
			if !p.addURL(line) {
				return
			}
		}
		return
	}

	root, entries, err := parseSitemapXML(body)
	if err != nil {
		p.result.Errors = append(p.result.Errors, url+" (malformed XML)")
		return
	}
	switch root {
	case "sitemapindex":
		if depth >= maxIndexDepth {
			p.result.Errors = append(p.result.Errors, url+" (max index depth reached)")
			return
		}
		for _, loc := range entries {
			loc = strings.TrimSpace(loc)
			if loc == "" {
				continue
			}
			p.visit(loc, depth+1)
			if len(p.urlSet) >= maxSitemapURLs || p.sitemapsFetched >= maxChildSitemaps {
				return
			}
		}
	case "urlset":
		for _, loc := range entries {
			loc = strings.TrimSpace(loc)
			if loc == "" {
				continue
			}
			if !p.addURL(loc) {
				return
			}
		}
	default:
		p.result.Errors = append(p.result.Errors, url+" (unknown root element <"+root+">)")
	}
}

// parseSitemapXML returns the lowercase root element name and the list of <loc>
// values (from <url> for urlset, <sitemap> for sitemapindex).
func parseSitemapXML(body []byte) (root string, locs []string, err error) {
	dec := xml.NewDecoder(bytes.NewReader(body))
	dec.Strict = false
	var depth int
	var inLoc bool
	var cur strings.Builder
	for {
		tok, e := dec.Token()
		if e == io.EOF {
			break
		}
		if e != nil {
			return "", nil, e
		}
		switch t := tok.(type) {
		case xml.StartElement:
			depth++
			name := strings.ToLower(t.Name.Local)
			if depth == 1 {
				root = name
			}
			if name == "loc" {
				inLoc = true
				cur.Reset()
			}
		case xml.CharData:
			if inLoc {
				cur.Write(t)
			}
		case xml.EndElement:
			depth--
			if strings.ToLower(t.Name.Local) == "loc" && inLoc {
				locs = append(locs, cur.String())
				inLoc = false
			}
		}
	}
	if root == "" {
		return "", nil, io.ErrUnexpectedEOF
	}
	return root, locs, nil
}

func (p *SitemapParser) fetch(url string) []byte {
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		p.result.Errors = append(p.result.Errors, url+" ("+err.Error()+")")
		return nil
	}
	// Same configured UA as the page crawler — no separate sitemap identity.
	ApplyBrowserHeaders(req, UserAgent())
	resp, err := p.client.Do(req)
	if err != nil {
		p.result.Errors = append(p.result.Errors, url+" ("+err.Error()+")")
		return nil
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 400 {
		p.result.Errors = append(p.result.Errors, url+" (HTTP "+itoa(resp.StatusCode)+")")
		return nil
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxBodyBytes+1))
	if err != nil {
		p.result.Errors = append(p.result.Errors, url+" ("+err.Error()+")")
		return nil
	}
	if len(body) > maxBodyBytes {
		p.result.Errors = append(p.result.Errors, url+" (body exceeds max bytes)")
		return nil
	}
	return body
}

func looksLikeText(url string, body []byte) bool {
	if txtExtRe.MatchString(url) {
		return true
	}
	trimmed := bytes.TrimLeft(body, " \t\r\n")
	if len(trimmed) == 0 {
		return false
	}
	return trimmed[0] != '<'
}

func gzipDecode(b []byte) ([]byte, error) {
	zr, err := gzip.NewReader(bytes.NewReader(b))
	if err != nil {
		return nil, err
	}
	defer zr.Close()
	return io.ReadAll(io.LimitReader(zr, maxBodyBytes))
}

func itoa(i int) string {
	if i == 0 {
		return "0"
	}
	neg := i < 0
	if neg {
		i = -i
	}
	var buf [20]byte
	pos := len(buf)
	for i > 0 {
		pos--
		buf[pos] = byte('0' + i%10)
		i /= 10
	}
	if neg {
		pos--
		buf[pos] = '-'
	}
	return string(buf[pos:])
}
