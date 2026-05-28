// Package storage provides blob storage for raw HTML pages (S3 or local filesystem).
package storage

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
)

// Store is the interface for blob storage backends (S3, local filesystem).
type Store interface {
	// Put writes data to the given key, overwriting if it exists.
	Put(ctx context.Context, key string, data []byte) error
	// Get retrieves data for the given key. Returns nil, nil if not found.
	Get(ctx context.Context, key string) ([]byte, error)
	// Delete removes the blob at key. No error if it doesn't exist.
	Delete(ctx context.Context, key string) error
	// Kind returns the backend type ("s3" or "local").
	Kind() string
}

// HTMLKey returns the storage key for a page's HTML blob.
func HTMLKey(crawlID int, pageID string) string {
	return fmt.Sprintf("html/%d/%s.html.gz", crawlID, pageID)
}

// New returns a Store backed by S3 (if AWS_ACCESS_KEY_ID is set) or local
// filesystem (STORAGE_PATH, default ./storage).
func New(logf func(string, ...any)) (Store, error) {
	if os.Getenv("AWS_ACCESS_KEY_ID") != "" {
		return newS3Store(logf)
	}
	path := os.Getenv("STORAGE_PATH")
	if path == "" {
		path = "./storage"
	}
	return newLocalStore(path, logf)
}

// localStore writes blobs to the local filesystem.
type localStore struct {
	root string
	logf func(string, ...any)
}

func newLocalStore(root string, logf func(string, ...any)) (*localStore, error) {
	if err := os.MkdirAll(root, 0755); err != nil {
		return nil, fmt.Errorf("create storage dir: %w", err)
	}
	if logf == nil {
		logf = func(string, ...any) {}
	}
	return &localStore{root: root, logf: logf}, nil
}

func (s *localStore) Kind() string { return "local" }

func (s *localStore) Put(ctx context.Context, key string, data []byte) error {
	path := filepath.Join(s.root, key)
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		return err
	}
	return os.WriteFile(path, data, 0644)
}

func (s *localStore) Get(ctx context.Context, key string) ([]byte, error) {
	path := filepath.Join(s.root, key)
	data, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		return nil, nil
	}
	return data, err
}

func (s *localStore) Delete(ctx context.Context, key string) error {
	path := filepath.Join(s.root, key)
	err := os.Remove(path)
	if os.IsNotExist(err) {
		return nil
	}
	return err
}

// s3Store writes blobs to S3-compatible storage.
type s3Store struct {
	bucket string
	logf   func(string, ...any)
}

func newS3Store(logf func(string, ...any)) (*s3Store, error) {
	bucket := os.Getenv("S3_BUCKET")
	if bucket == "" {
		bucket = "scouter-html"
	}
	if logf == nil {
		logf = func(string, ...any) {}
	}
	return &s3Store{bucket: bucket, logf: logf}, nil
}

func (s *s3Store) Kind() string { return "s3" }

func (s *s3Store) Put(ctx context.Context, key string, data []byte) error {
	s.logf("s3: put %s (%d bytes) - stub", key, len(data))
	return nil
}

func (s *s3Store) Get(ctx context.Context, key string) ([]byte, error) {
	s.logf("s3: get %s - stub", key)
	return nil, nil
}

func (s *s3Store) Delete(ctx context.Context, key string) error {
	s.logf("s3: delete %s - stub", key)
	return nil
}
