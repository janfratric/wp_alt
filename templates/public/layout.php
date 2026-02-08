<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e(($meta['title'] ?? $title ?? '') . ' — ' . ($siteName ?? 'LiteCMS')) ?></title>
<?php if (!empty($meta)): ?>
<?= $this->metaTags($meta) ?>
<?php endif; ?>
    <meta property="og:site_name" content="<?= $this->e($siteName ?? 'LiteCMS') ?>">
<?= $this->yieldSection('head') ?>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a href="/" class="site-logo"><?= $this->e($siteName ?? 'LiteCMS') ?></a>
            <nav class="site-nav" aria-label="Main navigation">
                <ul class="nav-list">
                    <li<?= (($currentSlug ?? '') === '' && ($title ?? '') !== 'Blog' && ($title ?? '') !== 'Page Not Found') ? ' class="active"' : '' ?>><a href="/">Home</a></li>
<?php if (!empty($navPages)): ?>
<?php foreach ($navPages as $navPage): ?>
                    <li<?= (($currentSlug ?? '') === $navPage['slug']) ? ' class="active"' : '' ?>><a href="/<?= $this->e($navPage['slug']) ?>"><?= $this->e($navPage['title']) ?></a></li>
<?php endforeach; ?>
<?php endif; ?>
                    <li<?= (($title ?? '') === 'Blog') ? ' class="active"' : '' ?>><a href="/blog">Blog</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="site-main">
        <div class="container">
<?= $this->content() ?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= $this->e($siteName ?? 'LiteCMS') ?>. All rights reserved.</p>
        </div>
    </footer>
<?= $this->yieldSection('scripts') ?>
</body>
</html>
