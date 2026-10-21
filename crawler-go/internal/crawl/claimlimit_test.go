package crawl

import "testing"

func TestClaimLimit(t *testing.T) {
	cases := []struct {
		max     int
		crawled int64
		want    int
		ok      bool
	}{
		{0, 99999, frontierBatch, true}, // uncapped
		{50, 0, 50, true},               // first claim = whole budget
		{500, 480, 20, true},            // only the remainder
		{20000, 0, frontierBatch, true}, // budget above one batch
		{50, 50, 0, false},              // spent
		{50, 53, 0, false},              // overshoot (retries) still stops
	}
	for _, c := range cases {
		got, ok := claimLimit(c.max, c.crawled, frontierBatch)
		if got != c.want || ok != c.ok {
			t.Errorf("claimLimit(%d,%d)=%d,%v want %d,%v", c.max, c.crawled, got, ok, c.want, c.ok)
		}
	}
}
