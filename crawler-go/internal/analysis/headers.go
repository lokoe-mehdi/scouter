package analysis

import (
	"net/http"
	"regexp"
	"strings"
	"sync"
)

// headers.go centralises the request headers the crawler sends so that, over the
// uTLS Chrome TLS fingerprint, the HTTP layer also looks like a real Chrome
// navigation instead of a bare two-header bot request (the tell that gets a crawl
// 403'd on day one by anti-bot stacks like DataDome).
//
// Hard rule: the configured crawl User-Agent is used VERBATIM on every request —
// page fetch, robots.txt, sitemap. We never substitute another UA. The Client
// Hints we add are DERIVED from that UA so they stay consistent with it.

// fallbackUserAgent is the honest Scouter identity, used only if the worker never
// called SetUserAgent (it always does before a crawl). It is never an
// impersonation: it matches the config package's defaultUserAgent.
const fallbackUserAgent = "Scouter/0.7 (Crawler developed by Lokoe SASU; +https://lokoe.fr/scouter-crawler)"

// DefaultAcceptLanguage is sent when the crawl doesn't override Accept-Language
// via custom headers. Realistic for the FR-first audience; overridable per crawl.
const DefaultAcceptLanguage = "fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7"

var (
	uaMu     sync.RWMutex
	configUA = fallbackUserAgent
)

// SetUserAgent records the crawl's configured UA so the package-internal fetchers
// (robots.txt, sitemap) send the exact same UA as the page crawler. Called once by
// the worker at startup from the resolved crawl config.
func SetUserAgent(ua string) {
	if strings.TrimSpace(ua) == "" {
		return
	}
	uaMu.Lock()
	configUA = ua
	uaMu.Unlock()
}

// UserAgent returns the configured crawl UA (or the honest fallback).
func UserAgent() string {
	uaMu.RLock()
	defer uaMu.RUnlock()
	return configUA
}

var chromeMajorRe = regexp.MustCompile(`(?i)(?:Chrome|Chromium|CriOS|Edg)/(\d+)`)

// ApplyBrowserHeaders sets, on req, the header set a real Chrome top-level
// navigation sends, using ua VERBATIM as the User-Agent. Client-Hint headers
// (sec-ch-ua*) are only added for Chromium-family UAs — Firefox/Safari don't send
// them, so adding them on a non-Chromium UA would itself be a fingerprint tell.
// Caller-supplied custom headers should be applied AFTER this (they may tune
// anything), but the UA must be re-asserted last so the configured UA always wins.
func ApplyBrowserHeaders(req *http.Request, ua string) {
	if strings.TrimSpace(ua) == "" {
		ua = UserAgent()
	}
	h := req.Header
	h.Set("User-Agent", ua)
	h.Set("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7")
	h.Set("Accept-Language", DefaultAcceptLanguage)
	// Only encodings we can actually decode (see decompressBody): gzip/deflate/br.
	h.Set("Accept-Encoding", "gzip, deflate, br")
	h.Set("Upgrade-Insecure-Requests", "1")
	// Fetch metadata for a user-initiated top-level navigation.
	h.Set("Sec-Fetch-Dest", "document")
	h.Set("Sec-Fetch-Mode", "navigate")
	h.Set("Sec-Fetch-Site", "none")
	h.Set("Sec-Fetch-User", "?1")

	if major := chromeMajor(ua); major != "" {
		h.Set("sec-ch-ua", secCHUA(major))
		h.Set("sec-ch-ua-mobile", chMobile(ua))
		h.Set("sec-ch-ua-platform", chPlatform(ua))
	}
}

// chromeMajor returns the Chromium-family major version in ua, or "".
func chromeMajor(ua string) string {
	if m := chromeMajorRe.FindStringSubmatch(ua); m != nil {
		return m[1]
	}
	return ""
}

// secCHUA builds the low-entropy sec-ch-ua brand list for a Chrome major version,
// mirroring the GREASE-padded format Chrome emits.
func secCHUA(major string) string {
	return `"Chromium";v="` + major + `", "Google Chrome";v="` + major + `", "Not_A Brand";v="99"`
}

func chMobile(ua string) string {
	if strings.Contains(ua, "Mobile") || strings.Contains(ua, "Android") || strings.Contains(ua, "iPhone") {
		return "?1"
	}
	return "?0"
}

func chPlatform(ua string) string {
	switch {
	case strings.Contains(ua, "Windows"):
		return `"Windows"`
	case strings.Contains(ua, "Android"):
		return `"Android"`
	case strings.Contains(ua, "CrOS"):
		return `"Chrome OS"`
	case strings.Contains(ua, "Mac OS X") || strings.Contains(ua, "Macintosh"):
		return `"macOS"`
	case strings.Contains(ua, "iPhone") || strings.Contains(ua, "iPad"):
		return `"iOS"`
	case strings.Contains(ua, "Linux") || strings.Contains(ua, "X11"):
		return `"Linux"`
	default:
		return `"Windows"`
	}
}
