<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <div class="page-header-left">
        <a href="/admin/elements" class="btn btn-link">&larr; Back to Elements</a>
        <h1><?= $this->e($title) ?></h1>
    </div>
</div>

<div class="proposal-filters" style="margin-bottom:1rem;display:flex;gap:0.5rem;">
    <a href="/admin/element-proposals?status=pending"
       class="btn btn-sm <?= ($statusFilter ?? 'pending') === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">
        Pending
    </a>
    <a href="/admin/element-proposals?status=approved"
       class="btn btn-sm <?= ($statusFilter ?? '') === 'approved' ? 'btn-primary' : 'btn-secondary' ?>">
        Approved
    </a>
    <a href="/admin/element-proposals?status=rejected"
       class="btn btn-sm <?= ($statusFilter ?? '') === 'rejected' ? 'btn-primary' : 'btn-secondary' ?>">
        Rejected
    </a>
</div>

<?php if (empty($proposals)): ?>
    <div class="empty-state">
        <p>No <?= $this->e($statusFilter ?? 'pending') ?> proposals.</p>
    </div>
<?php else: ?>
    <?php foreach ($proposals as $proposal): ?>
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <strong><?= $this->e($proposal['name'] ?? 'Untitled') ?></strong>
            <span class="badge"><?= $this->e($proposal['category'] ?? 'general') ?></span>
        </div>
        <div class="card-body" style="padding:1rem;">
            <?php if (!empty($proposal['description'])): ?>
                <p><?= $this->e($proposal['description']) ?></p>
            <?php endif; ?>
            <details style="margin-top:0.5rem;">
                <summary>HTML Template</summary>
                <pre style="background:#f8f9fa;padding:0.5rem;border-radius:4px;overflow:auto;max-height:200px;"><code><?= $this->e($proposal['html_template'] ?? '') ?></code></pre>
            </details>
            <details style="margin-top:0.5rem;">
                <summary>CSS</summary>
                <pre style="background:#f8f9fa;padding:0.5rem;border-radius:4px;overflow:auto;max-height:200px;"><code><?= $this->e($proposal['css'] ?? '') ?></code></pre>
            </details>
        </div>
        <?php if (($proposal['status'] ?? '') === 'pending'): ?>
        <div class="card-footer" style="padding:0.75rem 1rem;display:flex;gap:0.5rem;">
            <form method="POST" action="/admin/element-proposals/<?= (int)$proposal['id'] ?>/approve" style="display:inline;">
                <?= $this->csrfField() ?>
                <button type="submit" class="btn btn-primary btn-sm">Approve</button>
            </form>
            <form method="POST" action="/admin/element-proposals/<?= (int)$proposal['id'] ?>/reject" style="display:inline;">
                <?= $this->csrfField() ?>
                <button type="submit" class="btn btn-danger btn-sm">Reject</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
