<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Settings\AppSettings;
use App\AI\OpenRouterClient;
use App\AI\UrlFiltersPrompt;
use App\AI\BudgetService;
use PDO;

/**
 * NL → URL Explorer filters.
 *
 * Flow:
 *   1. Receive { project, question }.
 *   2. Resolve the crawl + project so we can give the AI the right context
 *      (categories of this project, schema types found in this crawl,
 *      custom extractors).
 *   3. Ask the model via OpenRouter, extract the JSON filter list from the <filters> tag.
 *   4. Normalize: map category NAMES → IDs (the JS state expects IDs).
 *   5. Return { filters: [...] } — the frontend pushes them into filterGroups,
 *      adds missing columns, and reloads.
 *
 * The generated filters are NOT executed here. They go through the regular
 * URL Explorer rendering pipeline, which already enforces project scoping.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class AIUrlFiltersController extends Controller
{
    private PDO $db;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * POST /api/url-explorer/ai-filters
     * Body: { crawl_id: int, question: string }
     */
    public function suggest(Request $request): void
    {
        try {
            $this->doSuggest($request);
        } catch (\Throwable $e) {
            // Catch everything so the user gets the actual cause instead of
            // the router's generic "An internal error occurred."
            error_log('[AIUrlFilters] suggest exception: ' . $e->getMessage()
                . "\n" . $e->getTraceAsString());
            $this->error('AI request failed: ' . $e->getMessage(), 500);
        }
    }

    private function doSuggest(Request $request): void
    {
        $crawlId  = (int)$request->get('crawl_id', 0);
        $question = trim((string)$request->get('question', ''));

        if ($crawlId <= 0) {
            $this->error('crawl_id is required', 400);
            return;
        }
        if ($question === '') {
            $this->error('question is required', 400);
            return;
        }
        if (mb_strlen($question) > 1000) {
            $this->error('question too long (max 1000 chars)', 400);
            return;
        }

        // Resolve crawl first so we can convert id → path for the access check.
        $crawl = CrawlDatabase::getCrawlById($crawlId);
        if (!$crawl) {
            $this->error('Crawl not found', 404);
            return;
        }
        $crawlPath = (string)($crawl->path ?? '');
        if ($crawlPath !== '') {
            $this->auth->requireCrawlAccess($crawlPath, true);
        } else {
            // No path on this crawl — fall back to id-based access check.
            $this->auth->requireCrawlAccessById($crawlId, true);
        }
        $projectId = (int)$crawl->project_id;

        // Per-user monthly AI budget gate (role + remaining budget).
        $budget = BudgetService::check((int)$this->userId, $this->auth->getCurrentRole());
        if (!$budget['allowed']) {
            $this->error(BudgetService::blockMessage($budget), 402);
            return;
        }

        $apiKey = (string)AppSettings::get('ai.openrouter.api_key');
        $model  = (string)AppSettings::get('ai.openrouter.model_light');
        if ($apiKey === '' || $model === '') {
            $this->error('AI provider is not configured. Ask an admin to set it up in Settings.', 400);
            return;
        }

        // Context for the prompt — categories, schemas, extractors live on
        // different tables so we fetch them here.
        $categories  = $this->fetchCategories($projectId);
        $schemaTypes = $this->fetchSchemaTypes($crawlId);
        $extractors  = $this->fetchExtractors($crawlId);
        $generations = $this->fetchGenerations($crawlId);

        // Attempt 1
        $prompt   = UrlFiltersPrompt::build($question, $categories, $schemaTypes, $extractors, null, $generations);
        $response = OpenRouterClient::chatCompletion($apiKey, $model, [['role' => 'user', 'content' => $prompt]]);
        $totalIn  = (int)($response['input_tokens'] ?? 0);
        $totalOut = (int)($response['output_tokens'] ?? 0);
        $totalCost = isset($response['cost_usd']) && $response['cost_usd'] !== null ? (float)$response['cost_usd'] : null;

        $groups = null;
        $error = null;
        if (!$response['ok']) {
            $error = $response['error'];
        } else {
            $groups = UrlFiltersPrompt::extractGroups($response['text']);
            if ($groups === null) {
                $error = 'No <filters>...</filters> JSON found in the model response';
            }
        }

        if ($groups === null && $error !== null) {
            $retryPrompt = UrlFiltersPrompt::build($question, $categories, $schemaTypes, $extractors, $error, $generations);
            $retry = OpenRouterClient::chatCompletion($apiKey, $model, [['role' => 'user', 'content' => $retryPrompt]]);
            $totalIn  += (int)($retry['input_tokens']  ?? 0);
            $totalOut += (int)($retry['output_tokens'] ?? 0);
            if (isset($retry['cost_usd']) && $retry['cost_usd'] !== null) {
                $totalCost = (float)$totalCost + (float)$retry['cost_usd'];
            }

            if (!$retry['ok']) {
                $error = $retry['error'];
            } else {
                $groups = UrlFiltersPrompt::extractGroups($retry['text']);
                if ($groups === null) {
                    $error = 'No <filters>...</filters> JSON found in the model response (retry)';
                }
            }
        }

        // Bill the call(s) — cost is incurred whether or not the JSON parsed.
        if ($totalIn > 0 || $totalOut > 0 || $totalCost !== null) {
            BudgetService::record(
                (int)$this->userId, BudgetService::FEATURE_FILTERS, $model,
                $totalIn, $totalOut, $totalCost, $crawlId, $groups !== null
            );
        }

        if ($groups === null) {
            $this->error('AI response could not be parsed: ' . ($error ?? 'unknown error'), 502);
            return;
        }

        $normalizedGroups = $this->normalizeGroups($groups, $categories);
        if (empty($normalizedGroups)) {
            $this->error('No usable filter could be derived from your question.', 400);
            return;
        }

        $this->success([
            'groups'        => $normalizedGroups,
            'model'         => $model,
            'input_tokens'  => $totalIn,
            'output_tokens' => $totalOut,
        ]);
    }

    // -------------------------------------------------------------------------

    /** @return array<int, object{id:int,cat:string}> */
    private function fetchCategories(int $projectId): array
    {
        $stmt = $this->db->prepare("SELECT id, cat FROM crawl_categories WHERE project_id = :pid ORDER BY cat");
        $stmt->execute([':pid' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ) ?: [];
    }

    /** @return string[] */
    private function fetchSchemaTypes(int $crawlId): array
    {
        $table = 'page_schemas_' . $crawlId;
        $exists = (bool)$this->db->query(
            "SELECT 1 FROM information_schema.tables WHERE table_name = " . $this->db->quote($table)
        )->fetchColumn();
        if (!$exists) return [];
        $stmt = $this->db->query("SELECT DISTINCT schema_type FROM {$table} ORDER BY schema_type");
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Get custom extractors with type detection. Defensive: a row whose
     * `extracts` isn't a JSON object (e.g. a stray array or scalar from
     * malformed config) would crash jsonb_object_keys, so we filter to
     * `jsonb_typeof = 'object'` before extracting keys.
     *
     * @return array<int, array{key:string,type:string}>
     */
    private function fetchExtractors(int $crawlId): array
    {
        try {
            $table = 'pages_' . $crawlId;
            $exists = (bool)$this->db->query(
                "SELECT 1 FROM information_schema.tables WHERE table_name = " . $this->db->quote($table)
            )->fetchColumn();
            if (!$exists) return [];

            $stmt = $this->db->query("
                SELECT DISTINCT jsonb_object_keys(extracts) AS k
                FROM (
                    SELECT extracts FROM {$table}
                    WHERE extracts IS NOT NULL
                      AND jsonb_typeof(extracts) = 'object'
                    LIMIT 200
                ) t
            ");
            $keys = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $out = [];
            foreach ($keys as $key) {
                if (!is_string($key) || $key === '') continue;
                $sample = $this->db->prepare("
                    SELECT extracts->>:k AS v
                    FROM {$table}
                    WHERE jsonb_typeof(extracts) = 'object'
                      AND extracts ? :k2
                      AND extracts->>:k3 IS NOT NULL
                    LIMIT 20
                ");
                $sample->execute([':k' => $key, ':k2' => $key, ':k3' => $key]);
                $vals = $sample->fetchAll(PDO::FETCH_COLUMN);
                $allNumeric = !empty($vals);
                foreach ($vals as $v) {
                    if (!is_numeric($v)) { $allNumeric = false; break; }
                }
                $out[] = ['key' => $key, 'type' => $allNumeric ? 'number' : 'text'];
            }
            return $out;
        } catch (\Throwable $e) {
            // Extractors are optional context — failure here must not kill
            // the whole AI request. Log and return empty.
            error_log('[AIUrlFilters] fetchExtractors failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Discover the AI-generated columns (pages.generation JSONB) and their
     * dominant type — feeds the "demande à l'IA" filter suggester so it
     * knows about generation_xxx fields the user has created.
     *
     * @return array<int, array{key:string,type:string}>
     */
    private function fetchGenerations(int $crawlId): array
    {
        try {
            $stmt = $this->db->prepare("
                WITH samples AS (
                    SELECT generation FROM pages
                    WHERE crawl_id = :crawl_id AND generation IS NOT NULL
                      AND jsonb_typeof(generation) = 'object'
                    LIMIT 500
                )
                SELECT j.key, jsonb_typeof(j.value) AS jtype, COUNT(*) AS n
                FROM samples s, jsonb_each(s.generation) j
                GROUP BY j.key, jsonb_typeof(j.value)
            ");
            $stmt->execute([':crawl_id' => $crawlId]);
            $byKey = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byKey[$row['key']][$row['jtype']] = (int)$row['n'];
            }
            $out = [];
            foreach ($byKey as $key => $typeCounts) {
                $total = array_sum($typeCounts);
                arsort($typeCounts);
                $dom = (string)array_key_first($typeCounts);
                $pct = $total > 0 ? $typeCounts[$dom] / $total : 0;
                if      ($dom === 'number'  && $pct >= 0.95) $t = 'number';
                elseif  ($dom === 'boolean' && $pct >= 0.95) $t = 'boolean';
                else                                          $t = 'text';
                $out[] = ['key' => $key, 'type' => $t];
            }
            usort($out, fn($a, $b) => strcmp($a['key'], $b['key']));
            return $out;
        } catch (\Throwable $e) {
            error_log('[AIUrlFilters] fetchGenerations failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Normalize a list of groups (array of arrays of chips). Each group's chips
     * are OR'd, groups themselves are AND'd, matching url-explorer.php's
     * `filterGroups` state.
     *
     * Per chip we:
     *   - drop unknown fields
     *   - resolve category names → IDs
     *   - coerce booleans to the "true"/"false" string the JS state expects
     * If a group ends up empty after normalization, the group is dropped too.
     *
     * @param array $groups     raw groups from the model
     * @param array $categories project categories ({id, cat})
     * @return array<int, array<int, array>>  groups of normalized chips
     */
    private function normalizeGroups(array $groups, array $categories): array
    {
        // Case-insensitive lookup of category name → id.
        $catByName = [];
        foreach ($categories as $c) {
            $name = is_object($c) ? (string)$c->cat : (string)($c['cat'] ?? '');
            $id   = is_object($c) ? (int)$c->id    : (int)($c['id']    ?? 0);
            if ($name !== '' && $id > 0) {
                $catByName[mb_strtolower($name)] = $id;
            }
        }

        $allowedFields = [
            'url', 'content_type', 'redirect_to', 'canonical_value', 'domain',
            'depth', 'inlinks', 'outlinks', 'response_time', 'word_count', 'pri',
            'compliant', 'canonical', 'noindex', 'nofollow', 'blocked',
            'h1_multiple', 'headings_missing', 'external', 'crawled',
            'in_sitemap', 'is_html', 'out_of_scope',
            'code', 'title', 'h1', 'metadesc', 'category', 'schemas',
        ];
        $boolFields = [
            'compliant', 'canonical', 'noindex', 'nofollow', 'blocked',
            'h1_multiple', 'headings_missing', 'external', 'crawled',
            'in_sitemap', 'is_html', 'out_of_scope',
        ];

        $outGroups = [];
        foreach ($groups as $group) {
            // Accept both [chips] and {chips: [...]} shapes for resilience.
            if (!is_array($group)) continue;
            $chips = isset($group['chips']) && is_array($group['chips']) ? $group['chips'] : $group;

            $normalizedChips = [];
            foreach ($chips as $f) {
                if (!is_array($f) || empty($f['field']) || !is_string($f['field'])) continue;
                $field = $f['field'];

                $isExtractor  = strpos($field, 'extract_')    === 0;
                $isGeneration = strpos($field, 'generation_') === 0;
                if (!$isExtractor && !$isGeneration && !in_array($field, $allowedFields, true)) continue;

                $operator = isset($f['operator']) && is_string($f['operator']) ? $f['operator'] : null;
                $value    = $f['value'] ?? null;

                // Category: names → ids
                if ($field === 'category') {
                    $names = is_array($value) ? $value : [$value];
                    $ids = [];
                    foreach ($names as $n) {
                        if (!is_string($n)) continue;
                        $id = $catByName[mb_strtolower($n)] ?? null;
                        if ($id) $ids[] = $id;
                    }
                    if (empty($ids)) continue;
                    $value = $ids;
                    if (!in_array($operator, ['in', 'not_in'], true)) $operator = 'in';
                }

                // Booleans → "true"/"false" strings
                if (in_array($field, $boolFields, true)) {
                    if (is_bool($value)) $value = $value ? 'true' : 'false';
                    elseif (is_string($value)) $value = strtolower($value) === 'true' ? 'true' : 'false';
                    else continue;
                    $operator = '=';
                }

                $chip = ['field' => $field];
                if ($operator !== null) $chip['operator'] = $operator;
                $chip['value'] = $value;
                $normalizedChips[] = $chip;
            }

            if (!empty($normalizedChips)) {
                $outGroups[] = $normalizedChips;
            }
        }
        return $outGroups;
    }
}
