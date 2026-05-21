<?php

use App\AI\BudgetService;
use App\Database\PostgresDatabase;
use App\Settings\AppSettings;

/**
 * Unit tests for the per-user monthly AI budget service.
 *
 * DB-backed (the container provides DATABASE_URL): we create a throwaway user,
 * write `ai_usage` rows, assert the live monthly aggregation + gating, and
 * clean everything up afterwards. The month window is computed live from
 * created_at (no cron), so we also assert that last-month rows are excluded.
 */

beforeEach(function () {
    $this->db = PostgresDatabase::getInstance()->getConnection();
    $this->email = 'budget-test-' . uniqid() . '@example.test';

    $this->db->prepare(
        "INSERT INTO users (email, password_hash, role) VALUES (:e, 'x', 'user')"
    )->execute([':e' => $this->email]);
    $this->uid = (int)$this->db->query(
        "SELECT id FROM users WHERE email = " . $this->db->quote($this->email)
    )->fetchColumn();

    // Known global default.
    AppSettings::set('ai.budget.monthly_usd', '10.00');
    AppSettings::flushCache();
});

afterEach(function () {
    $this->db->exec("DELETE FROM ai_usage WHERE user_id = " . (int)$this->uid);
    $this->db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $this->uid]);
});

// --- helpers ---------------------------------------------------------------
function insertUsage(PDO $db, int $uid, string $feature, float $cost, string $createdAt = 'now'): void
{
    $db->prepare("
        INSERT INTO ai_usage (user_id, feature, model, input_tokens, output_tokens, cost_usd, success, created_at)
        VALUES (:u, :f, 'test/model', 10, 10, :c, true, " .
        ($createdAt === 'now' ? "CURRENT_TIMESTAMP" : ":ts") . ")
    ")->execute($createdAt === 'now'
        ? [':u' => $uid, ':f' => $feature, ':c' => $cost]
        : [':u' => $uid, ':f' => $feature, ':c' => $cost, ':ts' => $createdAt]);
}

// --- role eligibility ------------------------------------------------------
it('only allows admin and user roles', function () {
    expect(BudgetService::isAiEligibleRole('admin'))->toBeTrue();
    expect(BudgetService::isAiEligibleRole('user'))->toBeTrue();
    expect(BudgetService::isAiEligibleRole('viewer'))->toBeFalse();
    expect(BudgetService::isAiEligibleRole(null))->toBeFalse();
});

// --- budget resolution -----------------------------------------------------
it('uses the global default when the user has no override', function () {
    expect(BudgetService::budgetForUser($this->uid))->toBe(10.0);
});

it('uses the per-user override when set', function () {
    $this->db->prepare("UPDATE users SET ai_monthly_budget_usd = 25 WHERE id = :id")
        ->execute([':id' => $this->uid]);
    expect(BudgetService::budgetForUser($this->uid))->toBe(25.0);
});

// --- monthly spend + breakdown --------------------------------------------
it('sums only the current calendar month', function () {
    insertUsage($this->db, $this->uid, BudgetService::FEATURE_CHATBOT, 0.50);
    insertUsage($this->db, $this->uid, BudgetService::FEATURE_FILTERS, 0.30);
    // 40 days ago = previous month → must be EXCLUDED.
    insertUsage($this->db, $this->uid, BudgetService::FEATURE_CHATBOT, 9.99,
        date('Y-m-d H:i:s', strtotime('-40 days')));

    expect(round(BudgetService::spentThisMonth($this->uid), 2))->toBe(0.80);

    $bd = BudgetService::breakdownThisMonth($this->uid);
    expect(round($bd[BudgetService::FEATURE_CHATBOT], 2))->toBe(0.50);
    expect(round($bd[BudgetService::FEATURE_FILTERS], 2))->toBe(0.30);
    expect($bd[BudgetService::FEATURE_BULK])->toBe(0.0);
});

it('records a usage row with the given cost', function () {
    BudgetService::record($this->uid, BudgetService::FEATURE_BULK, 'test/model', 100, 50, 0.42, null, true);
    expect(round(BudgetService::spentThisMonth($this->uid), 2))->toBe(0.42);
});

// --- gating ----------------------------------------------------------------
it('allows under budget, blocks when over', function () {
    $under = BudgetService::check($this->uid, 'user');
    expect($under['allowed'])->toBeTrue();

    insertUsage($this->db, $this->uid, BudgetService::FEATURE_CHATBOT, 10.50); // > 10 budget
    $over = BudgetService::check($this->uid, 'user');
    expect($over['allowed'])->toBeFalse();
    expect($over['reason'])->toBe('budget');
});

it('blocks viewers on role', function () {
    $r = BudgetService::check($this->uid, 'viewer');
    expect($r['allowed'])->toBeFalse();
    expect($r['reason'])->toBe('role');
});

it('pre-flight refuses an estimate exceeding the remaining budget', function () {
    insertUsage($this->db, $this->uid, BudgetService::FEATURE_BULK, 8.00); // remaining = 2
    $ok  = BudgetService::checkEstimate($this->uid, 'user', 1.50);
    $bad = BudgetService::checkEstimate($this->uid, 'user', 5.00);
    expect($ok['allowed'])->toBeTrue();
    expect($bad['allowed'])->toBeFalse();
    expect($bad['reason'])->toBe('budget');
});

// --- cost resolution + messaging ------------------------------------------
it('passes the real cost through resolveCost', function () {
    expect(BudgetService::resolveCost(0.123456, 'test/model', 100, 100))->toBe(0.123456);
});

it('builds a localized block message for role vs budget', function () {
    $roleMsg   = BudgetService::blockMessage(['reason' => 'role']);
    $budgetMsg = BudgetService::blockMessage(['reason' => 'budget', 'spent' => 3, 'budget' => 10]);
    expect($roleMsg)->toBeString()->not->toBe('');
    expect($budgetMsg)->toContain('10');
});
