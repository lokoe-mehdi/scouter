package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/go-rod/rod"
	"github.com/go-rod/rod/lib/launcher"
	"github.com/go-rod/rod/lib/proto"
)

// Configuration
const (
	MaxConcurrentPages = 50
	NavigationTimeout  = 15 * time.Second
	PagePoolSize       = 20
	Port               = 3000
)

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
		Set("single-process").
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
		Set("disable-breakpad")
	
	// Lancer avec gestion d'erreur
	wsURL, err := u.Launch()
	if err != nil {
		log.Printf("[Rod-Renderer] ERROR launching Chrome: %v", err)
		return fmt.Errorf("failed to launch Chrome: %w", err)
	}
	
	log.Printf("[Rod-Renderer] Chrome launched, connecting...")
	
	browser = rod.New().ControlURL(wsURL).MustConnect()

	// Initialiser le pool de pages
	pagePool = make(chan *rod.Page, PagePoolSize)
	semaphore = make(chan struct{}, MaxConcurrentPages)

	log.Printf("[Rod-Renderer] Browser initialized, pool size: %d, max concurrent: %d", PagePoolSize, MaxConcurrentPages)
	return nil
}

func getPage() *rod.Page {
	// Acquérir un slot
	semaphore <- struct{}{}

	// Essayer de récupérer une page du pool
	select {
	case page := <-pagePool:
		return page
	default:
		// Créer une nouvelle page
		page := browser.MustPage("")
		return page
	}
}

func releasePage(page *rod.Page) {
	// Nettoyer la page
	page.MustNavigate("about:blank")

	// Remettre dans le pool si possible
	select {
	case pagePool <- page:
		// OK
	default:
		// Pool plein, fermer la page
		page.Close()
	}

	// Libérer le slot
	<-semaphore
}

func renderURL(urlStr string, headers map[string]string) RenderResponse {
	startTime := time.Now()

	page := getPage()
	defer releasePage(page)

	// Configurer les headers (Rod prend des paires key, value)
	if len(headers) > 0 {
		args := make([]string, 0, len(headers)*2)
		for k, v := range headers {
			args = append(args, k, v)
		}
		page.MustSetExtraHeaders(args...)
	}

	// Variables pour capturer le code HTTP et le TTFB
	httpCode := 200
	var ttfb float64 = 0
	var ttfbCaptured bool = false
	var responseErr error

	// Écouter les réponses pour capturer le code HTTP et le TTFB du document principal
	go page.EachEvent(func(e *proto.NetworkResponseReceived) {
		if e.Type == proto.NetworkResourceTypeDocument && !ttfbCaptured {
			httpCode = e.Response.Status
			// Capturer le TTFB = temps depuis le début jusqu'à la première réponse
			ttfb = time.Since(startTime).Seconds()
			ttfbCaptured = true
		}
	})()

	// Navigation avec timeout
	err := page.Timeout(NavigationTimeout).Navigate(urlStr)
	if err != nil {
		responseErr = err
	}

	// Attendre que le DOM soit stable (50ms sans changement)
	if responseErr == nil {
		page.Timeout(NavigationTimeout).WaitStable(50 * time.Millisecond)
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

	// Si TTFB pas capturé (erreur), utiliser le temps total
	responseTime := ttfb
	if !ttfbCaptured {
		responseTime = time.Since(startTime).Seconds()
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

	http.HandleFunc("/render", handleRender)
	http.HandleFunc("/render-batch", handleBatchRender)
	http.HandleFunc("/health", handleHealth)

	addr := fmt.Sprintf(":%d", Port)
	log.Printf("[Rod-Renderer] Listening on %s", addr)
	log.Fatal(http.ListenAndServe(addr, nil))
}
