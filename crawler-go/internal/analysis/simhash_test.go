package analysis

import "testing"

// Golden values generated with PHP 8.3 App\Analysis\Simhash::compute().
func TestComputeSimhash(t *testing.T) {
	cases := []struct {
		text string
		want int64
	}{
		{"The quick brown fox jumps over the lazy dog. This is a test of the simhash algorithm.", 7570573804951840664},
		{"Bonjour le monde ceci est un test de contenu en français avec des accents éàù.", -6747516482691475767},
		{"short text", 795026632549273090},
		{"<p>Some <b>HTML</b> content</p> with tags &amp; entities that should be normalized properly here.", -6278325369429224847},
	}
	for _, c := range cases {
		got := ComputeSimhash(c.text)
		if got == nil {
			t.Errorf("ComputeSimhash(%q) = nil, want %d", c.text, c.want)
			continue
		}
		if *got != c.want {
			t.Errorf("ComputeSimhash(%q) = %d, want %d", c.text, *got, c.want)
		}
	}
}
