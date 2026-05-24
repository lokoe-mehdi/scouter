package postprocess

import (
	"context"
	"strings"

	"github.com/jackc/pgx/v5"
)

// duplicateAnalysis ports PostProcessor::duplicateAnalysis: exact clusters (same
// simhash) + near-duplicates (Hamming ≤ 9 on the top-2000 by inlinks, clustered
// via union-find) → duplicate_clusters, then crawl stats.
func (r *Runner) duplicateAnalysis(ctx context.Context) error {
	_, _ = r.pool.Exec(ctx, "SELECT create_crawl_partitions($1)", r.crawlID)
	if _, err := r.pool.Exec(ctx, "DELETE FROM duplicate_clusters WHERE crawl_id=$1", r.crawlID); err != nil {
		return err
	}

	totalDup, totalClusters := 0, 0

	// 1. Exact duplicates
	rows, err := r.pool.Query(ctx, `
		SELECT array_agg(id) AS page_ids, COUNT(*) AS page_count
		FROM pages
		WHERE crawl_id=$1 AND crawled=true AND code=200 AND compliant=true AND simhash IS NOT NULL
		GROUP BY simhash HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC`, r.crawlID)
	if err != nil {
		return err
	}
	type cluster struct {
		ids   []string
		count int
	}
	var exact []cluster
	for rows.Next() {
		var ids []string
		var cnt int
		if err := rows.Scan(&ids, &cnt); err != nil {
			rows.Close()
			return err
		}
		exact = append(exact, cluster{trimAll(ids), cnt})
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	for _, c := range exact {
		if _, err := r.pool.Exec(ctx, `
			INSERT INTO duplicate_clusters (crawl_id, similarity, page_count, page_ids)
			VALUES ($1, 100, $2, $3)`, r.crawlID, c.count, c.ids); err != nil {
			return err
		}
		totalDup += c.count
		totalClusters++
	}

	// 2. Near-duplicates (Hamming 1..9 on top-2000 by inlinks)
	if err := r.nearDuplicates(ctx, &totalDup, &totalClusters); err != nil {
		r.logf("near-duplicate analysis failed: %v", err)
	}

	_, err = r.pool.Exec(ctx, `
		UPDATE crawls SET compliant_duplicate=$1, clusters_duplicate=$2 WHERE id=$3`,
		totalDup, totalClusters, r.crawlID)
	return err
}

func (r *Runner) nearDuplicates(ctx context.Context, totalDup, totalClusters *int) error {
	rows, err := r.pool.Query(ctx, `
		WITH pages_sample AS (
			SELECT id, simhash FROM pages
			WHERE crawl_id=$1 AND crawled=true AND code=200 AND compliant=true AND simhash IS NOT NULL
			ORDER BY inlinks DESC LIMIT 2000
		)
		SELECT p1.id, p2.id
		FROM pages_sample p1 JOIN pages_sample p2 ON p1.id < p2.id
		WHERE bit_count((p1.simhash # p2.simhash)::bit(64)) BETWEEN 1 AND 9
		  AND p1.simhash != p2.simhash`, r.crawlID)
	if err != nil {
		return err
	}
	parent := map[string]string{}
	find := func(x string) string {
		for parent[x] != x {
			x = parent[x]
		}
		return x
	}
	var pairs [][2]string
	for rows.Next() {
		var a, b string
		if err := rows.Scan(&a, &b); err != nil {
			rows.Close()
			return err
		}
		a, b = strings.TrimSpace(a), strings.TrimSpace(b)
		pairs = append(pairs, [2]string{a, b})
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	for _, p := range pairs {
		a, b := p[0], p[1]
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
	clusters := map[string][]string{}
	for id := range parent {
		root := find(id)
		clusters[root] = append(clusters[root], id)
	}
	for _, ids := range clusters {
		if len(ids) < 2 {
			continue
		}
		var avgDist *int
		if err := r.pool.QueryRow(ctx, `
			SELECT AVG(bit_count((p1.simhash # p2.simhash)::bit(64)))::int
			FROM pages p1, pages p2
			WHERE p1.crawl_id=$1 AND p2.crawl_id=$1 AND p1.id = ANY($2) AND p2.id = ANY($2)
			  AND p1.id < p2.id AND p1.compliant=true AND p2.compliant=true`,
			r.crawlID, ids).Scan(&avgDist); err != nil {
			continue
		}
		d := 0
		if avgDist != nil {
			d = *avgDist
		}
		similarity := int(float64(64-d) / 64.0 * 100.0)
		if _, err := r.pool.Exec(ctx, `
			INSERT INTO duplicate_clusters (crawl_id, similarity, page_count, page_ids)
			VALUES ($1,$2,$3,$4)`, r.crawlID, similarity, len(ids), ids); err != nil {
			continue
		}
		*totalDup += len(ids)
		*totalClusters++
	}
	return nil
}

// redirectChainAnalysis ports PostProcessor::redirectChainAnalysis: follow the
// redirect edges into chains (detecting loops) → redirect_chains + stats.
func (r *Runner) redirectChainAnalysis(ctx context.Context) error {
	_, _ = r.pool.Exec(ctx, "SELECT create_crawl_partitions($1)", r.crawlID)
	if _, err := r.pool.Exec(ctx, "DELETE FROM redirect_chains WHERE crawl_id=$1", r.crawlID); err != nil {
		return err
	}

	rows, err := r.pool.Query(ctx, "SELECT src, target FROM links WHERE crawl_id=$1 AND type='redirect'", r.crawlID)
	if err != nil {
		return err
	}
	redirectMap := map[string]string{}
	isTarget := map[string]bool{}
	for rows.Next() {
		var src, target string
		if err := rows.Scan(&src, &target); err != nil {
			rows.Close()
			return err
		}
		src, target = strings.TrimSpace(src), strings.TrimSpace(target)
		redirectMap[src] = target
		isTarget[target] = true
	}
	rows.Close()
	if len(redirectMap) == 0 {
		return r.updateRedirectStats(ctx, 0, 0, 0)
	}

	var starters []string
	for src := range redirectMap {
		if !isTarget[src] {
			starters = append(starters, src)
		}
	}
	// closed-loop starters
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

	// load page info for all involved ids
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
		code      *int
		compliant bool
	}
	pages := map[string]pinfo{}
	if len(allIDs) > 0 {
		pr, err := r.pool.Query(ctx, "SELECT id, url, code, compliant FROM pages WHERE crawl_id=$1 AND id = ANY($2)", r.crawlID, allIDs)
		if err != nil {
			return err
		}
		for pr.Next() {
			var id, url string
			var code *int
			var compliant bool
			if err := pr.Scan(&id, &url, &code, &compliant); err != nil {
				pr.Close()
				return err
			}
			pages[strings.TrimSpace(id)] = pinfo{url, code, compliant}
		}
		pr.Close()
	}

	type chain struct {
		sourceID, sourceURL string
		finalID, finalURL   *string
		finalCode           *int
		finalCompliant      bool
		hops                int
		isLoop              bool
		chainIDs            []string
	}
	var chains []chain
	for _, start := range starters {
		visited := map[string]bool{}
		var ids []string
		cur := start
		isLoop := false
		for {
			if visited[cur] {
				isLoop = true
				break
			}
			visited[cur] = true
			ids = append(ids, cur)
			next, ok := redirectMap[cur]
			if !ok {
				break
			}
			cur = next
		}
		sourceID := ids[0]
		ch := chain{sourceID: sourceID, chainIDs: ids, isLoop: isLoop}
		if sp, ok := pages[sourceID]; ok {
			ch.sourceURL = sp.url
		}
		if isLoop {
			ch.hops = len(ids)
		} else {
			finalID := ids[len(ids)-1]
			ch.finalID = &finalID
			if fp, ok := pages[finalID]; ok {
				u := fp.url
				ch.finalURL = &u
				ch.finalCode = fp.code
				ch.finalCompliant = fp.compliant
			}
			ch.hops = len(ids) - 1
		}
		if ch.hops > 0 || isLoop {
			chains = append(chains, ch)
		}
	}

	for _, ch := range chains {
		if _, err := r.pool.Exec(ctx, `
			INSERT INTO redirect_chains (crawl_id, source_id, source_url, final_id, final_url, final_code, final_compliant, hops, is_loop, chain_ids)
			VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)`,
			r.crawlID, ch.sourceID, ch.sourceURL, ch.finalID, ch.finalURL, ch.finalCode, ch.finalCompliant, ch.hops, ch.isLoop, ch.chainIDs); err != nil {
			return err
		}
	}

	var redirectTotal int
	_ = r.pool.QueryRow(ctx, "SELECT COUNT(*) FROM pages WHERE crawl_id=$1 AND code>=300 AND code<400", r.crawlID).Scan(&redirectTotal)
	chainsErrors := 0
	for _, ch := range chains {
		if ch.isLoop || (ch.finalCode != nil && *ch.finalCode != 200) {
			chainsErrors++
		}
	}
	return r.updateRedirectStats(ctx, redirectTotal, len(chains), chainsErrors)
}

func (r *Runner) updateRedirectStats(ctx context.Context, total, chains, errCount int) error {
	_, err := r.pool.Exec(ctx, `
		UPDATE crawls SET redirect_total=$1, redirect_chains_count=$2, redirect_chains_errors=$3 WHERE id=$4`,
		total, chains, errCount, r.crawlID)
	return err
}

func trimAll(ids []string) []string {
	out := make([]string, len(ids))
	for i, s := range ids {
		out[i] = strings.TrimSpace(s)
	}
	return out
}

var _ = pgx.ErrNoRows
