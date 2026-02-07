<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $isNew ? 'Create Content' : 'Edit: ' . $this->e($content['title']) ?></h1>
    <a href="/admin/content" class="btn">← Back to Content</a>
</div>

<form method="POST"
      action="<?= $isNew ? '/admin/content' : '/admin/content/' . (int)$content['id'] ?>"
      id="content-form">
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
    </div>
</form>

<script src="/assets/js/editor.js"></script>
