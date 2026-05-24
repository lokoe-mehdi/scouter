package db

import (
	"bytes"
	"context"
	_ "embed"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

// schemaSQL is the canonical ClickHouse DDL (database + crawl-data tables). It is
// embedded into the binary so the crawler can create the schema itself at boot —
// see EnsureSchema. This is the single source of truth; docker-compose also mounts
// this same file into the clickhouse image's /docker-entrypoint-initdb.d.
//
//go:embed schema.sql
var schemaSQL string

// CH is a minimal ClickHouse client over the HTTP interface. We deliberately
// avoid a heavyweight driver: the crawler is network-bound on fetching, and the
// HTTP interface (INSERT … FORMAT JSONEachRow, SELECT … FORMAT TabSeparated) is
// plenty fast for batched appends and the post-processing reads. One CH is shared
// by the whole multi-crawl process, like the pg Pool.
//
// CH is enabled only when CLICKHOUSE_URL is set; otherwise NewCHFromEnv returns
// (nil, nil) and the crawler behaves exactly as before (PG-only, no regression).
type CH struct {
	base   string // e.g. http://clickhouse:8123
	db     string
	user   string
	pass   string
	client *http.Client
}

// NewCHFromEnv builds a CH from CLICKHOUSE_URL / CLICKHOUSE_DB / CLICKHOUSE_USER /
// CLICKHOUSE_PASSWORD. Returns (nil, nil) when CLICKHOUSE_URL is unset (disabled).
func NewCHFromEnv(ctx context.Context) (*CH, error) {
	base := strings.TrimRight(os.Getenv("CLICKHOUSE_URL"), "/")
	if base == "" {
		return nil, nil
	}
	ch := &CH{
		base: base,
		db:   getenvDef("CLICKHOUSE_DB", "scouter"),
		user: getenvDef("CLICKHOUSE_USER", "scouter"),
		pass: os.Getenv("CLICKHOUSE_PASSWORD"),
		client: &http.Client{
			Timeout: 5 * time.Minute, // post-processing queries can be long
		},
	}
	// Retry the ping for ~30s: the worker may boot before ClickHouse is ready
	// (local compose waits only for "started", not "healthy").
	var lastErr error
	for attempt := 0; attempt < 15; attempt++ {
		pingCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
		lastErr = ch.Exec(pingCtx, "SELECT 1")
		cancel()
		if lastErr == nil {
			// Guarantee the schema exists. The clickhouse image only runs its
			// init.sql on a pristine volume, so a pre-existing or half-initialized
			// volume leaves the tables missing — which silently breaks dual-write
			// and backfill with "Table scouter.pages does not exist". Idempotent.
			if err := ch.EnsureSchema(ctx); err != nil {
				return nil, fmt.Errorf("clickhouse ensure schema: %w", err)
			}
			return ch, nil
		}
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(2 * time.Second):
		}
	}
	return nil, fmt.Errorf("clickhouse ping: %w", lastErr)
}

// EnsureSchema creates the ClickHouse database and crawl-data tables if they do
// not exist, by running the embedded schema.sql. The DDL is idempotent
// (CREATE … IF NOT EXISTS), so it is safe to run on every boot. We split on ';'
// (the schema deliberately contains no ';' inside comments/literals — see its
// header) and skip comment-only chunks, since ClickHouse rejects an empty query.
func (c *CH) EnsureSchema(ctx context.Context) error {
	for _, stmt := range strings.Split(schemaSQL, ";") {
		stmt = strings.TrimSpace(stmt)
		if stmt == "" {
			continue
		}
		// A chunk before the first ';' (and between statements) carries the
		// preceding comment block; skip it if it has no actual SQL.
		hasSQL := false
		for _, line := range strings.Split(stmt, "\n") {
			l := strings.TrimSpace(line)
			if l != "" && !strings.HasPrefix(l, "--") {
				hasSQL = true
				break
			}
		}
		if !hasSQL {
			continue
		}
		if err := c.Exec(ctx, stmt); err != nil {
			return fmt.Errorf("apply DDL: %w", err)
		}
	}
	return nil
}

func getenvDef(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func (c *CH) post(ctx context.Context, query string, body io.Reader) (*http.Response, error) {
	u := c.base + "/?" + url.Values{"database": {c.db}}.Encode()
	if query != "" {
		u += "&query=" + url.QueryEscape(query)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, u, body)
	if err != nil {
		return nil, err
	}
	req.Header.Set("X-ClickHouse-User", c.user)
	if c.pass != "" {
		req.Header.Set("X-ClickHouse-Key", c.pass)
	}
	return c.client.Do(req)
}

// Exec runs a statement (DDL, INSERT … SELECT, ALTER … DROP PARTITION). The SQL
// is sent as the request body so there is no URL-length limit.
func (c *CH) Exec(ctx context.Context, sql string) error {
	resp, err := c.post(ctx, "", strings.NewReader(sql))
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
		return fmt.Errorf("clickhouse exec %d: %s", resp.StatusCode, strings.TrimSpace(string(b)))
	}
	_, _ = io.Copy(io.Discard, resp.Body)
	return nil
}

// InsertJSONEachRow appends rows to a table. Each element of rows is marshalled
// to one JSON object whose keys must match the target columns (Map→object,
// Array→array, Nullable→null are handled natively by ClickHouse).
func (c *CH) InsertJSONEachRow(ctx context.Context, table string, rows []any) error {
	if len(rows) == 0 {
		return nil
	}
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	for _, r := range rows {
		if err := enc.Encode(r); err != nil {
			return err
		}
	}
	query := "INSERT INTO " + table + " FORMAT JSONEachRow"
	resp, err := c.post(ctx, query, &buf)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
		return fmt.Errorf("clickhouse insert %s %d: %s", table, resp.StatusCode, strings.TrimSpace(string(b)))
	}
	_, _ = io.Copy(io.Discard, resp.Body)
	return nil
}

// QueryTSV runs a SELECT and returns rows as string slices (TabSeparated). NULLs
// come back as `\N`. Suitable for the scalar reads the post-processing needs.
func (c *CH) QueryTSV(ctx context.Context, sql string) ([][]string, error) {
	resp, err := c.post(ctx, "", strings.NewReader(sql+" FORMAT TabSeparated"))
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("clickhouse query %d: %s", resp.StatusCode, strings.TrimSpace(string(b)))
	}
	out := [][]string{}
	text := strings.TrimRight(string(b), "\n")
	if text == "" {
		return out, nil
	}
	for _, line := range strings.Split(text, "\n") {
		out = append(out, strings.Split(line, "\t"))
	}
	return out, nil
}

// QueryScalar runs a SELECT returning a single value (first row, first column).
func (c *CH) QueryScalar(ctx context.Context, sql string) (string, error) {
	rows, err := c.QueryTSV(ctx, sql)
	if err != nil {
		return "", err
	}
	if len(rows) == 0 || len(rows[0]) == 0 {
		return "", nil
	}
	return rows[0][0], nil
}

// DropPartition removes a crawl's partition from a table (idempotent — used by
// the post-processing before re-inserting derived tables, and by delete-crawl).
func (c *CH) DropPartition(ctx context.Context, table string, crawlID int) error {
	return c.Exec(ctx, fmt.Sprintf("ALTER TABLE %s DROP PARTITION %d", table, crawlID))
}

// DB returns the configured database name (for qualifying table names).
func (c *CH) DB() string { return c.db }
