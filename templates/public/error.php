<?php declare(strict_types=1); ?>
<?php $this->layout('public/layout'); ?>

<div class="error-page">
    <h1><?= $this->e($errorCode ?? '500') ?> — <?= $this->e($errorTitle ?? 'Server Error') ?></h1>
    <p><?= $this->e($errorMessage ?? 'Something went wrong. Please try again later.') ?></p>
    <p><a href="/">Return to homepage</a></p>
</div>
