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
            $configError = "Configuration vide ou invalide.";
        }
    } catch (Exception $e) {
        $configError = "Erreur lors de la lecture de la configuration : " . $e->getMessage();
    }
} else {
    $configError = "Aucune configuration trouvée pour ce crawl.";
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
    <h1 class="page-title" style="margin: 0;">Paramètres du crawl</h1>
    <?php if ($canManageCurrentProject): ?>
    <button class="btn btn-danger" onclick="deleteCrawl()" style="display: flex; align-items: center; gap: 0.5rem;">
        <span class="material-symbols-outlined">delete</span>
        Supprimer le crawl
    </button>
    <?php endif; ?>
</div>

<?php if ($configError): ?>
    <div class="alert alert-error">
        <strong>Erreur :</strong> <?= htmlspecialchars($configError) ?>
    </div>
<?php elseif ($configData): ?>
    <div class="config-layout">
        <!-- Configuration générale -->
        <?php if (isset($configData['general'])): ?>
        <div class="config-section">
            <h2>
                <span class="material-symbols-outlined">settings</span>
                Configuration générale
            </h2>
            <table class="data-table">
                <tbody>
                    <?php if (isset($configData['general']['start'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);">URL de départ</td>
                        <td>
                            <a href="<?= htmlspecialchars($configData['general']['start']) ?>" target="_blank" style="color: var(--primary-color); text-decoration: none;">
                                <?= htmlspecialchars($configData['general']['start']) ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['general']['domains']) && is_array($configData['general']['domains'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);">Domaines autorisés</td>
                        <td>
                            <?php foreach ($configData['general']['domains'] as $domain): ?>
                                <div style="padding: 0.25rem 0;"><?= htmlspecialchars($domain) ?></div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['general']['depthMax'])): ?>
                    <tr>
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);">Profondeur maximale</td>
                        <td><strong><?= htmlspecialchars($configData['general']['depthMax']) ?></strong> niveaux</td>
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
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);">Vitesse de crawl</td>
                        <td>
                            <?php 
                            $speed = $configData['general']['crawl_speed'];
                            $speedLabels = [
                                'very_slow' => ['label' => 'Très lent', 'desc' => '1 URL/seconde', 'icon' => 'speed', 'color' => 'var(--danger)'],
                                'slow' => ['label' => 'Lent', 'desc' => '5 URLs/seconde', 'icon' => 'speed', 'color' => 'var(--warning)'],
                                'fast' => ['label' => 'Rapide', 'desc' => '20 URLs/seconde', 'icon' => 'speed', 'color' => 'var(--success)'],
                                'unlimited' => ['label' => 'Sans limite', 'desc' => 'Maximum de performance', 'icon' => 'bolt', 'color' => 'var(--primary-color)']
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
                        <td style="width: 200px; font-weight: 600; color: var(--text-secondary);">Mode de crawl</td>
                        <td>
                            <?php 
                            $mode = $configData['general']['crawl_mode'];
                            $modeLabels = [
                                'classic' => ['label' => 'Crawl classique', 'desc' => 'Requêtes HTTP standard', 'icon' => 'http', 'color' => 'var(--info)'],
                                'javascript' => ['label' => 'Crawl avec exécution JS', 'desc' => 'Rendu JavaScript (plus lent)', 'icon' => 'javascript', 'color' => 'var(--warning)']
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
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Configuration avancée -->
        <?php if (isset($configData['advanced'])): ?>
        <div class="config-section">
            <h2>
                <span class="material-symbols-outlined">tune</span>
                Configuration avancée
            </h2>
            
            <?php if (isset($configData['advanced']['respect'])): ?>
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;">Respect des directives</h3>
            <table class="data-table" style="margin-bottom: 2rem;">
                <tbody>
                    <?php if (isset($configData['advanced']['respect']['robots'])): ?>
                    <tr>
                        <td style="width: 300px;">Respect du <strong>robots.txt</strong></td>
                        <td>
                            <span class="config-value boolean <?= $configData['advanced']['respect']['robots'] ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= $configData['advanced']['respect']['robots'] ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= $configData['advanced']['respect']['robots'] ? 'Oui' : 'Non' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['advanced']['respect']['nofollow'])): ?>
                    <tr>
                        <td style="width: 300px;">Respect du <strong>nofollow</strong></td>
                        <td>
                            <span class="config-value boolean <?= $configData['advanced']['respect']['nofollow'] ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= $configData['advanced']['respect']['nofollow'] ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= $configData['advanced']['respect']['nofollow'] ? 'Oui' : 'Non' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($configData['advanced']['respect']['canonical'])): ?>
                    <tr>
                        <td style="width: 300px;">Respect du <strong>canonical</strong></td>
                        <td>
                            <span class="config-value boolean <?= $configData['advanced']['respect']['canonical'] ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= $configData['advanced']['respect']['canonical'] ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= $configData['advanced']['respect']['canonical'] ? 'Oui' : 'Non' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (isset($configData['advanced']['httpAuth'])): ?>
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;">Authentification HTTP</h3>
            <table class="data-table" style="margin-bottom: 2rem;">
                <tbody>
                    <tr>
                        <td style="width: 300px;">Authentification activée</td>
                        <td>
                            <span class="config-value boolean <?= ($configData['advanced']['httpAuth']['enabled'] === true) ? 'true' : 'false' ?>">
                                <span class="material-symbols-outlined">
                                    <?= ($configData['advanced']['httpAuth']['enabled'] === true) ? 'check_circle' : 'cancel' ?>
                                </span>
                                <?= ($configData['advanced']['httpAuth']['enabled'] === true) ? 'Oui' : 'Non' ?>
                            </span>
                        </td>
                    </tr>
                    <?php if ($configData['advanced']['httpAuth']['enabled'] === true): ?>
                    <tr>
                        <td style="width: 300px;">Login</td>
                        <td><code class="config-code"><?= htmlspecialchars($configData['advanced']['httpAuth']['username'] ?? '') ?></code></td>
                    </tr>
                    <tr>
                        <td style="width: 300px;">Mot de passe</td>
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
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;">Headers HTTP personnalisés</h3>
            <table class="data-table" style="margin-bottom: 2rem;">
                <thead>
                    <tr>
                        <th style="width: 250px;">Clé</th>
                        <th>Valeur</th>
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
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;">Extracteurs XPath</h3>
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
            <h3 style="margin: 1.5rem 0 1rem 0; color: var(--text-primary); font-size: 1.1rem;">Extracteurs Regex</h3>
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
        Aucune configuration trouvée.
    </div>
<?php endif; ?>

<script>
async function deleteCrawl() {
    const projectDir = '<?= addslashes($projectDir) ?>';
    const projectName = '<?= addslashes($projectName) ?>';
    
    const confirmed = await customConfirm(
        `Êtes-vous sûr de vouloir supprimer définitivement le crawl "${projectName}" ?\n\nCette action est irréversible et supprimera toutes les données associées.`,
        'Supprimer le crawl',
        'Supprimer',
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
            throw new Error(result.error || 'Erreur lors de la suppression du crawl');
        }
        
        // Redirect to home page
        window.location.href = '../index.php';
        
    } catch (error) {
        alert(`Erreur: ${error.message}`);
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
