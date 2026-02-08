<?php $this->layout('admin/layout'); ?>

<div class="elements-page">
    <div class="page-header">
        <div class="page-header-left">
            <h1>Element Catalogue</h1>
            <span class="badge"><?= count($elements) ?> element(s)</span>
        </div>
        <a href="/admin/elements/create" class="btn btn-primary">+ New Element</a>
    </div>

    <!-- Filters -->
    <div class="elements-filters">
        <form method="GET" action="/admin/elements" class="filter-form">
            <select name="category" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $this->e($cat) ?>"
                        <?= ($filter['category'] ?? '') === $cat ? 'selected' : '' ?>>
                        <?= $this->e(ucfirst($cat)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" placeholder="Search elements..."
                   value="<?= $this->e($filter['q'] ?? '') ?>">
            <button type="submit" class="btn btn-secondary">Search</button>
            <?php if (!empty($filter['category']) || !empty($filter['q'])): ?>
                <a href="/admin/elements" class="btn btn-link">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($elements)): ?>
        <div class="empty-state">
            <p>No elements yet. Create your first reusable element.</p>
            <a href="/admin/elements/create" class="btn btn-primary">Create Element</a>
        </div>
    <?php else: ?>
        <div class="elements-grid">
            <?php foreach ($elements as $el): ?>
                <div class="element-card">
                    <div class="element-card-header">
                        <span class="element-category-badge"><?= $this->e($el['category']) ?></span>
                        <span class="element-status element-status-<?= $this->e($el['status']) ?>">
                            <?= $this->e(ucfirst($el['status'])) ?>
                        </span>
                    </div>
                    <div class="element-card-body">
                        <h3><?= $this->e($el['name']) ?></h3>
                        <code class="element-slug"><?= $this->e($el['slug']) ?></code>
                        <?php if (!empty($el['description'])): ?>
                            <p class="element-desc"><?= $this->e(mb_substr($el['description'], 0, 100)) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="element-card-footer">
                        <span class="element-usage" title="Pages using this element">
                            <?= (int) $el['usage_count'] ?> usage(s)
                        </span>
                        <div class="element-card-actions">
                            <a href="/admin/elements/<?= (int) $el['id'] ?>/edit" class="btn btn-sm">Edit</a>
                            <?php if ((int) $el['usage_count'] === 0): ?>
                                <form method="POST" action="/admin/elements/<?= (int) $el['id'] ?>"
                                      style="display:inline;"
                                      onsubmit="return confirm('Delete this element?');">
                                    <input type="hidden" name="_method" value="DELETE">
                                    <?= $this->csrfField() ?>
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
