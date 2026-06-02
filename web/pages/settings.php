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
// API & MCP key management is open to EVERY authenticated user: each user only
// ever sees and manages their OWN keys, and any key acts strictly within its
// owner's role/permissions. The AI provider, budget and team-management
// sections stay admin-only and are rendered conditionally on $isAdmin below.
$auth->requireLoginOrRedirect();
$isAdmin = $auth->isAdmin();

use App\Settings\AppSettings;
use App\AI\BudgetService;

// Everything below feeds the admin-only AI / budget / team sections. Skip it
// entirely for non-admins so other users' data is never computed or exposed.
if ($isAdmin) {
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

// --- Merged Team & Budgets data (one row per user: identity + month spend + override) ---
$currentUserId = (int)$auth->getCurrentUserId();
$teamMembers = [];
try {
    $_tm = \App\Database\PostgresDatabase::getInstance()->getConnection()->query("
        SELECT u.id, u.email, u.role, u.created_at,
               u.ai_monthly_budget_usd AS override,
               COALESCE((
                   SELECT SUM(cost_usd) FROM ai_usage a
                   WHERE a.user_id = u.id
                     AND a.created_at >= date_trunc('month', CURRENT_TIMESTAMP)
               ), 0) AS spent
        FROM users u
        ORDER BY u.created_at ASC
    ");
    $teamMembers = $_tm->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { $teamMembers = []; }
} // end if ($isAdmin)
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
    <!-- CodeMirror 5 (bundled locally, no CDN at runtime) — powers the API explorer
         body editor, the syntax-highlighted response, and the multi-language code
         snippets. Modes are loaded dependency-first (php needs clike + htmlmixed). -->
    <link rel="stylesheet" href="../assets/vendor/codemirror/codemirror.min.css">
    <link rel="stylesheet" href="../assets/vendor/codemirror/theme/material-darker.min.css">
    <link rel="stylesheet" href="../assets/vendor/codemirror/addon/foldgutter.min.css">
    <script src="../assets/vendor/codemirror/codemirror.min.js"></script>
    <script src="../assets/vendor/codemirror/mode/xml.min.js"></script>
    <script src="../assets/vendor/codemirror/mode/css.min.js"></script>
    <script src="../assets/vendor/codemirror/mode/javascript.min.js"></script>
    <script src="../assets/vendor/codemirror/mode/clike.min.js"></script>
    <script src="../assets/vendor/codemirror/mode/htmlmixed.min.js"></script>
    <script src="../assets/vendor/codemirror/addon/multiplex.min.js"></script>
    <script src="../assets/vendor/codemirror/addon/overlay.min.js"></script>
    <script src="../assets/vendor/codemirror/mode/php.min.js"></script>
    <script src="../assets/vendor/codemirror/mode/python.min.js"></script>
    <script src="../assets/vendor/codemirror/mode/shell.min.js"></script>
    <script src="../assets/vendor/codemirror/addon/foldcode.min.js"></script>
    <script src="../assets/vendor/codemirror/addon/foldgutter.min.js"></script>
    <script src="../assets/vendor/codemirror/addon/brace-fold.min.js"></script>
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

        /* ===== Tabs ===== */
        .settings-tabs-nav {
            display: flex; gap: 0.25rem; flex-wrap: wrap;
            border-bottom: 1px solid #e5e7eb; margin-bottom: 1.5rem;
        }
        .settings-tab-btn {
            padding: 0.7rem 1.1rem; border: none; background: none; cursor: pointer;
            font-size: 0.95rem; font-weight: 600; color: var(--text-secondary);
            border-bottom: 2px solid transparent; margin-bottom: -1px;
            display: inline-flex; align-items: center; gap: 0.45rem; font-family: inherit;
            transition: color 0.15s ease, border-color 0.15s ease;
        }
        .settings-tab-btn:hover { color: var(--text-primary); }
        .settings-tab-btn.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
        .settings-tab-btn .material-symbols-outlined { font-size: 1.15rem; }
        .settings-tab { display: none; }
        .settings-tab.active { display: block; }
        /* Stack cards vertically inside a tab (full width). */
        .settings-tab .settings-card { margin-bottom: 1.5rem; }

        /* ===== Sticky save footer (one per active tab) ===== */
        .settings-sticky-footer {
            position: sticky; bottom: 0; z-index: 20;
            display: flex; align-items: center; justify-content: flex-end; gap: 1rem;
            padding: 0.9rem 1.2rem; margin: 1rem -2rem 0;
            background: rgba(255,255,255,0.92); backdrop-filter: blur(6px);
            border-top: 1px solid #e5e7eb;
        }
        .settings-sticky-footer .save-hint { margin-right: auto; font-size: 0.85rem; color: #b45309; display: none; }
        .settings-sticky-footer.is-dirty .save-hint { display: inline; }
        .settings-sticky-footer .btn[disabled] { opacity: 0.5; cursor: not-allowed; }
        /* The single sticky footer replaces the old per-card save buttons. */
        #btn-save, #btn-prompt-save, #budget-save-btn { display: none !important; }
        /* Delete user: muted at rest, red only on hover (less aggressive). */
        .user-del-btn { color: #94a3b8; transition: color 0.15s ease; }
        .user-del-btn:hover { color: #dc2626; }

        /* ===== Table alignment helpers (spec §4) ===== */
        .num-cell { text-align: right; font-variant-numeric: tabular-nums; }
        .text-cell { text-align: left; }

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

        <?php if ($isAdmin && !$hasEncryption): ?>
        <div class="settings-warning">
            <?= __('settings.encryption_missing') ?>
            <code>SCOUTER_ENCRYPTION_KEY</code>
        </div>
        <?php endif; ?>

        <nav class="settings-tabs-nav" id="settingsTabsNav">
            <?php if ($isAdmin): ?>
            <button type="button" class="settings-tab-btn active" data-tab="ai">
                <span class="material-symbols-outlined">smart_toy</span> <?= __('settings.tab_ai') ?>
            </button>
            <button type="button" class="settings-tab-btn" data-tab="team">
                <span class="material-symbols-outlined">group</span> <?= __('settings.tab_team') ?>
            </button>
            <?php endif; ?>
            <button type="button" class="settings-tab-btn<?= $isAdmin ? '' : ' active' ?>" data-tab="api">
                <span class="material-symbols-outlined">vpn_key</span> <?= __('settings.tab_api') ?>
            </button>
        </nav>

        <?php if ($isAdmin): ?>
        <section class="settings-tab active" data-tab="ai">

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

            <details class="prompt-vars-card">
                <summary class="vars-title" style="cursor: pointer; list-style: revert;">
                    <span class="material-symbols-outlined" style="font-size: 1rem; vertical-align: -3px;">data_object</span>
                    <?= __('settings.vars_toggle') ?>
                </summary>
                <p class="vars-intro" style="margin-top: 0.6rem;"><?= __('settings.prompt_vars_intro') ?></p>
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
            </details>

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

        <div class="settings-sticky-footer" data-save-tab="ai">
            <span class="save-hint" data-save-hint><?= __('settings.unsaved_warning') ?></span>
            <button type="button" class="btn btn-primary-action" data-save-btn disabled>
                <span class="material-symbols-outlined">save</span> <?= __('settings.save_changes') ?>
            </button>
        </div>
        </section><!-- /tab ai -->
        <?php endif; ?>

        <?php if ($isAdmin): ?>
        <section class="settings-tab" data-tab="team">
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

            <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin:0 0 0.7rem;">
                <h3 style="font-size:0.95rem; margin:0;"><?= __('settings.team_title') ?></h3>
                <button type="button" id="addUserBtn" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <span class="material-symbols-outlined">person_add</span> <?= __('settings.add_user') ?>
                </button>
            </div>
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <thead><tr style="color:var(--text-secondary);">
                    <th class="text-cell" style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.api_col_name') ?></th>
                    <th class="text-cell" style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('header.user_role') ?></th>
                    <th class="num-cell"  style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.col_created') ?></th>
                    <th class="num-cell"  style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.col_spent_month') ?></th>
                    <th class="num-cell"  style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.budget_override') ?></th>
                    <th class="num-cell"  style="padding:0.4rem 0.5rem; border-bottom:1px solid #e5e7eb;"><?= __('settings.col_actions') ?></th>
                </tr></thead>
                <tbody>
                <?php
                $roleBadges = [
                    'admin'  => ['shield_person', '#7c3aed', __('admin.role_admin')],
                    'user'   => ['person', '#0891b2', __('admin.role_user')],
                    'viewer' => ['visibility', '#64748b', __('admin.role_viewer')],
                ];
                foreach ($teamMembers as $m):
                    $uid = (int)$m['id'];
                    $role = $m['role'] ?? 'user';
                    $isSelf = $uid === $currentUserId;
                    $hasBudget = in_array($role, ['admin', 'user'], true);
                    $ov = $m['override'];
                    $rb = $roleBadges[$role] ?? $roleBadges['user'];
                ?>
                    <tr>
                        <td class="text-cell" style="padding:0.45rem 0.5rem; border-bottom:1px solid #f5f5f5;">
                            <span style="display:inline-flex; align-items:center; gap:0.5rem;">
                                <span style="width:26px; height:26px; border-radius:50%; background:#e2e8f0; color:#475569; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:0.8rem;"><?= strtoupper(substr($m['email'], 0, 1)) ?></span>
                                <?= htmlspecialchars($m['email']) ?>
                                <?php if ($isSelf): ?><span style="color:var(--primary-color); font-size:0.78rem;">(<?= __('settings.you') ?>)</span><?php endif; ?>
                            </span>
                        </td>
                        <td class="text-cell" style="padding:0.45rem 0.5rem; border-bottom:1px solid #f5f5f5;">
                            <span style="display:inline-flex; align-items:center; gap:0.3rem; color:<?= $rb[1] ?>; font-weight:600; font-size:0.8rem;">
                                <span class="material-symbols-outlined" style="font-size:1rem;"><?= $rb[0] ?></span><?= htmlspecialchars($rb[2]) ?>
                            </span>
                        </td>
                        <td class="num-cell" style="padding:0.45rem 0.5rem; border-bottom:1px solid #f5f5f5; color:var(--text-secondary);"><?= htmlspecialchars(substr((string)$m['created_at'], 0, 10)) ?></td>
                        <td class="num-cell" style="padding:0.45rem 0.5rem; border-bottom:1px solid #f5f5f5;"><?= $hasBudget ? '$' . number_format((float)$m['spent'], 4) : '—' ?></td>
                        <td class="num-cell" style="padding:0.45rem 0.5rem; border-bottom:1px solid #f5f5f5;">
                            <?php if ($hasBudget): ?>
                            <input type="number" min="0" step="0.01" class="budget-override" data-user-id="<?= $uid ?>"
                                   value="<?= $ov !== null ? htmlspecialchars(number_format((float)$ov, 2, '.', '')) : '' ?>"
                                   placeholder="<?= htmlspecialchars(number_format($defaultBudget, 2, '.', '')) ?>"
                                   style="width:90px; padding:0.3rem 0.45rem; border:1px solid #cbd5e1; border-radius:6px; text-align:right;">
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="num-cell" style="padding:0.45rem 0.5rem; border-bottom:1px solid #f5f5f5; white-space:nowrap;">
                            <button type="button" class="icon-btn" title="<?= __('common.edit') ?? 'Edit' ?>"
                                    onclick="openUserModal(<?= $uid ?>, '<?= htmlspecialchars($m['email'], ENT_QUOTES) ?>', '<?= $role ?>')"
                                    style="background:none; border:none; cursor:pointer; color:#475569; padding:0.2rem;">
                                <span class="material-symbols-outlined" style="font-size:1.1rem;">edit</span>
                            </button>
                            <?php if (!$isSelf): ?>
                            <button type="button" class="icon-btn user-del-btn" title="<?= __('common.delete') ?>"
                                    onclick="deleteUserRow(<?= $uid ?>, '<?= htmlspecialchars($m['email'], ENT_QUOTES) ?>')"
                                    style="background:none; border:none; cursor:pointer; padding:0.2rem;">
                                <span class="material-symbols-outlined" style="font-size:1.1rem;">delete</span>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="budget-save-status" style="margin-top:0.6rem; font-size:0.85rem;"></div>
        </div>

        <div class="settings-sticky-footer" data-save-tab="team">
            <span class="save-hint" data-save-hint><?= __('settings.unsaved_warning') ?></span>
            <button type="button" class="btn btn-primary-action" data-save-btn disabled>
                <span class="material-symbols-outlined">save</span> <?= __('settings.save_changes') ?>
            </button>
        </div>
        </section><!-- /tab team -->
        <?php endif; ?>

        <section class="settings-tab<?= $isAdmin ? '' : ' active' ?>" data-tab="api">
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

        </div>

        <!-- ============ MCP SERVER ============ -->
        <div class="settings-card" style="grid-column: 1 / -1;">
            <h2><span class="material-symbols-outlined">hub</span> <?= __('settings.mcp_title') ?></h2>
            <p class="card-subtitle"><?= __('settings.mcp_intro') ?></p>

            <div style="margin:1rem 0 0.4rem; font-size:0.85rem; color:var(--text-secondary);"><?= __('settings.mcp_url_label') ?></div>
            <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:1rem;">
                <code id="mcpUrl" style="flex:1; font-family:ui-monospace,monospace; font-size:0.85rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:0.5rem 0.7rem; overflow-x:auto; white-space:nowrap;">…/mcp</code>
                <button type="button" id="mcpUrlCopy" class="btn btn-secondary" style="white-space:nowrap;"><?= __('settings.api_copy') ?></button>
            </div>

            <div style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:0.4rem;"><?= __('settings.mcp_howto') ?></div>
            <div style="display:flex; gap:0.5rem; align-items:flex-start; margin-bottom:1rem;">
                <pre id="mcpCmd" style="flex:1; margin:0; background:#0f172a; color:#e2e8f0; border-radius:8px; padding:0.8rem 1rem; overflow-x:auto; font-size:0.78rem; line-height:1.55; white-space:pre;"></pre>
                <button type="button" id="mcpCmdCopy" class="btn btn-secondary" style="white-space:nowrap;"><?= __('settings.api_copy') ?></button>
            </div>

            <details style="margin-bottom:0.8rem;">
                <summary style="cursor:pointer; font-weight:600; color:var(--text-primary); font-size:0.9rem;"><?= __('settings.mcp_desktop_toggle') ?></summary>
                <pre id="mcpJson" style="margin-top:0.7rem; background:#0f172a; color:#e2e8f0; border-radius:8px; padding:0.8rem 1rem; overflow-x:auto; font-size:0.78rem; line-height:1.55; white-space:pre;"></pre>
            </details>

            <p style="font-size:0.82rem; color:var(--text-secondary); margin:0;">
                <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle; color:#0891b2;">info</span>
                <?= __('settings.mcp_note_cloud') ?>
            </p>
        </div>

        <!-- ============ INTERACTIVE API EXPLORER (custom, dark, no Swagger) ============ -->
        <div class="settings-card" style="grid-column: 1 / -1;">
            <div class="api-section-header">
                <h2 style="margin:0;"><span class="material-symbols-outlined">api</span> <?= __('settings.api_doc_title') ?></h2>
                <!-- Fullscreen toggle lives in the TITLE row, outside the dark block,
                     so it never collides with the explorer's own header. -->
                <button type="button" id="btn-expand-api" title="<?= htmlspecialchars(__('settings.api_doc_fullscreen')) ?>">
                    <span class="material-symbols-outlined" style="font-size:1.05rem;">fullscreen</span>
                    <span class="api-expand-label"><?= __('settings.api_doc_fullscreen') ?></span>
                </button>
            </div>
            <p class="card-subtitle"><?= __('settings.api_doc_intro') ?></p>

            <!-- Scoped dark theme: the explorer is a self-contained dark module so
                 its palette never leaks into the (light) settings page around it. -->
            <style>
                .api-explorer{
                    --bg:#0f1117; --bg-side:#161b22; --bg-card:#1c2230; --bg-input:#0f1117;
                    --border:#2a3441; --text:#e6edf3; --text-dim:#8b949e; --accent:#14b8a6;
                    --mono:'JetBrains Mono','Fira Code',ui-monospace,SFMono-Regular,Menlo,monospace;
                    display:grid; grid-template-columns:240px 1fr; gap:0;
                    background:var(--bg); color:var(--text); border:1px solid var(--border);
                    border-radius:8px; overflow:hidden; margin-top:1rem;
                    font-family:'Inter',system-ui,-apple-system,sans-serif; min-height:520px;
                }
                /* ---- Sidebar ---- */
                .api-side{background:var(--bg-side); border-right:1px solid var(--border); padding:0.6rem 0.5rem; overflow-y:auto; max-height:calc(100vh - 4rem); position:sticky; top:0;}
                .api-grp{margin-bottom:0.25rem;}
                .api-grp-head{display:flex; align-items:center; gap:0.5rem; width:100%; background:transparent; border:none; color:var(--text); cursor:pointer; padding:0.5rem 0.55rem; border-radius:6px; font-size:0.82rem; font-weight:600; font-family:inherit;}
                .api-grp-head:hover{background:rgba(255,255,255,0.04);}
                .api-grp-caret{margin-left:auto; transition:transform .15s; color:var(--text-dim); font-size:1.1rem;}
                .api-grp.collapsed .api-grp-caret{transform:rotate(-90deg);}
                .api-grp.collapsed .api-grp-list{display:none;}
                .api-grp-list{display:flex; flex-direction:column; gap:1px; padding:0.15rem 0 0.35rem;}
                .api-ep{display:flex; align-items:center; gap:0.5rem; width:100%; text-align:left; background:transparent; border:none; color:var(--text-dim); cursor:pointer; padding:0.32rem 0.5rem 0.32rem 0.7rem; border-radius:6px; font-family:inherit; transition:background .12s,color .12s;}
                .api-ep:hover{background:rgba(255,255,255,0.05); color:var(--text);}
                .api-ep.is-active{background:var(--bg-card); color:var(--text);}
                .api-ep-path{font-family:var(--mono); font-size:0.76rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
                .api-method{display:inline-block; font-weight:700; font-size:0.62rem; letter-spacing:0.02em; padding:0.16rem 0.36rem; border-radius:4px; color:#0b0e14; text-align:center; flex-shrink:0;}
                /* ---- Main column ---- */
                .api-main{display:flex; flex-direction:column; min-width:0;}
                /* Hidden by default so it is NOT a grid item on desktop (it would
                   otherwise steal the 2nd column and push the main panel below the
                   sidebar). Only the mobile drawer turns it on. */
                .api-drawer-backdrop{display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:40;}
                .api-keybar{position:sticky; top:0; z-index:5; background:var(--bg-side); border-bottom:1px solid var(--border); padding:0.7rem 1.1rem; display:flex; gap:0.8rem; align-items:flex-end; flex-wrap:wrap;}
                .api-keybar label{font-size:0.72rem; color:var(--text-dim); font-weight:600; display:block; flex:1; min-width:240px;}
                .api-keybar input{display:block; width:100%; box-sizing:border-box; margin-top:0.25rem; padding:0.45rem 0.6rem; background:var(--bg-input); border:1px solid var(--border); border-radius:6px; color:var(--text); font-family:var(--mono); font-size:0.82rem;}
                .api-keybar input:focus{outline:none; border-color:var(--accent);}
                .api-keybar-meta{font-size:0.72rem; color:var(--text-dim); display:flex; flex-direction:column; gap:0.25rem; padding-bottom:0.15rem;}
                .api-keybar-meta a{color:var(--accent); text-decoration:none; display:inline-flex; align-items:center; gap:0.25rem;}
                .api-keybar-meta code{font-family:var(--mono); color:var(--text);}
                .api-detail{padding:1.1rem 1.2rem; overflow-y:auto;}
                .api-empty{color:var(--text-dim); text-align:center; padding:3rem 1rem; font-size:0.9rem;}
                /* ---- Endpoint header ---- */
                .api-h{display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap; margin-bottom:0.4rem;}
                .api-h code{font-family:var(--mono); font-size:0.95rem; color:var(--text); word-break:break-all;}
                .api-desc{color:var(--text-dim); font-size:0.85rem; line-height:1.5; margin:0 0 1.2rem;}
                /* ---- Fields ---- */
                .api-sec-title{font-size:0.72rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-dim); font-weight:700; margin:0 0 0.5rem;}
                .api-field{display:block; margin-bottom:0.7rem;}
                .api-field span{font-size:0.78rem; color:var(--text-dim); font-family:var(--mono);}
                .api-field input{display:block; width:100%; box-sizing:border-box; margin-top:0.25rem; padding:0.45rem 0.6rem; background:var(--bg-input); border:1px solid var(--border); border-radius:6px; color:var(--text); font-family:var(--mono); font-size:0.82rem;}
                .api-field input:focus{outline:none; border-color:var(--accent);}
                /* ---- Body editor (collapsed by default) ---- */
                .api-body-box{margin-bottom:1.1rem;}
                .api-body-toggle{display:inline-flex; align-items:center; gap:0.4rem; background:var(--bg-card); border:1px solid var(--border); color:var(--text); cursor:pointer; padding:0.45rem 0.7rem; border-radius:6px; font-size:0.82rem; font-family:inherit;}
                .api-body-toggle:hover{border-color:var(--accent);}
                .api-body-panel{margin-top:0.6rem;}
                .api-body-panel.hidden{display:none;}
                .api-variants{display:flex; gap:0.4rem; margin-bottom:0.5rem;}
                .api-variant{padding:0.25rem 0.8rem; font-size:0.76rem; border-radius:5px; cursor:pointer; border:1px solid var(--border); background:transparent; color:var(--text-dim); text-transform:capitalize; font-family:inherit;}
                .api-variant.is-active{background:var(--accent); color:#0b0e14; border-color:var(--accent); font-weight:600;}
                /* CM auto-height: cap the INNER scroller (.CodeMirror-scroll), not
                   .CodeMirror itself, otherwise the content is clipped with no scrollbar. */
                .api-explorer .CodeMirror{height:auto; border:1px solid var(--border); border-radius:6px; font-family:var(--mono); font-size:0.82rem;}
                .api-explorer .CodeMirror-scroll{min-height:120px; max-height:340px;}
                .api-ref{margin-bottom:1rem; border:1px solid var(--border); border-radius:6px; overflow:hidden;}
                .api-ref summary{cursor:pointer; font-size:0.8rem; font-weight:600; color:var(--accent); padding:0.5rem 0.7rem; background:var(--bg-card);}
                .api-ref pre{white-space:pre-wrap; font-size:0.78rem; line-height:1.5; color:var(--text-dim); padding:0.7rem; margin:0; max-height:280px; overflow-y:auto;}
                /* ---- Execute ---- */
                .api-run{display:flex; align-items:center; justify-content:center; gap:0.5rem; width:100%; padding:0.7rem; background:var(--accent); color:#06231f; border:none; border-radius:8px; font-size:0.9rem; font-weight:700; cursor:pointer; font-family:inherit; margin-bottom:1.1rem;}
                .api-run:hover:not(:disabled){filter:brightness(1.08);}
                .api-run:disabled{opacity:0.45; cursor:not-allowed;}
                .api-spinner{width:15px; height:15px; border:2px solid rgba(6,35,31,0.35); border-top-color:#06231f; border-radius:50%; animation:apispin .6s linear infinite;}
                @keyframes apispin{to{transform:rotate(360deg);}}
                /* ---- Response tabs ---- */
                .api-resp-zone{border:1px solid var(--border); border-radius:8px; overflow:hidden;}
                .api-resp-zone.hidden{display:none;}
                .api-tabs{display:flex; align-items:center; gap:0.2rem; background:var(--bg-card); border-bottom:1px solid var(--border); padding:0.35rem 0.5rem;}
                .api-tab{background:transparent; border:none; color:var(--text-dim); cursor:pointer; padding:0.35rem 0.7rem; border-radius:5px; font-size:0.8rem; font-weight:600; font-family:inherit;}
                .api-tab:hover{color:var(--text);}
                .api-tab.is-active{background:var(--bg); color:var(--accent);}
                .api-status{margin-left:auto; font-size:0.74rem; font-weight:700; padding:0.18rem 0.5rem; border-radius:4px; font-family:var(--mono);}
                .api-copy{margin-left:0.4rem; background:transparent; border:1px solid var(--border); color:var(--text-dim); cursor:pointer; padding:0.25rem 0.6rem; border-radius:5px; font-size:0.72rem; font-family:inherit;}
                .api-copy:hover{color:var(--text); border-color:var(--accent);}
                .api-pane{margin:0; padding:0.8rem 1rem; background:var(--bg); color:var(--text); font-family:var(--mono); font-size:0.78rem; line-height:1.55; overflow:auto; max-height:360px; white-space:pre;}
                .api-pane.hidden{display:none;}
                .api-cm-host .CodeMirror{height:auto; border:none; border-radius:0; font-family:var(--mono); font-size:0.78rem;}
                .api-cm-host .CodeMirror-scroll{max-height:360px;}
                /* ---- Code snippets zone (cURL · Python · JS · PHP │ n8n) ---- */
                .api-code{margin-top:1.1rem; border:1px solid var(--border); border-radius:8px; overflow:hidden;}
                .api-code-tabs{display:flex; align-items:center; gap:0.25rem; background:var(--bg-card); border-bottom:1px solid var(--border); padding:0.35rem 0.5rem; flex-wrap:wrap;}
                .api-code-tab{background:transparent; border:none; color:var(--text-dim); cursor:pointer; padding:0.32rem 0.7rem; border-radius:5px; font-size:0.8rem; font-weight:600; font-family:inherit; display:inline-flex; align-items:center; gap:0.35rem;}
                .api-code-tab:hover{color:var(--text);}
                .api-code-tab.is-active{background:var(--bg); color:var(--accent);}
                .api-code-sep{width:1px; align-self:stretch; background:var(--border); margin:0.1rem 0.35rem;}
                /* n8n tab: distinct orange identity + no-code pill + ↗ */
                .api-code-tab.n8n{color:#f97316;}
                .api-code-tab.n8n:hover{background:#1c1a14;}
                .api-code-tab.n8n.is-active{background:#1c1a14; color:#f97316;}
                .api-pill{font-size:0.6rem; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; padding:0.1rem 0.34rem; border-radius:9px; background:#431407; border:1px solid #7c2d12; color:#f97316;}
                .api-code-hint{font-size:0.76rem; color:var(--text-dim); padding:0.55rem 0.8rem; border-top:1px solid var(--border); background:var(--bg-card);}
                .api-code-hint.n8n{background:#1c1a14; border-top-color:#7c2d12; color:#f97316;}
                /* ---- Title-row header + fullscreen toggle (outside the dark block) ---- */
                .api-section-header{display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:8px;}
                #btn-expand-api{display:flex; align-items:center; gap:6px; background:transparent; border:1px solid #2d3748; color:#6b7280; font-size:13px; padding:5px 12px; border-radius:6px; cursor:pointer; transition:all .15s; font-family:inherit;}
                #btn-expand-api:hover{color:#14b8a6; border-color:#14b8a6; background:rgba(20,184,166,0.05);}
                #btn-expand-api.is-fullscreen{color:#ef4444; border-color:#ef4444;}
                #btn-expand-api.is-fullscreen:hover{background:rgba(239,68,68,0.12);}
                /* In fullscreen the explorer overlay (z-index 9999) covers the title row,
                   so float the Exit button above it, top-right, on a solid dark chip. */
                body.api-fullscreen-active #btn-expand-api{position:fixed; bottom:18px; right:18px; top:auto; z-index:10000; background:#1c2230; box-shadow:0 2px 12px rgba(0,0,0,0.5);}
                body.api-fullscreen-active #btn-expand-api:hover{background:rgba(239,68,68,0.18);}
                /* ---- Fullscreen overlay (fixed, no native requestFullscreen) ---- */
                .api-explorer.api-fullscreen{position:fixed !important; inset:0 !important; z-index:9999 !important; width:100vw !important; height:100vh !important; min-height:0 !important; border-radius:0 !important; margin:0 !important; overflow:hidden; transition:all .2s ease;}
                body.api-fullscreen-active{overflow:hidden;}
                .api-explorer.api-fullscreen .api-main{height:100vh; overflow-y:auto;}
                .api-explorer.api-fullscreen .api-side{height:100vh; max-height:100vh; overflow-y:auto;}
                .api-explorer.api-fullscreen .api-detail{overflow:visible;}
                /* ---- Mobile drawer ---- */
                .api-drawer-btn{display:none;}
                @media (max-width:768px){
                    .api-explorer{grid-template-columns:1fr;}
                    .api-side{position:fixed; top:0; left:0; bottom:0; width:80%; max-width:300px; z-index:50; max-height:none; transform:translateX(-100%); transition:transform .2s;}
                    .api-explorer.drawer-open .api-side{transform:translateX(0);}
                    .api-drawer-btn{display:inline-flex; align-items:center; gap:0.4rem; background:var(--bg-card); border:1px solid var(--border); color:var(--text); padding:0.4rem 0.7rem; border-radius:6px; font-size:0.8rem; cursor:pointer; font-family:inherit;}
                    .api-explorer.drawer-open .api-drawer-backdrop{display:block;}
                    #btn-expand-api{display:none;}  /* feature disabled on mobile */
                }
            </style>

            <div class="api-explorer" id="apiExplorer">
                <aside class="api-side" id="apiSidebar"></aside>
                <div class="api-drawer-backdrop" id="apiDrawerBackdrop"></div>
                <div class="api-main">
                    <!-- Global Bearer token (set once, persisted, reused everywhere). -->
                    <div class="api-keybar">
                        <button type="button" class="api-drawer-btn" id="apiDrawerBtn">
                            <span class="material-symbols-outlined" style="font-size:1.1rem;">menu</span>
                        </button>
                        <label>
                            <?= __('settings.api_doc_token_label') ?>
                            <input id="apiDocToken" type="text" autocomplete="off" spellcheck="false" placeholder="sctr_…">
                        </label>
                        <div class="api-keybar-meta">
                            <span>Base : <code id="apiDocBase">…/api/v1</code></span>
                            <a href="../openapi.yaml" target="_blank" rel="noopener">
                                <span class="material-symbols-outlined" style="font-size:1rem;">description</span> <?= __('settings.api_doc_spec') ?>
                            </a>
                        </div>
                    </div>
                    <div class="api-detail" id="apiWorkspace"></div>
                </div>
            </div>
        </div>

        </section><!-- /tab api -->
    </div><!-- /.container -->

    <!-- User add/edit modal (Team & Budgets tab) — admin-only -->
    <?php if ($isAdmin): ?>
    <div id="userModal" style="display:none; position:fixed; inset:0; z-index:1000; background:rgba(15,23,42,0.5); align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:14px; width:95vw; max-width:460px; padding:1.5rem;">
            <h3 id="userModalTitle" style="margin:0 0 1.2rem; font-size:1.1rem;"></h3>
            <label style="display:block; font-size:0.85rem; color:var(--text-secondary); margin-bottom:0.3rem;"><?= __('admin.label_email') ?></label>
            <input type="email" id="um-email" autocomplete="off"
                   style="width:100%; box-sizing:border-box; padding:0.55rem 0.7rem; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:1rem;">
            <label style="display:block; font-size:0.85rem; color:var(--text-secondary); margin-bottom:0.3rem;"><?= __('admin.label_role') ?></label>
            <select id="um-role" style="width:100%; box-sizing:border-box; padding:0.55rem 0.7rem; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:1rem; background:#fff;">
                <option value="admin"><?= __('admin.role_admin') ?></option>
                <option value="user"><?= __('admin.role_user') ?></option>
                <option value="viewer"><?= __('admin.role_viewer') ?></option>
            </select>
            <label id="um-pass-label" style="display:block; font-size:0.85rem; color:var(--text-secondary); margin-bottom:0.3rem;"><?= __('admin.label_password') ?></label>
            <input type="password" id="um-password" autocomplete="new-password"
                   style="width:100%; box-sizing:border-box; padding:0.55rem 0.7rem; border:1px solid #cbd5e1; border-radius:8px;">
            <div id="um-error" style="color:#dc2626; font-size:0.85rem; margin-top:0.6rem; min-height:1rem;"></div>
            <div style="display:flex; justify-content:flex-end; gap:0.6rem; margin-top:1.2rem;">
                <button type="button" id="um-cancel" class="btn"><?= __('common.cancel') ?></button>
                <button type="button" id="um-save" class="btn btn-primary"><?= __('common.save') ?></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="../assets/i18n.js"></script>
    <script>ScouterI18n.init(<?= I18n::getInstance()->getJsTranslations() ?>, <?= json_encode(I18n::getInstance()->getLang()) ?>);</script>
    <script src="../assets/confirm-modal.js"></script>

    <script>
    // Team tab — user CRUD via /api/users; reloads the page (?tab=team) on success
    // so the merged table + budget figures re-render server-side.
    (function () {
        const modal = document.getElementById('userModal');
        if (!modal) return;
        const titleEl = document.getElementById('userModalTitle');
        const emailIn = document.getElementById('um-email');
        const roleIn  = document.getElementById('um-role');
        const passIn  = document.getElementById('um-password');
        const passLbl = document.getElementById('um-pass-label');
        const errEl   = document.getElementById('um-error');
        let editId = 0;

        const T = {
            add:  <?= json_encode(__('admin.modal_add_title')) ?>,
            edit: <?= json_encode(__('admin.modal_edit_title')) ?>,
            pass: <?= json_encode(__('admin.label_password')) ?>,
            passHint: <?= json_encode(__('admin.label_new_password_hint')) ?>,
            delTitle: <?= json_encode(__('admin.confirm_delete_title')) ?>,
            delConfirm: <?= json_encode(__('admin.confirm_delete')) ?>,
            delOk: <?= json_encode(__('common.delete')) ?>,
        };

        window.openUserModal = function (id, email, role) {
            editId = id || 0;
            titleEl.textContent = editId ? T.edit : T.add;
            emailIn.value = email || '';
            roleIn.value  = role || 'user';
            passIn.value  = '';
            passLbl.style.color = '';
            // The label always reads "Password"; in edit mode it is optional, so
            // drop the required "*" and explain via placeholder instead.
            if (editId) {
                passLbl.textContent = T.pass.replace(/\s*\*$/, '');
                passIn.placeholder  = T.passHint;
            } else {
                passLbl.textContent = T.pass;
                passIn.placeholder  = '';
            }
            errEl.textContent = '';
            modal.style.display = 'flex';
            emailIn.focus();
        };
        function close() { modal.style.display = 'none'; }
        function reload() {
            window.__skipGuard = true; // intentional navigation, don't trigger the unsaved guard
            const u = new URL(location.href); u.searchParams.set('tab', 'team'); location.href = u.toString();
        }

        document.getElementById('addUserBtn')?.addEventListener('click', () => window.openUserModal(0, '', 'user'));
        document.getElementById('um-cancel').addEventListener('click', close);
        modal.addEventListener('click', e => { if (e.target === modal) close(); });

        document.getElementById('um-save').addEventListener('click', async () => {
            const body = { email: emailIn.value.trim(), role: roleIn.value };
            const pw = passIn.value;
            if (pw) body.password = pw;
            let url = '../api/users', method = 'POST';
            if (editId) { url = '../api/users/' + editId; method = 'PUT'; }
            else if (!pw) { errEl.textContent = '⚠'; passLbl.style.color = '#dc2626'; return; }
            try {
                const r = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
                const d = await r.json();
                if (!r.ok || d.success === false) { errEl.textContent = d.error || 'Error'; return; }
                reload();
            } catch (e) { errEl.textContent = 'Error'; }
        });

        window.deleteUserRow = async function (id, email) {
            const ok = window.customConfirm
                ? await window.customConfirm(email + ' — ' + T.delConfirm, T.delTitle, T.delOk, 'danger')
                : confirm(email + ' — ' + T.delConfirm);
            if (!ok) return;
            try {
                const r = await fetch('../api/users/' + id, { method: 'DELETE', headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                if (!r.ok || d.success === false) { alert(d.error || 'Error'); return; }
                reload();
            } catch (e) { alert('Error'); }
        };
    })();
    </script>

    <script>
    // Settings tabs: switch panels, deep-link via ?tab=ai|team|api.
    (function () {
        const nav = document.getElementById('settingsTabsNav');
        if (!nav) return;
        const btns   = Array.from(nav.querySelectorAll('.settings-tab-btn'));
        const panels = Array.from(document.querySelectorAll('.settings-tab'));

        function show(tab) {
            btns.forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
            panels.forEach(p => p.classList.toggle('active', p.dataset.tab === tab));
        }
        // The dirty tracker (set up later) flags a tab with unsaved edits; if the
        // current tab is dirty, confirm via the styled modal before switching.
        const WARN      = <?= json_encode(__('settings.unsaved_warning')) ?>;
        const WARN_LEAVE = <?= json_encode(__('settings.unsaved_leave')) ?>;
        async function attemptShow(tab) {
            const cur = document.querySelector('.settings-tab.active');
            const curTab = cur ? cur.dataset.tab : null;
            if (curTab && curTab !== tab && window.__settingsTabDirty && window.__settingsTabDirty(curTab)) {
                const ok = window.customConfirm
                    ? await window.customConfirm(WARN, undefined, WARN_LEAVE, 'danger')
                    : confirm(WARN);
                if (!ok) return;
                window.__settingsClearDirty && window.__settingsClearDirty(curTab);
            }
            show(tab);
            const url = new URL(location.href);
            url.searchParams.set('tab', tab);
            history.replaceState(null, '', url);
        }
        btns.forEach(b => b.addEventListener('click', () => attemptShow(b.dataset.tab)));

        const initial = new URLSearchParams(location.search).get('tab');
        if (initial && panels.some(p => p.dataset.tab === initial)) show(initial);
    })();
    </script>

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
                    // Pre-fill the interactive doc tester with the fresh token.
                    const docTok = document.getElementById('apiDocToken');
                    if (docTok) { docTok.value = d.token; try { sessionStorage.setItem('scouter_api_token', d.token); } catch (e) {} }
                    loadKeys();
                }
            } finally { genBtn.disabled = false; }
        }

        async function revokeKey(id) {
            const ok = window.customConfirm
                ? await window.customConfirm(T.confirm, undefined, T.revoke, 'danger')
                : confirm(T.confirm);
            if (!ok) return;
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

        loadKeys();
    })();
    </script>

    <script>
    // Interactive API doc + tester (design-system, no Swagger). The endpoint
    // catalog mirrors openapi.yaml — that file stays the machine-readable source
    // of truth (for MCP/external clients); this panel is the human-facing tester.
    (function () {
        const explorer  = document.getElementById('apiExplorer');
        const sidebar   = document.getElementById('apiSidebar');
        const workspace = document.getElementById('apiWorkspace');
        if (!explorer || !sidebar || !workspace) return;
        const base = location.origin + '/api/v1';
        const baseEl = document.getElementById('apiDocBase');
        if (baseEl) baseEl.textContent = base;

        const tokenIn = document.getElementById('apiDocToken');
        const TOKEN_KEY = 'scouter_api_key';
        // Persist the Bearer token in localStorage (per the spec) and reload it.
        try { const saved = localStorage.getItem(TOKEN_KEY); if (saved && tokenIn && !tokenIn.value) tokenIn.value = saved; } catch (e) {}
        tokenIn?.addEventListener('input', () => {
            try { localStorage.setItem(TOKEN_KEY, tokenIn.value.trim()); } catch (e) {}
            if (currentSync) currentSync();   // refresh Execute-disabled state + curl
        });

        // Mobile drawer wiring.
        const drawerBtn = document.getElementById('apiDrawerBtn');
        const backdrop  = document.getElementById('apiDrawerBackdrop');
        drawerBtn?.addEventListener('click', () => explorer.classList.toggle('drawer-open'));
        backdrop?.addEventListener('click', () => explorer.classList.remove('drawer-open'));

        // Set by renderEndpoint → lets the global token listener refresh the view.
        let currentSync = null;

        const T = {
            body:        <?= json_encode(__('settings.api_doc_body')) ?>,
            params:      <?= json_encode(__('settings.api_doc_params')) ?>,
            bodyToggle:  <?= json_encode(__('settings.api_doc_body_toggle')) ?>,
            response:    <?= json_encode(__('settings.api_doc_response')) ?>,
            curl:        <?= json_encode(__('settings.api_doc_tab_curl')) ?>,
            execute:     <?= json_encode(__('settings.api_doc_execute')) ?>,
            running:     <?= json_encode(__('settings.api_doc_running')) ?>,
            noToken:     <?= json_encode(__('settings.api_doc_no_token')) ?>,
            pick:        <?= json_encode(__('settings.api_doc_pick')) ?>,
            copy:        <?= json_encode(__('settings.api_copy')) ?>,
            copied:      <?= json_encode(__('settings.api_copied')) ?>,
            hintToken:   <?= json_encode(__('settings.api_doc_hint_token')) ?>,
            hintN8n:     <?= json_encode(__('settings.api_doc_hint_n8n')) ?>,
        };

        const CREATE_UA = 'Scouter/0.7 (Crawler developed by Lokoe SASU; +https://lokoe.fr/scouter-crawler)';
        const CREATE_ADVANCED = {
            respect_robots: true,
            respect_nofollow: true,
            respect_canonical: true,
            follow_redirects: true,
            retry_failed_urls: true,
            store_html: true,
            sitemap_urls: [],
            custom_headers: [],
            http_auth: null,
            xPathExtractors: { count_h2: 'count(//h2)' },
            regexExtractors: { google_analytics: 'ua":"(UA-\\d{8}-\\d)' },
        };
        const CREATE_SPIDER_EXAMPLE = JSON.stringify({
            config: {
                general: {
                    start: 'https://www.website.tld/',
                    domains: ['www.website.tld'],
                    depthMax: 30,
                    crawl_mode: 'classic',
                    crawl_type: 'spider',
                    'user-agent': CREATE_UA,
                    crawl_speed: 'fast',
                },
                advanced: CREATE_ADVANCED,
            },
        }, null, 2);
        const CREATE_LIST_EXAMPLE = JSON.stringify({
            config: {
                general: {
                    start: 'https://www.website.tld/',
                    domains: ['www.website.tld'],
                    depthMax: 30,
                    url_list: ['https://www.website.tld/page-1', 'https://www.website.tld/page-2', 'https://www.website.tld/blog/article'],
                    crawl_mode: 'classic',
                    crawl_type: 'list',
                    'user-agent': CREATE_UA,
                    crawl_speed: 'unlimited',
                },
                advanced: CREATE_ADVANCED,
            },
        }, null, 2);

        const ENDPOINTS = [
            { method:'GET',  path:'/projects', summary: <?= json_encode(__('settings.api_ep_projects')) ?>,
              desc: <?= json_encode(__('settings.api_ep_projects_desc')) ?>,
              params:[ {name:'limit', def:'50'}, {name:'offset', def:'0'} ] },
            { method:'GET',  path:'/projects/{id}/crawls', summary: <?= json_encode(__('settings.api_ep_crawls')) ?>,
              desc: <?= json_encode(__('settings.api_ep_crawls_desc')) ?>,
              pathParams:['id'], params:[ {name:'limit', def:'50'}, {name:'offset', def:'0'} ] },
            { method:'POST', path:'/crawls', summary: <?= json_encode(__('settings.api_ep_create')) ?>,
              desc: <?= json_encode(__('settings.api_ep_create_desc')) ?>,
              docText: <?= json_encode(__('settings.api_create_ref')) ?>,
              docTitle: <?= json_encode(__('settings.api_create_ref_title')) ?>,
              body: CREATE_SPIDER_EXAMPLE,
              bodyVariants: { spider: CREATE_SPIDER_EXAMPLE, list: CREATE_LIST_EXAMPLE } },
            { method:'GET',  path:'/schedules', summary: <?= json_encode(__('settings.api_ep_schedules')) ?>,
              desc: <?= json_encode(__('settings.api_ep_schedules_desc')) ?> },
            { method:'GET',  path:'/projects/{id}/schedule', summary: <?= json_encode(__('settings.api_ep_sched_get')) ?>,
              desc: <?= json_encode(__('settings.api_ep_sched_get_desc')) ?>,
              pathParams:['id'] },
            { method:'PUT',  path:'/projects/{id}/schedule', summary: <?= json_encode(__('settings.api_ep_sched_put')) ?>,
              desc: <?= json_encode(__('settings.api_ep_sched_put_desc')) ?>,
              docText: <?= json_encode(__('settings.api_schedule_ref')) ?>,
              docTitle: <?= json_encode(__('settings.api_schedule_ref_title')) ?>,
              pathParams:['id'],
              body: JSON.stringify({ template_crawl_id: 0, frequency: 'weekly', days_of_week: ['mon','thu'], hour: 6, minute: 30, enabled: true }, null, 2) },
            { method:'PATCH', path:'/projects/{id}/schedule', summary: <?= json_encode(__('settings.api_ep_sched_patch')) ?>,
              desc: <?= json_encode(__('settings.api_ep_sched_patch_desc')) ?>,
              pathParams:['id'],
              body: JSON.stringify({ enabled: false }, null, 2) },
            { method:'DELETE', path:'/projects/{id}/schedule', summary: <?= json_encode(__('settings.api_ep_sched_delete')) ?>,
              desc: <?= json_encode(__('settings.api_ep_sched_delete_desc')) ?>,
              pathParams:['id'] },
            { method:'GET',  path:'/crawls/{id}', summary: <?= json_encode(__('settings.api_ep_crawl')) ?>,
              desc: <?= json_encode(__('settings.api_ep_crawl_desc')) ?>,
              pathParams:['id'] },
            { method:'GET',  path:'/crawls/{id}/status', summary: <?= json_encode(__('settings.api_ep_status')) ?>,
              desc: <?= json_encode(__('settings.api_ep_status_desc')) ?>,
              pathParams:['id'] },
            { method:'POST', path:'/crawls/{id}/stop', summary: <?= json_encode(__('settings.api_ep_stop')) ?>,
              desc: <?= json_encode(__('settings.api_ep_stop_desc')) ?>,
              pathParams:['id'] },
            { method:'POST', path:'/crawls/{id}/start', summary: <?= json_encode(__('settings.api_ep_start')) ?>,
              desc: <?= json_encode(__('settings.api_ep_start_desc')) ?>,
              pathParams:['id'] },
            { method:'GET',  path:'/crawls/{id}/schema', summary: <?= json_encode(__('settings.api_ep_schema')) ?>,
              desc: <?= json_encode(__('settings.api_ep_schema_desc')) ?>,
              pathParams:['id'] },
            { method:'GET',  path:'/crawls/{id}/content', summary: <?= json_encode(__('settings.api_ep_content')) ?>,
              desc: <?= json_encode(__('settings.api_ep_content_desc')) ?>,
              pathParams:['id'], params:[ {name:'url'} ] },
            { method:'GET',  path:'/crawls/{id}/html', summary: <?= json_encode(__('settings.api_ep_html')) ?>,
              desc: <?= json_encode(__('settings.api_ep_html_desc')) ?>,
              pathParams:['id'], params:[ {name:'url'}, {name:'max_chars', def:'1000000'} ] },
            { method:'POST', path:'/crawls/{id}/query', summary: <?= json_encode(__('settings.api_ep_query')) ?>,
              desc: <?= json_encode(__('settings.api_ep_query_desc')) ?>,
              pathParams:['id'],
              body: JSON.stringify({ query:'SELECT url, code FROM pages WHERE code >= 400 ORDER BY inlinks DESC', page:1, page_size:100, count:true }, null, 2) },
            { method:'GET',  path:'/crawls/{id}/categorization', summary: <?= json_encode(__('settings.api_ep_categorization_get')) ?>,
              desc: <?= json_encode(__('settings.api_ep_categorization_get_desc')) ?>,
              pathParams:['id'] },
            { method:'PUT',  path:'/crawls/{id}/categorization', summary: <?= json_encode(__('settings.api_ep_categorization_set')) ?>,
              desc: <?= json_encode(__('settings.api_ep_categorization_set_desc')) ?>,
              docText: <?= json_encode(__('settings.api_categorization_ref')) ?>,
              docTitle: <?= json_encode(__('settings.api_categorization_ref_title')) ?>,
              pathParams:['id'],
              body: JSON.stringify({ yaml: "homepage:\n  include:\n    - ^/?$\n  color: '#4ecdc4'\nproduct:\n  include:\n    - ^/p/[0-9]+\n    - ^/product/[^/]+\n  color: '#6bd899'\nother:\n  include:\n    - .*\n  color: '#cccccc'", deploy_to_project: true }, null, 2) },
        ];

        // Method palette (per spec): GET teal · POST orange · PUT violet · PATCH yellow · DELETE red.
        const METHOD_COLORS = { GET:'#14b8a6', POST:'#f97316', PUT:'#a855f7', PATCH:'#eab308', DELETE:'#ef4444' };
        // Per-method text colour for legible contrast (dark on light teal/yellow, white on the rest).
        const METHOD_TEXT = { GET:'#06231f', POST:'#fff', PUT:'#fff', PATCH:'#3a2f00', DELETE:'#fff' };
        const escapeHtml = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        const methodBadge = (m, extra) => '<span class="api-method" style="background:' + (METHOD_COLORS[m] || '#475569') + ';color:' + (METHOD_TEXT[m] || '#fff') + ';' + (extra || '') + '">' + m + '</span>';
        // Stable, URL-safe id for routing (e.g. POST /crawls → POST_crawls).
        const slugOf = ep => (ep.method + '_' + ep.path).replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

        // Group endpoints by RESOURCE. /projects/{id}/schedule belongs to
        // "Schedules" even though its path starts with /projects → test it first.
        const GROUP_ORDER = ['Projects', 'Crawls', 'Schedules', 'Other'];
        const groupOf = ep => {
            const p = ep.path;
            if (p === '/schedules' || p.includes('/schedule')) return 'Schedules';
            if (p.startsWith('/projects')) return 'Projects';
            if (p.startsWith('/crawls'))   return 'Crawls';
            return 'Other';
        };

        let liveCMs = []; // CodeMirror instances of the current view (DOM nuked on switch)
        const hasCM = typeof CodeMirror !== 'undefined';

        // ----- Render the detail view of ONE endpoint into the workspace -----
        function renderEndpoint(ep) {
            liveCMs = [];               // old instances are detached when we wipe the DOM
            workspace.innerHTML = '';
            const inputs = {};

            // 1) Header — method + full path + description.
            const h = document.createElement('div');
            h.className = 'api-h';
            h.innerHTML = methodBadge(ep.method, 'font-size:0.7rem;padding:0.2rem 0.5rem;') + '<code>' + escapeHtml(ep.path) + '</code>';
            workspace.appendChild(h);
            if (ep.desc) {
                const d = document.createElement('p'); d.className = 'api-desc'; d.textContent = ep.desc;
                workspace.appendChild(d);
            }

            // Optional reference doc (crawl config keys / schedule format / YAML format).
            if (ep.docText) {
                const refDet = document.createElement('details');
                refDet.className = 'api-ref';
                const refSum = document.createElement('summary'); refSum.textContent = ep.docTitle || 'Reference';
                const refPre = document.createElement('pre'); refPre.textContent = ep.docText;
                refDet.appendChild(refSum); refDet.appendChild(refPre);
                workspace.appendChild(refDet);
            }

            // 2) Path / query parameters.
            const allParams = (ep.pathParams || []).map(p => ({ key: 'path:' + p, label: p, ph: 'path param' }))
                .concat((ep.params || []).map(p => ({ key: 'q:' + p.name, label: p.name, ph: p.def ? ('default: ' + p.def) : 'query param' })));
            if (allParams.length) {
                const t = document.createElement('div'); t.className = 'api-sec-title'; t.textContent = T.params;
                workspace.appendChild(t);
                allParams.forEach(p => {
                    const wrap = document.createElement('label'); wrap.className = 'api-field';
                    const span = document.createElement('span'); span.textContent = p.label;
                    const inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = p.ph;
                    wrap.appendChild(span); wrap.appendChild(inp); workspace.appendChild(wrap);
                    inputs[p.key] = inp;
                    inp.addEventListener('input', refreshCode);
                });
            }

            // 3) Body JSON (POST/PUT/PATCH) — CodeMirror editor, COLLAPSED by default.
            let getBody = null;
            let bodyCM = null;
            if (ep.body !== undefined) {
                const box = document.createElement('div'); box.className = 'api-body-box';
                const toggle = document.createElement('button');
                toggle.type = 'button'; toggle.className = 'api-body-toggle';
                toggle.innerHTML = '<span class="material-symbols-outlined" style="font-size:1.05rem;">data_object</span> ' + escapeHtml(T.bodyToggle);
                const panel = document.createElement('div'); panel.className = 'api-body-panel hidden';

                // Spider | List variant switch (create_crawl only).
                if (ep.bodyVariants) {
                    const sw = document.createElement('div'); sw.className = 'api-variants';
                    Object.keys(ep.bodyVariants).forEach((key, i) => {
                        const b = document.createElement('button');
                        b.type = 'button'; b.textContent = key;
                        b.className = 'api-variant' + (i === 0 ? ' is-active' : '');
                        b.addEventListener('click', () => {
                            sw.querySelectorAll('button').forEach(x => x.classList.remove('is-active'));
                            b.classList.add('is-active');
                            if (bodyCM) bodyCM.setValue(ep.bodyVariants[key]); else ta.value = ep.bodyVariants[key];
                            refreshCode();
                        });
                        sw.appendChild(b);
                    });
                    panel.appendChild(sw);
                }

                const ta = document.createElement('textarea'); ta.value = ep.body;
                panel.appendChild(ta);
                box.appendChild(toggle); box.appendChild(panel);
                workspace.appendChild(box);

                getBody = () => bodyCM ? bodyCM.getValue() : ta.value;

                // Mount CodeMirror lazily on first expand (it mis-measures while hidden).
                toggle.addEventListener('click', () => {
                    const willShow = panel.classList.contains('hidden');
                    panel.classList.toggle('hidden');
                    if (willShow && !bodyCM && hasCM) {
                        bodyCM = CodeMirror.fromTextArea(ta, {
                            mode: { name: 'javascript', json: true },
                            theme: 'material-darker', lineNumbers: true, lineWrapping: true,
                            tabSize: 2, viewportMargin: Infinity,
                        });
                        bodyCM.on('change', refreshCode);
                        liveCMs.push(bodyCM);
                    }
                    if (willShow && bodyCM) setTimeout(() => bodyCM.refresh(), 0);
                });
            }

            // 4) Execute button (full-width, teal, spinner, disabled without a key).
            const runBtn = document.createElement('button'); runBtn.type = 'button'; runBtn.className = 'api-run';
            const runLabel = '<span class="material-symbols-outlined" style="font-size:1.1rem;">play_arrow</span> ' + escapeHtml(T.execute);
            runBtn.innerHTML = runLabel;
            workspace.appendChild(runBtn);

            // 5) Response zone (revealed after first run): status badge + JSON viewer.
            const zone = document.createElement('div'); zone.className = 'api-resp-zone hidden';
            const rtabs = document.createElement('div'); rtabs.className = 'api-tabs';
            const rlabel = document.createElement('span'); rlabel.className = 'api-tab is-active'; rlabel.textContent = T.response;
            const statusBadge = document.createElement('span'); statusBadge.className = 'api-status'; statusBadge.style.display = 'none';
            const respCopy = document.createElement('button'); respCopy.type = 'button'; respCopy.className = 'api-copy'; respCopy.textContent = T.copy;
            rtabs.appendChild(rlabel); rtabs.appendChild(statusBadge); rtabs.appendChild(respCopy);
            const respHost = document.createElement('div'); respHost.className = 'api-cm-host';
            zone.appendChild(rtabs); zone.appendChild(respHost);
            workspace.appendChild(zone);
            let respCM = null, respText = '';
            respCopy.addEventListener('click', () => {
                navigator.clipboard.writeText(respCM ? respCM.getValue() : respText).then(() => {
                    respCopy.textContent = T.copied; setTimeout(() => { respCopy.textContent = T.copy; }, 1500);
                });
            });

            // 6) Code snippets zone — cURL · Python · JavaScript · PHP │ n8n.
            const LANGS = [
                { id: 'curl', label: 'cURL',       mode: 'shell',                       gen: genCurl },
                { id: 'py',   label: 'Python',     mode: 'python',                      gen: genPython },
                { id: 'js',   label: 'JavaScript', mode: 'javascript',                  gen: genJs },
                { id: 'php',  label: 'PHP',        mode: 'application/x-httpd-php',     gen: genPhp },
                { id: 'n8n',  label: 'n8n',        mode: { name: 'javascript', json: true }, gen: genN8n, special: true },
            ];
            const codeWrap = document.createElement('div'); codeWrap.className = 'api-code';
            const ctabs = document.createElement('div'); ctabs.className = 'api-code-tabs';
            const codeBtns = {};
            LANGS.forEach((l, i) => {
                if (l.special) { const sep = document.createElement('span'); sep.className = 'api-code-sep'; ctabs.appendChild(sep); }
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'api-code-tab' + (l.special ? ' n8n' : '') + (i === 0 ? ' is-active' : '');
                b.innerHTML = l.special
                    ? escapeHtml(l.label) + '<span class="api-pill">no-code</span><span class="material-symbols-outlined" style="font-size:0.95rem;">north_east</span>'
                    : escapeHtml(l.label);
                b.addEventListener('click', () => selectLang(l.id));
                codeBtns[l.id] = b; ctabs.appendChild(b);
            });
            const codeCopy = document.createElement('button'); codeCopy.type = 'button'; codeCopy.className = 'api-copy'; codeCopy.style.marginLeft = 'auto'; codeCopy.textContent = T.copy;
            ctabs.appendChild(codeCopy);
            const codeHost = document.createElement('div'); codeHost.className = 'api-cm-host';
            const hint = document.createElement('div'); hint.className = 'api-code-hint';
            codeWrap.appendChild(ctabs); codeWrap.appendChild(codeHost); codeWrap.appendChild(hint);
            workspace.appendChild(codeWrap);

            let codeCM = null, activeLang = 'curl';
            if (hasCM) {
                codeCM = CodeMirror(codeHost, {
                    value: '', mode: 'shell', theme: 'material-darker',
                    readOnly: true, lineNumbers: true, lineWrapping: true, viewportMargin: Infinity,
                });
                liveCMs.push(codeCM);
            } else {
                const pre = document.createElement('pre'); pre.className = 'api-pane'; codeHost.appendChild(pre);
                codeCM = { _pre: pre, setValue(v) { pre.textContent = v; }, getValue() { return pre.textContent; }, setOption() {}, refresh() {} };
            }
            codeCopy.addEventListener('click', () => {
                navigator.clipboard.writeText(codeCM.getValue()).then(() => { codeCopy.textContent = T.copied; setTimeout(() => { codeCopy.textContent = T.copy; }, 1500); });
            });

            // ---- request builders + snippet generators ----
            function buildUrl(forCurl) {
                const token = (tokenIn?.value || '').trim();
                let path = ep.path;
                (ep.pathParams || []).forEach(p => {
                    const raw = (inputs['path:' + p]?.value || '').trim();
                    const v = raw || (forCurl ? '{' + p + '}' : '');
                    path = path.replace('{' + p + '}', forCurl ? v : encodeURIComponent(v));
                });
                const qs = new URLSearchParams();
                (ep.params || []).forEach(p => { const v = (inputs['q:' + p.name]?.value || '').trim(); if (v !== '') qs.set(p.name, v); });
                let url = base + path; const q = qs.toString(); if (q) url += '?' + q;
                return { token, url };
            }
            const reqMeta = () => {
                const { url } = buildUrl(true);
                const tok = (tokenIn?.value || '').trim() || 'sctr_YOUR_TOKEN';
                const body = getBody ? (getBody() || '') : null;
                return { url, tok, body, m: ep.method, ml: ep.method.toLowerCase() };
            };
            function genCurl() {
                const { url, tok, body, m } = reqMeta();
                let c = 'curl -X ' + m + ' "' + url + '"';
                c += ' \\\n  -H "Authorization: Bearer ' + tok + '"';
                c += ' \\\n  -H "Accept: application/json"';
                if (body !== null) {
                    c += ' \\\n  -H "Content-Type: application/json"';
                    c += " \\\n  -d '" + body.replace(/\s*\n\s*/g, ' ').replace(/'/g, "'\\''") + "'";
                }
                return c;
            }
            function genPython() {
                const { url, tok, body, ml } = reqMeta();
                let s = 'import requests\n\n';
                s += 'url = "' + url + '"\n';
                s += 'headers = {\n    "Authorization": "Bearer ' + tok + '",\n    "Content-Type": "application/json"\n}\n\n';
                if (body !== null) {
                    s += 'payload = """' + body + '"""\n\n';
                    s += 'response = requests.' + ml + '(url, headers=headers, data=payload)\n';
                } else {
                    s += 'response = requests.' + ml + '(url, headers=headers)\n';
                }
                s += 'print(response.json())';
                return s;
            }
            function genJs() {
                const { url, tok, body, m } = reqMeta();
                let s = 'const response = await fetch(\n  "' + url + '",\n  {\n    method: "' + m + '",\n';
                s += '    headers: {\n      "Authorization": "Bearer ' + tok + '",\n      "Content-Type": "application/json"\n    }';
                if (body !== null) s += ',\n    body: JSON.stringify(' + body + ')';
                s += '\n  }\n);\n\nconst data = await response.json();\nconsole.log(data);';
                return s;
            }
            function genPhp() {
                const { url, tok, body, ml } = reqMeta();
                // NB: build the opening PHP tag by concatenation — writing it as a
                // single literal here would be parsed as a real open-tag in this file.
                let s = '<' + '?php\n$client = new GuzzleHttp\\Client();\n\n';
                s += "$response = $client->" + ml + "(\n  '" + url + "',\n  [\n    'headers' => [\n";
                s += "      'Authorization' => 'Bearer " + tok + "',\n      'Content-Type'  => 'application/json',\n    ],\n";
                if (body !== null) s += "    'body' => '" + body.replace(/\s*\n\s*/g, ' ').replace(/'/g, "\\'") + "',\n";
                s += "  ]\n);\n\n$data = json_decode($response->getBody(), true);";
                return s;
            }
            function genN8n() {
                const { url, tok, body, m } = reqMeta();
                const params = { method: m, url: url, sendHeaders: true,
                    headerParameters: { parameters: [{ name: 'Authorization', value: 'Bearer ' + tok }] } };
                if (body !== null) { params.sendBody = true; params.specifyBody = 'json'; params.jsonBody = body; }
                return JSON.stringify({ nodes: [{
                    name: 'Scouter - ' + (ep.summary || ep.path),
                    type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, parameters: params,
                }] }, null, 2);
            }

            function selectLang(id) {
                activeLang = id;
                const lang = LANGS.find(l => l.id === id);
                Object.values(codeBtns).forEach(b => b.classList.remove('is-active'));
                codeBtns[id].classList.add('is-active');
                if (codeCM.setOption) codeCM.setOption('mode', lang.mode);
                codeCM.setValue(lang.gen());
                if (codeCM.refresh) setTimeout(() => codeCM.refresh(), 0);
                // Contextual hint (n8n gets the import instructions + its own style).
                hint.className = 'api-code-hint' + (id === 'n8n' ? ' n8n' : '');
                hint.textContent = id === 'n8n' ? T.hintN8n : T.hintToken;
            }
            function refreshCode() { const lang = LANGS.find(l => l.id === activeLang); codeCM.setValue(lang.gen()); }

            // Keep Execute-enabled state + the visible snippet in sync with the token.
            function syncState() { runBtn.disabled = !(tokenIn?.value || '').trim(); refreshCode(); }
            currentSync = syncState;
            selectLang('curl');
            syncState();

            runBtn.addEventListener('click', async () => {
                const token = (tokenIn?.value || '').trim();
                zone.classList.remove('hidden');
                const showResp = (txt, mode) => {
                    if (respCM) { respCM.setValue(txt); if (mode) respCM.setOption('mode', mode); setTimeout(() => respCM.refresh(), 0); }
                    else if (hasCM) {
                        respCM = CodeMirror(respHost, { value: txt, mode: mode || { name: 'javascript', json: true }, theme: 'material-darker',
                            readOnly: true, lineNumbers: true, lineWrapping: true, viewportMargin: Infinity,
                            foldGutter: true, gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'] });
                        liveCMs.push(respCM); setTimeout(() => respCM.refresh(), 0);
                    } else { respText = txt; respHost.innerHTML = ''; const pre = document.createElement('pre'); pre.className = 'api-pane'; pre.textContent = txt; respHost.appendChild(pre); }
                };
                if (!token) { statusBadge.style.display = 'none'; showResp(T.noToken, 'text/plain'); return; }
                const { url } = buildUrl(false);
                const opts = { method: ep.method, headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' } };
                if (getBody) { opts.headers['Content-Type'] = 'application/json'; opts.body = getBody(); }

                runBtn.disabled = true;
                runBtn.innerHTML = '<span class="api-spinner"></span> ' + escapeHtml(T.running);
                try {
                    const r = await fetch(url, opts);
                    const txt = await r.text();
                    let pretty = txt, mode = 'text/plain';
                    try { pretty = JSON.stringify(JSON.parse(txt), null, 2); mode = { name: 'javascript', json: true }; } catch (e) {}
                    const col = r.status < 300 ? '#16a34a' : (r.status < 500 ? '#ef4444' : '#b91c1c');
                    statusBadge.style.display = ''; statusBadge.style.background = col; statusBadge.style.color = '#fff';
                    statusBadge.textContent = r.status + ' ' + r.statusText;
                    showResp(pretty, mode);
                } catch (e) {
                    statusBadge.style.display = ''; statusBadge.style.background = '#b91c1c'; statusBadge.style.color = '#fff';
                    statusBadge.textContent = 'Error';
                    showResp(String(e), 'text/plain');
                } finally { runBtn.disabled = false; runBtn.innerHTML = runLabel; }
            });
        }

        // ----- Build the grouped, collapsible sidebar -----
        const epButtons = new Map(); // slug → button (for active highlight + routing)
        function selectEndpoint(ep, push) {
            epButtons.forEach(b => b.classList.remove('is-active'));
            const btn = epButtons.get(slugOf(ep));
            if (btn) btn.classList.add('is-active');
            explorer.classList.remove('drawer-open'); // close mobile drawer on pick
            renderEndpoint(ep);
            if (push) {
                try { const u = new URL(location); u.searchParams.set('endpoint', slugOf(ep)); history.replaceState(null, '', u); } catch (e) {}
            }
        }

        const grouped = {};
        ENDPOINTS.forEach(ep => { const g = groupOf(ep); (grouped[g] = grouped[g] || []).push(ep); });

        GROUP_ORDER.forEach(group => {
            const list = grouped[group];
            if (!list || !list.length) return;
            const sec = document.createElement('div'); sec.className = 'api-grp';
            const head = document.createElement('button');
            head.type = 'button'; head.className = 'api-grp-head';
            head.innerHTML = '<span class="material-symbols-outlined" style="font-size:1.05rem;">folder</span><span>' + escapeHtml(group) + '</span>'
                + '<span class="material-symbols-outlined api-grp-caret">expand_more</span>';
            const ul = document.createElement('div'); ul.className = 'api-grp-list';
            head.addEventListener('click', () => sec.classList.toggle('collapsed'));
            sec.appendChild(head); sec.appendChild(ul);

            list.forEach(ep => {
                const btn = document.createElement('button');
                btn.type = 'button'; btn.className = 'api-ep'; btn.title = ep.method + ' ' + ep.path;
                btn.innerHTML = methodBadge(ep.method, 'min-width:42px;') + '<span class="api-ep-path">' + escapeHtml(ep.path) + '</span>';
                btn.addEventListener('click', () => selectEndpoint(ep, true));
                epButtons.set(slugOf(ep), btn);
                ul.appendChild(btn);
            });
            sidebar.appendChild(sec);
        });

        // ----- Initial selection: ?endpoint= deep-link, else the first endpoint -----
        if (ENDPOINTS.length) {
            let initial = ENDPOINTS[0];
            try {
                const want = new URL(location).searchParams.get('endpoint');
                if (want) { const m = ENDPOINTS.find(e => slugOf(e) === want); if (m) initial = m; }
            } catch (e) {}
            selectEndpoint(initial, false);
        } else {
            workspace.innerHTML = '<div class="api-empty">' + escapeHtml(T.pick) + '</div>';
        }
    })();
    </script>

    <script>
    // Fullscreen toggle for the API explorer — a fixed overlay (NOT the native
    // requestFullscreen, which hides the browser chrome). Esc closes it.
    (function () {
        const btn   = document.getElementById('btn-expand-api');
        const bloc  = document.getElementById('apiExplorer');
        if (!btn || !bloc) return;
        const label = btn.querySelector('.api-expand-label');
        const icon  = btn.querySelector('.material-symbols-outlined');
        const L = { open: <?= json_encode(__('settings.api_doc_fullscreen')) ?>, close: <?= json_encode(__('settings.api_doc_collapse')) ?> };

        function onEsc(e) { if (e.key === 'Escape') close(); }
        function open() {
            bloc.classList.add('api-fullscreen');
            document.body.classList.add('api-fullscreen-active');
            btn.classList.add('is-fullscreen');
            if (label) label.textContent = L.close;
            if (icon) icon.textContent = 'close_fullscreen';
            document.addEventListener('keydown', onEsc);
        }
        function close() {
            bloc.classList.remove('api-fullscreen');
            document.body.classList.remove('api-fullscreen-active');
            btn.classList.remove('is-fullscreen');
            if (label) label.textContent = L.open;
            if (icon) icon.textContent = 'fullscreen';
            document.removeEventListener('keydown', onEsc);
        }
        btn.addEventListener('click', () => bloc.classList.contains('api-fullscreen') ? close() : open());
    })();
    </script>

    <script>
    // MCP encart: fill the connector URL + ready-to-paste config for this host.
    (function () {
        const urlEl = document.getElementById('mcpUrl');
        if (!urlEl) return;
        const url = location.origin + '/mcp';
        urlEl.textContent = url;

        const cmdEl = document.getElementById('mcpCmd');
        const jsonEl = document.getElementById('mcpJson');
        if (cmdEl) {
            cmdEl.textContent =
                'claude mcp add --transport http scouter ' + url + ' \\\n' +
                '  --header "Authorization: Bearer sctr_YOUR_KEY"';
        }
        if (jsonEl) {
            jsonEl.textContent = JSON.stringify({
                mcpServers: {
                    scouter: { type: 'http', url: url, headers: { Authorization: 'Bearer sctr_YOUR_KEY' } }
                }
            }, null, 2);
        }

        const wireCopy = (btn, src) => {
            if (!btn || !src) return;
            const label = btn.textContent;
            btn.addEventListener('click', () => {
                navigator.clipboard.writeText(src.textContent).then(() => {
                    btn.textContent = <?= json_encode(__('settings.api_copied')) ?>;
                    setTimeout(() => { btn.textContent = label; }, 1500);
                });
            });
        };
        wireCopy(document.getElementById('mcpUrlCopy'), urlEl);
        wireCopy(document.getElementById('mcpCmdCopy'), cmdEl);
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

    <?php if ($isAdmin): /* AI provider + Dr. Brief prompt editor — admin-only */ ?>
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
                // Programmatic value changes don't fire events — dispatch one so
                // the per-tab dirty tracker enables the sticky Save button.
                hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
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
    <?php endif; ?>

    <script>
    // Single sticky Save per tab (spec §4). Each tab with a footer tracks its
    // own dirty state: the footer button stays disabled until something in the
    // panel changes, then clicking it fires the underlying (hidden) per-card
    // save handlers and clears the flag. Switching tab / leaving the page with
    // pending changes warns first. The API tab has no save (key actions are
    // immediate), so it is not tracked.
    (function () {
        // Footer Save -> the existing in-card buttons it stands in for.
        const SAVE_TRIGGERS = { ai: ['btn-save', 'btn-prompt-save'], team: ['budget-save-btn'] };
        const dirty = {};
        const footers = {};

        function setDirty(tab, v) {
            const f = footers[tab];
            if (!f) { dirty[tab] = v; return; }
            dirty[tab] = v;
            f.btn.disabled = !v;
            f.footer.classList.toggle('is-dirty', v);
        }

        document.querySelectorAll('.settings-sticky-footer[data-save-tab]').forEach(footer => {
            const tab = footer.dataset.saveTab;
            const btn = footer.querySelector('[data-save-btn]');
            const panel = document.querySelector('.settings-tab[data-tab="' + tab + '"]');
            if (!btn || !panel) return;
            dirty[tab] = false;
            footers[tab] = { footer, btn };

            panel.addEventListener('input', () => setDirty(tab, true));
            panel.addEventListener('change', () => setDirty(tab, true));

            btn.addEventListener('click', () => {
                (SAVE_TRIGGERS[tab] || []).forEach(id => document.getElementById(id)?.click());
                setDirty(tab, false); // optimistic; the underlying handlers report failures themselves
            });
        });

        // Exposed for the tab-switch handler (which uses the styled customConfirm).
        window.__settingsTabDirty  = (tab) => !!dirty[tab];
        window.__settingsClearDirty = (tab) => setDirty(tab, false);

        // beforeunload must stay native — browsers don't allow custom dialogs here.
        window.addEventListener('beforeunload', (e) => {
            if (window.__skipGuard) return;
            if (Object.values(dirty).some(Boolean)) { e.preventDefault(); e.returnValue = ''; return ''; }
        });
    })();
    </script>
</body>
</html>
