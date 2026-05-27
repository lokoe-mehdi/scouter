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
	"runtime/debug"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"scouter-crawler/internal/backfill"
	"scouter-crawler/internal/config"
	"scouter-crawler/internal/crawl"
	"scouter-crawler/internal/db"
	"scouter-crawler/internal/governor"
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

	applyMemoryLimit(workerID)

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

	// Backfill mode: `scouter-crawler backfill <crawlId|all>` migrates existing
	// PostgreSQL crawls into ClickHouse, then exits.
	if len(os.Args) > 1 && os.Args[1] == "backfill" {
		if ch == nil {
			log.Fatal("backfill requires CLICKHOUSE_URL to be set")
		}
		target := "all"
		if len(os.Args) > 2 {
			target = os.Args[2]
		}
		bf := backfill.New(pool, ch, func(f string, a ...any) { log.Printf(f, a...) })
		if target == "all" {
			if err := bf.All(ctx); err != nil {
				log.Fatalf("backfill all: %v", err)
			}
			// Retroactively optimize already-migrated crawls to the new read-perf
			// baseline (deduped parts, opt-in projection).
			if err := bf.OptimizeExisting(ctx); err != nil {
				log.Printf("backfill optimize-ch: %v", err)
			}
		} else {
			id, err := strconv.Atoi(target)
			if err != nil {
				log.Fatalf("backfill: invalid crawl id %q", target)
			}
			if err := bf.Crawl(ctx, id); err != nil {
				log.Fatalf("backfill crawl %d: %v", id, err)
			}
		}
		log.Printf("[%s] backfill finished", workerID)
		return
	}

	// Purge mode: `scouter-crawler purge-pg <crawlId|all>` drops the PostgreSQL
	// crawl-data partitions for crawls already migrated to ClickHouse (frees disk).
	if len(os.Args) > 1 && os.Args[1] == "purge-pg" {
		if ch == nil {
			log.Fatal("purge-pg requires CLICKHOUSE_URL to be set")
		}
		target := "all"
		if len(os.Args) > 2 {
			target = os.Args[2]
		}
		bf := backfill.New(pool, ch, func(f string, a ...any) { log.Printf(f, a...) })
		if err := bf.PurgePG(ctx, target); err != nil {
			log.Fatalf("purge-pg: %v", err)
		}
		if target == "all" {
			if err := bf.SweepOrphans(ctx); err != nil {
				log.Printf("[%s] purge-pg sweep-orphans error: %v", workerID, err)
			}
		}
		log.Printf("[%s] purge-pg finished", workerID)
		return
	}

	mgr := jobs.New(pool, workerID)
	if err := mgr.RecoverOrphans(ctx); err != nil {
		log.Printf("[%s] orphan recovery: %v", workerID, err)
	}

	// Auto-migration on boot (opt-in): when CLICKHOUSE_AUTO_MIGRATE is set, migrate
	// every not-yet-migrated PG crawl into ClickHouse — and, if CLICKHOUSE_DROP_PG
	// is ALSO set, purge their PostgreSQL data afterwards. Runs in the background so
	// the worker starts claiming crawls immediately; idempotent (skips done crawls).
	if ch != nil && envBoolFlag("CLICKHOUSE_AUTO_MIGRATE") {
		go func() {
			bf := backfill.New(pool, ch, func(f string, a ...any) { log.Printf("[migrate] "+f, a...) })
			// crawls.data_store is created by scouter's PHP migrations, which run
			// independently and may not be applied yet here (crawler-go depends_on
			// postgres+clickhouse, not scouter). Wait for the column so we don't
			// bail out on a transient "column does not exist".
			if err := bf.WaitForSchema(ctx); err != nil {
				log.Printf("[%s] auto-migration: schema not ready, skipping: %v", workerID, err)
				return
			}
			log.Printf("[%s] auto-migration: backfilling PG crawls into ClickHouse…", workerID)
			if err := bf.All(ctx); err != nil {
				log.Printf("[%s] auto-migration backfill error: %v", workerID, err)
				return
			}
			if envBoolFlag("CLICKHOUSE_DROP_PG") {
				log.Printf("[%s] auto-migration: purging migrated PG data…", workerID)
				if err := bf.PurgePG(ctx, "all"); err != nil {
					log.Printf("[%s] auto-migration purge error: %v", workerID, err)
				}
				// Strip the heavy links/HTML/schema partitions off the crawls PurgePG
				// kept whole for resume (recently-stopped): the resume only needs the
				// frontier (pages_<id>), and reports read ClickHouse — so those tables
				// are pure dead weight. Keeps pages_<id> so they stay resumable.
				if err := bf.PurgeHeavyKeepFrontier(ctx); err != nil {
					log.Printf("[%s] auto-migration slim-heavy error: %v", workerID, err)
				}
				// Last: sweep leftover partition tables of already-deleted crawls.
				// Runs only after migration+purge so a not-yet-migrated crawl is
				// never dropped here.
				if err := bf.SweepOrphans(ctx); err != nil {
					log.Printf("[%s] auto-migration sweep-orphans error: %v", workerID, err)
				}
			}
			// Retroactively optimize already-migrated crawls (deduped parts; opt-in
			// projection materialization). Cheap and idempotent after the first pass.
			if err := bf.OptimizeExisting(ctx); err != nil {
				log.Printf("[%s] auto-migration optimize-ch error: %v", workerID, err)
			}
			log.Printf("[%s] auto-migration finished", workerID)
		}()
	}

	// Process-wide CPU-pressure governor: a single dynamic in-flight ceiling
	// shared by every crawl (and the sitemap pass), steered by host PSI so the box
	// throttles under load instead of needing a reboot. Ceiling = today's static
	// aggregate (maxCrawls × per-crawl fetch concurrency); floor = one fetch per
	// crawl so none starves. Disabled (pinned at ceiling) when PSI is unreadable.
	perCrawl := envInt("MAX_CONCURRENT_CURL", 12)
	gov := governor.New(ctx, maxCrawls, maxCrawls*perCrawl, func(f string, a ...any) { log.Printf("[%s] "+f, append([]any{workerID}, a...)...) })

	log.Printf("[%s] Go crawler started (maxConcurrentCrawls=%d, renderers=%v)", workerID, maxCrawls, rendererURLs)

	sem := make(chan struct{}, maxCrawls)
	var wg sync.WaitGroup

	for {
		if ctx.Err() != nil {
			break
		}
		// Acquire a concurrency slot BEFORE claiming. ClaimNext marks the job
		// 'running' in the DB at claim time, so claiming one job ahead of an
		// available slot would leave it flagged "running" while it actually
		// just blocks here waiting for a slot — the dashboard then shows
		// MAX_CONCURRENT_CRAWLS+1 "running" (a queued crawl displayed as
		// running). Taking the slot first means a job is only claimed/marked
		// running when a slot is genuinely free → the count is exact.
		select {
		case sem <- struct{}{}:
		case <-ctx.Done():
		}
		if ctx.Err() != nil {
			break
		}

		job, err := mgr.ClaimNext(ctx)
		if err != nil {
			<-sem // release the reserved slot
			log.Printf("[%s] claim error: %v", workerID, err)
			sleepCtx(ctx, 4*time.Second)
			continue
		}
		if job == nil {
			<-sem // nothing to run; release the slot and idle-poll
			sleepCtx(ctx, 2*time.Second)
			continue
		}

		wg.Add(1)
		go func(j jobs.Job) {
			defer wg.Done()
			defer func() { <-sem }()
			runJob(ctx, pool, ch, mgr, j, rendererURLs, gov)
		}(*job)
	}

	log.Printf("[%s] shutting down, waiting for in-flight crawls…", workerID)
	wg.Wait()
}

// runJob executes one crawl + post-processing, mirroring scouter.php crawl +
// Crawler::depthStarter finalization, with panic isolation.
// applyMemoryLimit gives the Go runtime a soft heap ceiling (GOMEMLIMIT) so the
// GC reclaims aggressively before the heap overruns the container. Without it a
// large crawl can balloon RSS and, once the box is under memory pressure, drive
// the GC into a CPU-burning loop that lingers even when no crawl is active.
//
// If GOMEMLIMIT is already set in the environment the runtime has honoured it and
// we leave it alone. Otherwise we derive ~90% of the cgroup v2 memory limit (the
// 10% headroom is for off-heap memory: goroutine stacks, the ClickHouse HTTP
// client buffers, cgo). When neither applies (cgroup v1 / not containerised /
// unlimited) we leave the GC at its default.
func applyMemoryLimit(workerID string) {
	if os.Getenv("GOMEMLIMIT") != "" {
		return // runtime already applied the env-provided limit
	}
	b, err := os.ReadFile("/sys/fs/cgroup/memory.max")
	if err != nil {
		return
	}
	s := strings.TrimSpace(string(b))
	if s == "max" {
		return // no limit on the cgroup
	}
	max, err := strconv.ParseInt(s, 10, 64)
	if err != nil || max <= 0 {
		return
	}
	limit := max / 10 * 9
	debug.SetMemoryLimit(limit)
	log.Printf("[%s] Go soft memory limit set to %d MiB (90%% of cgroup %d MiB)", workerID, limit>>20, max>>20)
}

func runJob(ctx context.Context, pool *db.Pool, ch *db.CH, mgr *jobs.Manager, j jobs.Job, rendererURLs []string, gov *governor.Governor) {
	// A crawl + its post-processing build large transient structures (the dedup
	// set, batch buffers, parsed DOMs). Go keeps that freed heap mapped for reuse,
	// so RSS stays high after a crawl ends even though nothing is running. Hand it
	// back to the OS once the job is fully done so an idle worker stops looking
	// like a leak.
	defer debug.FreeOSMemory()

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
	if ch != nil {
		// Route this crawl's reads to ClickHouse (data is dual-written there).
		cdb.SetDataStore(ctx, "clickhouse")
	}

	// Full cutover (CLICKHOUSE_DROP_PG): ClickHouse is the sole store. In this mode
	// PG only carries the frontier (slimPG) — links/HTML/schemas/analytical columns
	// go to ClickHouse only, since the PG post-processor is skipped and reports read
	// CH. Default OFF keeps the full PG dual-write (the PG post-processor needs it).
	dropPG := ch != nil && envBoolFlag("CLICKHOUSE_DROP_PG")

	engine := crawl.NewEngine(cdb, cfg, crawl.Options{
		RendererURLs: rendererURLs,
		Governor:     gov,
		CHStore:      chStore,
		SlimPG:       dropPG,
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
			Governor:           gov,
			CHStore:            chStore,
			SlimPG:             dropPG,
			Logf:               logf,
			StopCheck:          func(context.Context) bool { return false }, // ignore stop during PP
		})
		return smEngine.FetchURLs(c, urls)
	}
	// dropPG (computed above) gates the PG post-processing: when ClickHouse is the
	// sole store we skip it entirely (the PageRank perf win) and drop the PG
	// crawl-data partitions at finish (below). Default OFF: PG post-processing
	// still runs so the comparison reports (still on PG) work.
	ppStart := time.Now()
	// On collecte les étapes de post-processing qui ÉCHOUENT : si l'analytique est
	// incomplète (typiquement un OOM ClickHouse sur un gros PageRank), on refuse de
	// dropper PostgreSQL et on ne déclare pas un faux "completed successfully".
	var ppFailures []string
	if dropPG {
		milestone("ClickHouse is the sole store (CLICKHOUSE_DROP_PG=1) — skipping PostgreSQL post-processing")
	} else {
		if err := pp.Run(ctx); err != nil {
			ppFailures = append(ppFailures, "pg-postprocess")
		}
	}

	// Post-processing in ClickHouse (PageRank/inlinks/semantic/duplicate/redirect)
	// → derived tables. Runs in addition to PG during the dual-write transition.
	if ch != nil {
		chpp := postprocess.NewCHRunner(ch, rec.ID, postprocess.RespectNofollowFromConfig(rec.Config), logf)
		ppFailures = append(ppFailures, chpp.Run(ctx)...)
		// In full-CH mode the PG post-processor is skipped, so write the
		// duplicate/redirect scorecard stats back to crawls.* from ClickHouse.
		if dropPG {
			backfill.New(pool, ch, logf).SyncStats(ctx, rec.ID)
		}
	}

	ppDur := time.Since(ppStart)
	milestone(fmt.Sprintf("Post-processing done in %s", fmtDur(ppDur)))

	total := time.Since(crawlStart)
	totalLine := fmt.Sprintf("Total time %s (crawl %s + post-process %s)", fmtDur(total), fmtDur(crawlDur), fmtDur(ppDur))

	// Worker en cours d'arrêt (SIGINT/SIGTERM a annulé ctx, typiquement un
	// redéploiement) : NE PAS finaliser. On laisse le job en 'running' pour que
	// la reprise d'orphelins au démarrage (cf. jobs.Manager) le re-queue et le
	// REPRENNE là où il en était — au lieu de le déclarer faussement "completed"
	// (ce qui, en mode CLICKHOUSE_DROP_PG, droppait en plus les partitions PG et
	// faisait perdre toutes les URLs restant à crawler).
	if ctx.Err() != nil {
		milestone("Crawl interrompu (arrêt du worker) — laissé en cours, reprise au redémarrage — " + totalLine)
		return
	}

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
		// Enqueue the PHP report-precompute job so the heavy report fragments
		// (PageRank category flux/position…) are warm on the first view. Best-effort:
		// the PHP report layer also lazy-warms them on first view if this is missed.
		pname := j.ProjectName
		if pname == "" {
			pname = j.ProjectDir
		}
		if _, err := pool.Exec(ctx,
			"INSERT INTO jobs (project_dir, project_name, command, status) VALUES ($1,$2,$3,'queued')",
			j.ProjectDir, pname, fmt.Sprintf("precompute-reports:%d", rec.ID)); err != nil {
			milestone("Report precompute enqueue skipped: " + err.Error())
		}
		// Full cutover: drop the heavy PG crawl-data partitions to free disk —
		// only for a finished crawl (stopped crawls keep PG for resume), seulement
		// après avoir confirmé que ClickHouse détient les données, ET seulement si
		// le post-processing a réussi : sinon on garde PostgreSQL pour pouvoir
		// recalculer l'analytique au lieu de perdre la donnée derrière un rapport
		// dégradé.
		if dropPG {
			if len(ppFailures) == 0 {
				dropPGData(ctx, pool, ch, cdb, rec.ID, milestone)
			} else {
				milestone("PG drop SKIPPED — post-processing incomplete (" + strings.Join(ppFailures, ", ") + "); PostgreSQL kept for recovery")
			}
		}
		if len(ppFailures) == 0 {
			milestone("Crawl completed successfully — " + totalLine)
		} else {
			milestone("Crawl completed with INCOMPLETE analytics (failed: " + strings.Join(ppFailures, ", ") + ") — report may be degraded, PostgreSQL kept — " + totalLine)
		}
	}
}

// dropPGData drops a crawl's PostgreSQL data partitions once ClickHouse is
// confirmed to hold the pages (guards against wiping PG if the CH write lagged).
func dropPGData(ctx context.Context, pool *db.Pool, ch *db.CH, cdb *db.CrawlDB, crawlID int, milestone func(string)) {
	pgCrawled := cdb.GetCrawledCount(ctx)
	chStr, err := ch.QueryScalar(ctx, fmt.Sprintf("SELECT uniqExact(id) FROM %s.pages WHERE crawl_id=%d", ch.DB(), crawlID))
	if err != nil {
		milestone("PG drop skipped: could not verify ClickHouse (" + err.Error() + ")")
		return
	}
	chCount, _ := strconv.Atoi(strings.TrimSpace(chStr))
	if chCount == 0 || chCount < pgCrawled*9/10 {
		milestone(fmt.Sprintf("PG drop skipped: ClickHouse has %d pages vs %d crawled — keeping PostgreSQL", chCount, pgCrawled))
		return
	}
	if _, err := pool.Exec(ctx, "SELECT drop_crawl_partitions($1)", crawlID); err != nil {
		milestone("PG drop failed: " + err.Error())
		return
	}
	milestone(fmt.Sprintf("Dropped PostgreSQL crawl data — %d pages now live only in ClickHouse", chCount))
}

func envBoolFlag(key string) bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv(key)))
	return v == "1" || v == "true" || v == "yes" || v == "on"
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
