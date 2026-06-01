package storage

import (
	"bytes"
	"context"
	"io"
	"os"
	"strings"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// s3Store is the S3-compatible backend (AWS S3, R2, MinIO, B2…), via minio-go.
// An optional S3_PREFIX namespaces all keys so one bucket can be shared.
type s3Store struct {
	cli    *minio.Client
	bucket string
	prefix string
	logf   func(string, ...any)
}

func newS3(bucket, accessKey, secretKey string, logf func(string, ...any)) (*s3Store, error) {
	if logf == nil {
		logf = func(string, ...any) {}
	}
	endpoint := strings.TrimSpace(os.Getenv("S3_ENDPOINT"))
	region := strings.TrimSpace(os.Getenv("S3_REGION"))

	// host is the bare host[:port]; secure toggles TLS. With no S3_ENDPOINT we
	// target AWS and derive the regional host; an explicit endpoint (R2/MinIO/B2)
	// may carry a scheme — http:// flips TLS off (typical for a local MinIO).
	secure := true
	host := endpoint
	if host == "" {
		r := region
		if r == "" {
			r = "us-east-1"
		}
		host = "s3." + r + ".amazonaws.com"
	} else {
		switch {
		case strings.HasPrefix(host, "http://"):
			secure = false
			host = strings.TrimPrefix(host, "http://")
		case strings.HasPrefix(host, "https://"):
			host = strings.TrimPrefix(host, "https://")
		}
		host = strings.TrimRight(host, "/")
	}

	opts := &minio.Options{
		Creds:  credentials.NewStaticV4(accessKey, secretKey, ""),
		Secure: secure,
		Region: region,
	}
	// Path-style ("host/bucket/key") is needed by MinIO and some S3-compatibles;
	// virtual-hosted style ("bucket.host/key") is the AWS default.
	if envBool("S3_USE_PATH_STYLE") {
		opts.BucketLookup = minio.BucketLookupPath
	}

	cli, err := minio.New(host, opts)
	if err != nil {
		return nil, err
	}
	prefix := strings.Trim(strings.TrimSpace(os.Getenv("S3_PREFIX")), "/")
	logf("storage: s3 backend host=%s bucket=%s prefix=%q secure=%v pathStyle=%v",
		host, bucket, prefix, secure, envBool("S3_USE_PATH_STYLE"))
	return &s3Store{cli: cli, bucket: bucket, prefix: prefix, logf: logf}, nil
}

// objectKey applies the optional prefix to a logical key.
func (s *s3Store) objectKey(key string) string {
	if s.prefix == "" {
		return key
	}
	return s.prefix + "/" + key
}

func (s *s3Store) Put(ctx context.Context, key string, data []byte) error {
	// Retry transient failures with backoff (network blips, throttling). The
	// crawler calls this from the per-page fetch goroutine, so this also
	// back-pressures the crawl under storage trouble rather than losing HTML.
	const maxAttempts = 5
	backoff := 250 * time.Millisecond
	var lastErr error
	for attempt := 1; attempt <= maxAttempts; attempt++ {
		_, err := s.cli.PutObject(ctx, s.bucket, s.objectKey(key),
			bytes.NewReader(data), int64(len(data)),
			minio.PutObjectOptions{ContentType: "application/gzip"})
		if err == nil {
			if attempt > 1 {
				s.logf("storage: s3 put recovered after %d attempts (%s)", attempt, key)
			}
			return nil
		}
		lastErr = err
		if attempt < maxAttempts {
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(backoff):
			}
			if backoff < 4*time.Second {
				backoff *= 2
			}
		}
	}
	return lastErr
}

func (s *s3Store) Get(ctx context.Context, key string) ([]byte, bool, error) {
	obj, err := s.cli.GetObject(ctx, s.bucket, s.objectKey(key), minio.GetObjectOptions{})
	if err != nil {
		return nil, false, err
	}
	defer obj.Close()
	b, err := io.ReadAll(obj)
	if err != nil {
		// minio surfaces a missing object as an error on the first read.
		if minio.ToErrorResponse(err).Code == "NoSuchKey" {
			return nil, false, nil
		}
		return nil, false, err
	}
	return b, true, nil
}

func (s *s3Store) DeletePrefix(ctx context.Context, prefix string) error {
	objects := s.cli.ListObjects(ctx, s.bucket, minio.ListObjectsOptions{
		Prefix:    s.objectKey(prefix),
		Recursive: true,
	})
	for e := range s.cli.RemoveObjects(ctx, s.bucket, objects, minio.RemoveObjectsOptions{}) {
		if e.Err != nil {
			return e.Err
		}
	}
	return nil
}

func (s *s3Store) Kind() string { return "s3" }
