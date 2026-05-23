package crawl

import (
	"context"
	"math/rand"
	"sync"
	"sync/atomic"
	"time"
)

// processURLs fetches one depth batch with the worker pool, then retries the
// retryable failures with exponential backoff. Mirrors DepthCrawler::runNormal /
// runWithThrottling + retryFailedUrls, and emits live progress lines to the log
// file (the format the UI's JobController::parseLogFile expects).
func (e *Engine) processURLs(ctx context.Context, urls []string, depth int) {
	if e.cfg.CrawlMode == "javascript" && len(e.rendererURLs) > 0 {
		failed := e.processJavascript(ctx, urls, depth)
		if e.cfg.RetryFailedURLs {
			e.retryFailed(ctx, failed, depth)
		}
		e.emitProgress(depth, true)
		return
	}
	failed := e.processClassic(ctx, urls, depth)
	if e.cfg.RetryFailedURLs {
		e.retryFailed(ctx, failed, depth)
	}
	e.emitProgress(depth, true)
}

func (e *Engine) processClassic(ctx context.Context, urls []string, depth int) []string {
	sem := make(chan struct{}, e.concurrency)
	var wg sync.WaitGroup
	var mu sync.Mutex
	var failed []string

	var tick <-chan time.Time
	if e.targetPerSec > 0 {
		t := time.NewTicker(time.Second / time.Duration(e.targetPerSec))
		defer t.Stop()
		tick = t.C
	}

	for _, u := range urls {
		if e.stopCheck(ctx) || ctx.Err() != nil {
			break
		}
		if tick != nil {
			<-tick
		}
		sem <- struct{}{}
		wg.Add(1)
		go func(url string) {
			defer wg.Done()
			defer func() { <-sem }()
			r := e.fetchOne(ctx, normalizeSlash(url))
			if e.cfg.RetryFailedURLs && r.retryable() {
				mu.Lock()
				failed = append(failed, url)
				mu.Unlock()
				return
			}
			if err := e.parseAndStore(ctx, r, depth); err != nil {
				e.logf("store error %s: %v", url, err)
			}
			e.tickProgress(depth)
		}(u)
	}
	wg.Wait()
	return failed
}

// retryFailed re-fetches failures in parallel with one backoff sleep per attempt
// (2/4/8/16s ±20% jitter), storing on success or on the final attempt.
func (e *Engine) retryFailed(ctx context.Context, failed []string, depth int) {
	for attempt := 1; attempt <= maxRetries && len(failed) > 0; attempt++ {
		if e.stopCheck(ctx) || ctx.Err() != nil {
			return
		}
		delay := time.Duration(int64(baseDelay) << uint(attempt-1)) // 2,4,8,16s
		jitter := time.Duration(float64(delay) * (float64(rand.Intn(41)-20) / 100.0))
		pause := delay + jitter
		// Make the backoff visible — otherwise the worker looks frozen for up to
		// ~30s at the end of a depth when transient failures (timeout/5xx/429) occur.
		e.logf("Retry %d/%d for %d URLs (~%.0fs pause)", attempt, maxRetries, len(failed), pause.Seconds())
		e.addRetryPause(pause) // idle time, excluded from the avg URLs/sec
		select {
		case <-ctx.Done():
			return
		case <-time.After(pause):
		}

		sem := make(chan struct{}, e.concurrency)
		var wg sync.WaitGroup
		var mu sync.Mutex
		var still []string
		for _, u := range failed {
			sem <- struct{}{}
			wg.Add(1)
			go func(url string) {
				defer wg.Done()
				defer func() { <-sem }()
				r := e.fetchOne(ctx, normalizeSlash(url))
				if r.retryable() && attempt < maxRetries {
					mu.Lock()
					still = append(still, url)
					mu.Unlock()
					return
				}
				if err := e.parseAndStore(ctx, r, depth); err != nil {
					e.logf("store error (retry) %s: %v", url, err)
				}
				e.tickProgress(depth)
			}(u)
		}
		wg.Wait()
		failed = still
	}
}

// --- live progress (URLs/sec) ---------------------------------------------
// The UI parses lines of the exact form "Depth N : X.XX URLs/sec (done/total)"
// out of logs/<projectDir>.log. We track per-depth counters and emit that line
// at most ~every 250ms (or on every 10th URL) to avoid flooding the file.

func (e *Engine) startDepthProgress(depth, total int) {
	e.progMu.Lock()
	e.progTotal = total
	e.progDone = 0
	e.progStart = time.Now()
	e.progLast = time.Time{}
	e.progMu.Unlock()
	e.emitProgress(depth, true)
}

func (e *Engine) tickProgress(depth int) {
	done := atomic.AddInt64(&e.progDone, 1)
	if done%10 == 0 {
		e.emitProgress(depth, false)
	}
}

func (e *Engine) emitProgress(depth int, force bool) {
	e.progMu.Lock()
	now := time.Now()
	if !force && now.Sub(e.progLast) < 250*time.Millisecond {
		e.progMu.Unlock()
		return
	}
	e.progLast = now
	done := atomic.LoadInt64(&e.progDone)
	total := e.progTotal
	elapsed := now.Sub(e.progStart).Seconds()
	e.progMu.Unlock()

	speed := 0.0
	if elapsed > 0 {
		speed = float64(done) / elapsed
	}
	shown := done
	if total > 0 && shown > int64(total) {
		shown = int64(total)
	}
	e.logf("Depth %d : %.2f URLs/sec (%d/%d)", depth, speed, shown, total)
}

func normalizeSlash(u string) string {
	u = trimSpace(u)
	if bareHostSlashRe.MatchString(u) {
		return u + "/"
	}
	return u
}
