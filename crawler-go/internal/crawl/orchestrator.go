package crawl

import (
	"context"
	"regexp"
	"strings"
	"sync"
	"time"

	"scouter-crawler/internal/analysis"
	"scouter-crawler/internal/db"
)

var bareHostSlashRe = regexp.MustCompile(`^https?://[^/]+$`)

const frontierBatch = 5000

// Crawl runs the full spider/list walk (Crawler::run + depthStarter), without the
// post-processing/finish — the worker drives those so it can apply status logic.
func (e *Engine) Crawl(ctx context.Context, newCrawl bool) error {
	if newCrawl {
		if err := e.insertStart(ctx); err != nil {
			return err
		}
	} else {
		// Resume: a fresh worker must be able to re-lease URLs the previous process
		// claimed but never crawled, without waiting out the 10-minute lease.
		_ = e.cdb.ResetClaims(ctx)
	}

	// Seed the live counters from the authoritative crawls row, then run the
	// stop-poller and stats-writer alongside the walk so neither a status check nor
	// a stats write ever sits on the fetch-dispatch path (the cause of the freeze on
	// huge crawls). They're bound to bgCtx, cancelled the moment the walk returns.
	e.seedLiveStats(ctx)
	bgCtx, cancelBg := context.WithCancel(ctx)
	var bg sync.WaitGroup
	bg.Add(2)
	go func() { defer bg.Done(); e.runStopPoller(bgCtx) }()
	go func() { defer bg.Done(); e.runStatsWriter(bgCtx) }()

	err := e.depthStarter(ctx, newCrawl)

	cancelBg()
	bg.Wait()
	// Final flush of the live counters (best-effort) so the last batch's count is
	// persisted even before the worker's finish-time UpdateCrawlStats.
	e.writeLiveStats(context.Background())
	return err
}

// FetchURLs fetches a flat list of URLs at depth=-1 (sitemap-only pass). Used by
// the post-processing sitemap step via a skip-link-extraction engine.
func (e *Engine) FetchURLs(ctx context.Context, urls []string) error {
	e.processURLs(ctx, urls, -1)
	e.chStore.Flush(ctx) // drain the sitemap-pass pages/html into ClickHouse
	return e.cdb.UpdateCrawlStats(ctx)
}

func (e *Engine) insertStart(ctx context.Context) error {
	if e.cfg.CrawlType == "list" {
		return e.insertURLList(ctx)
	}
	start := e.cfg.Start
	if bareHostSlashRe.MatchString(start) {
		start += "/"
	}
	blocked := !analysis.Allowed(start)
	if !e.cfg.RespectRobots {
		blocked = false
	}
	return e.cdb.InsertPage(ctx, db.PageRow{
		ID:      analysis.PageID(start),
		Domain:  hostOf(start),
		URL:     start,
		Depth:   0,
		Code:    0,
		Crawled: false,
		Blocked: blocked,
	})
}

func (e *Engine) insertURLList(ctx context.Context) error {
	pages := make([]db.PageRow, 0, len(e.cfg.URLList))
	for _, u := range e.cfg.URLList {
		if bareHostSlashRe.MatchString(u) {
			u += "/"
		}
		blocked := !analysis.Allowed(u)
		if !e.cfg.RespectRobots {
			blocked = false
		}
		pages = append(pages, db.PageRow{
			ID: analysis.PageID(u), Domain: hostOf(u), URL: u, Depth: 0,
			Crawled: false, External: false, Blocked: blocked, InCrawl: true,
		})
	}
	return e.cdb.InsertPages(ctx, pages)
}

// frontierOpTimeout caps a single frontier claim/count so a pathological scan is
// cancelled by the DB instead of wedging the driver goroutine forever (the
// idx_pages_frontier partial index keeps these in the millisecond range, so this
// only ever trips on genuine DB trouble).
const frontierOpTimeout = 60 * time.Second

// depthStarter ports Crawler::depthStarter: iterate depths 0..depthMax, draining
// each by leasing batches of frontierBatch from the frontier queue until none
// remain.
func (e *Engine) depthStarter(ctx context.Context, newCrawl bool) error {
	respectRobots := e.cfg.RespectRobots
	for i := 0; i <= e.cfg.DepthMax; i++ {
		if e.stopped(ctx) {
			return errStopped
		}
		if !newCrawl {
			if dr, err := e.cdb.GetCurrentDepth(ctx); err == nil && dr > 0 {
				i = dr
			}
		}
		total, err := e.countToCrawl(ctx, respectRobots, i)
		if err != nil {
			return err
		}
		if total == 0 {
			any, err := e.countToCrawl(ctx, respectRobots, -1)
			if err != nil {
				return err
			}
			if any == 0 {
				break
			}
			continue
		}

		e.startDepthProgress(i, total)

		// Drain the whole depth: keep leasing batches until none remain. Termination
		// is guaranteed — every leased URL is either marked crawled=true or keeps its
		// claim stamp, so ClaimUrlsToCrawl never hands the same row back within the
		// depth (no stuck-head guard needed: the claim is the guard).
		for {
			if e.stopped(ctx) {
				return errStopped
			}
			urls, err := e.claimToCrawl(ctx, respectRobots, frontierBatch, i)
			if err != nil {
				return err
			}
			if len(urls) == 0 {
				break
			}
			e.processURLs(ctx, urls, i)
		}
		// Authoritative recompute at the end of each depth (urls/duplicates/
		// in_progress/response_time the live writer doesn't track), then reseed the
		// live counters from it so they stay exact.
		if err := e.cdb.UpdateCrawlStats(ctx); err != nil {
			e.logf("depth %d: stats refresh failed: %v", i, err)
		}
		e.seedLiveStats(ctx)
	}
	return nil
}

// claimToCrawl leases one frontier batch under a timeout (see frontierOpTimeout).
func (e *Engine) claimToCrawl(ctx context.Context, respectRobots bool, limit, depth int) ([]string, error) {
	cctx, cancel := context.WithTimeout(ctx, frontierOpTimeout)
	defer cancel()
	return e.cdb.ClaimUrlsToCrawl(cctx, respectRobots, limit, depth)
}

// countToCrawl counts the remaining frontier at a depth under a timeout.
func (e *Engine) countToCrawl(ctx context.Context, respectRobots bool, depth int) (int, error) {
	cctx, cancel := context.WithTimeout(ctx, frontierOpTimeout)
	defer cancel()
	return e.cdb.CountUrlsToCrawl(cctx, respectRobots, depth)
}

// errStopped signals a user/worker stop during the walk.
var errStopped = errStringConst("crawl stop signal received")

type errStringConst string

func (e errStringConst) Error() string { return string(e) }

// IsStopped reports whether err is the stop sentinel.
func IsStopped(err error) bool { return err == errStopped }

func trimSpace(s string) string { return strings.TrimSpace(s) }

func hostOf(rawURL string) string {
	m := targetDomainRe.FindStringSubmatch(rawURL)
	if len(m) > 1 {
		return m[1]
	}
	return ""
}
