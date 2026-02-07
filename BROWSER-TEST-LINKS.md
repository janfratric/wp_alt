# LiteCMS — Browser Test Links

## Starting the Dev Server

From the project root, run:

```bash
php -S localhost:8000 -t public
```

This starts PHP's built-in development server with `public/` as the document root.

> **First request will auto-run migrations** — the SQLite database file (`storage/database.sqlite`) is created automatically.

---

## Available Pages (Chunks 1.1 + 1.2 + 1.3)

| # | URL | Expected Result |
|---|-----|-----------------|
| 1 | [http://localhost:8000/](http://localhost:8000/) | Public homepage — shows "Welcome to LiteCMS" with HTML layout |
| 2 | [http://localhost:8000/admin/login](http://localhost:8000/admin/login) | Login page — centered card with username/password form and CSRF token |
| 3 | [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard) | Admin dashboard — redirects to `/admin/login` if not authenticated; shows dashboard with logout button if logged in |
| 4 | [http://localhost:8000/nonexistent](http://localhost:8000/nonexistent) | 404 page — shows "404 Not Found" (no route matches) |

### Authentication Flow

1. On first visit, a default admin user is auto-created: **username=`admin`**, **password=`admin`**
2. Visiting any `/admin/*` route (except `/admin/login`) redirects to the login page if not authenticated
3. After login, you're redirected to `/admin/dashboard` which shows the admin panel with a logout button in the sidebar
4. POST `/admin/logout` destroys the session and redirects back to the login page

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
