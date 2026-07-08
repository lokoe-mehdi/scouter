package analysis

import "testing"

func TestLooksLikeSitemapLoc(t *testing.T) {
	sitemaps := []string{
		"https://tinder.com/sitemap-af.xml.gz",
		"https://tinder.com/sitemap-en-GB.xml.gz",
		"https://example.com/sitemap.xml",
		"https://example.com/sitemap_index.xml",
		"https://example.com/sitemaps/products.xml.gz",
		"https://example.com/sitemap1.gz",
		"https://example.com/news-sitemap.xml?foo=bar",
	}
	for _, u := range sitemaps {
		if !looksLikeSitemapLoc(u) {
			t.Errorf("expected %q to look like a sitemap loc", u)
		}
	}

	pages := []string{
		"https://policies.tinder.com/privacy",
		"https://www.help.tinder.com/",
		"https://example.com/product/123",
		"https://example.com/feed.xml",       // .xml but not a sitemap name
		"https://example.com/download/data.gz", // .gz but not a sitemap name
		"https://example.com/page?x=sitemap",   // query only, not the file
	}
	for _, u := range pages {
		if looksLikeSitemapLoc(u) {
			t.Errorf("expected %q NOT to look like a sitemap loc", u)
		}
	}
}

func TestParseSitemapXMLRoots(t *testing.T) {
	// Mislabelled index: child sitemaps listed as <url> inside a <urlset>.
	mislabeled := []byte(`<?xml version="1.0"?>
	<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
	  <url><loc>https://tinder.com/sitemap-fr.xml.gz</loc></url>
	  <url><loc>https://policies.tinder.com/privacy</loc></url>
	</urlset>`)
	root, locs, err := parseSitemapXML(mislabeled)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if root != "urlset" {
		t.Fatalf("root = %q, want urlset", root)
	}
	if len(locs) != 2 || locs[0] != "https://tinder.com/sitemap-fr.xml.gz" {
		t.Fatalf("locs = %v", locs)
	}
	// The urlset handler must recurse into the first (sitemap) loc and keep the
	// second (page) loc as a URL — asserted here at the classification level.
	if !looksLikeSitemapLoc(locs[0]) {
		t.Errorf("first loc should be treated as a nested sitemap")
	}
	if looksLikeSitemapLoc(locs[1]) {
		t.Errorf("second loc should be treated as a page URL")
	}

	index := []byte(`<sitemapindex><sitemap><loc>https://e.com/s.xml</loc></sitemap></sitemapindex>`)
	root, _, err = parseSitemapXML(index)
	if err != nil || root != "sitemapindex" {
		t.Fatalf("root = %q err = %v, want sitemapindex", root, err)
	}
}
