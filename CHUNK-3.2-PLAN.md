# Chunk 3.2 — Public Templates & Styling
## Detailed Implementation Plan

---

## Overview

This chunk makes the public-facing site fully presentable and legally compliant. It adds:

1. **Complete public CSS stylesheet** — mobile-first responsive design for all public templates
2. **Contact page** — form with CSRF protection, submissions stored in a new `contact_submissions` table
3. **Archive template** — generic listing for custom content types (used in Chunk 5.1)
4. **EU Cookie Consent banner** — GDPR/ePrivacy compliant, fixed-position, remembers user choice
5. **Conditional Google Analytics** — gtag.js loads only after explicit cookie consent acceptance

All existing public templates from Chunk 3.1 (`home.php`, `blog-index.php`, `blog-post.php`, `page.php`, `404.php`, `layout.php`) remain structurally intact — this chunk adds the CSS link, enhances the homepage with a hero section and CTA, and wires up the cookie consent and GA into the layout.

**No existing backend PHP classes are rewritten.** We add methods to `FrontController` and add new routes in `index.php`.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `migrations/002_contact_submissions.sqlite.sql`

**Purpose**: Create the `contact_submissions` table for storing contact form entries.

```sql
-- LiteCMS Migration 002 — Contact Submissions (SQLite)

CREATE TABLE IF NOT EXISTS contact_submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_contact_submissions_created ON contact_submissions(created_at);
```

---

### 2. `migrations/002_contact_submissions.pgsql.sql`

**Purpose**: PostgreSQL variant.

```sql
-- LiteCMS Migration 002 — Contact Submissions (PostgreSQL)

CREATE TABLE IF NOT EXISTS contact_submissions (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_contact_submissions_created ON contact_submissions(created_at);
```

---

### 3. `migrations/002_contact_submissions.mysql.sql`

**Purpose**: MariaDB/MySQL variant.

```sql
-- LiteCMS Migration 002 — Contact Submissions (MariaDB/MySQL)

CREATE TABLE IF NOT EXISTS contact_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_contact_submissions_created ON contact_submissions(created_at);
```

**Notes**:
- Migration 002 auto-runs on next page load via the existing Migrator system.
- The `ip_address` column is VARCHAR(45) to accommodate IPv6.
- No foreign keys — contact submissions are standalone.

---

### 4. `public/assets/css/style.css`

**Purpose**: Complete public site stylesheet. Mobile-first responsive design using CSS custom properties, consistent with admin.css design system.

**Design System** (shared palette with admin CSS):
- Primary color: `#2563eb` (blue)
- Font stack: `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`
- Border radius: `8px`
- Container max-width: `1100px`
- Mobile breakpoint: `768px`

**Section breakdown** (all CSS classes reference elements already in existing templates):

```css
/* ===================================================================
   LiteCMS — Public Stylesheet
   Mobile-first responsive design
   =================================================================== */

/* --- 1. CSS Custom Properties --- */
:root {
    --color-primary: #2563eb;
    --color-primary-hover: #1d4ed8;
    --color-text: #1e293b;
    --color-text-muted: #64748b;
    --color-bg: #ffffff;
    --color-bg-alt: #f8fafc;
    --color-border: #e2e8f0;
    --color-link: #2563eb;
    --color-link-hover: #1d4ed8;
    --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                   'Helvetica Neue', Arial, sans-serif;
    --font-size-base: 1rem;          /* 16px */
    --font-size-sm: 0.875rem;        /* 14px */
    --font-size-lg: 1.125rem;        /* 18px */
    --font-size-xl: 1.5rem;          /* 24px */
    --font-size-2xl: 2rem;           /* 32px */
    --font-size-3xl: 2.5rem;         /* 40px */
    --line-height: 1.7;
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
    --spacing-2xl: 3rem;
    --spacing-3xl: 4rem;
    --radius: 8px;
    --shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 4px 12px rgba(0, 0, 0, 0.1);
    --container-width: 1100px;
    --transition: 0.2s ease;
}

/* --- 2. Reset & Base --- */
*, *::before, *::after { box-sizing: border-box; }
body {
    margin: 0;
    font-family: var(--font-family);
    font-size: var(--font-size-base);
    line-height: var(--line-height);
    color: var(--color-text);
    background: var(--color-bg);
    -webkit-font-smoothing: antialiased;
}
a { color: var(--color-link); text-decoration: none; transition: color var(--transition); }
a:hover { color: var(--color-link-hover); text-decoration: underline; }
img { max-width: 100%; height: auto; display: block; }
h1, h2, h3, h4, h5, h6 { margin: 0 0 var(--spacing-md); line-height: 1.3; color: var(--color-text); }
h1 { font-size: var(--font-size-3xl); }
h2 { font-size: var(--font-size-2xl); }
h3 { font-size: var(--font-size-xl); }
p { margin: 0 0 var(--spacing-md); }

/* --- 3. Container --- */
.container {
    width: 100%;
    max-width: var(--container-width);
    margin: 0 auto;
    padding: 0 var(--spacing-md);
}

/* --- 4. Site Header & Navigation --- */
.site-header {
    background: var(--color-bg);
    border-bottom: 1px solid var(--color-border);
    padding: var(--spacing-md) 0;
    position: sticky;
    top: 0;
    z-index: 100;
}
.site-header .container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
}
.site-logo {
    font-size: var(--font-size-xl);
    font-weight: 700;
    color: var(--color-text);
    text-decoration: none;
}
.site-logo:hover { color: var(--color-primary); text-decoration: none; }

/* Hamburger toggle (mobile) */
.nav-toggle {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: var(--spacing-sm);
    color: var(--color-text);
}
.nav-toggle svg { width: 24px; height: 24px; }

.site-nav { display: flex; align-items: center; }
.nav-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    gap: var(--spacing-lg);
}
.nav-list li a {
    color: var(--color-text-muted);
    font-weight: 500;
    padding: var(--spacing-xs) 0;
    transition: color var(--transition);
}
.nav-list li a:hover,
.nav-list li.active a {
    color: var(--color-primary);
    text-decoration: none;
}
.nav-list li.active a { border-bottom: 2px solid var(--color-primary); }

/* Mobile nav */
@media (max-width: 768px) {
    .nav-toggle { display: block; }
    .site-nav {
        display: none;
        width: 100%;
        order: 3;
    }
    .site-nav.open { display: block; }
    .nav-list {
        flex-direction: column;
        gap: 0;
        padding: var(--spacing-md) 0;
    }
    .nav-list li a {
        display: block;
        padding: var(--spacing-sm) 0;
        border-bottom: 1px solid var(--color-border);
    }
    .nav-list li.active a { border-bottom-color: var(--color-primary); }
}

/* --- 5. Site Main --- */
.site-main {
    min-height: 60vh;
    padding: var(--spacing-2xl) 0;
}

/* --- 6. Site Footer --- */
.site-footer {
    background: var(--color-bg-alt);
    border-top: 1px solid var(--color-border);
    padding: var(--spacing-xl) 0;
    text-align: center;
    color: var(--color-text-muted);
    font-size: var(--font-size-sm);
}

/* --- 7. Homepage — Hero Section --- */
.hero {
    text-align: center;
    padding: var(--spacing-3xl) 0 var(--spacing-2xl);
}
.hero h1 {
    font-size: var(--font-size-3xl);
    margin-bottom: var(--spacing-md);
}
.hero p {
    font-size: var(--font-size-lg);
    color: var(--color-text-muted);
    max-width: 600px;
    margin: 0 auto var(--spacing-xl);
}
.hero .cta-button {
    display: inline-block;
    background: var(--color-primary);
    color: #fff;
    padding: var(--spacing-sm) var(--spacing-xl);
    border-radius: var(--radius);
    font-weight: 600;
    font-size: var(--font-size-base);
    transition: background var(--transition);
    text-decoration: none;
}
.hero .cta-button:hover {
    background: var(--color-primary-hover);
    color: #fff;
    text-decoration: none;
}

/* --- 8. Post Cards (blog index, homepage recent posts) --- */
.recent-posts,
.blog-index {
    padding: var(--spacing-xl) 0;
}
.recent-posts h2,
.blog-index h1 {
    margin-bottom: var(--spacing-xl);
}
.post-card {
    display: flex;
    gap: var(--spacing-lg);
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: var(--spacing-lg);
    transition: box-shadow var(--transition);
}
.post-card:hover { box-shadow: var(--shadow-lg); }
.post-card-image {
    flex-shrink: 0;
    width: 280px;
}
.post-card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.post-card-body {
    padding: var(--spacing-lg);
    display: flex;
    flex-direction: column;
    flex: 1;
}
.post-card-body h2,
.post-card-body h3 {
    margin-bottom: var(--spacing-sm);
}
.post-card-body h2 a,
.post-card-body h3 a {
    color: var(--color-text);
}
.post-card-body h2 a:hover,
.post-card-body h3 a:hover {
    color: var(--color-primary);
    text-decoration: none;
}
.post-meta {
    font-size: var(--font-size-sm);
    color: var(--color-text-muted);
    margin-bottom: var(--spacing-sm);
    display: flex;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}
.post-excerpt {
    color: var(--color-text-muted);
    flex: 1;
}
.read-more {
    font-weight: 600;
    color: var(--color-primary);
    margin-top: auto;
}
.read-more:hover { text-decoration: underline; }

/* Post cards on mobile: stack vertically */
@media (max-width: 768px) {
    .post-card { flex-direction: column; }
    .post-card-image { width: 100%; height: 200px; }
}

/* --- 9. Pagination --- */
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-lg);
    padding: var(--spacing-xl) 0;
}
.pagination-prev,
.pagination-next {
    display: inline-block;
    padding: var(--spacing-sm) var(--spacing-lg);
    background: var(--color-primary);
    color: #fff;
    border-radius: var(--radius);
    font-weight: 600;
    transition: background var(--transition);
    text-decoration: none;
}
.pagination-prev:hover,
.pagination-next:hover {
    background: var(--color-primary-hover);
    color: #fff;
    text-decoration: none;
}
.pagination-info {
    color: var(--color-text-muted);
    font-size: var(--font-size-sm);
}

/* --- 10. Single Blog Post --- */
.blog-post {
    max-width: 800px;
    margin: 0 auto;
}
.post-header {
    margin-bottom: var(--spacing-xl);
}
.post-header h1 {
    margin-bottom: var(--spacing-sm);
}
.post-featured-image {
    margin-bottom: var(--spacing-xl);
    border-radius: var(--radius);
    overflow: hidden;
}
.post-content {
    font-size: var(--font-size-lg);
    line-height: 1.8;
}
.post-content h2 { margin-top: var(--spacing-2xl); }
.post-content h3 { margin-top: var(--spacing-xl); }
.post-content p { margin-bottom: var(--spacing-lg); }
.post-content img {
    border-radius: var(--radius);
    margin: var(--spacing-lg) 0;
}
.post-content blockquote {
    border-left: 4px solid var(--color-primary);
    margin: var(--spacing-lg) 0;
    padding: var(--spacing-md) var(--spacing-lg);
    background: var(--color-bg-alt);
    border-radius: 0 var(--radius) var(--radius) 0;
    color: var(--color-text-muted);
    font-style: italic;
}
.post-content pre {
    background: var(--color-bg-alt);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: var(--spacing-lg);
    overflow-x: auto;
    font-size: var(--font-size-sm);
}
.post-content ul, .post-content ol {
    margin-bottom: var(--spacing-lg);
    padding-left: var(--spacing-xl);
}
.post-content li { margin-bottom: var(--spacing-xs); }

/* --- 11. Generic Page --- */
.page-content {
    max-width: 800px;
    margin: 0 auto;
}
.page-featured-image {
    margin-bottom: var(--spacing-xl);
    border-radius: var(--radius);
    overflow: hidden;
}
.page-body {
    font-size: var(--font-size-lg);
    line-height: 1.8;
}
.page-body h2 { margin-top: var(--spacing-2xl); }
.page-body h3 { margin-top: var(--spacing-xl); }
.page-body p { margin-bottom: var(--spacing-lg); }
.page-body img {
    border-radius: var(--radius);
    margin: var(--spacing-lg) 0;
}

/* --- 12. Contact Form --- */
.contact-page {
    max-width: 600px;
    margin: 0 auto;
}
.contact-page h1 {
    margin-bottom: var(--spacing-lg);
}
.contact-form {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-xs);
}
.form-group label {
    font-weight: 600;
    font-size: var(--font-size-sm);
    color: var(--color-text);
}
.form-group input,
.form-group textarea {
    padding: var(--spacing-sm) var(--spacing-md);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    font-family: var(--font-family);
    font-size: var(--font-size-base);
    color: var(--color-text);
    transition: border-color var(--transition);
    width: 100%;
}
.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}
.form-group textarea {
    min-height: 150px;
    resize: vertical;
}
.form-submit {
    display: inline-block;
    background: var(--color-primary);
    color: #fff;
    padding: var(--spacing-sm) var(--spacing-xl);
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: var(--font-size-base);
    cursor: pointer;
    transition: background var(--transition);
    align-self: flex-start;
}
.form-submit:hover { background: var(--color-primary-hover); }

/* Flash messages (success/error) */
.flash-success {
    background: #dcfce7;
    border: 1px solid #bbf7d0;
    color: #166534;
    padding: var(--spacing-md);
    border-radius: var(--radius);
    margin-bottom: var(--spacing-lg);
}
.flash-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    padding: var(--spacing-md);
    border-radius: var(--radius);
    margin-bottom: var(--spacing-lg);
}

/* --- 13. Archive Listing --- */
.archive-listing {
    padding: var(--spacing-xl) 0;
}
.archive-listing h1 {
    margin-bottom: var(--spacing-xl);
}
/* Archive reuses .post-card styles */

/* --- 14. Error Page (404) --- */
.error-page {
    text-align: center;
    padding: var(--spacing-3xl) 0;
}
.error-page h1 {
    font-size: var(--font-size-3xl);
    margin-bottom: var(--spacing-md);
}
.error-page p {
    color: var(--color-text-muted);
    margin-bottom: var(--spacing-md);
}
.error-page a {
    font-weight: 600;
    color: var(--color-primary);
}

/* --- 15. Breadcrumbs --- */
.breadcrumbs {
    list-style: none;
    padding: 0;
    margin: 0 0 var(--spacing-lg);
    display: flex;
    gap: var(--spacing-sm);
    font-size: var(--font-size-sm);
    color: var(--color-text-muted);
}
.breadcrumbs li + li::before {
    content: '/';
    margin-right: var(--spacing-sm);
    color: var(--color-border);
}
.breadcrumbs a { color: var(--color-text-muted); }
.breadcrumbs a:hover { color: var(--color-primary); }

/* --- 16. Cookie Consent Banner --- */
.cookie-consent {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--color-text);
    color: #fff;
    padding: var(--spacing-lg);
    z-index: 1000;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.15);
}
.cookie-consent .container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--spacing-lg);
    flex-wrap: wrap;
}
.cookie-consent-text {
    flex: 1;
    font-size: var(--font-size-sm);
    line-height: 1.5;
}
.cookie-consent-text a {
    color: #93c5fd;
    text-decoration: underline;
}
.cookie-consent-buttons {
    display: flex;
    gap: var(--spacing-sm);
    flex-shrink: 0;
}
.cookie-consent-accept,
.cookie-consent-decline {
    padding: var(--spacing-sm) var(--spacing-lg);
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: background var(--transition);
}
.cookie-consent-accept {
    background: var(--color-primary);
    color: #fff;
}
.cookie-consent-accept:hover { background: var(--color-primary-hover); }
.cookie-consent-decline {
    background: transparent;
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.cookie-consent-decline:hover { background: rgba(255, 255, 255, 0.1); }

@media (max-width: 768px) {
    .cookie-consent .container { flex-direction: column; text-align: center; }
    .cookie-consent-buttons { width: 100%; justify-content: center; }
}

/* --- 17. Utility Classes --- */
.text-muted { color: var(--color-text-muted); }
.text-center { text-align: center; }
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}
```

**Notes**:
- Mobile-first: base styles target mobile, `@media (min-width: 768px)` not needed because existing layouts work on desktop by default with flexbox.
- The `@media (max-width: 768px)` rules collapse the nav to a hamburger and stack post cards.
- CSS custom properties allow easy theme customization.
- All class names correspond to classes already present in templates from Chunk 3.1.
- Approximate size: ~350 lines, well under 15KB.

---

### 5. `public/assets/js/cookie-consent.js`

**Purpose**: Cookie consent banner logic — shows/hides the banner, stores the user's choice in a cookie, conditionally loads Google Analytics.

```javascript
/**
 * LiteCMS Cookie Consent
 *
 * - Checks for existing consent cookie on load.
 * - If no consent cookie: shows the banner.
 * - Accept: sets cookie, hides banner, loads GA (if configured).
 * - Decline: sets cookie, hides banner, does NOT load GA.
 * - Returning visitor with consent: loads GA silently (if accepted).
 */
(function () {
    'use strict';

    var COOKIE_NAME = 'litecms_consent';
    var COOKIE_DAYS = 365;

    function getCookie(name) {
        var match = document.cookie.match(
            new RegExp('(^|;\\s*)' + name + '=([^;]*)')
        );
        return match ? decodeURIComponent(match[2]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        document.cookie =
            name +
            '=' +
            encodeURIComponent(value) +
            ';expires=' +
            d.toUTCString() +
            ';path=/;SameSite=Lax';
    }

    function loadGA() {
        var gaId = document.body.getAttribute('data-ga-id');
        if (!gaId) return;

        // Prevent double-loading
        if (document.querySelector('script[src*="googletagmanager"]')) return;

        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + gaId;
        document.head.appendChild(script);

        window.dataLayer = window.dataLayer || [];
        function gtag() {
            window.dataLayer.push(arguments);
        }
        gtag('js', new Date());
        gtag('config', gaId);
    }

    function hideBanner() {
        var banner = document.getElementById('cookie-consent');
        if (banner) {
            banner.style.display = 'none';
        }
    }

    function showBanner() {
        var banner = document.getElementById('cookie-consent');
        if (banner) {
            banner.style.display = 'block';
        }
    }

    function init() {
        var consent = getCookie(COOKIE_NAME);

        if (consent === 'accepted') {
            hideBanner();
            loadGA();
            return;
        }

        if (consent === 'declined') {
            hideBanner();
            return;
        }

        // No consent yet — show the banner
        showBanner();

        var acceptBtn = document.getElementById('cookie-accept');
        var declineBtn = document.getElementById('cookie-decline');

        if (acceptBtn) {
            acceptBtn.addEventListener('click', function () {
                setCookie(COOKIE_NAME, 'accepted', COOKIE_DAYS);
                hideBanner();
                loadGA();
            });
        }

        if (declineBtn) {
            declineBtn.addEventListener('click', function () {
                setCookie(COOKIE_NAME, 'declined', COOKIE_DAYS);
                hideBanner();
            });
        }
    }

    // Run on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

**Notes**:
- Pure vanilla JS, no dependencies, IIFE-wrapped.
- GA Measurement ID is read from `data-ga-id` attribute on `<body>` (set by layout.php from settings).
- Prevents double-loading of the GA script.
- Cookie is `litecms_consent=accepted|declined`, 365-day expiry, SameSite=Lax.
- No tracking cookies are set until the user explicitly clicks Accept.

---

### 6. `templates/public/partials/cookie-consent.php`

**Purpose**: Cookie consent banner HTML partial, included at the bottom of the public layout.

```php
<?php declare(strict_types=1); ?>
<div id="cookie-consent" class="cookie-consent" style="display: none;" role="dialog" aria-label="Cookie consent">
    <div class="container">
        <div class="cookie-consent-text">
            <?= $this->e($consentText ?? 'This website uses cookies to enhance your experience. We also use analytics cookies to understand how visitors use our site.') ?>
<?php if (!empty($consentLink)): ?>
            <a href="<?= $this->e($consentLink) ?>">Learn more</a>
<?php endif; ?>
        </div>
        <div class="cookie-consent-buttons">
            <button type="button" id="cookie-accept" class="cookie-consent-accept">Accept</button>
            <button type="button" id="cookie-decline" class="cookie-consent-decline">Decline</button>
        </div>
    </div>
</div>
```

**Notes**:
- Banner starts hidden (`display: none`). The `cookie-consent.js` shows it if no consent cookie exists.
- `$consentText` and `$consentLink` are passed from the layout (read from settings table, with sensible defaults).
- Uses `role="dialog"` and `aria-label` for accessibility.

---

### 7. `templates/public/contact.php`

**Purpose**: Contact page with form. CSRF-protected. Displays success/error messages.

```php
<?php $this->layout('public/layout'); ?>

<div class="contact-page">
    <h1><?= $this->e($title) ?></h1>

<?php if (!empty($success)): ?>
    <div class="flash-success"><?= $this->e($success) ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="flash-error"><?= $this->e($error) ?></div>
<?php endif; ?>

    <form method="POST" action="/contact" class="contact-form" novalidate>
        <?= $this->csrfField() ?>

        <div class="form-group">
            <label for="contact-name">Name <span aria-hidden="true">*</span></label>
            <input type="text" id="contact-name" name="name" required maxlength="100"
                   value="<?= $this->e($old['name'] ?? '') ?>"
                   aria-required="true">
        </div>

        <div class="form-group">
            <label for="contact-email">Email <span aria-hidden="true">*</span></label>
            <input type="email" id="contact-email" name="email" required maxlength="255"
                   value="<?= $this->e($old['email'] ?? '') ?>"
                   aria-required="true">
        </div>

        <div class="form-group">
            <label for="contact-subject">Subject</label>
            <input type="text" id="contact-subject" name="subject" maxlength="255"
                   value="<?= $this->e($old['subject'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="contact-message">Message <span aria-hidden="true">*</span></label>
            <textarea id="contact-message" name="message" required maxlength="5000"
                      aria-required="true"><?= $this->e($old['message'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="form-submit">Send Message</button>
    </form>
</div>
```

**Notes**:
- Uses CSRF token via `$this->csrfField()` (already implemented in TemplateEngine).
- `$old` array preserves input values on validation failure (passed from the controller).
- `novalidate` on form allows server-side validation to take effect; browser validation still works as a first line.
- `aria-required="true"` for accessibility.
- Form POSTs to `/contact` route.

---

### 8. `templates/public/archive.php`

**Purpose**: Generic archive listing template for custom content types (e.g., `/products/`). Reuses post-card styling with pagination.

```php
<?php $this->layout('public/layout'); ?>

<div class="archive-listing">
    <h1><?= $this->e($archiveTitle ?? $title) ?></h1>

<?php if (!empty($items)): ?>
<?php foreach ($items as $item): ?>
    <article class="post-card">
<?php if (!empty($item['featured_image'])): ?>
        <div class="post-card-image">
            <a href="/<?= $this->e($archiveSlug ?? '') ?>/<?= $this->e($item['slug']) ?>">
                <img src="<?= $this->e($item['featured_image']) ?>" alt="<?= $this->e($item['title']) ?>">
            </a>
        </div>
<?php endif; ?>
        <div class="post-card-body">
            <h2><a href="/<?= $this->e($archiveSlug ?? '') ?>/<?= $this->e($item['slug']) ?>"><?= $this->e($item['title']) ?></a></h2>
            <div class="post-meta">
                <time datetime="<?= $this->e($item['published_at'] ?? $item['created_at']) ?>">
                    <?= date('M j, Y', strtotime($item['published_at'] ?? $item['created_at'])) ?>
                </time>
<?php if (!empty($item['author_name'])): ?>
                <span class="post-author">by <?= $this->e($item['author_name']) ?></span>
<?php endif; ?>
            </div>
<?php if (!empty($item['excerpt'])): ?>
            <p class="post-excerpt"><?= $this->e($item['excerpt']) ?></p>
<?php else: ?>
            <p class="post-excerpt"><?= $this->e(mb_substr(strip_tags($item['body'] ?? ''), 0, 160, 'UTF-8')) ?>...</p>
<?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>

<?php if (($totalPages ?? 1) > 1): ?>
    <nav class="pagination" aria-label="Archive pagination">
<?php if ($currentPage > 1): ?>
        <a href="/<?= $this->e($archiveSlug ?? '') ?>?page=<?= $currentPage - 1 ?>" class="pagination-prev">Previous</a>
<?php endif; ?>
        <span class="pagination-info">Page <?= $currentPage ?> of <?= $totalPages ?></span>
<?php if ($currentPage < $totalPages): ?>
        <a href="/<?= $this->e($archiveSlug ?? '') ?>?page=<?= $currentPage + 1 ?>" class="pagination-next">Next</a>
<?php endif; ?>
    </nav>
<?php endif; ?>

<?php else: ?>
    <p class="text-muted">No items found.</p>
<?php endif; ?>
</div>
```

**Notes**:
- This template is generic: `$archiveSlug` defines the URL prefix for items (e.g., `products`), `$archiveTitle` is the display name (e.g., "Products").
- Will be used by Chunk 5.1 (Custom Content Types) for archive pages of custom types.
- Reuses `.post-card` styles from the blog index for visual consistency.
- Pagination follows the same pattern as `blog-index.php`.

---

### 9. Modify: `templates/public/layout.php`

**Purpose**: Add the CSS link, hamburger toggle button, cookie consent partial, GA data attribute, and cookie consent JS script.

**Changes**:

```diff
  <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title><?= $this->e(($meta['title'] ?? $title ?? '') . ' — ' . ($siteName ?? 'LiteCMS')) ?></title>
  <?php if (!empty($meta)): ?>
  <?= $this->metaTags($meta) ?>
  <?php endif; ?>
      <meta property="og:site_name" content="<?= $this->e($siteName ?? 'LiteCMS') ?>">
+     <link rel="stylesheet" href="/assets/css/style.css">
  <?= $this->yieldSection('head') ?>
  </head>
- <body>
+ <body<?php if (!empty($gaId)): ?> data-ga-id="<?= $this->e($gaId) ?>"<?php endif; ?>>
      <header class="site-header">
          <div class="container">
              <a href="/" class="site-logo"><?= $this->e($siteName ?? 'LiteCMS') ?></a>
+             <button type="button" class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
+                 <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
+                     <path d="M3 12h18M3 6h18M3 18h18"/>
+                 </svg>
+             </button>
              <nav class="site-nav" aria-label="Main navigation">
                  ...navigation stays the same...
              </nav>
          </div>
      </header>
      ...main and footer stay the same...
+ <?= $this->partial('public/partials/cookie-consent', [
+     'consentText' => $consentText ?? '',
+     'consentLink' => $consentLink ?? '',
+ ]) ?>
+ <script src="/assets/js/cookie-consent.js"></script>
+ <script>
+ document.querySelector('.nav-toggle').addEventListener('click', function() {
+     var nav = document.querySelector('.site-nav');
+     var expanded = this.getAttribute('aria-expanded') === 'true';
+     nav.classList.toggle('open');
+     this.setAttribute('aria-expanded', !expanded);
+ });
+ </script>
  <?= $this->yieldSection('scripts') ?>
  </body>
```

**Full file** after modifications:

```php
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
    <link rel="stylesheet" href="/assets/css/style.css">
<?= $this->yieldSection('head') ?>
</head>
<body<?php if (!empty($gaId)): ?> data-ga-id="<?= $this->e($gaId) ?>"<?php endif; ?>>
    <header class="site-header">
        <div class="container">
            <a href="/" class="site-logo"><?= $this->e($siteName ?? 'LiteCMS') ?></a>
            <button type="button" class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 12h18M3 6h18M3 18h18"/>
                </svg>
            </button>
            <nav class="site-nav" aria-label="Main navigation">
                <ul class="nav-list">
                    <li<?= (($currentSlug ?? '') === '' && ($title ?? '') !== 'Blog' && ($title ?? '') !== 'Page Not Found') ? ' class="active"' : '' ?>><a href="/">Home</a></li>
<?php if (!empty($navPages)): ?>
<?php foreach ($navPages as $navPage): ?>
                    <li<?= (($currentSlug ?? '') === $navPage['slug']) ? ' class="active"' : '' ?>><a href="/<?= $this->e($navPage['slug']) ?>"><?= $this->e($navPage['title']) ?></a></li>
<?php endforeach; ?>
<?php endif; ?>
                    <li<?= (($title ?? '') === 'Blog') ? ' class="active"' : '' ?>><a href="/blog">Blog</a></li>
                    <li<?= (($title ?? '') === 'Contact') ? ' class="active"' : '' ?>><a href="/contact">Contact</a></li>
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

<?= $this->partial('public/partials/cookie-consent', [
    'consentText' => $consentText ?? '',
    'consentLink' => $consentLink ?? '',
]) ?>
    <script src="/assets/js/cookie-consent.js"></script>
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
```

**Key changes from original**:
1. Added `<link rel="stylesheet" href="/assets/css/style.css">` in `<head>`.
2. Added `data-ga-id` attribute on `<body>` (for cookie-consent.js to read).
3. Added hamburger toggle `<button class="nav-toggle">` before the `<nav>`.
4. Added "Contact" link in the navigation list.
5. Added cookie consent partial before closing `</body>`.
6. Added `cookie-consent.js` script.
7. Added inline nav-toggle click handler.

---

### 10. Modify: `templates/public/home.php`

**Purpose**: Add a hero section with tagline and CTA button, enhance the existing recent posts listing.

**Full file** after modification:

```php
<?php $this->layout('public/layout'); ?>

<div class="homepage">
    <section class="hero">
        <h1>Welcome to <?= $this->e($siteName ?? $title) ?></h1>
        <p><?= $this->e($tagline ?? 'A fast, lightweight content management system for your business.') ?></p>
        <a href="/blog" class="cta-button">Read Our Blog</a>
    </section>

<?php if (!empty($posts)): ?>
    <section class="recent-posts">
        <h2>Recent Posts</h2>
<?php foreach ($posts as $post): ?>
        <article class="post-card">
<?php if (!empty($post['featured_image'])): ?>
            <div class="post-card-image">
                <a href="/blog/<?= $this->e($post['slug']) ?>">
                    <img src="<?= $this->e($post['featured_image']) ?>" alt="<?= $this->e($post['title']) ?>">
                </a>
            </div>
<?php endif; ?>
            <div class="post-card-body">
                <h3><a href="/blog/<?= $this->e($post['slug']) ?>"><?= $this->e($post['title']) ?></a></h3>
                <div class="post-meta">
                    <time datetime="<?= $this->e($post['published_at'] ?? $post['created_at']) ?>">
                        <?= date('M j, Y', strtotime($post['published_at'] ?? $post['created_at'])) ?>
                    </time>
                    <span class="post-author">by <?= $this->e($post['author_name'] ?? 'Unknown') ?></span>
                </div>
<?php if (!empty($post['excerpt'])): ?>
                <p class="post-excerpt"><?= $this->e($post['excerpt']) ?></p>
<?php else: ?>
                <p class="post-excerpt"><?= $this->e(mb_substr(strip_tags($post['body']), 0, 160, 'UTF-8')) ?>...</p>
<?php endif; ?>
                <a href="/blog/<?= $this->e($post['slug']) ?>" class="read-more">Read more</a>
            </div>
        </article>
<?php endforeach; ?>
    </section>
<?php else: ?>
    <p>No posts published yet. Check back soon!</p>
<?php endif; ?>
</div>
```

**Changes from original**:
- Replaced the plain `<h1>` with a `<section class="hero">` that includes:
  - `<h1>` with site name
  - `<p>` tagline (from `$tagline` variable, with default text)
  - CTA button linking to `/blog`
- Rest of the file (recent posts) remains identical.

---

### 11. Modify: `app/Templates/FrontController.php`

**Purpose**: Add methods for the contact page (GET and POST), archive listing, and enhance `renderPublic()` to pass cookie consent and GA settings.

**New/modified methods**:

#### New method: `contactPage(Request $request): Response`

```php
/**
 * Contact page (GET) — show the contact form.
 */
public function contactPage(Request $request): Response
{
    $meta = [
        'title'       => 'Contact — ' . Config::getString('site_name', 'LiteCMS'),
        'description' => 'Get in touch with us.',
        'canonical'   => Config::getString('site_url', '') . '/contact',
        'og_type'     => 'website',
        'og_url'      => Config::getString('site_url', '') . '/contact',
    ];

    return $this->renderPublic('public/contact', [
        'title'   => 'Contact',
        'meta'    => $meta,
        'old'     => [],
        'success' => $_SESSION['flash_success'] ?? '',
        'error'   => '',
    ]);
}
```

#### New method: `contactSubmit(Request $request): Response`

```php
/**
 * Contact form submission (POST) — validate and store.
 */
public function contactSubmit(Request $request): Response
{
    $name    = trim((string) $request->input('name', ''));
    $email   = trim((string) $request->input('email', ''));
    $subject = trim((string) $request->input('subject', ''));
    $message = trim((string) $request->input('message', ''));

    // Server-side validation
    $errors = [];
    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Name is required (max 100 characters).';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $errors[] = 'A valid email address is required.';
    }
    if (mb_strlen($subject) > 255) {
        $errors[] = 'Subject must be 255 characters or fewer.';
    }
    if ($message === '' || mb_strlen($message) > 5000) {
        $errors[] = 'Message is required (max 5000 characters).';
    }

    if (!empty($errors)) {
        $meta = [
            'title'     => 'Contact — ' . Config::getString('site_name', 'LiteCMS'),
            'canonical' => Config::getString('site_url', '') . '/contact',
            'og_type'   => 'website',
        ];
        return $this->renderPublic('public/contact', [
            'title' => 'Contact',
            'meta'  => $meta,
            'old'   => ['name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message],
            'error' => implode(' ', $errors),
            'success' => '',
        ]);
    }

    // Store submission
    QueryBuilder::query('contact_submissions')->insert([
        'name'       => $name,
        'email'      => $email,
        'subject'    => $subject,
        'message'    => $message,
        'ip_address' => $request->server('REMOTE_ADDR', ''),
    ]);

    // Redirect with flash message (PRG pattern)
    $_SESSION['flash_success'] = 'Thank you for your message! We will get back to you soon.';
    return Response::redirect('/contact');
}
```

#### New method: `archive(Request $request, string $typeSlug): Response`

```php
/**
 * Archive listing for a custom content type.
 */
public function archive(Request $request, string $typeSlug): Response
{
    // Look up the content type
    $contentType = QueryBuilder::query('content_types')
        ->select()
        ->where('slug', $typeSlug)
        ->where('has_archive', 1)
        ->first();

    if ($contentType === null) {
        return $this->notFound($request);
    }

    $perPage = Config::getInt('items_per_page', 10);
    $page = max(1, (int) ($request->query('page', '1')));
    $offset = ($page - 1) * $perPage;
    $now = gmdate('Y-m-d H:i:s');

    $total = QueryBuilder::query('content')
        ->select()
        ->where('type', $typeSlug)
        ->where('status', 'published')
        ->whereRaw('(published_at IS NULL OR published_at <= :now)', [':now' => $now])
        ->count();

    $totalPages = (int) ceil($total / $perPage);

    $items = QueryBuilder::query('content')
        ->select('content.*', 'users.username as author_name')
        ->leftJoin('users', 'users.id', '=', 'content.author_id')
        ->where('content.type', $typeSlug)
        ->where('content.status', 'published')
        ->whereRaw('(content.published_at IS NULL OR content.published_at <= :now)', [':now' => $now])
        ->orderBy('content.published_at', 'DESC')
        ->limit($perPage)
        ->offset($offset)
        ->get();

    $meta = [
        'title'       => $contentType['name'] . ' — ' . Config::getString('site_name', 'LiteCMS'),
        'description' => 'Browse all ' . strtolower($contentType['name']),
        'canonical'   => Config::getString('site_url', '') . '/' . $typeSlug,
        'og_type'     => 'website',
        'og_url'      => Config::getString('site_url', '') . '/' . $typeSlug,
    ];

    return $this->renderPublic('public/archive', [
        'title'        => $contentType['name'],
        'archiveTitle' => $contentType['name'],
        'archiveSlug'  => $typeSlug,
        'items'        => $items,
        'currentPage'  => $page,
        'totalPages'   => $totalPages,
        'total'        => $total,
        'meta'         => $meta,
    ]);
}
```

#### Modify: `renderPublic()` — add cookie consent and GA settings

```php
/**
 * Common render helper — merges in navigation, global data, and settings.
 */
private function renderPublic(string $template, array $data): Response
{
    // Read cookie consent and GA settings from the settings table (with graceful fallback)
    $settings = $this->getPublicSettings();

    $data = array_merge([
        'navPages'    => $this->getNavPages(),
        'siteName'    => Config::getString('site_name', 'LiteCMS'),
        'siteUrl'     => Config::getString('site_url', ''),
        'currentSlug' => '',
        'tagline'     => $settings['site_tagline'] ?? '',
        'consentText' => $settings['cookie_consent_text'] ?? '',
        'consentLink' => $settings['cookie_consent_link'] ?? '',
        'gaId'        => ($settings['ga_enabled'] ?? '') === '1' ? ($settings['ga_measurement_id'] ?? '') : '',
    ], $data);

    if (isset($data['content']['slug'])) {
        $data['currentSlug'] = $data['content']['slug'];
    }

    // Clear flash messages after reading
    if (isset($_SESSION['flash_success'])) {
        $data['success'] = $data['success'] ?? $_SESSION['flash_success'];
        unset($_SESSION['flash_success']);
    }

    $html = $this->app->template()->render($template, $data);
    return Response::html($html);
}
```

#### New private method: `getPublicSettings(): array`

```php
/**
 * Fetch public-relevant settings from the settings table.
 * Returns an associative array of key => value.
 * Gracefully returns empty array if settings table is empty.
 */
private function getPublicSettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $rows = QueryBuilder::query('settings')
            ->select('key', 'value')
            ->whereRaw("key IN (:k1, :k2, :k3, :k4, :k5)", [
                ':k1' => 'site_tagline',
                ':k2' => 'cookie_consent_text',
                ':k3' => 'cookie_consent_link',
                ':k4' => 'ga_enabled',
                ':k5' => 'ga_measurement_id',
            ])
            ->get();

        $cache = [];
        foreach ($rows as $row) {
            $cache[$row['key']] = $row['value'];
        }
    } catch (\Throwable $e) {
        $cache = [];
    }

    return $cache;
}
```

**Complete method summary** (FrontController after changes):

| Method | Visibility | Signature | Status |
|--------|-----------|-----------|--------|
| `__construct` | public | `(App $app)` | Existing |
| `homepage` | public | `(Request $request): Response` | Existing |
| `blogIndex` | public | `(Request $request): Response` | Existing |
| `blogPost` | public | `(Request $request, string $slug): Response` | Existing |
| `page` | public | `(Request $request, string $slug): Response` | Existing |
| `notFound` | public | `(Request $request): Response` | Existing |
| `contactPage` | public | `(Request $request): Response` | **NEW** |
| `contactSubmit` | public | `(Request $request): Response` | **NEW** |
| `archive` | public | `(Request $request, string $typeSlug): Response` | **NEW** |
| `getNavPages` | private | `(): array` | Existing |
| `isPublished` | private | `(array $content): bool` | Existing |
| `buildMeta` | private | `(array $content, string $type): array` | Existing |
| `renderPublic` | private | `(string $template, array $data): Response` | **MODIFIED** |
| `getPublicSettings` | private | `(): array` | **NEW** |

---

### 12. Modify: `public/index.php`

**Purpose**: Add routes for the contact page (GET + POST) and the archive catch-all. Routes must be ordered carefully.

**Changes** — add these routes in the public routes section (before the `/{slug}` catch-all):

```php
// Contact page
$router->get('/contact', [FrontController::class, 'contactPage']);
$router->post('/contact', [FrontController::class, 'contactSubmit']);
```

**Route order in the file** (the complete public routes section):

```php
// Public routes (order matters — specific routes before catch-all)
$router->get('/', [FrontController::class, 'homepage']);
$router->get('/blog', [FrontController::class, 'blogIndex']);
$router->get('/blog/{slug}', [FrontController::class, 'blogPost']);
$router->get('/contact', [FrontController::class, 'contactPage']);
$router->post('/contact', [FrontController::class, 'contactSubmit']);

// ... auth routes and admin routes remain unchanged ...

// Catch-all for pages by slug (MUST be last)
$router->get('/{slug}', [FrontController::class, 'page']);
```

**Note on archive routes**: The archive route is NOT registered here yet because custom content types don't exist until Chunk 5.1. The `/{slug}` catch-all in `FrontController::page()` already handles page lookup, and in Chunk 5.1 we will add explicit archive routes like `$router->get('/{typeSlug}', [FrontController::class, 'archive'])` with appropriate priority. The `archive()` method is implemented now so it's ready, but routing is deferred.

---

## Detailed Class Specifications

### `App\Templates\FrontController` (modified)

```
EXISTING PROPERTIES:
  - private App $app

NEW METHODS:

  - public contactPage(Request $request): Response
      1. Build meta array (title, description, canonical).
      2. Read flash_success from $_SESSION (set by contactSubmit after PRG redirect).
      3. Render 'public/contact' with title, meta, empty old[], success message.

  - public contactSubmit(Request $request): Response
      1. Read and trim: name, email, subject, message from POST input.
      2. Validate:
         - name: required, max 100 chars.
         - email: required, valid email format, max 255 chars.
         - subject: optional, max 255 chars.
         - message: required, max 5000 chars.
      3. If validation fails: re-render contact form with errors and old values.
      4. If validation passes:
         a. Insert into contact_submissions table (name, email, subject, message, ip_address).
         b. Set $_SESSION['flash_success'] with thank-you message.
         c. Return Response::redirect('/contact') — PRG pattern.

  - public archive(Request $request, string $typeSlug): Response
      1. Look up content_types by slug where has_archive = 1.
      2. If not found: return notFound().
      3. Query published content of that type with pagination.
      4. Build meta array.
      5. Render 'public/archive' with items, pagination, archiveSlug, archiveTitle.

  - private getPublicSettings(): array
      1. Static cache (called once per request).
      2. Query settings table for: site_tagline, cookie_consent_text,
         cookie_consent_link, ga_enabled, ga_measurement_id.
      3. Return associative array. Gracefully handle empty/missing settings.

MODIFIED METHODS:

  - private renderPublic(string $template, array $data): Response
      ADDED: Calls getPublicSettings() and merges these template variables:
        - tagline (string) — for hero section
        - consentText (string) — cookie consent banner text
        - consentLink (string) — privacy policy link
        - gaId (string) — GA Measurement ID (empty if GA disabled)
      ADDED: Reads and clears $_SESSION['flash_success'] after PRG redirect.
```

---

## Acceptance Test Procedures

### Test 1: Homepage renders with hero section and featured/recent content
```
1. Ensure at least 2 published posts exist in the database.
2. Visit http://localhost/
3. Verify:
   - Hero section visible with site name, tagline, and "Read Our Blog" CTA button.
   - Recent Posts section shows published posts as cards.
   - Each card has title, date, author, excerpt, and "Read more" link.
   - Clicking CTA button navigates to /blog.
```

### Test 2: Blog index shows published posts with pagination
```
1. Create 12 published posts.
2. Visit http://localhost/blog
3. Verify:
   - First page shows 10 posts (items_per_page default).
   - Pagination shows "Page 1 of 2" with "Next" link.
   - Click "Next" — page 2 shows remaining 2 posts with "Previous" link.
```

### Test 3: Blog post page displays title, author, date, featured image, and full body
```
1. Create a published post with title, body, featured image, and author.
2. Visit /blog/{slug}
3. Verify:
   - Title in <h1>.
   - Author name and formatted date in .post-meta.
   - Featured image displayed.
   - Full body HTML rendered.
   - Meta tags present in page source.
```

### Test 4: Contact form submits successfully with CSRF protection
```
1. Visit /contact
2. Fill out the form with valid data (name, email, message).
3. Submit the form.
4. Verify:
   - Redirected back to /contact (PRG pattern).
   - Success message displayed: "Thank you for your message!"
   - Row inserted into contact_submissions table with correct data.
5. Try submitting with empty name — error message shown, form retains entered values.
6. Try submitting without CSRF token (e.g., via curl) — returns 403.
```

### Test 5: Site is responsive (mobile viewport)
```
1. Open browser dev tools, set viewport to 375px width.
2. Visit the homepage.
3. Verify:
   - Navigation collapses — hamburger icon visible.
   - Clicking hamburger toggles the nav menu.
   - Post cards stack vertically (no horizontal layout).
   - Cookie consent banner stacks text and buttons vertically.
   - All text is readable, no horizontal scrolling.
```

### Test 6: Semantic HTML5 and accessibility
```
1. View page source of the homepage.
2. Verify:
   - Semantic elements: <header>, <nav>, <main>, <article>, <footer>.
   - Navigation has aria-label="Main navigation".
   - Images have alt attributes.
   - Headings follow hierarchy (h1 → h2 → h3).
   - Cookie consent has role="dialog" and aria-label.
   - Nav toggle has aria-expanded and aria-label.
```

### Test 7: First visit shows cookie consent banner; no GA before acceptance
```
1. Clear all cookies for the site.
2. Visit any public page.
3. Verify:
   - Cookie consent banner appears at bottom of page.
   - View page source — NO googletagmanager.com script tag present.
   - No litecms_consent cookie set yet.
```

### Test 8: Clicking "Accept" loads GA (if configured)
```
1. Insert into settings: ('ga_enabled', '1') and ('ga_measurement_id', 'G-TESTID123').
2. Clear cookies, visit homepage.
3. Click "Accept" on the cookie banner.
4. Verify:
   - Banner disappears.
   - litecms_consent cookie set to "accepted" with ~365 day expiry.
   - A <script> tag for googletagmanager.com/gtag/js?id=G-TESTID123 is injected into <head>.
```

### Test 9: Clicking "Decline" — no GA loaded
```
1. Clear cookies, visit homepage.
2. Click "Decline" on the cookie banner.
3. Verify:
   - Banner disappears.
   - litecms_consent cookie set to "declined".
   - NO googletagmanager.com script in the DOM.
```

### Test 10: Returning visitor who already accepted — no banner, GA loads
```
1. After test 8, navigate to another page (e.g., /blog).
2. Verify:
   - Cookie consent banner is NOT shown (hidden).
   - GA script loads automatically (because consent cookie is "accepted").
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);` (except template files that begin with `<?php $this->layout(...)` — the `declare` is inherited from the rendering context).
- All user output escaped with `$this->e()`.
- All database queries use parameterized PDO (via QueryBuilder).
- CSRF token validated on POST via the existing `CsrfMiddleware` (already global).

### Contact Form Security
- CSRF protection: handled by global `CsrfMiddleware` — all POST requests must include a valid `_csrf_token`.
- Input validation: server-side required fields, email format validation, max-length enforcement.
- IP address stored for abuse tracking (VARCHAR 45 for IPv6 compatibility).
- No file uploads on the contact form.
- PRG (Post-Redirect-Get) pattern prevents duplicate submissions on refresh.

### Cookie Consent — GDPR/ePrivacy Compliance
- No tracking cookies or analytics scripts are loaded until the user explicitly clicks "Accept".
- The consent cookie itself (`litecms_consent`) is classified as a "strictly necessary" cookie (records the user's consent preference) and does not require consent.
- The banner text is configurable via the settings table (UI in Chunk 5.2) — for now, a sensible default is used.
- The "Learn more" link (`$consentLink`) can point to a privacy policy page — configurable in settings (Chunk 5.2).

### Google Analytics — Conditional Loading
- The GA Measurement ID and enabled/disabled flag are read from the `settings` table.
- Until Chunk 5.2 (Settings Panel), these can be set manually in the DB: `INSERT INTO settings (key, value) VALUES ('ga_enabled', '1'), ('ga_measurement_id', 'G-XXXXXXXXXX');`
- The `data-ga-id` attribute on `<body>` passes the ID to the JavaScript without embedding it in a `<script>` tag (cleaner separation).
- If `ga_enabled` is not `'1'` or `ga_measurement_id` is empty, no `data-ga-id` attribute is rendered, and the JS silently does nothing.

### Archive Template — Forward Compatibility
- The archive template and `archive()` method are implemented now for Chunk 5.1 (Custom Content Types).
- The archive route registration is deferred to Chunk 5.1 because no custom content types exist yet.
- The `page()` catch-all at `/{slug}` handles pages; in Chunk 5.1, a dedicated route for `/{typeSlug}` will be inserted before the catch-all.

### Flash Messages — PRG Pattern
- The contact form uses a standard PRG (Post/Redirect/Get) pattern:
  1. POST to `/contact` → validate → store → set `$_SESSION['flash_success']` → redirect to GET `/contact`.
  2. GET `/contact` → read flash message from session → display → clear from session.
- This prevents duplicate form submissions when the user refreshes the page.

### Mobile Navigation
- The hamburger toggle is a `<button>` element (not a link) for accessibility.
- It uses an inline SVG icon (three horizontal lines) — no external icon library needed.
- The toggle adds/removes the `.open` class on `.site-nav` and updates `aria-expanded`.
- The inline `<script>` at the bottom of layout.php is intentionally minimal (4 lines) — doesn't warrant a separate JS file.

### Settings Table Reads — Performance
- `getPublicSettings()` uses a static variable cache so it queries the database only once per request, even though `renderPublic()` is called on every page load.
- The query uses a WHERE IN clause to fetch all needed settings in a single query.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `migrations/002_contact_submissions.sqlite.sql` | Migration | **Create** |
| 2 | `migrations/002_contact_submissions.pgsql.sql` | Migration | **Create** |
| 3 | `migrations/002_contact_submissions.mysql.sql` | Migration | **Create** |
| 4 | `public/assets/css/style.css` | Stylesheet | **Create** |
| 5 | `public/assets/js/cookie-consent.js` | JavaScript | **Create** |
| 6 | `templates/public/partials/cookie-consent.php` | Template partial | **Create** |
| 7 | `templates/public/contact.php` | Template | **Create** |
| 8 | `templates/public/archive.php` | Template | **Create** |
| 9 | `templates/public/layout.php` | Template | **Modify** |
| 10 | `templates/public/home.php` | Template | **Modify** |
| 11 | `app/Templates/FrontController.php` | PHP class | **Modify** |
| 12 | `public/index.php` | Entry point | **Modify** |

---

## Estimated Scope

- **New PHP methods**: 4 (contactPage, contactSubmit, archive, getPublicSettings)
- **Modified PHP methods**: 1 (renderPublic)
- **New templates**: 3 (contact.php, archive.php, partials/cookie-consent.php)
- **Modified templates**: 2 (layout.php, home.php)
- **New assets**: 2 (style.css, cookie-consent.js)
- **New migrations**: 3 (one per database driver)
- **Approximate new LOC**: ~550 (CSS: ~350, PHP: ~120, JS: ~80, SQL: ~15, Templates: ~80)
