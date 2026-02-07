<?php declare(strict_types=1);

namespace App\Auth;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;

class AuthController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/login — Show the login form.
     */
    public function showLogin(Request $request): Response
    {
        // Already logged in? Redirect to dashboard.
        if (Session::get('user_id')) {
            return Response::redirect('/admin/dashboard');
        }

        return new Response(
            $this->app->template()->render('auth/login', [
                'title' => 'Login',
                'error' => Session::flash('error'),
                'success' => Session::flash('success'),
            ])
        );
    }

    /**
     * POST /admin/login — Authenticate the user.
     */
    public function handleLogin(Request $request): Response
    {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        // Rate limiting: check if this IP is locked out
        $ip = (string) ($request->server('REMOTE_ADDR') ?? '127.0.0.1');

        if ($this->isRateLimited($ip)) {
            Session::flash('error', 'Too many failed login attempts. Please try again in 15 minutes.');
            return Response::redirect('/admin/login');
        }

        // Validate input
        if ($username === '' || $password === '') {
            Session::flash('error', 'Username and password are required.');
            return Response::redirect('/admin/login');
        }

        // Look up user by username
        $user = QueryBuilder::query('users')
            ->select('*')
            ->where('username', $username)
            ->first();

        // Verify password
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($ip);
            Session::flash('error', 'Invalid username or password.');
            return Response::redirect('/admin/login');
        }

        // Authentication successful
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('user_role', $user['role']);
        Session::set('user_name', $user['username']);

        $this->clearFailedAttempts($ip);

        return Response::redirect('/admin/dashboard');
    }

    /**
     * POST /admin/logout — Destroy session and redirect to login.
     */
    public function logout(Request $request): Response
    {
        Session::destroy();
        return Response::redirect('/admin/login');
    }

    // -----------------------------------------------------------------
    // Rate limiting — file-based per-IP tracking
    // -----------------------------------------------------------------

    private function getRateLimitPath(string $ip): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache/rate_limit';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/' . hash('sha256', $ip) . '.json';
    }

    private function isRateLimited(string $ip): bool
    {
        $file = $this->getRateLimitPath($ip);
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return false;
        }

        // Check if currently locked out
        if (isset($data['locked_until']) && $data['locked_until'] > time()) {
            return true;
        }

        // Lockout expired — clear the file
        if (isset($data['locked_until']) && $data['locked_until'] <= time()) {
            @unlink($file);
            return false;
        }

        return false;
    }

    private function recordFailedAttempt(string $ip): void
    {
        $file = $this->getRateLimitPath($ip);
        $data = ['attempts' => 0, 'first_attempt_at' => time()];

        if (file_exists($file)) {
            $existing = json_decode((string) file_get_contents($file), true);
            if (is_array($existing)) {
                $data = $existing;
            }
        }

        $data['attempts'] = ($data['attempts'] ?? 0) + 1;

        // Lock out after 5 failures
        if ($data['attempts'] >= 5) {
            $data['locked_until'] = time() + (15 * 60);
        }

        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    private function clearFailedAttempts(string $ip): void
    {
        $file = $this->getRateLimitPath($ip);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
