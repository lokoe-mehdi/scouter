<?php
/**
 * Crawl Configuration Modal — Shared Component
 *
 * Included by index.php and project.php.
 * Requires I18n to be loaded. Expects the JS functions
 * (createProject, switchCrawlTab, etc.) to be defined after inclusion.
 */
?>
<div id="newProjectModal" class="modal">
    <div class="modal-content crawl-modal-redesign">
        <form id="newProjectForm" onsubmit="return createProject(event)">
            <div class="crawl-modal-hero">
                <div class="hero-header">
                    <div class="hero-title">
                        <span class="material-symbols-outlined">rocket_launch</span>
                        <?= __('index.modal_new_project') ?>
                    </div>
                    <button type="button" class="hero-close" onclick="closeNewProjectModal()">&times;</button>
                </div>
            </div>

            <div class="crawl-type-segmented">
                <div class="segmented-control">
                    <button type="button" class="segmented-btn active" data-type="spider" onclick="selectCrawlType('spider', this)">
                        <span class="material-symbols-outlined">bug_report</span>
                        <span class="segmented-label"><?= __('index.modal_type_spider') ?></span>
                    </button>
                    <button type="button" class="segmented-btn" data-type="list" onclick="selectCrawlType('list', this)">
                        <span class="material-symbols-outlined">list</span>
                        <span class="segmented-label"><?= __('index.modal_type_list') ?></span>
                    </button>
                </div>
                <input type="hidden" id="crawl_type" name="crawl_type" value="spider">
            </div>

            <div class="crawl-tabs">
                <button type="button" class="crawl-tab active" data-tab="general" onclick="switchCrawlTab('general')">
                    <span class="material-symbols-outlined">tune</span>
                    <?= __('index.modal_tab_general') ?>
                </button>
                <button type="button" class="crawl-tab" data-tab="scope" onclick="switchCrawlTab('scope')">
                    <span class="material-symbols-outlined">rule</span>
                    <?= __('index.modal_tab_scope') ?>
                </button>
                <button type="button" class="crawl-tab" data-tab="extraction" onclick="switchCrawlTab('extraction')">
                    <span class="material-symbols-outlined">data_object</span>
                    <?= __('index.modal_tab_extraction') ?>
                </button>
                <button type="button" class="crawl-tab" data-tab="advanced" onclick="switchCrawlTab('advanced')">
                    <span class="material-symbols-outlined">settings</span>
                    <?= __('index.modal_tab_advanced') ?>
                </button>
            </div>

            <div class="crawl-tab-content">
                <!-- General -->
                <div class="crawl-tab-pane active" id="tab-general">
                    <div class="body-url-group" id="startUrlGroup">
                        <label for="start_url" class="body-url-label">
                            <span class="material-symbols-outlined">language</span>
                            <?= __('index.modal_start_url') ?>
                        </label>
                        <input type="url" id="start_url" name="start_url" class="body-url-input" placeholder="https://site-a-crawler.com" required autofocus>
                    </div>
                    <div class="body-url-group" id="urlListGroup" style="display:none;">
                        <label for="url_list" class="body-url-label">
                            <span class="material-symbols-outlined">list</span>
                            <?= __('index.modal_url_list') ?>
                        </label>
                        <textarea id="url_list" name="url_list" class="body-url-textarea" placeholder="https://example.com/page-1&#10;https://example.com/page-2"></textarea>
                        <div class="url-list-footer">
                            <span class="url-list-hint"><?= __('index.modal_url_list_hint') ?></span>
                            <span class="url-counter" id="urlCounter"><?= __('index.modal_urls_detected', ['count' => '0']) ?></span>
                        </div>
                        <div class="file-upload-zone" id="fileUploadZone">
                            <label class="file-upload-btn" id="fileUploadLabel">
                                <span class="material-symbols-outlined">upload_file</span>
                                <span><?= __('index.modal_upload_file') ?></span>
                                <span class="file-upload-hint"><?= __('index.modal_upload_file_hint') ?></span>
                                <input type="file" id="urlFileInput" accept=".txt,.csv" style="display:none;" onchange="handleUrlFileUpload(this)">
                            </label>
                            <div class="file-upload-info" id="fileUploadInfo" style="display:none;">
                                <span class="material-symbols-outlined">description</span>
                                <span class="file-upload-name" id="fileUploadName"></span>
                                <button type="button" class="file-upload-remove" onclick="removeUrlFile()"><span class="material-symbols-outlined">close</span></button>
                            </div>
                        </div>
                    </div>
                    <div class="settings-grid">
                        <div class="setting-row" id="depthMaxRow">
                            <div class="setting-row-label"><span class="material-symbols-outlined">layers</span><h4><?= __('index.modal_max_depth') ?></h4></div>
                            <div class="setting-row-control">
                                <input type="number" id="depth_max" name="depth_max" value="30" min="1" max="100" required class="setting-input-number">
                                <span class="setting-unit"><?= __('common.levels') ?></span>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-row-label"><span class="material-symbols-outlined">speed</span><h4><?= __('index.modal_crawl_speed') ?></h4></div>
                            <div class="setting-row-control">
                                <input type="hidden" id="crawl_speed" name="crawl_speed" value="fast">
                                <div class="custom-speed-select" id="speedSelect">
                                    <div class="speed-select-trigger" onclick="toggleSpeedDropdown(event)">
                                        <div class="speed-select-value">
                                            <span class="material-symbols-outlined speed-icon speed-icon-fast">speed</span>
                                            <div class="speed-select-text"><span class="speed-select-name"><?= __('index.modal_speed_fast') ?></span><span class="speed-select-desc"><?= __('index.modal_speed_fast_desc') ?></span></div>
                                        </div>
                                        <span class="material-symbols-outlined speed-select-arrow">expand_more</span>
                                    </div>
                                    <div class="speed-select-dropdown" id="speedDropdown">
                                        <div class="speed-select-option" data-value="very_slow" onclick="selectSpeedOption('very_slow', __('index.modal_speed_very_slow'), __('index.modal_speed_very_slow_desc'), 'hourglass_top')"><span class="material-symbols-outlined speed-icon speed-icon-very_slow">hourglass_top</span><div class="speed-select-text"><span class="speed-select-name"><?= __('index.modal_speed_very_slow') ?></span><span class="speed-select-desc"><?= __('index.modal_speed_very_slow_desc') ?></span></div></div>
                                        <div class="speed-select-option" data-value="slow" onclick="selectSpeedOption('slow', __('index.modal_speed_slow'), __('index.modal_speed_slow_desc'), 'pace')"><span class="material-symbols-outlined speed-icon speed-icon-slow">pace</span><div class="speed-select-text"><span class="speed-select-name"><?= __('index.modal_speed_slow') ?></span><span class="speed-select-desc"><?= __('index.modal_speed_slow_desc') ?></span></div></div>
                                        <div class="speed-select-option selected" data-value="fast" onclick="selectSpeedOption('fast', __('index.modal_speed_fast'), __('index.modal_speed_fast_desc'), 'speed')"><span class="material-symbols-outlined speed-icon speed-icon-fast">speed</span><div class="speed-select-text"><span class="speed-select-name"><?= __('index.modal_speed_fast') ?></span><span class="speed-select-desc"><?= __('index.modal_speed_fast_desc') ?></span></div></div>
                                        <div class="speed-select-option" data-value="unlimited" onclick="selectSpeedOption('unlimited', __('index.modal_speed_unlimited'), __('index.modal_speed_unlimited_desc'), 'bolt')"><span class="material-symbols-outlined speed-icon speed-icon-unlimited">bolt</span><div class="speed-select-text"><span class="speed-select-name"><?= __('index.modal_speed_unlimited') ?></span><span class="speed-select-desc"><?= __('index.modal_speed_unlimited_desc') ?></span></div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-row-label"><span class="material-symbols-outlined">code</span><h4><?= __('index.modal_crawl_mode') ?></h4></div>
                            <div class="setting-row-control">
                                <div class="mode-selector">
                                    <button type="button" class="mode-btn active" data-mode="classic" onclick="selectMode('classic', this)"><span class="material-symbols-outlined">http</span><span class="mode-label"><?= __('index.modal_mode_classic') ?></span></button>
                                    <button type="button" class="mode-btn" data-mode="javascript" onclick="selectMode('javascript', this)"><span class="material-symbols-outlined">javascript</span><span class="mode-label"><?= __('index.modal_mode_javascript') ?></span></button>
                                </div>
                                <input type="hidden" id="crawl_mode" name="crawl_mode" value="classic">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scope -->
                <div class="crawl-tab-pane" id="tab-scope">
                    <div class="scope-section" id="allowedDomainsSection">
                        <h4 class="scope-section-title"><span class="material-symbols-outlined">domain</span><?= __('index.modal_allowed_domains') ?></h4>
                        <div class="scope-section-content">
                            <textarea id="allowed_domains" name="allowed_domains" rows="3" placeholder="<?= __('index.modal_allowed_domains_placeholder') ?>" class="domains-textarea"></textarea>
                            <div class="scope-hint"><span class="material-symbols-outlined">auto_awesome</span><?= __('index.modal_allowed_domains_hint') ?></div>
                        </div>
                    </div>
                    <div class="scope-section">
                        <h4 class="scope-section-title"><span class="material-symbols-outlined">rule</span><?= __('index.modal_crawl_rules') ?></h4>
                        <div class="rules-grid">
                            <label class="rule-toggle"><input type="checkbox" id="respect_robots" name="respect_robots" checked><span class="rule-toggle-slider"></span><div class="rule-toggle-content"><span class="rule-toggle-label"><?= __('index.modal_rule_robots') ?></span><span class="rule-toggle-hint"><?= __('index.modal_rule_robots_hint') ?></span></div></label>
                            <label class="rule-toggle"><input type="checkbox" id="respect_nofollow" name="respect_nofollow" checked><span class="rule-toggle-slider"></span><div class="rule-toggle-content"><span class="rule-toggle-label"><?= __('index.modal_rule_nofollow') ?></span><span class="rule-toggle-hint"><?= __('index.modal_rule_nofollow_hint') ?></span></div></label>
                            <label class="rule-toggle"><input type="checkbox" id="respect_canonical" name="respect_canonical" checked><span class="rule-toggle-slider"></span><div class="rule-toggle-content"><span class="rule-toggle-label"><?= __('index.modal_rule_canonical') ?></span><span class="rule-toggle-hint"><?= __('index.modal_rule_canonical_hint') ?></span></div></label>
                            <label class="rule-toggle"><input type="checkbox" id="follow_redirects" name="follow_redirects" checked><span class="rule-toggle-slider"></span><div class="rule-toggle-content"><span class="rule-toggle-label"><?= __('index.modal_rule_follow_redirects') ?></span><span class="rule-toggle-hint"><?= __('index.modal_rule_follow_redirects_hint') ?></span></div></label>
                            <label class="rule-toggle"><input type="checkbox" id="store_html" name="store_html" checked><span class="rule-toggle-slider"></span><div class="rule-toggle-content"><span class="rule-toggle-label"><?= __('index.modal_rule_store_html') ?></span><span class="rule-toggle-hint"><?= __('index.modal_rule_store_html_hint') ?></span></div></label>
                            <label class="rule-toggle"><input type="checkbox" id="retry_failed_urls" name="retry_failed_urls" checked><span class="rule-toggle-slider"></span><div class="rule-toggle-content"><span class="rule-toggle-label"><?= __('index.modal_rule_retry') ?></span><span class="rule-toggle-hint"><?= __('index.modal_rule_retry_hint') ?></span></div></label>
                        </div>
                    </div>
                    <div class="scope-section">
                        <h4 class="scope-section-title"><span class="material-symbols-outlined">lock</span><?= __('index.modal_http_auth') ?></h4>
                        <div class="scope-section-content">
                            <label class="rule-toggle" style="margin-bottom: 1rem;"><input type="checkbox" id="enable_auth" name="enable_auth" onchange="toggleAuthFields()"><span class="rule-toggle-slider"></span><div class="rule-toggle-content"><span class="rule-toggle-label"><?= __('index.modal_http_auth_enable') ?></span><span class="rule-toggle-hint"><?= __('index.modal_http_auth_hint') ?></span></div></label>
                            <div id="authFields" class="auth-fields" style="display: none;">
                                <div class="auth-grid">
                                    <div class="form-group"><label for="auth_username"><?= __('index.modal_auth_username') ?></label><input type="text" id="auth_username" name="auth_username" placeholder="<?= __('index.modal_auth_username') ?>"></div>
                                    <div class="form-group"><label for="auth_password"><?= __('index.modal_auth_password') ?></label><input type="password" id="auth_password" name="auth_password" placeholder="••••••••"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Extraction -->
                <div class="crawl-tab-pane" id="tab-extraction">
                    <div class="extractors-container">
                        <h4 class="scope-section-title"><span class="material-symbols-outlined">data_object</span><?= __('index.modal_xpath_extractors') ?></h4>
                        <div id="extractorsList" class="extractors-list"></div>
                        <div id="extractorsEmpty" class="extractors-empty"><div class="extractors-empty-icon"><span class="material-symbols-outlined">data_object</span></div><h4><?= __('index.no_extractors') ?></h4><p><?= __('index.no_extractors_desc') ?></p></div>
                        <button type="button" class="btn-add-extractor" onclick="addExtractor()"><span class="material-symbols-outlined">add</span><?= __('index.modal_add_extractor') ?></button>
                    </div>
                    <div class="extraction-help-toggle"><a href="#" onclick="toggleExtractionHelp(event)"><span class="material-symbols-outlined">help_outline</span><?= __('index.see_examples') ?></a></div>
                    <div class="extraction-help" id="extractionHelp" style="display: none;">
                        <div class="extraction-examples">
                            <div class="extraction-example"><span class="extraction-example-type">XPath</span><code>//h2</code><span class="extraction-example-desc"><?= __('index.example_simple_selection') ?></span></div>
                            <div class="extraction-example"><span class="extraction-example-type">XPath</span><code>count(//h2)</code><span class="extraction-example-desc"><?= __('index.example_xpath_function') ?></span></div>
                            <div class="extraction-example"><span class="extraction-example-type">Regex</span><code>price: (\d+)</code><span class="extraction-example-desc"><?= __('index.example_value_extraction') ?></span></div>
                        </div>
                    </div>
                </div>

                <!-- Advanced -->
                <div class="crawl-tab-pane" id="tab-advanced">
                    <div class="advanced-section">
                        <h4 class="advanced-section-title"><span class="material-symbols-outlined">smart_toy</span>User-Agent</h4>
                        <input type="hidden" id="user_agent" name="user_agent" value="Scouter/0.3 (Crawler developed by Lokoé SASU; +https://lokoe.fr/scouter-crawler)" required>
                        <div class="custom-ua-select" id="uaSelect">
                            <div class="ua-select-trigger" onclick="toggleUADropdown()">
                                <div class="ua-select-value"><span class="material-symbols-outlined ua-icon ua-icon-scouter">smart_toy</span><div class="ua-select-text"><span class="ua-select-name">Scouter</span><span class="ua-select-desc"><?= __('index.ua_default') ?></span></div></div>
                                <span class="material-symbols-outlined ua-select-arrow">expand_more</span>
                            </div>
                            <div class="ua-select-dropdown" id="uaDropdown">
                                <div class="ua-select-option selected" data-value="scouter" onclick="selectUAOption('scouter', 'Scouter', '<?= __('index.ua_default') ?>', 'smart_toy')"><span class="material-symbols-outlined ua-icon ua-icon-scouter">smart_toy</span><div class="ua-select-text"><span class="ua-select-name">Scouter</span><span class="ua-select-desc"><?= __('index.ua_default') ?></span></div></div>
                                <div class="ua-select-option" data-value="googlebot-mobile" onclick="selectUAOption('googlebot-mobile', 'Googlebot Smartphone', '<?= __('index.ua_googlebot_mobile') ?>', 'phone_android')"><span class="material-symbols-outlined ua-icon ua-icon-googlebot">phone_android</span><div class="ua-select-text"><span class="ua-select-name">Googlebot Smartphone</span><span class="ua-select-desc"><?= __('index.ua_googlebot_mobile') ?></span></div></div>
                                <div class="ua-select-option" data-value="googlebot-desktop" onclick="selectUAOption('googlebot-desktop', 'Googlebot Desktop', '<?= __('index.ua_googlebot_desktop') ?>', 'computer')"><span class="material-symbols-outlined ua-icon ua-icon-googlebot">computer</span><div class="ua-select-text"><span class="ua-select-name">Googlebot Desktop</span><span class="ua-select-desc"><?= __('index.ua_googlebot_desktop') ?></span></div></div>
                                <div class="ua-select-option" data-value="chrome" onclick="selectUAOption('chrome', '<?= __('index.ua_chrome_user') ?>', '<?= __('index.ua_chrome_desc') ?>', 'person')"><span class="material-symbols-outlined ua-icon ua-icon-chrome">person</span><div class="ua-select-text"><span class="ua-select-name"><?= __('index.ua_chrome_user') ?></span><span class="ua-select-desc"><?= __('index.ua_chrome_desc') ?></span></div></div>
                            </div>
                        </div>
                        <div class="ua-custom-input"><label><?= __('index.custom_ua') ?></label><input type="text" id="custom_ua_input" placeholder="<?= __('index.customize') ?>" onchange="applyCustomUA()"></div>
                    </div>
                    <div class="advanced-section">
                        <h4 class="advanced-section-title"><span class="material-symbols-outlined">http</span><?= __('index.modal_custom_headers') ?></h4>
                        <div class="headers-container">
                            <div id="headersList" class="headers-list"></div>
                            <button type="button" class="btn-add-header" onclick="addHeader()"><span class="material-symbols-outlined">add</span><?= __('index.modal_add_header') ?></button>
                            <div class="headers-hint"><strong><?= __('index.common_headers') ?></strong> Authorization, Cookie, X-API-Key</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="crawl-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeNewProjectModal()"><?= __('common.cancel') ?></button>
                <button type="submit" class="btn btn-launch" id="submitBtn"><span class="material-symbols-outlined">rocket_launch</span><?= __('index.btn_launch_crawl') ?></button>
            </div>
            <div id="formMessage" class="form-message"></div>
        </form>
    </div>
</div>
