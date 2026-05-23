package crawl

import (
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"sync/atomic"
	"testing"
)

func urlHostname(t *testing.T, rawURL string) string {
	t.Helper()
	u, err := url.Parse(rawURL)
	if err != nil {
		t.Fatalf("parse url: %v", err)
	}
	return u.Hostname()
}

func newTestStealth() *stealthTransport {
	st := newStealthTransport(4)
	st.tlsConfig.InsecureSkipVerify = true // test servers use self-signed certs
	return st
}

func doGet(t *testing.T, rt http.RoundTripper, url string) *http.Response {
	t.Helper()
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		t.Fatalf("new request: %v", err)
	}
	resp, err := rt.RoundTrip(req)
	if err != nil {
		t.Fatalf("roundtrip %s: %v", url, err)
	}
	return resp
}

func TestStealthTransportHTTP2(t *testing.T) {
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	srv.EnableHTTP2 = true
	srv.StartTLS()
	defer srv.Close()

	st := newTestStealth()
	resp := doGet(t, st, srv.URL)
	resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		t.Fatalf("status = %d", resp.StatusCode)
	}
	if resp.ProtoMajor != 2 {
		t.Fatalf("proto = %s, want HTTP/2", resp.Proto)
	}
	if st.h1Only[urlHostname(t, srv.URL)] {
		t.Fatalf("h2 host wrongly marked h1-only")
	}
}

func TestStealthTransportHTTP1Fallback(t *testing.T) {
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	st := newTestStealth()
	resp := doGet(t, st, srv.URL)
	resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		t.Fatalf("status = %d", resp.StatusCode)
	}
	if resp.ProtoMajor != 1 {
		t.Fatalf("proto = %s, want HTTP/1.1", resp.Proto)
	}
	if !st.h1Only[urlHostname(t, srv.URL)] {
		t.Fatalf("h1-only host not cached after ALPN fallback")
	}
}

func TestStealthTransportConnReuse(t *testing.T) {
	var conns int32
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	srv.EnableHTTP2 = true
	srv.Config.ConnState = func(_ net.Conn, state http.ConnState) {
		if state == http.StateNew {
			atomic.AddInt32(&conns, 1)
		}
	}
	srv.StartTLS()
	defer srv.Close()

	st := newTestStealth()
	for i := 0; i < 3; i++ {
		resp := doGet(t, st, srv.URL)
		resp.Body.Close()
	}
	if got := atomic.LoadInt32(&conns); got != 1 {
		t.Fatalf("opened %d connections for 3 sequential requests, want 1 (keep-alive)", got)
	}
}
