package analysis

import "testing"

// Golden values generated with PHP 8.3:  php -r 'echo hash("crc32", $url);'
func TestPageID(t *testing.T) {
	cases := map[string]string{
		"abc":                              "73bb8c64",
		"123456789":                        "181989fc",
		"https://example.com/":             "c53a6ada",
		"https://lokoe.fr/scouter":         "e0460585",
		"https://example.com/page?a=1&b=2": "75220e70",
		"héllo/wörld":                      "f6bdca6c",
	}
	for url, want := range cases {
		if got := PageID(url); got != want {
			t.Errorf("PageID(%q) = %s, want %s", url, got, want)
		}
	}
}
