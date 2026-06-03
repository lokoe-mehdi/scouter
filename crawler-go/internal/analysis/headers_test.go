package analysis

import (
	"net/http"
	"testing"
)

func TestApplyBrowserHeaders_ChromeUA(t *testing.T) {
	const ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
	req, _ := http.NewRequest(http.MethodGet, "https://example.com/", nil)
	ApplyBrowserHeaders(req, ua)

	if got := req.Header.Get("User-Agent"); got != ua {
		t.Fatalf("User-Agent not verbatim: got %q", got)
	}
	for _, h := range []string{"Accept", "Accept-Language", "Accept-Encoding", "Upgrade-Insecure-Requests",
		"Sec-Fetch-Dest", "Sec-Fetch-Mode", "Sec-Fetch-Site", "Sec-Fetch-User"} {
		if req.Header.Get(h) == "" {
			t.Errorf("missing browser header %q", h)
		}
	}
	if got := req.Header.Get("sec-ch-ua"); got == "" || !contains(got, `v="124"`) {
		t.Errorf("sec-ch-ua not derived from UA major: %q", got)
	}
	if got := req.Header.Get("sec-ch-ua-platform"); got != `"Windows"` {
		t.Errorf("sec-ch-ua-platform = %q, want \"Windows\"", got)
	}
	if got := req.Header.Get("sec-ch-ua-mobile"); got != "?0" {
		t.Errorf("sec-ch-ua-mobile = %q, want ?0", got)
	}
}

func TestApplyBrowserHeaders_NonChromeUA_NoClientHints(t *testing.T) {
	// A Firefox UA must NOT get sec-ch-ua headers (Firefox doesn't send them).
	const ua = "Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0"
	req, _ := http.NewRequest(http.MethodGet, "https://example.com/", nil)
	ApplyBrowserHeaders(req, ua)

	if got := req.Header.Get("User-Agent"); got != ua {
		t.Fatalf("User-Agent not verbatim: got %q", got)
	}
	if got := req.Header.Get("sec-ch-ua"); got != "" {
		t.Errorf("sec-ch-ua should be absent for Firefox UA, got %q", got)
	}
}

func contains(s, sub string) bool {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return true
		}
	}
	return false
}
