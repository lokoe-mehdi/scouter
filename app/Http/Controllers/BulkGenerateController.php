<?php

namespace App\Http\Controllers;

use App\Http\Controller;
use App\Http\Request;
use App\Database\PostgresDatabase;
use App\Database\CrawlDatabase;
use App\Settings\AppSettings;
use App\AI\OpenRouterClient;
use App\AI\ContextBuilder;
use App\AI\PromptEstimator;
use App\AI\BulkGenerator;
use App\AI\BudgetService;
use App\AI\Prompts\BulkGeneratePrompt;
use PDO;

/**
 * HTTP endpoints for the Bulk AI Generator.
 *
 * Flow :
 *   1. UI calls /context-fields (GET) once when the wizard opens, to list
 *      the fields the user can check (url, title, …, extract.xxx).
 *   2. UI calls /estimate (POST) every time the user toggles a checkbox
 *      or edits the prompt → returns the estimated tokens + cost.
 *   3. UI calls /preview (POST) once at step 2 → runs 3 URLs SYNCHRONOUSLY
 *      so the user can sanity-check the output before paying for hundreds.
 *   4. UI calls /start (POST) at step 3 → inserts a bulk_generation_jobs
 *      row + enqueues a JobManager job, returns IDs immediately (no work
 *      done here, ~50ms).
 *   5. UI polls /status (GET) every 2s while the job runs.
 *   6. UI calls /stop (POST) if the user clicks the Stop button.
 *
 * @package    Scouter
 * @subpackage Http\Controllers
 */
class BulkGenerateController extends Controller
{
    /** Hard limits — defensive defaults. Adjust here if you ever raise the cap. */
    private const MAX_ITEMS_PER_JOB = 10;
    private const MAX_PROMPT_LENGTH = 8000;

    private PDO $db;

    public function __construct($auth)
    {
        parent::__construct($auth);
        $this->db = PostgresDatabase::getInstance()->getConnection();
    }

    // -------------------------------------------------------------------------
    // GET /api/bulk-generate/context-fields?crawl_id=X
    // -------------------------------------------------------------------------

    /**
     * List the context fields available for this crawl, so the wizard's
     * checkbox panel can render the right options + custom extract.xxx
     * entries.
     */
    public function contextFields(Request $request): void
    {
        $crawlId = (int)$request->get('crawl_id', 0);
        if ($crawlId <= 0) { $this->error('crawl_id required', 400); return; }

        $crawl = CrawlDatabase::getCrawlById($crawlId);
        if (!$crawl) { $this->error('Crawl not found', 404); return; }
        $this->authorize($crawl);

        // Full inventory of pages columns the user can send to the model.
        // Labels REUSE the i18n keys already used by URL Explorer's table
        // columns + filter selector — same wording everywhere, no surprise
        // for the user. Grouped via the `group` field so the wizard renders
        // tidy sections instead of one giant flat list. `always:true` only
        // on `url` — everything else is opt-in.
        // Groups consolidated to keep the accordion short :
        //   - "Contenu"        : SEO text fields (title/h1/meta/schemas/word_count) + raw visible body
        //   - "KPI technique"  : everything indexability + HTTP + crawl structure
        // The order here drives the accordion order on the front-end.
        $fields = [
            // -------- Identification --------
            ['key' => 'url',              'label' => 'URL',                                  'group' => 'Identification', 'always' => true,  'avg_tokens' => 12],
            ['key' => 'domain',           'label' => __('url_explorer.field_domain'),        'group' => 'Identification', 'always' => false, 'avg_tokens' => 8],
            ['key' => 'category',         'label' => __('columns.category'),                 'group' => 'Identification', 'always' => false, 'avg_tokens' => 5],

            // -------- Contenu (SEO text + raw body) --------
            ['key' => 'title',            'label' => 'Title',                                'group' => 'Contenu',        'always' => false, 'avg_tokens' => 15],
            ['key' => 'title_status',     'label' => 'Title Status',                         'group' => 'Contenu',        'always' => false, 'avg_tokens' => 3],
            ['key' => 'h1',               'label' => 'H1',                                   'group' => 'Contenu',        'always' => false, 'avg_tokens' => 12],
            ['key' => 'h1_status',        'label' => 'H1 Status',                            'group' => 'Contenu',        'always' => false, 'avg_tokens' => 3],
            ['key' => 'h1_multiple',      'label' => __('columns.h1_multiple'),              'group' => 'Contenu',        'always' => false, 'avg_tokens' => 2],
            ['key' => 'headings_missing', 'label' => __('columns.bad_heading_structure'),    'group' => 'Contenu',        'always' => false, 'avg_tokens' => 2],
            ['key' => 'metadesc',         'label' => 'Meta Description',                     'group' => 'Contenu',        'always' => false, 'avg_tokens' => 30],
            ['key' => 'metadesc_status',  'label' => 'Meta Desc Status',                     'group' => 'Contenu',        'always' => false, 'avg_tokens' => 3],
            ['key' => 'word_count',       'label' => __('columns.word_count'),               'group' => 'Contenu',        'always' => false, 'avg_tokens' => 3],
            ['key' => 'schemas',          'label' => __('columns.structured_data'),          'group' => 'Contenu',        'always' => false, 'avg_tokens' => 10],
            ['key' => 'visible_content',  'label' => 'Contenu visible',                      'group' => 'Contenu',        'always' => false, 'avg_tokens' => 1000,
             'warning' => 'Multiplie le coût par ~10×. Décocher si pas indispensable.'],

            // -------- KPI technique (indexability + HTTP + crawl structure) --------
            ['key' => 'compliant',        'label' => __('columns.indexable'),                'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'canonical',        'label' => 'Canonical',                            'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'canonical_value',  'label' => __('columns.canonical_url'),            'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 12],
            ['key' => 'noindex',          'label' => 'Noindex',                              'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'nofollow',         'label' => 'Nofollow',                             'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'blocked',          'label' => __('columns.blocked'),                  'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'external',         'label' => __('url_explorer.field_external'),      'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'in_sitemap',       'label' => __('url_explorer.field_in_sitemap'),    'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'code',             'label' => __('columns.http_code'),                'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 3],
            ['key' => 'response_time',    'label' => 'TTFB (ms)',                            'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 4],
            ['key' => 'content_type',     'label' => __('columns.content_type'),             'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 5],
            ['key' => 'redirect_to',      'label' => __('columns.redirect_to'),              'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 12],
            ['key' => 'is_html',          'label' => __('url_explorer.field_is_html'),       'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'crawled',          'label' => __('url_explorer.field_crawled'),       'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'depth',            'label' => __('columns.depth'),                    'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
            ['key' => 'inlinks',          'label' => __('columns.inlinks'),                  'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 3],
            ['key' => 'outlinks',         'label' => __('columns.outlinks'),                 'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 3],
            ['key' => 'pri',              'label' => __('url_explorer.field_pagerank'),      'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 4],
            ['key' => 'in_crawl',         'label' => __('url_explorer.field_out_of_scope'),  'group' => 'KPI technique',  'always' => false, 'avg_tokens' => 2],
        ];

        // ClickHouse crawls read extracts/generation keys from CH (PG purged;
        // extracts is a Map, generation lives in page_generation).
        $useCh = \App\Database\CrawlStore::usesClickHouse($crawlId);

        // Custom extracts for this crawl — surface them as a dedicated
        // group in the same context grid so the user can mix them with
        // standard page columns.
        try {
            $extractKeys = [];
            if ($useCh) {
                $ch = \App\Database\ClickHouseDatabase::getInstance();
                $db = $ch->getDatabase();
                foreach ($ch->select("SELECT DISTINCT arrayJoin(mapKeys(extracts)) AS k
                    FROM (SELECT extracts FROM {$db}.pages WHERE crawl_id = " . (int)$crawlId . " LIMIT 1 BY id) LIMIT 200") as $r) {
                    $extractKeys[] = $r['k'];
                }
            } else {
                $stmt = $this->db->prepare("
                    SELECT DISTINCT jsonb_object_keys(extracts) AS k
                    FROM pages
                    WHERE crawl_id = :cid
                      AND extracts IS NOT NULL
                      AND jsonb_typeof(extracts) = 'object'
                    LIMIT 200
                ");
                $stmt->execute([':cid' => $crawlId]);
                $extractKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            foreach ($extractKeys as $k) {
                if (!is_string($k) || $k === '') continue;
                $fields[] = [
                    'key'        => 'extract.' . $k,
                    'label'      => $k,
                    'group'      => 'Extractions',
                    'always'     => false,
                    'avg_tokens' => 20,
                ];
            }
        } catch (\Throwable $e) {
            // Not fatal — extracts are optional.
        }

        // AI generations already in this crawl — same logic as
        // AIUrlFiltersController::fetchGenerations (dominant-type detection
        // over a 500-row sample) so users can chain runs : feed the output
        // of a previous job as input context for a new one (e.g. "summary"
        // → "improved_summary").
        try {
            $gens = [];
            if ($useCh) {
                // page_generation Map; reuse the shared CH key/type discovery.
                $gens = \App\Http\Controllers\AIUrlFiltersController::fetchGenerationsCH($crawlId, '[BulkGenerate]');
            } else {
                $stmt = $this->db->prepare("
                    WITH samples AS (
                        SELECT generation FROM pages
                        WHERE crawl_id = :cid AND generation IS NOT NULL
                          AND jsonb_typeof(generation) = 'object'
                        LIMIT 500
                    )
                    SELECT j.key, jsonb_typeof(j.value) AS jtype, COUNT(*) AS n
                    FROM samples s, jsonb_each(s.generation) j
                    GROUP BY j.key, jsonb_typeof(j.value)
                ");
                $stmt->execute([':cid' => $crawlId]);
                $byKey = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $byKey[$row['key']][$row['jtype']] = (int)$row['n'];
                }
                ksort($byKey);
                foreach ($byKey as $key => $typeCounts) {
                    $total = array_sum($typeCounts);
                    arsort($typeCounts);
                    $dom = (string)array_key_first($typeCounts);
                    $pct = $total > 0 ? $typeCounts[$dom] / $total : 0;
                    if      ($dom === 'number'  && $pct >= 0.95) { $t = 'number'; }
                    elseif  ($dom === 'boolean' && $pct >= 0.95) { $t = 'boolean'; }
                    else                                          { $t = 'text'; }
                    $gens[] = ['key' => $key, 'type' => $t];
                }
            }
            foreach ($gens as $g) {
                $avg = $g['type'] === 'number' ? 5 : ($g['type'] === 'boolean' ? 2 : 30);
                $fields[] = [
                    'key'        => 'generation.' . $g['key'],
                    'label'      => $g['key'] . ' (' . $g['type'] . ')',
                    'group'      => 'Générations IA',
                    'always'     => false,
                    'avg_tokens' => $avg,
                ];
            }
        } catch (\Throwable $e) {
            // Not fatal — generation column may not exist on legacy crawls.
        }

        $this->success(['fields' => $fields]);
    }

    // -------------------------------------------------------------------------
    // GET /api/bulk-generate/existing-keys?crawl_id=X
    // -------------------------------------------------------------------------

    /**
     * List the generation_keys already present in pages.generation for this
     * crawl. The wizard uses this to refuse new jobs that would overwrite
     * existing data — safer default than silently re-running and clobbering
     * a column the user spent money on last week.
     */
    public function existingKeys(Request $request): void
    {
        $crawlId = (int)$request->get('crawl_id', 0);
        if ($crawlId <= 0) { $this->error('crawl_id required', 400); return; }

        $crawl = CrawlDatabase::getCrawlById($crawlId);
        if (!$crawl) { $this->error('Crawl not found', 404); return; }
        $this->authorize($crawl);

        $keys = self::generationKeys($crawlId, $this->db);
        sort($keys);
        $this->success(['keys' => $keys]);
    }

    /**
     * Distinct generation keys already stored for a crawl. CH reads them from
     * page_generation (Map); legacy PG from pages.generation (JSONB). Returns []
     * on any error (table/column absent) — callers treat it as "no keys yet".
     *
     * @return array<int,string>
     */
    private static function generationKeys(int $crawlId, \PDO $pgDb): array
    {
        try {
            if (\App\Database\CrawlStore::usesClickHouse($crawlId)) {
                $ch = \App\Database\ClickHouseDatabase::getInstance();
                $db = $ch->getDatabase();
                $out = [];
                foreach ($ch->select("SELECT DISTINCT arrayJoin(mapKeys(generation)) AS k
                    FROM (SELECT generation FROM {$db}.page_generation WHERE crawl_id = " . (int)$crawlId . " LIMIT 1 BY id)") as $r) {
                    if (($r['k'] ?? '') !== '') { $out[] = (string)$r['k']; }
                }
                return $out;
            }
            $stmt = $pgDb->prepare("
                SELECT DISTINCT jsonb_object_keys(generation) AS k
                FROM pages
                WHERE crawl_id = :cid AND generation IS NOT NULL
                  AND jsonb_typeof(generation) = 'object'
            ");
            $stmt->execute([':cid' => $crawlId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/bulk-generate/estimate
    // -------------------------------------------------------------------------

    /**
     * Cost + token + duration estimate based on the wizard's current state.
     * Cheap, called repeatedly as the user toggles fields / changes prompt.
     *
     * Body : { crawl_id, page_ids[], items[], context_fields[],
     *          prompt_template, model_id, manual_batch_size? }
     */
    // GET /api/bulk-generate/models
    // Model catalog for the wizard. OpenRouter's /models endpoint is public
    // (no key needed), so this is available to any authenticated editor — unlike
    // /settings/ai/test which is admin-only.
    public function models(Request $request): void
    {
        $list = OpenRouterClient::listModels();
        if (!$list['ok']) {
            $this->error($list['error'] ?? 'Model list failed', 502);
            return;
        }
        $this->success(['models' => $list['models'], 'models_count' => count($list['models'])]);
    }

    public function estimate(Request $request): void
    {
        $params = $this->validateAndUnpackJobParams($request, /*allowEmptyIds*/ true);
        if (!$params) return; // error already sent

        $modelInfo = $this->resolveModelPricing($params['model']);

        $avgCtxTokens = $this->sampleAvgContextTokens(
            $params['crawl_id'],
            $params['page_ids'],
            $params['context_fields']
        );

        $est = PromptEstimator::estimate([
            'prompt_template'    => $params['prompt_template'],
            'items'              => $params['items'],
            'url_count'          => count($params['page_ids']),
            'avg_context_tokens' => $avgCtxTokens,
            'model'              => $modelInfo,
            'manual_batch_size'  => $params['manual_batch_size'],
        ]);

        $this->success([
            'estimate'           => $est,
            'avg_context_tokens' => $avgCtxTokens,
            'model'              => $modelInfo,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/bulk-generate/preview
    // -------------------------------------------------------------------------

    /**
     * Synchronous trial run on the first 3 URLs of the selection. Lets the
     * user sanity-check the output BEFORE paying for the full batch.
     *
     * Body : same shape as /estimate, with page_ids already truncated to 3.
     */
    public function preview(Request $request): void
    {
        $params = $this->validateAndUnpackJobParams($request, /*allowEmptyIds*/ false);
        if (!$params) return;

        // Budget gate — preview runs 3 URLs synchronously through the LLM, so
        // it has a (small) real cost. Block when already over budget; no
        // pre-flight estimate here (3 URLs is cheap), that's only for start().
        $budget = BudgetService::check((int)$this->userId, $this->auth->getCurrentRole());
        if (!$budget['allowed']) {
            $this->error(BudgetService::blockMessage($budget), 402);
            return;
        }

        $apiKey = (string)AppSettings::get('ai.openrouter.api_key');
        if ($apiKey === '') { $this->error('OpenRouter API key not configured', 400); return; }

        // Trim to 3 URLs maximum for the preview.
        $previewIds = array_slice($params['page_ids'], 0, 3);

        $ctxBuilder = new ContextBuilder($this->db);
        $contextBatch = $ctxBuilder->buildForPages(
            $params['crawl_id'], $previewIds, $params['context_fields']
        );

        if (empty($contextBatch)) {
            $this->error('No page context could be built (check the selection)', 400);
            return;
        }

        $prompt = BulkGeneratePrompt::build($params['items'], $params['prompt_template'], $contextBatch);
        $response = OpenRouterClient::chatCompletion($apiKey, $params['model'], [
            ['role' => 'system', 'content' => $prompt['system']],
            ['role' => 'user',   'content' => $prompt['user']],
        ], [
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.2,
        ]);

        // Bill the preview call against the budget (real cost incurred now).
        BudgetService::record(
            (int)$this->userId, BudgetService::FEATURE_BULK, $params['model'],
            (int)($response['input_tokens'] ?? 0), (int)($response['output_tokens'] ?? 0),
            isset($response['cost_usd']) ? $response['cost_usd'] : null,
            $params['crawl_id'], !empty($response['ok'])
        );

        if (!$response['ok']) {
            $this->error('Preview failed: ' . $response['error'], 502);
            return;
        }

        $expectedIds = array_map(static fn($e) => (string)$e['page_id'], $contextBatch);
        $parsed = BulkGeneratePrompt::parseResponse(
            (string)$response['text'], $params['items'], $expectedIds
        );

        if (!$parsed['ok']) {
            $this->error('Preview parse failed: ' . $parsed['error'], 502);
            return;
        }

        // Pair each result with its URL for display.
        $urlByPid = [];
        foreach ($contextBatch as $c) $urlByPid[$c['page_id']] = $c['url'] ?? '';

        $previewRows = [];
        foreach ($parsed['results'] as $pid => $values) {
            $previewRows[] = [
                'page_id' => $pid,
                'url'     => $urlByPid[$pid] ?? '',
                'values'  => $values,
            ];
        }

        $this->success([
            'preview'       => $previewRows,
            'input_tokens'  => (int)($response['input_tokens']  ?? 0),
            'output_tokens' => (int)($response['output_tokens'] ?? 0),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/bulk-generate/start
    // -------------------------------------------------------------------------

    /**
     * Persist the job, enqueue it for the worker, return immediately.
     * Hot path : ~50ms (just inserts).
     */
    public function start(Request $request): void
    {
        $params = $this->validateAndUnpackJobParams($request, /*allowEmptyIds*/ false);
        if (!$params) return;

        if (count($params['page_ids']) > BulkGenerator::MAX_URLS_PER_JOB) {
            $this->error('Too many URLs (max ' . BulkGenerator::MAX_URLS_PER_JOB . ')', 400);
            return;
        }

        $modelInfo = $this->resolveModelPricing($params['model']);
        $avgCtxTokens = $this->sampleAvgContextTokens(
            $params['crawl_id'], $params['page_ids'], $params['context_fields']
        );
        $est = PromptEstimator::estimate([
            'prompt_template'    => $params['prompt_template'],
            'items'              => $params['items'],
            'url_count'          => count($params['page_ids']),
            'avg_context_tokens' => $avgCtxTokens,
            'model'              => $modelInfo,
            'manual_batch_size'  => $params['manual_batch_size'],
        ]);

        // === Pre-flight budget gate (bulk only) ===
        // A bulk job can be expensive, so unlike the cheap features we refuse
        // to START one whose ESTIMATE would push the user past their remaining
        // monthly budget (rather than letting it run and blocking next time).
        $budget = BudgetService::checkEstimate(
            (int)$this->userId,
            $this->auth->getCurrentRole(),
            (float)($est['estimated_cost'] ?? 0)
        );
        if (!$budget['allowed']) {
            if (($budget['reason'] ?? null) === 'budget' && ($budget['remaining'] ?? 0) > 0) {
                // Has SOME budget left, just not enough for this job — be specific.
                $this->error(
                    str_replace(
                        ['{est}', '{remaining}'],
                        [number_format((float)$est['estimated_cost'], 2), number_format((float)$budget['remaining'], 2)],
                        __('ai_budget.bulk_too_expensive')
                    ),
                    402
                );
            } else {
                $this->error(BudgetService::blockMessage($budget), 402);
            }
            return;
        }

        // Insert the bulk_generation_jobs row.
        $stmt = $this->db->prepare("
            INSERT INTO bulk_generation_jobs
                (user_id, crawl_id, items, prompt_template, context_fields,
                 page_ids, model, batch_size, url_count, status, estimated_cost)
            VALUES
                (:uid, :cid, :items::jsonb, :prompt, :fields, :ids,
                 :model, :batch, :count, 'queued', :ecost)
            RETURNING id
        ");
        $stmt->execute([
            ':uid'    => $this->userId,
            ':cid'    => $params['crawl_id'],
            ':items'  => json_encode($params['items'], JSON_UNESCAPED_UNICODE),
            ':prompt' => $params['prompt_template'],
            ':fields' => $this->pgArray($params['context_fields']),
            ':ids'    => $this->pgArray($params['page_ids']),
            ':model'  => $params['model'],
            ':batch'  => $est['batch_size'],
            ':count'  => count($params['page_ids']),
            ':ecost'  => $est['estimated_cost'],
        ]);
        $bulkJobId = (int)$stmt->fetch(PDO::FETCH_OBJ)->id;

        // Enqueue for the worker via JobManager (so it shows up in /jobs UI too).
        $crawl = CrawlDatabase::getCrawlById($params['crawl_id']);
        $crawlPath = (string)($crawl->path ?? ('crawl-' . $params['crawl_id']));
        $jobManager = new \App\Job\JobManager();
        $jmId = $jobManager->createJob(
            $crawlPath,
            'AI Bulk Generation',
            'bulk-ai-generate:' . $bulkJobId
        );
        $jobManager->updateJobStatus($jmId, 'queued');
        $jobManager->addLog($jmId,
            'Queued ' . count($params['page_ids']) . ' URL(s) × '
            . count($params['items']) . ' item(s) using ' . $params['model'],
            'info'
        );

        $this->success([
            'bulk_job_id'    => $bulkJobId,
            'jm_job_id'      => $jmId,
            'estimate'       => $est,
            'message'        => 'Job queued — the worker will start it shortly.',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/bulk-generate/status?bulk_job_id=X
    // -------------------------------------------------------------------------

    public function status(Request $request): void
    {
        $bulkJobId = (int)$request->get('bulk_job_id', 0);
        if ($bulkJobId <= 0) { $this->error('bulk_job_id required', 400); return; }

        $stmt = $this->db->prepare("SELECT * FROM bulk_generation_jobs WHERE id = :id");
        $stmt->execute([':id' => $bulkJobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) { $this->error('Job not found', 404); return; }

        // Auth : the user must have access to the crawl.
        $crawl = CrawlDatabase::getCrawlById((int)$job['crawl_id']);
        if (!$crawl) { $this->error('Crawl gone', 404); return; }
        $this->authorize($crawl);

        // Live cost calc from current model pricing.
        $modelInfo = $this->resolveModelPricing((string)$job['model']);
        $actualCost = (int)$job['input_tokens']  * (float)($modelInfo['prompt_price']     ?? 0)
                    + (int)$job['output_tokens'] * (float)($modelInfo['completion_price'] ?? 0);

        // Fetch the 10 most-recently-touched URLs of the job (for the live
        // results panel). We can't reliably track "last touched" from DB,
        // so we just return the first N page_ids that have at least one
        // of the job's items present.
        $items = is_string($job['items']) ? json_decode($job['items'], true) : ($job['items'] ?? []);
        $firstKey = !empty($items) ? (string)($items[0]['name'] ?? '') : '';
        $lastResults = [];
        if ($firstKey !== '') {
            $pageIds = $this->parsePgArray((string)$job['page_ids']);
            if (!empty($pageIds)) {
                $placeholders = [];
                $params = [':cid' => (int)$job['crawl_id']];
                foreach (array_slice($pageIds, 0, 200) as $i => $pid) {
                    $k = ':pp' . $i;
                    $placeholders[] = $k;
                    $params[$k] = $pid;
                }
                // CH: generation is a Map in page_generation (exposed by ChPdo);
                // key-exists is mapContains(). Legacy PG: JSONB `?` operator.
                $useCh = \App\Database\CrawlStore::usesClickHouse((int)$job['crawl_id']);
                $statusDb = $useCh ? new \App\Database\ChPdo((int)$job['crawl_id']) : $this->db;
                $keyExists = $useCh
                    ? "mapContains(generation, '" . $this->safeSqlIdent($firstKey) . "')"
                    : "generation ? '" . $this->safeSqlIdent($firstKey) . "'";
                $sql = "SELECT id, url, generation FROM pages
                        WHERE crawl_id = :cid AND id IN (" . implode(',', $placeholders) . ")
                          AND {$keyExists}
                        ORDER BY id DESC LIMIT 10";
                try {
                    $stmt = $statusDb->prepare($sql);
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        // CH returns the Map already decoded to an array.
                        $gen = is_array($r['generation'])
                            ? $r['generation']
                            : (json_decode($r['generation'] ?? '{}', true) ?: []);
                        $values = [];
                        foreach ($items as $it) {
                            $name = (string)($it['name'] ?? '');
                            if ($name !== '' && array_key_exists($name, $gen)) {
                                $values[$name] = $gen[$name];
                            }
                        }
                        $lastResults[] = [
                            'page_id' => $r['id'],
                            'url'     => $r['url'],
                            'values'  => $values,
                        ];
                    }
                } catch (\Throwable $e) {
                    error_log('[BulkGenerate] status fetch results failed: ' . $e->getMessage());
                }
            }
        }

        $this->success([
            'id'              => (int)$job['id'],
            'crawl_id'        => (int)$job['crawl_id'],
            'status'          => (string)$job['status'],
            'items'           => $items,
            'url_count'       => (int)$job['url_count'],
            'processed_count' => (int)$job['processed_count'],
            'failed_count'    => (int)$job['failed_count'],
            'input_tokens'    => (int)$job['input_tokens'],
            'output_tokens'   => (int)$job['output_tokens'],
            'estimated_cost'  => $job['estimated_cost'] === null ? null : (float)$job['estimated_cost'],
            'actual_cost'     => round($actualCost, 6),
            'error_message'   => $job['error_message'],
            'errors_sample'   => is_string($job['errors_sample']) ? (json_decode($job['errors_sample'], true) ?: []) : ($job['errors_sample'] ?? []),
            'created_at'      => $job['created_at'],
            'started_at'      => $job['started_at'],
            'finished_at'     => $job['finished_at'],
            'last_results'    => $lastResults,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/bulk-generate/stop
    // -------------------------------------------------------------------------

    public function stop(Request $request): void
    {
        $bulkJobId = (int)$request->get('bulk_job_id', 0);
        if ($bulkJobId <= 0) { $this->error('bulk_job_id required', 400); return; }

        $stmt = $this->db->prepare("SELECT crawl_id, status FROM bulk_generation_jobs WHERE id = :id");
        $stmt->execute([':id' => $bulkJobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) { $this->error('Job not found', 404); return; }

        $crawl = CrawlDatabase::getCrawlById((int)$job['crawl_id']);
        if (!$crawl) { $this->error('Crawl gone', 404); return; }
        $this->authorize($crawl);

        // Mark stopped — the worker checks this between batches and exits.
        // We don't kill the OS process : graceful stop is enough and avoids
        // leaving the DB in a weird state mid-batch.
        $this->db->prepare("
            UPDATE bulk_generation_jobs
            SET status = 'stopped', finished_at = CURRENT_TIMESTAMP
            WHERE id = :id AND status IN ('queued', 'running')
        ")->execute([':id' => $bulkJobId]);

        $this->success(['stopped' => true]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the wizard payload and return the cleaned params, or null
     * after sending an error response.
     *
     * @return array|null
     */
    private function validateAndUnpackJobParams(Request $request, bool $allowEmptyIds)
    {
        $crawlId = (int)$request->get('crawl_id', 0);
        if ($crawlId <= 0) { $this->error('crawl_id required', 400); return null; }

        $crawl = CrawlDatabase::getCrawlById($crawlId);
        if (!$crawl) { $this->error('Crawl not found', 404); return null; }
        $this->authorize($crawl);

        $pageIds = $request->get('page_ids', []);
        if (!is_array($pageIds)) $pageIds = [];
        $pageIds = array_values(array_filter(array_map('strval', $pageIds), 'strlen'));
        if (!$allowEmptyIds && empty($pageIds)) {
            $this->error('page_ids required', 400);
            return null;
        }

        $items = $request->get('items', []);
        if (!is_array($items) || empty($items)) {
            $this->error('items required (at least 1)', 400);
            return null;
        }
        if (count($items) > self::MAX_ITEMS_PER_JOB) {
            $this->error('Too many items (max ' . self::MAX_ITEMS_PER_JOB . ')', 400);
            return null;
        }
        $cleanedItems = [];
        $seenNames = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name = isset($it['name']) ? trim((string)$it['name']) : '';
            $type = isset($it['type']) ? strtolower(trim((string)$it['type'])) : 'text';
            $note = isset($it['note']) ? (string)$it['note'] : '';
            if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $name)) {
                $this->error('Invalid item name: "' . $name . '" (must match [a-z][a-z0-9_]{0,49})', 400);
                return null;
            }
            if (isset($seenNames[$name])) {
                $this->error('Duplicate item name: ' . $name, 400);
                return null;
            }
            $seenNames[$name] = true;
            if (!in_array($type, ['text', 'number', 'boolean'], true)) $type = 'text';
            $cleanedItems[] = ['name' => $name, 'type' => $type, 'note' => $note];
        }

        // Note : we no longer REFUSE colliding keys here. The JSONB merge
        // (`generation || '{key: value}'`) naturally overwrites the key
        // ONLY for the URLs in this job's selection — pages outside the
        // selection keep their existing value untouched. The wizard
        // shows a soft warning so the admin sees what's about to happen.

        $template = trim((string)$request->get('prompt_template', ''));
        if ($template === '') { $this->error('prompt_template required', 400); return null; }
        if (mb_strlen($template) > self::MAX_PROMPT_LENGTH) {
            $this->error('prompt_template too long (max ' . self::MAX_PROMPT_LENGTH . ' chars)', 400);
            return null;
        }

        $fields = $request->get('context_fields', ['url']);
        if (!is_array($fields)) $fields = ['url'];
        // Keep only known fields + extract.* entries.
        $cleanedFields = [];
        foreach ($fields as $f) {
            if (!is_string($f)) continue;
            if (in_array($f, ContextBuilder::ALLOWED_FIELDS, true) || strpos($f, 'extract.') === 0) {
                $cleanedFields[] = $f;
            }
        }
        if (!in_array('url', $cleanedFields, true)) array_unshift($cleanedFields, 'url');

        $model = trim((string)$request->get('model', ''));
        if ($model === '') {
            // Default to the light model from settings.
            $model = (string)AppSettings::get('ai.openrouter.model_light');
            if ($model === '') {
                $this->error('No model specified and no default model_light in settings', 400);
                return null;
            }
        }

        $manualBatch = $request->get('manual_batch_size');
        $manualBatch = ($manualBatch !== null && (int)$manualBatch > 0) ? (int)$manualBatch : null;

        return [
            'crawl_id'           => $crawlId,
            'page_ids'           => $pageIds,
            'items'              => $cleanedItems,
            'prompt_template'    => $template,
            'context_fields'     => $cleanedFields,
            'model'              => $model,
            'manual_batch_size'  => $manualBatch,
        ];
    }

    private function authorize(object $crawl): void
    {
        // Bulk generation is restricted to the user's OWN crawls — NOT crawls
        // merely shared with them. Admins may operate on any crawl.
        if ($this->auth->isAdmin()) {
            return;
        }
        $projectId = (int)($crawl->project_id ?? 0);
        $stmt = $this->db->prepare("SELECT user_id FROM projects WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $projectId]);
        $ownerId = $stmt->fetchColumn();
        if ($ownerId === false || (int)$ownerId !== (int)$this->auth->getCurrentUserId()) {
            // Response::error() exits the request.
            $this->error('Bulk generation is only allowed on your own crawls.', 403);
        }
    }

    /** Fetch the list of generation_keys already present in this crawl. */
    private function fetchExistingKeys(int $crawlId): array
    {
        return self::generationKeys($crawlId, $this->db);
    }

    /** Sample N pages, build their context, return the avg token count. */
    private function sampleAvgContextTokens(int $crawlId, array $pageIds, array $fields): int
    {
        if (empty($pageIds)) return 100; // safe default for empty selection
        $sample = array_slice($pageIds, 0, 5);
        try {
            $batch = (new ContextBuilder($this->db))->buildForPages($crawlId, $sample, $fields);
        } catch (\Throwable $e) {
            return 100;
        }
        if (empty($batch)) return 100;
        $total = 0;
        foreach ($batch as $entry) {
            $total += ContextBuilder::estimateContextTokens($entry);
        }
        return (int)ceil($total / count($batch));
    }

    /**
     * Resolve a model id to its pricing (per-token USD) using the cached
     * /models response from OpenRouterClient.
     *
     * @return array{id:string,name:string,prompt_price:float,completion_price:float,supports_tools:bool,context_length:int}
     */
    private function resolveModelPricing(string $modelId): array
    {
        $list = OpenRouterClient::listModels();
        if (!$list['ok']) {
            // Defaults : zeros → estimates show $0, but the system still works.
            return [
                'id' => $modelId, 'name' => $modelId,
                'prompt_price' => 0.0, 'completion_price' => 0.0,
                'supports_tools' => false, 'context_length' => 0,
            ];
        }
        foreach ($list['models'] as $m) {
            if ($m['id'] === $modelId) return $m;
        }
        return [
            'id' => $modelId, 'name' => $modelId,
            'prompt_price' => 0.0, 'completion_price' => 0.0,
            'supports_tools' => false, 'context_length' => 0,
        ];
    }

    /** Build a Postgres TEXT[] literal from a PHP array of strings. */
    private function pgArray(array $items): string
    {
        $escaped = array_map(function ($s) {
            $s = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$s);
            return '"' . $s . '"';
        }, $items);
        return '{' . implode(',', $escaped) . '}';
    }

    /** Parse a Postgres TEXT[] literal "{a,b,c}" → ['a','b','c'] */
    private function parsePgArray(string $raw): array
    {
        $raw = trim($raw, '{}');
        if ($raw === '') return [];
        $parts = preg_split('/,/', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p, " \t\n\r\"");
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    /** Whitelist JSON key for use in inline SQL (avoid injection). */
    private function safeSqlIdent(string $s): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $s);
    }
}
