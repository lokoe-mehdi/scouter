// Stealth HTTP client: impersonates a real Chrome browser end-to-end so anti-bot
// systems can't fingerprint the crawler. It combines, via bogdanfinn/tls-client:
//   - the Chrome TLS ClientHello (JA3/JA4), with randomised extension order;
//   - the Chrome HTTP/2 fingerprint (SETTINGS, window update, priority, and the
//     :method/:authority/:scheme/:path pseudo-header order — all from the profile);
//   - a coherent Chrome header set + header order (so the UA, client hints and
//     TLS fingerprint all tell the same story).
//
// It is used either always (stealth_mode=always) or as an automatic fallback when
// a normal request is blocked (stealth_mode=auto).
package crawl

import (
	stdhttp "net/http"
	"strings"
	"time"

	fhttp "github.com/bogdanfinn/fhttp"
	tls_client "github.com/bogdanfinn/tls-client"
	"github.com/bogdanfinn/tls-client/profiles"
)

// stealthProfile is the browser whose TLS + HTTP/2 fingerprint we impersonate.
// Keep stealthUserAgent's Chrome major version in sync with it.
var stealthProfile = profiles.Chrome_133

const stealthUserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36"

// chromeHeaders is the default header set a desktop Chrome sends on a top-level
// navigation GET. Keys are lowercase (HTTP/2 convention); chromeHeaderOrder gives
// the exact emission order. Caller-provided headers override these by value.
var chromeHeaders = map[string][]string{
	"sec-ch-ua":                 {`"Not(A:Brand";v="99", "Google Chrome";v="133", "Chromium";v="133"`},
	"sec-ch-ua-mobile":          {"?0"},
	"sec-ch-ua-platform":        {`"Windows"`},
	"upgrade-insecure-requests": {"1"},
	"user-agent":                {stealthUserAgent},
	"accept":                    {"text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7"},
	"sec-fetch-site":            {"none"},
	"sec-fetch-mode":            {"navigate"},
	"sec-fetch-user":            {"?1"},
	"sec-fetch-dest":            {"document"},
	"accept-encoding":           {"gzip, deflate, br, zstd"},
	"accept-language":           {"en-US,en;q=0.9"},
	"priority":                  {"u=0, i"},
}

var chromeHeaderOrder = []string{
	"sec-ch-ua", "sec-ch-ua-mobile", "sec-ch-ua-platform", "upgrade-insecure-requests",
	"user-agent", "accept", "sec-fetch-site", "sec-fetch-mode", "sec-fetch-user",
	"sec-fetch-dest", "accept-encoding", "accept-language", "priority",
}

// stealthTransport adapts a tls-client (which speaks bogdanfinn/fhttp) to the
// standard net/http.RoundTripper the engine uses, so the rest of the crawler is
// unchanged. It injects the Chrome header block and decompresses responses (fhttp
// does not auto-decompress when Accept-Encoding is set explicitly).
type stealthTransport struct {
	client tls_client.HttpClient
}

func (s *stealthTransport) RoundTrip(req *stdhttp.Request) (*stdhttp.Response, error) {
	freq, err := fhttp.NewRequestWithContext(req.Context(), req.Method, req.URL.String(), req.Body)
	if err != nil {
		return nil, err
	}
	freq.Header = buildStealthHeaders(req.Header)

	fresp, err := s.client.Do(freq)
	if err != nil {
		return nil, err
	}
	fresp.Body = fhttp.DecompressBody(fresp)
	fresp.Header.Del("Content-Encoding")

	return &stdhttp.Response{
		Status:        fresp.Status,
		StatusCode:    fresp.StatusCode,
		Proto:         fresp.Proto,
		ProtoMajor:    fresp.ProtoMajor,
		ProtoMinor:    fresp.ProtoMinor,
		Header:        stdhttp.Header(fresp.Header),
		Body:          fresp.Body,
		ContentLength: -1, // unknown after decompression; engine reads via io.ReadAll
		Request:       req,
	}, nil
}

// buildStealthHeaders starts from the Chrome default headers and overlays the
// caller's headers (custom headers, basic auth) by value, appending any unknown
// header to the end of the order so the request still looks browser-like.
func buildStealthHeaders(caller stdhttp.Header) fhttp.Header {
	h := fhttp.Header{}
	for k, v := range chromeHeaders {
		h[k] = append([]string(nil), v...)
	}
	order := append([]string(nil), chromeHeaderOrder...)
	for k, vv := range caller {
		lk := strings.ToLower(k)
		if lk == "host" {
			continue
		}
		if _, exists := h[lk]; !exists {
			order = append(order, lk)
		}
		h[lk] = vv
	}
	h[fhttp.HeaderOrderKey] = order
	return h
}

// newStealthClient builds the Chrome-impersonating client wrapped in a net/http
// client that keeps the engine's redirect policy (never follow). insecure skips
// TLS verification (tests only).
func newStealthClient(timeout time.Duration, insecure bool) (*stdhttp.Client, error) {
	opts := []tls_client.HttpClientOption{
		tls_client.WithClientProfile(stealthProfile),
		tls_client.WithNotFollowRedirects(),
		tls_client.WithRandomTLSExtensionOrder(),
		tls_client.WithCookieJar(tls_client.NewCookieJar()),
		tls_client.WithTimeoutSeconds(int(timeout.Seconds())),
	}
	if insecure {
		opts = append(opts, tls_client.WithInsecureSkipVerify())
	}
	c, err := tls_client.NewHttpClient(tls_client.NewNoopLogger(), opts...)
	if err != nil {
		return nil, err
	}
	return &stdhttp.Client{
		Transport: &stealthTransport{client: c},
		Timeout:   timeout,
		CheckRedirect: func(*stdhttp.Request, []*stdhttp.Request) error {
			return stdhttp.ErrUseLastResponse
		},
	}, nil
}
