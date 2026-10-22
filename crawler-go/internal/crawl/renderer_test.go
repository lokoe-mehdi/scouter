package crawl

import (
	"testing"

	"scouter-crawler/internal/config"
)

func TestRenderHeadersIncludesHTTPBasicAuth(t *testing.T) {
	e := &Engine{
		cfg: &config.Config{
			UserAgent: "Scouter/Test",
			CustomHeaders: map[string]string{
				"X-Test":        "1",
				"Authorization": "Bearer stale",
			},
			HTTPAuth: config.HTTPAuth{
				Enabled:  true,
				Username: "user",
				Password: "pass",
			},
		},
	}

	headers := e.renderHeaders()

	if headers["User-Agent"] != "Scouter/Test" {
		t.Fatalf("User-Agent=%q", headers["User-Agent"])
	}
	if headers["X-Test"] != "1" {
		t.Fatalf("X-Test=%q", headers["X-Test"])
	}
	if headers["Authorization"] != "Basic dXNlcjpwYXNz" {
		t.Fatalf("Authorization=%q", headers["Authorization"])
	}
}
