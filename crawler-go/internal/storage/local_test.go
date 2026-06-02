package storage

import (
	"context"
	"testing"
)

func TestHTMLKey(t *testing.T) {
	if got := HTMLKey(42, "a1b2c3d4"); got != "html/42/a1b2c3d4.gz" {
		t.Fatalf("HTMLKey = %q", got)
	}
	if got := HTMLPrefix(42); got != "html/42/" {
		t.Fatalf("HTMLPrefix = %q", got)
	}
}

func TestLocalRoundTrip(t *testing.T) {
	ctx := context.Background()
	s, err := newLocal(t.TempDir(), nil)
	if err != nil {
		t.Fatalf("newLocal: %v", err)
	}

	// Missing key → not found, no error.
	if _, found, err := s.Get(ctx, HTMLKey(1, "deadbeef")); err != nil || found {
		t.Fatalf("Get(missing) = found=%v err=%v", found, err)
	}

	want := []byte("<html>hello</html>")
	key := HTMLKey(7, "cafef00d")
	if err := s.Put(ctx, key, want); err != nil {
		t.Fatalf("Put: %v", err)
	}
	got, found, err := s.Get(ctx, key)
	if err != nil || !found {
		t.Fatalf("Get = found=%v err=%v", found, err)
	}
	if string(got) != string(want) {
		t.Fatalf("Get = %q, want %q", got, want)
	}

	// Overwriting the same key must succeed (idempotent).
	if err := s.Put(ctx, key, []byte("v2")); err != nil {
		t.Fatalf("Put overwrite: %v", err)
	}
	if got, _, _ := s.Get(ctx, key); string(got) != "v2" {
		t.Fatalf("after overwrite Get = %q", got)
	}
}

func TestLocalDeletePrefix(t *testing.T) {
	ctx := context.Background()
	s, err := newLocal(t.TempDir(), nil)
	if err != nil {
		t.Fatalf("newLocal: %v", err)
	}

	// Two crawls' worth of blobs.
	for _, k := range []string{HTMLKey(1, "aaaa"), HTMLKey(1, "bbbb"), HTMLKey(2, "cccc")} {
		if err := s.Put(ctx, k, []byte("x")); err != nil {
			t.Fatalf("Put %s: %v", k, err)
		}
	}

	if err := s.DeletePrefix(ctx, HTMLPrefix(1)); err != nil {
		t.Fatalf("DeletePrefix: %v", err)
	}

	// Crawl 1 gone, crawl 2 untouched.
	if _, found, _ := s.Get(ctx, HTMLKey(1, "aaaa")); found {
		t.Fatal("crawl 1 blob survived DeletePrefix")
	}
	if _, found, _ := s.Get(ctx, HTMLKey(2, "cccc")); !found {
		t.Fatal("crawl 2 blob wrongly deleted")
	}

	// Deleting a non-existent prefix is a no-op, not an error.
	if err := s.DeletePrefix(ctx, HTMLPrefix(999)); err != nil {
		t.Fatalf("DeletePrefix(missing): %v", err)
	}
}
