# LiteCMS — Browser Test Links

## Starting the Dev Server

From the project root, run:

```bash
php -S localhost:8000 -t public
```

This starts PHP's built-in development server with `public/` as the document root.

> **First request will auto-run migrations** — the SQLite database file (`storage/database.sqlite`) is created automatically.

---

## Available Pages (Chunks 1.1 + 1.2 + 1.3 + 2.1)

| # | URL | Expected Result |
|---|-----|-----------------|
| 1 | [http://localhost:8000/](http://localhost:8000/) | Public homepage — shows "Welcome to LiteCMS" with HTML layout |
| 2 | [http://localhost:8000/admin/login](http://localhost:8000/admin/login) | Login page — centered card with username/password form and CSRF token |
| 3 | [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard) | Admin dashboard — styled sidebar nav, topbar, stats cards (Total Content, Published, Drafts, Users, Media Files), and recent content table. Redirects to `/admin/login` if not authenticated |
| 4 | [http://localhost:8000/admin/content](http://localhost:8000/admin/content) | Content placeholder — "Coming soon" message, sidebar highlights "Content" |
| 5 | [http://localhost:8000/admin/media](http://localhost:8000/admin/media) | Media placeholder — "Coming soon" message, sidebar highlights "Media" |
| 6 | [http://localhost:8000/admin/users](http://localhost:8000/admin/users) | Users placeholder — "Coming soon" message, sidebar highlights "Users" |
| 7 | [http://localhost:8000/admin/settings](http://localhost:8000/admin/settings) | Settings placeholder — "Coming soon" message, sidebar highlights "Settings" |
| 8 | [http://localhost:8000/nonexistent](http://localhost:8000/nonexistent) | 404 page — shows "404 Not Found" (no route matches) |

### Authentication Flow

1. On first visit, a default admin user is auto-created: **username=`admin`**, **password=`admin`**
2. Visiting any `/admin/*` route (except `/admin/login`) redirects to the login page if not authenticated
3. After login, you're redirected to `/admin/dashboard` which shows a polished admin panel with sidebar navigation, stats cards, and recent content table
4. POST `/admin/logout` destroys the session and redirects back to the login page

### Admin Panel (Chunk 2.1)

After logging in, the admin panel features:
- **Sidebar navigation** with 5 links: Dashboard, Content, Media, Users, Settings — grouped by section (Main, Content, System)
- **Active state highlighting** — current page's nav link is visually highlighted
- **Top bar** with page title and "View Site" link
- **Dashboard stats** — 5 cards showing Total Content, Published, Drafts, Users, Media Files
- **Recent content table** — last 5 updated items with title, type, status badge, author, and timestamp
- **Responsive design** — sidebar collapses on mobile (<=768px) with hamburger toggle
- **Security headers** — `X-Frame-Options: DENY` and `Content-Security-Policy` on dashboard responses
- **Flash messages** — styled alerts (success/error) that auto-dismiss after 5 seconds

---

## What Happens Behind the Scenes

When you hit any URL:

1. `public/index.php` boots the app
2. **Database connection** is established (SQLite at `storage/database.sqlite`)
3. **Migrations run** automatically (idempotent — skips if already applied)
4. **Session starts** with secure cookie params (httponly, samesite=Lax)
5. **Default admin bootstrap** — if no users exist, creates admin/admin
6. **CSRF middleware** generates a token (GET) or validates it (POST/PUT/DELETE)
7. **Auth middleware** protects `/admin/*` routes (redirects to login if unauthenticated)
8. **Router** matches the URL to a handler
9. **Template engine** renders the view with the layout
10. HTML response is sent back

---

## Verifying the Database Was Created

After visiting any page, check that these files appeared:

```
storage/database.sqlite       ← main database
storage/database.sqlite-wal   ← WAL journal (may appear)
storage/database.sqlite-shm   ← shared memory (may appear)
```

You can inspect the database with any SQLite tool (e.g., DB Browser for SQLite, `sqlite3` CLI):

```bash
sqlite3 storage/database.sqlite ".tables"
```

Expected output:
```
_migrations      content          content_types    custom_fields
ai_conversations media            settings         users
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| "Class not found" errors | Run `composer dump-autoload` in project root |
| Port 8000 in use | Use a different port: `php -S localhost:8080 -t public` |
| No database file created | Check that `storage/` directory exists and is writable |
| Blank page / 500 error | Check PHP error log or run with `php -S localhost:8000 -t public 2>&1` |
