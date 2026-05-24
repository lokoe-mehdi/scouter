// Package crawl is the crawl engine: fetch (classic/throttle/JS), parse, store,
// and depth orchestration. It mirrors app/Core/{Crawler,DepthCrawler,PageCrawler}.
package crawl

import (
	"context"
	"io"
	"net"
	"net/http"
	"net/http/httptrace"
	"net/url"
	"strings"
	"sync"
	"time"

	"scouter-crawler/internal/analysis"
	"scouter-crawler/internal/config"
	"scouter-crawler/internal/db"
	"scouter-crawler/internal/model"
	"scouter-crawler/internal/page"
)

var retryableCodes = map[int]bool{429: true, 500: true, 502: true, 503: true, 504: true}

const (
	maxRetries  = 4
	baseDelay   = 2 * time.Second
	bodyMaxSize = 16 << 20 // 16MB safety cap (PHP runs memory_limit=-1)
)

// Engine drives one crawl. One Engine per active crawl in the multi-crawl worker.
type Engine struct {
	cfg                *config.Config
	cdb                *db.CrawlDB
	client             *http.Client // uTLS client (Chrome TLS fingerprint) by default
	rendererURLs       []string
	concurrency        int
	targetPerSec       int
	skipLinkExtraction bool
	logf               func(string, ...any)
	stopCheck          func(context.Context) bool

	// live progress counters (per depth)
	progMu    sync.Mutex
	progTotal int
	progDone  int64
	progStart time.Time
	progLast  time.Time

	// total time spent idle in retry backoff pauses (excluded from the avg rate)
	retryPauseMu sync.Mutex
	retryPause   time.Duration

	// throttle for UpdateCrawlStats (each call scans the whole partition; on a
	// million-page crawl, calling it per 5000-batch would mean thousands of full
	// scans). Updated at most every statsInterval.
	statsMu   sync.Mutex
	lastStats time.Time
}

const statsInterval = 10 * time.Second

// maybeUpdateStats refreshes crawls.* stats at most once per statsInterval, to
// keep the UI progress live without hammering PG with full-partition COUNTs on
// huge crawls.
func (e *Engine) maybeUpdateStats(ctx context.Context) {
	e.statsMu.Lock()
	if time.Since(e.lastStats) < statsInterval {
		e.statsMu.Unlock()
		return
	}
	e.lastStats = time.Now()
	e.statsMu.Unlock()
	_ = e.cdb.UpdateCrawlStats(ctx)
}

// RetryPause returns the cumulative time spent sleeping in retry backoff, so the
// caller can report an average URLs/sec over the real crawling time only.
func (e *Engine) RetryPause() time.Duration {
	e.retryPauseMu.Lock()
	defer e.retryPauseMu.Unlock()
	return e.retryPause
}

func (e *Engine) addRetryPause(d time.Duration) {
	e.retryPauseMu.Lock()
	e.retryPause += d
	e.retryPauseMu.Unlock()
}

// Options configures an Engine.
type Options struct {
	RendererURLs       []string
	SkipLinkExtraction bool
	Logf               func(string, ...any)
	StopCheck          func(context.Context) bool
}

// NewEngine builds an Engine, deriving concurrency/throttle from crawl_speed and
// the MAX_CONCURRENT_CURL override (DepthCrawler::configureCrawlSpeed).
func NewEngine(cdb *db.CrawlDB, cfg *config.Config, opts Options) *Engine {
	concurrency, target := speedToLimits(cfg.CrawlSpeed)
	if cfg.MaxConcurrentCurl > 0 {
		concurrency = cfg.MaxConcurrentCurl
	}
	logf := opts.Logf
	if logf == nil {
		logf = func(string, ...any) {}
	}
	stop := opts.StopCheck
	if stop == nil {
		stop = func(context.Context) bool { return false }
	}
	return &Engine{
		cfg:                cfg,
		cdb:                cdb,
		rendererURLs:       opts.RendererURLs,
		concurrency:        concurrency,
		targetPerSec:       target,
		skipLinkExtraction: opts.SkipLinkExtraction,
		logf:               logf,
		stopCheck:          stop,
		// Single HTTP client, fetching over uTLS (Chrome TLS fingerprint). Never
		// follows redirects (PHP RollingCurl behaviour); the page parser handles them.
		client: newUTLSClient(concurrency, 5*time.Second),
	}
}

func speedToLimits(speed string) (concurrency, targetPerSec int) {
	switch speed {
	case "very_slow":
		return 2, 1
	case "slow":
		return 3, 5
	case "fast":
		return 8, 20
	default: // unlimited
		return 10, 0
	}
}

// fetchResult is the response metadata + body for one URL.
type fetchResult struct {
	url          string
	body         string
	code         int
	contentType  string
	redirectURL  string
	xRobots      string
	responseTime float64 // seconds (TTFB)
	timedOut     bool
	err          error
}

func (r fetchResult) retryable() bool {
	return retryableCodes[r.code] || r.timedOut
}

// fetchOne performs a single GET over the uTLS client (Chrome TLS fingerprint),
// capturing TTFB and the Location header (resolved absolute). SSRF-validated
// before the request. It uses the User-Agent from the crawl config and never
// executes JavaScript, so a non-JS crawl always returns the raw server response.
func (e *Engine) fetchOne(ctx context.Context, rawURL string) fetchResult {
	res := fetchResult{url: rawURL}
	if err := analysis.ValidateURL(rawURL); err != nil {
		res.err = err
		res.code = 0
		return res
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL, nil)
	if err != nil {
		res.err = err
		return res
	}
	req.Header.Set("User-Agent", e.cfg.UserAgent)
	req.Header.Set("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8")
	for k, v := range e.cfg.CustomHeaders {
		req.Header.Set(k, v)
	}
	if e.cfg.HTTPAuth.Enabled {
		req.SetBasicAuth(e.cfg.HTTPAuth.Username, e.cfg.HTTPAuth.Password)
	}

	start := time.Now()
	var ttfb time.Duration
	trace := &httptrace.ClientTrace{
		GotFirstResponseByte: func() { ttfb = time.Since(start) },
	}
	req = req.WithContext(httptrace.WithClientTrace(req.Context(), trace))

	resp, err := e.client.Do(req)
	if err != nil {
		res.err = err
		res.timedOut = isTimeout(err)
		return res
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, bodyMaxSize))
	res.body = string(body)
	res.code = resp.StatusCode
	res.contentType = resp.Header.Get("Content-Type")
	res.xRobots = resp.Header.Get("X-Robots-Tag")
	res.responseTime = ttfb.Seconds()
	if res.responseTime == 0 {
		res.responseTime = time.Since(start).Seconds()
	}
	res.redirectURL = resolveLocation(rawURL, resp)
	return res
}

func resolveLocation(base string, resp *http.Response) string {
	if resp.StatusCode < 300 || resp.StatusCode >= 400 {
		return ""
	}
	loc := resp.Header.Get("Location")
	if loc == "" {
		return ""
	}
	b, err := url.Parse(base)
	if err != nil {
		return loc
	}
	ref, err := url.Parse(loc)
	if err != nil {
		return loc
	}
	return b.ResolveReference(ref).String()
}

func isTimeout(err error) bool {
	var ne net.Error
	if e, ok := err.(net.Error); ok {
		ne = e
	}
	return ne != nil && ne.Timeout() || strings.Contains(strings.ToLower(err.Error()), "timeout")
}

// parseAndStore turns a fetched result into a page and persists it.
func (e *Engine) parseAndStore(ctx context.Context, r fetchResult, depth int) error {
	headers := model.Headers{
		HTTPCode:     r.code,
		RedirectURL:  r.redirectURL,
		ResponseTime: r.responseTime,
		Size:         len(r.body),
		ContentType:  r.contentType,
		XRobotsTag:   r.xRobots,
	}
	pc := page.ParseConfig{
		XPathExtractors: e.cfg.XPathExtractors,
		RegexExtractors: e.cfg.RegexExtractors,
		RespectRobots:   e.cfg.RespectRobots,
		Pattern:         e.cfg.Domains,
		// Robots nil → page package uses the shared process-wide robots cache.
	}
	p := page.Parse(r.url, headers, r.body, pc)
	return e.storePage(ctx, p, depth)
}
