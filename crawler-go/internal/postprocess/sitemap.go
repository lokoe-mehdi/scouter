package postprocess

import (
	"context"
	"regexp"
	"strconv"
	"strings"
	"time"

	"scouter-crawler/internal/analysis"
)

var smDomainRe = regexp.MustCompile(`(?i)https?://([^/?]+)`)

// sitemapAnalysis ports PostProcessor::sitemapAnalysis: only on a cleanly
// finished crawl, parse the configured sitemap(s), mark known pages in_sitemap,
// insert sitemap-only placeholders, and fetch the new in-scope URLs (via the
// SitemapFetch callback, which runs a skip-link-extraction crawl pass).
func (r *Runner) sitemapAnalysis(ctx context.Context) error {
	var status string
	if err := r.pool.QueryRow(ctx, "SELECT status FROM crawls WHERE id=$1", r.crawlID).Scan(&status); err != nil {
		return err
	}
	switch status {
	case "stopping", "stopped", "failed", "error":
		return nil // deferred until clean completion
	}

	var raw []byte
	var domain string
	if err := r.pool.QueryRow(ctx, "SELECT config, domain FROM crawls WHERE id=$1", r.crawlID).Scan(&raw, &domain); err != nil {
		return err
	}
	sitemapURLs := advancedStrings(raw, "sitemap_urls")
	clean := sitemapURLs[:0]
	for _, u := range sitemapURLs {
		if t := strings.TrimSpace(u); t != "" {
			clean = append(clean, t)
		}
	}
	if len(clean) == 0 {
		return nil
	}

	result := analysis.NewSitemapParser().Parse(clean)
	if len(result.URLs) == 0 {
		return nil
	}

	idToURL := make(map[string]string, len(result.URLs))
	for _, u := range result.URLs {
		idToURL[analysis.PageID(u)] = u
	}
	allIDs := make([]string, 0, len(idToURL))
	for id := range idToURL {
		allIDs = append(allIDs, id)
	}

	existing := map[string]bool{}
	for _, chunk := range chunk(allIDs, 5000) {
		rows, err := r.pool.Query(ctx, "SELECT id FROM pages WHERE crawl_id=$1 AND id = ANY($2)", r.crawlID, chunk)
		if err != nil {
			return err
		}
		for rows.Next() {
			var id string
			if err := rows.Scan(&id); err != nil {
				rows.Close()
				return err
			}
			existing[strings.TrimSpace(id)] = true
		}
		rows.Close()
	}

	// mark in_sitemap on known ids
	matchedIDs := make([]string, 0, len(existing))
	for id := range existing {
		matchedIDs = append(matchedIDs, id)
	}
	for _, chunk := range chunk(matchedIDs, 5000) {
		if _, err := r.pool.Exec(ctx, "UPDATE pages SET in_sitemap = TRUE WHERE crawl_id=$1 AND id = ANY($2)", r.crawlID, chunk); err != nil {
			return err
		}
	}

	allowed := generalStrings(raw, "domains")
	if len(allowed) == 0 {
		allowed = []string{domain}
	}

	var newInScopeURLs []string
	inScope := map[string]string{}
	outScope := map[string]string{}
	for id, u := range idToURL {
		if existing[id] {
			continue
		}
		if urlInScope(u, allowed) {
			inScope[id] = u
			newInScopeURLs = append(newInScopeURLs, u)
		} else {
			outScope[id] = u
		}
	}

	_ = r.insertSitemapOnly(ctx, inScope, false)
	_ = r.insertSitemapOnly(ctx, outScope, true)

	if len(newInScopeURLs) > 0 && r.SitemapFetch != nil {
		if err := r.SitemapFetch(ctx, newInScopeURLs, allowed); err != nil {
			r.logf("sitemap fetch error: %v", err)
		}
	}
	return nil
}

// insertSitemapOnly batch-inserts sitemap-only placeholder rows (depth=-1,
// in_crawl=false, in_sitemap=true), ON CONFLICT DO NOTHING.
func (r *Runner) insertSitemapOnly(ctx context.Context, idToURL map[string]string, external bool) int {
	if len(idToURL) == 0 {
		return 0
	}
	now := time.Now()
	total := 0
	ids := make([]string, 0, len(idToURL))
	for id := range idToURL {
		ids = append(ids, id)
	}
	for _, c := range chunk(ids, 500) {
		var sb strings.Builder
		sb.WriteString("INSERT INTO pages (crawl_id, id, domain, url, depth, code, crawled, external, blocked, date, in_crawl, in_sitemap) VALUES ")
		args := make([]any, 0, len(c)*7)
		for i, id := range c {
			u := idToURL[id]
			if i > 0 {
				sb.WriteByte(',')
			}
			b := i * 7
			sb.WriteString("($" + itoa(b+1) + ",$" + itoa(b+2) + ",$" + itoa(b+3) + ",$" + itoa(b+4) + ",-1,0,FALSE,$" + itoa(b+5) + ",FALSE,$" + itoa(b+6) + ",FALSE,TRUE)")
			args = append(args, r.crawlID, id, smDomain(u), truncate(u, 2083), external, now)
		}
		sb.WriteString(" ON CONFLICT (crawl_id, id) DO NOTHING")
		tag, err := r.pool.Exec(ctx, sb.String(), args...)
		if err == nil {
			total += int(tag.RowsAffected())
		}
	}
	return total
}

func urlInScope(u string, allowed []string) bool {
	for _, domain := range allowed {
		d := strings.ReplaceAll(domain, ".", `\.`)
		d = strings.ReplaceAll(d, "*", `[^.]*`)
		if re, err := regexp.Compile(`(?i)^https?://` + d); err == nil && re.MatchString(strings.TrimSpace(u)) {
			return true
		}
	}
	return false
}

func smDomain(u string) string {
	m := smDomainRe.FindStringSubmatch(u)
	if len(m) > 1 {
		return truncate(m[1], 255)
	}
	return ""
}

func truncate(s string, max int) string {
	if len(s) <= max {
		return s
	}
	for max > 0 && s[max]&0xC0 == 0x80 {
		max--
	}
	return s[:max]
}

func itoa(i int) string {
	return strconv.Itoa(i)
}

func chunk(ids []string, size int) [][]string {
	var out [][]string
	for i := 0; i < len(ids); i += size {
		end := i + size
		if end > len(ids) {
			end = len(ids)
		}
		out = append(out, ids[i:end])
	}
	return out
}
