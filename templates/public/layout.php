<!DOCTYPE html>
<html lang="en" data-theme-mode="<?= $this->e($activeTheme ?? 'light') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e(($meta['title'] ?? $title ?? '') . ' — ' . ($siteName ?? 'LiteCMS')) ?></title>
<?php if (!empty($meta)): ?>
<?= $this->metaTags($meta) ?>
<?php endif; ?>
    <meta property="og:site_name" content="<?= $this->e($siteName ?? 'LiteCMS') ?>">
    <link rel="stylesheet" href="/assets/css/style.css">
<?= $googleFontLinks ?? '' ?><?php if (!empty($styleOverrides)): ?>
    <style id="litecms-style-overrides"><?= $styleOverrides ?></style>
<?php endif; ?>
<?= $this->yieldSection('head') ?>
<?php if (!empty($elementCss)): ?>
    <style id="litecms-element-styles"><?= $elementCss ?></style>
<?php endif; ?>
</head>
<body data-theme-mode="<?= $this->e($activeTheme ?? 'light') ?>"<?php if (!empty($themeOverride)): ?> data-theme-override="<?= $this->e($themeOverride) ?>"<?php endif; ?> data-default-theme="<?= $this->e($defaultTheme ?? 'light') ?>"<?php if (!empty($gaId)): ?> data-ga-id="<?= $this->e($gaId) ?>"<?php endif; ?>>
<?php
    $lt = $layoutTemplate ?? [];
    $headerVisible = ((int)($lt['header_visible'] ?? 1)) === 1;
    $headerMode = $lt['header_mode'] ?? 'standard';
    $headerHeight = $lt['header_height'] ?? 'auto';
    $footerVisible = ((int)($lt['footer_visible'] ?? 1)) === 1;
    $footerMode = $lt['footer_mode'] ?? 'standard';
    $footerHeight = $lt['footer_height'] ?? 'auto';
?>
<?php if ($headerVisible): ?>
    <header class="site-header"<?= ($headerHeight !== 'auto') ? ' style="height:' . $this->e($headerHeight) . '"' : '' ?>>
<?php if ($headerMode === 'block' && !empty($headerBlockHtml)): ?>
        <?= $headerBlockHtml ?>
<?php else: ?>
        <div class="container">
            <a href="/" class="site-logo"><?= $this->e($siteName ?? 'LiteCMS') ?></a>
            <button type="button" class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 12h18M3 6h18M3 18h18"/>
                </svg>
            </button>
            <nav class="site-nav" aria-label="Main navigation">
                <ul class="nav-list">
                    <li<?= ((($currentSlug ?? '') === '' || ($currentSlug ?? '') === 'home') && ($title ?? '') !== 'Blog' && ($title ?? '') !== 'Page Not Found' && ($title ?? '') !== 'Contact') ? ' class="active"' : '' ?>><a href="/">Home</a></li>
<?php if (!empty($navPages)): ?>
<?php foreach ($navPages as $navPage): ?>
                    <li<?= (($currentSlug ?? '') === $navPage['slug']) ? ' class="active"' : '' ?>><a href="/<?= $this->e($navPage['slug']) ?>"><?= $this->e($navPage['title']) ?></a></li>
<?php endforeach; ?>
<?php endif; ?>
                    <li<?= (($title ?? '') === 'Blog') ? ' class="active"' : '' ?>><a href="/blog">Blog</a></li>
                    <li<?= (($title ?? '') === 'Contact') ? ' class="active"' : '' ?>><a href="/contact">Contact</a></li>
                </ul>
            </nav>
<?php if ($themeToggleEnabled ?? true): ?>
                    <button type="button" class="theme-toggle-btn" aria-label="Toggle theme">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                        </svg>
                    </button>
<?php endif; ?>
        </div>
<?php endif; ?>
    </header>
<?php endif; ?>

    <main class="site-main">
        <div class="container">
<?= $this->content() ?>
        </div>
    </main>

<?php if ($footerVisible): ?>
    <footer class="site-footer"<?= ($footerHeight !== 'auto') ? ' style="height:' . $this->e($footerHeight) . '"' : '' ?>>
<?php if ($footerMode === 'block' && !empty($footerBlockHtml)): ?>
        <?= $footerBlockHtml ?>
<?php else: ?>
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= $this->e($siteName ?? 'LiteCMS') ?>. All rights reserved.</p>
        </div>
<?php endif; ?>
    </footer>
<?php endif; ?>

<?php if ($consentEnabled ?? true): ?>
<?= $this->partial('public/partials/cookie-consent', [
    'consentText' => $consentText ?? '',
    'consentLink' => $consentLink ?? '',
]) ?>
    <script src="/assets/js/cookie-consent.js"></script>
<?php endif; ?>
    <script src="/assets/js/theme-toggle.js"></script>
    <script>
    document.querySelector('.nav-toggle').addEventListener('click', function() {
        var nav = document.querySelector('.site-nav');
        var expanded = this.getAttribute('aria-expanded') === 'true';
        nav.classList.toggle('open');
        this.setAttribute('aria-expanded', String(!expanded));
    });
    </script>
<?= $this->yieldSection('scripts') ?>
</body>
</html>
