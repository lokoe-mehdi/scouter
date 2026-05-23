package config

import (
	"os"
	"testing"
)

// TestLoadMapping checks the general/advanced → runtime Config mapping mirrors
// Cmder::crawl: defaults, key nesting, and the depth/crawl_type precedence rules.
func TestLoadMapping(t *testing.T) {
	raw := []byte(`{
		"general": {
			"start": "https://example.com/",
			"domains": ["example.com","www.example.com"],
			"crawl_speed": "unlimited",
			"crawl_mode": "javascript",
			"depthMax": 7
		},
		"advanced": {
			"respect_robots": false,
			"respect_canonical": false,
			"store_html": false,
			"custom_headers": {"X-Test": "1"},
			"stealth_mode": "auto",
			"http_auth": {"username": "u", "password": "p"},
			"xPathExtractors": {"price": "//span[@class='price']"},
			"sitemap_urls": ["https://example.com/sitemap.xml"]
		}
	}`)

	c, err := Load(raw, 0, "") // depthMaxCol=0 → general.depthMax wins; crawlType from general
	if err != nil {
		t.Fatal(err)
	}

	if c.Start != "https://example.com/" {
		t.Errorf("start=%q", c.Start)
	}
	if len(c.Domains) != 2 {
		t.Errorf("domains=%v", c.Domains)
	}
	if c.CrawlSpeed != "unlimited" || c.CrawlMode != "javascript" {
		t.Errorf("speed=%q mode=%q", c.CrawlSpeed, c.CrawlMode)
	}
	if c.DepthMax != 7 {
		t.Errorf("depthMax=%d, want 7 (general)", c.DepthMax)
	}
	if c.CrawlType != "spider" {
		t.Errorf("crawlType=%q, want spider (default)", c.CrawlType)
	}
	// respect flags: explicit false overrides; nofollow default false; robots default true
	if c.RespectRobots {
		t.Error("respect_robots should be false")
	}
	if c.RespectCanonical {
		t.Error("respect_canonical should be false")
	}
	if c.RespectNofollow {
		t.Error("respect_nofollow default should be false")
	}
	if c.StoreHTML {
		t.Error("store_html should be false")
	}
	if c.FollowRedirects != true || c.RetryFailedURLs != true {
		t.Error("follow_redirects / retry_failed_urls should default true")
	}
	if c.CustomHeaders["X-Test"] != "1" {
		t.Errorf("custom_headers=%v", c.CustomHeaders)
	}
	if !c.HTTPAuth.Enabled || c.HTTPAuth.Username != "u" || c.HTTPAuth.Password != "p" {
		t.Errorf("httpAuth=%+v", c.HTTPAuth)
	}
	if c.XPathExtractors["price"] == "" {
		t.Errorf("xPathExtractors=%v", c.XPathExtractors)
	}
	if len(c.SitemapURLs) != 1 {
		t.Errorf("sitemap_urls=%v", c.SitemapURLs)
	}
	if c.StealthMode != "auto" {
		t.Errorf("stealth_mode=%q, want auto", c.StealthMode)
	}
}

func TestLoadPrecedenceAndDefaults(t *testing.T) {
	// crawls-column values win over general; minimal config → defaults.
	c, err := Load([]byte(`{"general":{"start":"https://x.fr/"}}`), 12, "list")
	if err != nil {
		t.Fatal(err)
	}
	if c.DepthMax != 12 {
		t.Errorf("depthMax=%d, want 12 (column wins)", c.DepthMax)
	}
	if c.CrawlType != "list" {
		t.Errorf("crawlType=%q, want list (column wins)", c.CrawlType)
	}
	if c.CrawlSpeed != "fast" || c.CrawlMode != "classic" {
		t.Errorf("defaults: speed=%q mode=%q", c.CrawlSpeed, c.CrawlMode)
	}
	if c.StealthMode != "off" {
		t.Errorf("stealth_mode=%q, want off (default)", c.StealthMode)
	}
	if !c.RespectRobots || !c.RespectCanonical {
		t.Error("robots/canonical should default true")
	}
}

func TestEnvOverride(t *testing.T) {
	t.Setenv("MAX_CONCURRENT_CURL", "25")
	t.Setenv("MAX_CONCURRENT_CHROME", "9")
	c, err := Load([]byte(`{"general":{"start":"https://x.fr/"}}`), 5, "spider")
	if err != nil {
		t.Fatal(err)
	}
	if c.MaxConcurrentCurl != 25 {
		t.Errorf("MaxConcurrentCurl=%d, want 25 (env)", c.MaxConcurrentCurl)
	}
	if c.MaxConcurrentChrome != 9 {
		t.Errorf("MaxConcurrentChrome=%d, want 9 (env)", c.MaxConcurrentChrome)
	}
	_ = os.Unsetenv("MAX_CONCURRENT_CURL")
}
