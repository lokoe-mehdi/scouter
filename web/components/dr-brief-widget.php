<?php
/**
 * Dr. Brief — chat assistant widget
 *
 * Floating bubble bottom-right of the dashboard. Click → slide-out panel.
 * Conversation is in-memory only (no persistence) — reload = fresh chat.
 *
 * Required PHP scope when including:
 *   $crawlId    (int)
 *   $crawlRecord (object, used only for the AI configured check fallback)
 *
 * Renders nothing if AI is not configured for this instance.
 */

// AI availability check — match the pattern used in other pages.
$drBriefAiConfigured = false;
try {
    $_dbAiKey   = \App\Settings\AppSettings::get('ai.gemini.api_key');
    $_dbAiModel = \App\Settings\AppSettings::get('ai.gemini.model');
    $drBriefAiConfigured = $_dbAiKey !== null && $_dbAiKey !== '' && $_dbAiModel !== null && $_dbAiModel !== '';
} catch (\Throwable $e) {
    $drBriefAiConfigured = false;
}
if (!$drBriefAiConfigured) {
    // Don't render the widget at all when AI is off — keeps the UI clean.
    return;
}
?>

<!-- Dr. Brief floating widget -->
<div id="drBriefRoot">
    <!-- Bubble — custom tooltip via data attribute, opens above-left to never overflow the viewport. -->
    <button type="button" id="drBriefBubble" class="dr-brief-bubble"
            data-dr-tooltip="<?= htmlspecialchars(__('dr_brief.open')) ?>"
            aria-label="<?= htmlspecialchars(__('dr_brief.open')) ?>">
        <img src="assets/avatars/dr-brief.webp" alt="Dr. Brief" class="dr-brief-bubble-img">
    </button>

    <!-- Panel (hidden until bubble clicked) -->
    <div id="drBriefPanel" class="dr-brief-panel" style="display: none;">
        <div class="dr-brief-header">
            <div class="dr-brief-header-left">
                <div class="dr-brief-avatar">
                    <img src="assets/avatars/dr-brief.webp" alt="Dr. Brief" class="dr-brief-avatar-img">
                </div>
                <div class="dr-brief-title">
                    <div class="dr-brief-name">Dr. Brief</div>
                    <div class="dr-brief-status"><?= __('dr_brief.tagline') ?></div>
                </div>
            </div>
            <div class="dr-brief-header-actions">
                <button type="button" class="dr-brief-icon-btn" id="drBriefExpand" title="<?= __('dr_brief.expand') ?>">
                    <span class="material-symbols-outlined" id="drBriefExpandIcon">open_in_full</span>
                </button>
                <button type="button" class="dr-brief-icon-btn" id="drBriefReset" title="<?= __('dr_brief.new_chat') ?>">
                    <span class="material-symbols-outlined">restart_alt</span>
                </button>
                <button type="button" class="dr-brief-icon-btn" id="drBriefClose" title="<?= __('common.close') ?>">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>

        <div class="dr-brief-messages" id="drBriefMessages">
            <!-- Welcome message injected by JS on first open -->
        </div>

        <form class="dr-brief-composer" id="drBriefForm" autocomplete="off">
            <textarea id="drBriefInput"
                      class="dr-brief-input"
                      placeholder="<?= htmlspecialchars(__('dr_brief.placeholder')) ?>"
                      rows="2"></textarea>
            <button type="submit" class="dr-brief-send-btn" id="drBriefSend" title="<?= __('dr_brief.send') ?>">
                <span class="material-symbols-outlined">arrow_upward</span>
            </button>
        </form>
    </div>
</div>

<style>
/* ============================================================
   Dr. Brief widget
   ============================================================ */

/* Floating bubble bottom-right */
.dr-brief-bubble {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    cursor: pointer;
    box-shadow: 0 6px 24px rgba(102, 126, 234, 0.45);
    z-index: 9998;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.dr-brief-bubble:hover {
    transform: scale(1.05);
    box-shadow: 0 8px 32px rgba(102, 126, 234, 0.55);
}
.dr-brief-bubble .material-symbols-outlined {
    font-size: 28px;
}
/* Custom avatar image — fills the bubble. White ring around for contrast on
   the violet gradient + on hover. `object-fit: cover` crops centered. */
.dr-brief-bubble-img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    display: block;
}
/* Custom tooltip — anchored top-left of the bubble so it never overflows. */
.dr-brief-bubble[data-dr-tooltip]::after {
    content: attr(data-dr-tooltip);
    position: absolute;
    bottom: calc(100% + 8px);
    right: 0;
    background: #1f2937;
    color: white;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 0.78rem;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transform: translateY(4px);
    transition: opacity 0.15s, transform 0.15s;
}
.dr-brief-bubble[data-dr-tooltip]:hover::after {
    opacity: 1;
    transform: translateY(0);
}

/* Slide-out panel */
.dr-brief-panel {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 460px;
    height: min(720px, calc(100vh - 60px));
    background: white;
    border-radius: 14px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: drBriefSlideIn 0.18s ease-out;
    transition: width 0.2s ease, height 0.2s ease;
}
/* Expanded mode — much bigger for reading long reports / wide tables. */
.dr-brief-panel.expanded {
    width: min(900px, calc(100vw - 40px));
    height: calc(100vh - 40px);
    bottom: 20px;
    right: 20px;
}
@keyframes drBriefSlideIn {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Header */
.dr-brief-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    flex-shrink: 0;
}
.dr-brief-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.dr-brief-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
}
.dr-brief-avatar .material-symbols-outlined { font-size: 22px; }
.dr-brief-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.dr-brief-title { min-width: 0; }
.dr-brief-name {
    font-weight: 700;
    font-size: 1rem;
    line-height: 1.2;
}
.dr-brief-status {
    font-size: 0.75rem;
    opacity: 0.85;
    line-height: 1.2;
    margin-top: 2px;
}
.dr-brief-header-actions {
    display: flex;
    gap: 4px;
}
.dr-brief-icon-btn {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    color: rgba(255, 255, 255, 0.85);
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.12s;
}
.dr-brief-icon-btn:hover {
    background: rgba(255, 255, 255, 0.18);
    color: white;
}
.dr-brief-icon-btn .material-symbols-outlined { font-size: 18px; }

/* Messages area */
.dr-brief-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: #f8fafc;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.dr-brief-messages::-webkit-scrollbar { width: 6px; }
.dr-brief-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

/* Message bubbles */
.dr-brief-msg {
    display: flex;
    flex-direction: column;
    max-width: 92%;
    line-height: 1.4;
    font-size: 0.9rem;
}
.dr-brief-msg.user {
    align-self: flex-end;
}
.dr-brief-msg.user .dr-brief-msg-content {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 10px 14px;
    border-radius: 14px 14px 4px 14px;
    word-wrap: break-word;
    white-space: pre-wrap;
}
.dr-brief-msg.assistant {
    align-self: flex-start;
}
.dr-brief-msg.assistant .dr-brief-msg-content {
    background: white;
    color: var(--text-primary, #1f2937);
    padding: 12px 14px;
    border-radius: 14px 14px 14px 4px;
    border: 1px solid #e5e7eb;
    word-wrap: break-word;
}

/* Markdown styling inside assistant messages */
.dr-brief-msg-content p { margin: 0 0 8px; }
.dr-brief-msg-content p:last-child { margin-bottom: 0; }
.dr-brief-msg-content ul,
.dr-brief-msg-content ol { margin: 0 0 8px; padding-left: 22px; }
.dr-brief-msg-content li { margin: 2px 0; }
.dr-brief-msg-content code {
    background: #f1f5f9;
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 0.85em;
    font-family: ui-monospace, 'SF Mono', Monaco, Consolas, monospace;
}
.dr-brief-msg-content pre {
    background: #1e293b;
    color: #e2e8f0;
    padding: 10px 12px;
    border-radius: 6px;
    overflow-x: auto;
    font-size: 0.8rem;
    margin: 6px 0;
    line-height: 1.4;
}
.dr-brief-msg-content pre code {
    background: transparent;
    padding: 0;
    color: inherit;
}
/* Wrapper for markdown tables — gives them horizontal scroll instead of
   forcing the panel to grow. Direct .dr-brief-msg-content > table styling
   is kept for any direct (non-wrapped) tables, but new ones go through the
   wrapper that the markdown renderer creates. */
.dr-brief-md-tablewrap {
    overflow-x: auto;
    margin: 6px 0;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
}
.dr-brief-md-tablewrap table {
    border-collapse: collapse;
    font-size: 0.82rem;
    width: auto;
    min-width: 100%;
    margin: 0;
}
.dr-brief-msg-content table {
    border-collapse: collapse;
    margin: 6px 0;
    font-size: 0.82rem;
    width: 100%;
}
.dr-brief-msg-content th,
.dr-brief-msg-content td,
.dr-brief-md-tablewrap th,
.dr-brief-md-tablewrap td {
    border: 1px solid #e5e7eb;
    padding: 5px 8px;
    text-align: left;
    white-space: nowrap;
}
.dr-brief-msg-content th,
.dr-brief-md-tablewrap th {
    background: #f1f5f9;
    font-weight: 600;
    position: sticky;
    top: 0;
}
/* When wrapped, table borders would duplicate the wrapper border — collapse. */
.dr-brief-md-tablewrap table th:first-child,
.dr-brief-md-tablewrap table td:first-child { border-left: none; }
.dr-brief-md-tablewrap table th:last-child,
.dr-brief-md-tablewrap table td:last-child { border-right: none; }
.dr-brief-md-tablewrap table tr:first-child th { border-top: none; }
.dr-brief-md-tablewrap table tr:last-child td { border-bottom: none; }

/* Thin, well-licked scrollbars — applied across the whole widget. */
#drBriefRoot ::-webkit-scrollbar { width: 6px; height: 6px; }
#drBriefRoot ::-webkit-scrollbar-track { background: transparent; }
#drBriefRoot ::-webkit-scrollbar-thumb {
    background: rgba(102, 126, 234, 0.35);
    border-radius: 3px;
}
#drBriefRoot ::-webkit-scrollbar-thumb:hover {
    background: rgba(102, 126, 234, 0.6);
}
/* Firefox */
#drBriefRoot * {
    scrollbar-width: thin;
    scrollbar-color: rgba(102, 126, 234, 0.4) transparent;
}
.dr-brief-msg-content h1,
.dr-brief-msg-content h2,
.dr-brief-msg-content h3,
.dr-brief-msg-content h4,
.dr-brief-msg-content h5,
.dr-brief-msg-content h6 {
    margin: 8px 0 4px;
    font-weight: 600;
    line-height: 1.3;
}
.dr-brief-msg-content h1 { font-size: 1.05rem; }
.dr-brief-msg-content h2 { font-size: 1rem; }
.dr-brief-msg-content h3 { font-size: 0.95rem; }
.dr-brief-msg-content h4 { font-size: 0.9rem; }
.dr-brief-msg-content h5 { font-size: 0.85rem; color: var(--text-secondary, #6b7280); text-transform: uppercase; letter-spacing: 0.02em; }
.dr-brief-msg-content h6 { font-size: 0.8rem;  color: var(--text-secondary, #6b7280); text-transform: uppercase; letter-spacing: 0.02em; }
.dr-brief-msg-content a {
    color: #667eea;
    text-decoration: underline;
}

/* Tool step (collapsible) */
.dr-brief-tool {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 8px 10px;
    margin: 6px 0;
    font-size: 0.82rem;
    color: var(--text-secondary, #6b7280);
}
.dr-brief-tool-step {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 2px 0;
}
.dr-brief-tool-step .material-symbols-outlined {
    font-size: 16px;
    color: #667eea;
}
.dr-brief-tool-step.done .material-symbols-outlined {
    color: #10b981;
}
.dr-brief-tool-purpose {
    font-style: italic;
    color: var(--text-primary, #1f2937);
}
.dr-brief-tool details {
    margin-top: 6px;
}
.dr-brief-tool summary {
    cursor: pointer;
    color: #667eea;
    font-size: 0.75rem;
    user-select: none;
}
.dr-brief-tool pre {
    background: #1e293b;
    color: #cbd5e1;
    padding: 8px 10px;
    border-radius: 5px;
    overflow-x: auto;
    font-size: 0.75rem;
    margin-top: 6px;
    line-height: 1.35;
}
.dr-brief-tool-result-table {
    margin-top: 6px;
    overflow-x: auto;
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid #e5e7eb;
    border-radius: 5px;
}
.dr-brief-tool-result-table table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.78rem;
}
.dr-brief-tool-result-table th,
.dr-brief-tool-result-table td {
    padding: 4px 8px;
    border-bottom: 1px solid #f1f5f9;
    text-align: left;
    white-space: nowrap;
}
.dr-brief-tool-result-table th {
    background: #f8fafc;
    font-weight: 600;
    position: sticky;
    top: 0;
}
.dr-brief-tool-error {
    color: #dc2626;
    background: #fef2f2;
    padding: 6px 10px;
    border-radius: 5px;
    margin-top: 6px;
    font-size: 0.8rem;
}
.dr-brief-deeplink {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #667eea;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
}
.dr-brief-deeplink:hover { text-decoration: underline; }
/* Footer that lives at the bottom of each assistant bubble : holds the
   "Voir tout dans le SQL Explorer →" buttons. Sits below the text so the
   model's "via le bouton ci-dessous" actually points at something below. */
.dr-brief-deeplinks {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.dr-brief-deeplink .material-symbols-outlined { font-size: 14px; }

/* Typing dots */
.dr-brief-typing {
    display: inline-flex;
    gap: 4px;
    padding: 4px 0;
}
.dr-brief-typing span {
    width: 6px;
    height: 6px;
    background: #94a3b8;
    border-radius: 50%;
    animation: drBriefBlink 1.2s infinite ease-in-out;
}
.dr-brief-typing span:nth-child(2) { animation-delay: 0.2s; }
.dr-brief-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes drBriefBlink {
    0%, 80%, 100% { opacity: 0.3; }
    40% { opacity: 1; }
}

/* Composer */
.dr-brief-composer {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 10px 12px;
    border-top: 1px solid #e5e7eb;
    background: white;
    flex-shrink: 0;
}
.dr-brief-input {
    flex: 1;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 0.9rem;
    font-family: inherit;
    resize: none;
    line-height: 1.4;
    min-height: 38px;
    max-height: 120px;
    transition: border-color 0.15s;
}
.dr-brief-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.15);
}
.dr-brief-send-btn {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: opacity 0.15s, transform 0.1s;
}
.dr-brief-send-btn:hover:not(:disabled) { transform: translateY(-1px); }
.dr-brief-send-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.dr-brief-send-btn .material-symbols-outlined { font-size: 20px; }

/* Responsive — on small screens, the panel fills the viewport */
@media (max-width: 600px) {
    .dr-brief-panel {
        bottom: 0;
        right: 0;
        left: 0;
        width: auto;
        height: 100vh;
        border-radius: 0;
    }
    .dr-brief-bubble { bottom: 16px; right: 16px; }
}
</style>

<script>
(function() {
    // === State ===
    // Persisted in sessionStorage keyed by crawl_id so the conversation
    // survives navigation within the same tab (changing pages of the dashboard
    // doesn't lose context). Closing the tab wipes it — no server-side DB.
    const crawlId = <?= (int)$crawlId ?>;
    const STORAGE_KEY = 'dr-brief:msgs:' + crawlId;

    function loadMessages() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    }
    function saveMessages() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(messages));
        } catch (_) { /* quota / disabled storage — silent */ }
    }
    function clearMessages() {
        messages = [];
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (_) {}
    }

    let messages = loadMessages();
    let isStreaming = false;

    // Panel open/closed state — also persisted per crawl so navigation
    // doesn't reset it. Default: closed (bubble shown).
    const OPEN_KEY = 'dr-brief:open:' + crawlId;
    function isPanelRememberedOpen() {
        try { return sessionStorage.getItem(OPEN_KEY) === '1'; } catch (_) { return false; }
    }
    function setPanelOpen(open) {
        try {
            if (open) sessionStorage.setItem(OPEN_KEY, '1');
            else sessionStorage.removeItem(OPEN_KEY);
        } catch (_) {}
    }
    // Expanded state — same pattern. Survives navigation, per-crawl.
    const EXPAND_KEY = 'dr-brief:expanded:' + crawlId;
    function isPanelExpanded() {
        try { return sessionStorage.getItem(EXPAND_KEY) === '1'; } catch (_) { return false; }
    }
    function setPanelExpanded(expanded) {
        try {
            if (expanded) sessionStorage.setItem(EXPAND_KEY, '1');
            else sessionStorage.removeItem(EXPAND_KEY);
        } catch (_) {}
    }

    // === DOM ===
    const bubble   = document.getElementById('drBriefBubble');
    const panel    = document.getElementById('drBriefPanel');
    const closeBtn = document.getElementById('drBriefClose');
    const resetBtn = document.getElementById('drBriefReset');
    const msgsEl   = document.getElementById('drBriefMessages');
    const form     = document.getElementById('drBriefForm');
    const input    = document.getElementById('drBriefInput');
    const sendBtn  = document.getElementById('drBriefSend');

    // === All i18n strings centralized + JSON-encoded ===
    // Going through json_encode is the ONLY safe way to inject PHP strings
    // into JS source. Apostrophes (e.g. FR "Exécution d'une requête") in a
    // hardcoded single-quoted JS literal would terminate the string and
    // crash the whole IIFE — that's what happened before this fix.
    const T = <?= json_encode([
        'welcome'         => __('dr_brief.welcome'),
        'running_query'   => __('dr_brief.running_query'),
        'executing'       => __('dr_brief.executing'),
        'rows_returned'   => __('dr_brief.rows_returned'),
        'tool_error'      => __('dr_brief.tool_error'),
        'show_sql'        => __('dr_brief.show_sql'),
        'show_results'    => __('dr_brief.show_results'),
        'view_full'       => __('dr_brief.view_full_in_sql_explorer'),
        'error_prefix'    => 'Erreur : ',
        'sug_count'       => __('dr_brief.example_count'),
        'sug_404'         => __('dr_brief.example_404'),
        'sug_thin'        => __('dr_brief.example_thin'),
    ]) ?>;
    const WELCOME = T.welcome;
    const SUGGESTIONS = [T.sug_count, T.sug_404, T.sug_thin];

    // === Minimal Markdown renderer ===
    // Supports : **bold**, *italic*, `code`, ```fenced code```, # headers,
    // - / 1. lists, | tables |, [links](url), line breaks, paragraphs.
    // Not a full CommonMark — just enough for the model's typical output.
    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }
    function renderMarkdown(md) {
        if (!md) return '';
        let s = md;

        // Code blocks ```lang ... ```
        s = s.replace(/```([\w-]*)\n?([\s\S]*?)```/g,
            (_, lang, code) => '<pre><code>' + escapeHtml(code.replace(/\n$/, '')) + '</code></pre>');

        // Tables (very simple : | h | h |  /  | --- | --- |  /  | c | c |)
        // Trailing \n is OPTIONAL on the last line — otherwise a table at
        // the end of the response loses its last row. Wrapped in a div with
        // overflow-x so wide tables don't blow up the panel layout.
        s = s.replace(/((?:^\|[^\n]+\|\n?)+)/gm, (block) => {
            const rows = block.trim().split('\n').map(r => r.trim()).filter(r => r);
            if (rows.length < 2 || !/^\|[\s\-:|]+\|$/.test(rows[1])) return block;
            const head = rows[0].slice(1, -1).split('|').map(c => '<th>' + escapeHtml(c.trim()) + '</th>').join('');
            const body = rows.slice(2).map(r => {
                const cells = r.slice(1, -1).split('|').map(c => '<td>' + escapeHtml(c.trim()) + '</td>').join('');
                return '<tr>' + cells + '</tr>';
            }).join('');
            return '<div class="dr-brief-md-tablewrap"><table><thead><tr>'
                + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
        });

        // Headers — most specific (more #) first so `#####` doesn't get
        // eaten by the `###` pattern.
        s = s.replace(/^###### (.+)$/gm, '<h6>$1</h6>');
        s = s.replace(/^##### (.+)$/gm, '<h5>$1</h5>');
        s = s.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
        s = s.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        s = s.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        s = s.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // Lists — process BEFORE inline (so the bullet `*` of `* item` isn't
        // mistaken for an italic opener). Accept all three CommonMark bullet
        // markers: `-`, `*`, `+`.
        s = s.replace(/(?:^[-*+] .+(?:\n|$))+/gm, (block) => {
            const items = block.trim().split('\n')
                .map(l => '<li>' + l.replace(/^[-*+] /, '') + '</li>').join('');
            return '<ul>' + items + '</ul>';
        });
        // Numbered
        s = s.replace(/(?:^\d+\. .+(?:\n|$))+/gm, (block) => {
            const items = block.trim().split('\n')
                .map(l => '<li>' + l.replace(/^\d+\. /, '') + '</li>').join('');
            return '<ol>' + items + '</ol>';
        });

        // Inline: links, bold, code, italic.
        // Bold MUST come before italic (so **x** isn't matched twice).
        // No lookbehind here — Safari < 16.4 throws SyntaxError on (?<...)
        // and that would crash the whole IIFE silently.
        s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
        s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');

        // Paragraphs : split on blank lines, wrap in <p> unless already a block.
        const blocks = s.split(/\n{2,}/);
        return blocks.map(b => {
            const t = b.trim();
            if (!t) return '';
            if (/^<(h\d|ul|ol|pre|table|p|div)\b/.test(t)) return t;
            return '<p>' + t.replace(/\n/g, '<br>') + '</p>';
        }).join('\n');
    }

    // === DOM helpers ===
    function el(tag, attrs = {}, ...children) {
        const e = document.createElement(tag);
        for (const k in attrs) {
            if (k === 'class') e.className = attrs[k];
            else if (k === 'html') e.innerHTML = attrs[k];
            else if (k.startsWith('on')) e.addEventListener(k.slice(2), attrs[k]);
            else e.setAttribute(k, attrs[k]);
        }
        for (const c of children) {
            if (c == null) continue;
            e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return e;
    }
    function scrollToBottom() {
        msgsEl.scrollTop = msgsEl.scrollHeight;
    }

    function showWelcome() {
        msgsEl.innerHTML = '';
        const card = el('div', { class: 'dr-brief-msg assistant' },
            el('div', { class: 'dr-brief-msg-content', html: renderMarkdown(WELCOME) })
        );
        msgsEl.appendChild(card);

        if (SUGGESTIONS && SUGGESTIONS.length) {
            const sugWrap = el('div', { class: 'dr-brief-msg assistant' });
            const sugInner = el('div', { class: 'dr-brief-msg-content', style: 'background: transparent; border: none; padding: 0;' });
            SUGGESTIONS.forEach(q => {
                sugInner.appendChild(el('button', {
                    type: 'button',
                    style: 'display:block;margin:4px 0;padding:6px 10px;background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;border-radius:8px;cursor:pointer;font-size:0.82rem;text-align:left;width:100%;',
                    onclick: () => { input.value = q; input.focus(); }
                }, q));
            });
            sugWrap.appendChild(sugInner);
            msgsEl.appendChild(sugWrap);
        }
    }

    // === UI append helpers ===
    function appendUserMsg(text) {
        const node = el('div', { class: 'dr-brief-msg user' },
            el('div', { class: 'dr-brief-msg-content' }, text)
        );
        msgsEl.appendChild(node);
        scrollToBottom();
    }
    function appendAssistantContainer() {
        // Returns the content element so we can incrementally update it.
        const wrap = el('div', { class: 'dr-brief-msg assistant' });
        const content = el('div', { class: 'dr-brief-msg-content' });
        wrap.appendChild(content);
        msgsEl.appendChild(wrap);
        return content;
    }
    function appendTypingIndicator(parent) {
        const t = el('div', { class: 'dr-brief-typing' },
            el('span'), el('span'), el('span'));
        parent.appendChild(t);
        scrollToBottom();
        return t;
    }
    function appendToolStep(parent, purpose) {
        const tool = el('div', { class: 'dr-brief-tool' });
        const stepEl = el('div', { class: 'dr-brief-tool-step' },
            el('span', { class: 'material-symbols-outlined' }, 'database'),
            el('span', { class: 'dr-brief-tool-purpose' }, purpose || T.running_query)
        );
        tool.appendChild(stepEl);
        const statusEl = el('div', { class: 'dr-brief-tool-step' },
            el('span', { class: 'material-symbols-outlined' }, 'progress_activity'),
            el('span', {}, T.executing)
        );
        tool.appendChild(statusEl);
        parent.appendChild(tool);
        scrollToBottom();
        return { tool, statusEl };
    }

    /**
     * Fill an existing tool block (in-flight render path).
     * Used live as SSE arrives.
     */
    function attachToolResult(tool, statusEl, result) {
        statusEl.classList.add('done');
        statusEl.innerHTML = '';
        statusEl.appendChild(el('span', { class: 'material-symbols-outlined' }, 'check_circle'));
        const summary = result.success
            ? T.rows_returned.replace(':count', result.total_rows || 0)
            : T.tool_error;
        statusEl.appendChild(el('span', {}, summary));
        appendToolDetails(tool, result);
        scrollToBottom();
    }

    /**
     * Build a complete tool block from a saved result (history restore path).
     * Skips the "executing…" intermediate state — starts at "done".
     */
    function buildToolBlockFromSaved(result) {
        const tool = el('div', { class: 'dr-brief-tool' });
        tool.appendChild(el('div', { class: 'dr-brief-tool-step' },
            el('span', { class: 'material-symbols-outlined' }, 'database'),
            el('span', { class: 'dr-brief-tool-purpose' }, result.purpose || T.running_query)
        ));
        const statusEl = el('div', { class: 'dr-brief-tool-step done' });
        statusEl.appendChild(el('span', { class: 'material-symbols-outlined' }, 'check_circle'));
        const summary = result.success
            ? T.rows_returned.replace(':count', result.total_rows || 0)
            : T.tool_error;
        statusEl.appendChild(el('span', {}, summary));
        tool.appendChild(statusEl);
        appendToolDetails(tool, result);
        return tool;
    }

    /**
     * Common tail used by both render paths: collapsible SQL, result table,
     * "View all" deeplink, error block.
     */
    function appendToolDetails(tool, result) {
        const details = el('details');
        details.appendChild(el('summary', {}, T.show_sql));
        details.appendChild(el('pre', {}, result.query || ''));
        tool.appendChild(details);

        if (result.success) {
            if (result.rows && result.rows.length) {
                // Collapsible — keeps the chat compact. Click "Voir les
                // résultats" to expand the preview table.
                const resDetails = el('details');
                resDetails.appendChild(el('summary', {}, T.show_results));
                resDetails.appendChild(buildResultTable(result.rows, result.columns));
                tool.appendChild(resDetails);
            }
            // Note: the "Voir tout dans le SQL Explorer →" button is NOT
            // rendered here on purpose — it's appended to a footer below
            // the assistant's text answer so that when the model writes
            // "via le bouton ci-dessous" the button really is below.
        } else {
            tool.appendChild(el('div', { class: 'dr-brief-tool-error' }, result.error || 'Error'));
        }
    }

    /**
     * Build (or rebuild) the bottom-of-message deeplinks footer.
     * One link per truncated tool result. Always positioned LAST inside the
     * message content so it sits below the assistant's text.
     */
    function syncDeeplinksFooter(contentEl, tools) {
        // Remove any existing footer first (idempotent re-renders).
        const existing = contentEl.querySelector(':scope > .dr-brief-deeplinks');
        if (existing) existing.remove();

        const truncated = (tools || []).filter(t => t && t.success && t.truncated && t.deeplink);
        if (truncated.length === 0) return;

        const footer = el('div', { class: 'dr-brief-deeplinks' });
        truncated.forEach(t => {
            // Same-tab nav: sessionStorage survives the navigation so the
            // conversation is restored on the SQL Explorer page (Ctrl-click
            // for new tab if the user prefers).
            footer.appendChild(el('a', {
                href: t.deeplink,
                class: 'dr-brief-deeplink',
            }, T.view_full + ' →'));
        });
        contentEl.appendChild(footer);
    }
    function buildResultTable(rows, columns) {
        const wrap = el('div', { class: 'dr-brief-tool-result-table' });
        const table = el('table');
        const thead = el('thead');
        const headRow = el('tr');
        columns.forEach(c => headRow.appendChild(el('th', {}, c)));
        thead.appendChild(headRow);
        table.appendChild(thead);
        const tbody = el('tbody');
        rows.forEach(r => {
            const tr = el('tr');
            columns.forEach(c => {
                const v = r[c];
                tr.appendChild(el('td', {}, v === null || v === undefined ? '—' : String(v)));
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        return wrap;
    }

    // === SSE streaming via fetch ===
    async function sendMessage(text) {
        if (isStreaming) return;
        const trimmed = text.trim();
        if (!trimmed) return;

        // Clear welcome on first message
        if (messages.length === 0) msgsEl.innerHTML = '';

        messages.push({ role: 'user', content: trimmed });
        saveMessages();
        appendUserMsg(trimmed);
        input.value = '';
        input.style.height = 'auto';

        const contentEl = appendAssistantContainer();
        let textBuffer = '';      // raw markdown so far
        let toolStep = null;      // current tool step (if any)
        let typingEl = appendTypingIndicator(contentEl);
        // Collect all tool results from this turn — saved alongside the
        // assistant text so a full UI restore is possible after navigation.
        const turnTools = [];

        isStreaming = true;
        sendBtn.disabled = true;

        try {
            const res = await fetch('../api/dr-brief/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ crawl_id: crawlId, messages: messages }),
            });
            if (!res.ok) {
                let msg = 'HTTP ' + res.status;
                try {
                    const j = await res.json();
                    msg = j.error || j.message || msg;
                } catch (_) {}
                throw new Error(msg);
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });

                // Parse SSE event blocks (separated by blank lines)
                let idx;
                while ((idx = buffer.indexOf('\n\n')) !== -1) {
                    const block = buffer.slice(0, idx);
                    buffer = buffer.slice(idx + 2);
                    handleSseBlock(block);
                }
            }
        } catch (e) {
            contentEl.innerHTML = '';
            contentEl.appendChild(el('div', {
                style: 'color:#dc2626;'
            }, T.error_prefix + (e.message || e)));
        } finally {
            isStreaming = false;
            sendBtn.disabled = false;
            input.focus();
        }

        // Persist assistant turn — text + tool snapshots.
        // The server only reads {role, content} when re-sending to Gemini,
        // so the extra `tools` key is ignored on the wire. It lets us
        // rebuild the FULL UI (SQL block + result table + deeplink) when the
        // user navigates away and back.
        if (textBuffer.trim() || turnTools.length) {
            messages.push({
                role: 'assistant',
                content: textBuffer,
                tools: turnTools,
            });
            saveMessages();
        }

        // -------------------------------------------------------------
        function handleSseBlock(block) {
            const lines = block.split('\n');
            let eventName = 'message';
            let dataStr = '';
            for (const line of lines) {
                if (line.startsWith('event:')) eventName = line.slice(6).trim();
                else if (line.startsWith('data:')) dataStr += line.slice(5).trim();
            }
            if (!dataStr) return;
            let data;
            try { data = JSON.parse(dataStr); } catch (_) { return; }

            if (eventName === 'thinking') {
                // Already shown via typing dots — no-op here.
                return;
            }
            if (eventName === 'tool_call_ready') {
                if (typingEl) { typingEl.remove(); typingEl = null; }
                toolStep = appendToolStep(contentEl, data.purpose);
                toolStep.queryShown = data.query;
                toolStep.purpose    = data.purpose || '';
                return;
            }
            if (eventName === 'tool_executing') {
                // Status row already says "Executing…" — could update spinner.
                return;
            }
            if (eventName === 'tool_result') {
                if (toolStep) {
                    if (!toolStep.queryShown && data.query) toolStep.queryShown = data.query;
                    const result = {
                        success:    data.success,
                        purpose:    toolStep.purpose || '',
                        total_rows: data.total_rows,
                        truncated:  data.truncated,
                        query:      data.query || toolStep.queryShown || '',
                        rows:       data.rows || [],
                        columns:    data.columns || [],
                        deeplink:   data.deeplink || '',
                        error:      data.error || '',
                    };
                    attachToolResult(toolStep.tool, toolStep.statusEl, result);
                    // Snapshot for the persisted history.
                    turnTools.push(result);
                    // Keep the deeplinks footer up-to-date as tools complete.
                    // It always sits at the very end of the content, so even
                    // after text appends the footer is moved back to the
                    // bottom by syncDeeplinksFooter (it removes + re-adds).
                    syncDeeplinksFooter(contentEl, turnTools);
                    toolStep = null;
                }
                // Show typing dots again while Gemini formulates the answer.
                typingEl = appendTypingIndicator(contentEl);
                return;
            }
            if (eventName === 'text_delta') {
                if (typingEl) { typingEl.remove(); typingEl = null; }
                textBuffer += data.delta || '';
                // Re-render the markdown on every delta. For chat-sized
                // messages this is fine perf-wise.
                renderTextInto(contentEl, textBuffer);
                scrollToBottom();
                return;
            }
            if (eventName === 'done') {
                if (typingEl) { typingEl.remove(); typingEl = null; }
                // Final pass: make sure the deeplinks footer is below the
                // text (in case tool_result fired before any text_delta,
                // which would have left the footer above the text).
                syncDeeplinksFooter(contentEl, turnTools);
                return;
            }
            if (eventName === 'error') {
                if (typingEl) { typingEl.remove(); typingEl = null; }
                contentEl.appendChild(el('div', {
                    style: 'color:#dc2626;margin-top:8px;'
                }, data.message || 'Error'));
                scrollToBottom();
                return;
            }
        }
    }

    function renderTextInto(parent, markdown) {
        // Find or create the markdown <div>. We want it positioned BEFORE
        // the deeplinks footer (so the assistant's text comes before the
        // "Voir tout dans le SQL Explorer" buttons) but AFTER any tool
        // blocks. If a footer is already present, we insert the markdown
        // right before it.
        let mdEl = parent.querySelector(':scope > .dr-brief-md');
        const footer = parent.querySelector(':scope > .dr-brief-deeplinks');
        if (!mdEl) {
            mdEl = el('div', { class: 'dr-brief-md' });
            if (footer) parent.insertBefore(mdEl, footer);
            else parent.appendChild(mdEl);
        }
        mdEl.innerHTML = renderMarkdown(markdown);
    }

    // === Wire up ===
    function renderHistory() {
        msgsEl.innerHTML = '';
        // Re-render every persisted message. For assistant turns, we also
        // rebuild the tool steps (SQL preview, result table, deeplink) from
        // the saved snapshot — order is: tools first, then the text answer,
        // matching the live order.
        messages.forEach(m => {
            if (m.role === 'user') {
                appendUserMsg(m.content);
            } else if (m.role === 'assistant') {
                const wrap = el('div', { class: 'dr-brief-msg assistant' });
                const content = el('div', { class: 'dr-brief-msg-content' });
                if (Array.isArray(m.tools)) {
                    m.tools.forEach(t => content.appendChild(buildToolBlockFromSaved(t)));
                }
                if (m.content && m.content.trim()) {
                    content.appendChild(el('div', { class: 'dr-brief-md', html: renderMarkdown(m.content) }));
                }
                // Deeplinks footer LAST, below the assistant's text answer.
                if (Array.isArray(m.tools)) syncDeeplinksFooter(content, m.tools);
                wrap.appendChild(content);
                msgsEl.appendChild(wrap);
            }
        });
        scrollToBottom();
    }

    function openPanel() {
        panel.style.display = 'flex';
        bubble.style.display = 'none';
        if (messages.length === 0) showWelcome();
        else renderHistory();
        setTimeout(() => input.focus(), 50);
        setPanelOpen(true);
    }
    function closePanel() {
        panel.style.display = 'none';
        bubble.style.display = 'flex';
        setPanelOpen(false);
    }

    // Expand / collapse toggle. Swaps the icon between `open_in_full` and
    // `close_fullscreen` and persists the choice per crawl in sessionStorage.
    const expandBtn = document.getElementById('drBriefExpand');
    const expandIcon = document.getElementById('drBriefExpandIcon');
    const T_EXPAND   = <?= json_encode(__('dr_brief.expand')) ?>;
    const T_COLLAPSE = <?= json_encode(__('dr_brief.collapse')) ?>;
    function applyExpanded(expanded) {
        if (expanded) {
            panel.classList.add('expanded');
            expandIcon.textContent = 'close_fullscreen';
            expandBtn.title = T_COLLAPSE;
        } else {
            panel.classList.remove('expanded');
            expandIcon.textContent = 'open_in_full';
            expandBtn.title = T_EXPAND;
        }
    }
    function toggleExpanded() {
        const next = !panel.classList.contains('expanded');
        applyExpanded(next);
        setPanelExpanded(next);
    }

    bubble.addEventListener('click', openPanel);
    closeBtn.addEventListener('click', closePanel);
    expandBtn.addEventListener('click', toggleExpanded);

    // Restore expanded state immediately (no race with openPanel — the class
    // is applied to the panel element whether it's visible or not).
    applyExpanded(isPanelExpanded());

    // If the user had the panel open before navigating, restore it on load.
    if (isPanelRememberedOpen()) openPanel();
    resetBtn.addEventListener('click', () => {
        if (isStreaming) return;
        clearMessages();
        showWelcome();
    });

    form.addEventListener('submit', e => {
        e.preventDefault();
        sendMessage(input.value);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(input.value);
        }
    });
    // Auto-grow textarea
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });
})();
</script>
