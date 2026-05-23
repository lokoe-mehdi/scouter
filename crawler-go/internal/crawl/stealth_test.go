package crawl

import (
	"bytes"
	"compress/gzip"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func newInsecureStealthClient(t *testing.T) *http.Client {
	t.Helper()
	c, err := newStealthClient(5*time.Second, true)
	if err != nil {
		t.Fatalf("newStealthClient: %v", err)
	}
	return c
}

// TestStealthClientChromeFingerprint checks the request reaches the server over
// HTTP/2 carrying a coherent Chrome header set (UA + client hints), i.e. the
// crawler no longer self-identifies as Scouter in stealth mode.
func TestStealthClientChromeFingerprint(t *testing.T) {
	var gotUA, gotChUa string
	var gotProtoMajor int
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotUA = r.Header.Get("User-Agent")
		gotChUa = r.Header.Get("Sec-Ch-Ua")
		gotProtoMajor = r.ProtoMajor
		w.WriteHeader(http.StatusOK)
	}))
	srv.EnableHTTP2 = true
	srv.StartTLS()
	defer srv.Close()

	resp, err := newInsecureStealthClient(t).Get(srv.URL)
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	resp.Body.Close()

	if gotProtoMajor != 2 {
		t.Fatalf("server saw HTTP/%d, want HTTP/2", gotProtoMajor)
	}
	if want := "Chrome/133"; !contains(gotUA, want) {
		t.Fatalf("user-agent = %q, want it to contain %q", gotUA, want)
	}
	if gotChUa == "" {
		t.Fatalf("sec-ch-ua header missing (client hints not sent)")
	}
}

// TestStealthClientNoFollowRedirect verifies the stealth client returns 3xx as-is
// (the engine resolves Location itself and never follows).
func TestStealthClientNoFollowRedirect(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/redir" {
			w.Header().Set("Location", "/dest")
			w.WriteHeader(http.StatusFound)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	resp, err := newInsecureStealthClient(t).Get(srv.URL + "/redir")
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	resp.Body.Close()

	if resp.StatusCode != http.StatusFound {
		t.Fatalf("status = %d, want 302 (redirect not followed)", resp.StatusCode)
	}
	if loc := resp.Header.Get("Location"); loc != "/dest" {
		t.Fatalf("Location = %q, want /dest", loc)
	}
}

// TestStealthClientDecompressesBody guards the manual decompression: the stealth
// client advertises br/zstd/gzip, so fhttp won't auto-decompress and we must.
func TestStealthClientDecompressesBody(t *testing.T) {
	const payload = "<html>bonjour</html>"
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		var buf bytes.Buffer
		gz := gzip.NewWriter(&buf)
		gz.Write([]byte(payload))
		gz.Close()
		w.Header().Set("Content-Encoding", "gzip")
		w.Write(buf.Bytes())
	}))
	defer srv.Close()

	resp, err := newInsecureStealthClient(t).Get(srv.URL)
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)

	if string(body) != payload {
		t.Fatalf("body = %q, want decompressed %q", string(body), payload)
	}
}

func TestBuildStealthHeadersOverride(t *testing.T) {
	caller := http.Header{
		"User-Agent":   {"Custom/1.0"},
		"X-Api-Key":    {"secret"},
		"Content-Type": {"application/json"},
	}
	h := buildStealthHeaders(caller)

	// fhttp keeps lowercase keys for the HTTP/2 wire, so read the map directly.
	if got := h["user-agent"]; len(got) != 1 || got[0] != "Custom/1.0" {
		t.Fatalf("custom user-agent not honored: %v", got)
	}
	if len(h["sec-ch-ua"]) == 0 {
		t.Fatalf("chrome default sec-ch-ua dropped")
	}
	order := h["Header-Order:"]
	if !inOrder(order, "x-api-key") || !inOrder(order, "content-type") {
		t.Fatalf("custom headers not appended to order: %v", order)
	}
	if !inOrder(order, "user-agent") {
		t.Fatalf("chrome header missing from order: %v", order)
	}
}

func contains(s, sub string) bool { return bytes.Contains([]byte(s), []byte(sub)) }

func inOrder(order []string, key string) bool {
	for _, k := range order {
		if k == key {
			return true
		}
	}
	return false
}
