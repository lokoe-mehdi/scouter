package crawl

import (
	"context"
	"regexp"
	"strings"

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
	}
	return e.depthStarter(ctx, newCrawl)
}

// FetchURLs fetches a flat list of URLs at depth=-1 (sitemap-only pass). Used by
// the post-processing sitemap step via a skip-link-extraction engine.
func (e *Engine) FetchURLs(ctx context.Context, urls []string) error {
	e.processURLs(ctx, urls, -1)
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

// depthStarter ports Crawler::depthStarter: iterate depths 0..depthMax, draining
// each via batches of frontierBatch, with a safety cap on redirect-cycle passes.
func (e *Engine) depthStarter(ctx context.Context, newCrawl bool) error {
	respectRobots := e.cfg.RespectRobots
	for i := 0; i <= e.cfg.DepthMax; i++ {
		if e.stopCheck(ctx) {
			return errStopped
		}
		if !newCrawl {
			if dr, err := e.cdb.GetCurrentDepth(ctx); err == nil && dr > 0 {
				i = dr
			}
		}
		total, err := e.cdb.CountUrlsToCrawl(ctx, respectRobots, i)
		if err != nil {
			return err
		}
		if total == 0 {
			any, err := e.cdb.CountUrlsToCrawl(ctx, respectRobots, -1)
			if err != nil {
				return err
			}
			if any == 0 {
				break
			}
			continue
		}

		e.startDepthProgress(i, total)

		// Drain the whole depth: keep pulling batches until none remain. No fixed
		// pass cap — the old 50-pass cap silently truncated large depths at
		// 50*frontierBatch (=250k) URLs. Termination is guaranteed: every fetched
		// page is marked crawled=true and ON CONFLICT dedups already-seen URLs, so
		// the depth-i frontier strictly shrinks. Guard against a pathological page
		// that never gets marked (e.g. a persistent store error): if the head of
		// the queue doesn't advance across several passes, bail out of this depth.
		prevHead := ""
		stuck := 0
		for {
			if e.stopCheck(ctx) {
				return errStopped
			}
			urls, err := e.cdb.GetUrlsToCrawl(ctx, respectRobots, frontierBatch, i)
			if err != nil {
				return err
			}
			if len(urls) == 0 {
				break
			}
			if urls[0] == prevHead {
				if stuck++; stuck >= 3 {
					e.logf("depth %d: queue head not advancing (%s) — leaving this depth to avoid a loop", i, urls[0])
					break
				}
			} else {
				stuck = 0
				prevHead = urls[0]
			}
			e.processURLs(ctx, urls, i)
			e.maybeUpdateStats(ctx) // throttled: avoids a full-partition scan per batch
		}
		_ = e.cdb.UpdateCrawlStats(ctx) // accurate count at the end of each depth
	}
	return nil
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
