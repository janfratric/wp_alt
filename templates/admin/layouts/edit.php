<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $isNew ? 'Create Layout' : 'Edit: ' . $this->e($layout['name']) ?></h1>
    <a href="/admin/layouts" class="btn">&larr; Back to Layouts</a>
</div>

<form method="POST"
      action="<?= $isNew ? '/admin/layouts' : '/admin/layouts/' . (int)$layout['id'] ?>"
      class="layout-form">
    <?= $this->csrfField() ?>
    <?php if (!$isNew): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="layout-form-grid">
        <!-- General Section -->
        <div class="layout-section">
            <h2>General</h2>
            <p class="section-desc">Basic template information.</p>

            <div class="form-group">
                <label for="name">Name <span style="color:var(--color-error);">*</span></label>
                <input type="text" id="name" name="name"
                       value="<?= $this->e($layout['name']) ?>"
                       required maxlength="200"
                       placeholder="e.g. Home Layout, Landing Page">
            </div>

            <div class="form-group">
                <label for="slug">Slug</label>
                <input type="text" id="slug" name="slug"
                       value="<?= $this->e($layout['slug'] ?? '') ?>"
                       maxlength="100"
                       placeholder="auto-generated-from-name">
                <small>Leave blank to auto-generate from name.</small>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="hidden" name="is_default" value="0">
                    <input type="checkbox" name="is_default" value="1"
                           <?= ((int)($layout['is_default'] ?? 0) === 1) ? 'checked' : '' ?>>
                    Set as default layout
                </label>
                <small>The default layout is used for pages that don't specify one.</small>
            </div>
        </div>

        <!-- Header Section -->
        <div class="layout-section">
            <h2>Site Header</h2>
            <p class="section-desc">Configure how the page header appears.</p>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="hidden" name="header_visible" value="0">
                    <input type="checkbox" name="header_visible" value="1"
                           id="header_visible"
                           <?= ((int)($layout['header_visible'] ?? 1) === 1) ? 'checked' : '' ?>>
                    Show header
                </label>
            </div>

            <div id="header-options">
                <div class="form-group">
                    <label for="header_height">Height</label>
                    <input type="text" id="header_height" name="header_height"
                           value="<?= $this->e($layout['header_height'] ?? 'auto') ?>"
                           placeholder="auto or e.g. 80px">
                    <small>Use "auto" for default height, or specify a fixed height like "80px".</small>
                </div>

                <div class="form-group">
                    <label>Mode</label>
                    <div class="mode-options">
                        <label class="mode-option">
                            <input type="radio" name="header_mode" value="standard"
                                   <?= ($layout['header_mode'] ?? 'standard') === 'standard' ? 'checked' : '' ?>>
                            <span>Standard</span>
                            <small>Default header with logo and navigation.</small>
                        </label>
                        <label class="mode-option">
                            <input type="radio" name="header_mode" value="block"
                                   <?= ($layout['header_mode'] ?? 'standard') === 'block' ? 'checked' : '' ?>>
                            <span>Block Element</span>
                            <small>Replace header with a custom element.</small>
                        </label>
                    </div>
                </div>

                <div class="form-group element-picker" id="header-element-picker"
                     style="<?= ($layout['header_mode'] ?? 'standard') !== 'block' ? 'display:none;' : '' ?>">
                    <label for="header_element_id">Header Element</label>
                    <select id="header_element_id" name="header_element_id">
                        <option value="">— Select an element —</option>
                        <?php foreach ($elements as $el): ?>
                            <option value="<?= (int)$el['id'] ?>"
                                <?= ((int)($layout['header_element_id'] ?? 0) === (int)$el['id']) ? 'selected' : '' ?>>
                                <?= $this->e($el['name']) ?> (<?= $this->e($el['slug']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Choose an element from the catalogue to use as the header.</small>
                </div>
            </div>
        </div>

        <!-- Footer Section -->
        <div class="layout-section">
            <h2>Site Footer</h2>
            <p class="section-desc">Configure how the page footer appears.</p>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="hidden" name="footer_visible" value="0">
                    <input type="checkbox" name="footer_visible" value="1"
                           id="footer_visible"
                           <?= ((int)($layout['footer_visible'] ?? 1) === 1) ? 'checked' : '' ?>>
                    Show footer
                </label>
            </div>

            <div id="footer-options">
                <div class="form-group">
                    <label for="footer_height">Height</label>
                    <input type="text" id="footer_height" name="footer_height"
                           value="<?= $this->e($layout['footer_height'] ?? 'auto') ?>"
                           placeholder="auto or e.g. 60px">
                    <small>Use "auto" for default height, or specify a fixed height like "60px".</small>
                </div>

                <div class="form-group">
                    <label>Mode</label>
                    <div class="mode-options">
                        <label class="mode-option">
                            <input type="radio" name="footer_mode" value="standard"
                                   <?= ($layout['footer_mode'] ?? 'standard') === 'standard' ? 'checked' : '' ?>>
                            <span>Standard</span>
                            <small>Default footer with copyright.</small>
                        </label>
                        <label class="mode-option">
                            <input type="radio" name="footer_mode" value="block"
                                   <?= ($layout['footer_mode'] ?? 'standard') === 'block' ? 'checked' : '' ?>>
                            <span>Block Element</span>
                            <small>Replace footer with a custom element.</small>
                        </label>
                    </div>
                </div>

                <div class="form-group element-picker" id="footer-element-picker"
                     style="<?= ($layout['footer_mode'] ?? 'standard') !== 'block' ? 'display:none;' : '' ?>">
                    <label for="footer_element_id">Footer Element</label>
                    <select id="footer_element_id" name="footer_element_id">
                        <option value="">— Select an element —</option>
                        <?php foreach ($elements as $el): ?>
                            <option value="<?= (int)$el['id'] ?>"
                                <?= ((int)($layout['footer_element_id'] ?? 0) === (int)$el['id']) ? 'selected' : '' ?>>
                                <?= $this->e($el['name']) ?> (<?= $this->e($el['slug']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Choose an element from the catalogue to use as the footer.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions" style="margin-top:1rem;">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create Layout' : 'Update Layout' ?></button>
    </div>
</form>

<script src="/assets/js/layout-editor.js"></script>

<style>
.layout-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
    align-items: start;
}
.layout-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 8px;
    padding: 1.25rem;
}
.layout-section:first-child {
    grid-column: 1 / -1;
}
.layout-section h2 {
    margin-top: 0;
    font-size: 1.1rem;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--border-color, #dee2e6);
}
.section-desc {
    color: var(--text-muted, #6c757d);
    font-size: 0.85rem;
    margin-bottom: 1rem;
}
.mode-options {
    display: flex;
    gap: 0.75rem;
}
.mode-option {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.75rem;
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 6px;
    cursor: pointer;
    transition: border-color 0.15s;
}
.mode-option:hover {
    border-color: var(--color-primary, #2563eb);
}
.mode-option input[type="radio"] {
    width: auto;
    margin: 0 0 0.25rem;
}
.mode-option span {
    font-weight: 500;
    font-size: 0.95rem;
}
.mode-option small {
    color: var(--text-muted, #6c757d);
    font-size: 0.8rem;
}
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-weight: 500;
    cursor: pointer;
}
.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
}
.element-picker {
    margin-top: 0.5rem;
    padding: 0.75rem;
    background: var(--color-bg-alt, #f8fafc);
    border-radius: 6px;
}
@media (max-width: 768px) {
    .layout-form-grid {
        grid-template-columns: 1fr;
    }
    .mode-options {
        flex-direction: column;
    }
}
</style>
