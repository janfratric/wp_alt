# Chunk 2.1 — Admin Layout & Dashboard
## Detailed Implementation Plan

---

## Overview

This chunk builds the admin panel shell: a polished sidebar navigation, responsive layout with top bar, and a dashboard page showing content statistics and recent items. It establishes the visual foundation for all future admin pages. At completion, the admin sees a styled, responsive dashboard after login with working navigation links (pointing to placeholder pages for future chunks).

---

## Prerequisites (from previous chunks)

**Chunk 1.1** delivered: App bootstrap, Router, Request/Response, Middleware pipeline, TemplateEngine (with `render()`, `layout()`, `content()`, `partial()`, `e()`, `csrfField()`), Config.

**Chunk 1.2** delivered: Database Connection (PDO singleton), QueryBuilder (fluent `select()`, `where()`, `orderBy()`, `limit()`, `count()`, `get()`, `first()`, `join()`, `leftJoin()`, static `query()` and `raw()`), Migrator, all 7 tables (users, content, content_types, custom_fields, media, settings, ai_conversations).

**Chunk 1.3** delivered: AuthController (login/logout), AuthMiddleware (protects `/admin/*`), RoleMiddleware, CsrfMiddleware, Session (with flash messages), bcrypt auth, rate limiting. Login sets session keys: `user_id`, `user_role`, `user_name`.

**Current state of files being modified:**
- `templates/admin/layout.php` — Minimal: no CSS link, no JS link, hardcoded single "Dashboard" nav link, inline-styled flash messages, basic sidebar footer with username + logout.
- `templates/admin/dashboard.php` — Placeholder: just `<h1>Dashboard</h1>` and welcome text.
- `public/index.php` — Dashboard route is a closure (line 71), not a controller.
- `public/assets/css/` — Empty (only `.gitkeep`).
- `public/assets/js/` — Empty (only `.gitkeep`).

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `public/assets/css/admin.css`

**Purpose**: Complete admin panel stylesheet using CSS custom properties. Mobile-first responsive design. This file must exist before the layout template references it.

**No dependencies** — standalone CSS file.

```css
/* =========================================================
   LiteCMS Admin Stylesheet
   ========================================================= */

/* --- CSS Custom Properties (Theming) --- */
:root {
    /* Colors */
    --color-primary: #2563eb;
    --color-primary-hover: #1d4ed8;
    --color-primary-light: #dbeafe;
    --color-bg: #f0f2f5;
    --color-white: #ffffff;
    --color-text: #1a1a2e;
    --color-text-muted: #6b7280;
    --color-border: #e5e7eb;
    --color-border-light: #f3f4f6;

    /* Sidebar */
    --sidebar-width: 250px;
    --sidebar-bg: #1e293b;
    --sidebar-text: #cbd5e1;
    --sidebar-text-hover: #ffffff;
    --sidebar-active-bg: #334155;

    /* Topbar */
    --topbar-height: 56px;
    --topbar-bg: #ffffff;
    --topbar-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);

    /* Cards */
    --card-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    --card-radius: 8px;

    /* Status colors */
    --color-success: #16a34a;
    --color-success-bg: #f0fdf4;
    --color-success-border: #bbf7d0;
    --color-error: #dc2626;
    --color-error-bg: #fef2f2;
    --color-error-border: #fecaca;
    --color-warning: #d97706;
    --color-warning-bg: #fffbeb;
    --color-warning-border: #fde68a;
    --color-info: #2563eb;
    --color-info-bg: #eff6ff;
    --color-info-border: #bfdbfe;

    /* Typography */
    --font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
        "Helvetica Neue", Arial, sans-serif;
    --font-mono: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;

    /* Transitions */
    --transition-fast: 0.15s ease;
    --transition-normal: 0.25s ease;
}

/* --- Reset / Base --- */
*,
*::before,
*::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: var(--font-family);
    background: var(--color-bg);
    color: var(--color-text);
    line-height: 1.5;
    min-height: 100vh;
}

a {
    color: var(--color-primary);
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}

/* --- Admin Layout Wrapper --- */
.admin-wrapper {
    display: flex;
    min-height: 100vh;
}

/* --- Sidebar --- */
.sidebar {
    width: var(--sidebar-width);
    background: var(--sidebar-bg);
    color: var(--sidebar-text);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 100;
    transition: transform var(--transition-normal);
    overflow-y: auto;
}

.sidebar-brand {
    padding: 1.25rem 1.5rem;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--sidebar-text-hover);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.sidebar-brand a {
    color: inherit;
    text-decoration: none;
}

.sidebar-nav {
    flex: 1;
    padding: 0.75rem 0;
}

.sidebar-nav .nav-section {
    padding: 0.5rem 1.5rem 0.25rem;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: rgba(203, 213, 225, 0.5);
    font-weight: 600;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1.5rem;
    color: var(--sidebar-text);
    text-decoration: none;
    font-size: 0.9rem;
    transition: background var(--transition-fast), color var(--transition-fast);
    border-left: 3px solid transparent;
}

.sidebar-nav a:hover {
    background: rgba(255, 255, 255, 0.05);
    color: var(--sidebar-text-hover);
    text-decoration: none;
}

.sidebar-nav a.active {
    background: var(--sidebar-active-bg);
    color: var(--sidebar-text-hover);
    border-left-color: var(--color-primary);
}

.sidebar-nav .nav-icon {
    width: 1.1rem;
    text-align: center;
    flex-shrink: 0;
}

.sidebar-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 0.85rem;
}

.sidebar-footer .user-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.sidebar-footer .user-name {
    font-weight: 500;
    color: var(--sidebar-text-hover);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sidebar-footer .user-role {
    font-size: 0.75rem;
    color: var(--sidebar-text);
}

.sidebar-footer .logout-btn {
    background: none;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: var(--sidebar-text);
    padding: 0.3rem 0.6rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.8rem;
    transition: all var(--transition-fast);
}

.sidebar-footer .logout-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    color: var(--sidebar-text-hover);
    border-color: rgba(255, 255, 255, 0.3);
}

/* --- Main Content Area --- */
.admin-main {
    flex: 1;
    margin-left: var(--sidebar-width);
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* --- Top Bar --- */
.topbar {
    height: var(--topbar-height);
    background: var(--topbar-bg);
    box-shadow: var(--topbar-shadow);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1.5rem;
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.topbar-title {
    font-size: 1.1rem;
    font-weight: 600;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: 0.9rem;
    color: var(--color-text-muted);
}

.topbar-right a {
    color: var(--color-text-muted);
    font-size: 0.85rem;
}

.topbar-right a:hover {
    color: var(--color-primary);
}

/* Mobile menu toggle — hidden on desktop */
.sidebar-toggle {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
    color: var(--color-text);
    font-size: 1.25rem;
    line-height: 1;
}

/* --- Admin Content --- */
.admin-content {
    flex: 1;
    padding: 1.5rem;
    max-width: 1200px;
    width: 100%;
}

/* --- Page Header --- */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.page-header h1 {
    font-size: 1.5rem;
    font-weight: 600;
}

/* --- Alert / Flash Messages --- */
.alert {
    padding: 0.75rem 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
    border: 1px solid;
}

.alert-error {
    background: var(--color-error-bg);
    color: #991b1b;
    border-color: var(--color-error-border);
}

.alert-success {
    background: var(--color-success-bg);
    color: #166534;
    border-color: var(--color-success-border);
}

.alert-warning {
    background: var(--color-warning-bg);
    color: #92400e;
    border-color: var(--color-warning-border);
}

.alert-info {
    background: var(--color-info-bg);
    color: #1e40af;
    border-color: var(--color-info-border);
}

/* --- Stat Cards Grid --- */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--color-white);
    border-radius: var(--card-radius);
    box-shadow: var(--card-shadow);
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.stat-card .stat-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--color-text-muted);
    font-weight: 600;
}

.stat-card .stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--color-text);
    line-height: 1.2;
}

.stat-card .stat-detail {
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

/* --- Cards / Panels --- */
.card {
    background: var(--color-white);
    border-radius: var(--card-radius);
    box-shadow: var(--card-shadow);
    margin-bottom: 1.5rem;
}

.card-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    font-weight: 600;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-body {
    padding: 1.25rem;
}

/* --- Tables --- */
.table-responsive {
    overflow-x: auto;
}

table.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

table.data-table th,
table.data-table td {
    padding: 0.7rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

table.data-table th {
    font-weight: 600;
    color: var(--color-text-muted);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    background: var(--color-border-light);
}

table.data-table tbody tr:hover {
    background: var(--color-border-light);
}

table.data-table tbody tr:last-child td {
    border-bottom: none;
}

/* --- Status Badges --- */
.badge {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: capitalize;
}

.badge-published {
    background: var(--color-success-bg);
    color: var(--color-success);
}

.badge-draft {
    background: var(--color-warning-bg);
    color: var(--color-warning);
}

.badge-archived {
    background: var(--color-border-light);
    color: var(--color-text-muted);
}

.badge-admin {
    background: var(--color-primary-light);
    color: var(--color-primary);
}

.badge-editor {
    background: var(--color-info-bg);
    color: var(--color-info);
}

/* --- Buttons --- */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 1rem;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
    background: var(--color-white);
    color: var(--color-text);
    line-height: 1.4;
}

.btn:hover {
    text-decoration: none;
    background: var(--color-border-light);
}

.btn-primary {
    background: var(--color-primary);
    color: var(--color-white);
    border-color: var(--color-primary);
}

.btn-primary:hover {
    background: var(--color-primary-hover);
    border-color: var(--color-primary-hover);
}

.btn-danger {
    background: var(--color-error);
    color: var(--color-white);
    border-color: var(--color-error);
}

.btn-danger:hover {
    background: #b91c1c;
    border-color: #b91c1c;
}

.btn-sm {
    padding: 0.3rem 0.6rem;
    font-size: 0.8rem;
}

/* --- Forms (base styles for future chunks) --- */
.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.35rem;
    font-weight: 500;
    font-size: 0.9rem;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="password"],
.form-group input[type="url"],
.form-group input[type="number"],
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    font-size: 0.9rem;
    font-family: inherit;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

/* --- Empty State --- */
.empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
    color: var(--color-text-muted);
}

.empty-state p {
    margin-bottom: 1rem;
    font-size: 0.95rem;
}

/* --- Utility --- */
.text-muted {
    color: var(--color-text-muted);
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}

.mb-0 {
    margin-bottom: 0;
}

.mt-1 {
    margin-top: 0.5rem;
}

/* --- Responsive: Tablet & Mobile --- */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
    }

    .sidebar.open {
        transform: translateX(0);
    }

    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 99;
    }

    .sidebar-overlay.active {
        display: block;
    }

    .admin-main {
        margin-left: 0;
    }

    .sidebar-toggle {
        display: inline-flex;
    }

    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
}
```

**Notes**:
- CSS custom properties allow easy theming in future.
- Mobile-first responsive: sidebar slides off-screen on `<=768px`, toggled by JS.
- Stat cards use `auto-fit` grid — adapts from 1 to 4+ columns automatically.
- Table styles, badge styles, button styles, and form styles provide a foundation for chunks 2.2–2.4.
- Alert classes replace the inline styles currently in `templates/admin/layout.php`.

---

### 2. `public/assets/js/admin.js`

**Purpose**: Basic admin JavaScript — sidebar toggle for mobile, delete confirmation dialogs, auto-dismiss flash messages.

**Dependencies**: None (vanilla JS, no framework).

```js
/**
 * LiteCMS Admin JavaScript
 */
document.addEventListener("DOMContentLoaded", function () {
    // --- Sidebar Toggle (Mobile) ---
    const sidebar = document.querySelector(".sidebar");
    const sidebarToggle = document.querySelector(".sidebar-toggle");
    const sidebarOverlay = document.querySelector(".sidebar-overlay");

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener("click", function () {
            sidebar.classList.toggle("open");
            if (sidebarOverlay) {
                sidebarOverlay.classList.toggle("active");
            }
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener("click", function () {
            sidebar.classList.remove("open");
            sidebarOverlay.classList.remove("active");
        });
    }

    // --- Delete Confirmations ---
    document.querySelectorAll("[data-confirm]").forEach(function (el) {
        el.addEventListener("click", function (e) {
            if (!confirm(el.getAttribute("data-confirm"))) {
                e.preventDefault();
            }
        });
    });

    // --- Auto-dismiss flash messages after 5 seconds ---
    document.querySelectorAll(".alert").forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = "opacity 0.3s ease";
            alert.style.opacity = "0";
            setTimeout(function () {
                alert.remove();
            }, 300);
        }, 5000);
    });
});
```

**Notes**:
- `data-confirm="Are you sure?"` attribute on any element triggers a confirmation dialog on click.
- Sidebar open/close uses class toggling — CSS handles the animation via `transform`.
- Flash messages auto-fade and self-remove after 5 seconds.
- No dependencies — vanilla JS only per project constraints.

---

### 3. `app/Admin/DashboardController.php`

**Purpose**: Controller for the admin dashboard. Queries the database for content statistics and recent items, passes data to the dashboard template.

**Dependencies**: `App\Core\App`, `App\Core\Request`, `App\Core\Response`, `App\Database\QueryBuilder`.

**Class**: `App\Admin\DashboardController`

```php
<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;

class DashboardController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/dashboard — Show dashboard with stats and recent content.
     */
    public function index(Request $request): Response
    {
        // Content counts by status
        $totalContent   = QueryBuilder::query('content')->select()->count();
        $publishedCount = QueryBuilder::query('content')->select()->where('status', 'published')->count();
        $draftCount     = QueryBuilder::query('content')->select()->where('status', 'draft')->count();

        // Counts by type
        $pageCount = QueryBuilder::query('content')->select()->where('type', 'page')->count();
        $postCount = QueryBuilder::query('content')->select()->where('type', 'post')->count();

        // User and media counts
        $userCount  = QueryBuilder::query('users')->select()->count();
        $mediaCount = QueryBuilder::query('media')->select()->count();

        // Recent content (latest 5 items with author name)
        $recentContent = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id')
            ->orderBy('content.updated_at', 'DESC')
            ->limit(5)
            ->get();

        $html = $this->app->template()->render('admin/dashboard', [
            'title'          => 'Dashboard',
            'totalContent'   => $totalContent,
            'publishedCount' => $publishedCount,
            'draftCount'     => $draftCount,
            'pageCount'      => $pageCount,
            'postCount'      => $postCount,
            'userCount'      => $userCount,
            'mediaCount'     => $mediaCount,
            'recentContent'  => $recentContent,
        ]);

        return Response::html($html)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'");
    }
}
```

**Properties**:
```
PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app) — stores App reference for template rendering

METHODS:
  - public index(Request $request): Response
      1. Query content table for total count, published count, draft count.
      2. Query content table for page count, post count.
      3. Query users table for total count.
      4. Query media table for total count.
      5. Query content table JOIN users for 5 most recently updated items.
      6. Render 'admin/dashboard' template with all stats data.
      7. Return Response with CSP and X-Frame-Options headers.
```

**Notes**:
- CSP headers are set here on every admin response. In future chunks, this could be extracted to middleware. For now, the controller adds them directly.
- The `leftJoin` with users handles the case where an author may have been deleted (returns null author_name).
- Uses `Response::html()` static factory, then fluent `withHeader()` for security headers.

---

### 4. `templates/admin/layout.php` (UPDATE — replace existing)

**Purpose**: Full admin layout with sidebar navigation, top bar with user info, main content area, linked CSS/JS files, CSP meta tags, and responsive overlay.

**Dependencies**: `admin.css`, `admin.js` must exist.

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'Admin') ?> — LiteCMS Admin</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
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
```

**Key changes from existing layout**:
- Adds `<link>` to `admin.css` in `<head>`.
- Adds `<script>` to `admin.js` before `</body>`.
- Replaces `<h2>LiteCMS</h2>` with a proper `.sidebar-brand` with link.
- Replaces single "Dashboard" link with full navigation: Dashboard, Content, Media, Users, Settings.
- Navigation items use `$activeNav` template variable for active state highlighting.
- Navigation items grouped by section ("Main", "Content", "System") with section labels.
- Each nav item has a Unicode icon in `.nav-icon` span (no icon library needed).
- Sidebar footer now shows username, role, and a styled logout button.
- Adds `.sidebar-overlay` div for mobile overlay when sidebar is open.
- Wraps content in `.admin-main` with a `.topbar` header (page title, "View Site" link, mobile toggle).
- Replaces inline-styled flash messages with CSS class-based alerts.
- Mobile toggle button (`.sidebar-toggle`) hidden on desktop, shown on mobile via CSS.

---

### 5. `templates/admin/dashboard.php` (UPDATE — replace existing)

**Purpose**: Dashboard page with statistics cards and recent content table.

**Dependencies**: Receives data from `DashboardController::index()`.

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Dashboard</h1>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-label">Total Content</span>
        <span class="stat-value"><?= (int)$totalContent ?></span>
        <span class="stat-detail"><?= (int)$pageCount ?> pages, <?= (int)$postCount ?> posts</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Published</span>
        <span class="stat-value"><?= (int)$publishedCount ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Drafts</span>
        <span class="stat-value"><?= (int)$draftCount ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Users</span>
        <span class="stat-value"><?= (int)$userCount ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Media Files</span>
        <span class="stat-value"><?= (int)$mediaCount ?></span>
    </div>
</div>

<!-- Recent Content -->
<div class="card">
    <div class="card-header">
        Recent Content
    </div>
    <?php if (empty($recentContent)): ?>
        <div class="card-body">
            <div class="empty-state">
                <p>No content yet. Start by creating your first page or post.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Author</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentContent as $item): ?>
                        <tr>
                            <td>
                                <strong><?= $this->e($item['title']) ?></strong>
                            </td>
                            <td>
                                <span class="badge"><?= $this->e(ucfirst($item['type'])) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $this->e($item['status']) ?>">
                                    <?= $this->e(ucfirst($item['status'])) ?>
                                </span>
                            </td>
                            <td><?= $this->e($item['author_name'] ?? 'Unknown') ?></td>
                            <td class="text-muted">
                                <?= $this->e($item['updated_at'] ?? $item['created_at'] ?? '') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
```

**Key changes from existing template**:
- Replaces the simple `<h1>Dashboard</h1><p>Welcome...</p>` with a full stats + table dashboard.
- Statistics cards show: total content (with page/post breakdown), published count, draft count, user count, media count.
- Recent content table shows the 5 most recently updated items with title, type, status (as colored badge), author name, and updated timestamp.
- Empty state message shown when no content exists yet.
- All dynamic values are escaped with `$this->e()` or cast to `(int)`.

---

### 6. `public/index.php` (UPDATE — modify route registration)

**Purpose**: Replace the dashboard closure route with a controller reference. Add placeholder routes for other admin pages.

**Changes**:
1. Add `use App\Admin\DashboardController;` import.
2. Replace the dashboard closure route with `[DashboardController::class, 'index']`.
3. Add placeholder routes for Content, Media, Users, Settings pages (return simple "Coming Soon" pages) so sidebar links don't 404.

**Modified section** (only the admin routes block changes — lines 69-78):

```php
// (add to use statements at top)
use App\Admin\DashboardController;

// (replace the admin routes block)
// Admin routes (protected by AuthMiddleware)
$router->group('/admin', function($router) use ($app) {
    // Dashboard
    $router->get('/dashboard', [DashboardController::class, 'index']);

    // Placeholder routes for sidebar links (to be replaced in future chunks)
    $router->get('/content', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/placeholder', [
                'title' => 'Content',
                'activeNav' => 'content',
                'message' => 'Content management is coming in Chunk 2.2.',
            ])
        );
    });
    $router->get('/media', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/placeholder', [
                'title' => 'Media',
                'activeNav' => 'media',
                'message' => 'Media management is coming in Chunk 2.3.',
            ])
        );
    });
    $router->get('/users', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/placeholder', [
                'title' => 'Users',
                'activeNav' => 'users',
                'message' => 'User management is coming in Chunk 2.4.',
            ])
        );
    });
    $router->get('/settings', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/placeholder', [
                'title' => 'Settings',
                'activeNav' => 'settings',
                'message' => 'Settings panel is coming in Chunk 5.2.',
            ])
        );
    });
});
```

---

### 7. `templates/admin/placeholder.php` (NEW)

**Purpose**: Generic placeholder page for sidebar navigation links that don't have real controllers yet. Prevents 404s and shows a friendly "coming soon" message.

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $this->e($title ?? 'Coming Soon') ?></h1>
</div>

<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <p><?= $this->e($message ?? 'This feature is not yet implemented.') ?></p>
        </div>
    </div>
</div>
```

---

## Detailed Class Specifications

### `App\Admin\DashboardController`

```
NAMESPACE: App\Admin
FILE: app/Admin/DashboardController.php

PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app)
      Receives and stores the App container instance.
      Pattern consistent with AuthController from Chunk 1.3.

METHODS:
  - public index(Request $request): Response
      Parameters:
          $request: Request — the current HTTP request (unused but required by dispatcher)
      Returns: Response — HTML response with CSP headers

      Logic:
          1. $totalContent = QueryBuilder::query('content')->select()->count()
          2. $publishedCount = QueryBuilder::query('content')->select()->where('status', 'published')->count()
          3. $draftCount = QueryBuilder::query('content')->select()->where('status', 'draft')->count()
          4. $pageCount = QueryBuilder::query('content')->select()->where('type', 'page')->count()
          5. $postCount = QueryBuilder::query('content')->select()->where('type', 'post')->count()
          6. $userCount = QueryBuilder::query('users')->select()->count()
          7. $mediaCount = QueryBuilder::query('media')->select()->count()
          8. $recentContent = QueryBuilder::query('content')
                 ->select('content.*', 'users.username as author_name')
                 ->leftJoin('users', 'users.id', '=', 'content.author_id')
                 ->orderBy('content.updated_at', 'DESC')
                 ->limit(5)
                 ->get()
          9. Render 'admin/dashboard' with all data + 'activeNav' => 'dashboard'
         10. Return Response::html($html)
                 ->withHeader('X-Frame-Options', 'DENY')
                 ->withHeader('Content-Security-Policy', ...)
```

---

## Changes to Existing Files

### `templates/admin/layout.php` — FULL REPLACE

The entire file is replaced. See section 4 above for the complete new template. Summary of changes:

| Aspect | Before (Chunk 1.3) | After (Chunk 2.1) |
|--------|--------------------|--------------------|
| CSS | None (inline styles) | `<link>` to `admin.css` |
| JS | None | `<script>` to `admin.js` |
| Navigation | Single "Dashboard" link | 5 links grouped into 3 sections |
| Active state | None | `$activeNav` variable highlights current page |
| Top bar | None | Header with page title, mobile toggle, "View Site" link |
| Sidebar footer | Basic text + underlined link | Styled username/role + logout button |
| Flash messages | Inline-styled `<div>` | CSS class-based `.alert` elements |
| Mobile | Not responsive | Sidebar slides, overlay, toggle button |
| Structure | `<main class="admin-content">` | `<div class="admin-main">` wrapping topbar + content |

### `templates/admin/dashboard.php` — FULL REPLACE

The entire file is replaced. See section 5 above. Changes: from 3-line placeholder to full stats grid + recent content table.

### `public/index.php` — PARTIAL EDIT

- Add `use App\Admin\DashboardController;` to imports.
- Replace dashboard closure with `[DashboardController::class, 'index']`.
- Add placeholder routes for `/admin/content`, `/admin/media`, `/admin/users`, `/admin/settings`.

---

## Template Variables Contract

### `admin/layout.php` expects:
| Variable | Type | Required | Source |
|----------|------|----------|--------|
| `$title` | string | Yes | Passed by controller |
| `$activeNav` | string | Yes | Passed by controller. Values: `'dashboard'`, `'content'`, `'media'`, `'users'`, `'settings'` |

Session variables read directly: `$_SESSION['user_name']`, `$_SESSION['user_role']`.

### `admin/dashboard.php` expects:
| Variable | Type | Required | Source |
|----------|------|----------|--------|
| `$title` | string | Yes | `'Dashboard'` |
| `$totalContent` | int | Yes | QueryBuilder count |
| `$publishedCount` | int | Yes | QueryBuilder count |
| `$draftCount` | int | Yes | QueryBuilder count |
| `$pageCount` | int | Yes | QueryBuilder count |
| `$postCount` | int | Yes | QueryBuilder count |
| `$userCount` | int | Yes | QueryBuilder count |
| `$mediaCount` | int | Yes | QueryBuilder count |
| `$recentContent` | array | Yes | QueryBuilder get() — array of rows |

Each `$recentContent` row: `['title', 'type', 'status', 'author_name', 'updated_at', 'created_at', ...]`.

### `admin/placeholder.php` expects:
| Variable | Type | Required | Source |
|----------|------|----------|--------|
| `$title` | string | Yes | Page name |
| `$activeNav` | string | Yes | Nav item key |
| `$message` | string | Yes | Coming soon message |

---

## Acceptance Test Procedures

### Test 1: Dashboard loads after login with correct content counts

```
1. Reset/fresh database (0 content, 1 user, 0 media).
2. Log in as admin.
3. Verify /admin/dashboard returns HTTP 200.
4. Verify stats cards show: Total Content = 0, Published = 0, Drafts = 0, Users = 1, Media Files = 0.
5. Verify "pages, posts" detail under Total Content shows "0 pages, 0 posts".
6. Verify recent content area shows empty state message.
```

### Test 2: Sidebar navigation renders with all menu items; active item is highlighted

```
1. Visit /admin/dashboard.
2. Verify sidebar contains links: Dashboard, Content, Media, Users, Settings.
3. Verify the "Dashboard" link has class "active" (highlighted).
4. Click "Content" link — verify page loads and "Content" link is now highlighted.
5. Verify all 5 nav links are clickable and do not return 404.
```

### Test 3: Layout is responsive — works on mobile (sidebar collapses)

```
1. Visit /admin/dashboard on desktop (>768px) — sidebar visible, toggle button hidden.
2. Resize browser to <=768px — sidebar should slide off-screen, toggle button (☰) should appear.
3. Click toggle button — sidebar slides in, overlay appears behind it.
4. Click overlay — sidebar closes.
5. Verify content area is usable at mobile width.
```

### Test 4: Admin layout includes CSP headers

```
1. Visit /admin/dashboard.
2. Check response headers:
   - X-Frame-Options: DENY
   - Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'
3. Both headers must be present.
```

### Test 5: Username and logout link appear in the top bar / sidebar

```
1. Log in as "admin".
2. Visit /admin/dashboard.
3. Verify sidebar footer shows username "admin" and role "Admin".
4. Verify logout button is present and functional.
5. Click logout — verify session is destroyed and user is redirected to /admin/login.
```

### Test 6: Dashboard stats are accurate (non-zero data)

```
1. Insert test data into database:
   - 2 published pages, 1 draft post, 1 archived page.
   - 1 media record.
2. Visit /admin/dashboard.
3. Verify: Total Content = 4, Published = 2, Drafts = 1, Pages = 3, Posts = 1, Media = 1.
4. Verify recent content table shows all 4 items ordered by updated_at DESC.
5. Verify each row shows correct title, type, status badge, and author name.
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);` (except templates which are included by the engine).
- Controller follows the established pattern from `AuthController`: constructor receives `App`, methods receive `Request` and return `Response`.
- PSR-4: `App\Admin\DashboardController` → `app/Admin/DashboardController.php`.
- All user output in templates escaped with `$this->e()` or cast to `(int)`.

### Navigation Icons
- Using Unicode characters instead of an icon library to stay lightweight (no extra HTTP requests, no icon font).
- Characters chosen: ■ (dashboard), ✎ (content), 📷 (media), 👥 (users), ⚙ (settings).
- These can be replaced with SVG icons in a future polish pass if desired.

### CSP Headers
- `style-src 'self' 'unsafe-inline'` is needed because the auth layout (`templates/auth/layout.php`) uses `<style>` tags. This will remain until a future chunk extracts those styles. The admin layout itself does not use inline styles.
- `script-src 'self'` — no inline scripts. The `admin.js` file handles all behavior.
- In a future chunk, CSP could be moved to a middleware so all admin responses include it automatically.

### `activeNav` Variable
- Every controller/closure that renders an admin page must pass `'activeNav' => 'navKey'` in the template data array.
- The layout template compares this against each nav link to add the `active` class.
- This is a simple approach that avoids complex URL matching logic.

### Security
- CSRF protection is already handled by `CsrfMiddleware` (from Chunk 1.3) — no changes needed.
- Auth protection is already handled by `AuthMiddleware` (from Chunk 1.3) — no changes needed.
- `X-Frame-Options: DENY` prevents clickjacking on admin pages.
- `Content-Security-Policy` restricts script/style sources.

### Future Compatibility
- The CSS establishes styles for tables, forms, buttons, badges, and cards that will be used by chunks 2.2 (Content CRUD), 2.3 (Media), and 2.4 (Users).
- The sidebar navigation structure is ready for additional links (e.g., "Content Types", "AI Generator") that will be added in later chunks.
- The placeholder routes prevent broken links and will be replaced with real controllers in their respective chunks.

---

## File Checklist

| # | File | Action | Type |
|---|------|--------|------|
| 1 | `public/assets/css/admin.css` | CREATE | Stylesheet |
| 2 | `public/assets/js/admin.js` | CREATE | JavaScript |
| 3 | `app/Admin/DashboardController.php` | CREATE | PHP Class |
| 4 | `templates/admin/layout.php` | REPLACE | Template |
| 5 | `templates/admin/dashboard.php` | REPLACE | Template |
| 6 | `templates/admin/placeholder.php` | CREATE | Template |
| 7 | `public/index.php` | EDIT | Entry point |

---

## Estimated Scope

- **New PHP classes**: 1 (DashboardController)
- **New templates**: 1 (placeholder.php)
- **Updated templates**: 2 (layout.php, dashboard.php)
- **New static assets**: 2 (admin.css, admin.js)
- **Entry point edits**: 1 (index.php — route changes)
- **Approximate new LOC**: ~550 CSS + ~40 JS + ~55 PHP controller + ~100 template = ~745 lines
