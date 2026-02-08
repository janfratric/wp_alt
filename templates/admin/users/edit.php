<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $isNew ? 'Create User' : 'Edit User' ?></h1>
    <a href="/admin/users" class="btn">« Back to Users</a>
</div>

<form method="POST"
      action="<?= $isNew ? '/admin/users' : '/admin/users/' . (int)$user['id'] ?>">
    <?= $this->csrfField() ?>
    <?php if (!$isNew): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="card">
        <div class="card-header">Account Details</div>
        <div class="card-body">
            <div class="form-group">
                <label for="username">Username <span class="required">*</span></label>
                <input type="text" id="username" name="username"
                       value="<?= $this->e($user['username']) ?>"
                       required maxlength="50" pattern="[a-zA-Z0-9_]+"
                       title="Letters, numbers, and underscores only">
            </div>

            <div class="form-group">
                <label for="email">Email <span class="required">*</span></label>
                <input type="email" id="email" name="email"
                       value="<?= $this->e($user['email']) ?>"
                       required maxlength="255">
            </div>

            <div class="form-group">
                <label for="role">Role <span class="required">*</span></label>
                <?php if (!$isNew && ($isSelf ?? false)): ?>
                    <!-- Admins cannot change their own role -->
                    <input type="hidden" name="role" value="<?= $this->e($user['role']) ?>">
                    <input type="text" id="role" value="<?= $this->e(ucfirst($user['role'])) ?>" disabled>
                    <small class="form-help">You cannot change your own role.</small>
                <?php else: ?>
                    <select id="role" name="role">
                        <option value="editor" <?= ($user['role'] ?? 'editor') === 'editor' ? 'selected' : '' ?>>
                            Editor
                        </option>
                        <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>
                            Admin
                        </option>
                    </select>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 1rem;">
        <div class="card-header">
            <?= $isNew ? 'Set Password' : 'Change Password' ?>
        </div>
        <div class="card-body">
            <?php if (!$isNew && ($isSelf ?? false)): ?>
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password">
                    <small class="form-help">Required when changing your own password.</small>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="password">
                    <?= $isNew ? 'Password' : 'New Password' ?>
                    <?php if ($isNew): ?><span class="required">*</span><?php endif; ?>
                </label>
                <input type="password" id="password" name="password"
                       minlength="6"
                       <?= $isNew ? 'required' : '' ?>>
                <?php if (!$isNew): ?>
                    <small class="form-help">Leave blank to keep current password.</small>
                <?php else: ?>
                    <small class="form-help">Minimum 6 characters.</small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="margin-top: 1rem;">
        <button type="submit" class="btn btn-primary">
            <?= $isNew ? 'Create User' : 'Save Changes' ?>
        </button>
        <a href="/admin/users" class="btn">Cancel</a>
    </div>
</form>
