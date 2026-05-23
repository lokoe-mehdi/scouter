// Package config maps the persisted crawls.config JSONB into the runtime Config
// the crawler reads, reproducing the general/advanced→runtime mapping done by
// Cmder::crawl (app/Cli/Cmder.php).
package config

import (
	"encoding/json"
	"os"
	"strconv"
)

const defaultUserAgent = "Scouter/0.6 (Crawler developed by Lokoe SASU; +https://lokoe.fr/scouter-crawler)"

// HTTPAuth mirrors the runtime httpAuth structure.
type HTTPAuth struct {
	Enabled  bool
	Username string
	Password string
}

// Config is the fully-resolved runtime config consumed by the crawl engine.
type Config struct {
	Start    string
	DepthMax int
	Domains  []string
	URLList  []string

	CrawlSpeed  string // very_slow|slow|fast|unlimited
	CrawlMode   string // classic|javascript
	CrawlType   string // spider|list
	UserAgent   string
	StealthMode string // off|auto|always — uTLS browser-fingerprint to bypass anti-bot

	RespectRobots    bool
	RespectNofollow  bool
	RespectCanonical bool
	FollowRedirects  bool
	RetryFailedURLs  bool
	StoreHTML        bool

	CustomHeaders   map[string]string
	HTTPAuth        HTTPAuth
	XPathExtractors map[string]string
	RegexExtractors map[string]string
	SitemapURLs     []string

	MaxConcurrentCurl   int
	MaxConcurrentChrome int
}

// rawConfig matches the persisted JSON shape: {"general":{…},"advanced":{…}}.
type rawConfig struct {
	General  map[string]json.RawMessage `json:"general"`
	Advanced map[string]json.RawMessage `json:"advanced"`
}

// Load parses the JSONB config and applies the same defaults + env overrides as
// Cmder::crawl. depthMaxCol/crawlTypeCol come from the crawls row (authoritative).
func Load(raw []byte, depthMaxCol int, crawlTypeCol string) (*Config, error) {
	var rc rawConfig
	if len(raw) > 0 {
		if err := json.Unmarshal(raw, &rc); err != nil {
			return nil, err
		}
	}
	g := lookup(rc.General)
	a := lookup(rc.Advanced)

	c := &Config{
		Start:       g.str("start", ""),
		Domains:     g.strSlice("domains"),
		URLList:     g.strSlice("url_list"),
		CrawlSpeed:  g.str("crawl_speed", "fast"),
		CrawlMode:   g.str("crawl_mode", "classic"),
		UserAgent:   g.str("user-agent", defaultUserAgent),
		StealthMode: a.str("stealth_mode", "off"),

		RespectRobots:    a.boolDef("respect_robots", true),
		RespectNofollow:  a.boolDef("respect_nofollow", false), // matches Cmder default
		RespectCanonical: a.boolDef("respect_canonical", true),
		FollowRedirects:  a.boolDef("follow_redirects", true),
		RetryFailedURLs:  a.boolDef("retry_failed_urls", true),
		StoreHTML:        a.boolDef("store_html", true),

		CustomHeaders:   a.strMap("custom_headers"),
		XPathExtractors: a.strMap("xPathExtractors"),
		RegexExtractors: a.strMap("regexExtractors"),
		SitemapURLs:     a.strSlice("sitemap_urls"),
	}

	// crawl_type: crawls column wins, else general, else spider
	c.CrawlType = crawlTypeCol
	if c.CrawlType == "" {
		c.CrawlType = g.str("crawl_type", "spider")
	}
	// depth_max: crawls column wins, else general.depthMax, else 5
	c.DepthMax = depthMaxCol
	if c.DepthMax == 0 {
		c.DepthMax = g.intDef("depthMax", 5)
	}

	// http_auth → runtime httpAuth
	if auth := a.strMap("http_auth"); auth != nil {
		c.HTTPAuth = HTTPAuth{
			Enabled:  auth["username"] != "",
			Username: auth["username"],
			Password: auth["password"],
		}
	}

	// env overrides (injected by worker)
	if v := envInt("MAX_CONCURRENT_CURL"); v > 0 {
		c.MaxConcurrentCurl = v
	}
	if v := envInt("MAX_CONCURRENT_CHROME"); v > 0 {
		c.MaxConcurrentChrome = v
	}
	return c, nil
}

func envInt(key string) int {
	if v, err := strconv.Atoi(os.Getenv(key)); err == nil {
		return v
	}
	return 0
}

// section is a typed accessor over one JSON branch.
type section map[string]json.RawMessage

func lookup(m map[string]json.RawMessage) section {
	if m == nil {
		return section{}
	}
	return section(m)
}

func (s section) str(key, def string) string {
	if v, ok := s[key]; ok {
		var out string
		if json.Unmarshal(v, &out) == nil && out != "" {
			return out
		}
	}
	return def
}

func (s section) boolDef(key string, def bool) bool {
	if v, ok := s[key]; ok {
		var out bool
		if json.Unmarshal(v, &out) == nil {
			return out
		}
	}
	return def
}

func (s section) intDef(key string, def int) int {
	if v, ok := s[key]; ok {
		var out int
		if json.Unmarshal(v, &out) == nil {
			return out
		}
	}
	return def
}

func (s section) strSlice(key string) []string {
	if v, ok := s[key]; ok {
		var out []string
		if json.Unmarshal(v, &out) == nil {
			return out
		}
	}
	return nil
}

func (s section) strMap(key string) map[string]string {
	if v, ok := s[key]; ok {
		out := map[string]string{}
		if json.Unmarshal(v, &out) == nil {
			return out
		}
	}
	return nil
}
