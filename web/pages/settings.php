<?php
/**
 * Admin Settings page.
 *
 * AI provider section : OpenRouter (https://openrouter.ai), one API key that
 * unlocks many model providers (OpenAI, Anthropic, Google, Mistral, …) with a
 * single billing account. Admin picks :
 *   - a "light" model for fast/cheap one-shot tasks (categorization, NL→SQL,
 *     filter suggestions)
 *   - a "strong" model for the Dr. Brief chatbot (needs tool calling)
 *
 * Designed to grow — every new section should be a self-contained
 * `.settings-card` so we can keep adding without restructuring.
 */

require_once(__DIR__ . '/init.php');
$auth->requireAdmin(false);

use App\Settings\AppSettings;
use App\AI\BudgetService;

$hasEncryption  = AppSettings::hasEncryptionKey();
$apiKey         = AppSettings::get('ai.openrouter.api_key');
$hasStoredKey   = $apiKey !== null && $apiKey !== '';
$maskedKey      = $hasStoredKey ? AppSettings::maskSecret($apiKey) : '';
$modelLight     = AppSettings::get('ai.openrouter.model_light')  ?? '';
$modelStrong    = AppSettings::get('ai.openrouter.model_strong') ?? '';

// --- AI budget section data ---
$defaultBudget   = BudgetService::defaultBudget();
$globalBreakdown = BudgetService::globalBreakdownThisMonth();
$budgetByUser    = BudgetService::globalByUserThisMonth(); // [{id,email,role,spent}]
// Per-user overrides (NULL = uses default) for the editable table.
try {
    $_pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();
    $_ovStmt = $_pdo->query("SELECT id, ai_monthly_budget_usd FROM users WHERE role IN ('admin','user')");
    $userOverrides = [];
    foreach ($_ovStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $userOverrides[(int)$r['id']] = $r['ai_monthly_budget_usd'];
    }
} catch (\Throwable $e) { $userOverrides = []; }
$budgetFeatureLabel = static function (string $f): string {
    $map = ['chatbot'=>'feature.chatbot','categorization'=>'feature.categorization','bulk_generate'=>'feature.bulk_generate','ai_filters'=>'feature.ai_filters'];
    return isset($map[$f]) ? __($map[$f]) : $f;
};
?>
<!DOCTYPE html>
<html lang="<?= I18n::getInstance()->getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('settings.page_title') ?> - Scouter</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/responsive.css">
    <link rel="stylesheet" href="../assets/crawl-panel.css">
    <link rel="icon" type="image/png" href="/logo.png">
    <link rel="stylesheet" href="../assets/vendor/material-symbols/material-symbols.css" />
    <style>
        /* Bento layout — two columns above 1100px (AI Provider on the left,
           Dr. Brief prompt editor on the right), single column below that
           breakpoint so narrow viewports still get a usable stack. The
           prompt editor gets ~1.8× the room of the provider card because
           its markdown preview really wants the horizontal space. */
        .settings-bento {
            display: grid;
            grid-template-columns: minmax(440px, 1fr) minmax(560px, 1.8fr);
            gap: 1.5rem;
            align-items: start;
        }
        @media (max-width: 1100px) {
            .settings-bento { grid-template-columns: 1fr; }
        }

        .settings-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            margin-bottom: 0;
        }
        /* Inside the narrower bento column, tighten settings-row label width
           so the input + Test button still fit comfortably. */
        .settings-bento .settings-row {
            grid-template-columns: 140px 1fr auto;
            gap: 0.85rem;
        }
        /* The model selectors get a full-width "stacked" treatment : label
           on top with the sub-label, the combobox on its own line below.
           Reason : the bento's left column is ~440px wide ; squeezing a
           label + a rich combobox + an empty action cell on one row would
           leave ~150px for the combobox itself, and the dropdown gets
           cramped + the slug/pricing wrap badly. Stacked = combobox owns
           the full card width → dropdown breathes, prices fit on one line. */
        .settings-row.stacked {
            grid-template-columns: 1fr;
            gap: 0.5rem;
        }
        .settings-row.stacked > label { line-height: 1.35; }
        .settings-row.stacked > span:empty { display: none; }
        .settings-card h2 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
            color: var(--text-primary);
        }
        .settings-card .card-subtitle {
            color: var(--text-secondary);
            margin: 0 0 1.5rem;
            font-size: 0.9rem;
        }
        .settings-row {
            display: grid;
            grid-template-columns: 180px 1fr auto;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .settings-row label {
            font-weight: 500;
            color: var(--text-primary);
        }
        .settings-row .sub-label {
            display: block;
            color: var(--text-secondary);
            font-weight: 400;
            font-size: 0.8rem;
            margin-top: 0.15rem;
        }
        .settings-row input[type="password"],
        .settings-row input[type="text"] {
            padding: 0.6rem 0.9rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            width: 100%;
            box-sizing: border-box;
            background: white;
        }
        .settings-row input:focus { outline: none; border-color: var(--primary-color); }
        .settings-row input:disabled { background: #f7f7f9; color: #999; cursor: not-allowed; }
        .settings-status {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            min-height: 1.2em;
        }
        .settings-status.ok   { color: #2E7D32; }
        .settings-status.err  { color: #C62828; }
        .settings-status.info { color: var(--text-secondary); }
        .settings-card .actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 1rem;
        }
        .settings-warning {
            background: #FFF3E0;
            border-left: 4px solid #E65100;
            padding: 0.85rem 1rem;
            border-radius: 6px;
            color: #5D4037;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        .settings-warning code {
            background: rgba(0,0,0,0.08);
            padding: 0.05rem 0.4rem;
            border-radius: 4px;
        }
        .account-card {
            background: #F1F8E9;
            border-left: 4px solid #558B2F;
            padding: 0.85rem 1rem;
            border-radius: 6px;
            color: #33691E;
            font-size: 0.9rem;
            margin: 1rem 0 1.5rem;
            display: none;
        }
        .account-card.visible { display: block; }
        .account-card .label  { font-weight: 600; }
        .account-card .credit { margin-left: 1rem; opacity: 0.85; }

        /* =========================================================
           Custom combobox — fuzzy-searchable model picker.
           Built from scratch because <select> + <optgroup> can't
           show pricing/context inline, can't fuzzy-search, and
           rendering 300 options chokes the native control on Win.
           ========================================================= */
        .dr-combo {
            position: relative;
            width: 100%;
        }
        .dr-combo-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            width: 100%;
            padding: 0.55rem 0.85rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 0.95rem;
            text-align: left;
            color: var(--text-primary);
            box-sizing: border-box;
            /* Standard form-field height — the detailed pricing/context only
               appears in the OPEN dropdown to help the user compare. */
            min-height: 42px;
        }
        .dr-combo-trigger:hover:not(:disabled) { border-color: #cbd5e1; }
        .dr-combo-trigger:disabled { background: #f7f7f9; color: #999; cursor: not-allowed; }
        .dr-combo[data-open="true"] .dr-combo-trigger { border-color: #cbd5e1; }
        .dr-combo-display {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .dr-combo-display.placeholder { color: #94a3b8; }
        .dr-combo-trigger .material-symbols-outlined {
            color: #64748b;
            font-size: 1.25rem;
            transition: transform 0.15s;
        }
        .dr-combo[data-open="true"] .dr-combo-trigger .material-symbols-outlined {
            transform: rotate(180deg);
        }
        .dr-combo-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 1000;
            background: white;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            display: none;
            max-height: 420px;
            display: flex;
            flex-direction: column;
        }
        .dr-combo[data-open="false"] .dr-combo-dropdown { display: none; }
        /* Search bar : flex layout with icon as a real sibling of the input
           (no more absolute positioning that was overlapping the
           placeholder text). The wrap itself looks like the field — the
           input is borderless and sits inside the wrap's visual frame. */
        .dr-combo-search-wrap {
            padding: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: stretch;
        }
        .dr-combo-search-field {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0 0.7rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: white;
        }
        .dr-combo-search-field:focus-within { border-color: #cbd5e1; }
        .dr-combo-search-icon {
            flex-shrink: 0;
            color: #94a3b8;
            font-size: 1.1rem;
            line-height: 1;
        }
        /* Specificity must beat `.settings-row input[type="text"]` (0,2,1) which
           otherwise re-imposes a 1px border + green focus border on the model
           search field. Matching specificity + coming later wins the tie. */
        .dr-combo-search-field input.dr-combo-search {
            flex: 1;
            min-width: 0;
            padding: 0.5rem 0;
            border: 0;
            background: transparent;
            font-size: 0.9rem;
            outline: none;
            color: var(--text-primary);
        }
        .dr-combo-search-field input.dr-combo-search:focus { border: 0; box-shadow: none; }
        .dr-combo-search-field input.dr-combo-search::placeholder { color: #94a3b8; }
        .dr-combo-list {
            overflow-y: auto;
            max-height: 350px;
        }
        .dr-combo-group {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            padding: 0.45rem 0.85rem 0.2rem;
            background: #f8fafc;
            font-weight: 600;
            position: sticky;
            top: 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .dr-combo-item {
            padding: 0.55rem 0.85rem;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .dr-combo-item:hover,
        .dr-combo-item.active {
            background: #eff6ff;
        }
        .dr-combo-item.selected {
            background: #dbeafe;
        }
        .dr-combo-item-name {
            font-size: 0.9rem;
            color: var(--text-primary);
            font-weight: 500;
        }
        .dr-combo-item-id {
            font-size: 0.75rem;
            color: #94a3b8;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .dr-combo-item-meta {
            font-size: 0.75rem;
            color: var(--text-secondary);
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            margin-top: 0.1rem;
        }
        .dr-combo-item-meta .ctx { color: #475569; }
        .dr-combo-item-meta .price { color: #475569; }
        .dr-combo-item-meta .free { color: #16a34a; font-weight: 600; }
        .dr-combo-empty {
            padding: 1.5rem;
            text-align: center;
            color: #94a3b8;
            font-size: 0.85rem;
        }
        .dr-combo-loading-msg {
            padding: 0.9rem;
            text-align: center;
            color: #64748b;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .dr-combo-loading-msg .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid #cbd5e1;
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: dr-spin 0.7s linear infinite;
        }
        @keyframes dr-spin { to { transform: rotate(360deg); } }

        /* =========================================================
           Dr. Brief system prompt editor
           ========================================================= */
        .prompt-editor-row {
            display: block;
            margin-bottom: 1rem;
        }
        .prompt-editor-row label {
            display: block;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.4rem;
        }
        .prompt-editor-row .sub-label {
            display: block;
            font-weight: 400;
            color: var(--text-secondary);
            font-size: 0.82rem;
            margin-top: 0.2rem;
        }
        /* Split-view markdown editor — textarea on the left, live preview
           on the right. No external dependency : we already have a minimal
           markdown renderer for the chat widget, reused here. */
        .md-editor {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            background: white;
        }
        .md-editor:focus-within { border-color: var(--primary-color); }
        .md-toolbar {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.4rem 0.55rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }
        .md-tb-btn {
            background: transparent;
            border: 1px solid transparent;
            padding: 0.3rem 0.5rem;
            border-radius: 5px;
            cursor: pointer;
            color: #475569;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-family: inherit;
        }
        .md-tb-btn:hover { background: #e2e8f0; }
        .md-tb-btn.bold { font-weight: 700; }
        .md-tb-btn.italic { font-style: italic; }
        .md-tb-btn .material-symbols-outlined { font-size: 1rem; }
        .md-tb-sep {
            width: 1px;
            height: 18px;
            background: #cbd5e1;
            margin: 0 0.3rem;
        }
        .md-tb-spacer { flex: 1; }
        .md-view-toggle {
            display: inline-flex;
            background: white;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            overflow: hidden;
        }
        .md-view-toggle button {
            background: white;
            border: none;
            padding: 0.3rem 0.6rem;
            cursor: pointer;
            font-size: 0.75rem;
            color: #475569;
            font-family: inherit;
            border-right: 1px solid #cbd5e1;
        }
        .md-view-toggle button:last-child { border-right: none; }
        .md-view-toggle button:hover { background: #f1f5f9; }
        .md-view-toggle button.active {
            background: var(--primary-color, #3b82f6);
            color: white;
        }
        .md-pane {
            display: flex;
            /* Fixed height (not min-height) so the preview can't push the
               page down past the editor — both panes scroll internally
               instead. */
            height: 600px;
            background: white;
        }
        .md-edit {
            flex: 1 1 50%;
            width: 50%;
            padding: 0.85rem 1rem;
            border: none;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.82rem;
            line-height: 1.55;
            box-sizing: border-box;
            resize: none;
            tab-size: 2;
            background: #fafbfc;
            color: var(--text-primary);
            outline: none;
            border-right: 1px solid #e2e8f0;
            height: 100%;
            overflow-y: auto;
        }
        .md-edit:focus { background: white; }
        .md-preview {
            flex: 1 1 50%;
            width: 50%;
            padding: 1rem 1.25rem;
            overflow-y: auto;
            box-sizing: border-box;
            font-size: 0.88rem;
            line-height: 1.55;
            color: var(--text-primary);
            background: white;
            height: 100%;
        }
        .md-pane.mode-edit .md-preview { display: none; }
        .md-pane.mode-edit .md-edit { width: 100%; flex-basis: 100%; border-right: none; }
        .md-pane.mode-preview .md-edit { display: none; }
        .md-pane.mode-preview .md-preview { width: 100%; flex-basis: 100%; }
        /* Markdown preview typography — kept compact for the editor pane */
        .md-preview h1, .md-preview h2, .md-preview h3,
        .md-preview h4, .md-preview h5, .md-preview h6 {
            margin: 1.1rem 0 0.5rem; color: var(--text-primary);
            font-weight: 600;
        }
        .md-preview h1:first-child, .md-preview h2:first-child { margin-top: 0; }
        .md-preview h1 { font-size: 1.3rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.3rem; }
        .md-preview h2 { font-size: 1.1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.25rem; }
        .md-preview h3 { font-size: 1rem; }
        .md-preview p { margin: 0.5rem 0; }
        .md-preview ul, .md-preview ol { margin: 0.4rem 0 0.4rem 1.5rem; padding: 0; }
        .md-preview li { margin: 0.15rem 0; }
        .md-preview code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.85em;
            background: #f1f5f9;
            color: #be123c;
            padding: 0.08rem 0.35rem;
            border-radius: 3px;
        }
        .md-preview pre {
            background: #0f172a; color: #e2e8f0;
            padding: 0.7rem 0.9rem; border-radius: 6px;
            overflow-x: auto; font-size: 0.78rem;
            line-height: 1.5;
            margin: 0.6rem 0;
        }
        .md-preview pre code { background: transparent; color: inherit; padding: 0; }
        .md-preview blockquote {
            margin: 0.6rem 0; padding: 0.4rem 0.9rem;
            border-left: 3px solid #cbd5e1; color: #475569;
            background: #f8fafc;
        }
        .md-preview table {
            border-collapse: collapse; margin: 0.6rem 0;
            font-size: 0.82rem;
        }
        .md-preview th, .md-preview td {
            padding: 0.3rem 0.55rem; border: 1px solid #e2e8f0;
        }
        .md-preview th { background: #f8fafc; font-weight: 600; }
        .md-preview strong { color: var(--text-primary); font-weight: 600; }
        .md-preview hr { border: none; border-top: 1px solid #e2e8f0; margin: 1rem 0; }
        /* Placeholders {var} highlighted in the preview so admins see at
           a glance where the substitutions will land. */
        .md-preview .md-placeholder {
            background: #e0e7ff; color: #3730a3;
            padding: 0.05rem 0.35rem;
            border-radius: 3px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.85em;
            font-weight: 500;
        }
        .prompt-vars-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem 1.1rem;
            margin-top: 1.25rem;
            font-size: 0.85rem;
        }
        .prompt-vars-card .vars-title {
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .prompt-vars-card .vars-intro {
            color: var(--text-secondary);
            margin: 0 0 0.85rem;
            font-size: 0.82rem;
        }
        .prompt-vars-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        .prompt-vars-table th,
        .prompt-vars-table td {
            text-align: left;
            padding: 0.45rem 0.6rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .prompt-vars-table th {
            font-weight: 600;
            color: #475569;
            background: white;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .prompt-vars-table td:first-child code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            background: #e0e7ff;
            color: #3730a3;
            padding: 0.1rem 0.4rem;
            border-radius: 4px;
            font-size: 0.78rem;
            white-space: nowrap;
        }
        .prompt-vars-table tr:last-child td { border-bottom: none; }
        .prompt-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }
        .btn-secondary-action {
            background: white;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn-secondary-action:hover:not(:disabled) {
            background: #f1f5f9;
        }
    </style>
</head>
<body>
    <?php $headerContext = 'admin'; $isInSubfolder = true; include(__DIR__ . '/../components/top-header.php'); ?>

    <div class="container" style="max-width: 1400px; margin: 2rem auto; padding: 0 2rem;">
        <div class="admin-header" style="margin-bottom: 2rem;">
            <div>
                <h1 class="page-title"><?= __('settings.heading') ?></h1>
                <p style="color: var(--text-secondary); margin: 0.5rem 0 0;">
                    <?= __('settings.subtitle') ?>
                </p>
            </div>
        </div>

        <?php if (!$hasEncryption): ?>
        <div class="settings-warning">
            <?= __('settings.encryption_missing') ?>
            <code>SCOUTER_ENCRYPTION_KEY</code>
        </div>
        <?php endif; ?>

        <div class="settings-bento">

        <!-- ================== AI Provider (OpenRouter) ================== -->
        <div class="settings-card">
            <h2>
                <span class="material-symbols-outlined">auto_awesome</span>
                <?= __('settings.ai_section_title') ?>
            </h2>
            <p class="card-subtitle"><?= __('settings.ai_section_subtitle') ?></p>

            <div class="settings-row">
                <label for="api-key">
                    <?= __('settings.api_key_label') ?>
                    <span class="sub-label"><?= __('settings.api_key_hint') ?></span>
                </label>
                <input type="password" id="api-key"
                       placeholder="<?= $maskedKey !== '' ? htmlspecialchars($maskedKey) : 'sk-or-v1-...' ?>"
                       autocomplete="off" <?= !$hasEncryption ? 'disabled' : '' ?>>
                <button type="button" class="btn" id="btn-test-key"
                        <?= !$hasEncryption ? 'disabled' : '' ?>>
                    <span class="material-symbols-outlined">science</span>
                    <?= __('settings.btn_test') ?>
                </button>
            </div>
            <div class="settings-status" id="test-status"></div>

            <div class="account-card" id="account-info">
                <span class="material-symbols-outlined" style="vertical-align: -4px;">verified</span>
                <span class="label" id="account-label"></span>
                <span class="credit" id="account-credit"></span>
            </div>

            <div class="settings-row stacked" style="margin-top: 1.5rem;">
                <label>
                    <?= __('settings.model_light_label') ?>
                    <span class="sub-label"><?= __('settings.model_light_hint') ?></span>
                </label>
                <div class="dr-combo" id="combo-light" data-open="false">
                    <input type="hidden" id="model-light" value="<?= htmlspecialchars($modelLight) ?>">
                    <button type="button" class="dr-combo-trigger" <?= !$hasEncryption ? 'disabled' : '' ?>>
                        <span class="dr-combo-display placeholder">
                            <?= $modelLight !== '' ? htmlspecialchars($modelLight) : __('settings.model_placeholder') ?>
                        </span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </button>
                    <div class="dr-combo-dropdown">
                        <div class="dr-combo-search-wrap">
                            <div class="dr-combo-search-field">
                                <span class="material-symbols-outlined dr-combo-search-icon">search</span>
                                <input type="text" class="dr-combo-search"
                                       placeholder="<?= __('settings.combo_search_placeholder') ?>"
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="dr-combo-list" role="listbox"></div>
                    </div>
                </div>
                <span></span>
            </div>

            <div class="settings-row stacked" style="margin-top: 1.5rem;">
                <label>
                    <?= __('settings.model_strong_label') ?>
                    <span class="sub-label"><?= __('settings.model_strong_hint') ?></span>
                </label>
                <div class="dr-combo" id="combo-strong" data-open="false">
                    <input type="hidden" id="model-strong" value="<?= htmlspecialchars($modelStrong) ?>">
                    <button type="button" class="dr-combo-trigger" <?= !$hasEncryption ? 'disabled' : '' ?>>
                        <span class="dr-combo-display placeholder">
                            <?= $modelStrong !== '' ? htmlspecialchars($modelStrong) : __('settings.model_placeholder') ?>
                        </span>
                        <span class="material-symbols-outlined">expand_more</span>
                    </button>
                    <div class="dr-combo-dropdown">
                        <div class="dr-combo-search-wrap">
                            <div class="dr-combo-search-field">
                                <span class="material-symbols-outlined dr-combo-search-icon">search</span>
                                <input type="text" class="dr-combo-search"
                                       placeholder="<?= __('settings.combo_search_placeholder') ?>"
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="dr-combo-list" role="listbox"></div>
                    </div>
                </div>
                <span></span>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-primary-action" id="btn-save"
                        <?= !$hasEncryption ? 'disabled' : '' ?>>
                    <span class="material-symbols-outlined">save</span>
                    <?= __('settings.btn_save') ?>
                </button>
            </div>
        </div>

        <!-- ================== Dr. Brief system prompt ================== -->
        <div class="settings-card">
            <h2>
                <span class="material-symbols-outlined">edit_note</span>
                <?= __('settings.prompt_section_title') ?>
            </h2>
            <p class="card-subtitle"><?= __('settings.prompt_section_subtitle') ?></p>

            <div class="prompt-editor-row">
                <label for="dr-brief-prompt">
                    <?= __('settings.prompt_label') ?>
                    <span class="sub-label"><?= __('settings.prompt_hint') ?></span>
                </label>
                <div class="md-editor" id="md-editor">
                    <div class="md-toolbar">
                        <button type="button" class="md-tb-btn bold"   data-md-action="bold"     title="<?= __('settings.md_bold') ?>">B</button>
                        <button type="button" class="md-tb-btn italic" data-md-action="italic"   title="<?= __('settings.md_italic') ?>">I</button>
                        <span class="md-tb-sep"></span>
                        <button type="button" class="md-tb-btn"        data-md-action="h2"       title="<?= __('settings.md_h2') ?>">H2</button>
                        <button type="button" class="md-tb-btn"        data-md-action="h3"       title="<?= __('settings.md_h3') ?>">H3</button>
                        <span class="md-tb-sep"></span>
                        <button type="button" class="md-tb-btn"        data-md-action="ul"       title="<?= __('settings.md_list') ?>">
                            <span class="material-symbols-outlined">format_list_bulleted</span>
                        </button>
                        <button type="button" class="md-tb-btn"        data-md-action="code"     title="<?= __('settings.md_code') ?>">
                            <span class="material-symbols-outlined">code</span>
                        </button>
                        <button type="button" class="md-tb-btn"        data-md-action="codeblock" title="<?= __('settings.md_codeblock') ?>">
                            <span class="material-symbols-outlined">data_object</span>
                        </button>
                        <button type="button" class="md-tb-btn"        data-md-action="link"     title="<?= __('settings.md_link') ?>">
                            <span class="material-symbols-outlined">link</span>
                        </button>
                        <span class="md-tb-spacer"></span>
                        <div class="md-view-toggle" id="md-view-toggle">
                            <button type="button" data-md-view="edit"><?= __('settings.md_view_edit') ?></button>
                            <button type="button" data-md-view="preview" class="active"><?= __('settings.md_view_preview') ?></button>
                        </div>
                    </div>
                    <div class="md-pane mode-preview" id="md-pane">
                        <textarea id="dr-brief-prompt" class="md-edit" spellcheck="false"></textarea>
                        <div class="md-preview" id="md-preview"></div>
                    </div>
                </div>
            </div>

            <div class="prompt-vars-card">
                <p class="vars-title">
                    <span class="material-symbols-outlined" style="font-size: 1rem;">data_object</span>
                    <?= __('settings.prompt_vars_title') ?>
                </p>
                <p class="vars-intro"><?= __('settings.prompt_vars_intro') ?></p>
                <table class="prompt-vars-table" id="prompt-vars-table">
                    <thead>
                        <tr>
                            <th><?= __('settings.prompt_vars_col_name') ?></th>
                            <th><?= __('settings.prompt_vars_col_desc') ?></th>
                            <th><?= __('settings.prompt_vars_col_example') ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="settings-status" id="prompt-status"></div>

            <div class="prompt-actions">
                <button type="button" class="btn btn-secondary-action" id="btn-prompt-reset">
                    <span class="material-symbols-outlined">restart_alt</span>
                    <?= __('settings.prompt_btn_reset') ?>
                </button>
                <button type="button" class="btn btn-primary-action" id="btn-prompt-save">
                    <span class="material-symbols-outlined">save</span>
                    <?= __('settings.prompt_btn_save') ?>
                </button>
            </div>
        </div>

        <!-- ============ AI BUDGET ============ -->
        <div class="settings-card" style="grid-column: 1 / -1;">
            <h2><span class="material-symbols-outlined">payments</span> <?= __('settings.budget_title') ?></h2>

            <div style="display:flex; align-items:flex-end; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem;">
                <div style="flex:0 0 auto;">
                    <label for="budget-default" style="display:block; font-size:0.85rem; color:var(--text-secondary); margin-bottom:0.3rem;"><?= __('settings.budget_default') ?></label>
                    <input type="number" id="budget-default" min="0" step="0.01" value="<?= htmlspecialchars(number_format($defaultBudget, 2, '.', '')) ?>"
                           style="width:160px; padding:0.5rem 0.7rem; border:1px solid var(--border-color,#cbd5e1); border-radius:8px; font-size:1rem;">
                </div>
                <div style="font-size:0.8rem; color:var(--text-secondary); flex:1; min-width:200px;"><?= __('settings.budget_hint') ?></div>
                <button type="button" id="budget-save-btn" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <span class="material-symbols-outlined">save</span> <?= __('settings.budget_save') ?>
                </button>
            </div>

            <h3 style="font-size:0.95rem; margin:0 0 0.7rem;"><?= __('settings.budget_global_title') ?></h3>
            <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.5rem;">
                <?php foreach ($globalBreakdown as $feat => $cost): ?>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:0.6rem 0.9rem; min-width:140px;">
                    <div style="font-size:0.78rem; color:var(--text-secondary);"><?= htmlspecialchars($budgetFeatureLabel($feat)) ?></div>
                    <div style="font-size:1.1rem; font-weight:700; font-variant-numeric:tabular-nums;">$<?= number_format((float)$cost, 4) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <h3 style="font-size:0.95rem; margin:0 0 0.7rem;"><?= __('settings.budget_per_user') ?></h3>
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <thead><tr style="text-align:left; color:var(--text-secondary);">
                    <th style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;">Email</th>
                    <th style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('header.user_role') ?></th>
                    <th style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb; text-align:right;"><?= __('profile.spent') ?></th>
                    <th style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.budget_override') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($budgetByUser as $u):
                    $uid = (int)$u['id'];
                    $ov  = $userOverrides[$uid] ?? null;
                ?>
                    <tr>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5;"><?= htmlspecialchars($u['email']) ?></td>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5; color:var(--text-secondary);"><?= htmlspecialchars($u['role']) ?></td>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5; text-align:right; font-variant-numeric:tabular-nums;">$<?= number_format((float)$u['spent'], 4) ?></td>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5;">
                            <input type="number" min="0" step="0.01" class="budget-override" data-user-id="<?= $uid ?>"
                                   value="<?= $ov !== null ? htmlspecialchars(number_format((float)$ov, 2, '.', '')) : '' ?>"
                                   placeholder="<?= htmlspecialchars(number_format($defaultBudget, 2, '.', '')) ?>"
                                   style="width:110px; padding:0.35rem 0.5rem; border:1px solid #cbd5e1; border-radius:6px;">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="budget-save-status" style="margin-top:0.6rem; font-size:0.85rem;"></div>
        </div>

        <!-- ============ API & ACCESS KEYS ============ -->
        <div class="settings-card" style="grid-column: 1 / -1;">
            <h2><span class="material-symbols-outlined">key</span> <?= __('settings.api_title') ?></h2>
            <p class="card-subtitle"><?= __('settings.api_keys_subtitle') ?></p>

            <div style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center; margin:1rem 0;">
                <input id="apiKeyName" type="text" placeholder="<?= htmlspecialchars(__('settings.api_name_ph')) ?>"
                       style="flex:1; min-width:200px; padding:0.5rem 0.7rem; border:1px solid #cbd5e1; border-radius:8px;">
                <button type="button" id="apiKeyGenBtn" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <span class="material-symbols-outlined">add</span> <?= __('settings.api_generate') ?>
                </button>
            </div>

            <!-- Show-once token (revealed after generation) -->
            <div id="apiNewKey" style="display:none; background:#f0fdf4; border:1px solid #86efac; border-radius:10px; padding:0.9rem 1.1rem; margin-bottom:1rem;">
                <div style="font-size:0.85rem; color:#166534; margin-bottom:0.5rem;"><?= __('settings.api_token_once') ?></div>
                <div style="display:flex; gap:0.5rem; align-items:center;">
                    <code id="apiNewKeyVal" style="flex:1; font-family:ui-monospace,monospace; font-size:0.85rem; background:#fff; border:1px solid #d1fae5; border-radius:6px; padding:0.5rem 0.7rem; overflow-x:auto; white-space:nowrap;"></code>
                    <button type="button" id="apiCopyBtn" class="btn btn-secondary" style="white-space:nowrap;"><?= __('settings.api_copy') ?></button>
                </div>
            </div>

            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <thead><tr style="text-align:left; color:var(--text-secondary);">
                    <th style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.api_col_name') ?></th>
                    <th style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.api_col_prefix') ?></th>
                    <th style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.api_col_created') ?></th>
                    <th style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.api_col_used') ?></th>
                    <th style="border-bottom:1px solid #e5e7eb;"></th>
                </tr></thead>
                <tbody id="apiKeysBody"></tbody>
            </table>

            <div style="margin-top:1.5rem; display:flex; align-items:center; gap:0.8rem; flex-wrap:wrap;">
                <a href="../api-docs.php" target="_blank" rel="noopener" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <span class="material-symbols-outlined">api</span> <?= __('settings.api_open_docs') ?>
                </a>
                <span style="font-size:0.85rem; color:var(--text-secondary);">
                    Base : <code id="apiDocBase">…/api/v1</code> · <code>Authorization: Bearer sctr_…</code>
                </span>
            </div>
            <details style="margin-top:1rem;">
                <summary style="cursor:pointer; font-weight:600; color:var(--text-primary);"><?= __('settings.api_doc_title') ?> — curl</summary>
                <pre style="margin-top:0.8rem; background:#0f172a; color:#e2e8f0; border-radius:8px; padding:0.9rem 1.1rem; overflow-x:auto; font-size:0.78rem; line-height:1.55;"><code id="apiDocCurl"></code></pre>
            </details>
        </div>

        </div><!-- /.settings-bento -->
    </div><!-- /.container -->

    <script>
    // API keys management (admin). Talks to the session-authenticated /api/keys.
    (function () {
        const body   = document.getElementById('apiKeysBody');
        const genBtn = document.getElementById('apiKeyGenBtn');
        const nameIn = document.getElementById('apiKeyName');
        const newBox = document.getElementById('apiNewKey');
        const newVal = document.getElementById('apiNewKeyVal');
        const copyBtn= document.getElementById('apiCopyBtn');
        if (!body) return;

        const T = {
            none:   <?= json_encode(__('settings.api_no_keys')) ?>,
            revoke: <?= json_encode(__('settings.api_revoke')) ?>,
            confirm:<?= json_encode(__('settings.api_revoke_confirm')) ?>,
            copy:   <?= json_encode(__('settings.api_copy')) ?>,
            copied: <?= json_encode(__('settings.api_copied')) ?>,
        };
        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

        async function loadKeys() {
            try {
                const r = await fetch('../api/keys', { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                const keys = d.keys || [];
                if (!keys.length) {
                    body.innerHTML = '<tr><td colspan="5" style="padding:1rem 0.5rem; color:var(--text-secondary);">' + esc(T.none) + '</td></tr>';
                    return;
                }
                body.innerHTML = keys.map(k => `
                    <tr>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5;">${esc(k.name)}</td>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5;"><code>${esc(k.prefix)}…</code></td>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5; color:var(--text-secondary);">${esc((k.created_at||'').slice(0,10))}</td>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5; color:var(--text-secondary);">${esc(k.last_used_at ? k.last_used_at.slice(0,16) : '—')}</td>
                        <td style="padding:0.4rem 0.5rem; border-bottom:1px solid #f5f5f5; text-align:right;">
                            <button type="button" class="btn btn-sm" data-revoke="${k.id}" style="color:#dc2626; background:none; border:1px solid #fecaca; border-radius:6px; padding:0.2rem 0.6rem; cursor:pointer;">${esc(T.revoke)}</button>
                        </td>
                    </tr>`).join('');
                body.querySelectorAll('[data-revoke]').forEach(btn => {
                    btn.addEventListener('click', () => revokeKey(btn.dataset.revoke));
                });
            } catch (e) { /* silent */ }
        }

        async function createKey() {
            genBtn.disabled = true;
            try {
                const r = await fetch('../api/keys', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: nameIn.value.trim() }),
                });
                const d = await r.json();
                if (d.success !== false && d.token) {
                    newVal.textContent = d.token;
                    newBox.style.display = 'block';
                    nameIn.value = '';
                    loadKeys();
                }
            } finally { genBtn.disabled = false; }
        }

        async function revokeKey(id) {
            if (!confirm(T.confirm)) return;
            await fetch('../api/keys/' + encodeURIComponent(id), { method: 'DELETE', headers: { 'Accept': 'application/json' } });
            loadKeys();
        }

        genBtn.addEventListener('click', createKey);
        copyBtn.addEventListener('click', () => {
            navigator.clipboard.writeText(newVal.textContent).then(() => {
                copyBtn.textContent = T.copied;
                setTimeout(() => { copyBtn.textContent = T.copy; }, 1500);
            });
        });

        // Live doc: base URL + curl cheatsheet for this host.
        const base = location.origin + '/api/v1';
        document.getElementById('apiDocBase').textContent = base;
        document.getElementById('apiDocCurl').textContent =
            '# List projects\n' +
            'curl -H "Authorization: Bearer $TOKEN" ' + base + '/projects\n\n' +
            '# Crawls of a project\n' +
            'curl -H "Authorization: Bearer $TOKEN" ' + base + '/projects/{id}/crawls\n\n' +
            '# Crawl metadata + schema (column names for valid SQL)\n' +
            'curl -H "Authorization: Bearer $TOKEN" ' + base + '/crawls/{id}\n' +
            'curl -H "Authorization: Bearer $TOKEN" ' + base + '/crawls/{id}/schema\n\n' +
            '# Read-only SQL, paginated — loop pages while meta.has_more is true\n' +
            'curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \\\n' +
            '  -d \'{"query":"SELECT url, code FROM pages WHERE code >= 400","page":1,"page_size":500}\' \\\n' +
            '  ' + base + '/crawls/{id}/query';

        loadKeys();
    })();
    </script>

    <script>
    // AI budget save (default + per-user overrides).
    (function () {
        const btn = document.getElementById('budget-save-btn');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            const overrides = {};
            document.querySelectorAll('.budget-override').forEach(inp => {
                overrides[inp.dataset.userId] = inp.value === '' ? null : inp.value;
            });
            const statusEl = document.getElementById('budget-save-status');
            btn.disabled = true;
            statusEl.textContent = '…';
            try {
                const res = await fetch('../api/settings/budget', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        default_budget: document.getElementById('budget-default').value,
                        overrides: overrides,
                    }),
                });
                const d = await res.json();
                if (!res.ok || d.success === false) throw new Error(d.error || 'error');
                statusEl.style.color = '#16a34a';
                statusEl.textContent = '✓';
            } catch (e) {
                statusEl.style.color = '#dc2626';
                statusEl.textContent = '✗ ' + (e.message || 'error');
            } finally {
                btn.disabled = false;
            }
        });
    })();
    </script>

    <script>
    (function () {
        const apiKeyInput   = document.getElementById('api-key');
        const lightHidden   = document.getElementById('model-light');
        const strongHidden  = document.getElementById('model-strong');
        const lightCombo    = document.getElementById('combo-light');
        const strongCombo   = document.getElementById('combo-strong');
        const testBtn       = document.getElementById('btn-test-key');
        const saveBtn       = document.getElementById('btn-save');
        const testStatus    = document.getElementById('test-status');
        const accountCard   = document.getElementById('account-info');
        const accountLabel  = document.getElementById('account-label');
        const accountCredit = document.getElementById('account-credit');

        const T = {
            testing:        <?= json_encode(__('settings.status_testing')) ?>,
            test_ok:        <?= json_encode(__('settings.status_test_ok')) ?>,
            test_fail:      <?= json_encode(__('settings.status_test_fail')) ?>,
            saving:         <?= json_encode(__('settings.status_saving')) ?>,
            saved:          <?= json_encode(__('settings.status_saved')) ?>,
            save_fail:      <?= json_encode(__('settings.status_save_fail')) ?>,
            no_key:         <?= json_encode(__('settings.status_no_key')) ?>,
            no_model:       <?= json_encode(__('settings.status_no_model')) ?>,
            credit_unlimited: <?= json_encode(__('settings.credit_unlimited')) ?>,
            credit_remaining: <?= json_encode(__('settings.credit_remaining')) ?>,
            credit_usage:     <?= json_encode(__('settings.credit_usage')) ?>,
            model_ctx:        <?= json_encode(__('settings.model_ctx')) ?>,
            model_price:      <?= json_encode(__('settings.model_price')) ?>,
            model_free:       <?= json_encode(__('settings.model_free')) ?>,
            combo_no_results: <?= json_encode(__('settings.combo_no_results')) ?>,
            combo_loading:    <?= json_encode(__('settings.combo_loading')) ?>,
            combo_no_models:  <?= json_encode(__('settings.combo_no_models')) ?>,
            auto_loading:     <?= json_encode(__('settings.auto_loading')) ?>,
            auto_loaded:      <?= json_encode(__('settings.auto_loaded')) ?>,
            prompt_loading:        <?= json_encode(__('settings.prompt_status_loading')) ?>,
            prompt_load_fail:      <?= json_encode(__('settings.prompt_status_load_fail')) ?>,
            prompt_saving:         <?= json_encode(__('settings.prompt_status_saving')) ?>,
            prompt_saved_custom:   <?= json_encode(__('settings.prompt_status_saved_custom')) ?>,
            prompt_saved_default:  <?= json_encode(__('settings.prompt_status_saved_default')) ?>,
            prompt_save_fail:      <?= json_encode(__('settings.prompt_status_save_fail')) ?>,
            prompt_reset_done:     <?= json_encode(__('settings.prompt_status_reset_done')) ?>,
        };

        const HAS_STORED_KEY = <?= $hasStoredKey ? 'true' : 'false' ?>;
        const CURRENT_LIGHT  = <?= json_encode($modelLight) ?>;
        const CURRENT_STRONG = <?= json_encode($modelStrong) ?>;

        let lastModels = [];

        function setStatus(el, cls, msg) {
            el.className = 'settings-status ' + cls;
            el.textContent = msg;
        }

        function fmtUSD(n) {
            if (n === null || n === undefined) return '';
            return Math.abs(n) < 0.01 ? '$' + n.toFixed(6) : '$' + n.toFixed(2);
        }
        function pricePerMillion(price) {
            const perM = price * 1_000_000;
            return Math.abs(perM) < 0.01 ? '$' + perM.toFixed(4) : '$' + perM.toFixed(2);
        }

        // === Custom combobox ===
        // Re-built whenever the model list changes (after a /test). Tracks
        // selection via the hidden input value so the save handler reads it
        // like any normal form field.
        function makeCombo(rootEl, hiddenInput, opts) {
            const trigger    = rootEl.querySelector('.dr-combo-trigger');
            const display    = rootEl.querySelector('.dr-combo-display');
            const dropdown   = rootEl.querySelector('.dr-combo-dropdown');
            const searchInp  = rootEl.querySelector('.dr-combo-search');
            const list       = rootEl.querySelector('.dr-combo-list');

            let models       = [];
            let visible      = [];
            let activeIndex  = -1;

            function describeModel(m) {
                const parts = [];
                if (m.context_length) {
                    const ctxK = Math.round(m.context_length / 1000);
                    parts.push({ cls: 'ctx', text: T.model_ctx.replace('{ctx}', ctxK + 'k') });
                }
                const isFree = (m.prompt_price === 0 && m.completion_price === 0);
                if (isFree) {
                    parts.push({ cls: 'free', text: T.model_free });
                } else {
                    parts.push({ cls: 'price', text: T.model_price
                        .replace('{in}',  pricePerMillion(m.prompt_price))
                        .replace('{out}', pricePerMillion(m.completion_price)) });
                }
                return parts;
            }

            function matches(m, q) {
                if (!q) return true;
                const haystack = (m.id + ' ' + m.name).toLowerCase();
                // Every space-separated token must appear → fuzzy enough for
                // queries like "claude opus" matching "anthropic/claude-opus-4".
                return q.toLowerCase().trim().split(/\s+/).every(t => haystack.includes(t));
            }

            function render(query) {
                list.innerHTML = '';
                const matched = models.filter(m => matches(m, query));
                if (!matched.length) {
                    const empty = document.createElement('div');
                    empty.className = 'dr-combo-empty';
                    empty.textContent = T.combo_no_results;
                    list.appendChild(empty);
                    visible = [];
                    activeIndex = -1;
                    return;
                }
                // Group by provider prefix for scannability.
                const groups = {};
                matched.forEach(m => {
                    const provider = (m.id.split('/')[0] || 'other');
                    (groups[provider] = groups[provider] || []).push(m);
                });
                visible = [];
                Object.keys(groups).sort().forEach(provider => {
                    const header = document.createElement('div');
                    header.className = 'dr-combo-group';
                    header.textContent = provider;
                    list.appendChild(header);
                    groups[provider].forEach(m => {
                        const item = document.createElement('div');
                        item.className = 'dr-combo-item';
                        if (m.id === hiddenInput.value) item.classList.add('selected');
                        item.setAttribute('role', 'option');
                        item.dataset.id = m.id;

                        const name = document.createElement('div');
                        name.className = 'dr-combo-item-name';
                        name.textContent = m.name;
                        item.appendChild(name);

                        const id = document.createElement('div');
                        id.className = 'dr-combo-item-id';
                        id.textContent = m.id;
                        item.appendChild(id);

                        const meta = document.createElement('div');
                        meta.className = 'dr-combo-item-meta';
                        describeModel(m).forEach(p => {
                            const s = document.createElement('span');
                            s.className = p.cls;
                            s.textContent = p.text;
                            meta.appendChild(s);
                        });
                        item.appendChild(meta);

                        item.addEventListener('mousedown', e => {
                            // mousedown so the trigger blur doesn't close the
                            // dropdown before click fires.
                            e.preventDefault();
                            select(m);
                        });
                        list.appendChild(item);
                        visible.push({ model: m, el: item });
                    });
                });
                // Prefer the currently selected item as active, else the first.
                activeIndex = visible.findIndex(v => v.model.id === hiddenInput.value);
                if (activeIndex < 0) activeIndex = 0;
                highlight();
            }

            function highlight() {
                visible.forEach((v, i) => v.el.classList.toggle('active', i === activeIndex));
                if (activeIndex >= 0 && visible[activeIndex]) {
                    visible[activeIndex].el.scrollIntoView({ block: 'nearest' });
                }
            }

            function renderDisplay() {
                display.innerHTML = '';
                const m = models.find(x => x.id === hiddenInput.value);
                if (m) {
                    // Selected → one-line layout : the human-readable model
                    // name only. The slug + pricing + context are shown ONLY
                    // in the open dropdown (where the user is comparing),
                    // never on the closed trigger — the trigger needs to
                    // stay at standard form-field height.
                    display.classList.remove('placeholder');
                    display.textContent = m.name;
                    display.title = m.id;
                } else if (hiddenInput.value) {
                    // Value exists but the model isn't in the current list
                    // (no test run yet, or model was removed upstream).
                    display.classList.add('placeholder');
                    display.textContent = hiddenInput.value;
                    display.title = hiddenInput.value;
                } else {
                    display.classList.add('placeholder');
                    display.textContent = opts.placeholder || '';
                    display.title = '';
                }
            }

            function select(m) {
                hiddenInput.value = m.id;
                renderDisplay();
                close();
                trigger.focus();
            }

            function open() {
                if (trigger.disabled) return;
                rootEl.setAttribute('data-open', 'true');
                searchInp.value = '';
                render('');
                // Defer focus so the click that opened doesn't immediately blur.
                setTimeout(() => searchInp.focus(), 10);
            }
            function close() {
                rootEl.setAttribute('data-open', 'false');
            }

            trigger.addEventListener('click', () => {
                rootEl.getAttribute('data-open') === 'true' ? close() : open();
            });
            searchInp.addEventListener('input', () => render(searchInp.value));
            searchInp.addEventListener('keydown', e => {
                if (e.key === 'ArrowDown') {
                    activeIndex = Math.min(activeIndex + 1, visible.length - 1);
                    highlight();
                    e.preventDefault();
                } else if (e.key === 'ArrowUp') {
                    activeIndex = Math.max(activeIndex - 1, 0);
                    highlight();
                    e.preventDefault();
                } else if (e.key === 'Enter') {
                    if (visible[activeIndex]) select(visible[activeIndex].model);
                    e.preventDefault();
                } else if (e.key === 'Escape') {
                    close();
                    trigger.focus();
                }
            });
            document.addEventListener('mousedown', e => {
                if (!rootEl.contains(e.target)) close();
            });

            return {
                setModels(newModels) {
                    models = opts.filterToolsOnly
                        ? newModels.filter(m => m.supports_tools)
                        : newModels;
                    renderDisplay();
                },
            };
        }

        const lightHandle  = makeCombo(lightCombo,  lightHidden,  { filterToolsOnly: false, placeholder: <?= json_encode(__('settings.model_placeholder')) ?> });
        const strongHandle = makeCombo(strongCombo, strongHidden, { filterToolsOnly: true,  placeholder: <?= json_encode(__('settings.model_placeholder')) ?> });

        function applyAccountInfo(account) {
            if (!account) return;
            accountLabel.textContent = account.label || 'OpenRouter';
            let credit = '';
            if (account.limit !== null && account.limit !== undefined) {
                const remaining = account.limit - (account.usage || 0);
                credit = T.credit_remaining
                    .replace('{remaining}', fmtUSD(remaining))
                    .replace('{limit}',     fmtUSD(account.limit));
            } else if (account.usage !== null && account.usage !== undefined) {
                credit = T.credit_usage.replace('{used}', fmtUSD(account.usage));
            } else {
                credit = T.credit_unlimited;
            }
            accountCredit.textContent = credit;
            accountCard.classList.add('visible');
        }

        // First-time-setup defaults. Applied ONLY when the slot is still
        // empty (no model previously saved) AND the proposed default
        // actually exists in the OpenRouter catalog at test time. If a
        // default vanishes upstream (renamed, deprecated), we simply skip
        // the pre-selection and let the admin pick manually — no error,
        // no surprise.
        const DEFAULT_LIGHT  = 'openai/gpt-oss-safeguard-20b';
        const DEFAULT_STRONG = 'google/gemini-3.1-flash-lite-preview';

        function applyFirstTimeDefaults() {
            if (!lightHidden.value) {
                const found = lastModels.find(m => m.id === DEFAULT_LIGHT);
                if (found) lightHidden.value = DEFAULT_LIGHT;
            }
            if (!strongHidden.value) {
                const found = lastModels.find(m => m.id === DEFAULT_STRONG);
                // Strong slot is restricted to tool-capable models — only
                // pre-pick the default if it advertises that capability.
                if (found && found.supports_tools) strongHidden.value = DEFAULT_STRONG;
            }
        }

        async function runTest(apiKey, opts) {
            opts = opts || {};
            if (!opts.silent) setStatus(testStatus, 'info', T.testing);
            try {
                const res = await fetch('../api/settings/ai/test', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ api_key: apiKey || '' }),
                });
                const data = await res.json();
                if (!res.ok || data.success === false) {
                    setStatus(testStatus, 'err', T.test_fail + ' ' + (data.error || data.message || ''));
                    return false;
                }
                applyAccountInfo(data.account);
                lastModels = data.models || [];
                applyFirstTimeDefaults();
                lightHandle.setModels(lastModels);
                strongHandle.setModels(lastModels);
                setStatus(testStatus, opts.silent ? 'info' : 'ok',
                    (opts.silent ? T.auto_loaded : T.test_ok).replace('{count}', lastModels.length));
                return true;
            } catch (e) {
                setStatus(testStatus, 'err', T.test_fail + ' ' + e.message);
                return false;
            }
        }

        testBtn.addEventListener('click', () => runTest(apiKeyInput.value.trim()));

        saveBtn.addEventListener('click', async () => {
            const key    = apiKeyInput.value.trim();
            const light  = lightHidden.value;
            const strong = strongHidden.value;
            if (!light || !strong) {
                setStatus(testStatus, 'err', T.no_model);
                return;
            }
            setStatus(testStatus, 'info', T.saving);
            try {
                const body = { model_light: light, model_strong: strong };
                if (key) body.api_key = key;
                const res = await fetch('../api/settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                });
                const data = await res.json();
                if (!res.ok || data.success === false) {
                    setStatus(testStatus, 'err', T.save_fail + ' ' + (data.error || data.message || ''));
                    return;
                }
                apiKeyInput.value = '';
                if (data.api_key_masked) {
                    apiKeyInput.placeholder = data.api_key_masked;
                }
                setStatus(testStatus, 'ok', T.saved);
            } catch (e) {
                setStatus(testStatus, 'err', T.save_fail + ' ' + e.message);
            }
        });

        // === Auto-load on page load when a key is already stored ===
        // Saves the admin a click — pulls the model catalog + credit info
        // automatically so the selectors are usable immediately. Status is
        // shown in "info" tone (not "ok") to distinguish a passive auto-load
        // from an explicit Test action.
        if (HAS_STORED_KEY) {
            setStatus(testStatus, 'info', T.auto_loading);
            runTest('', { silent: true });
        }

        // === Dr. Brief system prompt editor ===
        // Loads the current template (custom override or default) into the
        // textarea, populates the variables documentation table, and wires
        // up the Reset / Save buttons. Kept as a separate IIFE-style block
        // so the rest of the settings code stays untouched.
        (function setupPromptEditor() {
            const promptTextarea = document.getElementById('dr-brief-prompt');
            const promptStatus   = document.getElementById('prompt-status');
            const promptResetBtn = document.getElementById('btn-prompt-reset');
            const promptSaveBtn  = document.getElementById('btn-prompt-save');
            const varsTableBody  = document.querySelector('#prompt-vars-table tbody');
            const mdPane         = document.getElementById('md-pane');
            const mdPreview      = document.getElementById('md-preview');
            const mdEditor       = document.getElementById('md-editor');
            const mdViewToggle   = document.getElementById('md-view-toggle');

            let defaultPrompt = '';

            // ----- Minimal markdown renderer (same family as the chat widget) -----
            function mdEscapeHtml(s) {
                return s.replace(/[&<>"']/g, c => ({
                    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                }[c]));
            }
            function renderPromptMarkdown(md) {
                if (!md) return '';
                let s = mdEscapeHtml(md);
                // Fenced code blocks first — pre-escaped above, just wrap.
                s = s.replace(/```([\w-]*)\n?([\s\S]*?)```/g,
                    (_, lang, code) => '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>');
                // Headers (most specific first so ###### isn't eaten by ###).
                s = s.replace(/^###### (.+)$/gm, '<h6>$1</h6>');
                s = s.replace(/^##### (.+)$/gm, '<h5>$1</h5>');
                s = s.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
                s = s.replace(/^### (.+)$/gm, '<h3>$1</h3>');
                s = s.replace(/^## (.+)$/gm, '<h2>$1</h2>');
                s = s.replace(/^# (.+)$/gm, '<h1>$1</h1>');
                // Unordered + ordered lists — done before inline so `*` of bullet
                // isn't mistaken for an italic opener.
                s = s.replace(/(?:^[-*+] .+(?:\n|$))+/gm, (block) => {
                    const items = block.trim().split('\n')
                        .map(l => '<li>' + l.replace(/^[-*+] /, '') + '</li>').join('');
                    return '<ul>' + items + '</ul>';
                });
                s = s.replace(/(?:^\d+\. .+(?:\n|$))+/gm, (block) => {
                    const items = block.trim().split('\n')
                        .map(l => '<li>' + l.replace(/^\d+\. /, '') + '</li>').join('');
                    return '<ol>' + items + '</ol>';
                });
                // Inline: links, bold, italic, inline code.
                s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
                s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
                s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
                s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
                // Highlight `{placeholder}` variables — done LAST so the regex
                // sees the post-transform text and applies to both inline and
                // code-block contexts (visually useful even inside <pre>).
                s = s.replace(/\{([a-z_][a-z0-9_]*)\}/g,
                    '<span class="md-placeholder">{$1}</span>');
                // Paragraphs : split on blank lines, wrap in <p> unless block.
                const blocks = s.split(/\n{2,}/);
                return blocks.map(b => {
                    const t = b.trim();
                    if (!t) return '';
                    if (/^<(h\d|ul|ol|pre|table|p|div|blockquote|hr)\b/.test(t)) return t;
                    return '<p>' + t.replace(/\n/g, '<br>') + '</p>';
                }).join('\n');
            }

            // ----- Live preview (debounced — markdown of a long template
            //       costs ~5ms but typing fires on every keystroke). -----
            let previewTimer = null;
            function schedulePreview() {
                if (previewTimer) clearTimeout(previewTimer);
                previewTimer = setTimeout(updatePreview, 80);
            }
            function updatePreview() {
                mdPreview.innerHTML = renderPromptMarkdown(promptTextarea.value);
            }
            promptTextarea.addEventListener('input', schedulePreview);

            // ----- View toggle (edit / split / preview) -----
            mdViewToggle.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-md-view]');
                if (!btn) return;
                const mode = btn.dataset.mdView;
                mdPane.classList.remove('mode-edit', 'mode-split', 'mode-preview');
                mdPane.classList.add('mode-' + mode);
                mdViewToggle.querySelectorAll('button').forEach(b =>
                    b.classList.toggle('active', b === btn));
                if (mode !== 'edit') updatePreview();
            });

            // ----- Toolbar : wrap/insert markdown markers at the selection. -----
            function wrapSelection(prefix, suffix, placeholder) {
                const t  = promptTextarea;
                const s  = t.selectionStart, e = t.selectionEnd;
                const sel = t.value.substring(s, e) || (placeholder || '');
                const before = t.value.substring(0, s);
                const after  = t.value.substring(e);
                t.value = before + prefix + sel + suffix + after;
                const newPos = before.length + prefix.length;
                t.focus();
                t.setSelectionRange(newPos, newPos + sel.length);
                schedulePreview();
            }
            function insertLineStart(marker, placeholder) {
                const t = promptTextarea;
                const s = t.selectionStart;
                const before = t.value.substring(0, s);
                const after  = t.value.substring(s);
                const lineStart = before.lastIndexOf('\n') + 1;
                const head = before.substring(0, lineStart);
                const line = before.substring(lineStart);
                const newLine = marker + (line || placeholder || '');
                t.value = head + newLine + after;
                const newPos = head.length + newLine.length;
                t.focus();
                t.setSelectionRange(newPos, newPos);
                schedulePreview();
            }
            mdEditor.querySelector('.md-toolbar').addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-md-action]');
                if (!btn) return;
                e.preventDefault();
                const action = btn.dataset.mdAction;
                switch (action) {
                    case 'bold':      wrapSelection('**', '**', 'texte en gras'); break;
                    case 'italic':    wrapSelection('*',  '*',  'texte en italique'); break;
                    case 'code':      wrapSelection('`',  '`',  'code'); break;
                    case 'codeblock': wrapSelection('\n```\n', '\n```\n', 'SELECT 1'); break;
                    case 'link':      wrapSelection('[',  '](https://)', 'texte du lien'); break;
                    case 'h2':        insertLineStart('## ',  'Titre'); break;
                    case 'h3':        insertLineStart('### ', 'Sous-titre'); break;
                    case 'ul':        insertLineStart('- ',   'élément'); break;
                }
            });

            async function loadInitial() {
                setStatus(promptStatus, 'info', T.prompt_loading);
                try {
                    const res = await fetch('../api/settings', { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (!res.ok || data.success === false) {
                        setStatus(promptStatus, 'err', T.prompt_load_fail + ' ' + (data.error || data.message || ''));
                        return;
                    }
                    defaultPrompt = data.dr_brief_prompt_default || '';
                    // Show the custom override if set, otherwise pre-fill with
                    // the default — so the admin can edit either way without
                    // having to click "Reset" first.
                    const custom = (data.dr_brief_prompt || '').trim();
                    promptTextarea.value = custom !== '' ? data.dr_brief_prompt : defaultPrompt;

                    renderVariables(data.dr_brief_variables || []);
                    updatePreview();
                    setStatus(promptStatus, '', '');
                } catch (e) {
                    setStatus(promptStatus, 'err', T.prompt_load_fail + ' ' + e.message);
                }
            }

            function renderVariables(vars) {
                varsTableBody.innerHTML = '';
                vars.forEach(v => {
                    const tr = document.createElement('tr');

                    const tdName = document.createElement('td');
                    const code   = document.createElement('code');
                    code.textContent = '{' + v.name + '}';
                    tdName.appendChild(code);
                    tr.appendChild(tdName);

                    const tdDesc = document.createElement('td');
                    tdDesc.textContent = v.description || '';
                    tr.appendChild(tdDesc);

                    const tdEx = document.createElement('td');
                    tdEx.style.color = '#64748b';
                    tdEx.style.fontFamily = 'ui-monospace, SFMono-Regular, Menlo, monospace';
                    tdEx.style.fontSize = '0.78rem';
                    tdEx.textContent = v.example || '';
                    tr.appendChild(tdEx);

                    varsTableBody.appendChild(tr);
                });
            }

            promptResetBtn.addEventListener('click', () => {
                promptTextarea.value = defaultPrompt;
                updatePreview();
                setStatus(promptStatus, 'info', T.prompt_reset_done);
            });

            promptSaveBtn.addEventListener('click', async () => {
                setStatus(promptStatus, 'info', T.prompt_saving);
                // If the textarea equals the default verbatim, persist an empty
                // string instead — that way build() falls back to whatever the
                // baked-in default is at the time of the call, which means
                // future improvements to the default propagate automatically.
                const editedValue = promptTextarea.value;
                const valueToSave = (editedValue.trim() === defaultPrompt.trim()) ? '' : editedValue;
                try {
                    const res = await fetch('../api/settings/ai/prompt', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ prompt: valueToSave }),
                    });
                    const data = await res.json();
                    if (!res.ok || data.success === false) {
                        setStatus(promptStatus, 'err', T.prompt_save_fail + ' ' + (data.error || data.message || ''));
                        return;
                    }
                    setStatus(promptStatus, 'ok',
                        data.has_custom_prompt ? T.prompt_saved_custom : T.prompt_saved_default);
                } catch (e) {
                    setStatus(promptStatus, 'err', T.prompt_save_fail + ' ' + e.message);
                }
            });

            loadInitial();
        })();
    })();
    </script>
</body>
</html>
