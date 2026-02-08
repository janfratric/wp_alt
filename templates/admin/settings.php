<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Settings</h1>
</div>

<form method="POST" action="/admin/settings" class="settings-form">
    <?= $this->csrfField() ?>
    <input type="hidden" name="_method" value="PUT">

    <!-- General Section -->
    <div class="settings-section">
        <h2>General</h2>
        <p class="section-desc">Basic site configuration.</p>

        <div class="form-group">
            <label for="site_name">Site Name</label>
            <input type="text"
                   id="site_name"
                   name="site_name"
                   value="<?= $this->e($settings['site_name'] ?? \App\Core\Config::getString('site_name', 'LiteCMS')) ?>">
            <small>The name of your website, shown in titles and navigation.</small>
        </div>

        <div class="form-group">
            <label for="site_url">Site URL</label>
            <input type="url"
                   id="site_url"
                   name="site_url"
                   value="<?= $this->e($settings['site_url'] ?? \App\Core\Config::getString('site_url', 'http://localhost')) ?>"
                   placeholder="https://example.com">
            <small>The full URL of your website (no trailing slash). Used for canonical URLs and Open Graph tags.</small>
        </div>

        <div class="form-group">
            <label for="site_tagline">Tagline</label>
            <input type="text"
                   id="site_tagline"
                   name="site_tagline"
                   value="<?= $this->e($settings['site_tagline'] ?? '') ?>"
                   placeholder="A short description of your site">
            <small>A brief tagline or slogan for your site.</small>
        </div>

        <div class="form-group">
            <label for="timezone">Timezone</label>
            <select id="timezone" name="timezone">
                <?php foreach ($timezones as $group => $tzList): ?>
                    <optgroup label="<?= $this->e($group) ?>">
                        <?php foreach ($tzList as $tz): ?>
                            <option value="<?= $this->e($tz) ?>"
                                <?= ($currentTimezone === $tz) ? 'selected' : '' ?>>
                                <?= $this->e($tz) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <small>Timezone used for displaying dates on the public site.</small>
        </div>

        <div class="form-group">
            <label for="items_per_page">Items Per Page</label>
            <input type="number"
                   id="items_per_page"
                   name="items_per_page"
                   value="<?= (int)($settings['items_per_page'] ?? \App\Core\Config::getInt('items_per_page', 10)) ?>"
                   min="1" max="100">
            <small>Number of items shown per page in listings (blog, admin content list, etc.).</small>
        </div>
    </div>

    <!-- SEO Section -->
    <div class="settings-section">
        <h2>SEO</h2>
        <p class="section-desc">Search engine optimization defaults. Individual content items can override these.</p>

        <div class="form-group">
            <label for="default_meta_description">Default Meta Description</label>
            <textarea id="default_meta_description"
                      name="default_meta_description"
                      rows="3"
                      maxlength="300"
                      placeholder="A brief description of your website for search engines..."
            ><?= $this->e($settings['default_meta_description'] ?? '') ?></textarea>
            <small>Used when individual pages don't have their own meta description. Max 300 characters.</small>
        </div>

        <div class="form-group">
            <label for="og_default_image">Default Open Graph Image</label>
            <input type="text"
                   id="og_default_image"
                   name="og_default_image"
                   value="<?= $this->e($settings['og_default_image'] ?? '') ?>"
                   placeholder="/assets/uploads/og-image.jpg">
            <small>Default image shown when pages are shared on social media. Use a path like <code>/assets/uploads/filename.jpg</code>.</small>
        </div>
    </div>

    <!-- Cookie Consent & Analytics Section -->
    <div class="settings-section">
        <h2>Cookie Consent &amp; Analytics</h2>
        <p class="section-desc">GDPR-compliant cookie consent banner and Google Analytics integration.</p>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="cookie_consent_enabled" value="0">
                <input type="checkbox"
                       name="cookie_consent_enabled"
                       value="1"
                       <?= ($settings['cookie_consent_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                Enable cookie consent banner
            </label>
            <small>Show a consent banner to first-time visitors. Recommended for EU compliance.</small>
        </div>

        <div class="form-group">
            <label for="cookie_consent_text">Consent Banner Text</label>
            <textarea id="cookie_consent_text"
                      name="cookie_consent_text"
                      rows="2"
                      placeholder="This website uses cookies to improve your experience..."
            ><?= $this->e($settings['cookie_consent_text'] ?? '') ?></textarea>
            <small>Custom message shown in the cookie consent banner.</small>
        </div>

        <div class="form-group">
            <label for="cookie_consent_link">Privacy Policy Link</label>
            <input type="text"
                   id="cookie_consent_link"
                   name="cookie_consent_link"
                   value="<?= $this->e($settings['cookie_consent_link'] ?? '') ?>"
                   placeholder="/privacy-policy">
            <small>Link to your privacy policy page. Shown as "Learn more" in the consent banner.</small>
        </div>

        <hr style="margin: 1.5rem 0; border-color: var(--border-color, #dee2e6);">

        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="ga_enabled" value="0">
                <input type="checkbox"
                       name="ga_enabled"
                       value="1"
                       <?= ($settings['ga_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                Enable Google Analytics
            </label>
            <small>When enabled, Google Analytics tracking code is loaded after cookie consent is accepted.</small>
        </div>

        <div class="form-group">
            <label for="ga_measurement_id">GA Measurement ID</label>
            <input type="text"
                   id="ga_measurement_id"
                   name="ga_measurement_id"
                   value="<?= $this->e($settings['ga_measurement_id'] ?? '') ?>"
                   placeholder="G-XXXXXXXXXX"
                   pattern="G-[A-Za-z0-9]+"
                   title="Must start with G- followed by alphanumeric characters">
            <small>Your Google Analytics 4 Measurement ID (e.g., <code>G-XXXXXXXXXX</code>). Found in GA Admin &gt; Data Streams.</small>
        </div>
    </div>

    <!-- Contact Form Section -->
    <div class="settings-section">
        <h2>Contact Form</h2>
        <p class="section-desc">Configure how contact form submissions are handled.</p>

        <div class="form-group">
            <label for="contact_notification_email">Notification Email</label>
            <input type="email"
                   id="contact_notification_email"
                   name="contact_notification_email"
                   value="<?= $this->e($settings['contact_notification_email'] ?? '') ?>"
                   placeholder="admin@example.com">
            <small>When set, new contact form submissions will trigger an email notification to this address. Leave blank to disable email notifications (submissions are still saved to the database).</small>
        </div>
    </div>

    <!-- AI Assistant Section -->
    <div class="settings-section full-width">
        <h2>AI Assistant</h2>
        <p class="section-desc">Configure your Claude API integration for the AI writing assistant.</p>

        <div class="form-group">
            <label for="claude_api_key">Claude API Key</label>
            <?php if ($hasApiKey): ?>
                <div class="key-status key-configured">
                    API key is configured (stored encrypted)
                </div>
            <?php else: ?>
                <div class="key-status key-missing">
                    No API key configured
                </div>
            <?php endif; ?>
            <input type="password"
                   id="claude_api_key"
                   name="claude_api_key"
                   placeholder="<?= $hasApiKey ? 'Leave blank to keep current key' : 'sk-ant-...' ?>"
                   autocomplete="off">
            <small>Get your API key from <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>. Leave blank to keep the current key.</small>
        </div>

        <div class="form-group">
            <label for="claude_model">Claude Model</label>
            <select id="claude_model" name="claude_model">
                <?php foreach ($dropdownModels as $model): ?>
                    <option value="<?= $this->e($model['id']) ?>"
                        <?= ($claudeModel === $model['id']) ? 'selected' : '' ?>>
                        <?= $this->e($model['display_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small>Choose the Claude model for the AI writing assistant.</small>
        </div>

        <!-- Model Management -->
        <details class="model-management" id="model-management">
            <summary><h3>Manage Available Models</h3></summary>
            <p class="section-desc">Fetch the latest models from Anthropic and choose which ones appear in the dropdown above.</p>

            <div class="model-actions">
                <button type="button" id="fetch-models-btn" class="btn btn-sm"
                        <?= !$hasApiKey ? 'disabled title="Configure API key first"' : '' ?>>
                    Refresh Models from API
                </button>
                <span id="fetch-models-status" class="status-text"></span>
            </div>

            <?php if (!$hasApiKey): ?>
                <div class="key-status key-missing" style="margin-top:0.75rem;">
                    Configure your Claude API key above to fetch available models.
                </div>
            <?php endif; ?>

            <div id="models-list" class="models-checklist">
                <?php if (!empty($availableModels)): ?>
                    <?php foreach ($availableModels as $model): ?>
                        <label class="model-checkbox-label">
                            <input type="checkbox"
                                   class="model-checkbox"
                                   value="<?= $this->e($model['id']) ?>"
                                   <?= in_array($model['id'], $enabledModels, true) ? 'checked' : '' ?>>
                            <span class="model-name"><?= $this->e($model['display_name']) ?></span>
                            <span class="model-id"><?= $this->e($model['id']) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="section-desc">No models fetched yet. Click "Refresh Models from API" to load available models.</p>
                <?php endif; ?>
            </div>

            <?php if (!empty($availableModels)): ?>
                <div class="model-save-area">
                    <button type="button" id="save-models-btn" class="btn btn-primary btn-sm">
                        Save Model Selection
                    </button>
                    <span id="save-models-status" class="status-text"></span>
                </div>
            <?php endif; ?>
        </details>

        <!-- API Parameters -->
        <div class="model-management">
            <h3>API Parameters</h3>
            <p class="section-desc">Fine-tune how the AI assistant communicates with the Claude API.</p>

            <?php
            $currentMaxTokens   = $settings['ai_max_tokens'] ?? \App\AIAssistant\ClaudeClient::DEFAULT_MAX_TOKENS;
            $currentTimeout     = $settings['ai_timeout'] ?? \App\AIAssistant\ClaudeClient::DEFAULT_TIMEOUT;
            $currentTemperature = $settings['ai_temperature'] ?? \App\AIAssistant\ClaudeClient::DEFAULT_TEMPERATURE;
            ?>

            <div class="ai-params-grid">
                <div class="form-group">
                    <label for="ai_max_tokens">Max Tokens</label>
                    <input type="number"
                           id="ai_max_tokens"
                           name="ai_max_tokens"
                           value="<?= (int) $currentMaxTokens ?>"
                           min="1" max="128000" step="1">
                    <small>Maximum number of tokens in the AI response (1 &ndash; 128,000). Default: <?= \App\AIAssistant\ClaudeClient::DEFAULT_MAX_TOKENS ?>.</small>
                </div>

                <div class="form-group">
                    <label for="ai_timeout">Timeout (seconds)</label>
                    <input type="number"
                           id="ai_timeout"
                           name="ai_timeout"
                           value="<?= (int) $currentTimeout ?>"
                           min="10" max="600" step="1">
                    <small>How long to wait for a response before timing out (10 &ndash; 600 s). Default: <?= \App\AIAssistant\ClaudeClient::DEFAULT_TIMEOUT ?>.</small>
                </div>

                <div class="form-group">
                    <label for="ai_temperature">Temperature</label>
                    <input type="number"
                           id="ai_temperature"
                           name="ai_temperature"
                           value="<?= number_format((float) $currentTemperature, 2) ?>"
                           min="0" max="1" step="0.05">
                    <small>Controls randomness: 0 = deterministic, 1 = most creative. Default: <?= number_format(\App\AIAssistant\ClaudeClient::DEFAULT_TEMPERATURE, 1) ?>.</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Media & Image Settings Section -->
    <div class="settings-section">
        <h2>Media &amp; Images</h2>
        <p class="section-desc">Configure upload limits and automatic image optimization for AI chat attachments.</p>

        <?php
        $currentMaxUpload       = $settings['max_upload_size'] ?? \App\Core\Config::getInt('max_upload_size', 5242880);
        $currentResizeThreshold = $settings['image_resize_threshold'] ?? 1572864;
        $currentMaxDimension    = $settings['image_max_dimension'] ?? 2048;
        $currentJpegQuality     = $settings['image_jpeg_quality'] ?? 85;
        ?>

        <div class="form-group">
            <label for="max_upload_size">Max Upload Size (bytes)</label>
            <input type="number"
                   id="max_upload_size"
                   name="max_upload_size"
                   value="<?= (int) $currentMaxUpload ?>"
                   min="102400" max="104857600" step="1024">
            <small>Server-side file size limit (100 KB &ndash; 100 MB). Default: 5,242,880 (5 MB). Note: PHP's <code>upload_max_filesize</code> (currently <?= ini_get('upload_max_filesize') ?>) also applies.</small>
        </div>

        <div class="ai-params-grid">
            <div class="form-group">
                <label for="image_resize_threshold">Auto-Resize Threshold (bytes)</label>
                <input type="number"
                       id="image_resize_threshold"
                       name="image_resize_threshold"
                       value="<?= (int) $currentResizeThreshold ?>"
                       min="102400" max="104857600" step="1024">
                <small>Images larger than this are auto-resized before upload. Default: 1,572,864 (1.5 MB).</small>
            </div>

            <div class="form-group">
                <label for="image_max_dimension">Max Dimension (px)</label>
                <input type="number"
                       id="image_max_dimension"
                       name="image_max_dimension"
                       value="<?= (int) $currentMaxDimension ?>"
                       min="100" max="10000" step="1">
                <small>Maximum width or height when resizing. Aspect ratio is preserved. Default: 2048.</small>
            </div>

            <div class="form-group">
                <label for="image_jpeg_quality">JPEG Quality (%)</label>
                <input type="number"
                       id="image_jpeg_quality"
                       name="image_jpeg_quality"
                       value="<?= (int) $currentJpegQuality ?>"
                       min="10" max="100" step="1">
                <small>Quality for JPEG compression when resizing (10 &ndash; 100). Default: 85.</small>
            </div>
        </div>
    </div>

    <!-- Advanced Section -->
    <div class="settings-section">
        <h2>Advanced</h2>
        <p class="section-desc">Advanced configuration options. Change these only if you know what you're doing.</p>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="registration_enabled" value="0">
                <input type="checkbox"
                       name="registration_enabled"
                       value="1"
                       <?= ($settings['registration_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                Enable user registration
            </label>
            <small>Allow new users to register accounts. When disabled, only admins can create user accounts.</small>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="maintenance_mode" value="0">
                <input type="checkbox"
                       name="maintenance_mode"
                       value="1"
                       <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                Maintenance mode
            </label>
            <small>When enabled, the public site shows a maintenance page. Admin panel remains accessible.</small>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<style>
.settings-form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: start;
}
.settings-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 8px;
    padding: 1.5rem;
}
.settings-section.full-width {
    grid-column: 1 / -1;
}
.form-actions {
    grid-column: 1 / -1;
}
.settings-section h2 {
    margin-top: 0;
    font-size: 1.15rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color, #dee2e6);
}
.section-desc {
    color: var(--text-muted, #6c757d);
    font-size: 0.9rem;
    margin-bottom: 1.25rem;
}
.key-status {
    padding: 0.5rem 0.75rem;
    border-radius: 4px;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}
.key-configured {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}
.key-missing {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}
.model-management {
    margin-top: 1.25rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--border-color, #dee2e6);
}
.model-management h3 {
    font-size: 1rem;
    margin: 0;
    display: inline;
}
details.model-management > summary {
    cursor: pointer;
    list-style: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    user-select: none;
}
details.model-management > summary::-webkit-details-marker {
    display: none;
}
details.model-management > summary::before {
    content: '\25B6';
    font-size: 0.7rem;
    transition: transform 0.2s;
}
details.model-management[open] > summary::before {
    transform: rotate(90deg);
}
details.model-management > summary + * {
    margin-top: 0.75rem;
}
.model-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.status-text {
    color: var(--text-muted, #6c757d);
    font-size: 0.85rem;
}
.models-checklist {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 6px;
    padding: 0.5rem;
    margin-top: 0.75rem;
}
.model-checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.5rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9rem;
}
.model-checkbox-label:hover {
    background: #f3f4f6;
}
.model-checkbox-label .model-id {
    color: var(--text-muted, #6c757d);
    font-size: 0.75rem;
    font-family: monospace;
}
.model-save-area {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.75rem;
}
.btn-sm {
    padding: 0.35rem 0.75rem;
    font-size: 0.85rem;
}
.ai-params-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 1rem;
}
.ai-params-grid input[type="number"] {
    width: 100%;
}
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 500;
    cursor: pointer;
}
.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
}
.settings-section textarea {
    width: 100%;
    resize: vertical;
}
@media (max-width: 900px) {
    .settings-form {
        grid-template-columns: 1fr;
    }
    .ai-params-grid {
        grid-template-columns: 1fr;
    }
}
</style>
