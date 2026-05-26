package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/go-rod/rod"
	"github.com/go-rod/rod/lib/launcher"
	"github.com/go-rod/rod/lib/proto"
)

// Configuration. MaxConcurrentPages / PagePoolSize are env-tunable so you can
// trade RAM (each pooled Chrome tab ≈ 30-80 MB) for throughput without a rebuild:
//
//	MAX_CONCURRENT_PAGES (default 16) — hard cap on simultaneous renders
//	PAGE_POOL_SIZE       (default 8)  — tabs kept warm for reuse (avoids churn)
//
// Defaults are deliberately modest: a full pool of warm tabs is pure idle RAM
// (poolSize × ~100 MB) that lingers long after a crawl ends, so on a shared box
// it can be what tips the host into swap. The pool reaper (RENDER_POOL_IDLE_SEC)
// closes those warm tabs once no render has happened for a while, so an idle
// renderer drops back to ~zero tabs instead of holding poolSize forever.
const (
	NavigationTimeout = 15 * time.Second
	Port              = 3000
)

var (
	maxConcurrentPages = envIntDefault("MAX_CONCURRENT_PAGES", 16)
	pagePoolSize       = envIntDefault("PAGE_POOL_SIZE", 8)
)

// lastActivityNano holds the UnixNano of the most recent render. The pool reaper
// reads it to decide when the renderer has been idle long enough to close its
// warm tabs. Atomic because it's written from many render goroutines.
var lastActivityNano atomic.Int64

func markActivity() { lastActivityNano.Store(time.Now().UnixNano()) }

// poolIdleTimeout is how long the renderer must go without a render before the
// reaper closes the warm tabs sitting in the pool. Tunable via
// RENDER_POOL_IDLE_SEC; 0 or negative disables the reaper.
func poolIdleTimeout() time.Duration {
	if v, err := strconv.Atoi(os.Getenv("RENDER_POOL_IDLE_SEC")); err == nil {
		return time.Duration(v) * time.Second
	}
	return 120 * time.Second
}

// drainPool closes every page currently parked in the pool and returns how many
// it closed. Pages in the pool are not in use (getPage took them out before a
// render, releasePage puts them back after), so closing them is safe: the next
// render just creates fresh tabs. This is what lets an idle renderer hand its
// RAM back instead of holding poolSize tabs forever.
func drainPool() int {
	closed := 0
	for {
		select {
		case p := <-pagePool:
			p.Close()
			closed++
		default:
			return closed
		}
	}
}

// poolReaper periodically closes the pool's warm tabs once the renderer has been
// idle for idle. It only ever touches parked (unused) pages, so it never
// interrupts an in-flight render.
func poolReaper(idle time.Duration) {
	if idle <= 0 {
		log.Printf("[Rod-Renderer] pool reaper disabled (RENDER_POOL_IDLE_SEC<=0)")
		return
	}
	tick := idle / 2
	if tick < time.Second {
		tick = time.Second
	}
	t := time.NewTicker(tick)
	defer t.Stop()
	for range t.C {
		last := lastActivityNano.Load()
		if last == 0 {
			continue // nothing rendered yet → nothing parked
		}
		if time.Since(time.Unix(0, last)) < idle {
			continue
		}
		if n := drainPool(); n > 0 {
			log.Printf("[Rod-Renderer] pool reaper: closed %d idle tab(s) after %s without activity", n, idle)
		}
	}
}

func envIntDefault(key string, def int) int {
	if v, err := strconv.Atoi(os.Getenv(key)); err == nil && v > 0 {
		return v
	}
	return def
}

// singleProcess reports whether to force Chrome's --single-process (serialized
// rendering). Off by default; set RENDER_SINGLE_PROCESS=1 to re-enable.
func singleProcess() bool {
	v := os.Getenv("RENDER_SINGLE_PROCESS")
	return v == "1" || v == "true"
}

// Structures pour les requêtes/réponses
type RenderRequest struct {
	URL         string            `json:"url"`
	Headers     map[string]string `json:"headers"`
	CheckRobots bool              `json:"checkRobots"`
}

type BatchRenderRequest struct {
	URLs    []interface{}     `json:"urls"`
	Headers map[string]string `json:"headers"`
}

type RenderResponse struct {
	Success      bool    `json:"success"`
	HTML         string  `json:"html"`
	HTTPCode     int     `json:"httpCode"`
	ResponseTime float64 `json:"responseTime"`
	FinalURL     string  `json:"finalUrl"`
	JSRedirect   bool    `json:"jsRedirect"`
	Error        string  `json:"error,omitempty"`
	URL          string  `json:"url,omitempty"`
}

type BatchRenderResponse struct {
	Results []RenderResponse `json:"results"`
}

// Browser pool
var (
	browser     *rod.Browser
	browserLock sync.Mutex
	pagePool    chan *rod.Page
	semaphore   chan struct{}
)

func initBrowser() error {
	browserLock.Lock()
	defer browserLock.Unlock()

	if browser != nil {
		return nil
	}

	// Utiliser ROD_BROWSER si défini, sinon chercher
	chromePath := os.Getenv("ROD_BROWSER")
	if chromePath == "" {
		var found bool
		chromePath, found = launcher.LookPath()
		if !found {
			// Essayer les chemins communs
			paths := []string{"/usr/bin/chromium", "/usr/bin/chromium-browser", "/usr/bin/google-chrome"}
			for _, p := range paths {
				if _, err := os.Stat(p); err == nil {
					chromePath = p
					break
				}
			}
		}
	}

	log.Printf("[Rod-Renderer] Using Chrome at: %s", chromePath)

	// Lancer Chrome avec les options optimisées pour VPS/Docker
	u := launcher.New().
		Bin(chromePath).
		Headless(true).
		Set("disable-gpu").
		Set("no-sandbox").
		Set("disable-setuid-sandbox").
		Set("disable-dev-shm-usage").
		Set("disable-background-networking").
		Set("disable-default-apps").
		Set("disable-extensions").
		Set("disable-sync").
		Set("disable-translate").
		Set("metrics-recording-only").
		Set("mute-audio").
		Set("no-first-run").
		Set("disable-hang-monitor").
		Set("disable-popup-blocking").
		Set("disable-prompt-on-repost").
		Set("disable-client-side-phishing-detection").
		Set("disable-component-update").
		Set("disable-domain-reliability").
		Set("disable-features", "TranslateUI,IsolateOrigins,site-per-process").
		Set("disable-ipc-flooding-protection").
		Set("disable-renderer-backgrounding").
		Set("disable-backgrounding-occluded-windows").
		Set("disable-breakpad").
		// SEO crawl: we only need the rendered DOM (text + links), never the
		// pixels. Disabling image decoding/loading cuts most of the per-page
		// network + CPU cost. Toggle off with RENDER_LOAD_IMAGES=1 if needed.
		Set("blink-settings", imagesSetting())

	// --single-process force TOUT Chrome dans un seul process/thread → le rendu
	// des pages est sérialisé (tueur de parallélisme). On le laisse DÉSACTIVÉ par
	// défaut : --disable-dev-shm-usage (ci-dessus) suffit à stabiliser le mode
	// multi-process en conteneur. Réactivable via RENDER_SINGLE_PROCESS=1 si tu
	// observes des crashs Chrome sur un environnement très contraint.
	if singleProcess() {
		u.Set("single-process")
		log.Printf("[Rod-Renderer] single-process mode ON (rendering serialized)")
	} else {
		log.Printf("[Rod-Renderer] multi-process mode (parallel rendering)")
	}

	// Lancer avec gestion d'erreur
	wsURL, err := u.Launch()
	if err != nil {
		log.Printf("[Rod-Renderer] ERROR launching Chrome: %v", err)
		return fmt.Errorf("failed to launch Chrome: %w", err)
	}

	log.Printf("[Rod-Renderer] Chrome launched, connecting...")

	browser = rod.New().ControlURL(wsURL).MustConnect()

	// Initialiser le pool de pages
	pagePool = make(chan *rod.Page, pagePoolSize)
	semaphore = make(chan struct{}, maxConcurrentPages)

	log.Printf("[Rod-Renderer] Browser initialized, pool size: %d, max concurrent: %d", pagePoolSize, maxConcurrentPages)
	return nil
}

// imagesSetting returns the blink-settings value controlling image loading.
// Default: images disabled (fastest for SEO crawling). Set RENDER_LOAD_IMAGES=1
// to load them again.
func imagesSetting() string {
	if v := os.Getenv("RENDER_LOAD_IMAGES"); v == "1" || v == "true" {
		return "imagesEnabled=true"
	}
	return "imagesEnabled=false"
}

// stableTimeout caps the WaitStable settle wait (default 5s). Tunable via
// RENDER_STABLE_TIMEOUT_MS. Kept well under NavigationTimeout so pages that never
// go idle don't burn the full navigation budget each.
func stableTimeout() time.Duration {
	if v, err := strconv.Atoi(os.Getenv("RENDER_STABLE_TIMEOUT_MS")); err == nil && v > 0 {
		return time.Duration(v) * time.Millisecond
	}
	return 5 * time.Second
}

func getPage() (*rod.Page, error) {
	markActivity() // keeps the pool reaper from closing tabs mid-crawl

	// Acquérir un slot
	semaphore <- struct{}{}

	// Essayer de récupérer une page du pool
	select {
	case page := <-pagePool:
		return page, nil
	default:
		// Créer une nouvelle page. MustPage panique si le navigateur est mort :
		// on récupère le panic (rod.Try) pour NE PAS fuiter le slot du sémaphore
		// déjà acquis (l'ancien code laissait le slot bloqué à jamais → renderer
		// qui se fige peu à peu).
		var page *rod.Page
		if err := rod.Try(func() { page = browser.MustPage("") }); err != nil {
			<-semaphore // rendre le slot avant de remonter l'erreur
			return nil, err
		}
		return page, nil
	}
}

func releasePage(page *rod.Page) {
	// Le slot est TOUJOURS rendu, même si le nettoyage de la page panique (onglet
	// Chrome mort) — sinon le slot fuit et le pool finit par se bloquer entièrement.
	defer func() { <-semaphore }()

	// Reset pour réutilisation ; si la page est morte (panic), on la jette au lieu
	// de la remettre dans le pool (une page cassée empoisonnerait les rendus suivants).
	if err := rod.Try(func() { page.MustNavigate("about:blank") }); err != nil {
		_ = rod.Try(func() { page.Close() })
		return
	}

	select {
	case pagePool <- page:
		// remise au pool OK
	default:
		// Pool plein : fermer la page (close protégé contre un panic de tab mort).
		_ = rod.Try(func() { page.Close() })
	}
}

func renderURL(urlStr string, headers map[string]string) RenderResponse {
	page, err := getPage()
	if err != nil {
		return RenderResponse{Success: false, Error: "renderer page unavailable: " + err.Error(), URL: urlStr, FinalURL: urlStr}
	}
	defer releasePage(page)

	// Configurer les headers (Rod prend des paires key, value). Protégé : un
	// MustSetExtraHeaders qui panique ne doit pas court-circuiter releasePage.
	if len(headers) > 0 {
		args := make([]string, 0, len(headers)*2)
		for k, v := range headers {
			args = append(args, k, v)
		}
		_ = rod.Try(func() { page.MustSetExtraHeaders(args...) })
	}

	// Variables pour capturer le code HTTP et le TTFB
	httpCode := 200
	var ttfb float64 = 0
	var ttfbCaptured bool = false
	var timingMu sync.Mutex
	var responseErr error

	// navStart est pris APRÈS getPage()+headers : sinon l'attente d'un slot du
	// pool (sous forte charge) serait comptée dans le "TTFB" et le gonflerait.
	navStart := time.Now()

	// Écouter les réponses pour capturer le code HTTP et le VRAI TTFB du document.
	// Le listener est scopé à CE rendu via un contexte annulable : sans ça, comme
	// les pages sont RÉUTILISÉES depuis le pool, chaque rendu empilait une goroutine
	// EachEvent qui ne s'arrêtait jamais → fuite de goroutines/RAM proportionnelle
	// au nombre de pages rendues (mortel sur un long crawl). cancelListener (defer)
	// garantit l'arrêt de la goroutine au retour de renderURL.
	listenerCtx, cancelListener := context.WithCancel(context.Background())
	defer cancelListener()
	go page.Context(listenerCtx).EachEvent(func(e *proto.NetworkResponseReceived) {
		if e.Type == proto.NetworkResourceTypeDocument {
			timingMu.Lock()
			if !ttfbCaptured {
				httpCode = e.Response.Status
				// Vrai TTFB serveur = temps entre l'ENVOI de la requête et la
				// réception des en-têtes (sendEnd → receiveHeadersEnd). On EXCLUT
				// la phase connexion (DNS/TCP/TLS) ET surtout la mise en file
				// d'attente de Chrome (~6 connexions/host) qui, sous forte
				// concurrence sur un même domaine, gonflait le chiffre. C'est ce
				// qui rend la valeur comparable au TTFB du mode classic (keep-alive).
				t := e.Response.Timing
				switch {
				case t != nil && t.ReceiveHeadersEnd > 0 && t.SendEnd > 0 && t.ReceiveHeadersEnd > t.SendEnd:
					ttfb = (t.ReceiveHeadersEnd - t.SendEnd) / 1000.0 // ms → s
				case t != nil && t.ReceiveHeadersEnd > 0:
					ttfb = t.ReceiveHeadersEnd / 1000.0
				default:
					ttfb = time.Since(navStart).Seconds()
				}
				ttfbCaptured = true
			}
			timingMu.Unlock()
		}
	})()

	// Navigation avec timeout
	if navErr := page.Timeout(NavigationTimeout).Navigate(urlStr); navErr != nil {
		responseErr = navErr
	}

	// Attendre que le DOM soit stable (50ms sans changement). Plafonné par un
	// timeout DÉDIÉ plus court que la navigation : sinon une page qui mute en
	// continu (animation, polling, analytics) ne se stabilise jamais et attend
	// les 15s complètes — c'est le principal tueur de débit en mode JS. Le
	// Navigate ci-dessus a déjà attendu l'évènement `load`, donc le contenu est
	// là ; on ne fait qu'accorder un court délai de décantation.
	if responseErr == nil {
		page.Timeout(stableTimeout()).WaitStable(50 * time.Millisecond)
	}

	// Récupérer le contenu
	html := ""
	finalURL := urlStr
	if responseErr == nil {
		html, _ = page.HTML()
		info, _ := page.Info()
		if info != nil {
			finalURL = info.URL
		}
	}

	// Si TTFB pas capturé (erreur), utiliser le temps depuis le début de la nav
	timingMu.Lock()
	responseTime := ttfb
	captured := ttfbCaptured
	timingMu.Unlock()
	if !captured {
		responseTime = time.Since(navStart).Seconds()
	}

	if responseErr != nil {
		return RenderResponse{
			Success:      false,
			HTTPCode:     httpCode,
			ResponseTime: responseTime,
			Error:        responseErr.Error(),
			URL:          urlStr,
			FinalURL:     finalURL,
		}
	}

	// Détecter redirection JS
	jsRedirect := finalURL != urlStr && !strings.HasPrefix(finalURL, "about:")

	return RenderResponse{
		Success:      true,
		HTML:         html,
		HTTPCode:     httpCode,
		ResponseTime: responseTime,
		FinalURL:     finalURL,
		JSRedirect:   jsRedirect,
		URL:          urlStr,
	}
}

func handleRender(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req RenderRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "Invalid JSON", http.StatusBadRequest)
		return
	}

	if req.URL == "" {
		http.Error(w, "URL required", http.StatusBadRequest)
		return
	}

	result := renderURL(req.URL, req.Headers)

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(result)
}

func handleBatchRender(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req BatchRenderRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "Invalid JSON", http.StatusBadRequest)
		return
	}

	if len(req.URLs) == 0 {
		http.Error(w, "URLs array required", http.StatusBadRequest)
		return
	}

	// Limiter à 20 URLs max
	urls := req.URLs
	if len(urls) > 20 {
		urls = urls[:20]
	}

	// Traiter en parallèle avec WaitGroup
	var wg sync.WaitGroup
	results := make([]RenderResponse, len(urls))

	for i, urlData := range urls {
		wg.Add(1)
		go func(idx int, data interface{}) {
			defer wg.Done()

			// Extraire l'URL (peut être string ou object)
			var url string
			switch v := data.(type) {
			case string:
				url = v
			case map[string]interface{}:
				if u, ok := v["url"].(string); ok {
					url = u
				}
			}

			if url != "" {
				results[idx] = renderURL(url, req.Headers)
			} else {
				results[idx] = RenderResponse{
					Success: false,
					Error:   "Invalid URL format",
				}
			}
		}(i, urlData)
	}

	wg.Wait()

	response := BatchRenderResponse{Results: results}
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(response)
}

func handleHealth(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"status": "ok", "engine": "rod"})
}

func main() {
	log.Println("[Rod-Renderer] Starting Go renderer with Rod...")

	if err := initBrowser(); err != nil {
		log.Fatalf("Failed to init browser: %v", err)
	}

	idle := poolIdleTimeout()
	log.Printf("[Rod-Renderer] pool reaper: closing warm tabs after %s idle", idle)
	go poolReaper(idle)

	http.HandleFunc("/render", handleRender)
	http.HandleFunc("/render-batch", handleBatchRender)
	http.HandleFunc("/health", handleHealth)

	addr := fmt.Sprintf(":%d", Port)
	log.Printf("[Rod-Renderer] Listening on %s", addr)
	log.Fatal(http.ListenAndServe(addr, nil))
}
