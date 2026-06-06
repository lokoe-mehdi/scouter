<?php
/**
 * Bulk AI Generator — wizard modal component.
 *
 * Self-contained PHP/HTML/CSS/JS. Include this file once per page where
 * you want the modal available, then call from JS :
 *
 *     window.scouterBulkAi.open({
 *         crawlId:    42,
 *         crawlPath:  'mysite',
 *         pageIds:    ['abc12345', 'xyz67890', ...],  // selected URLs
 *         totalShown: 487,                              // for UX messaging
 *     });
 *
 * The modal hits these endpoints (already wired in web/api/index.php) :
 *   GET  /api/bulk-generate/context-fields
 *   POST /api/bulk-generate/estimate
 *   POST /api/bulk-generate/preview
 *   POST /api/bulk-generate/start
 *   GET  /api/bulk-generate/status
 *   POST /api/bulk-generate/stop
 *
 * @see docs/bulk-ai-generator.md
 */
?>
<div id="bulkAiModal" class="bai-modal" hidden>
    <div class="bai-backdrop" data-close></div>
    <div class="bai-panel" role="dialog" aria-modal="true">
        <header class="bai-header">
            <span class="material-symbols-outlined" style="color:#a78bfa;">auto_awesome</span>
            <h2><?= __('bulk_gen.title') ?></h2>
            <span class="bai-step-pill" id="baiStepPill">1 / 3</span>
            <button type="button" class="bai-close" data-close aria-label="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </header>

        <div class="bai-body">
            <!-- ============ STEP 1 — Configure ============ -->
            <section class="bai-step bai-step-1" data-step="1">
                <div class="bai-section">
                    <label class="bai-section-label">
                        <?= __('bulk_gen.items_label') ?>
                        <span class="bai-hint"><?= __('bulk_gen.items_hint') ?></span>
                    </label>
                    <div class="bai-items-table">
                        <div class="bai-items-head">
                            <span><?= __('bulk_gen.col_name') ?></span>
                            <span><?= __('bulk_gen.col_type') ?></span>
                            <span><?= __('bulk_gen.col_note') ?></span>
                            <span></span>
                        </div>
                        <div class="bai-items-body" id="baiItemsBody"></div>
                    </div>
                    <button type="button" class="bai-add-item" id="baiAddItem">
                        <span class="material-symbols-outlined">add</span>
                        <?= __('bulk_gen.add_item') ?>
                    </button>
                </div>

                <div class="bai-section">
                    <label class="bai-section-label">
                        <?= __('bulk_gen.context_label') ?>
                        <span class="bai-hint"><?= __('bulk_gen.context_hint') ?></span>
                    </label>
                    <div class="bai-context-grid" id="baiContextGrid">
                        <div class="bai-loading"><?= __('bulk_gen.loading_fields') ?></div>
                    </div>
                </div>

                <div class="bai-section">
                    <label class="bai-section-label">
                        <?= __('bulk_gen.model_label') ?>
                    </label>
                    <!-- Custom combobox identical to /settings : 3-line trigger
                         (name / slug / context+price), grouped dropdown with
                         fuzzy search. Hidden input holds the current value. -->
                    <div class="bai-combo" id="baiModelCombo" data-open="false">
                        <input type="hidden" id="baiModel" value="">
                        <button type="button" class="bai-combo-trigger">
                            <span class="bai-combo-display placeholder"><?= __('bulk_gen.model_loading') ?></span>
                            <span class="material-symbols-outlined">expand_more</span>
                        </button>
                        <div class="bai-combo-dropdown">
                            <div class="bai-combo-search-wrap">
                                <div class="bai-combo-search-field">
                                    <span class="material-symbols-outlined bai-combo-search-icon">search</span>
                                    <input type="text" class="bai-combo-search"
                                           placeholder="<?= htmlspecialchars(__('bulk_gen.model_search')) ?>"
                                           autocomplete="off">
                                </div>
                            </div>
                            <div class="bai-combo-list" role="listbox"></div>
                        </div>
                    </div>
                </div>

                <div class="bai-section">
                    <label class="bai-section-label" for="baiPromptTpl">
                        <?= __('bulk_gen.prompt_label') ?>
                        <span class="bai-hint"><?= __('bulk_gen.prompt_hint') ?></span>
                    </label>
                    <textarea id="baiPromptTpl" class="bai-prompt"
                              rows="4" spellcheck="false"><?= htmlspecialchars(__('bulk_gen.prompt_placeholder')) ?></textarea>
                </div>

                <div class="bai-error" id="baiStep1Error" hidden></div>
            </section>

            <!-- ============ STEP 2 — Preview & estimation ============ -->
            <section class="bai-step bai-step-2" data-step="2" hidden>
                <div class="bai-section">
                    <h3 class="bai-h3">
                        <span class="material-symbols-outlined">analytics</span>
                        <?= __('bulk_gen.estimate_title') ?>
                    </h3>
                    <div class="bai-estimate-grid" id="baiEstimateGrid">
                        <div class="bai-loading"><?= __('bulk_gen.loading_estimate') ?></div>
                    </div>
                </div>

                <div class="bai-section">
                    <h3 class="bai-h3">
                        <span class="material-symbols-outlined">visibility</span>
                        <?= __('bulk_gen.preview_title') ?>
                        <span class="bai-hint"><?= __('bulk_gen.preview_hint') ?></span>
                    </h3>
                    <button type="button" class="bai-btn bai-btn-secondary" id="baiRunPreview">
                        <span class="material-symbols-outlined">play_arrow</span>
                        <?= __('bulk_gen.run_preview_btn') ?>
                    </button>
                    <div class="bai-preview-results" id="baiPreviewResults"></div>
                </div>

                <div class="bai-error" id="baiStep2Error" hidden></div>
            </section>

            <!-- ============ STEP 3 — Run + Results ============ -->
            <section class="bai-step bai-step-3" data-step="3" hidden>
                <div class="bai-section">
                    <h3 class="bai-h3" id="baiRunTitle"></h3>
                    <div class="bai-progress">
                        <div class="bai-progress-bar"><div class="bai-progress-fill" id="baiProgressFill"></div></div>
                        <div class="bai-progress-meta" id="baiProgressMeta"></div>
                    </div>
                    <div class="bai-run-actions">
                        <button type="button" class="bai-btn bai-btn-danger" id="baiStopBtn" hidden>
                            <span class="material-symbols-outlined">stop</span>
                            <?= __('bulk_gen.stop_btn') ?>
                        </button>
                        <button type="button" class="bai-btn bai-btn-secondary" id="baiCloseBtn">
                            <?= __('bulk_gen.close_keep_running') ?>
                        </button>
                    </div>
                </div>

                <div class="bai-final-actions" id="baiFinalActions" hidden>
                    <button type="button" class="bai-btn bai-btn-primary" id="baiViewInExplorer">
                        <span class="material-symbols-outlined">table_view</span>
                        <?= __('bulk_gen.view_in_explorer') ?>
                    </button>
                </div>

                <div class="bai-error" id="baiStep3Error" hidden></div>
            </section>
        </div>

        <footer class="bai-footer" id="baiFooter">
            <span class="bai-footer-info" id="baiFooterInfo"></span>
            <div class="bai-footer-actions">
                <button type="button" class="bai-btn bai-btn-ghost" id="baiBackBtn" hidden>
                    <?= __('bulk_gen.back_btn') ?>
                </button>
                <button type="button" class="bai-btn bai-btn-ghost" data-close>
                    <?= __('bulk_gen.cancel_btn') ?>
                </button>
                <button type="button" class="bai-btn bai-btn-primary" id="baiNextBtn">
                    <?= __('bulk_gen.next_btn') ?> →
                </button>
                <button type="button" class="bai-btn bai-btn-primary" id="baiLaunchBtn" hidden>
                    <span class="material-symbols-outlined">rocket_launch</span>
                    <?= __('bulk_gen.launch_btn') ?>
                </button>
            </div>
        </footer>
    </div>
</div>

<style>
/* ============ Bulk AI Modal ============ */
.bai-modal { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center; }
.bai-modal[hidden] { display: none; }
.bai-backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.55); backdrop-filter: blur(2px); }
.bai-panel {
    position: relative; background: white; border-radius: 14px;
    width: 95vw; max-width: 900px; max-height: 90vh;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    display: flex; flex-direction: column; overflow: hidden;
}
.bai-header {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.9rem 1.2rem; border-bottom: 1px solid #e5e7eb;
    background: linear-gradient(to right, #faf5ff, #ffffff);
}
.bai-header h2 { margin: 0; font-size: 1.05rem; font-weight: 600; color: #1f2937; }
.bai-step-pill {
    margin-left: 0.4rem; background: #ede9fe; color: #6d28d9;
    font-size: 0.78rem; font-weight: 600;
    padding: 0.18rem 0.55rem; border-radius: 999px;
}
.bai-close {
    margin-left: auto; background: transparent; border: none;
    cursor: pointer; color: #6b7280; padding: 0.3rem;
    display: flex; align-items: center;
}
.bai-close:hover { color: #1f2937; }

.bai-body {
    flex: 1; overflow-y: auto; padding: 1.2rem;
}

.bai-section { margin-bottom: 1.2rem; }
.bai-section-label {
    display: block; font-weight: 600; color: #374151;
    margin-bottom: 0.4rem; font-size: 0.9rem;
}
.bai-section-label .bai-hint {
    display: block; font-weight: 400; color: #6b7280;
    font-size: 0.78rem; margin-top: 0.1rem;
}
.bai-hint { color: #6b7280; font-size: 0.78rem; font-weight: 400; }
.bai-h3 {
    margin: 0 0 0.6rem; font-size: 0.95rem; color: #1f2937;
    display: flex; align-items: center; gap: 0.4rem; font-weight: 600;
}
.bai-h3 .material-symbols-outlined { font-size: 1.1rem; color: #6b7280; }

/* Items table */
.bai-items-table {
    border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;
    background: #fafbfc;
}
.bai-items-head, .bai-item-row {
    display: grid; grid-template-columns: 1.4fr 1fr 2fr 32px;
    gap: 0.5rem; align-items: center;
    padding: 0.5rem 0.7rem;
}
.bai-items-head {
    background: #f1f5f9; font-size: 0.7rem; text-transform: uppercase;
    letter-spacing: 0.04em; color: #475569; font-weight: 600;
    border-bottom: 1px solid #e5e7eb;
}
.bai-item-row { border-bottom: 1px solid #f1f5f9; background: white; }
.bai-item-row:last-child { border-bottom: none; }
.bai-item-row input, .bai-item-row select {
    width: 100%; padding: 0.35rem 0.5rem; font-size: 0.85rem;
    border: 1px solid #cbd5e1; border-radius: 5px; box-sizing: border-box;
    background: white;
}
.bai-item-row input:focus, .bai-item-row select:focus {
    outline: none; border-color: #a78bfa;
}
.bai-item-row .bai-item-name { font-family: ui-monospace, monospace; }
.bai-item-remove {
    background: transparent; border: none; cursor: pointer;
    color: #9ca3af; padding: 0.2rem; border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
}
.bai-item-remove:hover { background: #fee2e2; color: #dc2626; }
/* Outline secondary action — fine purple border, light purple wash on hover.
   Sits outside the items table on its own row so it reads as a deliberate
   "add another" action, not a tacked-on footer cell. */
.bai-add-item {
    display: inline-flex; align-items: center; gap: 0.35rem;
    margin-top: 0.55rem; padding: 0.42rem 0.85rem;
    background: white; color: #6d28d9;
    border: 1px solid #c4b5fd; border-radius: 7px;
    cursor: pointer; font-weight: 500; font-size: 0.85rem;
    font-family: inherit;
    transition: background-color 0.15s ease, border-color 0.15s ease;
}
.bai-add-item:hover { background: #faf5ff; border-color: #a78bfa; }
.bai-add-item .material-symbols-outlined { font-size: 1.05rem; }

/* Context checkboxes — flat accordion list, no nested boxes. The outer
   container has no border : it would create a "box-in-box" with the
   per-group borders, which made the previous version look like blocks
   cannibalizing each other on open/close. Now : just a scrollable list
   of headers separated by hairline rules, each revealing its grid on
   click. */
.bai-context-grid {
    max-height: 380px;
    overflow-y: auto;
    padding: 0.15rem;
    display: flex; flex-direction: column;
    accent-color: #6d28d9;
}
.bai-context-group {
    background: transparent;
    border-bottom: 1px solid #f1f5f9;
}
.bai-context-group:last-child { border-bottom: none; }
.bai-context-group-header {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.55rem 0.5rem;
    background: transparent; border: none; width: 100%;
    cursor: pointer; user-select: none;
    font-family: inherit; text-align: left;
    border-radius: 6px;
    transition: background-color 0.15s ease;
}
.bai-context-group-header:hover { background: #f8fafc; }
.bai-context-group-chevron {
    color: #94a3b8; font-size: 1.1rem; line-height: 1;
    transition: transform 0.2s ease;
    flex-shrink: 0;
}
.bai-context-group[data-open="true"] .bai-context-group-chevron {
    transform: rotate(90deg);
}
.bai-context-group-title {
    font-size: 0.72rem; font-weight: 700; color: #475569;
    text-transform: uppercase; letter-spacing: 0.05em;
    flex: 1;
}
.bai-context-group-count {
    font-size: 0.7rem; font-weight: 600;
    color: #64748b; background: #f1f5f9;
    padding: 0.1rem 0.5rem; border-radius: 9999px;
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.bai-context-group-count.has-checked {
    background: #ede9fe; color: #6d28d9;
}
/* Hide body when collapsed — we use display:none rather than max-height
   transitions because the inner grid has variable height (1 to 10 rows
   per group) and animating that creates jank with the scrollable parent. */
.bai-context-group-body {
    display: none;
    padding: 0.2rem 0.5rem 0.5rem;
}
.bai-context-group[data-open="true"] .bai-context-group-body {
    display: block;
}
/* "Tout cocher / décocher" — purple, uppercase, semi-transparent at rest,
   underline only on hover. No native blue-underline link look. */
.bai-context-toggle-all {
    background: transparent; border: none; cursor: pointer;
    color: #7c3aed;
    font-size: 0.7rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.05em;
    text-decoration: none; opacity: 0.75;
    padding: 0.15rem 0.4rem; border-radius: 4px;
    font-family: inherit;
    transition: opacity 0.15s ease, background-color 0.15s ease;
    flex-shrink: 0;
}
.bai-context-toggle-all:hover { opacity: 1; background: #f5f3ff; }
.bai-context-group-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.25rem 0.5rem;
}
@media (min-width: 720px) {
    .bai-context-group-grid { grid-template-columns: repeat(3, 1fr); }
}
/* Each option : name on the LEFT, token badge pinned on the RIGHT via
   justify-content: space-between so all tokens align vertically across
   the column. The :has(input:checked) selector lights the whole cell
   in violet so the active selection is obvious at a glance. */
.bai-context-item {
    display: flex; align-items: center;
    justify-content: space-between; gap: 0.5rem;
    padding: 0.4rem 0.6rem; border-radius: 6px;
    cursor: pointer; font-size: 0.85rem; line-height: 1.3;
    color: #334155;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.bai-context-item:hover { background: rgba(255,255,255,0.7); }
.bai-context-item:has(input:checked) {
    background: #f5f3ff; color: #6d28d9;
}
.bai-context-item input[type="checkbox"] { margin: 0; flex-shrink: 0; }
.bai-context-item input[disabled] { opacity: 0.6; }
/* Name + checkbox grouped on the left so space-between pushes ONLY the
   token badge / warning to the right. */
.bai-ctx-main {
    display: flex; align-items: center; gap: 0.45rem;
    flex: 1; min-width: 0;
}
.bai-ctx-name {
    flex: 1; min-width: 0;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.bai-ctx-meta {
    display: inline-flex; align-items: center;
    background: #e2e8f0; color: #475569;
    font-size: 0.7rem; font-weight: 600;
    padding: 0.1rem 0.5rem; border-radius: 9999px;
    flex-shrink: 0; font-variant-numeric: tabular-nums;
}
.bai-context-item:has(input:checked) .bai-ctx-meta {
    background: #ede9fe; color: #6d28d9;
}
.bai-ctx-warn {
    color: #f59e0b; font-size: 0.95rem; flex-shrink: 0;
    cursor: help; line-height: 1;
}

/* Prompt textarea */
.bai-prompt {
    width: 100%; box-sizing: border-box; padding: 0.7rem 0.85rem;
    border: 2px solid #e5e7eb; border-radius: 8px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.82rem; line-height: 1.5; resize: vertical;
    background: #fafbfc;
}
.bai-prompt:focus { outline: none; border-color: #a78bfa; background: white; }

.bai-row-2col {
    display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;
}
.bai-input {
    width: 100%; box-sizing: border-box; padding: 0.45rem 0.7rem;
    font-size: 0.9rem; border: 2px solid #e5e7eb; border-radius: 8px;
    background: white;
}
.bai-input:focus { outline: none; border-color: #a78bfa; }

/* Step 2 — estimate grid */
.bai-estimate-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem 1.5rem;
    padding: 0.85rem 1rem; background: #fafbfc;
    border: 1px solid #e5e7eb; border-radius: 8px;
}
.bai-estimate-row { display: flex; justify-content: space-between; font-size: 0.88rem; }
.bai-estimate-row .lbl { color: #6b7280; }
.bai-estimate-row .val { font-weight: 600; color: #1f2937; font-family: ui-monospace, monospace; }
.bai-estimate-row.highlight .val { color: #6d28d9; font-size: 1rem; }

/* Preview rows */
.bai-preview-results { margin-top: 0.7rem; }
.bai-preview-row {
    border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.7rem 0.85rem;
    margin-bottom: 0.5rem; background: white;
}
.bai-preview-row .pr-url {
    font-family: ui-monospace, monospace; font-size: 0.78rem;
    color: #6b7280; margin-bottom: 0.4rem; word-break: break-all;
}
.bai-preview-row .pr-values { font-size: 0.85rem; }
.bai-preview-row .pr-val-line {
    margin: 0.15rem 0; line-height: 1.4;
}
.bai-preview-row .pr-val-key {
    font-family: ui-monospace, monospace; font-weight: 600;
    color: #6d28d9; font-size: 0.78rem; margin-right: 0.4rem;
}

/* Step 3 — progress + live results */
.bai-progress { margin: 0.5rem 0 0.8rem; }
.bai-progress-bar {
    height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;
}
.bai-progress-fill {
    height: 100%; background: linear-gradient(90deg, #a78bfa, #6d28d9);
    transition: width 0.3s ease; width: 0%;
}
.bai-progress-meta {
    margin-top: 0.4rem; font-size: 0.82rem; color: #4b5563;
    display: flex; justify-content: space-between;
}
.bai-run-actions { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
.bai-live-results {
    max-height: 250px; overflow-y: auto;
    border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 0.4rem 0.5rem; background: #fafbfc;
    font-size: 0.82rem;
}
.bai-live-result {
    padding: 0.35rem 0.5rem; border-bottom: 1px solid #f1f5f9;
}
.bai-live-result:last-child { border-bottom: none; }
.bai-live-result .url {
    font-family: ui-monospace, monospace; font-size: 0.76rem;
    color: #6b7280; word-break: break-all;
}
.bai-live-result .vals {
    margin-top: 0.2rem; color: #374151;
}
.bai-final-actions {
    margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;
    text-align: center;
}

/* Footer + buttons */
.bai-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.85rem 1.2rem; border-top: 1px solid #e5e7eb;
    background: #fafbfc;
}
.bai-footer-info { font-size: 0.85rem; color: #6b7280; }
.bai-footer-actions { display: flex; gap: 0.5rem; }
.bai-btn {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.5rem 0.95rem; border-radius: 7px; font-size: 0.88rem;
    font-weight: 500; cursor: pointer; border: 1px solid transparent;
    font-family: inherit; text-decoration: none;
}
/* hidden HTML attribute must beat the inline-flex default — otherwise
   the Suivant/Lancer toggle was broken (both visible at the same time). */
.bai-btn[hidden], .bai-stop-btn[hidden] { display: none !important; }
.bai-btn .material-symbols-outlined { font-size: 1.05rem; }
.bai-btn-primary { background: #6d28d9; color: white; }
.bai-btn-primary:hover { background: #5b21b6; }
.bai-btn-primary:disabled { background: #c4b5fd; cursor: not-allowed; }
.bai-btn-secondary { background: white; color: #6d28d9; border-color: #c4b5fd; }
.bai-btn-secondary:hover { background: #faf5ff; }
.bai-btn-ghost { background: white; color: #475569; border-color: #cbd5e1; }
.bai-btn-ghost:hover { background: #f1f5f9; }
.bai-btn-danger { background: #dc2626; color: white; }
.bai-btn-danger:hover { background: #b91c1c; }

/* Error block */
.bai-error {
    margin-top: 0.6rem; padding: 0.6rem 0.85rem; border-radius: 6px;
    background: #fef2f2; border-left: 3px solid #dc2626; color: #991b1b;
    font-size: 0.85rem;
}
.bai-loading {
    padding: 1rem; text-align: center; color: #6b7280; font-size: 0.85rem;
}

/* ============ Bulk AI Modal — Model combobox (clone of /settings) ============ */
.bai-combo { position: relative; width: 100%; }
.bai-combo-trigger {
    display: flex; align-items: center; justify-content: space-between;
    gap: 0.5rem; width: 100%;
    padding: 0.55rem 0.85rem;
    border: 2px solid #e5e7eb; border-radius: 8px;
    background: white; cursor: pointer; font-size: 0.95rem;
    text-align: left; color: #1f2937; box-sizing: border-box;
    min-height: 42px; font-family: inherit;
}
.bai-combo-trigger:hover { border-color: #cbd5e1; }
.bai-combo[data-open="true"] .bai-combo-trigger { border-color: #a78bfa; }
.bai-combo-display {
    flex: 1; min-width: 0; overflow: hidden;
    text-overflow: ellipsis; white-space: nowrap;
    /* Inherit pointer cursor from the trigger so the whole clickable
       area looks clickable — without this Chrome shows a help-cursor
       (the "?" the user noticed) when a title attribute is on the span. */
    cursor: pointer;
}
.bai-combo-display.placeholder { color: #94a3b8; }
.bai-combo-trigger .material-symbols-outlined {
    color: #64748b; font-size: 1.25rem; transition: transform 0.15s;
}
.bai-combo[data-open="true"] .bai-combo-trigger .material-symbols-outlined {
    transform: rotate(180deg);
}
.bai-combo-dropdown {
    /* position:fixed + JS-computed top/left/width — so the dropdown can
       escape the modal body's `overflow-y: auto` clipping (otherwise it
       gets cut at the panel border instead of opening over it). */
    position: fixed;
    z-index: 10100;
    background: white;
    border: 1px solid #cbd5e1; border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    max-height: 420px; display: none; flex-direction: column;
}
.bai-combo[data-open="true"] .bai-combo-dropdown { display: flex; }
.bai-combo-search-wrap {
    padding: 0.5rem; border-bottom: 1px solid #e2e8f0;
    display: flex; align-items: stretch;
}
.bai-combo-search-field {
    flex: 1; display: flex; align-items: center; gap: 0.5rem;
    padding: 0 0.7rem; border: 1px solid #e2e8f0; border-radius: 6px;
    background: white;
}
.bai-combo-search-field:focus-within { border-color: #a78bfa; }
.bai-combo-search-icon {
    flex-shrink: 0; color: #94a3b8; font-size: 1.1rem; line-height: 1;
}
.bai-combo-search {
    flex: 1; min-width: 0; padding: 0.5rem 0;
    border: none; background: transparent; font-size: 0.9rem;
    outline: none; color: #1f2937;
}
.bai-combo-search::placeholder { color: #94a3b8; }
.bai-combo-list { overflow-y: auto; max-height: 350px; }
.bai-combo-group {
    font-size: 0.72rem; text-transform: uppercase;
    letter-spacing: 0.04em; color: #64748b;
    padding: 0.45rem 0.85rem 0.2rem; background: #f8fafc;
    font-weight: 600; position: sticky; top: 0;
    border-bottom: 1px solid #e2e8f0;
}
.bai-combo-item {
    padding: 0.55rem 0.85rem; cursor: pointer;
    display: flex; flex-direction: column; gap: 0.15rem;
    border-bottom: 1px solid #f1f5f9;
}
.bai-combo-item:hover, .bai-combo-item.active { background: #eff6ff; }
.bai-combo-item.selected { background: #dbeafe; }
.bai-combo-item-name {
    font-size: 0.9rem; color: #1f2937; font-weight: 500;
}
.bai-combo-item-id {
    font-size: 0.75rem; color: #94a3b8;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.bai-combo-item-meta {
    font-size: 0.75rem; color: #6b7280;
    display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 0.1rem;
}
.bai-combo-item-meta .ctx, .bai-combo-item-meta .price { color: #475569; }
.bai-combo-item-meta .free { color: #16a34a; font-weight: 600; }
.bai-combo-empty {
    padding: 1.5rem; text-align: center;
    color: #94a3b8; font-size: 0.85rem;
}

/* Soft warning when an item name matches an existing key — orange
   border, not red : it's allowed (overwrites by URL selection) but
   worth flagging. */
.bai-item-name.bai-item-warn {
    border-color: #f59e0b !important;
    background: #fffbeb;
}
/* Lightweight inline caption: a single-line label followed by real chip
   badges — no double-border, no boxed container that competed visually
   with the items table above it. */
.bai-existing-keys {
    margin-top: 0.7rem;
    display: flex; flex-wrap: wrap; align-items: center;
    gap: 0.3rem 0.4rem;
    font-size: 0.78rem; color: #64748b;
}
.bai-existing-keys .bai-existing-label {
    color: #475569; font-weight: 500;
}
.bai-existing-keys code {
    display: inline-flex; align-items: center;
    background: #f1f5f9; border: none;
    padding: 0.12rem 0.55rem; border-radius: 9999px;
    color: #334155; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.72rem; line-height: 1.5;
}
.bai-collision-warn code {
    display: inline-block;
    background: white; border: 1px solid #fde68a;
    padding: 0.05rem 0.4rem; border-radius: 4px;
    color: #92400e; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.76rem; margin: 0.1rem 0.15rem;
}
.bai-collision-warn {
    margin-top: 0.5rem;
    font-size: 0.82rem; color: #92400e;
    background: #fffbeb; border-left: 3px solid #f59e0b;
    padding: 0.55rem 0.7rem; border-radius: 4px;
    line-height: 1.45;
}
.bai-collision-warn code {
    background: white; border-color: #fde68a; color: #92400e;
}
</style>

<script>
(function () {
    // === State ===
    const state = {
        crawlId:    null,
        crawlPath:  '',
        pageIds:    [],
        items:      [],
        contextFields: ['url'],
        promptTpl:  '',
        modelId:    '',
        availableContextFields: [],
        availableModels: [],
        existingKeys: [],   // generation keys already in pages.generation
        currentStep: 1,
        currentBulkJobId: null,
        pollTimer: null,
    };
    let modelComboHandle = null;

    // === DOM refs ===
    const modal       = document.getElementById('bulkAiModal');
    const stepPill    = document.getElementById('baiStepPill');
    const itemsBody   = document.getElementById('baiItemsBody');
    const addItemBtn  = document.getElementById('baiAddItem');
    const ctxGrid     = document.getElementById('baiContextGrid');
    const promptTpl   = document.getElementById('baiPromptTpl');
    const modelHidden = document.getElementById('baiModel');
    const modelCombo  = document.getElementById('baiModelCombo');
    const backBtn     = document.getElementById('baiBackBtn');
    const nextBtn     = document.getElementById('baiNextBtn');
    const launchBtn   = document.getElementById('baiLaunchBtn');
    const footerInfo  = document.getElementById('baiFooterInfo');
    const estimateGrid= document.getElementById('baiEstimateGrid');
    const runPreviewBtn = document.getElementById('baiRunPreview');
    const previewResults= document.getElementById('baiPreviewResults');
    const runTitle    = document.getElementById('baiRunTitle');
    const progressFill= document.getElementById('baiProgressFill');
    const progressMeta= document.getElementById('baiProgressMeta');
    const stopBtn     = document.getElementById('baiStopBtn');
    const closeBtn    = document.getElementById('baiCloseBtn');
    const finalActions= document.getElementById('baiFinalActions');
    const viewInExpBtn= document.getElementById('baiViewInExplorer');
    // Wire ONCE at init — clicking closes the modal and reloads the page
    // with ?add_cols=generation_xxx,generation_yyy appended, so URL
    // Explorer auto-activates the newly-generated columns instead of
    // forcing the user to find them in the column picker.
    viewInExpBtn.addEventListener('click', (e) => {
        e.preventDefault();
        const itemNames = (state.lastJobItems || []).map(i => 'generation_' + i.name);
        const url = new URL(window.location.href);
        if (itemNames.length) url.searchParams.set('add_cols', itemNames.join(','));
        closeModal();
        window.location.href = url.toString();
    });
    const e1 = document.getElementById('baiStep1Error');
    const e2 = document.getElementById('baiStep2Error');
    const e3 = document.getElementById('baiStep3Error');

    // === T (i18n) ===
    const T = <?= json_encode([
        'items_min'           => __('bulk_gen.err_items_min'),
        'name_invalid'        => __('bulk_gen.err_name_invalid'),
        'duplicate_name'      => __('bulk_gen.err_duplicate_name'),
        'prompt_empty'        => __('bulk_gen.err_prompt_empty'),
        'no_model'            => __('bulk_gen.err_no_model'),
        'estimate_label_urls' => __('bulk_gen.estimate_urls'),
        'estimate_label_batch'=> __('bulk_gen.estimate_batch'),
        'estimate_label_calls'=> __('bulk_gen.estimate_calls'),
        'estimate_label_in'   => __('bulk_gen.estimate_in_tokens'),
        'estimate_label_out'  => __('bulk_gen.estimate_out_tokens'),
        'estimate_label_cost' => __('bulk_gen.estimate_cost'),
        'estimate_label_dur'  => __('bulk_gen.estimate_duration'),
        'estimate_label_model'=> __('bulk_gen.estimate_model'),
        'preview_running'     => __('bulk_gen.preview_running'),
        'launching'           => __('bulk_gen.launching'),
        'status_queued'       => __('bulk_gen.status_queued'),
        'status_running'      => __('bulk_gen.status_running'),
        'status_done'         => __('bulk_gen.status_done'),
        'status_stopped'      => __('bulk_gen.status_stopped'),
        'status_failed'       => __('bulk_gen.status_failed'),
        'urls_summary'        => __('bulk_gen.urls_summary'),
        'key_collides'        => __('bulk_gen.key_collides'),
        'toggle_all'          => __('bulk_gen.toggle_all'),
    ]) ?>;

    // === Public API ===
    window.scouterBulkAi = {
        open(opts) {
            state.crawlId = opts.crawlId || null;
            state.crawlPath = opts.crawlPath || '';
            state.pageIds = Array.isArray(opts.pageIds) ? opts.pageIds : [];
            if (!state.crawlId || state.pageIds.length === 0) {
                alert(<?= json_encode(__('bulk_gen.err_no_selection')) ?>);
                return;
            }
            resetState();
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            footerInfo.textContent = T.urls_summary.replace('{n}', state.pageIds.length);
            loadInitialData();
        },
        close() { closeModal(); },
    };

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }
    modal.addEventListener('click', (e) => {
        if (e.target.closest('[data-close]')) closeModal();
    });

    // === Initial data load ===
    async function loadInitialData() {
        // Context fields — the backend now merges page columns + custom
        // extracts + previously-generated AI keys into one `fields` list,
        // each with its own `group` so renderContextGrid splits them into
        // sections (Identification, …, Extractions, Générations IA).
        try {
            const r = await fetch(`../api/bulk-generate/context-fields?crawl_id=${state.crawlId}`);
            const d = await r.json();
            if (d.success !== false) {
                state.availableContextFields = d.fields || [];
                renderContextGrid();
            }
        } catch (e) {}

        // Existing generation keys in this crawl — used to refuse names
        // that would overwrite a previous job's data.
        try {
            const r = await fetch(`../api/bulk-generate/existing-keys?crawl_id=${state.crawlId}`);
            const d = await r.json();
            if (d.success !== false) {
                state.existingKeys = d.keys || [];
                if (state.items.length) renderItems(); // re-render to show inline warnings
            }
        } catch (e) {}

        // Models — dedicated bulk-generate endpoint (works for all editors, not
        // just admins; /settings/ai/test is admin-only and 403s for users).
        try {
            const r = await fetch('../api/bulk-generate/models');
            const d = await r.json();
            if (d.success !== false && Array.isArray(d.models)) {
                state.availableModels = d.models;
                initModelCombo();
            }
        } catch (e) {}

        // Seed with 1 item by default so the wizard isn't empty.
        if (state.items.length === 0) addItem();
    }

    // Default prompt text — same i18n string we used to put in `placeholder`,
    // now seeded directly as the textarea value so users have something to
    // ship without writing anything. They can edit it freely.
    const DEFAULT_PROMPT_TEMPLATE = <?= json_encode(__('bulk_gen.prompt_placeholder')) ?>;

    function resetState() {
        state.items = [];
        state.contextFields = ['url'];
        state.promptTpl = DEFAULT_PROMPT_TEMPLATE;
        state.currentBulkJobId = null;
        state.currentStep = 1;
        state.existingKeys = [];
        promptTpl.value = DEFAULT_PROMPT_TEMPLATE;
        hideError(e1); hideError(e2); hideError(e3);
        previewResults.innerHTML = '';
        progressFill.style.width = '0%';
        progressMeta.textContent = '';
        finalActions.hidden = true;
        showStep(1);
    }

    // === Items builder ===
    function addItem() {
        state.items.push({ name: '', type: 'text', note: '' });
        renderItems();
    }
    function renderItems() {
        itemsBody.innerHTML = '';
        state.items.forEach((it, idx) => {
            const row = document.createElement('div');
            row.className = 'bai-item-row';
            const collides = it.name && state.existingKeys.includes(it.name);
            row.innerHTML = `
                <input class="bai-item-name${collides ? ' bai-item-warn' : ''}" type="text" placeholder="my_field_name"
                       value="${escapeHtml(it.name)}" maxlength="50">
                <select class="bai-item-type">
                    <option value="text"    ${it.type === 'text'    ? 'selected' : ''}>Text</option>
                    <option value="number"  ${it.type === 'number'  ? 'selected' : ''}>Number</option>
                    <option value="boolean" ${it.type === 'boolean' ? 'selected' : ''}>Boolean</option>
                </select>
                <input class="bai-item-note" type="text" placeholder="ex: max 60 chars"
                       value="${escapeHtml(it.note)}" maxlength="100">
                <button type="button" class="bai-item-remove" title="${escapeHtml(<?= json_encode(__('bulk_gen.remove_item')) ?>)}">
                    <span class="material-symbols-outlined">close</span>
                </button>
            `;
            const [nameInp, typeSel, noteInp, rmBtn] = row.children;
            nameInp.addEventListener('input', () => {
                state.items[idx].name = nameInp.value.toLowerCase().replace(/[^a-z0-9_]/g, '');
                nameInp.value = state.items[idx].name;
                renderCollisionWarning();
                // Toggle warn style live as the user types.
                const c = state.items[idx].name && state.existingKeys.includes(state.items[idx].name);
                nameInp.classList.toggle('bai-item-warn', !!c);
            });
            typeSel.addEventListener('change', () => { state.items[idx].type = typeSel.value; });
            noteInp.addEventListener('input',  () => { state.items[idx].note = noteInp.value; });
            rmBtn.addEventListener('click',    () => { state.items.splice(idx, 1); renderItems(); });
            itemsBody.appendChild(row);
        });
        renderCollisionWarning();
    }

    function renderCollisionWarning() {
        // Two captions :
        //  - "existingKeys" (info) : list of keys already used in this crawl,
        //    shown so the admin knows what's taken
        //  - "collisions"   (warn) : keys this job would overwrite (only when
        //    one of the typed item names matches an existing key)
        const captionId    = 'baiExistingKeysCaption';
        const collisionId  = 'baiCollisionWarn';
        const oldCap   = document.getElementById(captionId);   if (oldCap)   oldCap.remove();
        const oldWarn  = document.getElementById(collisionId); if (oldWarn)  oldWarn.remove();

        // Append into the .bai-section (parent of the items-table) — NOT
        // inside the items-table itself, otherwise the caption hugs the
        // table's left edge instead of aligning with the section's padding.
        const section = itemsBody.closest('.bai-section');
        if (!section) return;

        if (state.existingKeys.length) {
            const cap = document.createElement('div');
            cap.id = captionId;
            cap.className = 'bai-existing-keys';
            cap.innerHTML = `<span class="bai-existing-label">${<?= json_encode(__('bulk_gen.existing_keys_label')) ?>}</span> `
                + state.existingKeys.map(k => `<code>${escapeHtml(k)}</code>`).join('');
            section.appendChild(cap);
        }

        const collidingNames = state.items
            .map(it => it.name)
            .filter(n => n && state.existingKeys.includes(n));
        if (collidingNames.length) {
            const w = document.createElement('div');
            w.id = collisionId;
            w.className = 'bai-collision-warn';
            const codes = collidingNames.map(n => `<code>${escapeHtml(n)}</code>`).join(' ');
            w.innerHTML = '⚠ ' + (<?= json_encode(__('bulk_gen.collision_warn')) ?>).replace('{keys}', codes);
            section.appendChild(w);
        }
    }
    addItemBtn.addEventListener('click', addItem);

    // === Context grid ===
    // Track which accordion sections are open across re-renders. First group
    // is opened by default on the very first render so the user doesn't face
    // an entirely-collapsed panel.
    const openGroups = new Set();

    function renderContextGrid() {
        ctxGrid.innerHTML = '';
        // Group by f.group so the wizard renders sections (Identification,
        // SEO content, Indexability, …) — needed because the full list of
        // pages columns is ~30 items and a flat grid would be unreadable.
        const groups = {};
        const groupOrder = [];
        state.availableContextFields.forEach(f => {
            const g = f.group || 'Autres';
            if (!groups[g]) { groups[g] = []; groupOrder.push(g); }
            groups[g].push(f);
        });

        // Default-open: only the first group on first render.
        if (openGroups.size === 0 && groupOrder.length > 0) {
            openGroups.add(groupOrder[0]);
        }

        groupOrder.forEach(groupName => {
            const section = document.createElement('div');
            section.className = 'bai-context-group';
            section.dataset.group = groupName;
            section.dataset.open  = openGroups.has(groupName) ? 'true' : 'false';

            // ----- Header (clickable, toggles open/close) -----
            const header = document.createElement('button');
            header.type = 'button';
            header.className = 'bai-context-group-header';
            header.setAttribute('aria-expanded', openGroups.has(groupName) ? 'true' : 'false');

            const chevron = document.createElement('span');
            chevron.className = 'material-symbols-outlined bai-context-group-chevron';
            chevron.textContent = 'chevron_right';
            header.appendChild(chevron);

            const title = document.createElement('span');
            title.className = 'bai-context-group-title';
            title.textContent = groupName;
            header.appendChild(title);

            // Count badge — shows N/total, lights up purple when N > 0 so
            // the user can see at a glance where their selections are even
            // when the section is collapsed.
            const togglable = groups[groupName].filter(f => !f.always);
            const checkedCount = togglable.filter(f => state.contextFields.includes(f.key)).length;
            const countBadge = document.createElement('span');
            countBadge.className = 'bai-context-group-count' + (checkedCount > 0 ? ' has-checked' : '');
            countBadge.textContent = checkedCount + '/' + togglable.length;
            // Hide the badge entirely if the group has no togglable items
            // (e.g. just `url` with `always:true`).
            if (togglable.length === 0) countBadge.style.display = 'none';
            header.appendChild(countBadge);

            // "Tout cocher" stays accessible directly in the header line
            // so the user doesn't have to expand a section just to bulk-toggle.
            if (togglable.length > 0) {
                const toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'bai-context-toggle-all';
                toggleBtn.textContent = T.toggle_all;
                // Stop the click from also collapsing/expanding the section.
                toggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleGroup(togglable);
                });
                header.appendChild(toggleBtn);
            }

            header.addEventListener('click', () => {
                const nowOpen = section.dataset.open !== 'true';
                if (nowOpen) {
                    // Single-open accordion : opening a section closes every
                    // other. Collapse the previously-open siblings in the DOM
                    // directly so we don't need a full re-render (which would
                    // flicker and lose scroll position).
                    openGroups.clear();
                    openGroups.add(groupName);
                    ctxGrid.querySelectorAll('.bai-context-group').forEach(g => {
                        if (g === section) return;
                        g.dataset.open = 'false';
                        const h = g.querySelector('.bai-context-group-header');
                        if (h) h.setAttribute('aria-expanded', 'false');
                    });
                } else {
                    openGroups.delete(groupName);
                }
                section.dataset.open = nowOpen ? 'true' : 'false';
                header.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');
            });

            section.appendChild(header);

            // ----- Body (collapsible) -----
            const body = document.createElement('div');
            body.className = 'bai-context-group-body';

            const grid = document.createElement('div');
            grid.className = 'bai-context-group-grid';
            groups[groupName].forEach(f => {
                const label = document.createElement('label');
                label.className = 'bai-context-item';
                const checked = state.contextFields.includes(f.key) || f.always;
                // Layout : [☐ Name …]                    [~Xt] [⚠]
                // checkbox + name grouped left, token badge pinned right
                // via .bai-context-item's justify-content:space-between,
                // so badges align vertically across all rows.
                label.innerHTML = `
                    <span class="bai-ctx-main">
                        <input type="checkbox" data-field="${escapeHtml(f.key)}" ${checked ? 'checked' : ''} ${f.always ? 'disabled' : ''}>
                        <span class="bai-ctx-name">${escapeHtml(f.label)}</span>
                    </span>
                    <span class="bai-ctx-meta">~${f.avg_tokens}t</span>
                    ${f.warning ? `<span class="bai-ctx-warn" title="${escapeHtml(f.warning)}">⚠</span>` : ''}
                `;
                const cb = label.querySelector('input');
                cb.addEventListener('change', () => {
                    if (cb.checked) {
                        if (!state.contextFields.includes(f.key)) state.contextFields.push(f.key);
                    } else {
                        state.contextFields = state.contextFields.filter(k => k !== f.key);
                    }
                    // Live-update the group's count badge without losing the
                    // accordion open/close state of OTHER groups.
                    const n = togglable.filter(x => state.contextFields.includes(x.key)).length;
                    countBadge.textContent = n + '/' + togglable.length;
                    countBadge.classList.toggle('has-checked', n > 0);
                });
                grid.appendChild(label);
            });
            body.appendChild(grid);
            section.appendChild(body);
            ctxGrid.appendChild(section);
        });
    }

    // Smart toggle : if everything (except `always`) is already checked,
    // uncheck the whole group ; otherwise check them all. Re-renders to
    // reflect the new state in the checkboxes — open/closed state is
    // preserved via the persistent `openGroups` Set.
    function toggleGroup(togglableFields) {
        const allChecked = togglableFields.every(f => state.contextFields.includes(f.key));
        togglableFields.forEach(f => {
            if (allChecked) {
                state.contextFields = state.contextFields.filter(k => k !== f.key);
            } else if (!state.contextFields.includes(f.key)) {
                state.contextFields.push(f.key);
            }
        });
        renderContextGrid();
    }

    // === Custom model combobox (clone of /settings) ===
    function initModelCombo() {
        // Default to the configured model_light from /settings.
        const defaultModel = <?= json_encode(\App\Settings\AppSettings::get('ai.openrouter.model_light') ?? '') ?>;
        if (defaultModel && state.availableModels.some(m => m.id === defaultModel)) {
            modelHidden.value = defaultModel;
            state.modelId = defaultModel;
        } else if (state.availableModels.length) {
            modelHidden.value = state.availableModels[0].id;
            state.modelId = state.availableModels[0].id;
        }
        modelComboHandle = makeCombo(modelCombo, modelHidden, {
            placeholder: <?= json_encode(__('bulk_gen.model_loading')) ?>,
        });
        modelComboHandle.setModels(state.availableModels);
    }

    function makeCombo(rootEl, hiddenInput, opts) {
        const trigger   = rootEl.querySelector('.bai-combo-trigger');
        const display   = rootEl.querySelector('.bai-combo-display');
        const dropdown  = rootEl.querySelector('.bai-combo-dropdown');
        const searchInp = rootEl.querySelector('.bai-combo-search');
        const list      = rootEl.querySelector('.bai-combo-list');
        let models = [], visible = [], activeIndex = -1;

        function pricePerMillion(p) {
            const v = (p || 0) * 1e6;
            return Math.abs(v) < 0.01 ? '$' + v.toFixed(4) : '$' + v.toFixed(2);
        }
        function describeModel(m) {
            const parts = [];
            if (m.context_length) {
                const ctxK = Math.round(m.context_length / 1000);
                parts.push({ cls: 'ctx',   text: 'ctx ' + ctxK + 'k' });
            }
            const isFree = (m.prompt_price === 0 && m.completion_price === 0);
            if (isFree) parts.push({ cls: 'free', text: 'gratuit' });
            else        parts.push({ cls: 'price', text: 'in ' + pricePerMillion(m.prompt_price) + ' / out ' + pricePerMillion(m.completion_price) + ' / 1M' });
            return parts;
        }
        function matches(m, q) {
            if (!q) return true;
            const h = (m.id + ' ' + m.name).toLowerCase();
            return q.toLowerCase().trim().split(/\s+/).every(t => h.includes(t));
        }
        function render(q) {
            list.innerHTML = '';
            const matched = models.filter(m => matches(m, q));
            if (!matched.length) {
                const empty = document.createElement('div');
                empty.className = 'bai-combo-empty';
                empty.textContent = <?= json_encode(__('bulk_gen.model_no_match')) ?>;
                list.appendChild(empty);
                visible = []; activeIndex = -1; return;
            }
            const groups = {};
            matched.forEach(m => {
                const p = (m.id.split('/')[0] || 'other');
                (groups[p] = groups[p] || []).push(m);
            });
            visible = [];
            Object.keys(groups).sort().forEach(p => {
                const h = document.createElement('div');
                h.className = 'bai-combo-group';
                h.textContent = p;
                list.appendChild(h);
                groups[p].forEach(m => {
                    const item = document.createElement('div');
                    item.className = 'bai-combo-item' + (m.id === hiddenInput.value ? ' selected' : '');
                    item.dataset.id = m.id;
                    const n = document.createElement('div');
                    n.className = 'bai-combo-item-name';
                    n.textContent = m.name;
                    item.appendChild(n);
                    const id = document.createElement('div');
                    id.className = 'bai-combo-item-id';
                    id.textContent = m.id;
                    item.appendChild(id);
                    const meta = document.createElement('div');
                    meta.className = 'bai-combo-item-meta';
                    describeModel(m).forEach(part => {
                        const s = document.createElement('span');
                        s.className = part.cls;
                        s.textContent = part.text;
                        meta.appendChild(s);
                    });
                    item.appendChild(meta);
                    item.addEventListener('mousedown', e => { e.preventDefault(); select(m); });
                    list.appendChild(item);
                    visible.push({ model: m, el: item });
                });
            });
            activeIndex = visible.findIndex(v => v.model.id === hiddenInput.value);
            if (activeIndex < 0) activeIndex = 0;
            highlight();
        }
        function highlight() {
            visible.forEach((v, i) => v.el.classList.toggle('active', i === activeIndex));
            if (visible[activeIndex]) visible[activeIndex].el.scrollIntoView({ block: 'nearest' });
        }
        function renderDisplay() {
            const m = models.find(x => x.id === hiddenInput.value);
            if (m) {
                display.classList.remove('placeholder');
                display.textContent = m.name;
                // Tooltip moved to the trigger button (not the inner span) :
                // some browsers show a help-cursor / "?" icon when a title is
                // on a non-interactive child of a button, which made the
                // central area feel non-clickable.
                trigger.title = m.id;
                display.removeAttribute('title');
            } else {
                display.classList.add('placeholder');
                display.textContent = opts.placeholder || '';
                trigger.removeAttribute('title');
                display.removeAttribute('title');
            }
        }
        function select(m) {
            hiddenInput.value = m.id;
            state.modelId = m.id;
            renderDisplay();
            close();
            trigger.focus();
        }
        function positionDropdown() {
            // Anchor next to the trigger button using viewport coordinates.
            // position:fixed lets us escape the modal body's overflow clip.
            //
            // Flip logic : if there's not enough room below for a usable
            // dropdown, open UPWARDS instead. We also size max-height to
            // whatever room is actually available, so the dropdown is
            // never cut off the viewport.
            const rect = trigger.getBoundingClientRect();
            const wantedH    = 420;            // matches CSS max-height
            const margin     = 8;
            const spaceBelow = window.innerHeight - rect.bottom - margin;
            const spaceAbove = rect.top - margin;
            const openUp     = spaceBelow < 200 && spaceAbove > spaceBelow;

            dropdown.style.left  = rect.left  + 'px';
            dropdown.style.width = rect.width + 'px';

            if (openUp) {
                dropdown.style.top    = 'auto';
                dropdown.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
                dropdown.style.maxHeight = Math.min(wantedH, spaceAbove) + 'px';
            } else {
                dropdown.style.bottom = 'auto';
                dropdown.style.top    = (rect.bottom + 4) + 'px';
                dropdown.style.maxHeight = Math.min(wantedH, spaceBelow) + 'px';
            }
        }
        function open() {
            if (trigger.disabled) return;
            rootEl.setAttribute('data-open', 'true');
            searchInp.value = '';
            positionDropdown();
            render('');
            setTimeout(() => searchInp.focus(), 10);
        }
        function close() { rootEl.setAttribute('data-open', 'false'); }

        trigger.addEventListener('click', () => {
            rootEl.getAttribute('data-open') === 'true' ? close() : open();
        });
        searchInp.addEventListener('input', () => render(searchInp.value));
        searchInp.addEventListener('keydown', e => {
            if      (e.key === 'ArrowDown') { activeIndex = Math.min(activeIndex + 1, visible.length - 1); highlight(); e.preventDefault(); }
            else if (e.key === 'ArrowUp')   { activeIndex = Math.max(activeIndex - 1, 0); highlight(); e.preventDefault(); }
            else if (e.key === 'Enter')     { if (visible[activeIndex]) select(visible[activeIndex].model); e.preventDefault(); }
            else if (e.key === 'Escape')    { close(); trigger.focus(); }
        });
        htmxPageListener(document, 'mousedown', e => {
            if (!rootEl.contains(e.target) && !dropdown.contains(e.target)) close();
        });
        // Close on OUTSIDE scroll/resize so the dropdown doesn't drift away
        // from its anchor. Crucially, ignore scroll events that bubble FROM
        // INSIDE the dropdown — otherwise scrolling the model list itself
        // would close the dropdown immediately (chicken-and-egg).
        htmxPageListener(document, 'scroll', (e) => {
            if (rootEl.getAttribute('data-open') !== 'true') return;
            if (e.target && (dropdown === e.target || dropdown.contains(e.target))) return;
            close();
        }, true);
        htmxPageListener(window, 'resize', () => { if (rootEl.getAttribute('data-open') === 'true') close(); });

        return {
            setModels(newModels) { models = newModels; renderDisplay(); },
        };
    }

    // === Step navigation ===
    function showStep(n) {
        state.currentStep = n;
        stepPill.textContent = n + ' / 3';
        modal.querySelectorAll('.bai-step').forEach(s => {
            s.hidden = (parseInt(s.dataset.step, 10) !== n);
        });
        backBtn.hidden   = (n === 1 || n === 3);
        nextBtn.hidden   = (n !== 1);
        launchBtn.hidden = (n !== 2);
    }
    backBtn.addEventListener('click', () => { if (state.currentStep > 1) showStep(state.currentStep - 1); });

    // === Step 1 → 2 (Next) ===
    nextBtn.addEventListener('click', async () => {
        const err = validateStep1();
        if (err) { showError(e1, err); return; }
        hideError(e1);
        state.promptTpl = promptTpl.value.trim();
        showStep(2);
        await refreshEstimate();
    });

    function validateStep1() {
        if (state.items.length === 0) return T.items_min;
        const seen = new Set();
        for (const it of state.items) {
            if (!/^[a-z][a-z0-9_]{0,49}$/.test(it.name)) return T.name_invalid + ' : "' + it.name + '"';
            if (seen.has(it.name)) return T.duplicate_name + ' : ' + it.name;
            seen.add(it.name);
            // NOTE : key collisions are now a WARNING, not a blocker.
            // The JSONB merge only overwrites the keys for URLs in the
            // current selection ; other URLs keep their value untouched.
        }
        if (!promptTpl.value.trim()) return T.prompt_empty;
        if (!state.modelId) return T.no_model;
        return null;
    }

    async function refreshEstimate() {
        estimateGrid.innerHTML = `<div class="bai-loading">${escapeHtml(T.estimate_label_urls)}…</div>`;
        try {
            const r = await fetch('../api/bulk-generate/estimate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(jobPayload()),
            });
            const d = await r.json();
            if (!d.success) { showError(e2, d.error || 'estimate failed'); return; }
            renderEstimate(d.estimate, d.model);
        } catch (e) {
            showError(e2, 'estimate error: ' + e.message);
        }
    }

    function renderEstimate(est, model) {
        const fmtUsd = v => '$' + (Math.abs(v) < 0.01 ? v.toFixed(6) : v.toFixed(4));
        const rows = [
            [T.estimate_label_urls,  state.pageIds.length.toLocaleString()],
            [T.estimate_label_batch, est.batch_size],
            [T.estimate_label_calls, est.api_calls.toLocaleString()],
            [T.estimate_label_in,    est.input_tokens.toLocaleString()],
            [T.estimate_label_out,   est.output_tokens.toLocaleString()],
            [T.estimate_label_dur,   '~' + Math.ceil(est.estimated_seconds / 60) + ' min'],
            [T.estimate_label_model, (model.name || model.id) + (model.supports_tools ? '' : '')],
        ];
        estimateGrid.innerHTML = rows.map(([k, v]) =>
            `<div class="bai-estimate-row"><span class="lbl">${escapeHtml(k)}</span><span class="val">${escapeHtml(String(v))}</span></div>`
        ).join('') + `<div class="bai-estimate-row highlight" style="grid-column: 1 / -1;"><span class="lbl">${escapeHtml(T.estimate_label_cost)}</span><span class="val">${fmtUsd(est.estimated_cost)}</span></div>`;
    }

    // === Preview (Step 2) ===
    runPreviewBtn.addEventListener('click', async () => {
        runPreviewBtn.disabled = true;
        previewResults.innerHTML = `<div class="bai-loading">${escapeHtml(T.preview_running)}</div>`;
        try {
            const r = await fetch('../api/bulk-generate/preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(jobPayload({ truncate: 3 })),
            });
            const d = await r.json();
            if (!d.success) {
                previewResults.innerHTML = '';
                showError(e2, d.error || 'preview failed');
                return;
            }
            renderPreviewResults(d.preview || []);
        } catch (e) {
            previewResults.innerHTML = '';
            showError(e2, 'preview error: ' + e.message);
        } finally {
            runPreviewBtn.disabled = false;
        }
    });

    function renderPreviewResults(rows) {
        if (!rows.length) { previewResults.innerHTML = '<em style="color:#9ca3af;font-size:0.85rem;">No preview returned.</em>'; return; }
        previewResults.innerHTML = rows.map(r => {
            const valsHtml = Object.entries(r.values).map(([k, v]) =>
                `<div class="pr-val-line"><span class="pr-val-key">${escapeHtml(k)}</span>${escapeHtml(String(v))}</div>`
            ).join('');
            return `<div class="bai-preview-row">
                <div class="pr-url">${escapeHtml(r.url || r.page_id)}</div>
                <div class="pr-values">${valsHtml}</div>
            </div>`;
        }).join('');
    }

    // === Launch (Step 2 → 3) ===
    launchBtn.addEventListener('click', async () => {
        launchBtn.disabled = true;
        showStep(3);
        runTitle.textContent = T.launching;
        try {
            const r = await fetch('../api/bulk-generate/start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(jobPayload()),
            });
            const d = await r.json();
            if (!d.success) {
                showError(e3, d.error || 'start failed');
                runTitle.textContent = T.status_failed;
                return;
            }
            state.currentBulkJobId = d.bulk_job_id;
            stopBtn.hidden = false;
            startPolling();
        } catch (e) {
            showError(e3, 'start error: ' + e.message);
        } finally {
            launchBtn.disabled = false;
        }
    });

    function startPolling() {
        const tick = async () => {
            if (!state.currentBulkJobId) return;
            try {
                const r = await fetch(`../api/bulk-generate/status?bulk_job_id=${state.currentBulkJobId}`);
                const d = await r.json();
                if (!d.success) return;
                renderRunStatus(d);
                if (['done', 'failed', 'stopped'].includes(d.status)) {
                    clearInterval(state.pollTimer); state.pollTimer = null;
                    stopBtn.hidden = true;
                    // Remember which items were generated, so the
                    // "Voir dans URL Explorer" button can auto-activate
                    // them as columns when it reloads the page.
                    state.lastJobItems = d.items || [];
                    // Show the CTA only when the job actually produced
                    // something. On a hard failure there's no new column.
                    finalActions.hidden = (d.status === 'failed' && d.processed_count === 0);
                }
            } catch (e) {}
        };
        tick();
        state.pollTimer = setInterval(tick, 2000);
    }

    function renderRunStatus(d) {
        const pct = d.url_count > 0 ? Math.round(d.processed_count * 100 / d.url_count) : 0;
        progressFill.style.width = pct + '%';
        const statusLabel = T['status_' + d.status] || d.status;
        const itemsStr = (d.items || []).map(i => i.name).join(', ');
        runTitle.textContent = `${statusLabel} — ${itemsStr}`;
        progressMeta.innerHTML = `
            <span>${d.processed_count} / ${d.url_count} (${pct}%)${d.failed_count > 0 ? ' — ' + d.failed_count + ' failed' : ''}</span>
            <span>${d.input_tokens.toLocaleString()} in / ${d.output_tokens.toLocaleString()} out · $${(d.actual_cost || 0).toFixed(4)}</span>
        `;
        // Surface errors when the job ends in failure or has a non-zero
        // failed_count — gives the user actionable info instead of just
        // "Échec — h1" with no detail.
        if (d.status === 'failed' && d.error_message) {
            showError(e3, d.error_message);
        } else if (d.failed_count > 0 && d.errors_sample) {
            const samples = Object.entries(d.errors_sample).slice(0, 5);
            if (samples.length) {
                const msg = samples.map(([pid, err]) => `${pid}: ${err}`).join(' | ');
                showError(e3, 'Erreurs détectées (échantillon) : ' + msg);
            }
        }
    }

    stopBtn.addEventListener('click', async () => {
        if (!state.currentBulkJobId) return;
        if (!confirm(<?= json_encode(__('bulk_gen.stop_confirm')) ?>)) return;
        try {
            await fetch('../api/bulk-generate/stop', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bulk_job_id: state.currentBulkJobId }),
            });
        } catch (e) {}
    });

    closeBtn.addEventListener('click', () => {
        // Keep polling stopped but leave the job running on the server.
        closeModal();
    });

    // === Helpers ===
    function jobPayload(opts) {
        opts = opts || {};
        const ids = opts.truncate ? state.pageIds.slice(0, opts.truncate) : state.pageIds;
        return {
            crawl_id: state.crawlId,
            page_ids: ids,
            items: state.items,
            context_fields: state.contextFields,
            prompt_template: promptTpl.value.trim(),
            model: state.modelId,
        };
    }

    function showError(el, msg) { el.textContent = msg; el.hidden = false; }
    function hideError(el)      { el.textContent = ''; el.hidden = true; }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }
})();
</script>
