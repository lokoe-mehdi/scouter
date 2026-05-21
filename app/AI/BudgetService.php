<?php

namespace App\AI;

use App\Database\PostgresDatabase;
use App\Settings\AppSettings;
use PDO;

/**
 * Per-user monthly AI budget: enforcement + accounting.
 *
 * Single source of truth for "how much has this user spent on AI this month,
 * and are they allowed to spend more". Backed by the `ai_usage` ledger (one
 * row per billed AI action, real OpenRouter cost in USD).
 *
 * Design choices (validated with the product owner):
 *  - The monthly window is the CURRENT CALENDAR MONTH, computed live from
 *    created_at — no cron, no destructive reset. Raising a user's budget
 *    unblocks them immediately (it's a live SUM vs the current budget).
 *  - Only `admin` and `user` roles can use AI. `viewer` is never allowed.
 *  - Cost is recorded AFTER each call from OpenRouter's real `usage.cost`.
 *    A tiny overage on the call that crosses the threshold is accepted; the
 *    NEXT call is blocked. The bulk generator additionally pre-flights its
 *    estimate against the remaining budget (one job can be expensive).
 *
 * @package    Scouter
 * @subpackage AI
 */
class BudgetService
{
    /** Feature buckets shown in the profile / admin breakdowns. */
    public const FEATURE_CHATBOT       = 'chatbot';
    public const FEATURE_CATEGORIZATION = 'categorization';
    public const FEATURE_BULK          = 'bulk_generate';
    public const FEATURE_FILTERS       = 'ai_filters';

    public const FEATURES = [
        self::FEATURE_CHATBOT,
        self::FEATURE_CATEGORIZATION,
        self::FEATURE_BULK,
        self::FEATURE_FILTERS,
    ];

    /** Hard fallback if the global setting is missing/corrupt. */
    private const DEFAULT_BUDGET_USD = 10.00;

    /** Process-lifetime cache of the OpenRouter pricing map (id => model). */
    private static ?array $pricingCache = null;

    private static function db(): PDO
    {
        return PostgresDatabase::getInstance()->getConnection();
    }

    // -------------------------------------------------------------------------
    // Roles
    // -------------------------------------------------------------------------

    /** Only admins and editors ("user") may use AI features. Viewers never. */
    public static function isAiEligibleRole(?string $role): bool
    {
        return $role === 'admin' || $role === 'user';
    }

    // -------------------------------------------------------------------------
    // Budget resolution
    // -------------------------------------------------------------------------

    /** Global default monthly budget (USD) from app_settings. */
    public static function defaultBudget(): float
    {
        $raw = AppSettings::get('ai.budget.monthly_usd');
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return self::DEFAULT_BUDGET_USD;
        }
        return max(0.0, (float)$raw);
    }

    /** Effective monthly budget for a user: per-user override ?? global default. */
    public static function budgetForUser(int $userId): float
    {
        $stmt = self::db()->prepare(
            "SELECT ai_monthly_budget_usd FROM users WHERE id = :id"
        );
        $stmt->execute([':id' => $userId]);
        $override = $stmt->fetchColumn();
        if ($override !== false && $override !== null && is_numeric($override)) {
            return max(0.0, (float)$override);
        }
        return self::defaultBudget();
    }

    // -------------------------------------------------------------------------
    // Spend (current calendar month)
    // -------------------------------------------------------------------------

    /** Total USD spent by a user since the 1st of the current month. */
    public static function spentThisMonth(int $userId): float
    {
        $stmt = self::db()->prepare("
            SELECT COALESCE(SUM(cost_usd), 0)
            FROM ai_usage
            WHERE user_id = :id
              AND created_at >= date_trunc('month', CURRENT_TIMESTAMP)
        ");
        $stmt->execute([':id' => $userId]);
        return (float)$stmt->fetchColumn();
    }

    /** Per-feature USD spend this month: [feature => cost]. Zero-filled. */
    public static function breakdownThisMonth(int $userId): array
    {
        $out = array_fill_keys(self::FEATURES, 0.0);
        $stmt = self::db()->prepare("
            SELECT feature, COALESCE(SUM(cost_usd), 0) AS c
            FROM ai_usage
            WHERE user_id = :id
              AND created_at >= date_trunc('month', CURRENT_TIMESTAMP)
            GROUP BY feature
        ");
        $stmt->execute([':id' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['feature']] = (float)$row['c'];
        }
        return $out;
    }

    /**
     * Full budget status for a user — drives the profile page + the gate.
     *
     * @return array{role_allowed:bool, budget:float, spent:float,
     *               remaining:float, exhausted:bool, breakdown:array<string,float>}
     */
    public static function status(int $userId, ?string $role): array
    {
        $budget = self::budgetForUser($userId);
        $spent  = self::spentThisMonth($userId);
        $remaining = max(0.0, $budget - $spent);
        return [
            'role_allowed' => self::isAiEligibleRole($role),
            'budget'       => round($budget, 2),
            'spent'        => round($spent, 6),
            'remaining'    => round($remaining, 6),
            'exhausted'    => $spent >= $budget,
            'breakdown'    => self::breakdownThisMonth($userId),
        ];
    }

    // -------------------------------------------------------------------------
    // Gate
    // -------------------------------------------------------------------------

    /**
     * Can this user run an AI action right now?
     *
     * @return array{allowed:bool, reason:?string, budget:float, spent:float}
     *   reason ∈ {null, 'role', 'budget'}.
     */
    public static function check(int $userId, ?string $role): array
    {
        if (!self::isAiEligibleRole($role)) {
            return ['allowed' => false, 'reason' => 'role', 'budget' => 0.0, 'spent' => 0.0];
        }
        $budget = self::budgetForUser($userId);
        $spent  = self::spentThisMonth($userId);
        if ($spent >= $budget) {
            return ['allowed' => false, 'reason' => 'budget', 'budget' => $budget, 'spent' => $spent];
        }
        return ['allowed' => true, 'reason' => null, 'budget' => $budget, 'spent' => $spent];
    }

    /**
     * Pre-flight check for a potentially expensive action (bulk generate):
     * would `estimatedCost` push the user past their budget?
     *
     * @return array{allowed:bool, reason:?string, budget:float, spent:float, remaining:float}
     */
    public static function checkEstimate(int $userId, ?string $role, float $estimatedCost): array
    {
        $base = self::check($userId, $role);
        $remaining = max(0.0, $base['budget'] - $base['spent']);
        if (!$base['allowed']) {
            return $base + ['remaining' => $remaining];
        }
        if ($estimatedCost > $remaining) {
            return ['allowed' => false, 'reason' => 'budget', 'budget' => $base['budget'],
                    'spent' => $base['spent'], 'remaining' => $remaining];
        }
        return ['allowed' => true, 'reason' => null, 'budget' => $base['budget'],
                'spent' => $base['spent'], 'remaining' => $remaining];
    }

    /**
     * Human, localized message for a denied check() / checkEstimate() — used by
     * the non-streaming features (filters, categorization, bulk) to show a
     * friendly reason instead of a raw error.
     */
    public static function blockMessage(array $check): string
    {
        $t = static function (string $key, string $fallback): string {
            return function_exists('__') ? __($key) : $fallback;
        };
        if (($check['reason'] ?? null) === 'role') {
            return $t('ai_budget.role_blocked', 'AI features are not available for your role.');
        }
        $tpl = $t('ai_budget.reached', 'Monthly AI budget reached ({spent} $ / {budget} $). It resets on the 1st of next month.');
        return str_replace(
            ['{spent}', '{budget}'],
            [number_format((float)($check['spent'] ?? 0), 2), number_format((float)($check['budget'] ?? 0), 2)],
            $tpl
        );
    }

    // -------------------------------------------------------------------------
    // Recording
    // -------------------------------------------------------------------------

    /**
     * Record one billed AI action. `costUsd` should be OpenRouter's real
     * `usage.cost`; pass null to compute a fallback from the model's per-token
     * pricing. Never throws — accounting must not break the feature.
     */
    public static function record(
        int $userId,
        string $feature,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?float $costUsd,
        ?int $crawlId = null,
        bool $success = true
    ): void {
        try {
            $cost = self::resolveCost($costUsd, $model, $inputTokens, $outputTokens);
            $stmt = self::db()->prepare("
                INSERT INTO ai_usage
                    (user_id, feature, model, input_tokens, output_tokens, cost_usd, crawl_id, success)
                VALUES (:uid, :feat, :model, :it, :ot, :cost, :cid, :ok)
            ");
            $stmt->execute([
                ':uid'   => $userId > 0 ? $userId : null,
                ':feat'  => $feature,
                ':model' => mb_substr($model, 0, 100),
                ':it'    => $inputTokens,
                ':ot'    => $outputTokens,
                ':cost'  => round($cost, 6),
                ':cid'   => $crawlId,
                ':ok'    => $success ? 'true' : 'false',
            ]);
        } catch (\Throwable $e) {
            error_log('[BudgetService] record failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve the USD cost of a call. Prefer OpenRouter's real billed cost;
     * fall back to input_tokens*prompt_price + output_tokens*completion_price
     * using the published per-token pricing (cached per process). Returns 0 if
     * neither is available — extremely rare since we set usage.include.
     */
    public static function resolveCost(?float $costUsd, string $model, int $inputTokens, int $outputTokens): float
    {
        if ($costUsd !== null && $costUsd > 0) {
            return $costUsd;
        }
        $pricing = self::pricingFor($model);
        if ($pricing === null) {
            return 0.0;
        }
        return $inputTokens * $pricing['prompt'] + $outputTokens * $pricing['completion'];
    }

    /** Per-token prices for a model, or null if the catalog is unavailable. */
    private static function pricingFor(string $model): ?array
    {
        if (self::$pricingCache === null) {
            self::$pricingCache = [];
            $list = OpenRouterClient::listModels();
            if (!empty($list['ok']) && !empty($list['models'])) {
                foreach ($list['models'] as $m) {
                    self::$pricingCache[$m['id']] = [
                        'prompt'     => (float)($m['prompt_price'] ?? 0.0),
                        'completion' => (float)($m['completion_price'] ?? 0.0),
                    ];
                }
            }
        }
        return self::$pricingCache[$model] ?? null;
    }

    // -------------------------------------------------------------------------
    // Admin global view
    // -------------------------------------------------------------------------

    /** Global spend this month per feature: [feature => cost]. Zero-filled. */
    public static function globalBreakdownThisMonth(): array
    {
        $out = array_fill_keys(self::FEATURES, 0.0);
        $stmt = self::db()->query("
            SELECT feature, COALESCE(SUM(cost_usd), 0) AS c
            FROM ai_usage
            WHERE created_at >= date_trunc('month', CURRENT_TIMESTAMP)
            GROUP BY feature
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['feature']] = (float)$row['c'];
        }
        return $out;
    }

    /** Global spend this month per user: [{user_id, email, role, spent}]. */
    public static function globalByUserThisMonth(): array
    {
        $stmt = self::db()->query("
            SELECT u.id, u.email, u.role,
                   COALESCE(SUM(a.cost_usd), 0) AS spent
            FROM users u
            LEFT JOIN ai_usage a
              ON a.user_id = u.id
             AND a.created_at >= date_trunc('month', CURRENT_TIMESTAMP)
            WHERE u.role IN ('admin', 'user')
            GROUP BY u.id, u.email, u.role
            ORDER BY spent DESC, u.email ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Recent actions for a user (profile history). */
    public static function recentHistory(int $userId, int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = self::db()->prepare("
            SELECT feature, model, input_tokens, output_tokens, cost_usd, crawl_id, success, created_at
            FROM ai_usage
            WHERE user_id = :id
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
