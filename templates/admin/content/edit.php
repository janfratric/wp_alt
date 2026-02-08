<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $isNew ? 'Create Content' : 'Edit: ' . $this->e($content['title']) ?></h1>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <a href="/admin/content" class="btn">← Back to Content</a>
        <button type="button" id="ai-toggle-btn" aria-expanded="false"
                title="Toggle AI Assistant">
            <span class="ai-icon">&#9733;</span> AI Assistant
        </button>
    </div>
</div>

<form method="POST"
      action="<?= $isNew ? '/admin/content' : '/admin/content/' . (int)$content['id'] ?>"
      id="content-form"
      data-content-id="<?= $isNew ? '' : (int)$content['id'] ?>">
    <?= $this->csrfField() ?>
    <?php if (!$isNew): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="editor-layout">
        <!-- Main Column -->
        <div class="editor-main">
            <div class="form-group">
                <label for="title">Title <span style="color:var(--color-error);">*</span></label>
                <input type="text" id="title" name="title"
                       value="<?= $this->e($content['title']) ?>"
                       required maxlength="255"
                       placeholder="Enter title...">
            </div>

            <div class="form-group">
                <label for="slug">Slug</label>
                <div class="slug-field">
                    <span class="slug-prefix">/</span>
                    <input type="text" id="slug" name="slug"
                           value="<?= $this->e($content['slug']) ?>"
                           placeholder="auto-generated-from-title">
                </div>
            </div>

            <div class="form-group">
                <label for="body">Body</label>
                <textarea id="body" name="body" rows="20"><?= $content['body'] ?></textarea>
            </div>

            <div class="form-group">
                <label for="excerpt">Excerpt</label>
                <textarea id="excerpt" name="excerpt" rows="3"
                          placeholder="Brief summary for listings..."><?= $this->e($content['excerpt'] ?? '') ?></textarea>
            </div>

            <?php if (!empty($customFieldDefinitions)): ?>
            <div class="custom-fields-section" style="margin-top: 1rem;">
                <h3 style="margin-bottom: 0.75rem;">Custom Fields</h3>
                <?php foreach ($customFieldDefinitions as $fieldDef): ?>
                    <div class="form-group">
                        <label for="cf_<?= $this->e($fieldDef['key']) ?>">
                            <?= $this->e($fieldDef['label']) ?>
                            <?php if (!empty($fieldDef['required'])): ?>
                                <span style="color:var(--color-error);">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($fieldDef['type'] === 'text'): ?>
                            <input type="text"
                                   id="cf_<?= $this->e($fieldDef['key']) ?>"
                                   name="custom_fields[<?= $this->e($fieldDef['key']) ?>]"
                                   value="<?= $this->e($customFieldValues[$fieldDef['key']] ?? '') ?>">

                        <?php elseif ($fieldDef['type'] === 'textarea'): ?>
                            <textarea id="cf_<?= $this->e($fieldDef['key']) ?>"
                                      name="custom_fields[<?= $this->e($fieldDef['key']) ?>]"
                                      rows="5"><?= $this->e($customFieldValues[$fieldDef['key']] ?? '') ?></textarea>

                        <?php elseif ($fieldDef['type'] === 'image'): ?>
                            <?php $cfImgVal = $customFieldValues[$fieldDef['key']] ?? ''; ?>
                            <div style="margin-bottom: 0.5rem;">
                                <img id="cf_preview_<?= $this->e($fieldDef['key']) ?>"
                                     src="<?= $this->e($cfImgVal) ?>"
                                     alt=""
                                     style="max-width:200px;max-height:120px;<?= $cfImgVal ? '' : 'display:none;' ?>">
                            </div>
                            <input type="hidden"
                                   id="cf_<?= $this->e($fieldDef['key']) ?>"
                                   name="custom_fields[<?= $this->e($fieldDef['key']) ?>]"
                                   value="<?= $this->e($cfImgVal) ?>">
                            <button type="button" class="btn btn-sm cf-image-browse"
                                    data-field="<?= $this->e($fieldDef['key']) ?>">Browse Media</button>
                            <button type="button" class="btn btn-sm cf-image-remove"
                                    data-field="<?= $this->e($fieldDef['key']) ?>"
                                    style="<?= $cfImgVal ? '' : 'display:none;' ?>">Remove</button>

                        <?php elseif ($fieldDef['type'] === 'boolean'): ?>
                            <div>
                                <label style="cursor:pointer;">
                                    <input type="checkbox"
                                           id="cf_<?= $this->e($fieldDef['key']) ?>"
                                           name="custom_fields[<?= $this->e($fieldDef['key']) ?>]"
                                           value="1"
                                           <?= ($customFieldValues[$fieldDef['key']] ?? '0') === '1' ? 'checked' : '' ?>>
                                    Yes
                                </label>
                            </div>

                        <?php elseif ($fieldDef['type'] === 'select'): ?>
                            <select id="cf_<?= $this->e($fieldDef['key']) ?>"
                                    name="custom_fields[<?= $this->e($fieldDef['key']) ?>]">
                                <option value="">— Select —</option>
                                <?php foreach (($fieldDef['options'] ?? []) as $option): ?>
                                    <option value="<?= $this->e($option) ?>"
                                            <?= ($customFieldValues[$fieldDef['key']] ?? '') === $option ? 'selected' : '' ?>>
                                        <?= $this->e($option) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Column -->
        <div class="editor-sidebar">
            <!-- Publish Card -->
            <div class="card">
                <div class="card-header">Publish</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value="page" <?= ($content['type'] ?? 'page') === 'page' ? 'selected' : '' ?>>Page</option>
                            <option value="post" <?= ($content['type'] ?? '') === 'post' ? 'selected' : '' ?>>Post</option>
                            <?php if (!empty($contentTypes)): ?>
                                <?php foreach ($contentTypes as $ct): ?>
                                    <option value="<?= $this->e($ct['slug']) ?>"
                                            <?= ($content['type'] ?? '') === $ct['slug'] ? 'selected' : '' ?>>
                                        <?= $this->e($ct['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft" <?= ($content['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($content['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="archived" <?= ($content['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="published_at">Publish Date</label>
                        <?php
                        $pubAt = $content['published_at'] ?? '';
                        $pubAtValue = '';
                        if ($pubAt !== '' && $pubAt !== null) {
                            $ts = strtotime($pubAt);
                            if ($ts !== false) {
                                $pubAtValue = date('Y-m-d\TH:i', $ts);
                            }
                        }
                        ?>
                        <input type="datetime-local" id="published_at" name="published_at"
                               value="<?= $this->e($pubAtValue) ?>">
                    </div>

                    <div class="form-group">
                        <label for="sort_order">Sort Order</label>
                        <input type="number" id="sort_order" name="sort_order"
                               value="<?= (int)($content['sort_order'] ?? 0) ?>" min="0">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <?= $isNew ? 'Create' : 'Update' ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- SEO Card -->
            <div class="card">
                <div class="card-header">SEO</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="meta_title">Meta Title</label>
                        <input type="text" id="meta_title" name="meta_title"
                               value="<?= $this->e($content['meta_title'] ?? '') ?>"
                               maxlength="255"
                               placeholder="Custom title for search engines">
                    </div>
                    <div class="form-group mb-0">
                        <label for="meta_description">Meta Description</label>
                        <textarea id="meta_description" name="meta_description"
                                  rows="3" maxlength="320"
                                  placeholder="Brief description for search results..."><?= $this->e($content['meta_description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Featured Image Card -->
            <div class="card">
                <div class="card-header">Featured Image</div>
                <div class="card-body">
                    <div class="form-group mb-0">
                        <?php
                        $featuredImg = $content['featured_image'] ?? '';
                        $hasImage = ($featuredImg !== '' && $featuredImg !== null);
                        ?>
                        <div class="featured-image-preview"
                             style="<?= $hasImage ? '' : 'display:none;' ?>">
                            <img id="featured-image-preview-img"
                                 src="<?= $this->e((string)$featuredImg) ?>"
                                 alt="Featured image preview">
                        </div>
                        <input type="hidden" id="featured_image" name="featured_image"
                               value="<?= $this->e((string)$featuredImg) ?>">
                        <div class="featured-image-actions">
                            <button type="button" class="btn btn-sm" id="featured-image-browse">
                                Browse Media
                            </button>
                            <button type="button" class="btn btn-sm"
                                    id="featured-image-remove"
                                    style="<?= $hasImage ? '' : 'display:none;' ?>">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Assistant Panel (hidden by default, shown when toggled) -->
        <div id="ai-panel">
            <div class="ai-panel-header">
                <span>AI Assistant</span>
                <div class="ai-panel-header-actions">
                    <button type="button" id="ai-new-conversation" title="New conversation">New</button>
                    <button type="button" id="ai-close-btn" title="Close panel">&times;</button>
                </div>
            </div>
            <div id="ai-messages">
                <div class="ai-empty-state">
                    <div class="ai-empty-icon">&#9733;</div>
                    <p>Ask the AI assistant to help write, edit, or improve your content.</p>
                    <p style="font-size:0.8rem;margin-top:0.25rem;">Try: "Write an introduction for this page" or "Make this text more concise"</p>
                </div>
            </div>
            <div class="ai-panel-input">
                <textarea id="ai-input" rows="1"
                          placeholder="Ask the AI assistant..."
                          autocomplete="off"></textarea>
                <button type="button" id="ai-send-btn" title="Send message">&#10148;</button>
            </div>
        </div>
    </div>
</form>

<script src="/assets/js/editor.js"></script>
<script src="/assets/js/ai-assistant.js"></script>
<script src="/assets/js/custom-fields.js"></script>
