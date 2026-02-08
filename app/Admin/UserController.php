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
                    'author_id'  => $reassignTo,
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
        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false
            && !preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+$/', $data['email'])) {
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
