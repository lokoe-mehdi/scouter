package crawl

import (
	"context"
	"regexp"
	"strings"

	"scouter-crawler/internal/analysis"
	"scouter-crawler/internal/db"
	"scouter-crawler/internal/model"
)

var targetDomainRe = regexp.MustCompile(`(?i)https?://([^/?]+)`)

// storePage ports PageCrawler::run/storePageComplete/storeLinks/storeRedirect/
// storeRaw: persist one parsed page and its discovered frontier rows.
func (e *Engine) storePage(ctx context.Context, p model.Page, depth int) error {
	respectCanonical := e.cfg.RespectCanonical
	isCanonical := p.Canonical
	canonicalForIndex := isCanonical || !respectCanonical
	blocked := !analysis.Allowed(p.URL)
	if !e.cfg.RespectRobots {
		blocked = false
	}

	countLinks := len(p.Links)
	if !isCanonical && respectCanonical {
		if p.CanonicalURL != "" {
			countLinks = 1
		} else {
			countLinks = 0
		}
	}

	compliant := !blocked && !p.Noindex && canonicalForIndex && p.HTTPCode == 200 && p.DomHash != ""

	sets := map[string]any{
		"code":             p.HTTPCode,
		"crawled":          true,
		"content_type":     truncateStr(p.ContentType, 100),
		"outlinks":         countLinks,
		"nofollow":         p.Nofollow,
		"compliant":        compliant,
		"noindex":          p.Noindex,
		"canonical":        isCanonical,
		"canonical_value":  p.CanonicalURL,
		"redirect_to":      p.RedirectTo,
		"response_time":    p.ResponseTime * 1000.0,
		"is_html":          p.IsHTML,
		"simhash":          p.Simhash,
		"h1_multiple":      p.H1Multiple,
		"headings_missing": p.HeadingsMissing,
	}
	if p.DomHash != "" {
		sets["title"] = p.Title
		sets["h1"] = p.H1
		sets["metadesc"] = p.MetaDesc
		sets["word_count"] = p.WordCount
		if len(p.CustomExtract) > 0 {
			sets["extracts"] = p.CustomExtract
		}
		if len(p.Schemas) > 0 {
			sets["schemas"] = p.Schemas
		}
	}
	if err := e.cdb.UpdatePage(ctx, p.ID, sets); err != nil {
		return err
	}
	if p.DomHash != "" && len(p.Schemas) > 0 {
		_ = e.cdb.InsertPageSchemas(ctx, p.ID, p.Schemas)
	}

	if e.skipLinkExtraction {
		return e.storeRaw(ctx, p)
	}

	if strings.TrimSpace(p.RedirectTo) != "" {
		return e.storeRedirect(ctx, p, depth)
	}
	if err := e.storeLinks(ctx, p, depth); err != nil {
		return err
	}
	return e.storeRaw(ctx, p)
}

func (e *Engine) storeRedirect(ctx context.Context, p model.Page, depth int) error {
	url := p.RedirectTo
	isList := e.cfg.CrawlType == "list"
	id := analysis.PageID(url)
	external := isExternal(url, e.cfg.Domains)
	if isList {
		external = false
	}

	if err := e.cdb.InsertLink(ctx, db.LinkRow{
		Src: p.ID, Target: id, Type: "redirect", External: external, Nofollow: false, Position: "Content",
	}); err != nil {
		return err
	}
	if !e.cfg.FollowRedirects {
		return nil
	}
	blocked := e.cfg.RespectRobots && !analysis.Allowed(url)
	return e.cdb.InsertPage(ctx, db.PageRow{
		ID: id, Domain: p.Domain, URL: url, Depth: depth, Code: 0,
		Crawled: false, External: external, Blocked: blocked,
	})
}

func (e *Engine) storeLinks(ctx context.Context, p model.Page, depth int) error {
	isList := e.cfg.CrawlType == "list"
	src := p.ID
	childDepth := depth + 1

	// Non-canonical + respect_canonical: follow only the canonical.
	if !p.Canonical && e.cfg.RespectCanonical {
		if p.CanonicalURL == "" {
			return nil
		}
		cible := analysis.PageID(p.CanonicalURL)
		external := isExternal(p.CanonicalURL, e.cfg.Domains)
		blocked := e.cfg.RespectRobots && !analysis.Allowed(p.CanonicalURL)
		domain := targetDomain(p.CanonicalURL)
		if err := e.cdb.InsertLink(ctx, db.LinkRow{
			Src: src, Target: cible, Type: "canonical", External: external, Position: "Content",
		}); err != nil {
			return err
		}
		ext := external
		if isList {
			ext = true
		}
		return e.cdb.InsertPage(ctx, db.PageRow{
			ID: cible, Domain: domain, URL: p.CanonicalURL, Depth: childDepth,
			Crawled: false, External: ext, Blocked: blocked, InCrawl: true,
		})
	}

	respectNofollow := e.cfg.RespectNofollow
	pageMetaNofollow := respectNofollow && p.Nofollow

	links := make([]db.LinkRow, 0, len(p.Links))
	pages := make([]db.PageRow, 0, len(p.Links))
	for _, l := range p.Links {
		var xptr *string
		if l.XPath != "" {
			xp := l.XPath
			xptr = &xp
		}
		links = append(links, db.LinkRow{
			Src: src, Target: l.TargetID, Anchor: l.Anchor, Type: "ahref",
			External: l.External, Nofollow: l.Nofollow, XPath: xptr, Position: positionOr(l.Position),
		})
		followable := !respectNofollow || (!pageMetaNofollow && !l.Nofollow)
		ext := l.External
		if isList {
			ext = true
		}
		pages = append(pages, db.PageRow{
			ID: l.TargetID, Domain: targetDomain(l.Target), URL: l.Target, Depth: childDepth,
			Crawled: false, External: ext, Blocked: l.Blocked, InCrawl: followable,
		})
	}
	if len(links) > 0 {
		if err := e.cdb.InsertLinks(ctx, links); err != nil {
			return err
		}
	}
	if len(pages) > 0 {
		if err := e.cdb.InsertPages(ctx, pages); err != nil {
			return err
		}
	}
	return nil
}

func (e *Engine) storeRaw(ctx context.Context, p model.Page) error {
	if !e.cfg.StoreHTML || p.DomZip == "" {
		return nil
	}
	return e.cdb.InsertHTML(ctx, p.ID, p.DomZip)
}

func targetDomain(url string) string {
	m := targetDomainRe.FindStringSubmatch(url)
	if len(m) > 1 {
		return truncateStr(m[1], 255)
	}
	return ""
}

func positionOr(p string) string {
	if p == "" {
		return "Content"
	}
	return p
}

func truncateStr(s string, max int) string {
	if len(s) <= max {
		return s
	}
	for max > 0 && s[max]&0xC0 == 0x80 {
		max--
	}
	return s[:max]
}

// isExternal mirrors PageCrawler::isExternal (allowed-domain pattern matching).
func isExternal(url string, pattern []string) bool {
	for _, domain := range pattern {
		d := strings.ReplaceAll(domain, ".", `\.`)
		d = strings.ReplaceAll(d, "*", `[^.]*`)
		if re, err := regexp.Compile(`^https?://` + d); err == nil && re.MatchString(strings.TrimSpace(url)) {
			return false
		}
	}
	return true
}
