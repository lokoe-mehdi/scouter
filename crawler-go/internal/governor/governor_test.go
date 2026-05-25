package governor

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestReadPSISome10(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "cpu")
	content := "some avg10=12.34 avg60=5.00 avg300=1.00 total=999\n" +
		"full avg10=0.00 avg60=0.00 avg300=0.00 total=0\n"
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
	v, err := readPSISome10(path)
	if err != nil {
		t.Fatalf("readPSISome10: %v", err)
	}
	if v != 12.34 {
		t.Fatalf("got %v, want 12.34", v)
	}

	if _, err := readPSISome10(filepath.Join(dir, "missing")); err == nil {
		t.Fatal("expected error for missing file")
	}
}

func TestAIMD(t *testing.T) {
	// Disabled-by-env so New skips the background loop; we drive adjust() directly.
	t.Setenv("CPU_GOVERNOR", "0")
	g := New(context.Background(), 2, 100, nil)
	// New starts at the ceiling.
	if got := g.Limit(); got != 100 {
		t.Fatalf("initial limit = %d, want 100", got)
	}

	// High pressure → multiplicative decrease toward the floor.
	for i := 0; i < 20; i++ {
		g.adjust(g.highPSI + 10)
	}
	if got := g.Limit(); got != 2 {
		t.Fatalf("after sustained high PSI, limit = %d, want floor 2", got)
	}

	// Low pressure → additive increase, capped at the ceiling.
	for i := 0; i < 1000; i++ {
		g.adjust(0)
	}
	if got := g.Limit(); got != 100 {
		t.Fatalf("after sustained low PSI, limit = %d, want ceiling 100", got)
	}

	// In the dead-band (between low and high) the limit holds.
	mid := (g.lowPSI + g.highPSI) / 2
	before := g.Limit()
	g.adjust(mid)
	if got := g.Limit(); got != before {
		t.Fatalf("dead-band PSI moved limit %d → %d", before, got)
	}
}

func TestAcquireRespectsLimit(t *testing.T) {
	t.Setenv("CPU_GOVERNOR", "0")
	g := New(context.Background(), 1, 2, nil)
	ctx := context.Background()

	if !g.Acquire(ctx) || !g.Acquire(ctx) {
		t.Fatal("first two Acquire should succeed (ceiling 2)")
	}
	// Ceiling reached: a cancelled context must make Acquire return false rather
	// than block forever.
	cancelled, cancel := context.WithCancel(ctx)
	cancel()
	if g.Acquire(cancelled) {
		t.Fatal("Acquire over the ceiling with a cancelled ctx should return false")
	}
	g.Release()
	if !g.Acquire(ctx) {
		t.Fatal("Acquire should succeed after a Release freed a slot")
	}
}

func TestNilGovernorIsNoOp(t *testing.T) {
	var g *Governor
	if !g.Acquire(context.Background()) {
		t.Fatal("nil governor Acquire should return true")
	}
	g.Release() // must not panic
	if g.Limit() != 0 {
		t.Fatal("nil governor Limit should be 0")
	}
}
