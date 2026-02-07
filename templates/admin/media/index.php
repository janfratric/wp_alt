<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Media Library</h1>
    <span class="text-muted"><?= $total ?> file(s)</span>
</div>

<!-- Upload Form -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">Upload New File</div>
    <div class="card-body">
        <form method="POST" action="/admin/media/upload" enctype="multipart/form-data"
              id="upload-form">
            <?= $this->csrfField() ?>
            <div class="upload-zone" id="upload-zone">
                <div class="upload-zone-content">
                    <p class="upload-zone-icon">&#128206;</p>
                    <p>Drag & drop a file here, or click to select</p>
                    <p class="text-muted" style="font-size: 0.8rem;">
                        Allowed: JPG, PNG, GIF, WebP, PDF — Max 5 MB
                    </p>
                </div>
                <input type="file" name="file" id="file-input"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.pdf"
                       style="display: none;">
            </div>
            <div id="upload-preview" style="display:none; margin-top: 1rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <img id="upload-preview-img" src="" alt="Preview"
                         style="max-width: 80px; max-height: 80px; border-radius: 4px; display: none;">
                    <div>
                        <div id="upload-preview-name" style="font-weight: 500;"></div>
                        <div id="upload-preview-size" class="text-muted"
                             style="font-size: 0.85rem;"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                    <button type="button" class="btn" id="upload-cancel">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Media Grid -->
<?php if (empty($items)): ?>
    <div class="empty-state">
        <p>No media files uploaded yet.</p>
        <p class="text-muted">Upload your first file using the form above.</p>
    </div>
<?php else: ?>
    <div class="media-grid">
        <?php foreach ($items as $item): ?>
            <?php
            $isImage = str_starts_with($item['mime_type'], 'image/');
            $url = '/assets/uploads/' . $item['filename'];
            ?>
            <div class="media-card" data-id="<?= (int)$item['id'] ?>">
                <div class="media-card-preview">
                    <?php if ($isImage): ?>
                        <img src="<?= $this->e($url) ?>"
                             alt="<?= $this->e($item['original_name']) ?>"
                             loading="lazy">
                    <?php else: ?>
                        <div class="media-card-icon">&#128196;</div>
                    <?php endif; ?>
                </div>
                <div class="media-card-info">
                    <div class="media-card-name" title="<?= $this->e($item['original_name']) ?>">
                        <?= $this->e($item['original_name']) ?>
                    </div>
                    <div class="media-card-meta">
                        <?= $this->e($item['mime_type']) ?>
                        — <?= $this->e($item['uploaded_by_name'] ?? 'unknown') ?>
                    </div>
                </div>
                <div class="media-card-actions">
                    <a href="<?= $this->e($url) ?>" target="_blank"
                       class="btn btn-sm">View</a>
                    <form method="POST" action="/admin/media/<?= (int)$item['id'] ?>"
                          style="display:inline;"
                          onsubmit="return confirm('Delete this file permanently?');">
                        <?= $this->csrfField() ?>
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="/admin/media?page=<?= $page - 1 ?>">← Prev</a>
            <?php endif; ?>

            <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

            <?php if ($page < $totalPages): ?>
                <a href="/admin/media?page=<?= $page + 1 ?>">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
