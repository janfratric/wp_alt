<?php $this->layout('auth/layout'); ?>

<h1>LiteCMS</h1>
<p class="subtitle">Sign in to your account</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= $this->e($error) ?></div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= $this->e($success) ?></div>
<?php endif; ?>

<form method="POST" action="/admin/login">
    <?= $this->csrfField() ?>

    <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus
               autocomplete="username">
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required
               autocomplete="current-password">
    </div>

    <button type="submit" class="btn-primary">Sign In</button>
</form>
