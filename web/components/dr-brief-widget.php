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

// AI availability check — Dr. Brief specifically needs the "strong" model
// (function calling required for run_sql + get_page_headings).
$drBriefAiConfigured = false;
try {
    $_dbAiKey   = \App\Settings\AppSettings::get('ai.openrouter.api_key');
    $_dbAiModel = \App\Settings\AppSettings::get('ai.openrouter.model_strong');
    $drBriefAiConfigured = $_dbAiKey !== null && $_dbAiKey !== '' && $_dbAiModel !== null && $_dbAiModel !== '';
} catch (\Throwable $e) {
    $drBriefAiConfigured = false;
}
if (!$drBriefAiConfigured) {
    // Don't render the widget at all when AI is off — keeps the UI clean.
    return;
}
// AI is reserved for admins + editors ("user"). Viewers (read-only) must not
// see ANY AI feature — render nothing, as if it didn't exist for them.
if (!\App\AI\BudgetService::isAiEligibleRole($_SESSION['role'] ?? null)) {
    return;
}
?>

<!-- Dr. Brief floating widget -->
<div id="drBriefRoot">
    <!-- Greeting speech bubble — visible by default with no JS dependency.
         Once the user dismisses it (× button) or opens the chat, a flag is
         saved in the PHP session and the bubble renders `hidden` on the next
         loads. Logout / session expiry resets it → bubble returns. -->
    <?php $greetingDismissed = !empty($_SESSION['dr_brief_greeting_dismissed']); ?>
    <div id="drBriefGreeting" class="dr-brief-greeting"<?= $greetingDismissed ? ' hidden' : '' ?>>
        <span class="dr-brief-greeting-text"><?= htmlspecialchars(__('dr_brief.greeting')) ?></span>
        <button type="button" class="dr-brief-greeting-close" id="drBriefGreetingClose"
                aria-label="<?= htmlspecialchars(__('dr_brief.greeting_dismiss')) ?>">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>

    <!-- Bubble — custom tooltip via data attribute, opens above-left to never overflow the viewport. -->
    <button type="button" id="drBriefBubble" class="dr-brief-bubble"
            data-dr-tooltip="<?= htmlspecialchars(__('dr_brief.open')) ?>"
            aria-label="<?= htmlspecialchars(__('dr_brief.open')) ?>">
        <img src="assets/avatars/dr-brief.png" alt="Dr. Brief" class="dr-brief-bubble-img">
    </button>

    <!-- Panel (hidden until bubble clicked) -->
    <div id="drBriefPanel" class="dr-brief-panel" style="display: none;">
        <div class="dr-brief-header">
            <div class="dr-brief-header-left">
                <div class="dr-brief-avatar">
                    <img src="assets/avatars/dr-brief.png" alt="Dr. Brief" class="dr-brief-avatar-img">
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
            <button type="button" class="dr-brief-stop-btn" id="drBriefStop" title="<?= __('dr_brief.stop') ?>" hidden>
                <span class="material-symbols-outlined">stop</span>
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
/* Hide the hover tooltip while the greeting bubble is on screen — they'd
   otherwise stack and look messy. */
#drBriefRoot.greeting-active .dr-brief-bubble[data-dr-tooltip]:hover::after {
    opacity: 0;
}

/* Greeting speech bubble — sits to the LEFT of the avatar, vertically
   centered on it, with a little tail pointing toward the avatar. */
.dr-brief-greeting {
    position: fixed;
    bottom: 34px;          /* aligns roughly with the 56px bubble center */
    right: 92px;           /* 24 (bubble right) + 56 (bubble) + 12 gap */
    max-width: 240px;
    background: white;
    color: #1f2937;
    padding: 10px 14px;
    border-radius: 14px;
    box-shadow: 0 8px 28px rgba(102, 126, 234, 0.28);
    z-index: 9998;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    line-height: 1.35;
    font-weight: 500;
    animation: drBriefGreetingIn 0.35s cubic-bezier(0.18, 0.89, 0.32, 1.28);
    transform-origin: right center;
}
.dr-brief-greeting[hidden] { display: none; }
.dr-brief-greeting.leaving {
    animation: drBriefGreetingOut 0.2s ease forwards;
}
/* Tail — small triangle on the right edge pointing at the avatar. */
.dr-brief-greeting::after {
    content: '';
    position: absolute;
    right: -6px;
    top: 50%;
    transform: translateY(-50%);
    border-top: 7px solid transparent;
    border-bottom: 7px solid transparent;
    border-left: 7px solid white;
}
.dr-brief-greeting-text { flex: 1; cursor: pointer; }
.dr-brief-greeting-close {
    flex-shrink: 0;
    width: 20px; height: 20px;
    border: none; background: #f1f5f9; color: #64748b;
    border-radius: 50%; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    padding: 0;
}
.dr-brief-greeting-close:hover { background: #e2e8f0; color: #1f2937; }
.dr-brief-greeting-close .material-symbols-outlined { font-size: 14px; }
@keyframes drBriefGreetingIn {
    from { opacity: 0; transform: scale(0.8) translateX(10px); }
    to   { opacity: 1; transform: scale(1) translateX(0); }
}
@keyframes drBriefGreetingOut {
    from { opacity: 1; transform: scale(1); }
    to   { opacity: 0; transform: scale(0.85) translateX(8px); }
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

/* Header — the whole violet band acts as a "close panel" surface.
   Buttons inside keep their own handlers thanks to event delegation
   (see JS : the click handler ignores clicks landing on .dr-brief-header-actions). */
.dr-brief-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    flex-shrink: 0;
    cursor: pointer;
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
    position: relative;
}

/* Per-message Markdown-copy button. Sits inside the bubble (top-right),
   appears on hover so it doesn't clutter the conversation. The "Copied!"
   confirmation flips the icon to a green checkmark for ~1.5s. */
.dr-brief-copy-btn {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 26px;
    height: 26px;
    border: none;
    border-radius: 6px;
    background: rgba(15, 23, 42, 0.08);
    color: #475569;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.15s, background 0.15s, color 0.15s;
    padding: 0;
}
.dr-brief-msg:hover .dr-brief-copy-btn,
.dr-brief-copy-btn:focus-visible { opacity: 1; }
.dr-brief-copy-btn:hover {
    background: rgba(15, 23, 42, 0.15);
    color: #0f172a;
}
.dr-brief-copy-btn .material-symbols-outlined { font-size: 16px; }
.dr-brief-copy-btn.copied {
    opacity: 1;
    background: rgba(22, 163, 74, 0.15);
    color: #15803d;
}
/* On the user bubble (gradient background) the button needs more contrast. */
.dr-brief-msg.user .dr-brief-copy-btn {
    background: rgba(255, 255, 255, 0.2);
    color: white;
}
.dr-brief-msg.user .dr-brief-copy-btn:hover { background: rgba(255, 255, 255, 0.35); }
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
/* Failed state: discreet orange/amber, no red — we don't want to alarm the
   user since the AI will retry on the next iteration. */
.dr-brief-tool-step.failed .material-symbols-outlined {
    color: #d97706;
}
.dr-brief-tool-step.failed {
    color: #92400e;
}
.dr-brief-tool-error-pre {
    background: #fef3c7;
    color: #92400e;
    padding: 8px 10px;
    border-radius: 5px;
    font-size: 0.75rem;
    margin-top: 6px;
    white-space: pre-wrap;
    word-wrap: break-word;
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
.dr-brief-tool-pages-list {
    margin: 6px 0 0;
    padding: 6px 10px 6px 22px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 5px;
    font-size: 0.8rem;
    line-height: 1.5;
}
.dr-brief-tool-pages-list li { margin: 0; word-break: break-all; }
.dr-brief-tool-page-url {
    color: #1d4ed8;
    text-decoration: none;
}
.dr-brief-tool-page-url:hover { text-decoration: underline; }
.dr-brief-tool-page-meta { color: #6b7280; }
.dr-brief-tool-page-meta-warn { color: #b45309; }
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

/* Quick actions — small pill buttons above the composer for common
   "summarize this page" type intents. Scrollable horizontally if many. */
.dr-brief-quick-actions {
    display: flex;
    gap: 6px;
    padding: 6px 12px 0;
    background: white;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.dr-brief-quick-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #eef2ff;
    color: #4338ca;
    border: 1px solid #c7d2fe;
    border-radius: 14px;
    padding: 3px 10px;
    font-size: 0.78rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
}
.dr-brief-quick-btn:hover:not(:disabled) {
    background: #c7d2fe;
}
.dr-brief-quick-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.dr-brief-quick-btn .material-symbols-outlined { font-size: 15px; }

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
.dr-brief-stop-btn {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #dc2626;
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: opacity 0.15s, transform 0.1s;
}
.dr-brief-stop-btn[hidden] { display: none; }
.dr-brief-stop-btn:hover { background: #b91c1c; transform: translateY(-1px); }
.dr-brief-stop-btn .material-symbols-outlined { font-size: 20px; }

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
    /* Lift the bubble above the mobile bottom nav/action bar so it doesn't
       overlap it, and give the dashboard a matching bottom padding so the
       last rows of content stay reachable above the floating bubble. Both
       mobile-only. */
    .dr-brief-bubble { bottom: 76px; right: 16px; }
    .dashboard-layout { padding-bottom: 70px; }
    /* Follow the bubble's shifted position + cap width so it never runs off
       the left edge on a phone. */
    .dr-brief-greeting {
        bottom: 86px;
        right: 84px;
        max-width: calc(100vw - 100px);
    }
}
</style>

<script>
(function() {
    // === State ===
    // Persisted in localStorage keyed by crawl_id so the conversation survives
    // BOTH same-tab navigation AND opening a new tab/window (localStorage is
    // shared across all tabs of the origin — unlike sessionStorage which is
    // per-tab). Still no server-side DB. To keep it from lingering forever we
    // (a) stamp each save and discard on load past a TTL, and (b) clear it on
    // logout (see top-header.php). A `storage` event listener below keeps
    // multiple open tabs in sync live.
    const crawlId = <?= (int)$crawlId ?>;
    const STORAGE_KEY = 'dr-brief:msgs:' + crawlId;
    const STORAGE_TTL_MS = 24 * 60 * 60 * 1000; // 24h — "another day = fresh chat"

    function loadMessages() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            // Current format: { savedAt, messages }. Tolerate the legacy bare
            // array (older sessionStorage payloads) for a smooth transition.
            if (Array.isArray(parsed)) return parsed;
            if (parsed && Array.isArray(parsed.messages)) {
                if (parsed.savedAt && (Date.now() - parsed.savedAt) > STORAGE_TTL_MS) {
                    localStorage.removeItem(STORAGE_KEY);
                    return [];
                }
                return parsed.messages;
            }
            return [];
        } catch (_) {
            return [];
        }
    }
    function saveMessages() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                savedAt: Date.now(),
                messages: messages,
            }));
        } catch (_) { /* quota / disabled storage — silent */ }
    }
    function clearMessages() {
        messages = [];
        try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
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
    const stopBtn  = document.getElementById('drBriefStop');
    // Active fetch's AbortController, kept here so the stop button can abort
    // the in-flight request. Reset to null between turns.
    let currentAbortController = null;

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
        'show_error'      => __('dr_brief.show_error'),
        'stopped'         => __('dr_brief.stopped'),
        'copy_md'         => __('dr_brief.copy_md'),
        'tool_html_no_html'   => __('dr_brief.tool_html_no_html'),
        'tool_html_truncated' => __('dr_brief.tool_html_truncated'),
        'tool_skipped'    => __('dr_brief.tool_skipped'),
        'view_full'       => __('dr_brief.view_full_in_sql_explorer'),
        'error_prefix'    => 'Erreur : ',
        'sug_explain'      => __('dr_brief.example_explain'),
        'sug_broken_links' => __('dr_brief.example_broken_links'),
        'sug_seo_tags'     => __('dr_brief.example_seo_tags'),
    ]) ?>;
    const WELCOME = T.welcome;
    const SUGGESTIONS = [T.sug_explain, T.sug_broken_links, T.sug_seo_tags];

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
        // STEP 1 — pre-escape ALL HTML special chars in the source.
        // Reason: when the LLM occasionally writes raw HTML (a stray
        // `<table>`, an `<a href>`, even a `<script>`), we don't want it
        // interpreted by the browser when we set innerHTML. Pre-escaping
        // means everything not produced by the transforms below stays as
        // literal text. The markdown markers (`**`, `|`, `#`, `-`, etc.)
        // aren't HTML-special, so they survive the escape and still match.
        let s = escapeHtml(md);

        // Code blocks ```lang ... ``` — content is ALREADY escaped by step 1
        // so we just wrap; no re-escape (would double-encode `&lt;` → `&amp;lt;`).
        s = s.replace(/```([\w-]*)\n?([\s\S]*?)```/g,
            (_, lang, code) => '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>');

        // Tables — same idea, cells are pre-escaped.
        s = s.replace(/((?:^\|[^\n]+\|\n?)+)/gm, (block) => {
            const rows = block.trim().split('\n').map(r => r.trim()).filter(r => r);
            if (rows.length < 2 || !/^\|[\s\-:|]+\|$/.test(rows[1])) return block;
            const head = rows[0].slice(1, -1).split('|').map(c => '<th>' + c.trim() + '</th>').join('');
            const body = rows.slice(2).map(r => {
                const cells = r.slice(1, -1).split('|').map(c => '<td>' + c.trim() + '</td>').join('');
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
        //
        // SECURITY: block script-executing URL schemes in links. The model
        // output is semi-trusted (it ingests crawled page content, so a
        // prompt-injection could try to make it emit `[x](javascript:…)`,
        // which would run in the dashboard origin). We blacklist the
        // executable scheme family and keep everything else clickable
        // (http(s), relative paths, mailto, our internal `sqlx:`, …). A
        // blocked link degrades to its plain anchor text (never a dead/unsafe
        // href). The URL is already HTML-escaped by STEP 1, so attribute
        // break-out isn't possible; only the scheme matters here.
        s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (full, text, url) => {
            // Normalize for the scheme test only: strip the whitespace/control
            // chars browsers ignore inside a scheme (e.g. "java\tscript:"),
            // and lowercase. Decision only — the original `url` is kept as-is.
            const probe = String(url).replace(/[\u0000-\u0020]+/g, '').toLowerCase();
            if (/^(?:javascript|data|vbscript):/.test(probe)) {
                return text; // neutralize: keep the label, drop the link
            }
            return '<a href="' + url + '" target="_blank" rel="noopener">' + text + '</a>';
        });
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

    /**
     * Attach a small "copy as Markdown" button to a message bubble. `source`
     * can be a string (fixed text) or a function returning the current text
     * — the latter is useful for live-streaming assistant replies where the
     * markdown is still accumulating and we want each click to grab the
     * latest snapshot.
     */
    function attachCopyBtn(wrapEl, source) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dr-brief-copy-btn';
        btn.title = T.copy_md || 'Copier en Markdown';
        btn.innerHTML = '<span class="material-symbols-outlined">content_copy</span>';
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const text = typeof source === 'function' ? source() : source;
            if (text == null || text === '') return;
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    // Fallback for older browsers / non-HTTPS contexts.
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                btn.classList.add('copied');
                btn.innerHTML = '<span class="material-symbols-outlined">check</span>';
                setTimeout(() => {
                    btn.classList.remove('copied');
                    btn.innerHTML = '<span class="material-symbols-outlined">content_copy</span>';
                }, 1500);
            } catch (err) {
                console.warn('[DrBrief] clipboard copy failed:', err);
            }
        });
        wrapEl.appendChild(btn);
        return btn;
    }

    function appendUserMsg(text) {
        const node = el('div', { class: 'dr-brief-msg user' },
            el('div', { class: 'dr-brief-msg-content' }, text)
        );
        attachCopyBtn(node, text);
        msgsEl.appendChild(node);
        scrollToBottom();
    }
    function appendAssistantContainer() {
        // Returns the content element so we can incrementally update it.
        // The copy button is attached by the caller once the markdown is
        // available (either right away for restored history, or at the
        // end of the live stream when textBuffer is final).
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
     *
     * On error we soften the visual: no red banner, no SQL error message
     * dumped in the chat — just a discreet warning text. The actual error
     * is still sent back to the model in the for_model payload so the AI can
     * try a corrected query on the next iteration.
     */
    function attachToolResult(tool, statusEl, result) {
        statusEl.innerHTML = '';
        if (result.success) {
            statusEl.classList.add('done');
            statusEl.appendChild(el('span', { class: 'material-symbols-outlined' }, 'check_circle'));
            statusEl.appendChild(el('span', {}, T.rows_returned.replace(':count', result.total_rows || 0)));
        } else {
            statusEl.classList.add('failed');
            statusEl.appendChild(el('span', { class: 'material-symbols-outlined' }, 'info'));
            statusEl.appendChild(el('span', {}, T.tool_skipped));
        }
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
        const statusEl = el('div', { class: 'dr-brief-tool-step ' + (result.success ? 'done' : 'failed') });
        if (result.success) {
            statusEl.appendChild(el('span', { class: 'material-symbols-outlined' }, 'check_circle'));
            statusEl.appendChild(el('span', {}, T.rows_returned.replace(':count', result.total_rows || 0)));
        } else {
            statusEl.appendChild(el('span', { class: 'material-symbols-outlined' }, 'info'));
            statusEl.appendChild(el('span', {}, T.tool_skipped));
        }
        tool.appendChild(statusEl);
        appendToolDetails(tool, result);
        return tool;
    }

    /**
     * Common tail used by both render paths: collapsible SQL (only for SQL
     * tool), result table, "View all" deeplink, error block.
     */
    function appendToolDetails(tool, result) {
        // The "Voir le SQL" collapsible only makes sense for the SQL tool.
        // For other tools (extraction, etc.) we skip it — they have nothing
        // SQL-like to show.
        const isSql  = result.tool_kind === 'sql';
        const isHtml = result.tool_kind === 'html';
        if (isSql && result.query) {
            const details = el('details');
            details.appendChild(el('summary', {}, T.show_sql));
            details.appendChild(el('pre', {}, result.query));
            tool.appendChild(details);
        }

        if (result.success) {
            // HTML inspection tool : compact list of pages + char counts.
            // We don't dump the raw markup in the chat (too noisy and the
            // user can already see their own pages) — the metadata is what
            // makes the tool block readable to a human.
            if (isHtml && Array.isArray(result.pages) && result.pages.length) {
                const list = el('ul', { class: 'dr-brief-tool-pages-list' });
                result.pages.forEach(p => {
                    const li = el('li');
                    const a  = el('a', {
                        href:   p.url,
                        target: '_blank',
                        rel:    'noopener noreferrer',
                        class:  'dr-brief-tool-page-url',
                    }, p.url);
                    li.appendChild(a);
                    if (p.has_html === false) {
                        li.appendChild(el('span', { class: 'dr-brief-tool-page-meta dr-brief-tool-page-meta-warn' },
                            ' — ' + (T.tool_html_no_html || 'no HTML stored')));
                    } else {
                        const kBefore = Math.round((p.original_chars  || 0) / 1024);
                        const kAfter  = Math.round((p.chars_returned || 0) / 1024);
                        let metaText = ' — ' + kAfter + ' KB';
                        if (p.original_chars && p.chars_returned !== p.original_chars) {
                            metaText += ' / ' + kBefore + ' KB';
                        }
                        if (p.truncated) {
                            metaText += ' (' + (T.tool_html_truncated || 'truncated') + ')';
                        }
                        li.appendChild(el('span', { class: 'dr-brief-tool-page-meta' }, metaText));
                    }
                    list.appendChild(li);
                });
                tool.appendChild(list);
            }
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
            // No prominent red box — the error message is hidden inside the
            // collapsible "Voir la SQL" block (alongside the failed query),
            // so curious users can still debug if they want.
            const errDetails = el('details');
            errDetails.appendChild(el('summary', {}, T.show_error));
            errDetails.appendChild(el('pre', { class: 'dr-brief-tool-error-pre' }, result.error || 'Error'));
            tool.appendChild(errDetails);
        }
    }

    /**
     * Resolve the agent's inline SQL-Explorer links.
     *
     * The model writes markdown links whose URL is a short token (`sqlx:<id>`)
     * it got from a run_sql result — it can't reproduce the long base64
     * deeplink reliably, so we hand it a token and swap it here for the real
     * URL. This replaces the old bottom-of-message footer (which produced a
     * confusing stack of unlabeled "view all" links): now the link is exactly
     * where the agent placed it in its prose, with its own descriptive text.
     */
    function resolveSqlExplorerLinks(contentEl, tools) {
        const md = contentEl.querySelector(':scope > .dr-brief-md');
        if (!md) return;
        const map = {};
        (tools || []).forEach(t => {
            if (t && t.link_token && t.deeplink) map[t.link_token] = t.deeplink;
        });
        md.querySelectorAll('a[href^="sqlx:"]').forEach(a => {
            const real = map[a.getAttribute('href')];
            if (real) {
                a.setAttribute('href', real);
                // SAME-TAB navigation: the chat lives in sessionStorage keyed
                // by crawl_id, and SQL Explorer is the same dashboard app that
                // re-renders the widget — so navigating in place RESTORES the
                // conversation. The markdown renderer defaults links to
                // target="_blank" rel="noopener", which would open a new tab
                // with a FRESH sessionStorage (empty chat) — strip those so the
                // history is preserved. (Ctrl/Cmd-click still opens a new tab
                // for users who want one.)
                a.removeAttribute('target');
                a.removeAttribute('rel');
                a.classList.add('dr-brief-inline-sqllink');
            } else {
                // Unknown token (still streaming, or a stray) — drop the href
                // so it isn't a dead "sqlx:" link. Re-resolved on the next pass.
                a.removeAttribute('href');
            }
        });
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
        // Live copy button : closure over textBuffer so each click grabs
        // the latest snapshot (partial during streaming, final after done).
        attachCopyBtn(contentEl.parentElement, () => textBuffer);
        // Collect all tool results from this turn — saved alongside the
        // assistant text so a full UI restore is possible after navigation.
        const turnTools = [];

        isStreaming = true;
        sendBtn.hidden = true;
        stopBtn.hidden = false;
        currentAbortController = new AbortController();

        try {
            // Collect the current page snapshot fresh on every send — so
            // Dr. Brief always knows what the user is looking at. The AI
            // ignores it unless the question is about the current view.
            let pageContext = '';
            try { pageContext = collectPageContext(); } catch (_) {}

            const res = await fetch('../api/dr-brief/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    crawl_id: crawlId,
                    messages: messages,
                    page_context: pageContext,
                }),
                signal: currentAbortController.signal,
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
            // Differentiate a user-initiated abort from a real error :
            // the AbortController surfaces as `AbortError` / `name === 'AbortError'`,
            // we don't want to scream a scary red message just because the
            // user clicked Stop. Append a discreet "(arrêté)" note instead.
            if (e && e.name === 'AbortError') {
                if (typingEl && typingEl.remove) typingEl.remove();
                contentEl.appendChild(el('div', {
                    style: 'color:#94a3b8; font-style:italic; font-size:0.85rem; margin-top:4px;'
                }, T.stopped || '(arrêté)'));
            } else {
                contentEl.innerHTML = '';
                contentEl.appendChild(el('div', {
                    style: 'color:#dc2626;'
                }, T.error_prefix + (e.message || e)));
            }
        } finally {
            isStreaming = false;
            sendBtn.hidden = false;
            stopBtn.hidden = true;
            currentAbortController = null;
            input.focus();
        }

        // Persist assistant turn — text + tool snapshots.
        // The server only reads {role, content} when re-sending to the model,
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
                        // tool_kind ('sql' | 'headings' | 'html' | ...) drives
                        // which collapsibles & deeplinks appear in the UI.
                        // Default to 'sql' for backward-compat with older SSE
                        // payloads that didn't carry the field.
                        tool_kind:  data.tool_kind || 'sql',
                        success:    data.success,
                        purpose:    toolStep.purpose || '',
                        total_rows: data.total_rows,
                        truncated:  data.truncated,
                        query:      data.query || toolStep.queryShown || '',
                        rows:       data.rows || [],
                        columns:    data.columns || [],
                        deeplink:   data.deeplink || '',
                        link_token: data.link_token || '',
                        error:      data.error || '',
                        // get_page_html payload — list of inspected pages with
                        // byte counts. Stays empty for other tool kinds.
                        pages:      data.pages || [],
                    };
                    attachToolResult(toolStep.tool, toolStep.statusEl, result);
                    // Snapshot for the persisted history.
                    turnTools.push(result);
                    toolStep = null;
                }
                // Show typing dots again while the model formulates the answer.
                typingEl = appendTypingIndicator(contentEl);
                return;
            }
            if (eventName === 'text_delta') {
                if (typingEl) { typingEl.remove(); typingEl = null; }
                textBuffer += data.delta || '';
                // Re-render the markdown on every delta. For chat-sized
                // messages this is fine perf-wise.
                renderTextInto(contentEl, textBuffer);
                resolveSqlExplorerLinks(contentEl, turnTools);
                scrollToBottom();
                return;
            }
            if (eventName === 'done') {
                if (typingEl) { typingEl.remove(); typingEl = null; }
                // Final pass: resolve any inline SQL-Explorer link tokens the
                // agent wrote into its answer.
                resolveSqlExplorerLinks(contentEl, turnTools);
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
                // Resolve the agent's inline SQL-Explorer link tokens.
                if (Array.isArray(m.tools)) resolveSqlExplorerLinks(content, m.tools);
                wrap.appendChild(content);
                // Copy-as-Markdown button on the restored bubble too.
                if (m.content && m.content.trim()) attachCopyBtn(wrap, m.content);
                msgsEl.appendChild(wrap);
            }
        });
        scrollToBottom();
    }

    function openPanel() {
        dismissGreeting();
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

    // === Summarize current page ===
    // Scrapes the dashboard DOM (page title, KPI cards, Highcharts data,
    // visible tables) and asks Dr. Brief to synthesize. Works on any
    // dashboard page without touching the page itself — that's the whole
    // point of doing it client-side.
    function collectPageContext() {
        const out = [];

        // Page section title (h1.page-title is the convention across views).
        const h1 = document.querySelector('main h1.page-title, main h1, h1.page-title');
        const pageTitle = h1 ? h1.textContent.trim() : (document.title || 'Dashboard');
        out.push('Page: ' + pageTitle);

        // URL params — useful to know which filters are active.
        const params = new URLSearchParams(window.location.search);
        const interesting = ['page', 'filters', 'columns', 'search', 'compare', 'filter_cat', 'sort'];
        const paramBits = [];
        interesting.forEach(k => {
            const v = params.get(k);
            if (v) paramBits.push(k + '=' + (v.length > 80 ? v.slice(0, 80) + '…' : v));
        });
        if (paramBits.length) out.push('URL params: ' + paramBits.join(' ; '));

        // Scorecards (KPI tiles). Convention from web/components/card.php :
        // .card with .card-title / .card-value / .card-desc inside .scorecards.
        const cards = document.querySelectorAll('.scorecards .card');
        if (cards.length) {
            out.push('\nKPIs (' + cards.length + '):');
            cards.forEach(c => {
                const title = (c.querySelector('.card-title') || {}).textContent || '';
                const value = (c.querySelector('.card-value') || {}).textContent || '';
                const desc  = (c.querySelector('.card-desc')  || {}).textContent || '';
                const line = '  - ' + title.trim() + ': ' + value.trim()
                    + (desc.trim() ? ' (' + desc.trim() + ')' : '');
                out.push(line);
            });
        }

        // Highcharts instances — title + subtitle + series compactly.
        if (typeof Highcharts !== 'undefined' && Array.isArray(Highcharts.charts)) {
            const charts = Highcharts.charts.filter(c => c && c.renderTo);
            if (charts.length) {
                out.push('\nCharts (' + charts.length + '):');
                charts.forEach((c, idx) => {
                    const title = c.title ? (c.title.textStr || c.title.text || '') : '';
                    const subtitle = c.subtitle ? (c.subtitle.textStr || c.subtitle.text || '') : '';
                    out.push('  · ' + (title || 'Chart ' + (idx + 1))
                        + (subtitle ? ' — ' + subtitle : ''));
                    (c.series || []).forEach(s => {
                        if (!s.options || s.options.showInLegend === false) return;
                        const name = s.name || 'series';
                        const data = (s.options.data || s.points || []).slice(0, 30);
                        const compact = data.map(pt => {
                            if (pt == null) return '';
                            if (typeof pt === 'number') return pt;
                            if (Array.isArray(pt)) return pt.join(':');
                            const n = pt.name || pt.category || '';
                            const y = (pt.y !== undefined) ? pt.y : (pt.value !== undefined ? pt.value : '');
                            return n ? n + '=' + y : y;
                        }).filter(x => x !== '').join(', ');
                        if (compact) out.push('      ' + name + ': ' + compact);
                    });
                });
            }
        }

        // Visible tables — first 20 rows of each, capped to 3 tables to
        // keep the context size reasonable. Each table is also tagged with
        // its HUMAN TITLE (h3.table-title in the table-card, or fallback
        // to the nearest preceding heading). Without this, when a page
        // has two tables with opposite meanings ("missing from sitemap"
        // vs "in sitemap but non-indexable"), the AI can't tell them
        // apart and inverts the rows.
        const tables = Array.from(document.querySelectorAll('main table'))
            .filter(t => t.offsetParent !== null) // only visible ones
            .slice(0, 3);
        if (tables.length) {
            out.push('\nTables (' + tables.length + '):');
            tables.forEach((tbl, idx) => {
                // Look for a meaningful title near the table.
                let title = '';
                const card = tbl.closest('.table-card');
                if (card) {
                    const t = card.querySelector('.table-title');
                    if (t) title = t.textContent.trim().replace(/\s+/g, ' ');
                }
                if (!title) {
                    // Fallback: nearest preceding h2/h3 in the DOM.
                    let n = tbl.previousElementSibling;
                    let hops = 0;
                    while (n && hops < 6) {
                        if (n.matches && n.matches('h2, h3, .table-title')) {
                            title = n.textContent.trim().replace(/\s+/g, ' ');
                            break;
                        }
                        n = n.previousElementSibling;
                        hops++;
                    }
                }
                out.push('  Table ' + (idx + 1) + (title ? ' — "' + title + '"' : '') + ':');

                const heads = Array.from(tbl.querySelectorAll('thead th'))
                    .map(th => th.textContent.trim().replace(/\s+/g, ' '));
                if (heads.length) out.push('    columns: ' + heads.join(' | '));
                const rows = Array.from(tbl.querySelectorAll('tbody tr')).slice(0, 20);
                rows.forEach(tr => {
                    const cells = Array.from(tr.querySelectorAll('td'))
                        .map(td => (td.textContent || '').trim().replace(/\s+/g, ' '));
                    out.push('      ' + cells.join(' | '));
                });
                const total = tbl.querySelectorAll('tbody tr').length;
                if (total > 20) out.push('      … (' + (total - 20) + ' more rows)');
            });
        }

        // Cap total size at ~12k chars to stay reasonable in token cost.
        let ctx = out.join('\n');
        if (ctx.length > 12000) {
            ctx = ctx.slice(0, 12000) + '\n… (page context truncated)';
        }
        return ctx;
    }

    bubble.addEventListener('click', openPanel);
    closeBtn.addEventListener('click', closePanel);
    expandBtn.addEventListener('click', toggleExpanded);

    // Live cross-tab sync. localStorage fires a `storage` event in OTHER tabs
    // of the same origin whenever this tab writes the conversation. Mirror the
    // change so every open tab shows the same history. Guards:
    //  - skip while THIS tab is mid-stream (don't clobber the live answer);
    //  - only re-render if the panel is currently open (otherwise just keep the
    //    in-memory `messages` fresh for the next open).
    window.addEventListener('storage', (e) => {
        if (e.key !== STORAGE_KEY) return;
        if (isStreaming) return;
        messages = loadMessages();
        if (panel.style.display === 'flex') {
            if (messages.length === 0) showWelcome();
            else renderHistory();
        }
    });

    // === Greeting accroche bubble ===
    // Visible BY DEFAULT (no `hidden` attr in the HTML) so it always shows on
    // load with zero JS dependency — pure HTML/CSS. JS here only handles
    // dismissal : the × button, or opening the panel. No timer, no session
    // flag, no scroll-dismiss (all of which previously made it flaky).
    const greetingEl    = document.getElementById('drBriefGreeting');
    const greetingClose = document.getElementById('drBriefGreetingClose');
    const greetingText  = greetingEl ? greetingEl.querySelector('.dr-brief-greeting-text') : null;

    // Tell the server the greeting is dismissed for this session, so it
    // renders `hidden` on the next page loads. Fire-and-forget — a failed
    // request just means the bubble reappears on the next load, no big deal.
    function persistGreetingDismissed() {
        try {
            fetch('../api/dr-brief/dismiss-greeting', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
                keepalive: true,
            }).catch(() => {});
        } catch (_) {}
    }

    // Self-contained (uses getElementById, not closure consts) so it's safe to
    // call from openPanel() even before this block's consts are initialized
    // (avoids a temporal-dead-zone ReferenceError when the panel is remembered
    // open across navigation). `persist` defaults to true : every dismissal
    // path (× button OR opening the chat) should remember the choice.
    function dismissGreeting(persist = true) {
        const el = document.getElementById('drBriefGreeting');
        const rt = document.getElementById('drBriefRoot');
        if (!el || el.hidden) return;
        if (persist) persistGreetingDismissed();
        el.classList.add('leaving');
        rt && rt.classList.remove('greeting-active');
        setTimeout(() => { el.hidden = true; el.classList.remove('leaving'); }, 200);
    }
    // Mark the root so the hover tooltip on the avatar doesn't stack with the
    // greeting bubble while it's on screen (only when actually shown).
    if (greetingEl && !greetingEl.hidden) {
        const rt = document.getElementById('drBriefRoot');
        rt && rt.classList.add('greeting-active');
    }
    if (greetingClose) {
        greetingClose.addEventListener('click', (e) => { e.stopPropagation(); dismissGreeting(); });
    }
    if (greetingText) {
        greetingText.addEventListener('click', openPanel);
    }

    // Make the whole violet header a "click to minimize" zone, but ignore
    // clicks that landed on the action buttons (reset / expand / close)
    // — those have their own handlers.
    const header = panel.querySelector('.dr-brief-header');
    if (header) {
        header.addEventListener('click', function (e) {
            if (e.target.closest('.dr-brief-header-actions')) return;
            closePanel();
        });
    }

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
    stopBtn.addEventListener('click', () => {
        // Aborts the in-flight fetch — the AbortError is caught in
        // sendMessage's catch block, which appends an "(arrêté)" hint
        // and re-toggles the buttons via the finally block.
        if (currentAbortController) currentAbortController.abort();
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
