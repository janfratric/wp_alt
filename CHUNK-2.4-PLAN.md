# Chunk 2.4 — User Management
## Detailed Implementation Plan

---

## Overview

This chunk builds the user management interface (admin-only). Admins can list all users, create new users with role assignment, edit user details, change passwords, and delete users with content reassignment. Editors are denied access to the entire user management section via `RoleMiddleware`.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it (plus already-existing code from chunks 1.1–2.3).

---

### 1. `app/Admin/UserController.php` (NEW)

**Purpose**: Full CRUD controller for user management. Admin-only access enforced in every method.

**Class**: `App\Admin\UserController`

**Dependencies**: `App\Core\App`, `App\Core\Config`, `App\Core\Request`, `App\Core\Response`, `App\Database\QueryBuilder`, `App\Auth\Session`, `App\Auth\RoleMiddleware`

**Design**:
- Mirrors the `ContentController` pattern (constructor receives `App`, private helpers for validation and security headers).
- Every public method starts with a `RoleMiddleware::check('admin')` call. If the user is not an admin, return the 403 response immediately.
- Password fields are optional on edit (only update when provided).
- Self-deletion is prevented. Self-role-change is prevented (admin cannot demote themselves).
- Deleting a user with content requires reassigning that content to another user.

**Public API**:
```php
__construct(App $app)

index(Request $request): Response             // GET /admin/users — list with search/pagination
create(Request $request): Response            // GET /admin/users/create — new user form
store(Request $request): Response             // POST /admin/users — validate + insert
edit(Request $request, string $id): Response  // GET /admin/users/{id}/edit — edit form
update(Request $request, string $id): Response // PUT /admin/users/{id} — validate + update
delete(Request $request, string $id): Response // DELETE /admin/users/{id} — delete with reassignment
```

**Private helpers**:
```php
readFormData(Request $request): array         // Extract form fields
validate(array $data, bool $isNew, ?int $excludeId = null): ?string  // Returns error string or null
withSecurityHeaders(Response $response): Response  // Add X-Frame-Options + CSP
```

**Implementation details**:

#### `index(Request $request): Response`
1. Call `RoleMiddleware::check('admin')` — return 403 if denied.
2. Read query params: `q` (search), `page` (pagination).
3. Build count query on `users` table, applying `whereRaw` for search (matches `username` OR `email`).
4. Calculate pagination: `$perPage` from `Config::getInt('items_per_page', 10)`, `$totalPages`, `$offset`.
5. Build data query on `users`, selecting `id, username, email, role, created_at`. Order by `created_at DESC`. Apply same search filter. Limit/offset.
6. Render `admin/users/index` template with: `title`, `activeNav`, `users`, `search`, `page`, `totalPages`, `total`.
7. Return response with security headers.

#### `create(Request $request): Response`
1. Role check.
2. Render `admin/users/edit` with empty user array and `isNew = true`.
3. Return response with security headers.

Empty user array:
```php
$user = [
    'id'       => null,
    'username' => '',
    'email'    => '',
    'role'     => 'editor',
];
```

#### `store(Request $request): Response`
1. Role check.
2. `readFormData($request)` to extract fields.
3. `validate($data, true)` — if error, flash and redirect back to `/admin/users/create`.
4. Hash password: `password_hash($data['password'], PASSWORD_BCRYPT)`.
5. Insert into `users` table: `username`, `email`, `password_hash`, `role`.
6. Flash success, redirect to `/admin/users`.

#### `edit(Request $request, string $id): Response`
1. Role check.
2. Fetch user by `id` from `users` table. If not found, flash error and redirect to `/admin/users`.
3. Render `admin/users/edit` with user data and `isNew = false`.
4. Also pass `isSelf = ($user['id'] === Session::get('user_id'))` for template logic.
5. Return response with security headers.

#### `update(Request $request, string $id): Response`
1. Role check.
2. Fetch existing user by `id`. If not found, flash error and redirect.
3. `readFormData($request)` to extract fields.
4. `validate($data, false, (int)$id)` — if error, flash and redirect back to edit.
5. Build update array: `username`, `email`, `role`, `updated_at`.
6. **Self-role-change prevention**: If `$id == Session::get('user_id')` and `$data['role'] !== $existing['role']`, flash error and redirect back.
7. **Password update**: If `$data['password'] !== ''`:
   - If editing own account (`$id == Session::get('user_id')`), require `current_password` field. Verify with `password_verify()`. If wrong, flash error and redirect.
   - If editing another user (admin resetting), no current password needed.
   - Add `password_hash` to update array.
8. Execute update query.
9. **Session sync**: If editing own account and username changed, update `Session::set('user_name', $data['username'])`.
10. Flash success, redirect to `/admin/users/{id}/edit`.

#### `delete(Request $request, string $id): Response`
1. Role check.
2. **Self-deletion prevention**: If `$id == Session::get('user_id')`, flash error "You cannot delete your own account." and redirect.
3. Fetch user by `id`. If not found, flash error and redirect.
4. **Content reassignment**: Read `reassign_to` from request input. If the user has content (`SELECT COUNT(*) FROM content WHERE author_id = :id`):
   - If `reassign_to` is empty or invalid, flash error "Please select a user to reassign content to." and redirect.
   - Verify the reassign target user exists and is not the user being deleted.
   - Update all `content` rows: `SET author_id = :reassign_to WHERE author_id = :id`.
5. Delete the user from `users` table.
6. Flash success, redirect to `/admin/users`.

#### `readFormData(Request $request): array`
```php
return [
    'username'         => trim((string) $request->input('username', '')),
    'email'            => trim((string) $request->input('email', '')),
    'password'         => (string) $request->input('password', ''),
    'current_password' => (string) $request->input('current_password', ''),
    'role'             => (string) $request->input('role', 'editor'),
];
```

#### `validate(array $data, bool $isNew, ?int $excludeId = null): ?string`
1. Username required, 1-50 chars, alphanumeric + underscores only (`/^[a-zA-Z0-9_]+$/`).
2. Email required, valid format (`filter_var(..., FILTER_VALIDATE_EMAIL)`), max 255 chars.
3. Role must be `admin` or `editor`.
4. If `$isNew`: password required, min 6 chars.
5. If not `$isNew` and password provided: min 6 chars.
6. Username uniqueness: query `users` table for matching username, excluding `$excludeId` if set. If found, "Username is already taken."
7. Email uniqueness: query `users` table for matching email, excluding `$excludeId` if set. If found, "Email is already in use."

#### `withSecurityHeaders(Response $response): Response`
Same pattern as `ContentController`:
```php
return $response
    ->withHeader('X-Frame-Options', 'DENY')
    ->withHeader('Content-Security-Policy',
        "default-src 'self'; "
        . "script-src 'self' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; "
        . "font-src 'self' https://cdn.jsdelivr.net"
    );
```

---

### 2. `templates/admin/users/index.php` (NEW)

**Purpose**: User list page with search, pagination, and delete functionality.

**Template variables**: `$title`, `$activeNav`, `$users`, `$search`, `$page`, `$totalPages`, `$total`

**Template**:
```php
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
    const modal = document.getElementById('delete-user-modal');
    const form = document.getElementById('delete-user-form');
    const nameSpan = document.getElementById('delete-user-name');
    const reassignSelect = document.getElementById('reassign-to');

    document.querySelectorAll('.delete-user-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const userId = this.dataset.id;
            const username = this.dataset.username;

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
            for (let opt of reassignSelect.options) {
                if (opt.style.display !== 'none') {
                    opt.selected = true;
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
```

**Notes**:
- Follows the same card/table/pagination pattern as `admin/content/index.php`.
- The delete modal is inline with JavaScript (no external JS file needed — keeps it simple).
- The delete button is hidden for the current user's own row.
- Reassignment select excludes the user being deleted.

---

### 3. `templates/admin/users/edit.php` (NEW)

**Purpose**: Create/edit user form with password handling.

**Template variables**: `$title`, `$activeNav`, `$user`, `$isNew`, `$isSelf` (bool, true when editing own account)

**Template**:
```php
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
```

**Notes**:
- Two cards: "Account Details" (username, email, role) and "Password" section.
- Role selector is disabled (hidden field preserves value) when editing own account.
- Current password field only shown when editing own account.
- Password field is required for new users, optional for editing.
- Uses same `card`, `form-group`, `btn` CSS classes already defined in `admin.css`.

---

### 4. `public/index.php` (MODIFY — route changes only)

**Purpose**: Replace the placeholder `/admin/users` route with real `UserController` routes.

**Changes**:
1. Add `use App\Admin\UserController;` import at the top.
2. Remove the placeholder closure route for `/admin/users`.
3. Add user CRUD routes inside the `/admin` group:

```php
// User management routes
$router->get('/users', [UserController::class, 'index']);
$router->get('/users/create', [UserController::class, 'create']);
$router->post('/users', [UserController::class, 'store']);
$router->get('/users/{id}/edit', [UserController::class, 'edit']);
$router->put('/users/{id}', [UserController::class, 'update']);
$router->delete('/users/{id}', [UserController::class, 'delete']);
```

**Note**: The routes live inside the existing `$router->group('/admin', ...)` block, so full paths are `/admin/users`, `/admin/users/create`, etc. The placeholder closure for `/users` is replaced — not appended.

---

## Detailed Class Specification

### `App\Admin\UserController`

```
PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app)
      $this->app = $app

METHODS:

  - public index(Request $request): Response
      1. $denied = RoleMiddleware::check('admin')
         if ($denied !== null) return $denied
      2. $search = trim((string) $request->query('q', ''))
         $page = max(1, (int) $request->query('page', '1'))
         $perPage = Config::getInt('items_per_page', 10)
      3. Count query:
         $countQb = QueryBuilder::query('users')->select()
         if ($search !== ''):
             $countQb->whereRaw(
                 '(username LIKE :search OR email LIKE :search)',
                 [':search' => "%{$search}%"]
             )
         $total = $countQb->count()
      4. $totalPages = max(1, (int) ceil($total / $perPage))
         $page = min($page, $totalPages)
         $offset = ($page - 1) * $perPage
      5. Data query:
         $qb = QueryBuilder::query('users')
             ->select('id', 'username', 'email', 'role', 'created_at')
         if ($search !== ''):
             $qb->whereRaw(
                 '(username LIKE :search OR email LIKE :search)',
                 [':search' => "%{$search}%"]
             )
         $users = $qb->orderBy('created_at', 'DESC')
             ->limit($perPage)->offset($offset)->get()
      6. Render 'admin/users/index' with:
         title='Users', activeNav='users', users, search, page, totalPages, total
      7. Return with security headers

  - public create(Request $request): Response
      1. Role check
      2. $user = ['id'=>null, 'username'=>'', 'email'=>'', 'role'=>'editor']
      3. Render 'admin/users/edit' with:
         title='Create User', activeNav='users', user, isNew=true, isSelf=false
      4. Return with security headers

  - public store(Request $request): Response
      1. Role check
      2. $data = $this->readFormData($request)
      3. $error = $this->validate($data, true)
         if error: Session::flash('error', $error)
                   return Response::redirect('/admin/users/create')
      4. QueryBuilder::query('users')->insert([
             'username'      => $data['username'],
             'email'         => $data['email'],
             'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
             'role'          => $data['role'],
         ])
      5. Session::flash('success', 'User created successfully.')
         return Response::redirect('/admin/users')

  - public edit(Request $request, string $id): Response
      1. Role check
      2. $user = QueryBuilder::query('users')->select()->where('id', (int)$id)->first()
         if null: Session::flash('error', 'User not found.')
                  return Response::redirect('/admin/users')
      3. $isSelf = ((int)$user['id'] === (int)Session::get('user_id'))
      4. Render 'admin/users/edit' with:
         title='Edit: '.$user['username'], activeNav='users',
         user, isNew=false, isSelf
      5. Return with security headers

  - public update(Request $request, string $id): Response
      1. Role check
      2. $existing = QueryBuilder::query('users')
             ->select()->where('id', (int)$id)->first()
         if null: flash error, redirect to /admin/users
      3. $data = $this->readFormData($request)
      4. $error = $this->validate($data, false, (int)$id)
         if error: flash error, redirect to /admin/users/{id}/edit
      5. $isSelf = ((int)$id === (int)Session::get('user_id'))
      6. // Prevent self-role-change
         if $isSelf && $data['role'] !== $existing['role']:
             flash error 'You cannot change your own role.'
             redirect to /admin/users/{id}/edit
      7. // Build update data
         $updateData = [
             'username'   => $data['username'],
             'email'      => $data['email'],
             'role'       => $data['role'],
             'updated_at' => date('Y-m-d H:i:s'),
         ]
      8. // Password change
         if $data['password'] !== '':
             if $isSelf:
                 if $data['current_password'] === '' ||
                    !password_verify($data['current_password'], $existing['password_hash']):
                     flash error 'Current password is incorrect.'
                     redirect to /admin/users/{id}/edit
             $updateData['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT)
      9. QueryBuilder::query('users')->where('id', (int)$id)->update($updateData)
     10. // Sync session if editing own account
         if $isSelf:
             Session::set('user_name', $data['username'])
     11. flash success 'User updated successfully.'
         redirect to /admin/users/{id}/edit

  - public delete(Request $request, string $id): Response
      1. Role check
      2. // Self-deletion prevention
         if (int)$id === (int)Session::get('user_id'):
             flash error 'You cannot delete your own account.'
             redirect to /admin/users
      3. $user = QueryBuilder::query('users')
             ->select()->where('id', (int)$id)->first()
         if null: flash error 'User not found.', redirect
      4. // Check for content authored by this user
         $contentCount = QueryBuilder::query('content')
             ->select()->where('author_id', (int)$id)->count()
      5. if $contentCount > 0:
             $reassignTo = (int) $request->input('reassign_to', '0')
             if $reassignTo <= 0 || $reassignTo === (int)$id:
                 flash error 'Please select a valid user to reassign content to.'
                 redirect to /admin/users
             // Verify target user exists
             $target = QueryBuilder::query('users')
                 ->select()->where('id', $reassignTo)->first()
             if $target === null:
                 flash error 'Reassignment target user not found.'
                 redirect to /admin/users
             // Reassign content
             QueryBuilder::query('content')
                 ->where('author_id', (int)$id)
                 ->update(['author_id' => $reassignTo, 'updated_at' => date('Y-m-d H:i:s')])
      6. QueryBuilder::query('users')->where('id', (int)$id)->delete()
      7. flash success 'User deleted.'
         redirect to /admin/users

  - private readFormData(Request $request): array
      return [
          'username'         => trim((string) $request->input('username', '')),
          'email'            => trim((string) $request->input('email', '')),
          'password'         => (string) $request->input('password', ''),
          'current_password' => (string) $request->input('current_password', ''),
          'role'             => (string) $request->input('role', 'editor'),
      ]

  - private validate(array $data, bool $isNew, ?int $excludeId = null): ?string
      1. if $data['username'] === '': return 'Username is required.'
      2. if !preg_match('/^[a-zA-Z0-9_]+$/', $data['username']):
             return 'Username may only contain letters, numbers, and underscores.'
      3. if mb_strlen($data['username']) > 50:
             return 'Username must be 50 characters or less.'
      4. if $data['email'] === '': return 'Email is required.'
      5. if filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false:
             return 'Please enter a valid email address.'
      6. if mb_strlen($data['email']) > 255:
             return 'Email must be 255 characters or less.'
      7. if !in_array($data['role'], ['admin', 'editor'], true):
             return 'Invalid role.'
      8. if $isNew && $data['password'] === '':
             return 'Password is required.'
      9. if $data['password'] !== '' && mb_strlen($data['password']) < 6:
             return 'Password must be at least 6 characters.'
     10. // Username uniqueness
         $qb = QueryBuilder::query('users')->select()->where('username', $data['username'])
         if $excludeId !== null: $qb->where('id', '!=', $excludeId)
         if $qb->first() !== null: return 'Username is already taken.'
     11. // Email uniqueness
         $qb = QueryBuilder::query('users')->select()->where('email', $data['email'])
         if $excludeId !== null: $qb->where('id', '!=', $excludeId)
         if $qb->first() !== null: return 'Email is already in use.'
     12. return null

  - private withSecurityHeaders(Response $response): Response
      return $response
          ->withHeader('X-Frame-Options', 'DENY')
          ->withHeader('Content-Security-Policy',
              "default-src 'self'; "
              . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
              . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
              . "img-src 'self' data: blob:; "
              . "connect-src 'self'; "
              . "font-src 'self' https://cdn.jsdelivr.net"
          )
```

**Note on CSP**: The users/index template includes an inline `<script>` for the delete modal. The CSP must include `'unsafe-inline'` for `script-src`. This is already acceptable given the admin context, and later chunks can extract to a separate JS file if desired.

---

## Full Code Templates

### `app/Admin/UserController.php`

```php
<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;
use App\Auth\RoleMiddleware;

class UserController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/users — List users with search and pagination.
     */
    public function index(Request $request): Response
    {
        $denied = RoleMiddleware::check('admin');
        if ($denied !== null) {
            return $denied;
        }

        $search  = trim((string) $request->query('q', ''));
        $page    = max(1, (int) $request->query('page', '1'));
        $perPage = Config::getInt('items_per_page', 10);

        // Count query
        $countQb = QueryBuilder::query('users')->select();
        if ($search !== '') {
            $countQb->whereRaw(
                '(username LIKE :search OR email LIKE :search)',
                [':search' => "%{$search}%"]
            );
        }
        $total = $countQb->count();

        // Pagination
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // Data query
        $qb = QueryBuilder::query('users')
            ->select('id', 'username', 'email', 'role', 'created_at');
        if ($search !== '') {
            $qb->whereRaw(
                '(username LIKE :search OR email LIKE :search)',
                [':search' => "%{$search}%"]
            );
        }
        $users = $qb->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $html = $this->app->template()->render('admin/users/index', [
            'title'      => 'Users',
            'activeNav'  => 'users',
            'users'      => $users,
            'search'     => $search,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * GET /admin/users/create — Show new user form.
     */
    public function create(Request $request): Response
    {
        $denied = RoleMiddleware::check('admin');
        if ($denied !== null) {
            return $denied;
        }

        $user = [
            'id'       => null,
            'username' => '',
            'email'    => '',
            'role'     => 'editor',
        ];

        $html = $this->app->template()->render('admin/users/edit', [
            'title'     => 'Create User',
            'activeNav' => 'users',
            'user'      => $user,
            'isNew'     => true,
            'isSelf'    => false,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * POST /admin/users — Validate and store new user.
     */
    public function store(Request $request): Response
    {
        $denied = RoleMiddleware::check('admin');
        if ($denied !== null) {
            return $denied;
        }

        $data = $this->readFormData($request);
        $error = $this->validate($data, true);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/users/create');
        }

        QueryBuilder::query('users')->insert([
            'username'      => $data['username'],
            'email'         => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'role'          => $data['role'],
        ]);

        Session::flash('success', 'User created successfully.');
        return Response::redirect('/admin/users');
    }

    /**
     * GET /admin/users/{id}/edit — Show edit form.
     */
    public function edit(Request $request, string $id): Response
    {
        $denied = RoleMiddleware::check('admin');
        if ($denied !== null) {
            return $denied;
        }

        $user = QueryBuilder::query('users')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($user === null) {
            Session::flash('error', 'User not found.');
            return Response::redirect('/admin/users');
        }

        $isSelf = ((int) $user['id'] === (int) Session::get('user_id'));

        $html = $this->app->template()->render('admin/users/edit', [
            'title'     => 'Edit: ' . $user['username'],
            'activeNav' => 'users',
            'user'      => $user,
            'isNew'     => false,
            'isSelf'    => $isSelf,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * PUT /admin/users/{id} — Validate and update user.
     */
    public function update(Request $request, string $id): Response
    {
        $denied = RoleMiddleware::check('admin');
        if ($denied !== null) {
            return $denied;
        }

        $existing = QueryBuilder::query('users')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($existing === null) {
            Session::flash('error', 'User not found.');
            return Response::redirect('/admin/users');
        }

        $data = $this->readFormData($request);
        $error = $this->validate($data, false, (int) $id);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/users/' . $id . '/edit');
        }

        $isSelf = ((int) $id === (int) Session::get('user_id'));

        // Prevent self-role-change
        if ($isSelf && $data['role'] !== $existing['role']) {
            Session::flash('error', 'You cannot change your own role.');
            return Response::redirect('/admin/users/' . $id . '/edit');
        }

        $updateData = [
            'username'   => $data['username'],
            'email'      => $data['email'],
            'role'       => $data['role'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Password change
        if ($data['password'] !== '') {
            if ($isSelf) {
                if ($data['current_password'] === '' ||
                    !password_verify($data['current_password'], $existing['password_hash'])) {
                    Session::flash('error', 'Current password is incorrect.');
                    return Response::redirect('/admin/users/' . $id . '/edit');
                }
            }
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        QueryBuilder::query('users')->where('id', (int) $id)->update($updateData);

        // Sync session if editing own account
        if ($isSelf) {
            Session::set('user_name', $data['username']);
        }

        Session::flash('success', 'User updated successfully.');
        return Response::redirect('/admin/users/' . $id . '/edit');
    }

    /**
     * DELETE /admin/users/{id} — Delete user with content reassignment.
     */
    public function delete(Request $request, string $id): Response
    {
        $denied = RoleMiddleware::check('admin');
        if ($denied !== null) {
            return $denied;
        }

        // Self-deletion prevention
        if ((int) $id === (int) Session::get('user_id')) {
            Session::flash('error', 'You cannot delete your own account.');
            return Response::redirect('/admin/users');
        }

        $user = QueryBuilder::query('users')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($user === null) {
            Session::flash('error', 'User not found.');
            return Response::redirect('/admin/users');
        }

        // Check for content authored by this user
        $contentCount = QueryBuilder::query('content')
            ->select()
            ->where('author_id', (int) $id)
            ->count();

        if ($contentCount > 0) {
            $reassignTo = (int) $request->input('reassign_to', '0');

            if ($reassignTo <= 0 || $reassignTo === (int) $id) {
                Session::flash('error', 'Please select a valid user to reassign content to.');
                return Response::redirect('/admin/users');
            }

            // Verify target user exists
            $target = QueryBuilder::query('users')
                ->select()
                ->where('id', $reassignTo)
                ->first();

            if ($target === null) {
                Session::flash('error', 'Reassignment target user not found.');
                return Response::redirect('/admin/users');
            }

            // Reassign content
            QueryBuilder::query('content')
                ->where('author_id', (int) $id)
                ->update([
                    'author_id' => $reassignTo,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }

        QueryBuilder::query('users')->where('id', (int) $id)->delete();

        Session::flash('success', 'User deleted.');
        return Response::redirect('/admin/users');
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function readFormData(Request $request): array
    {
        return [
            'username'         => trim((string) $request->input('username', '')),
            'email'            => trim((string) $request->input('email', '')),
            'password'         => (string) $request->input('password', ''),
            'current_password' => (string) $request->input('current_password', ''),
            'role'             => (string) $request->input('role', 'editor'),
        ];
    }

    private function validate(array $data, bool $isNew, ?int $excludeId = null): ?string
    {
        if ($data['username'] === '') {
            return 'Username is required.';
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
            return 'Username may only contain letters, numbers, and underscores.';
        }
        if (mb_strlen($data['username']) > 50) {
            return 'Username must be 50 characters or less.';
        }
        if ($data['email'] === '') {
            return 'Email is required.';
        }
        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'Please enter a valid email address.';
        }
        if (mb_strlen($data['email']) > 255) {
            return 'Email must be 255 characters or less.';
        }
        if (!in_array($data['role'], ['admin', 'editor'], true)) {
            return 'Invalid role.';
        }
        if ($isNew && $data['password'] === '') {
            return 'Password is required.';
        }
        if ($data['password'] !== '' && mb_strlen($data['password']) < 6) {
            return 'Password must be at least 6 characters.';
        }

        // Username uniqueness
        $qb = QueryBuilder::query('users')->select()->where('username', $data['username']);
        if ($excludeId !== null) {
            $qb->where('id', '!=', $excludeId);
        }
        if ($qb->first() !== null) {
            return 'Username is already taken.';
        }

        // Email uniqueness
        $qb = QueryBuilder::query('users')->select()->where('email', $data['email']);
        if ($excludeId !== null) {
            $qb->where('id', '!=', $excludeId);
        }
        if ($qb->first() !== null) {
            return 'Email is already in use.';
        }

        return null;
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self' https://cdn.jsdelivr.net"
            );
    }
}
```

---

### `public/index.php` — Changes Only

Add import at top (after `use App\Admin\MediaController;`):
```php
use App\Admin\UserController;
```

Replace the placeholder `/users` route inside the `/admin` group:

**Remove:**
```php
    // Placeholder routes for sidebar links (to be replaced in future chunks)
    $router->get('/users', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/placeholder', [
                'title' => 'Users',
                'activeNav' => 'users',
                'message' => 'User management is coming in Chunk 2.4.',
            ])
        );
    });
```

**Replace with:**
```php
    // User management routes
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/users/create', [UserController::class, 'create']);
    $router->post('/users', [UserController::class, 'store']);
    $router->get('/users/{id}/edit', [UserController::class, 'edit']);
    $router->put('/users/{id}', [UserController::class, 'update']);
    $router->delete('/users/{id}', [UserController::class, 'delete']);
```

---

## Acceptance Test Procedures

### Test 1: Admin can create a new editor user — user appears in list, can log in
```
1. Log in as admin (admin/admin).
2. Navigate to /admin/users.
3. Click "+ New User".
4. Fill in: username=testuser, email=test@example.com, password=password123, role=Editor.
5. Submit the form.
6. Verify: redirected to /admin/users with success flash. "testuser" appears in the list with "Editor" badge.
7. Log out.
8. Log in as testuser/password123.
9. Verify: login succeeds, dashboard loads.
```

### Test 2: Admin can change a user's role from editor to admin
```
1. Log in as admin.
2. Navigate to /admin/users.
3. Click Edit on the editor user.
4. Change Role to "Admin".
5. Submit the form.
6. Verify: success message, role badge shows "Admin" on the users list.
7. Verify: the role value in the database is 'admin'.
```

### Test 3: User can change own password (requires entering current password)
```
1. Log in as admin.
2. Navigate to /admin/users/{own-id}/edit.
3. Enter new password without entering current password.
4. Submit — verify error message "Current password is incorrect."
5. Enter correct current password + new password.
6. Submit — verify success.
7. Log out, log back in with the new password — verify it works.
```

### Test 4: Attempting to delete own account shows error
```
1. Log in as admin.
2. Note that the "Delete" button is NOT shown on your own row in the users list.
3. Manually navigate to DELETE /admin/users/{own-id} (via curl or form manipulation).
4. Verify: error flash "You cannot delete your own account." Redirect to /admin/users.
```

### Test 5: Editor role user cannot access /admin/users — gets 403
```
1. Create a user with role=editor.
2. Log in as that editor user.
3. Navigate to /admin/users.
4. Verify: 403 Forbidden response ("You do not have permission to access this page.").
5. Verify: /admin/users/create also returns 403.
6. Verify: editor CAN access /admin/dashboard and /admin/content (those are not restricted).
```

### Test 6: Deleting a user prompts for content reassignment
```
1. Log in as admin.
2. Create a second user ("author2").
3. Log in as author2, create a content item (e.g., a page titled "Test Page").
4. Log back in as admin.
5. Navigate to /admin/users, click Delete on author2.
6. Verify: a modal/prompt asks which user to reassign content to.
7. Select admin as the reassignment target, confirm deletion.
8. Verify: author2 is deleted from users list.
9. Navigate to /admin/content — verify "Test Page" still exists with author=admin.
```

### Test 7: Username and email uniqueness enforced
```
1. Log in as admin.
2. Try to create a new user with username "admin" — verify error "Username is already taken."
3. Try to create a new user with email "admin@localhost" — verify error "Email is already in use."
4. Edit an existing user and try to change username to another existing user's username — verify error.
```

### Test 8: Admin cannot change own role
```
1. Log in as admin.
2. Navigate to /admin/users/{own-id}/edit.
3. Verify: the Role field is disabled/locked.
4. Attempt to submit with a role change (via form manipulation) — verify error "You cannot change your own role."
```

---

## Implementation Notes

### Coding Standards
- `<?php declare(strict_types=1);` at top of `UserController.php`.
- PSR-4: `App\Admin\UserController` → `app/Admin/UserController.php`.
- No framework imports — native PHP only.
- All template output escaped with `$this->e()`.
- All queries use parameterized values via `QueryBuilder`.

### Security Considerations
- **Role enforcement**: Every controller method starts with `RoleMiddleware::check('admin')`. This is defense-in-depth — even if someone bypasses the UI, the server rejects them.
- **Self-protection**: Admins cannot delete themselves or change their own role. This prevents lockout scenarios.
- **Password hashing**: Always `PASSWORD_BCRYPT`. Never stored or compared in plain text.
- **Current password required for self-edit**: Prevents session hijacking from leading to password change.
- **CSRF**: All POST/PUT/DELETE forms include `$this->csrfField()`. The global `CsrfMiddleware` validates tokens.
- **Input validation**: Server-side validation in `validate()` method. Client-side HTML5 attributes (`required`, `pattern`, `maxlength`) are convenience only — never trusted.

### No Database Changes
The `users` table already has all needed columns (`id`, `username`, `email`, `password_hash`, `role`, `created_at`, `updated_at`). No migration changes are required for this chunk.

### Session Data Updated on Self-Edit
When an admin edits their own username, `Session::set('user_name', ...)` updates the sidebar display without requiring re-login.

### Delete Modal Approach
The delete workflow uses an inline modal on the users index page rather than a separate confirmation page. This keeps the UX smooth while still requiring the admin to choose a reassignment target. The modal hides the user being deleted from the reassignment dropdown.

### Edge Case: Deleting User With No Content
If the user being deleted has zero content items, the reassignment step is skipped entirely — the user is deleted immediately. The modal still shows but the reassignment select is informational only (the controller handles the `$contentCount === 0` case).

### Edge Case: Only One Admin
The system does not prevent deleting the last admin (other than the self-deletion check). If there are two admins and one deletes the other, the system continues to function with one admin. The first-run bootstrap only creates the default admin when zero users exist.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/Admin/UserController.php` | Class | CREATE |
| 2 | `templates/admin/users/index.php` | Template | CREATE |
| 3 | `templates/admin/users/edit.php` | Template | CREATE |
| 4 | `public/index.php` | Entry point | MODIFY (routes) |

---

## Estimated Scope

- **New PHP classes**: 1 (`UserController`)
- **New templates**: 2 (`users/index.php`, `users/edit.php`)
- **Modified files**: 1 (`public/index.php` — route changes)
- **Approximate new PHP LOC**: ~200 lines (controller)
- **Approximate new template LOC**: ~200 lines (both templates)
- **Database changes**: None
