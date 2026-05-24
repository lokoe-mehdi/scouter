// The crawl engine fetches over uTLS by default: it sends a real Chrome TLS
// ClientHello (JA3/JA4) instead of Go's default TLS stack, which many anti-bot
// systems fingerprint and block. This is purely a transport-level disguise; the
// crawler still sends the User-Agent from the crawl config and never executes
// JavaScript, so a non-JS crawl keeps returning the raw server response.
//
// Go's net/http transport hardcodes a *tls.Conn assertion on its HTTP/2 path,
// which panics with a uTLS connection, so we cannot reuse the standard h2
// wiring. Instead we keep two transports (a plain http.Transport for HTTP/1.1
// and a golang.org/x/net/http2 Transport), both dialing through uTLS, and route
// to h1 when a host turns out not to speak h2 (cached per host afterwards).
package crawl

import (
	"context"
	"crypto/tls"
	"errors"
	"net"
	"net/http"
	"sync"
	"time"

	utls "github.com/refraction-networking/utls"
	"golang.org/x/net/http2"
)

// errNotH2 is returned by the h2 transport's dialer when the server did not
// negotiate HTTP/2 via ALPN, so RoundTrip can fall back to HTTP/1.1.
var errNotH2 = errors.New("utls: server did not negotiate http/2")

// utlsTransport routes requests over uTLS, preferring HTTP/2 and falling back to
// HTTP/1.1 for hosts that don't negotiate h2. Both underlying transports pool
// connections, so the (expensive) TLS handshake is amortised across requests.
type utlsTransport struct {
	dialer    *net.Dialer
	helloID   utls.ClientHelloID
	tlsConfig *utls.Config // base config (ServerName is set per-dial); seam for tests/custom CAs

	h1 *http.Transport
	h2 *http2.Transport

	mu     sync.RWMutex
	h1Only map[string]bool // hosts known to not support h2
}

func newUTLSTransport(concurrency int) *utlsTransport {
	st := &utlsTransport{
		dialer:  &net.Dialer{Timeout: 3 * time.Second},
		helloID: utls.HelloChrome_Auto,
		// A crawler fetches whatever is served, regardless of certificate
		// validity (matches the old PHP CURLOPT_SSL_VERIFYPEER=false).
		tlsConfig: &utls.Config{InsecureSkipVerify: true},
		h1Only:    map[string]bool{},
	}

	dialTLS := func(ctx context.Context, network, addr string) (net.Conn, error) {
		raw, err := st.dialer.DialContext(ctx, network, addr)
		if err != nil {
			return nil, err
		}
		host, _, err := net.SplitHostPort(addr)
		if err != nil {
			raw.Close()
			return nil, err
		}
		cfg := st.tlsConfig.Clone()
		cfg.ServerName = host
		uconn := utls.UClient(raw, cfg, st.helloID)
		if err := uconn.HandshakeContext(ctx); err != nil {
			raw.Close()
			return nil, err
		}
		return uconn, nil
	}

	st.h1 = &http.Transport{
		MaxIdleConns:        200,
		MaxIdleConnsPerHost: concurrency * 2,
		IdleConnTimeout:     60 * time.Second,
		DialTLSContext:      dialTLS,
		// Empty (non-nil) map disables the transport's own h2 upgrade so it never
		// hits the *tls.Conn assertion that would panic on a uTLS connection.
		TLSNextProto: map[string]func(string, *tls.Conn) http.RoundTripper{},
	}
	st.h2 = &http2.Transport{
		DialTLSContext: func(ctx context.Context, network, addr string, _ *tls.Config) (net.Conn, error) {
			conn, err := dialTLS(ctx, network, addr)
			if err != nil {
				return nil, err
			}
			if uc, ok := conn.(*utls.UConn); ok && uc.ConnectionState().NegotiatedProtocol != http2.NextProtoTLS {
				conn.Close()
				return nil, errNotH2
			}
			return conn, nil
		},
	}
	return st
}

func (st *utlsTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	host := req.URL.Hostname()

	st.mu.RLock()
	h1Only := st.h1Only[host]
	st.mu.RUnlock()
	if h1Only {
		return st.h1.RoundTrip(req)
	}

	resp, err := st.h2.RoundTrip(req)
	if err != nil && errors.Is(err, errNotH2) {
		// Host negotiated http/1.1, not h2: remember it and serve over h1.
		st.mu.Lock()
		st.h1Only[host] = true
		st.mu.Unlock()
		return st.h1.RoundTrip(req)
	}
	return resp, err
}

// newUTLSClient wraps the dual-protocol uTLS transport in an http.Client that
// keeps the engine's redirect policy (never follow) and timeout.
func newUTLSClient(concurrency int, timeout time.Duration) *http.Client {
	return &http.Client{
		Transport: newUTLSTransport(concurrency),
		Timeout:   timeout,
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
}
