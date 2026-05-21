<?php

namespace App\AI;

/**
 * Rough token/cost estimator for the Bulk AI Generator wizard's
 * pre-flight panel.
 *
 * Three things matter to the user before they hit "Lancer 🚀" :
 *   - how many tokens will be consumed (in/out)
 *   - how much that will cost in USD with the selected model
 *   - how long the job will take, roughly
 *
 * We use a coarse `chars / 4` heuristic for tokenization. That's the
 * standard approximation for OpenAI / Anthropic tokenizers on Latin
 * scripts ; accurate to ±10-15%, more than enough for "is this going
 * to cost $0.05 or $5".
 *
 * @package    Scouter
 * @subpackage AI
 */
class PromptEstimator
{
    /** System prompt + tool/format scaffolding sent per API call. */
    private const SYSTEM_PROMPT_TOKENS_BASE   = 250;
    /** Per-item overhead added to the system prompt (type spec + name). */
    private const SYSTEM_PROMPT_TOKENS_PER_ITEM = 25;
    /** Typical output size per generated text item (résumé court, title…). */
    private const OUTPUT_TOKENS_PER_TEXT_ITEM    = 60;
    /** Typical output size per generated number/boolean item (much smaller). */
    private const OUTPUT_TOKENS_PER_SCALAR_ITEM  = 8;

    /** Sweet spot for input tokens per API call (perf/cost/parse-fail risk). */
    private const TARGET_INPUT_TOKENS_PER_CALL = 8000;

    /**
     * Compute an end-to-end estimate.
     *
     * @param array $params {
     *   prompt_template:   string,
     *   items:             array<int, array{name:string,type:string,note?:string}>,
     *   url_count:         int,
     *   avg_context_tokens:int (estimated by caller via ContextBuilder::estimateContextTokens
     *                          on a small sample),
     *   model:             array{id:string, prompt_price:float, completion_price:float}
     *                          (prices in USD per token — same shape as OpenRouterClient::listModels()),
     *   manual_batch_size?:int (override the auto-computed batch size),
     * }
     * @return array {
     *   batch_size:        int,
     *   api_calls:         int,
     *   input_tokens:      int,
     *   output_tokens:     int,
     *   estimated_cost:    float (USD),
     *   estimated_seconds: int,
     * }
     */
    public static function estimate(array $params): array
    {
        $template       = (string)($params['prompt_template'] ?? '');
        $items          = is_array($params['items'] ?? null) ? $params['items'] : [];
        $urlCount       = max(0, (int)($params['url_count'] ?? 0));
        $avgCtxTokens   = max(0, (int)($params['avg_context_tokens'] ?? 0));
        $model          = is_array($params['model'] ?? null) ? $params['model'] : [];
        $manualBatch    = isset($params['manual_batch_size']) ? (int)$params['manual_batch_size'] : null;

        // --- System prompt size ---------------------------------------
        $itemsCount    = count($items);
        $systemTokens  = self::SYSTEM_PROMPT_TOKENS_BASE
                       + $itemsCount * self::SYSTEM_PROMPT_TOKENS_PER_ITEM
                       + self::roughTokens($template);

        // --- Output size per URL --------------------------------------
        $outputPerUrl = 0;
        foreach ($items as $item) {
            $type = (string)($item['type'] ?? 'text');
            $outputPerUrl += in_array($type, ['number', 'boolean'], true)
                ? self::OUTPUT_TOKENS_PER_SCALAR_ITEM
                : self::OUTPUT_TOKENS_PER_TEXT_ITEM;
        }
        // JSON wrapper overhead per row (page_id field + braces + commas).
        $outputPerUrl += 15;

        // --- Batch size (auto vs manual) ------------------------------
        $batchSize = $manualBatch !== null && $manualBatch > 0
            ? $manualBatch
            : self::autoBatchSize($avgCtxTokens, $itemsCount);
        if ($urlCount > 0 && $batchSize > $urlCount) $batchSize = $urlCount;
        $batchSize = max(1, $batchSize);

        // --- Aggregate totals -----------------------------------------
        $apiCalls    = $urlCount > 0 ? (int)ceil($urlCount / $batchSize) : 0;
        $inputTokens = $apiCalls * $systemTokens          // system reuse
                     + $urlCount * $avgCtxTokens;          // per-URL context
        $outputTokens = $urlCount * $outputPerUrl;

        // --- Cost (model prices are USD per token) --------------------
        $promptPrice     = (float)($model['prompt_price']     ?? 0.0);
        $completionPrice = (float)($model['completion_price'] ?? 0.0);
        $cost = $inputTokens * $promptPrice + $outputTokens * $completionPrice;

        // --- Wall-clock estimate --------------------------------------
        // Average API call ~2-3s (TTFT + ~0.5k output tokens streamed).
        // Add a small constant per call for processing.
        $estimatedSeconds = (int)ceil($apiCalls * 3);

        return [
            'batch_size'        => $batchSize,
            'api_calls'         => $apiCalls,
            'input_tokens'      => $inputTokens,
            'output_tokens'     => $outputTokens,
            'estimated_cost'    => round($cost, 4),
            'estimated_seconds' => $estimatedSeconds,
        ];
    }

    /**
     * Auto-batch heuristic : target ~TARGET_INPUT_TOKENS_PER_CALL per API call.
     * More items → smaller batch (output also grows). Clamps [1, 50].
     */
    public static function autoBatchSize(int $contextTokensPerUrl, int $itemsCount): int
    {
        $perUrl = max(1, $contextTokensPerUrl + max(1, $itemsCount) * 80);
        $batch  = (int)floor(self::TARGET_INPUT_TOKENS_PER_CALL / $perUrl);
        return max(1, min(50, $batch));
    }

    /** Coarse char/4 tokenization heuristic. */
    public static function roughTokens(string $s): int
    {
        if ($s === '') return 0;
        return (int)ceil(mb_strlen($s) / 4);
    }
}
