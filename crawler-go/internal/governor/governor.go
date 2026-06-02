// Package governor is a process-wide, CPU-pressure-aware concurrency limiter.
//
// It reads Linux PSI (Pressure Stall Information, /proc/pressure/cpu) and steers
// a dynamic in-flight ceiling with an AIMD control loop — additive-increase /
// multiplicative-decrease, the same family as TCP congestion control: the limit
// grows while the host has CPU headroom and is cut sharply the moment CPU stall
// pressure climbs. Every unit of work calls Acquire before running and Release
// when done, so the SUM of in-flight work across all crawls tracks what the host
// can actually absorb — not a hard-coded core count.
//
// Why PSI and not "N cores": PSI answers the only question that matters — "is the
// CPU genuinely contended right now?" — without the code ever knowing how many
// cores exist. 2 vCPU or 64, VPS or Kubernetes: same code, it adapts to whatever
// is available. That is the whole design goal (no machine-specific constants).
//
// Graceful degradation: when PSI is unreadable (cgroup v1, non-Linux dev box,
// the file missing, or CPU_GOVERNOR=0), the governor pins itself at max — i.e.
// exactly the previous static behaviour: no throttling, no new failure mode.
//
// Container caveat: inside a container, /proc/pressure/cpu reflects that
// container's own cgroup, not the whole host. To make the crawler back off on
// TOTAL host pressure (ClickHouse + renderers + workers, not just its own
// parsing), mount the host file read-only and point PSI_PATH at it (see
// docker-compose). Absent that, it still self-regulates on its own cgroup, which
// is a safe subset.
package governor

import (
	"context"
	"math"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Governor is a dynamically-sized concurrency limiter shared by the whole
// multi-crawl process. The zero value is unusable; build one with New. A nil
// *Governor is a valid no-op (Acquire returns true, Release does nothing), so
// callers never need a nil check.
type Governor struct {
	mu       sync.Mutex
	cond     *sync.Cond
	limit    float64 // current in-flight ceiling (fractional for smooth AIMD)
	inflight int
	min      float64
	max      float64

	// AIMD tuning (all overridable via env; see New).
	lowPSI    float64 // PSI some-avg10 below this → grow (headroom available)
	highPSI   float64 // PSI some-avg10 above this → cut (real contention)
	incStep   float64 // additive increase per tick under low pressure
	decFactor float64 // multiplicative cut applied under high pressure

	psiPath  string
	interval time.Duration
	enabled  bool // false → pinned at max (PSI unavailable / disabled)
	logf     func(string, ...any)

	// observability: last applied limit/psi, logged when it moves materially.
	lastLogged float64
}

// New builds a Governor with an in-flight ceiling that floats between floor and
// ceiling. floor guarantees forward progress (set it to at least the number of
// concurrent crawls so none starves); ceiling caps the aggregate at today's
// static maximum so the governor can only ever throttle, never over-subscribe.
//
// It probes PSI once: if unreadable or CPU_GOVERNOR is falsey, the governor is
// disabled (pinned at ceiling) and no control loop runs. Otherwise it starts a
// background control loop bound to ctx.
func New(ctx context.Context, floor, ceiling int, logf func(string, ...any)) *Governor {
	if logf == nil {
		logf = func(string, ...any) {}
	}
	if floor < 1 {
		floor = 1
	}
	if ceiling < floor {
		ceiling = floor
	}
	g := &Governor{
		limit:     float64(ceiling), // start optimistic; back off only under pressure
		min:       float64(floor),
		max:       float64(ceiling),
		lowPSI:    envFloat("PSI_LOW", 15),
		highPSI:   envFloat("PSI_HIGH", 50),
		incStep:   envFloat("GOVERNOR_INC_STEP", 2),
		decFactor: envFloat("GOVERNOR_DEC_FACTOR", 0.7),
		psiPath:   getenv("PSI_PATH", "/proc/pressure/cpu"),
		interval:  time.Duration(envFloat("GOVERNOR_INTERVAL_MS", 250)) * time.Millisecond,
		logf:      logf,
	}
	g.cond = sync.NewCond(&g.mu)
	g.lastLogged = g.limit

	if !envBool("CPU_GOVERNOR", true) {
		logf("CPU governor disabled (CPU_GOVERNOR=0) — in-flight pinned at %d", ceiling)
		return g // disabled: limit stays at max, Release still signals waiters
	}
	if _, err := readPSISome10(g.psiPath); err != nil {
		logf("CPU governor: PSI unreadable at %s (%v) — pinned at %d (no throttling)", g.psiPath, err, ceiling)
		return g
	}
	g.enabled = true
	logf("CPU governor enabled — in-flight %d..%d, PSI %s (low=%.0f high=%.0f)", floor, ceiling, g.psiPath, g.lowPSI, g.highPSI)
	go g.run(ctx)
	return g
}

// Acquire blocks until an in-flight slot is free under the current ceiling, then
// reserves it. Returns false (without reserving) if ctx is cancelled while
// waiting — the caller must then NOT call Release. A nil *Governor returns true.
func (g *Governor) Acquire(ctx context.Context) bool {
	if g == nil {
		return true
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	for float64(g.inflight) >= g.limit {
		if ctx.Err() != nil {
			return false
		}
		g.cond.Wait() // woken by Release (always) and the control loop (per tick)
	}
	g.inflight++
	return true
}

// Release frees a slot reserved by a successful Acquire. A nil *Governor is a
// no-op. Never call it for an Acquire that returned false.
func (g *Governor) Release() {
	if g == nil {
		return
	}
	g.mu.Lock()
	if g.inflight > 0 {
		g.inflight--
	}
	g.cond.Signal()
	g.mu.Unlock()
}

// run is the AIMD control loop: every tick, read PSI and nudge the ceiling.
func (g *Governor) run(ctx context.Context) {
	t := time.NewTicker(g.interval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			psi, err := readPSISome10(g.psiPath)
			if err != nil {
				continue // transient read error: hold the current ceiling
			}
			cur, moved := g.adjust(psi)
			if moved {
				g.logf("CPU governor: PSI some/10s=%.1f → in-flight ceiling %.0f", psi, cur)
			}
		}
	}
}

// adjust applies one AIMD step for the given PSI reading and wakes waiters. It
// returns the new ceiling and whether it moved by at least 1 since the last log
// (so the caller logs only meaningful changes). Split out from run for testing.
func (g *Governor) adjust(psi float64) (limit float64, logIt bool) {
	g.mu.Lock()
	defer g.mu.Unlock()
	switch {
	case psi >= g.highPSI:
		// Multiplicative decrease: cut hard so the host recovers fast.
		g.limit = math.Max(g.min, g.limit*g.decFactor)
	case psi < g.lowPSI:
		// Additive increase: probe for more throughput gently.
		g.limit = math.Min(g.max, g.limit+g.incStep)
	}
	// Wake waiters so a raised ceiling admits work and a cancelled ctx is observed
	// within one tick.
	g.cond.Broadcast()
	if math.Abs(g.limit-g.lastLogged) >= 1 {
		g.lastLogged = g.limit
		logIt = true
	}
	return g.limit, logIt
}

// Limit reports the current in-flight ceiling (for tests / introspection).
func (g *Governor) Limit() int {
	if g == nil {
		return 0
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	return int(g.limit)
}

// readPSISome10 parses the `some avg10` field of a PSI file. Format:
//
//	some avg10=0.12 avg60=0.05 avg300=0.01 total=1234567
//	full avg10=0.00 avg60=0.00 avg300=0.00 total=0
//
// The value is a percentage in [0,100]: the share of the last 10s during which
// some task stalled waiting for CPU. Returns an error if the file or field is
// absent (the caller then disables throttling).
func readPSISome10(path string) (float64, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return 0, err
	}
	for _, line := range strings.Split(string(b), "\n") {
		if !strings.HasPrefix(line, "some ") {
			continue
		}
		for _, f := range strings.Fields(line) {
			if v, ok := strings.CutPrefix(f, "avg10="); ok {
				return strconv.ParseFloat(v, 64)
			}
		}
	}
	return 0, os.ErrInvalid
}

func getenv(key, def string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return def
}

func envFloat(key string, def float64) float64 {
	if v, err := strconv.ParseFloat(strings.TrimSpace(os.Getenv(key)), 64); err == nil && v > 0 {
		return v
	}
	return def
}

func envBool(key string, def bool) bool {
	switch strings.ToLower(strings.TrimSpace(os.Getenv(key))) {
	case "":
		return def
	case "0", "false", "no", "off":
		return false
	default:
		return true
	}
}
