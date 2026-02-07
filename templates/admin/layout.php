<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'Admin') ?> — LiteCMS Admin</title>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="sidebar">
            <h2>LiteCMS</h2>
            <nav>
                <a href="/admin/dashboard">Dashboard</a>
            </nav>
            <div class="sidebar-footer">
                <span><?= $this->e($_SESSION['user_name'] ?? '') ?></span>
                <form method="POST" action="/admin/logout" style="display:inline;">
                    <?= $this->csrfField() ?>
                    <button type="submit" style="background:none;border:none;color:inherit;cursor:pointer;text-decoration:underline;">
                        Logout
                    </button>
                </form>
            </div>
        </aside>
        <main class="admin-content">
            <?php
            $flashError = \App\Auth\Session::flash('error');
            $flashSuccess = \App\Auth\Session::flash('success');
            ?>
            <?php if ($flashError): ?>
                <div class="alert alert-error" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:0.75rem 1rem;border-radius:6px;margin-bottom:1rem;">
                    <?= $this->e($flashError) ?>
                </div>
            <?php endif; ?>
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:0.75rem 1rem;border-radius:6px;margin-bottom:1rem;">
                    <?= $this->e($flashSuccess) ?>
                </div>
            <?php endif; ?>

            <?= $this->content() ?>
        </main>
    </div>
</body>
</html>
