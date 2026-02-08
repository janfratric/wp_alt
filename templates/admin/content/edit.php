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

            <!-- Editor Mode Toggle -->
            <div class="form-group pb-mode-toggle">
                <label>Editor Mode</label>
                <div class="pb-mode-options">
                    <label class="pb-mode-option">
                        <input type="radio" name="editor_mode" value="html"
                               <?= ($content['editor_mode'] ?? 'html') === 'html' ? 'checked' : '' ?>>
                        <span>HTML Editor</span>
                    </label>
                    <label class="pb-mode-option">
                        <input type="radio" name="editor_mode" value="elements"
                               <?= ($content['editor_mode'] ?? 'html') === 'elements' ? 'checked' : '' ?>>
                        <span>Page Builder</span>
                    </label>
                </div>
            </div>

            <!-- HTML Editor Panel (visible when editor_mode = html) -->
            <div id="html-editor-panel"
                 class="<?= ($content['editor_mode'] ?? 'html') === 'elements' ? 'hidden' : '' ?>">
                <div class="form-group">
                    <label for="body">Body</label>
                    <textarea id="body" name="body" rows="20"><?= $content['body'] ?></textarea>
                </div>
            </div>

            <!-- Page Builder Panel (visible when editor_mode = elements) -->
            <div id="page-builder-panel"
                 class="<?= ($content['editor_mode'] ?? 'html') !== 'elements' ? 'hidden' : '' ?>"
                 data-instances="<?= $this->e(json_encode($pageElements ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
                 data-csrf="<?= $this->e($csrfToken ?? '') ?>">
                <div class="pb-toolbar">
                    <button type="button" id="pb-add-element" class="btn btn-primary btn-sm">
                        + Add Element
                    </button>
                    <span id="pb-element-count" class="pb-count-badge">0 elements</span>
                </div>

                <div id="pb-instance-list" class="pb-instance-list">
                    <div class="pb-empty-state" id="pb-empty-state">
                        <div class="pb-empty-icon">&#9647;</div>
                        <p>No elements added yet.</p>
                        <p style="font-size:0.85rem;color:var(--color-text-muted);">
                            Click "Add Element" to start building your page.
                        </p>
                    </div>
                </div>

                <input type="hidden" id="elements-json-input" name="elements_json" value="">
            </div>

            <!-- Element Picker Modal -->
            <div id="pb-picker-modal" class="pb-picker-modal hidden">
                <div class="pb-picker-overlay"></div>
                <div class="pb-picker-content">
                    <div class="pb-picker-header">
                        <h3>Choose an Element</h3>
                        <button type="button" class="pb-picker-close" title="Close">&times;</button>
                    </div>
                    <div class="pb-picker-search">
                        <input type="text" id="pb-picker-search" placeholder="Search elements...">
                    </div>
                    <div class="pb-picker-categories" id="pb-picker-categories"></div>
                    <div class="pb-picker-grid" id="pb-picker-grid"></div>
                </div>
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
            <div class="ai-resize-handle"></div>
            <div class="ai-panel-header" id="ai-panel-header">
                <div class="ai-panel-header-left">
                    <select class="ai-model-select" title="Select model"></select>
                </div>
                <div class="ai-panel-header-actions">
                    <button type="button" id="ai-compact-btn" title="Compact conversation" style="display:none;">Compact</button>
                    <button type="button" id="ai-history-btn" title="Conversation history">History</button>
                    <button type="button" id="ai-new-conversation" title="New conversation">New</button>
                    <button type="button" id="ai-close-btn" title="Close panel">&times;</button>
                </div>
            </div>
            <div class="ai-context-meter">
                <div class="context-bar-track">
                    <div class="context-bar context-bar-ok" style="width:0"></div>
                </div>
                <span class="context-text">0 / 200.0k</span>
            </div>
            <div id="ai-history-dropdown" class="ai-history-dropdown" style="display:none;"></div>
            <div id="ai-messages">
                <div class="ai-empty-state">
                    <div class="ai-empty-icon">&#9733;</div>
                    <p>Ask the AI assistant to help write, edit, or improve your content.</p>
                    <p style="font-size:0.8rem;margin-top:0.25rem;">Try: "Write an introduction for this page" or "Make this text more concise"</p>
                </div>
            </div>
            <div id="ai-attach-preview" class="ai-attachments-preview"></div>
            <div class="ai-panel-input">
                <button type="button" id="ai-attach-btn" class="ai-attach-btn" title="Attach image">&#128206;</button>
                <textarea id="ai-input" rows="1"
                          placeholder="Ask the AI assistant..."
                          autocomplete="off"></textarea>
                <button type="button" id="ai-send-btn" title="Send message">&#10148;</button>
            </div>
        </div>
    </div>
</form>

<script src="/assets/js/page-builder.js"></script>
<script src="/assets/js/page-builder-init.js"></script>
<script src="/assets/js/editor.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11/build/styles/github-dark.min.css">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11/build/highlight.min.js"></script>
<script src="/assets/js/ai-chat-core.js"></script>
<script src="/assets/js/ai-assistant.js"></script>
<script src="/assets/js/custom-fields.js"></script>
