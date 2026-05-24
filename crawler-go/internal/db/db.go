// Package db is the PostgreSQL layer for the Go crawler. It mirrors the SQL and
// semantics of app/Database/CrawlDatabase.php (partitions, ON CONFLICT promotion,
// stats) so Go crawls write rows indistinguishable from PHP crawls.
package db

import (
	"context"
	"errors"
	"fmt"
	"math/rand"
	"time"

	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"
)

// Pool wraps a pgx pool. One pool is shared by the whole multi-crawl process.
type Pool struct{ *pgxpool.Pool }

// NewPool builds a connection pool from a DATABASE_URL / DSN.
func NewPool(ctx context.Context, dsn string, maxConns int32) (*Pool, error) {
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return nil, err
	}
	if maxConns > 0 {
		cfg.MaxConns = maxConns
	}
	p, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		return nil, err
	}
	if err := p.Ping(ctx); err != nil {
		p.Close()
		return nil, err
	}
	return &Pool{p}, nil
}

// retryablePgCodes mirrors DeadlockRetry.php: deadlock, serialization failure,
// lock-not-available.
var retryablePgCodes = map[string]bool{"40P01": true, "40001": true, "55P03": true}

// withRetry runs fn, retrying up to 3 times with exponential backoff + jitter on
// the retryable Postgres errors (50→500ms), like the PHP DeadlockRetry trait.
func withRetry(ctx context.Context, fn func() error) error {
	const maxRetries = 3
	var lastErr error
	for attempt := 1; attempt <= maxRetries; attempt++ {
		lastErr = fn()
		if lastErr == nil {
			return nil
		}
		var pgErr *pgconn.PgError
		if !errors.As(lastErr, &pgErr) || !retryablePgCodes[pgErr.Code] {
			return lastErr
		}
		base := time.Duration(50<<uint(attempt-1)) * time.Millisecond // 50,100,200ms
		jitter := time.Duration(rand.Int63n(int64(base) / 2))
		delay := base + jitter
		if delay > 500*time.Millisecond {
			delay = 500 * time.Millisecond
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(delay):
		}
	}
	return fmt.Errorf("retry exhausted: %w", lastErr)
}
