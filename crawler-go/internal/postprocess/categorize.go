package postprocess

import (
	"context"
	"encoding/json"
	"regexp"
	"strings"

	"gopkg.in/yaml.v3"
)

// catRule is one parsed categorization rule (order preserved = first match wins).
type catRule struct {
	name     string
	domain   string
	includes []string
	excludes []string
	color    string
}

// categorize ports PostProcessor::categorize + CategorizationService::applyCategorization.
func (r *Runner) categorize(ctx context.Context) error {
	var projectID *int
	var domain string
	if err := r.pool.QueryRow(ctx, "SELECT project_id, domain FROM crawls WHERE id=$1", r.crawlID).Scan(&projectID, &domain); err != nil {
		return err
	}

	yamlContent := ""
	if projectID != nil {
		var cfg *string
		_ = r.pool.QueryRow(ctx, "SELECT categorization_config FROM projects WHERE id=$1", *projectID).Scan(&cfg)
		if cfg != nil && *cfg != "" {
			yamlContent = *cfg
		}
	}
	if yamlContent == "" {
		var cfg *string
		_ = r.pool.QueryRow(ctx, "SELECT config FROM categorization_config WHERE crawl_id=$1", r.crawlID).Scan(&cfg)
		if cfg != nil && *cfg != "" {
			yamlContent = *cfg
		}
	}
	if yamlContent == "" {
		return nil // no config
	}
	if projectID == nil {
		return nil
	}

	rules, err := parseRules(yamlContent)
	if err != nil {
		return err
	}

	if _, err := r.pool.Exec(ctx, "UPDATE pages SET cat_id = NULL WHERE crawl_id = $1", r.crawlID); err != nil {
		return err
	}

	for _, rule := range rules {
		var catID int
		if err := r.pool.QueryRow(ctx, `
			INSERT INTO crawl_categories (project_id, cat, color) VALUES ($1,$2,$3)
			ON CONFLICT (project_id, cat) DO UPDATE SET color = EXCLUDED.color RETURNING id`,
			*projectID, rule.name, rule.color).Scan(&catID); err != nil {
			return err
		}
		domainEscaped := regexp.QuoteMeta(rule.domain)
		includePattern := strings.Join(rule.includes, "|")
		if len(rule.excludes) > 0 {
			excludePattern := strings.Join(rule.excludes, "|")
			_, err = r.pool.Exec(ctx, `
				UPDATE pages SET cat_id = $1
				WHERE crawl_id = $2 AND cat_id IS NULL AND external = false
				  AND url ~* $3
				  AND regexp_replace(url, '^https?://[^/]+', '') ~* $4
				  AND NOT regexp_replace(url, '^https?://[^/]+', '') ~* $5`,
				catID, r.crawlID, domainEscaped, includePattern, excludePattern)
		} else {
			_, err = r.pool.Exec(ctx, `
				UPDATE pages SET cat_id = $1
				WHERE crawl_id = $2 AND cat_id IS NULL AND external = false
				  AND url ~* $3
				  AND regexp_replace(url, '^https?://[^/]+', '') ~* $4`,
				catID, r.crawlID, domainEscaped, includePattern)
		}
		if err != nil {
			return err
		}
	}

	// Cleanup categories no longer in the YAML (project-scoped).
	keep := make([]string, 0, len(rules))
	for _, rule := range rules {
		keep = append(keep, rule.name)
	}
	if len(keep) > 0 {
		_, _ = r.pool.Exec(ctx, `
			UPDATE pages SET cat_id = NULL
			WHERE crawl_id IN (SELECT id FROM crawls WHERE project_id = $1)
			  AND cat_id IN (SELECT id FROM crawl_categories WHERE project_id = $1 AND cat <> ALL($2))`,
			*projectID, keep)
		_, _ = r.pool.Exec(ctx, `DELETE FROM crawl_categories WHERE project_id = $1 AND cat <> ALL($2)`, *projectID, keep)
	} else {
		_, _ = r.pool.Exec(ctx, `
			UPDATE pages SET cat_id = NULL
			WHERE crawl_id IN (SELECT id FROM crawls WHERE project_id = $1)
			  AND cat_id IN (SELECT id FROM crawl_categories WHERE project_id = $1)`, *projectID)
		_, _ = r.pool.Exec(ctx, `DELETE FROM crawl_categories WHERE project_id = $1`, *projectID)
	}
	return nil
}

// parseRules ports CategorizationService::parseRules, preserving YAML mapping
// order via yaml.Node (first match wins).
func parseRules(yamlContent string) ([]catRule, error) {
	var root yaml.Node
	if err := yaml.Unmarshal([]byte(yamlContent), &root); err != nil {
		return nil, err
	}
	if len(root.Content) == 0 || root.Content[0].Kind != yaml.MappingNode {
		return nil, nil
	}
	mapping := root.Content[0]
	var rules []catRule
	for i := 0; i+1 < len(mapping.Content); i += 2 {
		name := mapping.Content[i].Value
		val := mapping.Content[i+1]
		if val.Kind != yaml.MappingNode {
			continue
		}
		var dom, color string
		var includes, excludes []string
		color = "#aaaaaa"
		for j := 0; j+1 < len(val.Content); j += 2 {
			key := val.Content[j].Value
			node := val.Content[j+1]
			switch key {
			case "dom":
				if node.Kind == yaml.ScalarNode {
					dom = node.Value
				}
			case "include":
				includes = scalarOrSeq(node)
			case "exclude":
				excludes = scalarOrSeq(node)
			case "color":
				color = strings.Trim(node.Value, `"'`)
			}
		}
		if dom == "" || len(includes) == 0 {
			continue
		}
		// validate regexes (drop rule on invalid, like PHP throwing — we skip)
		if !validRegexes(includes) || !validRegexes(excludes) {
			continue
		}
		rules = append(rules, catRule{name: name, domain: dom, includes: includes, excludes: excludes, color: color})
	}
	return rules, nil
}

func scalarOrSeq(n *yaml.Node) []string {
	if n.Kind == yaml.ScalarNode {
		if n.Value == "" {
			return nil
		}
		return []string{n.Value}
	}
	var out []string
	for _, c := range n.Content {
		if c.Kind == yaml.ScalarNode {
			out = append(out, c.Value)
		}
	}
	return out
}

func validRegexes(patterns []string) bool {
	for _, p := range patterns {
		if _, err := regexp.Compile(p); err != nil {
			return false
		}
	}
	return true
}

// advancedBool reads config.advanced.<key> with a default, for raw JSONB.
func advancedBool(raw []byte, key string, def bool) bool {
	var cfg struct {
		Advanced map[string]json.RawMessage `json:"advanced"`
	}
	if json.Unmarshal(raw, &cfg) != nil {
		return def
	}
	if v, ok := cfg.Advanced[key]; ok {
		var b bool
		if json.Unmarshal(v, &b) == nil {
			return b
		}
	}
	return def
}

// advancedStrings reads config.advanced.<key> as a string slice (scalar or list).
func advancedStrings(raw []byte, key string) []string {
	var cfg struct {
		Advanced map[string]json.RawMessage `json:"advanced"`
	}
	if json.Unmarshal(raw, &cfg) != nil {
		return nil
	}
	v, ok := cfg.Advanced[key]
	if !ok {
		return nil
	}
	var list []string
	if json.Unmarshal(v, &list) == nil {
		return list
	}
	var single string
	if json.Unmarshal(v, &single) == nil && single != "" {
		return []string{single}
	}
	return nil
}

// generalStrings reads config.general.<key> as a string slice.
func generalStrings(raw []byte, key string) []string {
	var cfg struct {
		General map[string]json.RawMessage `json:"general"`
	}
	if json.Unmarshal(raw, &cfg) != nil {
		return nil
	}
	v, ok := cfg.General[key]
	if !ok {
		return nil
	}
	var list []string
	if json.Unmarshal(v, &list) == nil {
		return list
	}
	return nil
}
