package page

import (
	"bytes"
	"compress/flate"
	"crypto/sha1"
	"encoding/base64"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"scouter-crawler/internal/analysis"
	"scouter-crawler/internal/model"

	"github.com/antchfx/htmlquery"
	"github.com/antchfx/xpath"
	"golang.org/x/net/html"
	"golang.org/x/net/html/charset"
)

// ParseConfig is the subset of crawl config the parser needs. Maps preserve no
// particular order; extractor results land in pages.extracts (JSONB) where order
// is irrelevant.
type ParseConfig struct {
	XPathExtractors map[string]string
	RegexExtractors map[string]string
	RespectRobots   bool
	LinkBlacklist   []string // mailto:/javascript:/tel: by default
	Pattern         []string // allowed domains (for external detection)
	Robots          *analysis.Robots
}

var linkBlacklist = []string{"mailto:", "javascript:", "tel:"}

var (
	bareHostRe = regexp.MustCompile(`^https?://[^/]+$`)
	httpRe     = regexp.MustCompile(`(?i)^https?://`)
)

// Parse turns a fetched response into a model.Page, reproducing the behaviour of
// PHP's Page class (constructor + getPage). `body` is the raw response body.
func Parse(rawURL string, headers model.Headers, body string, cfg ParseConfig) model.Page {
	domain := firstSubmatch(`(?i)https?://([^/?]+)`, rawURL)

	isHTMLLike := containsTags(body) && strings.Contains(strings.ToLower(headers.ContentType), "html")

	var dom string
	var doc *html.Node
	base := rawURL
	if isHTMLLike {
		dom = decodeAndNormalize(body, headers.ContentType)
		if d, err := html.Parse(strings.NewReader(dom)); err == nil {
			doc = d
			if b := htmlquery.FindOne(doc, "//base/@href"); b != nil {
				if v := htmlquery.SelectAttr(b, "href"); v != "" {
					base = v
				}
			}
		}
	}

	p := model.Page{
		ID:           analysis.PageID(rawURL),
		URL:          rawURL,
		Domain:       domain,
		DomainID:     analysis.PageID(domain),
		HTTPCode:     headers.HTTPCode,
		RedirectTo:   headers.RedirectURL,
		ResponseTime: headers.ResponseTime,
		Size:         headers.Size,
		ContentType:  headers.ContentType,
		Canonical:    true,
	}

	p.IsHTML = detectIsHTML(rawURL, headers.ContentType, dom)
	if p.IsHTML {
		p.Simhash = computeSimhash(dom, rawURL)
	}

	// Full parse only for real HTML 200s (Page::getPage first branch).
	if doc != nil && containsTags(dom) && strings.Contains(strings.ToLower(headers.ContentType), "html") && headers.HTTPCode == 200 {
		title, h1, meta, canonical, custom := extractAll(doc, dom, base, cfg)
		p.Title = title
		p.H1 = h1
		p.MetaDesc = meta
		p.CanonicalURL = canonical
		p.CustomExtract = custom

		nofollow, noindex, isCanonical := configuration(doc, canonical, rawURL, headers.XRobotsTag)
		p.Nofollow = nofollow
		p.Noindex = noindex
		p.Canonical = isCanonical

		p.Schemas = extractSchemaTypes(doc, dom)
		p.WordCount = calculateWordCount(dom, rawURL)
		p.H1Multiple, p.HeadingsMissing = analyzeHeadings(doc)

		sum := sha1.Sum([]byte(dom))
		p.DomHash = fmt.Sprintf("%x", sum)
		p.DomZip = zipDom(dom)

		p.Links = buildLinks(extractRawLinks(doc), base, cfg)
	} else {
		// Minimal page (non-HTML / non-200): defaults already set.
		p.Canonical = true
	}
	return p
}

// extractAll ports Page::extract for the four builtins + the custom extractors.
func extractAll(doc *html.Node, dom, base string, cfg ParseConfig) (title, h1, meta, canonical string, custom map[string]string) {
	title = nodeText(htmlquery.FindOne(doc, "//title"))
	meta = attrVal(htmlquery.FindOne(doc, `//meta[@name="description"]/@content`), "content")
	h1 = nodeText(htmlquery.FindOne(doc, "//h1"))
	canonical = attrVal(htmlquery.FindOne(doc, `//link[@rel="canonical"]/@href`), "href")
	if strings.TrimSpace(canonical) != "" {
		canonical = rel2abs(base, canonical)
		if bareHostRe.MatchString(canonical) {
			canonical += "/"
		}
	}

	custom = map[string]string{}
	for key, xp := range cfg.XPathExtractors {
		custom[key] = evalXPathExtractor(doc, xp)
	}
	for key, rx := range cfg.RegexExtractors {
		custom[key] = evalRegexExtractor(dom, rx)
	}
	return
}

// configuration ports Page::configuration + robotsTag.
func configuration(doc *html.Node, canonical, url, xRobotsTag string) (nofollow, noindex, isCanonical bool) {
	isCanonical = true
	if strings.TrimSpace(canonical) != "" && canonical != url {
		isCanonical = false
	}
	robotsContent := strings.ToLower(attrVal(htmlquery.FindOne(doc, `//meta[@name="robots"]/@content`), "content"))
	noindex = strings.Contains(robotsContent, "noindex")
	nofollow = strings.Contains(robotsContent, "nofollow")
	xr := strings.ToLower(xRobotsTag)
	if strings.Contains(xr, "nofollow") {
		nofollow = true
	}
	if strings.Contains(xr, "noindex") {
		noindex = true
	}
	return
}

// buildLinks ports Page::parse: normalize each raw <a> into a model.Link.
func buildLinks(raw []rawLink, base string, cfg ParseConfig) []model.Link {
	var out []model.Link
	for _, rl := range raw {
		target := rl.target
		if i := strings.IndexByte(target, '#'); i >= 0 {
			target = target[:i]
		}
		if target != "" && !httpRe.MatchString(target) {
			target = rel2abs(base, target)
		}
		if !checkLink(target) {
			continue
		}
		external := isExternal(target, cfg.Pattern)
		blocked := false
		if !external && cfg.RespectRobots {
			if cfg.Robots != nil {
				blocked = !cfg.Robots.Allowed(target)
			} else {
				blocked = !analysis.Allowed(target)
			}
		}
		if bareHostRe.MatchString(target) {
			target += "/"
		}
		out = append(out, model.Link{
			Target:   target,
			TargetID: analysis.PageID(target),
			External: external,
			Anchor:   rl.anchor,
			Nofollow: strings.Contains(strings.ToLower(rl.rel), "nofollow"),
			Blocked:  blocked,
			XPath:    rl.xpath,
			Position: rl.position,
		})
	}
	return out
}

func checkLink(url string) bool {
	url = strings.TrimSpace(url)
	if url == "" {
		return false
	}
	low := strings.ToLower(url)
	for _, b := range linkBlacklist {
		if strings.Contains(low, b) {
			return false
		}
	}
	return true
}

// isExternal ports Page::isExternal: a URL is internal if it matches one of the
// allowed domain patterns (where `.`→`\.` and `*`→`[^.]*`).
func isExternal(url string, pattern []string) bool {
	for _, domain := range pattern {
		d := strings.ReplaceAll(domain, ".", `\.`)
		d = strings.ReplaceAll(d, "*", `[^.]*`)
		if re, err := regexp.Compile(`^https?://` + d); err == nil && re.MatchString(strings.TrimSpace(url)) {
			return false
		}
	}
	return true
}

// analyzeHeadings ports Page::analyzeHeadings (h1_multiple + headings_missing).
func analyzeHeadings(doc *html.Node) (h1Multiple, headingsMissing bool) {
	headings := htmlquery.Find(doc, "//h1|//h2|//h3|//h4|//h5|//h6")
	if len(headings) == 0 {
		return false, false
	}
	h1Count := 0
	levels := make([]int, 0, len(headings))
	for _, h := range headings {
		level, _ := strconv.Atoi(strings.ToLower(h.Data)[1:])
		if level == 1 {
			h1Count++
		}
		levels = append(levels, level)
	}
	if h1Count > 1 {
		h1Multiple = true
	}
	prev := 0
	for i, level := range levels {
		if i == 0 && level > 1 {
			headingsMissing = true
			break
		}
		if prev > 0 && level > prev+1 {
			headingsMissing = true
			break
		}
		prev = level
	}
	return
}

// --- helpers ---

func decodeAndNormalize(body, contentType string) string {
	r, err := charset.NewReader(strings.NewReader(body), contentType)
	dom := body
	if err == nil {
		var buf bytes.Buffer
		if _, err := buf.ReadFrom(r); err == nil {
			dom = buf.String()
		}
	}
	// Page::encode: collapse \r\n and \n to spaces before further processing.
	dom = strings.ReplaceAll(dom, "\r\n", " ")
	dom = strings.ReplaceAll(dom, "\n", " ")
	// charset.NewReader passes UTF-8 (and mis-detected) bodies through without
	// validating, so a single bad byte — a truncated multibyte char, a wrong
	// charset guess, or a body that never decoded (e.g. a still-compressed
	// response) — would survive into title/h1/meta/links and make Postgres reject
	// the whole page with "invalid byte sequence for encoding UTF8" (22021),
	// silently dropping it. Coerce to valid UTF-8 here, the single chokepoint all
	// text fields derive from. (PHP/MySQL tolerated this; PG/UTF8 does not.)
	if !utf8.ValidString(dom) {
		dom = strings.ToValidUTF8(dom, "�")
	}
	return dom
}

func zipDom(dom string) string {
	var buf bytes.Buffer
	w, _ := flate.NewWriter(&buf, flate.DefaultCompression)
	_, _ = w.Write([]byte(dom))
	_ = w.Close()
	return base64.StdEncoding.EncodeToString(buf.Bytes())
}

func containsTags(s string) bool {
	return tagRe.MatchString(s)
}

func nodeText(n *html.Node) string {
	if n == nil {
		return ""
	}
	if n.Type == html.ElementNode {
		return textContent(n)
	}
	return n.Data
}

func attrVal(n *html.Node, key string) string {
	if n == nil {
		return ""
	}
	// htmlquery attribute selects (…/@x) return an attribute node whose
	// FirstChild text holds the value.
	if n.Type == html.TextNode {
		return n.Data
	}
	if n.FirstChild != nil && n.FirstChild.Type == html.TextNode {
		return n.FirstChild.Data
	}
	return attr(n, key)
}

func firstSubmatch(pattern, s string) string {
	re := regexp.MustCompile(pattern)
	m := re.FindStringSubmatch(s)
	if len(m) > 1 {
		return m[1]
	}
	return ""
}

// evalXPathExtractor ports the XPath-2.0-flavoured custom extractor handling in
// Page::extract (replace/lower-case/upper-case/matches/ends-with/tokenize).
func evalXPathExtractor(doc *html.Node, expr string) string {
	clean := expr
	type pp struct {
		kind, a, b string
		idx        int
	}
	var post *pp
	if m := reReplace.FindStringSubmatch(expr); m != nil {
		clean, post = m[1], &pp{kind: "replace", a: m[2], b: m[3]}
	} else if m := reLower.FindStringSubmatch(expr); m != nil {
		clean, post = m[1], &pp{kind: "lower"}
	} else if m := reUpper.FindStringSubmatch(expr); m != nil {
		clean, post = m[1], &pp{kind: "upper"}
	} else if m := reMatches.FindStringSubmatch(expr); m != nil {
		clean, post = m[1], &pp{kind: "matches", a: m[2]}
	} else if m := reEndsWith.FindStringSubmatch(expr); m != nil {
		clean, post = m[1], &pp{kind: "ends-with", a: m[2]}
	} else if m := reTokenize.FindStringSubmatch(expr); m != nil {
		idx, _ := strconv.Atoi(m[3])
		clean, post = m[1], &pp{kind: "tokenize", a: m[2], idx: idx}
	}

	val := evalXPathValue(doc, clean)
	if post != nil {
		switch post.kind {
		case "replace":
			if re, err := regexp.Compile(post.a); err == nil {
				val = re.ReplaceAllString(val, post.b)
			}
		case "lower":
			val = strings.ToLower(val)
		case "upper":
			val = strings.ToUpper(val)
		case "matches":
			val = boolStr(regexpMatch(post.a, val))
		case "ends-with":
			val = boolStr(strings.HasSuffix(val, post.a))
		case "tokenize":
			parts := splitRegex(post.a, val)
			i := post.idx - 1
			if i >= 0 && i < len(parts) {
				val = parts[i]
			} else {
				val = ""
			}
		}
	}
	return val
}

func evalXPathValue(doc *html.Node, expr string) string {
	exp, err := xpath.Compile(expr)
	if err != nil {
		return ""
	}
	nav := htmlquery.CreateXPathNavigator(doc)
	switch v := exp.Evaluate(nav).(type) {
	case float64:
		return strconv.FormatFloat(v, 'f', -1, 64)
	case bool:
		return boolStr(v)
	case string:
		return v
	case *xpath.NodeIterator:
		if v.MoveNext() {
			return v.Current().Value()
		}
	}
	return ""
}

func evalRegexExtractor(dom, rx string) string {
	re, err := regexp.Compile("(?is)" + rx)
	if err != nil {
		return ""
	}
	m := re.FindStringSubmatch(dom)
	if len(m) > 1 {
		return m[1]
	}
	return ""
}

var (
	reReplace  = regexp.MustCompile(`(?i)^replace\s*\(\s*(.+?)\s*,\s*['"](.+?)['"]\s*,\s*['"](.*)['"]\s*\)$`)
	reLower    = regexp.MustCompile(`(?i)^lower-case\s*\(\s*(.+?)\s*\)$`)
	reUpper    = regexp.MustCompile(`(?i)^upper-case\s*\(\s*(.+?)\s*\)$`)
	reMatches  = regexp.MustCompile(`(?i)^matches\s*\(\s*(.+?)\s*,\s*['"](.+?)['"]\s*\)$`)
	reEndsWith = regexp.MustCompile(`(?i)^ends-with\s*\(\s*(.+?)\s*,\s*['"](.+?)['"]\s*\)$`)
	reTokenize = regexp.MustCompile(`(?i)^tokenize\s*\(\s*(.+?)\s*,\s*['"](.+?)['"]\s*\)\s*\[(\d+)\]$`)
)

func boolStr(b bool) string {
	if b {
		return "true"
	}
	return "false"
}

func regexpMatch(pattern, s string) bool {
	re, err := regexp.Compile(pattern)
	return err == nil && re.MatchString(s)
}

func splitRegex(pattern, s string) []string {
	re, err := regexp.Compile(pattern)
	if err != nil {
		return []string{s}
	}
	return re.Split(s, -1)
}
