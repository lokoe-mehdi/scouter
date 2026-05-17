<?php
/**
 * Admin Settings page.
 *
 * Currently a single section: AI provider (Gemini). Designed to grow — every
 * new section should be a self-contained `.settings-card` so we can keep
 * adding without restructuring.
 */

require_once(__DIR__ . '/init.php');
$auth->requireAdmin(false);

use App\Settings\AppSettings;

$hasEncryption = AppSettings::hasEncryptionKey();
$apiKey        = AppSettings::get('ai.gemini.api_key');
$maskedKey     = $apiKey !== null ? AppSettings::maskSecret($apiKey) : '';
$currentModel  = AppSettings::get('ai.gemini.model') ?? '';
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
        .settings-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
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
            grid-template-columns: 160px 1fr auto;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .settings-row label {
            font-weight: 500;
            color: var(--text-primary);
        }
        .settings-row input,
        .settings-row select {
            padding: 0.6rem 0.9rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            width: 100%;
            box-sizing: border-box;
        }
        .settings-row input:focus,
        .settings-row select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
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
    </style>
</head>
<body>
    <?php $headerContext = 'admin'; $isInSubfolder = true; include(__DIR__ . '/../components/top-header.php'); ?>

    <div class="container" style="max-width: 900px; margin: 2rem auto; padding: 0 2rem;">
        <div class="admin-header">
            <div>
                <h1 class="page-title"><?= __('settings.heading') ?></h1>
                <p style="color: var(--text-secondary); margin-top: 0.5rem;">
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

        <!-- ================== AI Provider (Gemini) ================== -->
        <div class="settings-card">
            <h2>
                <span class="material-symbols-outlined">auto_awesome</span>
                <?= __('settings.ai_section_title') ?>
            </h2>
            <p class="card-subtitle"><?= __('settings.ai_section_subtitle') ?></p>

            <div class="settings-row">
                <label for="gemini-api-key"><?= __('settings.api_key_label') ?></label>
                <input type="password" id="gemini-api-key"
                       placeholder="<?= $maskedKey !== '' ? htmlspecialchars($maskedKey) : 'AIza...' ?>"
                       autocomplete="off" <?= !$hasEncryption ? 'disabled' : '' ?>>
                <button type="button" class="btn" id="btn-test-key"
                        <?= !$hasEncryption ? 'disabled' : '' ?>>
                    <span class="material-symbols-outlined">science</span>
                    <?= __('settings.btn_test') ?>
                </button>
            </div>
            <div class="settings-status" id="test-status"></div>

            <div class="settings-row" style="margin-top: 1.5rem;">
                <label for="gemini-model"><?= __('settings.model_label') ?></label>
                <select id="gemini-model" <?= !$hasEncryption ? 'disabled' : '' ?>>
                    <?php if ($currentModel !== ''): ?>
                        <option value="<?= htmlspecialchars($currentModel) ?>" selected>
                            <?= htmlspecialchars($currentModel) ?>
                        </option>
                    <?php else: ?>
                        <option value=""><?= __('settings.model_placeholder') ?></option>
                    <?php endif; ?>
                </select>
                <span></span>
            </div>
            <div class="settings-status info" id="model-info">
                <?= __('settings.model_hint') ?>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-primary-action" id="btn-save"
                        <?= !$hasEncryption ? 'disabled' : '' ?>>
                    <span class="material-symbols-outlined">save</span>
                    <?= __('settings.btn_save') ?>
                </button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const apiKeyInput = document.getElementById('gemini-api-key');
        const modelSelect = document.getElementById('gemini-model');
        const testBtn     = document.getElementById('btn-test-key');
        const saveBtn     = document.getElementById('btn-save');
        const testStatus  = document.getElementById('test-status');

        const T = {
            testing:    <?= json_encode(__('settings.status_testing')) ?>,
            test_ok:    <?= json_encode(__('settings.status_test_ok')) ?>,
            test_fail:  <?= json_encode(__('settings.status_test_fail')) ?>,
            saving:     <?= json_encode(__('settings.status_saving')) ?>,
            saved:      <?= json_encode(__('settings.status_saved')) ?>,
            save_fail:  <?= json_encode(__('settings.status_save_fail')) ?>,
            no_key:     <?= json_encode(__('settings.status_no_key')) ?>,
            no_model:   <?= json_encode(__('settings.status_no_model')) ?>,
        };

        function setStatus(el, cls, msg) {
            el.className = 'settings-status ' + cls;
            el.textContent = msg;
        }

        testBtn.addEventListener('click', async () => {
            const key = apiKeyInput.value.trim();
            // empty key = test the one currently stored
            setStatus(testStatus, 'info', T.testing);
            try {
                const res = await fetch('../api/settings/ai/test', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ api_key: key }),
                });
                const data = await res.json();
                if (!res.ok || data.success === false) {
                    setStatus(testStatus, 'err', T.test_fail + ' ' + (data.error || data.message || ''));
                    return;
                }
                // Response::success() merges data at the root level (no `data.data` wrapper).
                const models = data.models || [];
                const previouslySelected = modelSelect.value;
                modelSelect.innerHTML = '';
                models.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.id;
                    opt.textContent = m.display_name || m.id;
                    modelSelect.appendChild(opt);
                });
                if (previouslySelected && models.some(m => m.id === previouslySelected)) {
                    modelSelect.value = previouslySelected;
                }
                setStatus(testStatus, 'ok', T.test_ok.replace('{count}', models.length));
            } catch (e) {
                setStatus(testStatus, 'err', T.test_fail + ' ' + e.message);
            }
        });

        saveBtn.addEventListener('click', async () => {
            const key = apiKeyInput.value.trim();
            const model = modelSelect.value;
            if (!model) {
                setStatus(testStatus, 'err', T.no_model);
                return;
            }
            setStatus(testStatus, 'info', T.saving);
            try {
                const body = { model };
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
    })();
    </script>
</body>
</html>
