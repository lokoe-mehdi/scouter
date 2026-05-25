package db

import "testing"

func TestBuildCHSettings(t *testing.T) {
	// Default: background work runs at a low OS thread priority (nice 5), and
	// max_threads is left to ClickHouse (adaptive to cores — no hard cap).
	t.Setenv("CH_OS_THREAD_PRIORITY", "")
	t.Setenv("CH_PRIORITY", "")
	t.Setenv("CH_MAX_THREADS", "")
	v := buildCHSettings()
	if got := v.Get("os_thread_priority"); got != "5" {
		t.Fatalf("default os_thread_priority = %q, want 5", got)
	}
	if v.Has("max_threads") {
		t.Fatalf("max_threads should be unset by default (adaptive), got %q", v.Get("max_threads"))
	}
	if v.Has("priority") {
		t.Fatalf("priority should be unset by default, got %q", v.Get("priority"))
	}

	// Explicit overrides flow through; nice=0 disables the hint entirely.
	t.Setenv("CH_OS_THREAD_PRIORITY", "0")
	t.Setenv("CH_PRIORITY", "10")
	t.Setenv("CH_MAX_THREADS", "4")
	v = buildCHSettings()
	if v.Has("os_thread_priority") {
		t.Fatalf("os_thread_priority should be disabled when set to 0, got %q", v.Get("os_thread_priority"))
	}
	if got := v.Get("priority"); got != "10" {
		t.Fatalf("priority = %q, want 10", got)
	}
	if got := v.Get("max_threads"); got != "4" {
		t.Fatalf("max_threads = %q, want 4", got)
	}
}
