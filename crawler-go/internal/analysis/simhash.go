package analysis

import (
	"math/bits"
	"regexp"
	"strings"
	"unicode/utf8"

	"golang.org/x/net/html"
)

// Simhash ports app/Analysis/Simhash.php bit-for-bit. Divergence here means the
// duplicate_clusters of Go crawls would not be comparable to PHP crawls, so the
// algorithm (normalize → 3-word shingles → 64-bit CRC vote) is reproduced exactly.

var (
	stripTagsRe = regexp.MustCompile(`<[^>]*>`)
	nonWordRe   = regexp.MustCompile(`[^\p{L}\p{N}\s]`)
	wsSplitRe   = regexp.MustCompile(`\s+`)
)

// ComputeSimhash returns the 64-bit simhash as int64 (the value PHP stores in the
// BIGINT column), or nil when the text yields no tokens.
func ComputeSimhash(text string) *int64 {
	text = simhashNormalize(text)
	if text == "" {
		return nil
	}
	tokens := simhashTokenize(text, 3)
	if len(tokens) == 0 {
		return nil
	}

	var v [64]int
	for _, tok := range tokens {
		h := simhashHash64(tok)
		for i := 0; i < 64; i++ {
			if (h>>uint(i))&1 == 1 {
				v[i]++
			} else {
				v[i]--
			}
		}
	}

	var sh uint64
	for i := 0; i < 64; i++ {
		if v[i] > 0 {
			sh |= uint64(1) << uint(i)
		}
	}
	out := int64(sh) // same bit pattern PHP stores in BIGINT
	return &out
}

// HammingDistance mirrors Simhash::hammingDistance.
func HammingDistance(a, b int64) int {
	return bits.OnesCount64(uint64(a) ^ uint64(b))
}

func simhashNormalize(text string) string {
	text = stripTagsRe.ReplaceAllString(text, "")
	text = html.UnescapeString(text)
	text = strings.ToLower(text)
	text = nonWordRe.ReplaceAllString(text, " ")
	repl := strings.NewReplacer("\r\n", " ", "\r", " ", "\n", " ", "\t", " ")
	text = repl.Replace(text)
	for strings.Contains(text, "  ") {
		text = strings.ReplaceAll(text, "  ", " ")
	}
	return strings.TrimSpace(text)
}

func simhashTokenize(text string, shingleSize int) []string {
	parts := wsSplitRe.Split(text, -1)
	words := make([]string, 0, len(parts))
	for _, w := range parts {
		if w == "" {
			continue
		}
		if utf8.RuneCountInString(w) > 2 { // mb_strlen($w) > 2
			words = append(words, w)
		}
	}
	if len(words) < shingleSize {
		return words
	}
	shingles := make([]string, 0, len(words)-shingleSize+1)
	for i := 0; i <= len(words)-shingleSize; i++ {
		shingles = append(shingles, strings.Join(words[i:i+shingleSize], " "))
	}
	return shingles
}

// simhashHash64 ports Simhash::hash64 on a 64-bit platform:
//
//	hash1 = crc32(token)
//	hash2 = crc32(strrev(token) . token)
//	return (hash1 << 32) | (hash2 & 0xFFFFFFFF)
func simhashHash64(token string) uint64 {
	h1 := crc32IEEE([]byte(token))
	h2 := crc32IEEE([]byte(reverseBytes(token) + token))
	return uint64(h1)<<32 | uint64(h2)
}

// reverseBytes mirrors PHP strrev (byte-wise reversal, not rune-aware).
func reverseBytes(s string) string {
	b := []byte(s)
	for i, j := 0, len(b)-1; i < j; i, j = i+1, j-1 {
		b[i], b[j] = b[j], b[i]
	}
	return string(b)
}
