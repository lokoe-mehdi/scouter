// Package storage persists per-page raw HTML OUTSIDE the database. Storing the
// HTML blobs in ClickHouse blew up the on-disk footprint; instead each page's
// HTML is gzip-compressed and written to an object store.
//
// Two interchangeable backends, selected from the environment at startup:
//   - S3-compatible object store, when S3 credentials are present (AWS S3,
//     Cloudflare R2, MinIO, Backblaze B2…). The data persists long-term so a URL
//     can be re-fetched months after the crawl.
//   - a local directory (the default fallback) when no S3 credentials are set —
//     handy for dev and single-host deploys. Must be a persistent, shared volume
//     so both the crawler (writer) and the web app (reader) see the same files.
//
// Keys are backend-agnostic slash-separated paths, e.g. "html/123/a1b2c3d4.gz".
package storage

import (
	"context"
	"fmt"
	"os"
	"strings"
)

// Store is the minimal blob API the crawler and purge paths need.
type Store interface {
	// Put writes data at key, overwriting any existing object (idempotent).
	Put(ctx context.Context, key string, data []byte) error
	// Get returns the object at key. found=false (nil error) when it is absent.
	Get(ctx context.Context, key string) (data []byte, found bool, err error)
	// DeletePrefix removes every object whose key starts with prefix.
	DeletePrefix(ctx context.Context, prefix string) error
	// Kind reports the backend ("s3" or "local"), for logging.
	Kind() string
}

// HTMLKey is the object key for a page's HTML: html/<crawl_id>/<page_id>.gz.
// page_id is the crc32-bzip2 hash of the URL (analysis.PageID), the same id used
// everywhere else — so any reader can rebuild the key from (crawl_id, page_id).
// Sharding by crawl_id lets a whole crawl be purged by deleting one prefix.
func HTMLKey(crawlID int, pageID string) string {
	return fmt.Sprintf("html/%d/%s.gz", crawlID, pageID)
}

// HTMLPrefix is the key prefix that holds one crawl's HTML (used to purge it).
func HTMLPrefix(crawlID int) string {
	return fmt.Sprintf("html/%d/", crawlID)
}

// New builds the Store from the environment: an S3 backend when S3_BUCKET plus
// credentials are set, otherwise a local directory (STORAGE_PATH, default
// ./storage). Returns an error only on misconfiguration (e.g. an unwritable
// local directory); transient S3 failures surface later, at Put/Get time.
//
// Le choix est explicite : les TROIS variables S3 (bucket + access key + secret)
// doivent être définies pour basculer en S3 ; sinon on garde le backend local.
// Si seulement UNE OU DEUX sont définies, on prévient (config probablement
// incomplète) mais on reste en local pour que le crawl continue à enregistrer
// le HTML — pas de perte de données silencieuse.
func New(logf func(string, ...any)) (Store, error) {
	if logf == nil {
		logf = func(string, ...any) {}
	}
	bucket := strings.TrimSpace(os.Getenv("S3_BUCKET"))
	accessKey := strings.TrimSpace(os.Getenv("S3_ACCESS_KEY_ID"))
	secretKey := strings.TrimSpace(os.Getenv("S3_SECRET_ACCESS_KEY"))
	allSet := bucket != "" && accessKey != "" && secretKey != ""
	anySet := bucket != "" || accessKey != "" || secretKey != ""
	if allSet {
		return newS3(bucket, accessKey, secretKey, logf)
	}
	if anySet {
		logf("storage: S3 config incomplète (bucket=%t access_key=%t secret=%t) → backend LOCAL ; définis les trois pour activer S3, ou supprime-les pour rester en local sans warning.",
			bucket != "", accessKey != "", secretKey != "")
	}
	path := strings.TrimSpace(os.Getenv("STORAGE_PATH"))
	if path == "" {
		path = "./storage"
	}
	return newLocal(path, logf)
}

// envBool reports whether an env var is a truthy flag (1/true/yes/on).
func envBool(key string) bool {
	switch strings.ToLower(strings.TrimSpace(os.Getenv(key))) {
	case "1", "true", "yes", "on":
		return true
	}
	return false
}
