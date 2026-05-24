package analysis

import (
	"io"
	"net/http"
	"regexp"
	"strings"
	"sync"
	"time"
)

// Robots ports app/Analysis/RobotsTxt.php: per-host fetch + cache of robots.txt
// and the allow/deny matcher (User-agent groups, Allow/Disallow, `*` and `$`).
// The cache is process-wide and safe for the multi-crawl worker (robots.txt is a
// property of the host, identical across crawls).
type Robots struct {
	mu     sync.Mutex
	cache  map[string]string // base ("https://host") → robots.txt body
	client *http.Client
}

var defaultRobots = NewRobots()

// NewRobots builds a Robots cache with a Googlebot-style fetcher (120s timeout,
// SSRF-validated, http(s) only — like RobotsTxt::get_file).
func NewRobots() *Robots {
	return &Robots{
		cache:  make(map[string]string),
		client: &http.Client{Timeout: 120 * time.Second},
	}
}

var (
	reBase = regexp.MustCompile(`(?i)(https?://[^/]+)`)
	rePath = regexp.MustCompile(`(?i)https?://[^/]+(.*)$`)
)

// Allowed reports whether url may be crawled, using the default process cache.
func Allowed(url string) bool { return defaultRobots.Allowed(url) }

// Allowed mirrors RobotsTxt::robots_allowed($url) with the default agents
// ["*","Googlebot","googlebot"].
func (r *Robots) Allowed(url string) bool {
	path := first(rePath, url)
	base := first(reBase, url)
	body := r.get(base)

	body = strings.ReplaceAll(body, "\r", "")
	lines := strings.Split(body, "\n")

	agents := map[string]bool{"*": true, "Googlebot": true, "googlebot": true}
	active := false
	type rule struct {
		kind string // "allow" | "disallow"
		re   *regexp.Regexp
	}
	var rules []rule

	uaRe := regexp.MustCompile(`(?i)^user-agent:(.*)$`)
	disRe := regexp.MustCompile(`(?i)^disallow:(.*)$`)
	allowRe := regexp.MustCompile(`(?i)^allow:(.*)$`)

	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if m := uaRe.FindStringSubmatch(line); m != nil {
			active = agents[strings.TrimSpace(m[1])]
			continue
		}
		if active {
			if m := disRe.FindStringSubmatch(line); m != nil && strings.TrimSpace(m[1]) != "" {
				if re := buildRobotsRegex(strings.TrimSpace(m[1])); re != nil {
					rules = append(rules, rule{"disallow", re})
				}
				continue
			}
			if m := allowRe.FindStringSubmatch(line); m != nil && strings.TrimSpace(m[1]) != "" {
				if re := buildRobotsRegex(strings.TrimSpace(m[1])); re != nil {
					rules = append(rules, rule{"allow", re})
				}
			}
		}
	}

	allow := true
	for _, ru := range rules {
		if ru.re.MatchString(path) {
			allow = ru.kind == "allow"
		}
	}
	return allow
}

// buildRobotsRegex reproduces the escaping pipeline of RobotsTxt::robots_allowed:
// strip a trailing `$` anchor, escape regex metacharacters, turn `*`→`.*`, anchor
// at start, and append `.*$` unless an explicit end-anchor was present.
func buildRobotsRegex(rawRule string) *regexp.Regexp {
	rule := rawRule
	hasEndAnchor := strings.HasSuffix(rule, "$")
	if hasEndAnchor {
		rule = rule[:len(rule)-1]
	}
	repl := strings.NewReplacer(
		`\`, `\\`,
		`.`, `\.`,
		`|`, `\|`,
		`?`, `\?`,
		`[`, `\[`,
		`]`, `\]`,
		`+`, `\+`,
		`(`, `\(`,
		`)`, `\)`,
		`{`, `\{`,
		`}`, `\}`,
		`^`, `\^`,
		`$`, `\$`,
	)
	rule = repl.Replace(rule)
	rule = strings.ReplaceAll(rule, "*", ".*")
	rule = "^" + rule
	if hasEndAnchor {
		rule += "$"
	} else {
		rule += ".*$"
	}
	re, err := regexp.Compile(rule)
	if err != nil {
		return nil
	}
	return re
}

// robotsCacheCap bounds the per-process robots.txt cache so a long-running
// multi-crawl worker doesn't accumulate one entry per host forever. Eviction is
// arbitrary (a re-fetch later is cheap and correct).
const robotsCacheCap = 20000

func (r *Robots) get(base string) string {
	r.mu.Lock()
	if v, ok := r.cache[base]; ok {
		r.mu.Unlock()
		return v
	}
	r.mu.Unlock()

	body := r.fetch(base + "/robots.txt")

	r.mu.Lock()
	if len(r.cache) >= robotsCacheCap {
		for k := range r.cache { // Go randomizes map range order -> arbitrary eviction
			delete(r.cache, k)
			break
		}
	}
	r.cache[base] = body
	r.mu.Unlock()
	return body
}

func (r *Robots) fetch(url string) string {
	if err := ValidateURL(url); err != nil {
		return ""
	}
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return ""
	}
	req.Header.Set("User-Agent", "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)")
	resp, err := r.client.Do(req)
	if err != nil {
		return ""
	}
	defer resp.Body.Close()
	b, err := io.ReadAll(io.LimitReader(resp.Body, 5<<20))
	if err != nil {
		return ""
	}
	return string(b)
}

func first(re *regexp.Regexp, s string) string {
	m := re.FindStringSubmatch(s)
	if m == nil {
		return ""
	}
	if len(m) > 1 {
		return m[1]
	}
	return m[0]
}
