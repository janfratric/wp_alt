<?php declare(strict_types=1); ?>
<?php $this->layout('admin/layout'); ?>

<div class="content-header">
    <h1>View Message</h1>
    <a href="/admin/contact-submissions" class="btn">Back to Messages</a>
</div>

<div class="card">
    <table class="detail-table">
        <tr>
            <th>Name</th>
            <td><?= $this->e($submission['name']) ?></td>
        </tr>
        <tr>
            <th>Email</th>
            <td><a href="mailto:<?= $this->e($submission['email']) ?>"><?= $this->e($submission['email']) ?></a></td>
        </tr>
        <tr>
            <th>Subject</th>
            <td><?= $this->e($submission['subject'] ?: '—') ?></td>
        </tr>
        <tr>
            <th>IP Address</th>
            <td><?= $this->e($submission['ip_address'] ?? '—') ?></td>
        </tr>
        <tr>
            <th>Date</th>
            <td><?= $this->e(date('F j, Y g:i:s A', strtotime($submission['created_at']))) ?></td>
        </tr>
        <tr>
            <th>Message</th>
            <td class="message-body"><?= nl2br($this->e($submission['message'])) ?></td>
        </tr>
    </table>
</div>

<form method="POST" action="/admin/contact-submissions/<?= $this->e((string)$submission['id']) ?>"
      style="margin-top: 1rem;" onsubmit="return confirm('Delete this message?');">
    <input type="hidden" name="_method" value="DELETE">
    <?= $this->csrfField() ?>
    <button type="submit" class="btn btn-danger">Delete Message</button>
</form>
