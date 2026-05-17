<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Settings\AppSettings;
use App\AI\GeminiClient;
use App\AI\LinkFiltersPrompt;
use PDO;

/**
 * NL → Link Explorer filters.
 *
 * Mirrors AIUrlFiltersController but with the extra `target` axis required
 * by link rows: each chip applies to the source page, the target page, or
 * the link itself. The normalization step enforces this — chips without a
 * valid target are dropped.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class AILinkFiltersController extends Controller
{
    private PDO $db;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    public function suggest(Request $request): void
    {
        try {
            $this->doSuggest($request);
        } catch (\Throwable $e) {
            error_log('[AILinkFilters] suggest exception: ' . $e->getMessage()
                . "\n" . $e->getTraceAsString());
            $this->error('AI request failed: ' . $e->getMessage(), 500);
        }
    }

    private function doSuggest(Request $request): void
    {
        $crawlId  = (int)$request->get('crawl_id', 0);
        $question = trim((string)$request->get('question', ''));

        if ($crawlId <= 0)              { $this->error('crawl_id is required', 400); return; }
        if ($question === '')           { $this->error('question is required', 400); return; }
        if (mb_strlen($question) > 1000) { $this->error('question too long (max 1000 chars)', 400); return; }

        $crawl = CrawlDatabase::getCrawlById($crawlId);
        if (!$crawl) { $this->error('Crawl not found', 404); return; }

        $crawlPath = (string)($crawl->path ?? '');
        if ($crawlPath !== '') {
            $this->auth->requireCrawlAccess($crawlPath, true);
        } else {
            $this->auth->requireCrawlAccessById($crawlId, true);
        }
        $projectId = (int)$crawl->project_id;

        $apiKey = (string)AppSettings::get('ai.gemini.api_key');
        $model  = (string)AppSettings::get('ai.gemini.model');
        if ($apiKey === '' || $model === '') {
            $this->error('AI provider is not configured. Ask an admin to set it up in Settings.', 400);
            return;
        }

        $categories  = $this->fetchCategories($projectId);
        $schemaTypes = $this->fetchSchemaTypes($crawlId);
        $extractors  = $this->fetchExtractors($crawlId);

        // Attempt 1
        $prompt   = LinkFiltersPrompt::build($question, $categories, $schemaTypes, $extractors);
        $response = GeminiClient::generateContent($apiKey, $model, $prompt);
        $totalIn  = (int)($response['input_tokens'] ?? 0);
        $totalOut = (int)($response['output_tokens'] ?? 0);

        $groups = null;
        $error = null;
        if (!$response['ok']) {
            $error = $response['error'];
        } else {
            $groups = LinkFiltersPrompt::extractGroups($response['text']);
            if ($groups === null) {
                $error = 'No <filters>...</filters> JSON found in the model response';
            }
        }

        // Retry once
        if ($groups === null && $error !== null) {
            $retryPrompt = LinkFiltersPrompt::build($question, $categories, $schemaTypes, $extractors, $error);
            $retry = GeminiClient::generateContent($apiKey, $model, $retryPrompt);
            $totalIn  += (int)($retry['input_tokens']  ?? 0);
            $totalOut += (int)($retry['output_tokens'] ?? 0);

            if (!$retry['ok']) {
                $error = $retry['error'];
            } else {
                $groups = LinkFiltersPrompt::extractGroups($retry['text']);
                if ($groups === null) {
                    $error = 'No <filters>...</filters> JSON found in the model response (retry)';
                }
            }
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
    // DB context
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

    /** Same idea as in AIUrlFiltersController — defensive type detection. */
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
            error_log('[AILinkFilters] fetchExtractors failed: ' . $e->getMessage());
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Normalization
    // -------------------------------------------------------------------------

    /**
     * Validate groups of chips. Each chip must declare a valid `target`
     * (source/target/link); page-scope fields are only valid for source/target,
     * link-scope fields only for link. Drop anything that doesn't fit.
     *
     * @return array<int, array<int, array>>
     */
    private function normalizeGroups(array $groups, array $categories): array
    {
        $catByName = [];
        foreach ($categories as $c) {
            $name = is_object($c) ? (string)$c->cat : (string)($c['cat'] ?? '');
            $id   = is_object($c) ? (int)$c->id    : (int)($c['id']    ?? 0);
            if ($name !== '' && $id > 0) {
                $catByName[mb_strtolower($name)] = $id;
            }
        }

        // Page-scope (need target = source|target)
        $pageFields = [
            'url', 'content_type', 'redirect_to', 'canonical_value', 'domain',
            'depth', 'inlinks', 'outlinks', 'response_time', 'word_count', 'pri',
            'compliant', 'canonical', 'noindex', 'nofollow', 'blocked',
            'h1_multiple', 'headings_missing', 'crawled',
            'in_sitemap', 'is_html', 'out_of_scope',
            'code', 'title', 'h1', 'metadesc', 'category', 'schemas',
        ];
        // Link-scope (target = link)
        $linkFields = [
            'anchor', 'external', 'link_nofollow', 'type', 'self_link',
            'position', 'xpath',
        ];
        $boolFields = [
            'compliant', 'canonical', 'noindex', 'nofollow', 'blocked',
            'h1_multiple', 'headings_missing', 'crawled',
            'in_sitemap', 'is_html', 'out_of_scope',
        ];

        $outGroups = [];
        foreach ($groups as $group) {
            if (!is_array($group)) continue;
            $chips = isset($group['chips']) && is_array($group['chips']) ? $group['chips'] : $group;

            $normalizedChips = [];
            foreach ($chips as $f) {
                if (!is_array($f) || empty($f['field']) || !is_string($f['field'])) continue;
                $field  = $f['field'];
                $target = isset($f['target']) && is_string($f['target']) ? $f['target'] : null;

                $isExtractor = strpos($field, 'extract_') === 0;
                $isLinkField = in_array($field, $linkFields, true);
                $isPageField = $isExtractor || in_array($field, $pageFields, true);

                if (!$isLinkField && !$isPageField) continue;

                // Enforce target / scope coherence
                if ($isLinkField) {
                    $target = 'link';
                } else {
                    if (!in_array($target, ['source', 'target'], true)) {
                        // Default to target page (destination) if missing/invalid —
                        // mirrors what the prompt asks the model to do.
                        $target = 'target';
                    }
                }

                $operator = isset($f['operator']) && is_string($f['operator']) ? $f['operator'] : null;
                $value    = $f['value'] ?? null;

                // Category → IDs
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

                // self_link is an "instant" filter — force operator/value the UI expects
                if ($field === 'self_link') {
                    $operator = '=';
                    $value    = true;
                }

                $chip = ['field' => $field];
                if ($operator !== null) $chip['operator'] = $operator;
                $chip['value']  = $value;
                $chip['target'] = $target;
                $normalizedChips[] = $chip;
            }

            if (!empty($normalizedChips)) {
                $outGroups[] = $normalizedChips;
            }
        }
        return $outGroups;
    }
}
