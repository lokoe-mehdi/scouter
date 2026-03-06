<?php
/**
 * Configuration du crawl (PostgreSQL)
 * $crawlRecord est défini dans dashboard.php et contient la config en JSONB
 */

$configData = null;
$configError = null;

// Lire la config depuis le champ JSONB de la table crawls
if (!empty($crawlRecord->config)) {
    try {
        // La config est déjà en JSONB, on la décode
        if (is_string($crawlRecord->config)) {
            $configData = json_decode($crawlRecord->config, true);
        } else {
            $configData = (array)$crawlRecord->config;
        }
        
        if (empty($configData)) {
            $configError = __('config.error_empty_config');
    }
    } catch (Exception $e) {
        $configError = __('config.error_reading_config') . $e->getMessage();
    }
} else {
    $configError = __('config.error_no_config');
}
?>

<style>
.config-layout {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.config-section {
    background: var(--card-bg);
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.config-section h2 {
    margin: 0 0 1.5rem 0;
    color: var(--text-primary);
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid var(--border-color);
}

.config-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
}

.config-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 1rem;
    background: var(--background);
    border-radius: 6px;
    border-left: 3px solid var(--primary-color);
}

.config-label {
    font-size: 0.85rem;
    color: var(--text-secondary);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.config-value {
    font-size: 1.1rem;
    color: var(--text-primary);
    font-weight: 500;
}

.config-value.boolean {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.config-value.boolean.true {
    color: var(--success);
}

.config-value.boolean.false {
    color: var(--danger);
}

.config-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.config-list li {
    padding: 0.5rem 0.75rem;
    background: var(--card-bg);
    border-radius: 4px;
    border-left: 2px solid var(--primary-color);
}

.config-code {
    font-family: 'Courier New', monospace;
    background: var(--background);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.95rem;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.alert-error {
    background: #FEE;
    color: #C33;
    border-left: 4px solid #C33;
}
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h1 class="page-title" style="margin: 0;"><?= __('config.page_title') ?></h1>
    <?php if ($canManageCurrentProject): ?>
    <button class="btn btn-danger" onclick="deleteCrawl()" style="display: flex; align-items: center; gap: 0.5rem;">
        <span class="material-symbols-outlined">delete</span>
        <?= __('config.delete_crawl') ?>
    </button>
    <?php endif; ?>
</div>

<?php if ($configError): ?>
    <div class="alert alert-error">
        <strong><?= __('common.error') ?> :</strong> <?= htmlspecialchars($configError) ?>
    </div>
<?php elseif ($configData): ?>
    <div class="config-layout">
        <!-- Configuration générale -->
        <?php if (isset($configData['general'])): ?>
        <div class="config-section">
            <h2>
                <span class="material-symbols-outlined">settings</span>
                <?= __('config.section_general') ?>
            </h2>
            <table class="data-table">
                <tbody>
                    <?php if (isset($configData['general']['start'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);"><?= __('config.start_url') ?></td>
                        <td>
                            <a href="<?= htmlspecialchars($configData['general']['start']) ?>" target="_blank" style="color: var(--primary-color); text-decoration: none;">
                                <?= htmlspecialchars($configData['general']['start']) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['general']['domains']) && is_array($configData['general']['domains'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);"><?= __('config.allowed_domains') ?></td>
                        <td>
                            <?php foreach ($configData['general']['domains'] as $domain): ?>
                                <div style="padding: 0.25rem 0;"><?= htmlspecialchars($domain) ?></div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['general']['depthMax'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);"><?= __('config.max_depth') ?></td>
                        <td><strong><?= htmlspecialchars($configData['general']['depthMax']) ?></strong> <?= __('common.levels') ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['general']['user-agent'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);">User-Agent</td>
                        <td><code class="config-code"><?= htmlspecialchars($configData['general']['user-agent']) ?></code></td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['general']['crawl_speed'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);"><?= __('config.crawl_speed') ?></td>
                        <td>
                            <?php
                            $speed = $configData['general']['crawl_speed'];
                            $speedLabels = [
                                'very_slow' => ['label' => __('config.speed_very_slow'), 'desc' => __('config.speed_very_slow_desc'), 'icon' => 'speed', 'color' => 'var(--danger)'],
                                'slow' => ['label' => __('config.speed_slow'), 'desc' => __('config.speed_slow_desc'), 'icon' => 'speed', 'color' => 'var(--warning)'],
                                'fast' => ['label' => __('config.speed_fast'), 'desc' => __('config.speed_fast_desc'), 'icon' => 'speed', 'color' => 'var(--success)'],
                                'unlimited' => ['label' => __('config.speed_unlimited'), 'desc' => __('config.speed_unlimited_desc'), 'icon' => 'bolt', 'color' => 'var(--primary-color)']
                            ];
                            $speedInfo = $speedLabels[$speed] ?? $speedLabels['fast'];
                            ?>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span class="material-symbols-outlined" style="font-size: 20px; color: <?= $speedInfo['color'] ?>;">
                                    <?= $speedInfo['icon'] ?>
                                </span>
                                <div>
                                    <div style="font-weight: 600; color: var(--text-primary);"><?= $speedInfo['label'] ?></div>
                                    <div style="font-size: 0.9rem; color: var(--text-secondary);"><?= $speedInfo['desc'] ?></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['general']['crawl_mode'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);"><?= __('config.crawl_mode') ?></td>
                        <td>
                            <?php
                            $mode = $configData['general']['crawl_mode'];
                            $modeLabels = [
                                'classic' => ['label' => __('config.mode_classic'), 'desc' => __('config.mode_classic_desc'), 'icon' => 'http', 'color' => 'var(--info)'],
                                'javascript' => ['label' => __('config.mode_javascript'), 'desc' => __('config.mode_javascript_desc'), 'icon' => 'javascript', 'color' => 'var(--warning)']
                            ];
                            $modeInfo = $modeLabels[$mode] ?? $modeLabels['classic'];
                            ?>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span class="material-symbols-outlined" style="font-size: 20px; color: <?= $modeInfo['color'] ?>;">
                                    <?= $modeInfo['icon'] ?>
                                </span>
                                <div>
                                    <div style="font-weight: 600; color: var(--text-primary);"><?= $modeInfo['label'] ?></div>
                                    <div style="font-size: 0.9rem; color: var(--text-secondary);"><?= $modeInfo['desc'] ?></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($crawlRecord->crawl_type)): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);"><?= __('config.crawl_type') ?></td>
                        <td>
                            <?php
                            $crawlType = $crawlRecord->crawl_type;
                            $typeLabels = [
                                'spider' => ['label' => __('config.type_spider'), 'icon' => 'bug_report', 'color' => 'var(--info)'],
                                'list' => ['label' => __('config.type_list'), 'icon' => 'list', 'color' => 'var(--warning)']
                            ];
                            $typeInfo = $typeLabels[$crawlType] ?? $typeLabels['spider'];
                            ?>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span class="material-symbols-outlined" style="font-size: 20px; color: <?= $typeInfo['color'] ?>;">
                                    <?= $typeInfo['icon'] ?>
                                </span>
                                <div style="font-weight: 600; color: var(--text-primary);"><?= $typeInfo['label'] ?></div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Configuration avancée -->
        <?php if (isset($configData['advanced'])): ?>
        <div class="config-section">
            <h2>
                <span class="material-symbols-outlined">tune</span>
                <?= __('config.section_advanced') ?>
            </h2>
            
            <?php if (isset($configData['advanced']['respect'])): ?>
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;"><?= __('config.directives_respect') ?></h3>
            <table class="data-table" style="margin-bottom: 2rem;">
                <tbody>
                    <?php if (isset($configData['advanced']['respect']['robots'])): ?>
                    <tr>
                        <td style="width: 300px;"><?= __('config.respect_robots') ?></td>
                        <td>
                            <span class="config-value boolean <?= $configData['advanced']['respect']['robots'] ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= $configData['advanced']['respect']['robots'] ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= $configData['advanced']['respect']['robots'] ? __('common.yes') : __('common.no') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['advanced']['respect']['nofollow'])): ?>
                    <tr>
                        <td style="width: 300px;"><?= __('config.respect_nofollow') ?></td>
                        <td>
                            <span class="config-value boolean <?= $configData['advanced']['respect']['nofollow'] ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= $configData['advanced']['respect']['nofollow'] ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= $configData['advanced']['respect']['nofollow'] ? __('common.yes') : __('common.no') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['advanced']['respect']['canonical'])): ?>
                    <tr>
                        <td style="width: 300px;"><?= __('config.respect_canonical') ?></td>
                        <td>
                            <span class="config-value boolean <?= $configData['advanced']['respect']['canonical'] ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= $configData['advanced']['respect']['canonical'] ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= $configData['advanced']['respect']['canonical'] ? __('common.yes') : __('common.no') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['advanced']['follow_redirects'])): ?>
                    <tr>
                        <td style="width: 300px;"><?= __('config.follow_redirects') ?></td>
                        <td>
                            <span class="config-value boolean <?= $configData['advanced']['follow_redirects'] ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= $configData['advanced']['follow_redirects'] ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= $configData['advanced']['follow_redirects'] ? __('common.yes') : __('common.no') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (isset($configData['advanced']['httpAuth'])): ?>
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;"><?= __('config.http_auth') ?></h3>
            <table class="data-table" style="margin-bottom: 2rem;">
                <tbody>
                    <tr>
                        <td style="width: 300px;"><?= __('config.auth_enabled') ?></td>
                        <td>
                            <span class="config-value boolean <?= ($configData['advanced']['httpAuth']['enabled'] === true) ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= ($configData['advanced']['httpAuth']['enabled'] === true) ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= ($configData['advanced']['httpAuth']['enabled'] === true) ? __('common.yes') : __('common.no') ?>
                            </span>
                        </td>
                    </tr>
                    <?php if ($configData['advanced']['httpAuth']['enabled'] === true): ?>
                    <tr>
                        <td style="width: 300px;">Login</td>
                        <td><code class="config-code"><?= htmlspecialchars($configData['advanced']['httpAuth']['username'] ?? '') ?></code></td>
                    </tr>
                    <tr>
                        <td style="width: 300px;"><?= __('config.password') ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <code class="config-code" id="passwordField" data-password="<?= htmlspecialchars($configData['advanced']['httpAuth']['password'] ?? '') ?>">••••••••</code>
                                <button onclick="togglePassword()" style="background: none; border: none; cursor: pointer; padding: 0.25rem; color: var(--text-secondary);">
                                    <span class="material-symbols-outlined" id="eyeIcon" style="font-size: 20px;">visibility</span>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (isset($configData['advanced']['customHeaders']) && is_array($configData['advanced']['customHeaders']) && !empty($configData['advanced']['customHeaders'])): ?>
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;"><?= __('config.custom_headers') ?></h3>
            <table class="data-table" style="margin-bottom: 2rem;">
                <thead>
                    <tr>
                        <th style="width: 250px;"><?= __('config.key') ?></th>
                        <th><?= __('config.value') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configData['advanced']['customHeaders'] as $name => $value): ?>
                    <tr>
                        <td><strong style="color: var(--primary-color);"><?= htmlspecialchars($name) ?></strong></td>
                        <td><code class="config-code"><?= htmlspecialchars($value) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (isset($configData['advanced']['xPathExtractors']) && !empty($configData['advanced']['xPathExtractors'])): ?>
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;"><?= __('config.xpath_extractors') ?></h3>
            <table class="data-table" style="margin-bottom: 2rem;">
                <thead>
                    <tr>
                        <th style="width: 200px;">Nom</th>
                        <th>XPath</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configData['advanced']['xPathExtractors'] as $name => $xpath): ?>
                    <tr>
                        <td><strong style="color: var(--primary-color);"><?= htmlspecialchars($name) ?></strong></td>
                        <td><code class="config-code"><?= htmlspecialchars($xpath) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (isset($configData['advanced']['regexExtractors']) && !empty($configData['advanced']['regexExtractors'])): ?>
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;"><?= __('config.regex_extractors') ?></h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 200px;">Nom</th>
                        <th>Expression régulière</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configData['advanced']['regexExtractors'] as $name => $regex): ?>
                    <tr>
                        <td><strong style="color: var(--primary-color);"><?= htmlspecialchars($name) ?></strong></td>
                        <td><code class="config-code"><?= htmlspecialchars($regex) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="alert alert-error">
        <?= __('config.error_no_config') ?>
    </div>
<?php endif; ?>

<script>
async function deleteCrawl() {
    const projectDir = '<?= addslashes($projectDir) ?>';
    const projectName = '<?= addslashes($projectName) ?>';
    
    const confirmed = await customConfirm(
        __('config.delete_confirm', {name: projectName}),
        __('config.delete_crawl'),
        __('common.delete'),
        'danger'
    );
    
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await fetch('../api/crawls/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ project_dir: projectDir })
        });
        
        const result = await response.json();
        
        if (!response.ok || !result.success) {
            throw new Error(result.error || __('config.delete_error'));
        }
        
        // Redirect to home page
        window.location.href = '../index.php';
        
    } catch (error) {
        alert(`${__('common.error')}: ${error.message}`);
    }
}

function togglePassword() {
    const passwordField = document.getElementById('passwordField');
    const eyeIcon = document.getElementById('eyeIcon');
    const realPassword = passwordField.getAttribute('data-password');
    
    if (passwordField.textContent === '••••••••') {
        passwordField.textContent = realPassword;
        eyeIcon.textContent = 'visibility_off';
    } else {
        passwordField.textContent = '••••••••';
        eyeIcon.textContent = 'visibility';
    }
}
</script>
