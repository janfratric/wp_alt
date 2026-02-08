<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Content Types</h1>
    <a href="/admin/content-types/create" class="btn btn-primary">+ New Content Type</a>
</div>

<?php if (empty($types)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <p>No custom content types defined yet.</p>
                <p class="text-muted">Content types let you create structured content like Products, Team Members, Testimonials, etc.</p>
                <a href="/admin/content-types/create" class="btn btn-primary">Create your first content type</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Fields</th>
                        <th>Content Items</th>
                        <th>Archive</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $ct): ?>
                        <?php $fields = json_decode($ct['fields_json'] ?? '[]', true) ?: []; ?>
                        <tr>
                            <td>
                                <a href="/admin/content-types/<?= (int)$ct['id'] ?>/edit">
                                    <strong><?= $this->e($ct['name']) ?></strong>
                                </a>
                            </td>
                            <td class="text-muted"><?= $this->e($ct['slug']) ?></td>
                            <td><?= count($fields) ?> field(s)</td>
                            <td>
                                <?php if ((int)($ct['content_count'] ?? 0) > 0): ?>
                                    <a href="/admin/content?type=<?= urlencode($ct['slug']) ?>">
                                        <?= (int)$ct['content_count'] ?> item(s)
                                    </a>
                                <?php else: ?>
                                    0 items
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)($ct['has_archive'] ?? 1)): ?>
                                    <span class="badge badge-published">Yes</span>
                                <?php else: ?>
                                    <span class="badge">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/content-types/<?= (int)$ct['id'] ?>/edit"
                                   class="btn btn-sm">Edit</a>
                                <button type="button" class="btn btn-sm btn-danger delete-ct-btn"
                                        data-id="<?= (int)$ct['id'] ?>"
                                        data-name="<?= $this->e($ct['name']) ?>">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Delete form -->
    <form method="POST" id="delete-ct-form" style="display:none;">
        <?= $this->csrfField() ?>
        <input type="hidden" name="_method" value="DELETE">
    </form>

    <script src="/assets/js/content-type-list.js"></script>
<?php endif; ?>
