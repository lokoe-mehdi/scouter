<?php

namespace App\AI;

use App\Database\PostgresDatabase;
use App\AI\Prompts\BulkGeneratePrompt;
use App\AI\BudgetService;
use App\Settings\AppSettings;
use PDO;

/**
 * Orchestrator for the Bulk AI Generator.
 *
 * Given a `bulk_generation_jobs.id`, loops over the configured page_ids in
 * batches, calls OpenRouter once per batch, parses the typed JSON response,
 * writes results into `pages.generation` (JSONB merge) and updates the job's
 * progress + token usage + cost.
 *
 * Designed to be called from the worker (CLI), NOT from a web request :
 * a 1000-URL job can take minutes. The web layer only inserts the
 * `bulk_generation_jobs` row and enqueues the job via JobManager — the
 * worker pulls it from there.
 *
 * Per-URL semantics : the model returns one entry per page with all the
 * requested fields. The worker merges that JSONB into pages.generation
 * via `||` so that previous keys (from earlier jobs) survive intact —
 * only the keys this job produces are added/overwritten.
 *
 * Fault tolerance :
 *   - If the model's JSON for a batch can't be parsed at all → the
 *     entire batch is retried 1-by-1, so a single bad URL doesn't
 *     drop the other 9.
 *   - A URL whose payload mistypes a field (e.g. expected number, got
 *     string) is marked failed and skipped (no retry — likely a structural
 *     issue with that page, retrying won't help).
 *   - Stop requested by the user (`status = 'stopped'` set by the
 *     controller) is checked between batches → clean exit.
 *
 * @package    Scouter
 * @subpackage AI
 */
class BulkGenerator
{
    /** Max URLs we allow in a single job (hard safety cap). */
    public const MAX_URLS_PER_JOB = 5000;

    /** Sample size used to estimate avg context tokens for batch sizing. */
    private const CTX_SAMPLE_SIZE = 5;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? PostgresDatabase::getInstance()->getConnection();
    }

    /**
     * Run the job identified by $bulkJobId until done / failed / stopped.
     *
     * Intended to be called from CLI (Cmder::bulkAiGenerate). Writes
     * progress to bulk_generation_jobs ; also writes results to
     * pages.generation as it goes.
     *
     * @param int $bulkJobId
     * @param callable|null $onProgress  called as $onProgress(processed,total) for live UX
     */
    public function run(int $bulkJobId, ?callable $onProgress = null): void
    {
        $job = $this->loadJob($bulkJobId);
        if (!$job) {
            throw new \RuntimeException("Bulk job {$bulkJobId} not found");
        }

        // Resolve API key with explicit diagnostics — the generic
        // "not configured" message was unactionable. Three distinct
        // failure modes are possible from a worker process :
        //   (a) SCOUTER_ENCRYPTION_KEY env var missing on this worker
        //       (different from the web container's env)
        //   (b) the key was never saved in /settings
        //   (c) the key is in the DB but decrypt returns null (encryption
        //       key changed since the value was stored)
        $hasEncKey = AppSettings::hasEncryptionKey();
        $apiKey    = (string)AppSettings::get('ai.openrouter.api_key');
        if ($apiKey === '') {
            if (!$hasEncKey) {
                $reason = 'SCOUTER_ENCRYPTION_KEY env var is not set in the worker '
                        . 'container — restart the worker after exporting it. '
                        . '(The web container can have it without the worker, '
                        . 'and the worker is the one running this job.)';
            } else {
                // Either no row in app_settings, or decrypt returned null.
                $stmt = $this->db->prepare("SELECT value FROM app_settings WHERE key = 'ai.openrouter.api_key'");
                $stmt->execute();
                $raw = $stmt->fetchColumn();
                if ($raw === false || $raw === null) {
                    $reason = 'OpenRouter API key has never been saved in /settings — '
                            . 'go to Admin → Settings and configure it.';
                } else {
                    $reason = 'OpenRouter API key is in the DB but could not be decrypted. '
                            . 'The SCOUTER_ENCRYPTION_KEY in this worker does NOT match the '
                            . 'one that was active when the key was saved. Re-save the key '
                            . 'from /settings with the current encryption key, or restore '
                            . 'the original encryption key.';
                }
            }
            error_log('[BulkGenerator] ' . $reason);
            $this->markFailed($bulkJobId, $reason);
            return;
        }

        $this->markStarted($bulkJobId);

        $items     = is_array($job['items']) ? $job['items'] : (json_decode($job['items'], true) ?: []);
        $template  = (string)$job['prompt_template'];
        $contextFields = is_array($job['context_fields']) ? $job['context_fields'] : [];
        $pageIds   = is_array($job['page_ids']) ? $job['page_ids'] : [];
        $model     = (string)$job['model'];
        $batchSize = max(1, (int)$job['batch_size']);
        $crawlId   = (int)$job['crawl_id'];
        $userId    = (int)($job['user_id'] ?? 0);

        $processed = 0;
        $failed    = 0;
        $totalIn   = 0;
        $totalOut  = 0;
        $totalCost = null;   // accumulated real OpenRouter cost (USD)
        $errorsSample = [];
        $ctxBuilder = new ContextBuilder($this->db);

        // Process page IDs in chunks of $batchSize.
        $chunks = array_chunk($pageIds, $batchSize);
        foreach ($chunks as $chunkIdx => $chunk) {
            // Per-batch stop check — keeps worker responsive to Stop clicks.
            if ($this->isStopRequested($bulkJobId)) {
                $this->markStopped($bulkJobId, $processed, $failed, $totalIn, $totalOut, $errorsSample);
                $this->recordBudget($userId, $model, $crawlId, $totalIn, $totalOut, $totalCost, true);
                return;
            }

            // 1) Build the context for this chunk.
            try {
                $contextBatch = $ctxBuilder->buildForPages($crawlId, $chunk, $contextFields);
            } catch (\Throwable $e) {
                error_log('[BulkGenerator] context build failed: ' . $e->getMessage());
                foreach ($chunk as $pid) {
                    $failed++;
                    if (count($errorsSample) < 20) $errorsSample[$pid] = 'context build: ' . $e->getMessage();
                }
                $processed += count($chunk);
                $this->updateProgress($bulkJobId, $processed, $failed, $totalIn, $totalOut, $errorsSample);
                continue;
            }

            // 2) Run the batch — first attempt (batched), retry 1-by-1 on parse failure.
            $batchResult = $this->runBatch($apiKey, $model, $items, $template, $contextBatch);
            $totalIn  += $batchResult['input_tokens'];
            $totalOut += $batchResult['output_tokens'];
            if (isset($batchResult['cost_usd']) && $batchResult['cost_usd'] !== null) {
                $totalCost = (float)$totalCost + (float)$batchResult['cost_usd'];
            }

            if (!$batchResult['ok'] && count($contextBatch) > 1) {
                // Fallback : retry each URL alone so 1 bad apple doesn't ruin 9 good ones.
                foreach ($contextBatch as $singleCtx) {
                    $singleResult = $this->runBatch($apiKey, $model, $items, $template, [$singleCtx]);
                    $totalIn  += $singleResult['input_tokens'];
                    $totalOut += $singleResult['output_tokens'];
                    if (isset($singleResult['cost_usd']) && $singleResult['cost_usd'] !== null) {
                        $totalCost = (float)$totalCost + (float)$singleResult['cost_usd'];
                    }
                    if ($singleResult['ok'] && !empty($singleResult['results'])) {
                        $this->writeResults($crawlId, $singleResult['results']);
                    } else {
                        $pid = (string)$singleCtx['page_id'];
                        $failed++;
                        if (count($errorsSample) < 20) {
                            $errorsSample[$pid] = $singleResult['error'] ?? 'retry failed';
                        }
                    }
                }
            } elseif ($batchResult['ok']) {
                $this->writeResults($crawlId, $batchResult['results']);
                // Pages that DIDN'T come back from the batched call (model
                // dropped them, common when the batch is large) → retry each
                // missing URL in its own single-URL call. The model is
                // way more reliable on single inputs.
                $returnedIds = array_keys($batchResult['results']);
                $missingCtx = [];
                foreach ($contextBatch as $singleCtx) {
                    if (!in_array((string)$singleCtx['page_id'], $returnedIds, true)) {
                        $missingCtx[] = $singleCtx;
                    }
                }
                foreach ($missingCtx as $singleCtx) {
                    $singleResult = $this->runBatch($apiKey, $model, $items, $template, [$singleCtx]);
                    $totalIn  += $singleResult['input_tokens'];
                    $totalOut += $singleResult['output_tokens'];
                    if (isset($singleResult['cost_usd']) && $singleResult['cost_usd'] !== null) {
                        $totalCost = (float)$totalCost + (float)$singleResult['cost_usd'];
                    }
                    if ($singleResult['ok'] && !empty($singleResult['results'])) {
                        $this->writeResults($crawlId, $singleResult['results']);
                    } else {
                        $pid = (string)$singleCtx['page_id'];
                        $failed++;
                        if (count($errorsSample) < 20) {
                            $errorsSample[$pid] = $singleResult['error'] ?? 'still missing after 1-by-1 retry';
                        }
                    }
                }
            } else {
                // Hard failure on a single-URL batch (no fallback possible).
                $pid = (string)$contextBatch[0]['page_id'];
                $failed++;
                if (count($errorsSample) < 20) {
                    $errorsSample[$pid] = $batchResult['error'] ?? 'unknown error';
                }
            }

            $processed += count($chunk);
            $this->updateProgress($bulkJobId, $processed, $failed, $totalIn, $totalOut, $errorsSample);

            if ($onProgress) {
                try { $onProgress($processed, count($pageIds)); } catch (\Throwable $e) { /* swallow */ }
            }
        }

        $this->markDone($bulkJobId, $processed, $failed, $totalIn, $totalOut, $errorsSample);
        $this->recordBudget($userId, $model, $crawlId, $totalIn, $totalOut, $totalCost, true);
    }

    /**
     * Bill a finished/stopped bulk job against the owner's monthly AI budget.
     * One ledger row for the whole job (sum of all batch + retry calls).
     * Never throws — accounting must not break the worker.
     */
    private function recordBudget(int $userId, string $model, int $crawlId, int $inTok, int $outTok, ?float $cost, bool $success): void
    {
        if ($userId <= 0) return;
        if ($inTok <= 0 && $outTok <= 0 && $cost === null) return;
        BudgetService::record($userId, BudgetService::FEATURE_BULK, $model, $inTok, $outTok, $cost, $crawlId, $success);
    }

    /**
     * Run one batch through OpenRouter, parse, validate.
     *
     * @return array{
     *   ok:bool, results:array<string,array>, error?:string,
     *   input_tokens:int, output_tokens:int
     * }
     */
    private function runBatch(string $apiKey, string $model, array $items, string $template, array $contextBatch): array
    {
        $prompt = BulkGeneratePrompt::build($items, $template, $contextBatch);
        $messages = [
            ['role' => 'system', 'content' => $prompt['system']],
            ['role' => 'user',   'content' => $prompt['user']],
        ];

        $response = OpenRouterClient::chatCompletion($apiKey, $model, $messages, [
            // Force JSON output mode — much more reliable parsing.
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.2,
        ]);

        $inTok  = (int)($response['input_tokens']  ?? 0);
        $outTok = (int)($response['output_tokens'] ?? 0);
        $cost   = isset($response['cost_usd']) ? $response['cost_usd'] : null;

        if (!$response['ok']) {
            return [
                'ok' => false, 'results' => [],
                'error' => $response['error'] ?? 'OpenRouter error',
                'input_tokens' => $inTok, 'output_tokens' => $outTok, 'cost_usd' => $cost,
            ];
        }

        $expectedIds = array_map(static fn($e) => (string)$e['page_id'], $contextBatch);
        $parsed = BulkGeneratePrompt::parseResponse((string)$response['text'], $items, $expectedIds);

        if (!$parsed['ok']) {
            return [
                'ok' => false, 'results' => [],
                'error' => $parsed['error'] ?? 'parse error',
                'input_tokens' => $inTok, 'output_tokens' => $outTok, 'cost_usd' => $cost,
            ];
        }

        return [
            'ok' => true,
            'results' => $parsed['results'],
            'input_tokens' => $inTok, 'output_tokens' => $outTok, 'cost_usd' => $cost,
        ];
    }

    /**
     * Merge the JSONB result for each page_id into pages.generation.
     *
     * @param array<string, array<string,mixed>> $results  page_id => {field => typed_value}
     */
    private function writeResults(int $crawlId, array $results): void
    {
        if (empty($results)) return;

        // One UPDATE per page_id to preserve typing of each value (jsonb_build_object
        // with placeholders would lose the native types). Cheap because batches
        // are small (≤ 50). Wrapped in a transaction for atomicity per batch.
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE pages
                SET generation = coalesce(generation, '{}'::jsonb) || :merge::jsonb
                WHERE crawl_id = :cid AND id = :pid
            ");
            foreach ($results as $pid => $values) {
                $merge = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $stmt->execute([
                    ':merge' => $merge,
                    ':cid'   => $crawlId,
                    ':pid'   => $pid,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('[BulkGenerator] writeResults failed: ' . $e->getMessage());
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Job state helpers
    // -------------------------------------------------------------------------

    /** @return array|null  associative row or null */
    private function loadJob(int $bulkJobId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bulk_generation_jobs WHERE id = :id");
        $stmt->execute([':id' => $bulkJobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Postgres array literals → PHP arrays.
        $row['context_fields'] = self::pgTextArray($row['context_fields'] ?? '{}');
        $row['page_ids']       = self::pgTextArray($row['page_ids']       ?? '{}');
        // items is JSONB → decode.
        $row['items'] = is_string($row['items']) ? json_decode($row['items'], true) : ($row['items'] ?? []);
        return $row;
    }

    private function markStarted(int $jobId): void
    {
        $this->db->prepare("
            UPDATE bulk_generation_jobs
            SET status = 'running', started_at = CURRENT_TIMESTAMP
            WHERE id = :id AND status IN ('queued', 'running')
        ")->execute([':id' => $jobId]);
    }

    private function isStopRequested(int $jobId): bool
    {
        $stmt = $this->db->prepare("SELECT status FROM bulk_generation_jobs WHERE id = :id");
        $stmt->execute([':id' => $jobId]);
        return ($stmt->fetchColumn() === 'stopped');
    }

    private function updateProgress(int $jobId, int $processed, int $failed, int $inTok, int $outTok, array $errors): void
    {
        // actual_cost is updated by the controller / a separate step using
        // the current model's pricing — keeping the worker schema-free of
        // pricing knowledge (it changes upstream, no reason to bake it in).
        $this->db->prepare("
            UPDATE bulk_generation_jobs
            SET processed_count = :p,
                failed_count    = :f,
                input_tokens    = :it,
                output_tokens   = :ot,
                errors_sample   = :err::jsonb
            WHERE id = :id
        ")->execute([
            ':p'   => $processed,
            ':f'   => $failed,
            ':it'  => $inTok,
            ':ot'  => $outTok,
            ':err' => json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id'  => $jobId,
        ]);
    }

    private function markDone(int $jobId, int $processed, int $failed, int $inTok, int $outTok, array $errors): void
    {
        $this->updateProgress($jobId, $processed, $failed, $inTok, $outTok, $errors);
        $this->db->prepare("
            UPDATE bulk_generation_jobs
            SET status = 'done', finished_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':id' => $jobId]);
    }

    private function markStopped(int $jobId, int $processed, int $failed, int $inTok, int $outTok, array $errors): void
    {
        $this->updateProgress($jobId, $processed, $failed, $inTok, $outTok, $errors);
        $this->db->prepare("
            UPDATE bulk_generation_jobs
            SET status = 'stopped', finished_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':id' => $jobId]);
    }

    private function markFailed(int $jobId, string $error): void
    {
        $this->db->prepare("
            UPDATE bulk_generation_jobs
            SET status = 'failed',
                error_message = :err,
                finished_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([':err' => $error, ':id' => $jobId]);
    }

    /** Parse a Postgres TEXT[] literal like "{a,b,c}" into a PHP array. */
    private static function pgTextArray(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '{}') return [];
        $raw = trim($raw, '{}');
        // PG quotes elements with embedded commas — we don't expect any here
        // (page IDs are CHAR(8) hex-ish, context fields are bare slugs) so
        // a simple split is fine.
        $parts = preg_split('/,/', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p, " \t\n\r\"");
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }
}
