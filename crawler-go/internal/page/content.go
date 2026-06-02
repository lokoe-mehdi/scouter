package page

import (
	"net/url"
	"regexp"
	"strings"

	"scouter-crawler/internal/analysis"

	readability "github.com/go-shiori/go-readability"
	"golang.org/x/net/html"
)

// content.go ports Page::calculateWordCount and Page::computeSimhash. Per the
// locked decision, go-readability replaces fivefilters/readability.php, so word
// counts / simhash may drift slightly vs PHP crawls (documented, acceptable).

var (
	commentRe   = regexp.MustCompile(`(?s)<!--.*?-->`)
	scriptRe    = regexp.MustCompile(`(?is)<script[^>]*>.*?</script>`)
	styleRe     = regexp.MustCompile(`(?is)<style[^>]*>.*?</style>`)
	noscriptRe  = regexp.MustCompile(`(?is)<noscript[^>]*>.*?</noscript>`)
	h1Re        = regexp.MustCompile(`(?is)<h1[^>]*>(.*?)</h1>`)
	mainRe      = regexp.MustCompile(`(?is)<main[^>]*>(.*?)</main>`)
	bodyRe      = regexp.MustCompile(`(?is)<body[^>]*>(.*?)</body>`)
	boilerRe    = regexp.MustCompile(`(?is)<(nav|header|footer|aside|form)[^>]*>.*?</(nav|header|footer|aside|form)>`)
	tagRe       = regexp.MustCompile(`(?s)<[^>]*>`)
	spaceRe     = regexp.MustCompile(`\s+`)
	wordCountRe = regexp.MustCompile(`[\p{L}]+(?:[-'][\p{L}]+)*`)
)

func readabilityText(dom string, pageURL string) (string, bool) {
	cleaned := commentRe.ReplaceAllString(dom, "")
	u, _ := url.Parse(pageURL)
	if u == nil {
		u = &url.URL{}
	}
	art, err := readability.FromReader(strings.NewReader(cleaned), u)
	if err != nil {
		return "", false
	}
	return art.TextContent, true
}

// calculateWordCount mirrors Page::calculateWordCount.
func calculateWordCount(dom, pageURL string) int {
	if dom == "" {
		return 0
	}
	text, ok := readabilityText(dom, pageURL)
	if !ok || strings.TrimSpace(text) == "" {
		return 0
	}
	text = html.UnescapeString(text)
	text = strings.TrimSpace(spaceRe.ReplaceAllString(text, " "))
	if text == "" {
		return 0
	}
	return len(wordCountRe.FindAllString(text, -1))
}

// computeSimhash mirrors Page::computeSimhash (readability when ≥200 words, else
// a main/body fallback with boilerplate stripped, H1 forced to the front).
func computeSimhash(dom, pageURL string) *int64 {
	if dom == "" {
		return nil
	}
	h1Text := ""
	if m := h1Re.FindStringSubmatch(dom); m != nil {
		h1Text = strings.TrimSpace(tagRe.ReplaceAllString(m[1], ""))
	}

	var content string
	useReadability := false
	if text, ok := readabilityText(dom, pageURL); ok && text != "" {
		if len(wordCountRe.FindAllString(text, -1)) >= 200 {
			content = text
			useReadability = true
		}
	}

	if !useReadability {
		switch {
		case mainRe.MatchString(dom):
			content = mainRe.FindStringSubmatch(dom)[1]
		case bodyRe.MatchString(dom):
			content = bodyRe.FindStringSubmatch(dom)[1]
		default:
			content = dom
		}
		content = boilerRe.ReplaceAllString(content, "")
		content = extractVisibleText(content)
	}

	if h1Text != "" {
		content = h1Text + " " + content
	}
	if strings.TrimSpace(content) == "" {
		return nil
	}
	return analysis.ComputeSimhash(content)
}

func extractVisibleText(htmlStr string) string {
	htmlStr = scriptRe.ReplaceAllString(htmlStr, "")
	htmlStr = styleRe.ReplaceAllString(htmlStr, "")
	htmlStr = noscriptRe.ReplaceAllString(htmlStr, "")
	htmlStr = commentRe.ReplaceAllString(htmlStr, "")
	text := tagRe.ReplaceAllString(htmlStr, "")
	return html.UnescapeString(text)
}
