package page

import (
	"strconv"
	"strings"

	"github.com/antchfx/htmlquery"
	"golang.org/x/net/html"
)

// rawLink mirrors one row of HtmlParser::extractLinksWithPosition before the
// Page::parse() normalization (fragment strip, rel2abs, scoping).
type rawLink struct {
	target   string // raw href
	anchor   string
	rel      string
	xpath    string
	position string
}

// extractRawLinks ports HtmlParser::extractLinksWithPosition: every <a>, with its
// enriched XPath and semantic position. Never panics on a single bad link.
func extractRawLinks(doc *html.Node) []rawLink {
	var out []rawLink
	for _, n := range htmlquery.Find(doc, "//a") {
		xp := buildEnrichedXPath(n)
		out = append(out, rawLink{
			target:   htmlquery.SelectAttr(n, "href"),
			anchor:   textContent(n),
			rel:      htmlquery.SelectAttr(n, "rel"),
			xpath:    xp,
			position: classifyLinkPosition(xp),
		})
	}
	return out
}

// buildEnrichedXPath ports HtmlParser::buildEnrichedXPath: absolute path with
// per-tag sibling index and [@id=…]/[@class=…] of ancestors.
func buildEnrichedXPath(node *html.Node) string {
	var segments []string
	for cur := node; cur != nil && cur.Type == html.ElementNode; cur = cur.Parent {
		tag := cur.Data
		index := 1
		for sib := cur.PrevSibling; sib != nil; sib = sib.PrevSibling {
			if sib.Type == html.ElementNode && sib.Data == tag {
				index++
			}
		}
		extras := ""
		if id := attr(cur, "id"); id != "" {
			extras += `[@id="` + strings.ReplaceAll(id, `"`, "") + `"]`
		}
		if class := attr(cur, "class"); class != "" {
			class = strings.ReplaceAll(class, `"`, "")
			if len(class) > 200 {
				class = class[:200]
			}
			extras += `[@class="` + class + `"]`
		}
		seg := tag + "[" + strconv.Itoa(index) + "]" + extras
		segments = append([]string{seg}, segments...)
	}
	return "/" + strings.Join(segments, "/")
}

// classifyLinkPosition ports HtmlParser::classifyLinkPosition (order matters:
// Navigation wins over Header).
func classifyLinkPosition(xpath string) string {
	l := strings.ToLower(xpath)
	switch {
	case strings.Contains(l, "nav") || strings.Contains(l, "menu"):
		return "Navigation"
	case strings.Contains(l, "header"):
		return "Header"
	case strings.Contains(l, "footer"):
		return "Footer"
	case strings.Contains(l, "aside"):
		return "Aside"
	default:
		return "Content"
	}
}

func attr(n *html.Node, key string) string {
	if n.Type != html.ElementNode {
		return ""
	}
	for _, a := range n.Attr {
		if a.Key == key {
			return a.Val
		}
	}
	return ""
}

// textContent returns the concatenated descendant text (DOM textContent).
func textContent(n *html.Node) string {
	var sb strings.Builder
	var walk func(*html.Node)
	walk = func(node *html.Node) {
		if node.Type == html.TextNode {
			sb.WriteString(node.Data)
		}
		for c := node.FirstChild; c != nil; c = c.NextSibling {
			walk(c)
		}
	}
	walk(n)
	return sb.String()
}
