<?php $this->layout('admin/layout'); ?>

<?php
$isNew = $isNew ?? true;
$el = $element ?? [];
$action = $isNew ? '/admin/elements' : '/admin/elements/' . (int) $el['id'];
$slots = json_decode($el['slots_json'] ?? '[]', true) ?: [];
?>

<div class="element-editor-page">
    <div class="page-header">
        <div class="page-header-left">
            <a href="/admin/elements" class="btn btn-link">&larr; Back</a>
            <h1><?= $isNew ? 'Create Element' : 'Edit: ' . $this->e($el['name']) ?></h1>
            <?php if (!$isNew && ($usageCount ?? 0) > 0): ?>
                <span class="badge"><?= (int) $usageCount ?> page(s) use this</span>
            <?php endif; ?>
            <button type="button" id="element-ai-toggle" class="btn btn-secondary btn-sm"
                    aria-expanded="false" title="Toggle AI Assistant">
                AI Assistant
            </button>
        </div>
    </div>

    <form id="element-form" method="POST" action="<?= $this->e($action) ?>"
          data-element-id="<?= $isNew ? '' : (int) $el['id'] ?>">
        <?php if (!$isNew): ?>
            <input type="hidden" name="_method" value="PUT">
        <?php endif; ?>
        <?= $this->csrfField() ?>

        <div class="element-editor-grid">
            <!-- Left: Tabbed sidebar -->
            <div class="element-meta-panel">
                <div class="el-tab-bar">
                    <button type="button" class="el-tab active" data-tab="settings">Settings</button>
                    <button type="button" class="el-tab" data-tab="slots">Slots</button>
                    <button type="button" class="el-tab" data-tab="content">Content</button>
                </div>

                <!-- Tab 1: Settings -->
                <div class="el-tab-pane active" id="el-tab-settings">
                    <div class="form-group">
                        <label for="el-name">Name</label>
                        <input type="text" id="el-name" name="name"
                               value="<?= $this->e($el['name'] ?? '') ?>" required maxlength="200">
                    </div>
                    <div class="form-group">
                        <label for="el-slug">Slug</label>
                        <input type="text" id="el-slug" name="slug"
                               value="<?= $this->e($el['slug'] ?? '') ?>"
                               pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="100"
                               placeholder="auto-generated from name">
                    </div>
                    <div class="form-group">
                        <label for="el-description">Description</label>
                        <textarea id="el-description" name="description" rows="2"
                                  placeholder="What does this element do?"><?= $this->e($el['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="el-category">Category</label>
                            <input type="text" id="el-category" name="category"
                                   value="<?= $this->e($el['category'] ?? 'general') ?>"
                                   list="category-suggestions" placeholder="general">
                            <datalist id="category-suggestions">
                                <option value="hero">
                                <option value="content">
                                <option value="features">
                                <option value="testimonials">
                                <option value="cta">
                                <option value="gallery">
                                <option value="commerce">
                                <option value="navigation">
                                <option value="general">
                            </datalist>
                        </div>
                        <div class="form-group">
                            <label for="el-status">Status</label>
                            <select id="el-status" name="status">
                                <option value="active" <?= ($el['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="draft" <?= ($el['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="archived" <?= ($el['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Slots -->
                <div class="el-tab-pane" id="el-tab-slots">
                    <p class="section-desc">Define the content fields this element accepts.</p>
                    <div id="slot-list"></div>
                    <p id="no-slots-msg" style="color:#999;font-style:italic;">No slots defined yet.</p>
                    <div class="slot-add-bar">
                        <button type="button" id="add-slot-btn" class="btn btn-secondary">+ Add Slot</button>
                    </div>
                </div>

                <!-- Tab 3: Content -->
                <div class="el-tab-pane" id="el-tab-content">
                    <p class="section-desc">Fill in sample content to preview how this element looks.</p>
                    <div id="content-fields"></div>
                    <p id="no-content-msg" style="color:#999;font-style:italic;">Define slots first, then fill in sample content here.</p>
                </div>

                <!-- Hidden JSON input (always present, outside tabs) -->
                <input type="hidden" name="slots_json" id="slots-json-input"
                       value="<?= $this->e($el['slots_json'] ?? '[]') ?>">

                <!-- Form actions (always visible) -->
                <div class="form-actions" style="margin-top:1rem;">
                    <button type="submit" class="btn btn-primary">
                        <?= $isNew ? 'Create Element' : 'Save Changes' ?>
                    </button>
                    <a href="/admin/elements" class="btn btn-secondary">Cancel</a>
                </div>
            </div>

            <!-- Center: Preview-first + collapsible code -->
            <div class="element-code-panel">
                <div class="preview-section">
                    <div class="preview-header">
                        <h3>Live Preview</h3>
                        <button type="button" id="refresh-preview-btn" class="btn btn-sm btn-secondary">Refresh</button>
                    </div>
                    <div id="element-preview" class="element-preview-container element-preview-large">
                        <p style="color:#999;">Preview will appear here when you add content.</p>
                    </div>
                </div>

                <div class="code-collapse-section">
                    <button type="button" id="code-toggle-btn" class="code-toggle-btn" aria-expanded="false">
                        <span class="code-toggle-icon">&#9654;</span> Show Code
                    </button>
                    <div class="code-collapse-body" id="code-collapse-body" style="display:none;">
                        <div class="code-tab-bar">
                            <button type="button" class="code-tab active" data-code-tab="html">HTML Template</button>
                            <button type="button" class="code-tab" data-code-tab="css">CSS</button>
                        </div>
                        <div class="code-tab-pane active" id="code-tab-html">
                            <p class="section-desc">Use <code>{{slot}}</code> for text, <code>{{{slot}}}</code> for HTML,
                                <code>{{#list}}...{{/list}}</code> for loops.</p>
                            <textarea id="el-html-template" name="html_template" rows="14"
                                      class="code-editor" spellcheck="false"><?= $this->e($el['html_template'] ?? '') ?></textarea>
                        </div>
                        <div class="code-tab-pane" id="code-tab-css">
                            <p class="section-desc">Scope all selectors under <code>.lcms-el-<span id="css-slug-hint"><?= $this->e($el['slug'] ?? 'your-slug') ?></span></code></p>
                            <textarea id="el-css" name="css" rows="10"
                                      class="code-editor" spellcheck="false"><?= $this->e($el['css'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Assistant Panel -->
            <div class="element-ai-panel" id="element-ai-panel" style="display:none;">
                <div class="ai-resize-handle" title="Drag to resize"></div>
                <div class="ai-panel-header" id="element-ai-header">
                    <div class="ai-panel-header-left">
                        <select class="ai-model-select" title="Select model"></select>
                    </div>
                    <div class="ai-panel-header-actions">
                        <button type="button" id="element-ai-compact" title="Compact conversation" style="display:none;">Compact</button>
                        <button type="button" id="element-ai-history" title="Conversation history" style="display:none;">History</button>
                        <button type="button" id="element-ai-new" title="New conversation">New</button>
                        <button type="button" id="element-ai-close" title="Close panel">&times;</button>
                    </div>
                </div>
                <div class="ai-context-meter">
                    <div class="context-bar-track">
                        <div class="context-bar context-bar-ok" style="width:0"></div>
                    </div>
                    <span class="context-text">0 / 200.0k</span>
                </div>
                <div id="element-ai-messages" class="chat-messages ai-chat-messages"></div>
                <div id="element-ai-attach-preview" class="ai-attachments-preview"></div>
                <div class="ai-panel-input">
                    <button type="button" id="element-ai-attach-btn" class="ai-attach-btn" title="Attach image">&#128206;</button>
                    <textarea id="element-ai-input" rows="1" placeholder="Ask AI to help write HTML/CSS..." autocomplete="off"></textarea>
                    <button type="button" id="element-ai-send" class="ai-send-btn" title="Send message">&#10148;</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="/assets/js/editor.js"></script>
<script src="/assets/js/element-editor.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11/build/styles/github-dark.min.css">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11/build/highlight.min.js"></script>
<script src="/assets/js/ai-chat-core.js"></script>
<script src="/assets/js/element-ai-assistant.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        initElementEditor(<?= $el['slots_json'] ?? '[]' ?>, <?= $isNew ? 'null' : (int) $el['id'] ?>);
    });
</script>
