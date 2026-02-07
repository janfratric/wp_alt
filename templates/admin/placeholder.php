<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $this->e($title ?? 'Coming Soon') ?></h1>
</div>

<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <p><?= $this->e($message ?? 'This feature is not yet implemented.') ?></p>
        </div>
    </div>
</div>
