// Command scouter-crawler is the Go crawl worker. It replaces the crawl path of
// app/bin/worker.php: poll the jobs table for command='crawl', and run each
// crawl + post-processing in-process (multi-crawl), instead of proc_open'ing PHP.
//
// The PHP worker keeps handling batch-categorize / delete / bulk-ai jobs; the two
// cohabit by filtering the claim on `command` (see internal/jobs).
package main

import (
	"context"
	"fmt"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"scouter-crawler/internal/config"
	"scouter-crawler/internal/crawl"
	"scouter-crawler/internal/db"
	"scouter-crawler/internal/jobs"
	"scouter-crawler/internal/postprocess"
)

func main() {
	workerID := getenv("HOSTNAME", "go-crawler")
	dsn := os.Getenv("DATABASE_URL")
	if dsn == "" {
		log.Fatal("DATABASE_URL is required")
	}
	rendererURLs := parseRenderers()
	maxCrawls := envInt("MAX_CONCURRENT_CRAWLS", 4)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	pool, err := db.NewPool(ctx, dsn, int32(maxCrawls*int(envInt("POOL_CONNS_PER_CRAWL", 6))))
	if err != nil {
		log.Fatalf("[%s] db connect: %v", workerID, err)
	}
	defer pool.Close()

	// ClickHouse is optional: nil when CLICKHOUSE_URL is unset (PG-only, unchanged).
	ch, chErr := db.NewCHFromEnv(ctx)
	if chErr != nil {
		log.Printf("[%s] ClickHouse disabled (connect failed): %v", workerID, chErr)
	} else if ch != nil {
		log.Printf("[%s] ClickHouse enabled — dual-write + post-processing in CH", workerID)
	}

	mgr := jobs.New(pool, workerID)
	if err := mgr.RecoverOrphans(ctx); err != nil {
		log.Printf("[%s] orphan recovery: %v", workerID, err)
	}

	log.Printf("[%s] Go crawler started (maxConcurrentCrawls=%d, renderers=%v)", workerID, maxCrawls, rendererURLs)

	sem := make(chan struct{}, maxCrawls)
	var wg sync.WaitGroup

	for {
		if ctx.Err() != nil {
			break
		}
		job, err := mgr.ClaimNext(ctx)
		if err != nil {
			log.Printf("[%s] claim error: %v", workerID, err)
			sleepCtx(ctx, 4*time.Second)
			continue
		}
		if job == nil {
			sleepCtx(ctx, 2*time.Second) // idle poll
			continue
		}

		sem <- struct{}{}
		wg.Add(1)
		go func(j jobs.Job) {
			defer wg.Done()
			defer func() { <-sem }()
			runJob(ctx, pool, ch, mgr, j, rendererURLs)
		}(*job)
	}

	log.Printf("[%s] shutting down, waiting for in-flight crawls…", workerID)
	wg.Wait()
}

// runJob executes one crawl + post-processing, mirroring scouter.php crawl +
// Crawler::depthStarter finalization, with panic isolation.
func runJob(ctx context.Context, pool *db.Pool, ch *db.CH, mgr *jobs.Manager, j jobs.Job, rendererURLs []string) {
	// Per-crawl log file: the UI's live console reads logs/<projectDir>.log
	// (JobController::parseLogFile). The engine emits "Depth N : X URLs/sec
	// (done/total)" lines there; post-processing emits its step lines. Volume
	// ./logs must be mounted into this container (see docker-compose).
	logDir := getenv("LOG_DIR", "/app/logs")
	logFile := openLogFile(logDir, j.ProjectDir)
	if logFile != nil {
		defer logFile.Close()
		fmt.Fprintln(logFile, "=== GO WORKER STARTED CRAWL ===")
	}
	writeLine := func(s string) {
		log.Printf("[job %d] %s", j.ID, s)
		if logFile != nil {
			fmt.Fprintln(logFile, s)
		}
	}
	// logf: high-volume engine/post-processing lines → log file + stdout only.
	logf := func(format string, args ...any) { writeLine(fmt.Sprintf(format, args...)) }
	// milestone: also persisted to job_logs (the db_logs stream).
	milestone := func(msg string) {
		writeLine(msg)
		mgr.AddLog(ctx, j.ID, msg, "info")
	}
	defer func() {
		if r := recover(); r != nil {
			err := fmt.Sprintf("panic: %v", r)
			mgr.SetError(ctx, j.ID, err)
			_ = mgr.UpdateStatus(ctx, j.ID, "failed", j.ProjectDir)
			log.Printf("[job %d] %s", j.ID, err)
		}
	}()

	rec, err := pool.GetCrawlByPath(ctx, j.ProjectDir)
	if err != nil || rec == nil {
		mgr.SetError(ctx, j.ID, "crawl not found for path "+j.ProjectDir)
		_ = mgr.UpdateStatus(ctx, j.ID, "failed", j.ProjectDir)
		return
	}
	cfg, err := config.Load(rec.Config, rec.DepthMax, rec.CrawlType)
	if err != nil {
		mgr.SetError(ctx, j.ID, "config: "+err.Error())
		_ = mgr.UpdateStatus(ctx, j.ID, "failed", j.ProjectDir)
		return
	}

	cdb := db.NewCrawlDB(pool, rec.ID)
	if err := cdb.CreatePartitions(ctx); err != nil {
		mgr.SetError(ctx, j.ID, "partitions: "+err.Error())
		_ = mgr.UpdateStatus(ctx, j.ID, "failed", j.ProjectDir)
		return
	}
	milestone(fmt.Sprintf("Crawl %d started (%s, %s mode, speed=%s, depth %d)", rec.ID, cfg.CrawlType, cfg.CrawlMode, cfg.CrawlSpeed, cfg.DepthMax))

	newCrawl, _ := cdb.IsNewCrawl(ctx)

	stopCheck := func(c context.Context) bool {
		st, err := cdb.GetCrawlStatus(c)
		if err != nil {
			return false
		}
		return st == "stopping" || st == "stopped" || st == "failed"
	}

	chStore := crawl.NewCHStore(ch, rec.ID, logf)

	engine := crawl.NewEngine(cdb, cfg, crawl.Options{
		RendererURLs: rendererURLs,
		CHStore:      chStore,
		Logf:         logf,
		StopCheck:    stopCheck,
	})

	crawlStart := time.Now()
	crawlErr := engine.Crawl(ctx, newCrawl)
	crawlDur := time.Since(crawlStart)
	// Active crawl time = wall-clock minus the idle retry-backoff pauses, so the
	// average reflects the real fetching rate, not the time spent waiting to retry.
	activeDur := crawlDur - engine.RetryPause()
	if activeDur < 0 {
		activeDur = 0
	}
	crawled := cdb.GetCrawledCount(ctx)
	avg := 0.0
	if activeDur.Seconds() > 0 {
		avg = float64(crawled) / activeDur.Seconds()
	}
	line := fmt.Sprintf("Crawl walk done: %d URLs in %s active (avg %.2f URLs/sec)", crawled, fmtDur(activeDur), avg)
	if rp := engine.RetryPause(); rp > 0 {
		line += fmt.Sprintf(" + %s in retry pauses", fmtDur(rp))
	}
	milestone(line)

	// Drain any buffered crawl-data into ClickHouse before post-processing reads it.
	// Detached context so a graceful stop still flushes what was crawled.
	if chStore != nil {
		flushCtx, cancelFlush := context.WithTimeout(context.Background(), 60*time.Second)
		chStore.Flush(flushCtx)
		cancelFlush()
	}
	milestone("Starting post-processing")

	// Post-processing (status changes on the crawls row must NOT abort it, only a
	// 'failed' watchdog status does — handled inside postprocess.interrupted).
	pp := postprocess.New(pool, rec.ID, logf)
	pp.SitemapFetch = func(c context.Context, urls, domains []string) error {
		smCfg := *cfg
		smCfg.Domains = domains
		smCfg.CrawlMode = "classic"
		smEngine := crawl.NewEngine(cdb, &smCfg, crawl.Options{
			RendererURLs:       rendererURLs,
			SkipLinkExtraction: true,
			CHStore:            chStore,
			Logf:               logf,
			StopCheck:          func(context.Context) bool { return false }, // ignore stop during PP
		})
		return smEngine.FetchURLs(c, urls)
	}
	ppStart := time.Now()
	_ = pp.Run(ctx)
	ppDur := time.Since(ppStart)
	milestone(fmt.Sprintf("Post-processing done in %s", fmtDur(ppDur)))

	total := time.Since(crawlStart)
	totalLine := fmt.Sprintf("Total time %s (crawl %s + post-process %s)", fmtDur(total), fmtDur(crawlDur), fmtDur(ppDur))

	// Finalize, mirroring Crawler::depthStarter's status logic.
	status, _ := cdb.GetCrawlStatus(ctx)
	switch {
	case status == "failed":
		_ = cdb.UpdateCrawlStats(ctx)
		mgr.SetError(ctx, j.ID, "crawl marked failed (watchdog)")
		milestone("Crawl finalized as failed — " + totalLine)
	case status == "stopping" || status == "stopped" || crawl.IsStopped(crawlErr):
		_ = cdb.UpdateCrawlStats(ctx)
		_ = cdb.SetStopped(ctx)
		_ = mgr.UpdateStatus(ctx, j.ID, "stopped", j.ProjectDir)
		milestone("Crawl stopped by user — " + totalLine)
	default:
		_ = cdb.FinishCrawl(ctx)
		_ = mgr.UpdateStatus(ctx, j.ID, "completed", j.ProjectDir)
		milestone("Crawl completed successfully — " + totalLine)
	}
}

// fmtDur renders a duration compactly (rounded to 0.1s).
func fmtDur(d time.Duration) string {
	return d.Round(100 * time.Millisecond).String()
}

// openLogFile opens (append) the per-crawl log file the UI tails. Returns nil on
// failure (logging then degrades to stdout only, crawl still runs).
func openLogFile(dir, projectDir string) *os.File {
	if dir == "" {
		return nil
	}
	_ = os.MkdirAll(dir, 0o777)
	safe := filepath.Base(projectDir) // prevent path traversal
	path := filepath.Join(dir, safe+".log")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o666)
	if err != nil {
		log.Printf("could not open log file %s: %v", path, err)
		return nil
	}
	return f
}

func parseRenderers() []string {
	raw := os.Getenv("RENDERER_URLS")
	if raw == "" {
		raw = os.Getenv("RENDERER_URL")
	}
	if raw == "" {
		return nil
	}
	var out []string
	for _, u := range strings.Split(raw, ",") {
		if t := strings.TrimSpace(u); t != "" {
			out = append(out, t)
		}
	}
	return out
}

func getenv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func envInt(key string, def int) int {
	if v, err := strconv.Atoi(os.Getenv(key)); err == nil && v > 0 {
		return v
	}
	return def
}

func sleepCtx(ctx context.Context, d time.Duration) {
	select {
	case <-ctx.Done():
	case <-time.After(d):
	}
}
