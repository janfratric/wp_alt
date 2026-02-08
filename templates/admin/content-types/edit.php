<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $isNew ? 'Create Content Type' : 'Edit: ' . $this->e($type['name']) ?></h1>
    <a href="/admin/content-types" class="btn">← Back to Content Types</a>
</div>

<form method="POST"
      action="<?= $isNew ? '/admin/content-types' : '/admin/content-types/' . (int)$type['id'] ?>"
      id="content-type-form">
    <?= $this->csrfField() ?>
    <?php if (!$isNew): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-header">Type Settings</div>
        <div class="card-body">
            <div class="form-group">
                <label for="ct-name">Name <span style="color:var(--color-error);">*</span></label>
                <input type="text" id="ct-name" name="name"
                       value="<?= $this->e($type['name']) ?>"
                       required maxlength="100"
                       placeholder="e.g. Products, Team Members, Testimonials">
            </div>

            <div class="form-group">
                <label for="ct-slug">Slug <span style="color:var(--color-error);">*</span></label>
                <input type="text" id="ct-slug" name="slug"
                       value="<?= $this->e($type['slug']) ?>"
                       maxlength="50"
                       placeholder="auto-generated-from-name"
                       pattern="[a-z0-9]+(-[a-z0-9]+)*">
                <small class="text-muted">Used in URLs. Lowercase letters, numbers, and hyphens only.</small>
            </div>

            <div class="form-group mb-0">
                <label>
                    <input type="checkbox" name="has_archive" value="1"
                           <?= (int)($type['has_archive'] ?? 1) ? 'checked' : '' ?>>
                    Enable archive page
                </label>
                <small class="text-muted">When enabled, a public listing page is available at /slug/</small>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-header">
            <span>Custom Fields</span>
            <button type="button" id="add-field-btn" class="btn btn-sm btn-primary">+ Add Field</button>
        </div>
        <div class="card-body">
            <div id="field-list">
                <!-- Field rows rendered by JS from fields_json -->
            </div>
            <p id="no-fields-msg" class="text-muted" style="text-align:center; padding: 1rem 0;">
                No custom fields defined. Click "Add Field" to begin.
            </p>
        </div>
    </div>

    <!-- Hidden input to hold serialized fields JSON -->
    <input type="hidden" name="fields_json" id="fields-json-input"
           value="<?= $this->e($type['fields_json'] ?? '[]') ?>">

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <?= $isNew ? 'Create Content Type' : 'Update Content Type' ?>
        </button>
        <?php if (!$isNew && ($contentCount ?? 0) === 0): ?>
            <button type="button" class="btn btn-danger" id="delete-type-btn"
                    style="margin-left: 0.5rem;">Delete Type</button>
        <?php elseif (!$isNew): ?>
            <span class="text-muted" style="margin-left: 0.5rem;">
                <?= (int)$contentCount ?> content item(s) use this type
            </span>
        <?php endif; ?>
    </div>
</form>

<?php if (!$isNew): ?>
<form method="POST" id="delete-type-form"
      action="/admin/content-types/<?= (int)$type['id'] ?>" style="display:none;">
    <?= $this->csrfField() ?>
    <input type="hidden" name="_method" value="DELETE">
</form>
<?php endif; ?>

<script src="/assets/js/field-builder.js"></script>
<script src="/assets/js/content-type-editor.js"></script>
