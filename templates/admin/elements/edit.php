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
        </div>
    </div>

    <form id="element-form" method="POST" action="<?= $this->e($action) ?>">
        <?php if (!$isNew): ?>
            <input type="hidden" name="_method" value="PUT">
        <?php endif; ?>
        <?= $this->csrfField() ?>

        <div class="element-editor-grid">
            <!-- Left: Meta fields -->
            <div class="element-meta-panel">
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

                <h3>Slots</h3>
                <p class="section-desc">Define the content fields this element accepts.</p>

                <div id="slot-list"></div>
                <p id="no-slots-msg" style="color:#999;font-style:italic;">No slots defined yet.</p>
                <button type="button" id="add-slot-btn" class="btn btn-secondary">+ Add Slot</button>

                <input type="hidden" name="slots_json" id="slots-json-input"
                       value="<?= $this->e($el['slots_json'] ?? '[]') ?>">

                <div class="form-actions" style="margin-top:1.5rem;">
                    <button type="submit" class="btn btn-primary">
                        <?= $isNew ? 'Create Element' : 'Save Changes' ?>
                    </button>
                    <a href="/admin/elements" class="btn btn-secondary">Cancel</a>
                </div>
            </div>

            <!-- Right: Code editors + Preview -->
            <div class="element-code-panel">
                <div class="code-section">
                    <h3>HTML Template</h3>
                    <p class="section-desc">Use <code>{{slot}}</code> for text, <code>{{{slot}}}</code> for HTML,
                        <code>{{#list}}...{{/list}}</code> for loops.</p>
                    <textarea id="el-html-template" name="html_template" rows="14"
                              class="code-editor" spellcheck="false"><?= $this->e($el['html_template'] ?? '') ?></textarea>
                </div>

                <div class="code-section">
                    <h3>CSS</h3>
                    <p class="section-desc">Scope all selectors under <code>.lcms-el-<span id="css-slug-hint"><?= $this->e($el['slug'] ?? 'your-slug') ?></span></code></p>
                    <textarea id="el-css" name="css" rows="10"
                              class="code-editor" spellcheck="false"><?= $this->e($el['css'] ?? '') ?></textarea>
                </div>

                <div class="code-section">
                    <h3>Live Preview</h3>
                    <button type="button" id="refresh-preview-btn" class="btn btn-sm btn-secondary">Refresh Preview</button>
                    <div id="element-preview" class="element-preview-container">
                        <p style="color:#999;">Click "Refresh Preview" to see the rendered element.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="/assets/js/element-editor.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        initElementEditor(<?= $el['slots_json'] ?? '[]' ?>, <?= $isNew ? 'null' : (int) $el['id'] ?>);
    });
</script>
