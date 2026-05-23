package page

import (
	"encoding/json"
	"regexp"
	"strings"

	"github.com/antchfx/htmlquery"
	"golang.org/x/net/html"
)

// extractSchemaTypes ports Page::extractSchemaTypes: collects unique top-level
// structured-data @type names from JSON-LD, Microdata and RDFa.
var (
	jsonLdRe       = regexp.MustCompile(`(?is)<script[^>]*type=["']application/ld\+json["'][^>]*>(.*?)</script>`)
	schemaOrgRe    = regexp.MustCompile(`(?i)^https?://schema\.org/(.+)$`)
	schemaPrefixRe = regexp.MustCompile(`(?i)^schema:(.+)$`)
	schemaPlainRe  = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9]*$`)
	wsRe           = regexp.MustCompile(`\s+`)
)

func extractSchemaTypes(doc *html.Node, dom string) []string {
	var types []string
	if dom == "" {
		return types
	}
	extractJSONLDTypes(dom, &types)
	if doc != nil {
		// Microdata: itemscope + itemtype, NOT itemprop
		for _, n := range htmlquery.Find(doc, "//*[@itemscope][@itemtype][not(@itemprop)]") {
			if it := htmlquery.SelectAttr(n, "itemtype"); it != "" {
				if t := cleanSchemaType(it); t != "" {
					types = append(types, t)
				}
			}
		}
		// RDFa: typeof, NOT property
		for _, n := range htmlquery.Find(doc, "//*[@typeof][not(@property)]") {
			if to := htmlquery.SelectAttr(n, "typeof"); to != "" {
				for _, raw := range wsRe.Split(strings.TrimSpace(to), -1) {
					if t := cleanSchemaType(raw); t != "" {
						types = append(types, t)
					}
				}
			}
		}
	}
	return uniqueStrings(types)
}

func extractJSONLDTypes(dom string, types *[]string) {
	for _, m := range jsonLdRe.FindAllStringSubmatch(dom, -1) {
		content := strings.TrimSpace(m[1])
		if content == "" {
			continue
		}
		var data interface{}
		if err := json.Unmarshal([]byte(content), &data); err != nil {
			continue
		}
		extractTopLevelTypes(data, types)
	}
}

func extractTopLevelTypes(data interface{}, types *[]string) {
	obj, ok := data.(map[string]interface{})
	if ok {
		if graph, ok := obj["@graph"].([]interface{}); ok {
			for _, item := range graph {
				extractTypeFromObject(item, types)
			}
			return
		}
		if _, ok := obj["@type"]; ok {
			extractTypeFromObject(obj, types)
			return
		}
		return
	}
	if arr, ok := data.([]interface{}); ok {
		for _, item := range arr {
			extractTypeFromObject(item, types)
		}
	}
}

func extractTypeFromObject(item interface{}, types *[]string) {
	obj, ok := item.(map[string]interface{})
	if !ok {
		return
	}
	t, ok := obj["@type"]
	if !ok {
		return
	}
	switch v := t.(type) {
	case string:
		*types = append(*types, v)
	case []interface{}:
		for _, e := range v {
			if s, ok := e.(string); ok {
				*types = append(*types, s)
			}
		}
	}
}

func cleanSchemaType(raw string) string {
	raw = strings.TrimSpace(raw)
	if m := schemaOrgRe.FindStringSubmatch(raw); m != nil {
		return m[1]
	}
	if m := schemaPrefixRe.FindStringSubmatch(raw); m != nil {
		return m[1]
	}
	if schemaPlainRe.MatchString(raw) {
		return raw
	}
	return ""
}

func uniqueStrings(in []string) []string {
	seen := map[string]bool{}
	var out []string
	for _, s := range in {
		if !seen[s] {
			seen[s] = true
			out = append(out, s)
		}
	}
	return out
}
