package crawl

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"os"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

// renderer.go ports the JS-mode path of DepthCrawler::runJavascript: batch URLs
// to the Go renderer's /render-batch endpoint (20 per request, round-robin).
//
// Unlike the old sequential mega-batch design, we keep several batches in flight
// per renderer (no barrier), so a single slow page can't idle the whole pass and
// the renderer's page pool (MaxConcurrentPages) stays saturated.

const batchPerRenderer = 20

// renderInFlightPerRenderer = concurrent /render-batch requests kept in flight
// per renderer. batchPerRenderer × this should roughly fill the renderer's
// MaxConcurrentPages (50) → ~3. Tunable via RENDERER_BATCHES_INFLIGHT.
func renderInFlightPerRenderer() int {
	if v, err := strconv.Atoi(os.Getenv("RENDERER_BATCHES_INFLIGHT")); err == nil && v > 0 {
		return v
	}
	return 3
}

type renderBatchRequest struct {
	URLs    []string          `json:"urls"`
	Headers map[string]string `json:"headers"`
}

type renderResult struct {
	Success      bool    `json:"success"`
	HTML         string  `json:"html"`
	HTTPCode     int     `json:"httpCode"`
	ResponseTime float64 `json:"responseTime"`
	FinalURL     string  `json:"finalUrl"`
	JSRedirect   bool    `json:"jsRedirect"`
	Error        string  `json:"error,omitempty"`
	URL          string  `json:"url,omitempty"`
}

type renderBatchResponse struct {
	Results []renderResult `json:"results"`
}

func (e *Engine) processJavascript(ctx context.Context, urls []string, depth int) []string {
	headers := e.renderHeaders()

	rc := len(e.rendererURLs)
	client := &http.Client{Timeout: 90 * time.Second}

	// Continuous dispatch: at most (inFlightPerRenderer × rc) /render-batch calls
	// run at once, refilled as each finishes — no per-mega-batch barrier.
	maxConcurrent := renderInFlightPerRenderer() * rc
	if maxConcurrent < 1 {
		maxConcurrent = 1
	}
	sem := make(chan struct{}, maxConcurrent)

	var wg sync.WaitGroup
	var mu sync.Mutex
	var failed []string
	var rr uint64 // round-robin renderer selector

	for b := 0; b < len(urls); b += batchPerRenderer {
		if e.stopped(ctx) {
			break
		}
		be := b + batchPerRenderer
		if be > len(urls) {
			be = len(urls)
		}
		batch := urls[b:be]
		// Global CPU-pressure gate (one slot per render batch): parsing a batch's
		// rendered HTML is the crawler-side CPU cost, so this throttles when many
		// JS crawls run at once and the host is contended.
		if !e.gov.Acquire(ctx) {
			break
		}
		sem <- struct{}{}
		wg.Add(1)
		go func(batch []string) {
			defer wg.Done()
			defer e.gov.Release()
			defer func() { <-sem }()
			rurl := e.rendererURLs[int(atomic.AddUint64(&rr, 1))%rc]

			results := postRenderBatch(ctx, client, rurl, batch, headers)
			// Renderer unreachable / timed out → don't silently drop the URLs
			// (they'd stay crawled=false and get re-fetched up to 50× by the
			// depth loop). Send them to the HTTP retry path and make it visible.
			if len(results) == 0 {
				e.logf("renderer %s returned no results for %d URLs (timeout/unreachable?) — falling back to HTTP retry", rurl, len(batch))
				if e.cfg.RetryFailedURLs {
					mu.Lock()
					failed = append(failed, batch...)
					mu.Unlock()
				}
				return
			}
			for _, res := range results {
				r := renderToFetchResult(res)
				if e.cfg.RetryFailedURLs && r.retryable() {
					mu.Lock()
					failed = append(failed, r.url)
					mu.Unlock()
					continue
				}
				if err := e.parseAndStore(ctx, r, depth); err != nil {
					e.logf("store error (js) %s: %v", r.url, err)
				} else {
					e.tickProgress(depth)
				}
			}
			// Live feedback: progress line is throttled internally (250ms). The crawls.*
			// counters are persisted off this path by the background stats-writer.
			e.emitProgress(depth, false)
		}(batch)
	}
	wg.Wait()
	e.emitProgress(depth, true)
	return failed
}

func (e *Engine) renderHeaders() map[string]string {
	headers := map[string]string{"User-Agent": e.cfg.UserAgent}
	for k, v := range e.cfg.CustomHeaders {
		headers[k] = v
	}
	if e.cfg.HTTPAuth.Enabled {
		token := base64.StdEncoding.EncodeToString([]byte(e.cfg.HTTPAuth.Username + ":" + e.cfg.HTTPAuth.Password))
		headers["Authorization"] = "Basic " + token
	}
	return headers
}

func postRenderBatch(ctx context.Context, client *http.Client, rendererURL string, urls []string, headers map[string]string) []renderResult {
	payload, _ := json.Marshal(renderBatchRequest{URLs: urls, Headers: headers})
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, rendererURL+"/render-batch", bytes.NewReader(payload))
	if err != nil {
		return nil
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := client.Do(req)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()
	var out renderBatchResponse
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil
	}
	return out.Results
}

// renderToFetchResult maps a renderer result to a fetchResult, reproducing the
// HTTP-redirect vs JS-redirect (code 311) handling of runJavascript.
func renderToFetchResult(res renderResult) fetchResult {
	if res.URL == "" {
		return fetchResult{code: 500, err: errString("invalid render result")}
	}
	if !res.Success {
		return fetchResult{url: res.URL, code: 500, err: errString(res.Error)}
	}
	code := res.HTTPCode
	if code == 0 {
		code = 200
	}
	r := fetchResult{
		url:          res.URL,
		body:         res.HTML,
		code:         code,
		contentType:  "text/html",
		responseTime: res.ResponseTime,
	}
	httpRedirect := code == 301 || code == 302 || code == 303 || code == 307 || code == 308
	switch {
	case httpRedirect && res.FinalURL != "":
		r.redirectURL = res.FinalURL
	case (code == 200 || code == 304) && res.JSRedirect && res.FinalURL != "":
		r.code = 311 // internal sentinel: JS redirect detected
		r.redirectURL = res.FinalURL
	}
	return r
}

type errString string

func (e errString) Error() string { return string(e) }
