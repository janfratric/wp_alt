<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Layout Templates</h1>
    <a href="/admin/layouts/create" class="btn btn-primary">+ New Layout</a>
</div>

<?php if (empty($templates)): ?>
    <div class="card">
        <div class="card-body" style="text-align:center;padding:3rem;">
            <p style="font-size:1.1rem;color:var(--color-text-muted);">No layout templates yet.</p>
            <a href="/admin/layouts/create" class="btn btn-primary">Create your first layout</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Header</th>
                    <th>Footer</th>
                    <th>Pages</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $tpl): ?>
                <tr>
                    <td>
                        <a href="/admin/layouts/<?= (int)$tpl['id'] ?>/edit" style="font-weight:500;">
                            <?= $this->e($tpl['name']) ?>
                        </a>
                        <?php if ((int)$tpl['is_default'] === 1): ?>
                            <span class="badge badge-published">Default</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= $this->e($tpl['slug']) ?></code></td>
                    <td>
                        <?php if ((int)($tpl['header_visible'] ?? 1) === 0): ?>
                            <span style="color:var(--color-text-muted);">Hidden</span>
                        <?php elseif (($tpl['header_mode'] ?? 'standard') === 'block'): ?>
                            <span class="badge badge-draft">Block</span>
                        <?php else: ?>
                            Standard
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)($tpl['footer_visible'] ?? 1) === 0): ?>
                            <span style="color:var(--color-text-muted);">Hidden</span>
                        <?php elseif (($tpl['footer_mode'] ?? 'standard') === 'block'): ?>
                            <span class="badge badge-draft">Block</span>
                        <?php else: ?>
                            Standard
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$tpl['usage_count'] ?></td>
                    <td>
                        <div style="display:flex;gap:0.25rem;">
                            <a href="/admin/layouts/<?= (int)$tpl['id'] ?>/edit" class="btn btn-sm">Edit</a>
                            <?php if ((int)$tpl['is_default'] !== 1 && (int)$tpl['usage_count'] === 0): ?>
                                <form method="POST" action="/admin/layouts/<?= (int)$tpl['id'] ?>"
                                      onsubmit="return confirm('Delete this layout template?');">
                                    <?= $this->csrfField() ?>
                                    <input type="hidden" name="_method" value="DELETE">
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
