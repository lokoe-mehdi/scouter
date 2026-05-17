<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Settings\AppSettings;
use App\AI\GeminiClient;
use App\AI\CategorizationPrompt;
use App\Analysis\CategorizationService;
use PDO;

/**
 * Endpoint behind the "Suggest with AI" button on the Categorize page.
 *
 * Flow:
 *   1. Resolve the crawl from the project path (same idiom as CategorizationController).
 *   2. Authorize (requireCrawlManagement).
 *   3. Sample up to 200 INTERNAL crawled URLs from pages_<crawl_id>, stratified by depth.
 *   4. Build the Gemini prompt + call the configured model.
 *   5. Extract YAML from the <categorization> tag. If extraction or compilation
 *      fails, retry ONCE with the error message appended to the prompt.
 *   6. Persist a row in ai_categorization_runs for audit/cost tracking.
 *   7. Return the raw YAML — the user reviews and saves it manually.
 *
 * Nothing is auto-applied to the project: the YAML lands in the editor, the
 * existing Save flow handles persistence.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class AICategorizationController extends Controller
{
    private const SAMPLE_SIZE = 200;
    private PDO $db;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * POST /api/categorization/ai-suggest
     * Body: { project: string }
     */
    public function suggest(Request $request): void
    {
        $projectDir = (string)$request->get('project', '');
        if ($projectDir === '') {
            $this->error('project is required', 400);
            return;
        }

        // Auth — same idiom as save()
        $this->auth->requireCrawlManagement($projectDir, true);

        // Resolve crawl
        $crawl = CrawlDatabase::getCrawlByPath($projectDir);
        if (!$crawl) {
            $this->error('Crawl not found', 404);
            return;
        }
        $crawlId = (int)$crawl->id;
        $domain  = (string)($crawl->domain ?? '');

        // Check AI is configured
        $apiKey = (string)AppSettings::get('ai.gemini.api_key');
        $model  = (string)AppSettings::get('ai.gemini.model');
        if ($apiKey === '' || $model === '') {
            $this->error('AI provider is not configured. Ask an admin to set it up in Settings.', 400);
            return;
        }

        // Sample 200 internal crawled URLs, stratified by depth
        $sample = $this->sampleCrawl($crawlId);
        if (empty($sample)) {
            $this->error('No internal crawled URLs found in this crawl', 400);
            return;
        }
        $sampleCount = count($sample);

        // Attempt 1
        $prompt   = CategorizationPrompt::build($sample, $domain);
        $response = GeminiClient::generateContent($apiKey, $model, $prompt);
        $totalIn  = (int)($response['input_tokens'] ?? 0);
        $totalOut = (int)($response['output_tokens'] ?? 0);

        $yaml = null;
        $error = null;
        if (!$response['ok']) {
            $error = $response['error'];
        } else {
            [$yaml, $error] = $this->parseAndValidate($response['text']);
        }

        // Attempt 2 (retry once) if we got an error we can express back to the model
        if ($yaml === null && $error !== null) {
            $retryPrompt = CategorizationPrompt::build($sample, $domain, $error);
            $retry = GeminiClient::generateContent($apiKey, $model, $retryPrompt);
            $totalIn  += (int)($retry['input_tokens']  ?? 0);
            $totalOut += (int)($retry['output_tokens'] ?? 0);

            if (!$retry['ok']) {
                $error = $retry['error'];
            } else {
                [$yaml, $error] = $this->parseAndValidate($retry['text']);
            }
        }

        // Audit log
        $this->logRun($crawlId, $model, $totalIn, $totalOut, $sampleCount, $yaml !== null, $error);

        if ($yaml === null) {
            $this->error('AI response could not be parsed: ' . ($error ?? 'unknown error'), 502);
            return;
        }

        $this->success([
            'yaml'         => $yaml,
            'model'        => $model,
            'input_tokens' => $totalIn,
            'output_tokens'=> $totalOut,
            'pages_sampled'=> $sampleCount,
        ]);
    }

    // -------------------------------------------------------------------------
    // Sampling
    // -------------------------------------------------------------------------

    /**
     * Random sample of up to SAMPLE_SIZE internal crawled HTML URLs from
     * the crawl's partition, stratified by depth bucket so the proposal
     * isn't biased towards the homepage area.
     *
     * Stratification targets (sum = 200):
     *   depth 0-1 :  40
     *   depth 2   :  60
     *   depth 3   :  60
     *   depth ≥4  :  40
     *
     * If a bucket is short, the missing quota is reallocated to the remaining
     * buckets in priority order (2 → 3 → 0-1 → ≥4).
     *
     * @return array<int, array{url: string, h1: ?string, title: ?string}>
     */
    private function sampleCrawl(int $crawlId): array
    {
        $table = 'pages_' . $crawlId;
        // Defensive: the table must exist as a partition; if not, return empty.
        $exists = (bool)$this->db->query("
            SELECT 1 FROM information_schema.tables WHERE table_name = " . $this->db->quote($table)
        )->fetchColumn();
        if (!$exists) {
            return [];
        }

        $buckets = [
            'd01' => ['quota' => 40, 'where' => 'depth <= 1'],
            'd2'  => ['quota' => 60, 'where' => 'depth = 2'],
            'd3'  => ['quota' => 60, 'where' => 'depth = 3'],
            'd4p' => ['quota' => 40, 'where' => 'depth >= 4'],
        ];

        // Round 1: pull up to quota per bucket
        $picked = [];
        $shortfall = 0;
        $remainingBuckets = [];
        foreach ($buckets as $name => $b) {
            $rows = $this->randomFromBucket($table, $b['where'], $b['quota']);
            $picked = array_merge($picked, $rows);
            $got = count($rows);
            if ($got < $b['quota']) {
                $shortfall += ($b['quota'] - $got);
            } else {
                $remainingBuckets[] = $b['where'];
            }
        }

        // Round 2: if some buckets were short, top up from the non-exhausted ones
        if ($shortfall > 0 && !empty($remainingBuckets)) {
            $alreadyUrls = array_column($picked, 'url');
            $extra = $this->randomFromBucket(
                $table,
                '(' . implode(' OR ', $remainingBuckets) . ')',
                $shortfall,
                $alreadyUrls
            );
            $picked = array_merge($picked, $extra);
        }

        return array_slice($picked, 0, self::SAMPLE_SIZE);
    }

    /**
     * @param string[] $exclude URLs to skip (already picked)
     * @return array<int, array{url: string, h1: ?string, title: ?string}>
     */
    private function randomFromBucket(string $table, string $where, int $limit, array $exclude = []): array
    {
        if ($limit <= 0) {
            return [];
        }
        $excludeSql = '';
        $params = [];
        if (!empty($exclude)) {
            // Build a parameterized NOT IN list (cap to a reasonable size).
            $exclude = array_slice($exclude, 0, 1000);
            $placeholders = [];
            foreach ($exclude as $i => $u) {
                $key = ':ex' . $i;
                $placeholders[] = $key;
                $params[$key] = $u;
            }
            $excludeSql = ' AND url NOT IN (' . implode(',', $placeholders) . ')';
        }

        // Internal URLs in the crawl scope. We include URLs that were merely
        // DISCOVERED (code = 0, not yet fetched: depth limit, abandoned crawl,
        // robots-blocked, etc.) because the categorization will be APPLIED to
        // them too — if we hide them from the AI, it can't propose patterns
        // that cover them, and they end up uncategorized after save.
        //
        // For non-crawled URLs, h1/title are NULL — the AI gets only the URL,
        // which is enough to recognize a path pattern (e.g. /a/<slug> = product).
        $sql = "
            SELECT url, h1, title
            FROM {$table}
            WHERE external = false
              AND in_crawl = TRUE
              AND {$where}
              {$excludeSql}
            ORDER BY random()
            LIMIT " . (int)$limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // -------------------------------------------------------------------------
    // Parse + validate the model output
    // -------------------------------------------------------------------------

    /**
     * @return array{0: ?string, 1: ?string}  [yaml, errorMessage]
     */
    private function parseAndValidate(string $text): array
    {
        $yaml = CategorizationPrompt::extractYaml($text);
        if ($yaml === null) {
            return [null, 'No <categorization>...</categorization> tag found in the response'];
        }

        $parsed = @\Spyc::YAMLLoadString($yaml);
        if (!is_array($parsed) || empty($parsed)) {
            return [null, 'YAML inside the tag is empty or could not be parsed'];
        }

        // Run the same validation the dashboard will run when the user saves:
        // every include/exclude pattern must be a valid PCRE.
        try {
            $service = new CategorizationService($this->db);
            $service->parseRules($parsed);
        } catch (\Throwable $e) {
            return [null, 'Invalid regex in YAML: ' . $e->getMessage()];
        }

        return [$yaml, null];
    }

    // -------------------------------------------------------------------------
    // Audit log
    // -------------------------------------------------------------------------

    private function logRun(int $crawlId, string $model, int $inTok, int $outTok, int $sampled, bool $success, ?string $error): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ai_categorization_runs
                    (user_id, crawl_id, model, input_tokens, output_tokens, pages_sampled, success, error_message)
                VALUES
                    (:uid, :cid, :model, :in, :out, :sampled, :success, :err)
            ");
            $stmt->execute([
                ':uid'    => $this->userId,
                ':cid'    => $crawlId,
                ':model'  => $model,
                ':in'     => $inTok,
                ':out'    => $outTok,
                ':sampled'=> $sampled,
                ':success'=> $success ? 1 : 0,
                ':err'    => $error,
            ]);
        } catch (\Throwable $e) {
            // Logging is best-effort — we don't want a logging failure to mask
            // a successful generation, so swallow and just error_log it.
            error_log('[AICategorization] Failed to write audit row: ' . $e->getMessage());
        }
    }
}
