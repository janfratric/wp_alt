<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Content</h1>
    <a href="/admin/content/create" class="btn btn-primary">+ New Content</a>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 1rem;">
    <div class="card-body">
        <form method="GET" action="/admin/content" class="filter-form">
            <div class="form-group search-group">
                <label for="q">Search</label>
                <input type="text" id="q" name="q"
                       value="<?= $this->e($search) ?>" placeholder="Search by title...">
            </div>
            <div class="form-group">
                <label for="filter-type">Type</label>
                <select id="filter-type" name="type">
                    <option value="">All Types</option>
                    <option value="page" <?= $type === 'page' ? 'selected' : '' ?>>Page</option>
                    <option value="post" <?= $type === 'post' ? 'selected' : '' ?>>Post</option>
                </select>
            </div>
            <div class="form-group">
                <label for="filter-status">Status</label>
                <select id="filter-status" name="status">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="/admin/content" class="btn btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Content Table with Bulk Actions -->
<form method="POST" action="/admin/content/bulk" id="bulk-form">
    <?= $this->csrfField() ?>

    <div class="card">
        <div class="card-header">
            <span><?= (int)$total ?> item(s)</span>
            <div class="bulk-actions">
                <select name="bulk_action">
                    <option value="">Bulk Actions</option>
                    <option value="publish">Set Published</option>
                    <option value="draft">Set Draft</option>
                    <option value="archive">Set Archived</option>
                    <option value="delete">Delete</option>
                </select>
                <button type="submit" class="btn btn-sm"
                        data-confirm="Apply this action to all selected items?">Apply</button>
            </div>
        </div>

        <?php if (empty($items)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <p>No content found.</p>
                    <a href="/admin/content/create" class="btn btn-primary">Create your first page</a>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all"></th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Author</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="ids[]"
                                           value="<?= (int)$item['id'] ?>">
                                </td>
                                <td>
                                    <a href="/admin/content/<?= (int)$item['id'] ?>/edit">
                                        <strong><?= $this->e($item['title']) ?></strong>
                                    </a>
                                    <div class="text-muted" style="font-size:0.8rem;">
                                        /<?= $this->e($item['slug']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge">
                                        <?= $this->e(ucfirst($item['type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $this->e($item['status']) ?>">
                                        <?= $this->e(ucfirst($item['status'])) ?>
                                    </span>
                                </td>
                                <td><?= $this->e($item['author_name'] ?? 'Unknown') ?></td>
                                <td class="text-muted">
                                    <?= $this->e($item['updated_at'] ?? '') ?>
                                </td>
                                <td>
                                    <a href="/admin/content/<?= (int)$item['id'] ?>/edit"
                                       class="btn btn-sm">Edit</a>
                                    <form method="POST"
                                          action="/admin/content/<?= (int)$item['id'] ?>/delete"
                                          style="display:inline;">
                                        <?= $this->csrfField() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                data-confirm="Are you sure you want to delete this content?">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</form>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        // Build query string preserving filters
        $queryParams = [];
        if ($type !== '') $queryParams['type'] = $type;
        if ($status !== '') $queryParams['status'] = $status;
        if ($search !== '') $queryParams['q'] = $search;
        ?>

        <?php if ($page > 1): ?>
            <?php $queryParams['page'] = $page - 1; ?>
            <a href="/admin/content?<?= http_build_query($queryParams) ?>">« Prev</a>
        <?php endif; ?>

        <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <?php $queryParams['page'] = $page + 1; ?>
            <a href="/admin/content?<?= http_build_query($queryParams) ?>">Next »</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script src="/assets/js/editor.js"></script>
