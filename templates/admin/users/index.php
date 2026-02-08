<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Users</h1>
    <a href="/admin/users/create" class="btn btn-primary">+ New User</a>
</div>

<!-- Search -->
<div class="card" style="margin-bottom: 1rem;">
    <div class="card-body">
        <form method="GET" action="/admin/users" class="filter-form">
            <div class="form-group search-group">
                <label for="q">Search</label>
                <input type="text" id="q" name="q"
                       value="<?= $this->e($search) ?>" placeholder="Search by username or email...">
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="/admin/users" class="btn btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- User Table -->
<div class="card">
    <div class="card-header">
        <span><?= (int)$total ?> user(s)</span>
    </div>

    <?php if (empty($users)): ?>
        <div class="card-body">
            <div class="empty-state">
                <p>No users found.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <a href="/admin/users/<?= (int)$user['id'] ?>/edit">
                                    <strong><?= $this->e($user['username']) ?></strong>
                                </a>
                            </td>
                            <td><?= $this->e($user['email']) ?></td>
                            <td>
                                <span class="badge badge-<?= $this->e($user['role']) ?>">
                                    <?= $this->e(ucfirst($user['role'])) ?>
                                </span>
                            </td>
                            <td class="text-muted"><?= $this->e($user['created_at'] ?? '') ?></td>
                            <td>
                                <a href="/admin/users/<?= (int)$user['id'] ?>/edit"
                                   class="btn btn-sm">Edit</a>
                                <?php if ((int)$user['id'] !== (\App\Auth\Session::get('user_id'))): ?>
                                    <button type="button" class="btn btn-sm btn-danger delete-user-btn"
                                            data-id="<?= (int)$user['id'] ?>"
                                            data-username="<?= $this->e($user['username']) ?>">
                                        Delete
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Delete modal (hidden by default) -->
<div id="delete-user-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h3>Delete User</h3>
        <p>Are you sure you want to delete user <strong id="delete-user-name"></strong>?</p>
        <div id="reassign-section">
            <p>Reassign their content to:</p>
            <form method="POST" id="delete-user-form">
                <?= $this->csrfField() ?>
                <input type="hidden" name="_method" value="DELETE">
                <select name="reassign_to" id="reassign-to">
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"
                                data-id="<?= (int)$u['id'] ?>">
                            <?= $this->e($u['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top: 1rem;">
                    <button type="submit" class="btn btn-danger">Delete User</button>
                    <button type="button" class="btn cancel-delete">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        $queryParams = [];
        if ($search !== '') $queryParams['q'] = $search;
        ?>

        <?php if ($page > 1): ?>
            <?php $queryParams['page'] = $page - 1; ?>
            <a href="/admin/users?<?= http_build_query($queryParams) ?>">« Prev</a>
        <?php endif; ?>

        <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <?php $queryParams['page'] = $page + 1; ?>
            <a href="/admin/users?<?= http_build_query($queryParams) ?>">Next »</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
// User delete confirmation with reassignment
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('delete-user-modal');
    var form = document.getElementById('delete-user-form');
    var nameSpan = document.getElementById('delete-user-name');
    var reassignSelect = document.getElementById('reassign-to');

    document.querySelectorAll('.delete-user-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var userId = this.dataset.id;
            var username = this.dataset.username;

            nameSpan.textContent = username;
            form.action = '/admin/users/' + userId;

            // Hide the user being deleted from reassignment options
            Array.from(reassignSelect.options).forEach(function(opt) {
                opt.style.display = (opt.dataset.id === userId) ? 'none' : '';
                if (opt.dataset.id === userId && opt.selected) {
                    opt.selected = false;
                }
            });
            // Select first visible option
            for (var i = 0; i < reassignSelect.options.length; i++) {
                if (reassignSelect.options[i].style.display !== 'none') {
                    reassignSelect.options[i].selected = true;
                    break;
                }
            }

            modal.style.display = 'flex';
        });
    });

    document.querySelectorAll('.cancel-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    });

    // Close on overlay click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>
