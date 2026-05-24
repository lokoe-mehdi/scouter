package postprocess

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"sort"
	"testing"

	"scouter-crawler/internal/db"
)

// TestCategorizationParity is the guardrail for the deliberately-kept duplication
// of the categorizer (Go in the crawl, PHP for UI/API/AI/batch). It seeds two
// identical crawls, categorizes one with the Go code and the other with the PHP
// CategorizationService, and asserts every URL gets the same category. If the two
// implementations ever drift, this fails.
//
// Requires a throwaway Postgres (schema loaded) + the PHP CLI. Skipped unless
// PARITY_DATABASE_URL is set. Run via tests/parity/run_categorization_parity.sh.
func TestCategorizationParity(t *testing.T) {
	dsn := os.Getenv("PARITY_DATABASE_URL")
	if dsn == "" {
		t.Skip("set PARITY_DATABASE_URL (+ run a seeded Postgres) to run the parity test")
	}
	ctx := context.Background()
	pool, err := db.NewPool(ctx, dsn, 4)
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	defer pool.Close()

	const yaml = `product:
  dom: example.com
  include:
    - '/product/'
  exclude:
    - '/product/old/'
  color: '#ff0000'
category:
  dom: example.com
  include:
    - '/category/'
    - '/cat/'
  color: '#00ff00'
blog:
  dom: example.com
  include:
    - '/blog/'
  color: '#0000ff'`

	// URLs chosen to exercise: include, exclude, multiple includes, first-match
	// order, case-insensitivity, unicode, query string, trailing-slash boundary.
	urls := []string{
		"https://example.com/product/123",
		"https://example.com/product/old/9",
		"https://example.com/category/shoes",
		"https://example.com/cat/x",
		"https://example.com/blog/post-1",
		"https://example.com/blog/old/post",
		"https://example.com/",
		"https://example.com/about",
		"https://example.com/product/abc?ref=1",
		"https://example.com/h%C3%A9llo/cat%C3%A9",
		"https://example.com/CATEGORY/UP",
		"https://example.com/blog",
	}

	seed(ctx, t, pool, yaml, urls)

	// Go side → crawl 1
	goRunner := New(pool, 1, nil)
	if err := goRunner.Categorize(ctx); err != nil {
		t.Fatalf("go categorize: %v", err)
	}
	goRes := dumpCategories(ctx, t, pool, 1)

	// PHP side → crawl 2
	runPHPCategorize(t, dsn, 2)
	phpRes := dumpCategories(ctx, t, pool, 2)

	// Compare
	if len(goRes) != len(phpRes) {
		t.Fatalf("row count mismatch: go=%d php=%d", len(goRes), len(phpRes))
	}
	var diffs []string
	keys := make([]string, 0, len(goRes))
	for u := range goRes {
		keys = append(keys, u)
	}
	sort.Strings(keys)
	for _, u := range keys {
		if goRes[u] != phpRes[u] {
			diffs = append(diffs, fmt.Sprintf("  %s : go=%q php=%q", u, goRes[u], phpRes[u]))
		}
	}
	if len(diffs) > 0 {
		t.Fatalf("categorization drift between Go and PHP:\n%s", joinLines(diffs))
	}
	for _, u := range keys {
		t.Logf("OK %-45s → %s", u, goRes[u])
	}
}

func seed(ctx context.Context, t *testing.T, pool *db.Pool, yaml string, urls []string) {
	t.Helper()
	exec := func(sql string, args ...any) {
		if _, err := pool.Exec(ctx, sql, args...); err != nil {
			t.Fatalf("seed exec %q: %v", sql, err)
		}
	}
	// Clean slate for the two test crawls.
	exec("SELECT drop_crawl_partitions(1)")
	exec("SELECT drop_crawl_partitions(2)")
	exec("DELETE FROM crawls WHERE id IN (1,2)")
	exec("DELETE FROM crawl_categories WHERE project_id = 1")
	exec("DELETE FROM projects WHERE id = 1")
	exec("DELETE FROM users WHERE id = 1")

	exec("INSERT INTO users (id, email, password_hash) VALUES (1,'parity@test','x')")
	exec("INSERT INTO projects (id, user_id, name, categorization_config) VALUES (1,1,'parity',$1)", yaml)
	for _, cid := range []int{1, 2} {
		exec("INSERT INTO crawls (id, project_id, domain, path, status) VALUES ($1,1,'example.com',$2,'finished')",
			cid, fmt.Sprintf("parity-%d", cid))
		exec("SELECT create_crawl_partitions($1)", cid)
		for i, u := range urls {
			exec(`INSERT INTO pages (crawl_id, id, domain, url, depth, code, crawled, external, in_crawl)
			      VALUES ($1,$2,'example.com',$3,1,200,true,false,true)`,
				cid, fmt.Sprintf("p%07d", i), u)
		}
	}
}

func dumpCategories(ctx context.Context, t *testing.T, pool *db.Pool, crawlID int) map[string]string {
	t.Helper()
	rows, err := pool.Query(ctx, `
		SELECT p.url, COALESCE(c.cat,'(none)')
		FROM pages p LEFT JOIN crawl_categories c ON c.id = p.cat_id
		WHERE p.crawl_id = $1`, crawlID)
	if err != nil {
		t.Fatalf("dump: %v", err)
	}
	defer rows.Close()
	out := map[string]string{}
	for rows.Next() {
		var u, cat string
		if err := rows.Scan(&u, &cat); err != nil {
			t.Fatalf("scan: %v", err)
		}
		out[u] = cat
	}
	return out
}

func runPHPCategorize(t *testing.T, dsn string, crawlID int) {
	t.Helper()
	_, file, _, _ := runtime.Caller(0)
	repoRoot := filepath.Join(filepath.Dir(file), "..", "..", "..")
	script := filepath.Join(repoRoot, "tests", "parity", "php_categorize.php")

	cmd := exec.Command("php", script, fmt.Sprintf("%d", crawlID))
	cmd.Env = append(os.Environ(),
		"PARITY_DSN="+os.Getenv("PARITY_PDO_DSN"),
		"PARITY_USER="+os.Getenv("PARITY_PDO_USER"),
		"PARITY_PASS="+os.Getenv("PARITY_PDO_PASS"),
	)
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("php categorize failed: %v\n%s", err, out)
	}
	t.Logf("php: %s", out)
}

func joinLines(s []string) string {
	out := ""
	for _, l := range s {
		out += l + "\n"
	}
	return out
}
