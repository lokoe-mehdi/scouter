<?php
/**
 * User profile — personal AI budget & usage.
 *
 * Every logged-in user (admin + editor) sees their monthly AI budget, how much
 * they've spent this month, a per-feature breakdown and a recent history.
 * Viewers (read-only) have no AI access, so they just get a notice.
 *
 * No DB persistence of conversations — this only reads the `ai_usage` ledger.
 */

require_once(__DIR__ . '/init.php');
require_once(__DIR__ . '/config/i18n.php');

use App\Auth\Auth;
use App\AI\BudgetService;

$auth = new Auth();
$userId = (int)$auth->getCurrentUserId();
$role   = $auth->getCurrentRole();
$isAdmin = $auth->hasRole('admin');

$roleAllowed = BudgetService::isAiEligibleRole($role);
$status  = $roleAllowed ? BudgetService::status($userId, $role) : null;
$history = $roleAllowed ? BudgetService::recentHistory($userId, 25) : [];

$featureLabel = static function (string $f): string {
    $map = [
        BudgetService::FEATURE_CHATBOT        => 'feature.chatbot',
        BudgetService::FEATURE_CATEGORIZATION => 'feature.categorization',
        BudgetService::FEATURE_BULK           => 'feature.bulk_generate',
        BudgetService::FEATURE_FILTERS        => 'feature.ai_filters',
    ];
    return isset($map[$f]) ? __($map[$f]) : $f;
};

$pct = 0.0;
if ($status && $status['budget'] > 0) {
    $pct = min(100.0, ($status['spent'] / $status['budget']) * 100.0);
}
$barColor = $pct >= 100 ? '#dc2626' : ($pct >= 80 ? '#f59e0b' : 'var(--primary-color)');
?>
<!DOCTYPE html>
<html lang="<?= I18n::getInstance()->getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scouter - <?= __('profile.title') ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/responsive.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/vendor/material-symbols/material-symbols.css" />
    <script src="assets/i18n.js"></script>
    <script>ScouterI18n.init(<?= I18n::getInstance()->getJsTranslations() ?>, <?= json_encode(I18n::getInstance()->getLang()) ?>);</script>
    <style>
        .pf { max-width: 880px; margin: 2rem auto; padding: 0 1.5rem; }
        .pf-h1 { font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.5rem; }
        .pf-card { background: #fff; border: 1px solid var(--border-color, #e5e7eb); border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.25rem; }
        .pf-card h2 { font-size: 1rem; font-weight: 600; color: var(--text-primary); margin: 0 0 1rem; }
        .pf-budget-row { display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .pf-stat .lbl { font-size: 0.8rem; color: var(--text-secondary); }
        .pf-stat .val { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); font-variant-numeric: tabular-nums; }
        .pf-bar { height: 10px; background: #f1f5f9; border-radius: 999px; overflow: hidden; }
        .pf-bar > div { height: 100%; border-radius: 999px; transition: width .3s ease; }
        .pf-note { font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.6rem; }
        .pf-bd { display: flex; flex-direction: column; gap: 0.5rem; }
        .pf-bd-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; padding: 0.4rem 0; border-bottom: 1px solid #f1f5f9; }
        .pf-bd-row:last-child { border-bottom: none; }
        .pf-bd-row .c { font-variant-numeric: tabular-nums; font-weight: 600; }
        .pf-tbl { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .pf-tbl th { text-align: left; color: var(--text-secondary); font-weight: 600; padding: 0.45rem 0.5rem; border-bottom: 1px solid #e5e7eb; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; }
        .pf-tbl td { padding: 0.45rem 0.5rem; border-bottom: 1px solid #f5f5f5; }
        .pf-tbl td.c { text-align: right; font-variant-numeric: tabular-nums; }
        .pf-empty { color: var(--text-secondary); font-size: 0.9rem; padding: 1rem 0; }
        .pf-notice { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 1rem 1.25rem; }
    </style>
</head>
<body style="background: #f4f5f7;">
    <?php $headerContext = 'profile'; include 'components/top-header.php'; ?>

    <div class="pf">
        <div class="pf-h1"><?= __('profile.title') ?></div>

        <?php if (!$roleAllowed): ?>
            <div class="pf-notice"><?= __('profile.ai_not_available_role') ?></div>
        <?php else: ?>
            <!-- Budget -->
            <div class="pf-card">
                <h2><?= __('profile.budget_title') ?></h2>
                <div class="pf-budget-row">
                    <div class="pf-stat"><div class="lbl"><?= __('profile.monthly_budget') ?></div><div class="val">$<?= number_format($status['budget'], 2) ?></div></div>
                    <div class="pf-stat"><div class="lbl"><?= __('profile.spent') ?></div><div class="val">$<?= number_format($status['spent'], 2) ?></div></div>
                    <div class="pf-stat"><div class="lbl"><?= __('profile.remaining') ?></div><div class="val">$<?= number_format($status['remaining'], 2) ?></div></div>
                </div>
                <div class="pf-bar"><div style="width: <?= round($pct, 1) ?>%; background: <?= $barColor ?>;"></div></div>
                <div class="pf-note"><?= __('profile.resets_note') ?></div>
            </div>

            <!-- Breakdown -->
            <div class="pf-card">
                <h2><?= __('profile.breakdown_title') ?></h2>
                <div class="pf-bd">
                    <?php foreach ($status['breakdown'] as $feature => $cost): ?>
                    <div class="pf-bd-row">
                        <span><?= htmlspecialchars($featureLabel($feature)) ?></span>
                        <span class="c">$<?= number_format((float)$cost, 4) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- History -->
            <div class="pf-card">
                <h2><?= __('profile.history_title') ?></h2>
                <?php if (empty($history)): ?>
                    <div class="pf-empty"><?= __('profile.no_history') ?></div>
                <?php else: ?>
                    <table class="pf-tbl">
                        <thead><tr>
                            <th><?= __('profile.col_date') ?></th>
                            <th><?= __('profile.col_feature') ?></th>
                            <th><?= __('profile.col_model') ?></th>
                            <th style="text-align:right;"><?= __('profile.col_cost') ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$h['created_at']) ?></td>
                                <td><?= htmlspecialchars($featureLabel((string)$h['feature'])) ?></td>
                                <td style="color:var(--text-secondary);"><?= htmlspecialchars((string)$h['model']) ?></td>
                                <td class="c">$<?= number_format((float)$h['cost_usd'], 5) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
