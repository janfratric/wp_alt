<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'Admin') ?> — LiteCMS Admin</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <link rel="stylesheet" href="/assets/css/ai-chat.css">
</head>
<?php
$imgSettings = json_encode([
    'resize_threshold' => (int) \App\Core\Config::getInt('image_resize_threshold', 1572864),
    'max_dimension'    => (int) \App\Core\Config::getInt('image_max_dimension', 2048),
    'jpeg_quality'     => (int) \App\Core\Config::getInt('image_jpeg_quality', 85),
]);
?>
<body data-image-settings="<?= $this->e($imgSettings) ?>">
    <div class="admin-wrapper">
        <!-- Sidebar Overlay (mobile) -->
        <div class="sidebar-overlay"></div>

        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <a href="/admin/dashboard">LiteCMS</a>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">Main</div>
                <a href="/admin/dashboard"
                   class="<?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9632;</span> Dashboard
                </a>

                <div class="nav-section">Content</div>
                <a href="/admin/content"
                   class="<?= ($activeNav ?? '') === 'content' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9998;</span> Content
                </a>
                <a href="/admin/media"
                   class="<?= ($activeNav ?? '') === 'media' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128247;</span> Media
                </a>
                <a href="/admin/content-types"
                   class="<?= ($activeNav ?? '') === 'content-types' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128209;</span> Content Types
                </a>
                <a href="/admin/elements"
                   class="<?= ($activeNav ?? '') === 'elements' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9647;</span> Elements
                </a>
                <a href="/admin/generator"
                   class="<?= ($activeNav ?? '') === 'generator' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9733;</span> Generate Page
                </a>

                <div class="nav-section">Design</div>
                <a href="/admin/style"
                   class="<?= ($activeNav ?? '') === 'style' ? 'active' : '' ?>">
                    <span class="nav-icon">&#127912;</span> Master Style
                </a>
                <a href="/admin/layouts"
                   class="<?= ($activeNav ?? '') === 'layouts' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9638;</span> Layouts
                </a>

                <div class="nav-section">System</div>
                <a href="/admin/users"
                   class="<?= ($activeNav ?? '') === 'users' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128101;</span> Users
                </a>
                <a href="/admin/settings"
                   class="<?= ($activeNav ?? '') === 'settings' ? 'active' : '' ?>">
                    <span class="nav-icon">&#9881;</span> Settings
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="user-info">
                    <div>
                        <div class="user-name"><?= $this->e($_SESSION['user_name'] ?? '') ?></div>
                        <div class="user-role"><?= $this->e(ucfirst($_SESSION['user_role'] ?? '')) ?></div>
                    </div>
                    <form method="POST" action="/admin/logout" style="margin:0;">
                        <?= $this->csrfField() ?>
                        <button type="submit" class="logout-btn">Logout</button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="admin-main">
            <!-- Top Bar -->
            <header class="topbar">
                <div class="topbar-left">
                    <button class="sidebar-toggle" aria-label="Toggle menu">&#9776;</button>
                    <span class="topbar-title"><?= $this->e($title ?? 'Admin') ?></span>
                </div>
                <div class="topbar-right">
                    <a href="/" target="_blank">View Site</a>
                </div>
            </header>

            <!-- Page Content -->
            <div class="admin-content">
                <?php
                $flashError = \App\Auth\Session::flash('error');
                $flashSuccess = \App\Auth\Session::flash('success');
                ?>
                <?php if ($flashError): ?>
                    <div class="alert alert-error">
                        <?= $this->e($flashError) ?>
                    </div>
                <?php endif; ?>
                <?php if ($flashSuccess): ?>
                    <div class="alert alert-success">
                        <?= $this->e($flashSuccess) ?>
                    </div>
                <?php endif; ?>

                <?= $this->content() ?>
            </div>
        </div>
    </div>

    <script src="/assets/js/admin.js"></script>
</body>
</html>
