package page

import (
	"regexp"
	"strings"

	"golang.org/x/net/html"
)

// rel2abs is a faithful port of Page::rel2abs (app/Core/Page.php). It resolves a
// (possibly relative) href against a base URL. The exact output string matters:
// the target's CHAR(8) id is crc32(target), so any divergence from PHP changes
// the crawl graph. This mirrors PHP's hand-rolled algorithm, NOT net/url's
// RFC-3986 resolver (which normalizes differently).
func rel2abs(base0, rel0 string) string {
	rel0 = html.UnescapeString(rel0)
	base := phpParseURL(base0)
	rel := phpParseURL(rel0)
	if !rel.ok {
		rel = phpURL{ok: true, has: map[string]bool{"path": true}, path: strings.TrimSpace(rel0)}
	}

	relPath := ""
	if rel.has["path"] {
		relPath = rel.path
	}
	basePath := ""
	if base.has["path"] {
		basePath = base.path
	}

	// if rel has scheme, it has everything
	if rel.has["scheme"] {
		return rel0
	}

	abs := ""
	if base.has["scheme"] {
		abs = base.scheme
	}
	if len(abs) > 0 {
		abs += "://"
	}

	if rel.has["host"] {
		abs += rel.host
		if rel.has["port"] {
			abs += ":" + rel.port
		}
		basePath = ""
	} else if base.has["host"] {
		abs += base.host
		if base.has["port"] {
			abs += ":" + base.port
		}
	}

	// if rel starts with "/", that's it
	if len(relPath) > 0 && relPath[0] == '/' {
		retour := abs + relPath
		if rel.has["query"] {
			retour += "?" + rel.query
		}
		return retour
	}

	// split base path
	var parts []string
	for _, p := range strings.Split(basePath, "/") {
		parts = append(parts, p)
	}
	for len(parts) >= 1 && len(parts[0]) == 0 {
		parts = parts[1:]
	}

	relParts := strings.Split(relPath, "/")
	if len(relParts) > 0 && len(relParts[0]) > 0 && len(parts) > 0 {
		parts = parts[:len(parts)-1]
	}

	addSlash := false
	for _, p := range relParts {
		switch p {
		case "":
			// skip
		case ".":
			addSlash = true
		case "..":
			if len(parts) > 0 {
				parts = parts[:len(parts)-1]
			}
			addSlash = true
		default:
			parts = append(parts, p)
			addSlash = false
		}
	}

	for _, p := range parts {
		abs += "/" + p
	}
	if addSlash {
		abs += "/"
	}
	if rel.has["query"] {
		abs += "?" + rel.query
	}
	if rel.has["fragment"] {
		abs += "#" + rel.fragment
	}
	return abs
}

// phpURL is the subset of PHP parse_url() components rel2abs relies on.
type phpURL struct {
	ok                                        bool
	has                                       map[string]bool
	scheme, host, port, path, query, fragment string
}

var schemeRe = regexp.MustCompile(`^[a-zA-Z][a-zA-Z0-9+\-.]*:`)

// phpParseURL emulates PHP parse_url for the fields rel2abs uses. Parse order:
// fragment (after first '#'), query (after first '?' in remainder), scheme,
// //authority (host[:port]), path. Returns ok=false only for the empty string,
// matching the cases where PHP returns non-array (rel2abs then falls back to a
// path-only component).
func phpParseURL(s string) phpURL {
	u := phpURL{ok: true, has: map[string]bool{}}
	if s == "" {
		// PHP parse_url("") returns [] (array) → path absent; treat as ok with nothing.
		return u
	}

	// fragment
	if i := strings.IndexByte(s, '#'); i >= 0 {
		u.fragment = s[i+1:]
		u.has["fragment"] = true
		s = s[:i]
	}
	// query
	if i := strings.IndexByte(s, '?'); i >= 0 {
		u.query = s[i+1:]
		u.has["query"] = true
		s = s[:i]
	}
	// scheme
	if loc := schemeRe.FindString(s); loc != "" {
		u.scheme = strings.TrimSuffix(loc, ":")
		u.has["scheme"] = true
		s = s[len(loc):]
	}
	// authority
	if strings.HasPrefix(s, "//") {
		s = s[2:]
		end := len(s)
		if i := strings.IndexByte(s, '/'); i >= 0 && i < end {
			end = i
		}
		authority := s[:end]
		s = s[end:]
		// strip userinfo
		if at := strings.LastIndexByte(authority, '@'); at >= 0 {
			authority = authority[at+1:]
		}
		host := authority
		if c := strings.LastIndexByte(authority, ':'); c >= 0 && !strings.Contains(authority[c+1:], "]") {
			host = authority[:c]
			u.port = authority[c+1:]
			if u.port != "" {
				u.has["port"] = true
			}
		}
		if host != "" {
			u.host = host
			u.has["host"] = true
		}
	}
	// path
	if s != "" {
		u.path = s
		u.has["path"] = true
	}
	return u
}
