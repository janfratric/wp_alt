<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Settings</h1>
</div>

<form method="POST" action="/admin/settings" class="settings-form">
    <?= $this->csrfField() ?>
    <input type="hidden" name="_method" value="PUT">

    <!-- AI Assistant Section -->
    <div class="settings-section">
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
        <div class="model-management" id="model-management">
            <h3>Manage Available Models</h3>
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
        </div>
    </div>

    <!-- General Section -->
    <div class="settings-section">
        <h2>General</h2>

        <div class="form-group">
            <label for="site_name">Site Name</label>
            <input type="text"
                   id="site_name"
                   name="site_name"
                   value="<?= $this->e($settings['site_name'] ?? \App\Core\Config::getString('site_name', 'LiteCMS')) ?>">
            <small>The name of your website, shown in titles and navigation.</small>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<style>
.settings-form {
    max-width: 700px;
}
.settings-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
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
    margin-top: 0;
    margin-bottom: 0.25rem;
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
</style>
