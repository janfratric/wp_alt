<?php declare(strict_types=1); ?>
<?php $this->layout('admin/layout'); ?>

<div class="content-header">
    <h1>Messages</h1>
</div>

<?php if (empty($submissions)): ?>
    <div class="empty-state">
        <p>No contact form submissions yet.</p>
    </div>
<?php else: ?>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Subject</th>
                <th>Message</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($submissions as $s): ?>
            <tr>
                <td><?= $this->e($s['name']) ?></td>
                <td><a href="mailto:<?= $this->e($s['email']) ?>"><?= $this->e($s['email']) ?></a></td>
                <td><?= $this->e($s['subject'] ?: '—') ?></td>
                <td class="message-preview"><?= $this->e(mb_strimwidth($s['message'], 0, 80, '...')) ?></td>
                <td><?= $this->e(date('M j, Y g:ia', strtotime($s['created_at']))) ?></td>
                <td>
                    <a href="/admin/contact-submissions/<?= $this->e((string)$s['id']) ?>" class="btn btn-sm">View</a>
                    <form method="POST" action="/admin/contact-submissions/<?= $this->e((string)$s['id']) ?>"
                          style="display:inline;" onsubmit="return confirm('Delete this message?');">
                        <input type="hidden" name="_method" value="DELETE">
                        <?= $this->csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="pagination-current"><?= $i ?></span>
        <?php else: ?>
            <a href="/admin/contact-submissions?page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
