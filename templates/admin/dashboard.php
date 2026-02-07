<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Dashboard</h1>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-label">Total Content</span>
        <span class="stat-value"><?= (int)$totalContent ?></span>
        <span class="stat-detail"><?= (int)$pageCount ?> pages, <?= (int)$postCount ?> posts</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Published</span>
        <span class="stat-value"><?= (int)$publishedCount ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Drafts</span>
        <span class="stat-value"><?= (int)$draftCount ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Users</span>
        <span class="stat-value"><?= (int)$userCount ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Media Files</span>
        <span class="stat-value"><?= (int)$mediaCount ?></span>
    </div>
</div>

<!-- Recent Content -->
<div class="card">
    <div class="card-header">
        Recent Content
    </div>
    <?php if (empty($recentContent)): ?>
        <div class="card-body">
            <div class="empty-state">
                <p>No content yet. Start by creating your first page or post.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Author</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentContent as $item): ?>
                        <tr>
                            <td>
                                <strong><?= $this->e($item['title']) ?></strong>
                            </td>
                            <td>
                                <span class="badge"><?= $this->e(ucfirst($item['type'])) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $this->e($item['status']) ?>">
                                    <?= $this->e(ucfirst($item['status'])) ?>
                                </span>
                            </td>
                            <td><?= $this->e($item['author_name'] ?? 'Unknown') ?></td>
                            <td class="text-muted">
                                <?= $this->e($item['updated_at'] ?? $item['created_at'] ?? '') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
