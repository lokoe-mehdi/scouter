package postprocess

import (
	"context"
	"fmt"
	"math/bits"
	"strconv"
	"strings"

	"scouter-crawler/internal/db"
)

// CHRunner runs the post-processing IN ClickHouse, writing the derived tables
// (page_metrics, duplicate_clusters, redirect_chains) for one crawl. It is the
// CH half of the dual pipeline: the PG Runner still updates the crawls.* stats
// (which live in PostgreSQL), while this produces the analytical tables that the
// reports read once cut over. Everything is append-after-DROP-PARTITION, so it is
// idempotent on resume (recompute replaces the crawl's partition).
//
// This is where PageRank gets fast: 30 graph iterations run inside ClickHouse
// over Memory tables instead of PG temp-table UPDATEs.
type CHRunner struct {
	ch              *db.CH
	pool            *db.Pool // PostgreSQL — source of truth for the sitemap dimension (may be nil)
	crawlID         int
	respectNofollow bool
	logf            func(string, ...any)

	// sitemapTable is the Memory table of in_sitemap page ids built by loadSitemap;
	// buildMetrics joins it to flag page_metrics.in_sitemap. Empty = no sitemap.
	sitemapTable string
}

// RespectNofollowFromConfig reads advanced.respect_nofollow (default true, like
// the PG pagerank) from a crawls.config JSONB blob.
func RespectNofollowFromConfig(raw []byte) bool {
	return advancedBool(raw, "respect_nofollow", true)
}

// NewCHRunner returns a CH post-processor, or nil if ch is nil (CH disabled).
// pool is the PostgreSQL pool: the PG post-processing's sitemapAnalysis is the
// source of truth for the sitemap dimension (in_sitemap flags + sitemap-only rows),
// which loadSitemap copies into ClickHouse. May be nil (sitemap step is then a no-op).
func NewCHRunner(ch *db.CH, pool *db.Pool, crawlID int, respectNofollow bool, logf func(string, ...any)) *CHRunner {
	if ch == nil {
		return nil
	}
	if logf == nil {
		logf = func(string, ...any) {}
	}
	return &CHRunner{ch: ch, pool: pool, crawlID: crawlID, respectNofollow: respectNofollow, logf: logf}
}

func (r *CHRunner) t(name string) string { return r.ch.DB() + "." + name }
func (r *CHRunner) cid() string          { return strconv.Itoa(r.crawlID) }

// pd is the deduplicated `pages` source for this crawl. CH is append-only, so a
// page re-written during the crawl (sitemap re-fetch, retries) leaves >1 row per
// id; LIMIT 1 BY id keeps one (scoped to the crawl_id partition). Used by every
// pages read so the derived tables are built from clean, one-row-per-page data.
func (r *CHRunner) pd() string {
	return "(SELECT * FROM " + r.t("pages") + " WHERE crawl_id = " + r.cid() + " LIMIT 1 BY id)"
}

// pdCrawl is pd() restricted to pages actually reached by the crawl (depth >= 0).
// Sitemap-only placeholder rows are stored with depth = -1, so this excludes them
// from PageRank (which mirrors the PG pagerank's `WHERE in_crawl = TRUE`).
func (r *CHRunner) pdCrawl() string {
	return "(SELECT * FROM " + r.t("pages") + " WHERE crawl_id = " + r.cid() + " AND depth >= 0 LIMIT 1 BY id)"
}

// Run executes the CH post-processing steps, isolating failures per step.
// Returns the names of the steps that FAILED (nil/empty = tout OK) pour que
// l'appelant ne déclare pas un crawl à l'analytique dégradée comme un succès
// propre, et ne droppe pas PostgreSQL alors que les tables dérivées sont
// incomplètes.
func (r *CHRunner) Run(ctx context.Context) []string {
	if r == nil {
		return nil
	}
	steps := []struct {
		name string
		fn   func(context.Context) error
	}{
		// First: pull the sitemap dimension from PG into CH so buildMetrics can flag
		// in_sitemap and the sitemap-only rows are countable.
		{"ch-sitemap", r.loadSitemap},
		{"ch-pagerank+metrics", r.buildMetrics},
		{"ch-duplicate", r.duplicateAnalysis},
		{"ch-redirect", r.redirectChainAnalysis},
		// Last: collapse the crawl's ReplacingMergeTree duplicates now (crawl is
		// done → no more inserts), so report reads hit fully-merged parts instead
		// of paying the LIMIT 1 BY dedup over many small parts on every query.
		{"ch-optimize", r.optimizeFinal},
	}
	var failed []string
	for _, s := range steps {
		if err := s.fn(ctx); err != nil {
			r.logf("clickhouse post-processing error in %s: %v", s.name, err)
			failed = append(failed, s.name)
		} else {
			r.logf("clickhouse post-processing %s done", s.name)
		}
	}
	return failed
}

// buildMetrics computes PageRank into a Memory table, then assembles page_metrics
// (inlinks + pri + title/h1/metadesc status) via one INSERT … SELECT.
func (r *CHRunner) buildMetrics(ctx context.Context) error {
	if err := r.computePageRank(ctx); err != nil {
		return err
	}
	defer func() {
		_ = r.ch.Exec(ctx, "DROP TABLE IF EXISTS "+r.t("pr_cur_"+r.cid()))
		_ = r.ch.Exec(ctx, "DROP TABLE IF EXISTS "+r.t("pr_next_"+r.cid()))
		if r.sitemapTable != "" {
			_ = r.ch.Exec(ctx, "DROP TABLE IF EXISTS "+r.sitemapTable)
		}
	}()

	cid := r.cid()
	if err := r.ch.DropPartition(ctx, r.t("page_metrics"), r.crawlID); err != nil {
		return err
	}

	// status is defined over compliant pages only (mirrors the PG semantic step);
	// non-compliant pages fall through the LEFT JOIN to '' (CH default fill).
	statusSub := `(SELECT id,
			multiIf(title = '', 'empty', count() OVER (PARTITION BY title) > 1, 'duplicate', 'unique') AS title_status,
			multiIf(h1 = '', 'empty', count() OVER (PARTITION BY h1) > 1, 'duplicate', 'unique') AS h1_status,
			multiIf(metadesc = '', 'empty', count() OVER (PARTITION BY metadesc) > 1, 'duplicate', 'unique') AS metadesc_status
		FROM ` + r.pd() + ` WHERE compliant = 1)`

	inlinksSub := `(SELECT target AS tid, count() AS inlinks FROM ` + r.t("links") +
		` WHERE crawl_id = ` + cid + ` GROUP BY target)`

	// in_sitemap: 1 when the page id is in the sitemap set loaded from PG, else 0.
	// (Hardcoded 0 until #38's CH migration was fixed — which silently zeroed every
	// crawl's sitemap report.) When there is no sitemap, sitemapTable is empty → 0.
	inSitemap := "0"
	if r.sitemapTable != "" {
		inSitemap = "if(p.id IN (SELECT id FROM " + r.sitemapTable + "), 1, 0)"
	}

	sql := `INSERT INTO ` + r.t("page_metrics") +
		` (crawl_id, id, inlinks, pri, title_status, h1_status, metadesc_status, in_sitemap)
		SELECT ` + cid + `, p.id, il.inlinks, pr.pr, st.title_status, st.h1_status, st.metadesc_status, ` + inSitemap + `
		FROM ` + r.pd() + ` p
		LEFT JOIN ` + inlinksSub + ` il ON il.tid = p.id
		LEFT JOIN ` + r.t("pr_cur_"+cid) + ` pr ON pr.id = p.id
		LEFT JOIN ` + statusSub + ` st ON st.id = p.id
		WHERE p.crawl_id = ` + cid
	return r.ch.Exec(ctx, sql)
}

// loadSitemap brings the sitemap dimension into ClickHouse. The PG post-processing
// (sitemapAnalysis) is the source of truth: it parses the sitemap, flags the
// crawled pages that appear in it (pages.in_sitemap), and inserts sitemap-only
// placeholder rows (in_crawl = FALSE, depth = -1) for URLs that are in the sitemap
// but were not reached by the crawl. None of that ever reached ClickHouse — the CH
// page_metrics.in_sitemap was hardcoded to 0 and the placeholders were never copied
// — so the sitemap report read all-zeros once a crawl was on ClickHouse. This step:
//
//  1. loads the in_sitemap id set into a Memory table that buildMetrics joins, and
//  2. copies the sitemap-only rows into CH `pages` (depth -1 preserved), so the
//     read shim derives in_crawl = FALSE and the "sitemap only" / "total sitemap"
//     counts are complete.
//
// No-op when there is no PG pool or the crawl has no sitemap data (leaves in_sitemap
// at 0). Runs before buildMetrics so the placeholders exist when page_metrics is
// built — PageRank uses pdCrawl() (depth >= 0) so the placeholders never skew it.
func (r *CHRunner) loadSitemap(ctx context.Context) error {
	if r.pool == nil {
		return nil
	}
	var n int
	if err := r.pool.QueryRow(ctx,
		"SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND in_sitemap=TRUE", r.crawlID).Scan(&n); err != nil {
		return err
	}
	if n == 0 {
		return nil // no sitemap configured, or nothing matched — nothing to mark
	}

	// 1) in_sitemap id set → Memory table joined by buildMetrics.
	tbl := r.t("sitemap_ids_" + r.cid())
	if err := r.ch.Exec(ctx, "DROP TABLE IF EXISTS "+tbl); err != nil {
		return err
	}
	if err := r.ch.Exec(ctx, "CREATE TABLE "+tbl+" (id FixedString(8)) ENGINE = Memory"); err != nil {
		return err
	}
	idRows, err := r.pool.Query(ctx, "SELECT id FROM pages WHERE crawl_id=$1 AND in_sitemap=TRUE", r.crawlID)
	if err != nil {
		return err
	}
	batch := make([]any, 0, 2000)
	flushIDs := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := r.ch.InsertJSONEachRow(ctx, tbl, batch)
		batch = batch[:0]
		return err
	}
	for idRows.Next() {
		var id string
		if err := idRows.Scan(&id); err != nil {
			idRows.Close()
			return err
		}
		batch = append(batch, map[string]any{"id": strings.TrimSpace(id)})
		if len(batch) >= 2000 {
			if err := flushIDs(); err != nil {
				idRows.Close()
				return err
			}
		}
	}
	idRows.Close()
	if err := idRows.Err(); err != nil {
		return err
	}
	if err := flushIDs(); err != nil {
		return err
	}
	r.sitemapTable = tbl

	// 2) sitemap-only rows (in the sitemap, not in the crawl graph) → CH pages.
	return r.copySitemapOnlyPages(ctx)
}

// copySitemapOnlyPages copies the PG sitemap-only rows (in_crawl = FALSE) into CH
// `pages` so they are countable by the report. depth is forced to -1 (the
// placeholder sentinel) so the read shim classifies them as in_crawl = FALSE.
//
// Ids already present in CH are skipped: an in-scope sitemap URL fetched during the
// PG sitemap pass was written to CH by the crawl store (with its real depth), and a
// second row for the same id would corrupt duplicate detection (its simhash counted
// twice) and the in_crawl classification. Those keep their fetched row (counted as
// crawl ∩ sitemap); only the never-fetched URLs become depth -1 placeholders.
func (r *CHRunner) copySitemapOnlyPages(ctx context.Context) error {
	cid := r.cid()

	// Candidate ids, then drop those already in CH so we never write a 2nd row/id.
	candRows, err := r.pool.Query(ctx, "SELECT id FROM pages WHERE crawl_id=$1 AND in_crawl=FALSE", r.crawlID)
	if err != nil {
		return err
	}
	var candidates []string
	for candRows.Next() {
		var id string
		if err := candRows.Scan(&id); err != nil {
			candRows.Close()
			return err
		}
		candidates = append(candidates, strings.TrimSpace(id))
	}
	candRows.Close()
	if err := candRows.Err(); err != nil {
		return err
	}
	if len(candidates) == 0 {
		return nil
	}
	existing := map[string]bool{}
	for _, c := range chunk(candidates, 5000) {
		tsv, err := r.ch.QueryTSV(ctx, "SELECT id FROM "+r.t("pages")+" WHERE crawl_id="+cid+" AND id IN ("+quoteList(c)+")")
		if err != nil {
			return err
		}
		for _, row := range tsv {
			if len(row) > 0 {
				existing[strings.TrimSpace(row[0])] = true
			}
		}
	}

	rows, err := r.pool.Query(ctx, `
		SELECT id, domain, url, code, response_time, outlinks, content_type, redirect_to,
		       crawled, compliant, noindex, nofollow, canonical, canonical_value, external, blocked,
		       title, h1, metadesc, simhash, word_count
		FROM pages WHERE crawl_id=$1 AND in_crawl=FALSE`, r.crawlID)
	if err != nil {
		return err
	}
	defer rows.Close()
	batch := make([]any, 0, 2000)
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := r.ch.InsertJSONEachRow(ctx, r.t("pages"), batch)
		batch = batch[:0]
		return err
	}
	for rows.Next() {
		var (
			id                                                                        string
			domain, url, contentType, redirectTo, canonicalValue, title, h1, metadesc *string
			outlinks, wordCount                                                       int
			code                                                                      *int
			responseTime                                                              *float64
			crawled, compliant, noindex, nofollow, canonical, external, blocked       bool
			simhash                                                                   *int64
		)
		if err := rows.Scan(&id, &domain, &url, &code, &responseTime, &outlinks, &contentType, &redirectTo,
			&crawled, &compliant, &noindex, &nofollow, &canonical, &canonicalValue, &external, &blocked,
			&title, &h1, &metadesc, &simhash, &wordCount); err != nil {
			return err
		}
		id = strings.TrimSpace(id)
		if existing[id] {
			continue // already in CH (fetched in-scope) — keep that row
		}
		codeV := 0
		if code != nil {
			codeV = *code
		}
		rtV := 0.0
		if responseTime != nil {
			rtV = *responseTime
		}
		batch = append(batch, map[string]any{
			"crawl_id": r.crawlID, "id": id, "domain": chStr(domain), "url": chStr(url),
			"depth": -1, "code": codeV, "response_time": rtV, "outlinks": outlinks,
			"content_type": chStr(contentType), "redirect_to": chStr(redirectTo), "crawled": b2iCH(crawled),
			"compliant": b2iCH(compliant), "noindex": b2iCH(noindex), "nofollow": b2iCH(nofollow),
			"canonical": b2iCH(canonical), "canonical_value": chStr(canonicalValue), "external": b2iCH(external),
			"blocked": b2iCH(blocked), "title": chStr(title), "h1": chStr(h1), "metadesc": chStr(metadesc),
			"extracts": map[string]string{}, "simhash": simhash, "is_html": 0, "h1_multiple": 0,
			"headings_missing": 0, "schemas": []string{}, "word_count": wordCount,
		})
		if len(batch) >= 2000 {
			if err := flush(); err != nil {
				return err
			}
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	return flush()
}

// chStr derefs a nullable PG string column to "" (CH has no NULLs in those cols).
func chStr(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

// optimizeFinal merges this crawl's partitions so subsequent report reads work
// on fully-merged (deduplicated) parts. pages/html are ReplacingMergeTree, so
// FINAL collapses the >1-row-per-id left by retries/sitemap re-fetches; the other
// tables just benefit from part consolidation. Per-partition + best-effort: a
// failure here only means reads stay at the pre-merge cost, never a data error.
func (r *CHRunner) optimizeFinal(ctx context.Context) error {
	for _, t := range []string{"pages", "page_metrics", "html", "page_schemas"} {
		if err := r.ch.Exec(ctx, fmt.Sprintf("OPTIMIZE TABLE %s PARTITION %d FINAL", r.t(t), r.crawlID)); err != nil {
			return err
		}
	}
	return nil
}

// computePageRank runs the iterative PageRank in ClickHouse and leaves the result
// in the Memory table pr_cur_<crawlID> (id, pr, outlinks). Mirrors the PG version:
// damping 0.85, 30 iterations, dead-end redistribution, optional nofollow filter.
func (r *CHRunner) computePageRank(ctx context.Context) error {
	const iterations, damping = 30, 0.85
	cid := r.cid()
	prCur := r.t("pr_cur_" + cid)
	prNext := r.t("pr_next_" + cid)

	for _, tbl := range []string{prCur, prNext} {
		_ = r.ch.Exec(ctx, "DROP TABLE IF EXISTS "+tbl)
		if err := r.ch.Exec(ctx, "CREATE TABLE "+tbl+" (id FixedString(8), pr Float64, outlinks Int32) ENGINE = Memory"); err != nil {
			return err
		}
	}

	pagesCountStr, err := r.ch.QueryScalar(ctx, "SELECT count() FROM "+r.pdCrawl())
	if err != nil {
		return err
	}
	pagesCount, _ := strconv.Atoi(strings.TrimSpace(pagesCountStr))
	if pagesCount == 0 {
		return nil
	}
	hasLinksStr, _ := r.ch.QueryScalar(ctx, "SELECT count() FROM "+r.t("links")+" WHERE crawl_id="+cid)
	hasLinks := strings.TrimSpace(hasLinksStr) != "0" && hasLinksStr != ""

	// outlinks per node (used both for the init and the dead-end set).
	outlinksSub := `(SELECT src AS sid, count() AS c FROM ` + r.t("links") + ` WHERE crawl_id=` + cid + ` GROUP BY src)`

	if !hasLinks {
		// No graph: pri stays 0 (matches PG, which skips the update entirely).
		return r.ch.Exec(ctx, "INSERT INTO "+prCur+" SELECT id, 0, 0 FROM "+r.pdCrawl())
	}

	initPR := 1.0 / float64(pagesCount)
	bonus := (1 - damping) / float64(pagesCount)
	if err := r.ch.Exec(ctx, `INSERT INTO `+prCur+`
		SELECT p.id, `+f(initPR)+`, ol.c
		FROM `+r.pdCrawl()+` p
		LEFT JOIN `+outlinksSub+` ol ON ol.sid = p.id`); err != nil {
		return err
	}

	nofollowClause := ""
	if r.respectNofollow {
		nofollowClause = " AND l.nofollow = 0"
	}

	for i := 0; i < iterations; i++ {
		deadEndStr, err := r.ch.QueryScalar(ctx, "SELECT sum(pr) FROM "+prCur+" WHERE outlinks=0")
		if err != nil {
			return err
		}
		deadEndPR, _ := strconv.ParseFloat(strings.TrimSpace(deadEndStr), 64)
		iterBonus := bonus + damping*deadEndPR/float64(pagesCount)

		if err := r.ch.Exec(ctx, "TRUNCATE TABLE "+prNext); err != nil {
			return err
		}
		incomingSub := `(SELECT l.target AS tid, sum(c.pr / c.outlinks) AS s
			FROM ` + r.t("links") + ` l
			INNER JOIN ` + prCur + ` c ON c.id = l.src AND c.outlinks > 0
			WHERE l.crawl_id = ` + cid + nofollowClause + `
			GROUP BY l.target)`
		if err := r.ch.Exec(ctx, `INSERT INTO `+prNext+`
			SELECT c.id, `+f(iterBonus)+` + `+f(damping)+` * inc.s, c.outlinks
			FROM `+prCur+` c
			LEFT JOIN `+incomingSub+` inc ON inc.tid = c.id`); err != nil {
			return err
		}
		if err := r.ch.Exec(ctx, "TRUNCATE TABLE "+prCur); err != nil {
			return err
		}
		if err := r.ch.Exec(ctx, "INSERT INTO "+prCur+" SELECT * FROM "+prNext); err != nil {
			return err
		}
	}
	return nil
}

// duplicateAnalysis builds duplicate_clusters: exact clusters (same simhash) in a
// single SQL GROUP BY, plus near-duplicates (Hamming ≤9 on the top-2000 by
// inlinks, union-find in Go) — mirrors the PG duplicate step.
func (r *CHRunner) duplicateAnalysis(ctx context.Context) error {
	cid := r.cid()
	if err := r.ch.DropPartition(ctx, r.t("duplicate_clusters"), r.crawlID); err != nil {
		return err
	}

	// 1. Exact duplicates — one statement, cluster_id from a running row number.
	exactSQL := `INSERT INTO ` + r.t("duplicate_clusters") + ` (crawl_id, cluster_id, similarity, page_count, page_ids)
		SELECT ` + cid + `, rowNumberInAllBlocks() + 1, 100, page_count, page_ids
		FROM (
			SELECT count() AS page_count, groupArray(toString(id)) AS page_ids
			FROM ` + r.t("pages") + `
			WHERE crawl_id = ` + cid + ` AND crawled = 1 AND code = 200 AND compliant = 1 AND simhash IS NOT NULL
			GROUP BY simhash HAVING count() > 1
			ORDER BY count() DESC
		)`
	if err := r.ch.Exec(ctx, exactSQL); err != nil {
		return err
	}

	// 2. Near-duplicates on the top-2000 compliant pages by inlinks.
	rows, err := r.ch.QueryTSV(ctx, `SELECT toString(p.id), toString(p.simhash)
		FROM `+r.t("pages")+` p
		LEFT JOIN `+r.t("page_metrics")+` m ON m.crawl_id = p.crawl_id AND m.id = p.id
		WHERE p.crawl_id = `+cid+` AND p.crawled = 1 AND p.code = 200 AND p.compliant = 1 AND p.simhash IS NOT NULL
		ORDER BY m.inlinks DESC LIMIT 2000`)
	if err != nil {
		return err
	}
	if len(rows) < 2 {
		return nil
	}

	ids := make([]string, 0, len(rows))
	sim := make(map[string]int64, len(rows))
	for _, row := range rows {
		if len(row) < 2 {
			continue
		}
		id := row[0]
		v, perr := strconv.ParseInt(row[1], 10, 64)
		if perr != nil {
			continue
		}
		ids = append(ids, id)
		sim[id] = v
	}

	// pairwise Hamming 1..9, union-find clustering (same as PG).
	parent := map[string]string{}
	var find func(string) string
	find = func(x string) string {
		for parent[x] != x {
			parent[x] = parent[parent[x]]
			x = parent[x]
		}
		return x
	}
	union := func(a, b string) {
		if _, ok := parent[a]; !ok {
			parent[a] = a
		}
		if _, ok := parent[b]; !ok {
			parent[b] = b
		}
		ra, rb := find(a), find(b)
		if ra != rb {
			parent[rb] = ra
		}
	}
	for i := 0; i < len(ids); i++ {
		for j := i + 1; j < len(ids); j++ {
			a, b := ids[i], ids[j]
			if sim[a] == sim[b] {
				continue
			}
			d := bits.OnesCount64(uint64(sim[a] ^ sim[b]))
			if d >= 1 && d <= 9 {
				union(a, b)
			}
		}
	}
	clusters := map[string][]string{}
	for id := range parent {
		root := find(id)
		clusters[root] = append(clusters[root], id)
	}

	// continue cluster_id after the exact clusters.
	maxIDStr, _ := r.ch.QueryScalar(ctx, "SELECT max(cluster_id) FROM "+r.t("duplicate_clusters")+" WHERE crawl_id="+cid)
	nextID, _ := strconv.Atoi(strings.TrimSpace(maxIDStr))

	type dcRow struct {
		CrawlID    int      `json:"crawl_id"`
		ClusterID  int      `json:"cluster_id"`
		Similarity int      `json:"similarity"`
		PageCount  int      `json:"page_count"`
		PageIDs    []string `json:"page_ids"`
	}
	var batch []any
	for _, members := range clusters {
		if len(members) < 2 {
			continue
		}
		// average Hamming distance within the cluster → similarity %.
		sum, n := 0, 0
		for i := 0; i < len(members); i++ {
			for j := i + 1; j < len(members); j++ {
				sum += bits.OnesCount64(uint64(sim[members[i]] ^ sim[members[j]]))
				n++
			}
		}
		avg := 0
		if n > 0 {
			avg = sum / n
		}
		similarity := int(float64(64-avg) / 64.0 * 100.0)
		nextID++
		batch = append(batch, dcRow{r.crawlID, nextID, similarity, len(members), members})
	}
	return r.ch.InsertJSONEachRow(ctx, r.t("duplicate_clusters"), batch)
}

// redirectChainAnalysis follows the redirect edges into chains (detecting loops)
// → redirect_chains. Same graph logic as the PG step, reading/writing CH.
func (r *CHRunner) redirectChainAnalysis(ctx context.Context) error {
	cid := r.cid()
	if err := r.ch.DropPartition(ctx, r.t("redirect_chains"), r.crawlID); err != nil {
		return err
	}

	rows, err := r.ch.QueryTSV(ctx, "SELECT toString(src), toString(target) FROM "+r.t("links")+" WHERE crawl_id="+cid+" AND type='redirect'")
	if err != nil {
		return err
	}
	redirectMap := map[string]string{}
	isTarget := map[string]bool{}
	for _, row := range rows {
		if len(row) < 2 {
			continue
		}
		redirectMap[row[0]] = row[1]
		isTarget[row[1]] = true
	}
	if len(redirectMap) == 0 {
		return nil
	}

	// starters = sources that are not themselves a target (chain heads)…
	var starters []string
	for src := range redirectMap {
		if !isTarget[src] {
			starters = append(starters, src)
		}
	}
	// …plus closed-loop members not reachable from any head.
	covered := map[string]bool{}
	for _, s := range starters {
		cur, visited := s, map[string]bool{}
		for {
			if visited[cur] {
				break
			}
			visited[cur] = true
			covered[cur] = true
			next, ok := redirectMap[cur]
			if !ok {
				break
			}
			cur = next
		}
	}
	loopVisited := map[string]bool{}
	for src := range redirectMap {
		if covered[src] || loopVisited[src] {
			continue
		}
		starters = append(starters, src)
		cur := src
		for {
			if loopVisited[cur] {
				break
			}
			loopVisited[cur] = true
			next, ok := redirectMap[cur]
			if !ok {
				break
			}
			cur = next
		}
	}

	// page info for all involved ids.
	idset := map[string]bool{}
	for s, t := range redirectMap {
		idset[s] = true
		idset[t] = true
	}
	allIDs := make([]string, 0, len(idset))
	for id := range idset {
		allIDs = append(allIDs, id)
	}
	type pinfo struct {
		url       string
		code      int
		hasCode   bool
		compliant bool
	}
	pages := map[string]pinfo{}
	if len(allIDs) > 0 {
		pr, perr := r.ch.QueryTSV(ctx, "SELECT toString(id), url, toString(code), toString(compliant) FROM "+
			r.t("pages")+" WHERE crawl_id="+cid+" AND id IN ("+quoteList(allIDs)+")")
		if perr != nil {
			return perr
		}
		for _, row := range pr {
			if len(row) < 4 {
				continue
			}
			code, cerr := strconv.Atoi(row[2])
			pages[row[0]] = pinfo{url: row[1], code: code, hasCode: cerr == nil, compliant: row[3] == "1"}
		}
	}

	type chRow struct {
		CrawlID        int      `json:"crawl_id"`
		ChainID        int      `json:"chain_id"`
		SourceID       string   `json:"source_id"`
		SourceURL      string   `json:"source_url"`
		FinalID        string   `json:"final_id"`
		FinalURL       string   `json:"final_url"`
		FinalCode      int      `json:"final_code"`
		FinalCompliant int      `json:"final_compliant"`
		Hops           int      `json:"hops"`
		IsLoop         int      `json:"is_loop"`
		ChainIDs       []string `json:"chain_ids"`
	}

	var batch []any
	chainID := 0
	redirectChains, chainsErrors := 0, 0
	for _, start := range starters {
		visited := map[string]bool{}
		var chainIDs []string
		cur := start
		isLoop := false
		for {
			if visited[cur] {
				isLoop = true
				break
			}
			visited[cur] = true
			chainIDs = append(chainIDs, cur)
			next, ok := redirectMap[cur]
			if !ok {
				break
			}
			cur = next
		}
		sourceID := chainIDs[0]
		row := chRow{CrawlID: r.crawlID, SourceID: sourceID, ChainIDs: chainIDs, IsLoop: b2iCH(isLoop)}
		if sp, ok := pages[sourceID]; ok {
			row.SourceURL = sp.url
		}
		hops := 0
		if isLoop {
			hops = len(chainIDs)
		} else {
			finalID := chainIDs[len(chainIDs)-1]
			row.FinalID = finalID
			if fp, ok := pages[finalID]; ok {
				row.FinalURL = fp.url
				if fp.hasCode {
					row.FinalCode = fp.code
				}
				row.FinalCompliant = b2iCH(fp.compliant)
			}
			hops = len(chainIDs) - 1
		}
		row.Hops = hops
		if hops <= 0 && !isLoop {
			continue
		}
		chainID++
		row.ChainID = chainID
		batch = append(batch, row)
		redirectChains++
		if isLoop || (row.FinalCode != 200 && row.FinalID != "") {
			chainsErrors++
		}
	}
	_ = redirectChains
	_ = chainsErrors
	return r.ch.InsertJSONEachRow(ctx, r.t("redirect_chains"), batch)
}

func b2iCH(b bool) int {
	if b {
		return 1
	}
	return 0
}

// f formats a float for inline SQL without scientific notation surprises.
func f(v float64) string { return strconv.FormatFloat(v, 'f', -1, 64) }

// quoteList renders ids as a ClickHouse string IN-list: 'a','b',…
func quoteList(ids []string) string {
	parts := make([]string, len(ids))
	for i, id := range ids {
		parts[i] = "'" + strings.ReplaceAll(id, "'", "") + "'"
	}
	return strings.Join(parts, ",")
}
