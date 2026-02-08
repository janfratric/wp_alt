# LiteCMS — Browser Test Links

## Starting the Dev Server

From the project root, run:

```bash
php -S localhost:8000 -t public
```

This starts PHP's built-in development server with `public/` as the document root.

> **First request will auto-run migrations** — the SQLite database file (`storage/database.sqlite`) is created automatically.

---

## Available Pages (Chunks 1.1 + 1.2 + 1.3 + 2.1 + 2.2 + 2.3 + 2.4 + 3.1 + 3.2)

| # | URL | Expected Result |
|---|-----|-----------------|
| 1 | [http://localhost:8000/](http://localhost:8000/) | Public homepage — hero section with tagline and CTA button, styled recent blog posts as post cards, mobile-first responsive CSS |
| 2 | [http://localhost:8000/admin/login](http://localhost:8000/admin/login) | Login page — centered card with username/password form and CSRF token |
| 3 | [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard) | Admin dashboard — styled sidebar nav, topbar, stats cards (Total Content, Published, Drafts, Users, Media Files), and recent content table. Redirects to `/admin/login` if not authenticated |
| 4 | [http://localhost:8000/admin/content](http://localhost:8000/admin/content) | Content list — filterable/searchable table with type and status filters, pagination, bulk actions, and "+ New Content" button |
| 5 | [http://localhost:8000/admin/content/create](http://localhost:8000/admin/content/create) | Content editor (create mode) — two-column layout with title, slug, TinyMCE body editor, excerpt, and sidebar with publish/SEO/image fields |
| 6 | [http://localhost:8000/admin/content/create?type=post](http://localhost:8000/admin/content/create?type=post) | Content editor (create mode) — pre-selects "Post" type in the sidebar |
| 7 | [http://localhost:8000/admin/content/1/edit](http://localhost:8000/admin/content/1/edit) | Content editor (edit mode) — loads existing content, shows "Update" button instead of "Create", includes `_method=PUT` hidden field |
| 8 | [http://localhost:8000/admin/media](http://localhost:8000/admin/media) | Media library — upload form with drag & drop zone, media grid with thumbnails, pagination, delete buttons. Sidebar highlights "Media" |
| 8a | [http://localhost:8000/admin/media/browse?type=image](http://localhost:8000/admin/media/browse?type=image) | JSON endpoint — returns paginated list of image media items (used by media browser modal) |
| 9 | [http://localhost:8000/admin/users](http://localhost:8000/admin/users) | User list — searchable table with username, email, role badge, created date, edit/delete actions, pagination, and "+ New User" button. Admin-only (editors get 403) |
| 9a | [http://localhost:8000/admin/users/create](http://localhost:8000/admin/users/create) | Create user form — username, email, role select, password fields. Admin-only |
| 9b | [http://localhost:8000/admin/users/1/edit](http://localhost:8000/admin/users/1/edit) | Edit user form — pre-filled fields, `_method=PUT`, current password required for own password change, role field disabled when editing self |
| 10 | [http://localhost:8000/admin/settings](http://localhost:8000/admin/settings) | Settings placeholder — "Coming soon" message, sidebar highlights "Settings" |
| 11 | [http://localhost:8000/blog](http://localhost:8000/blog) | Blog index — paginated listing of published posts with author, date, excerpts. Pagination via `?page=N` |
| 11a | [http://localhost:8000/blog/hello-world](http://localhost:8000/blog/hello-world) | Single blog post — full post content with author name, date, featured image, and article-type OG tags |
| 12 | [http://localhost:8000/about](http://localhost:8000/about) | Single page — renders published page by slug with SEO meta tags (create an "about" page first via admin) |
| 14 | [http://localhost:8000/contact](http://localhost:8000/contact) | Contact form — CSRF-protected form with name, email, subject, message fields. Submits via POST, stores in DB, shows success via PRG pattern |
| 15 | [http://localhost:8000/nonexistent](http://localhost:8000/nonexistent) | Styled 404 page — "404 — Page Not Found" with navigation and "Return to homepage" link |

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

### Content Management (Chunk 2.2)

The content management section (`/admin/content`) provides full CRUD for pages and posts:
- **Content list** — sortable table with title, type badge, status badge, author, and date columns
- **Search** — filter content by title via search input
- **Type filter** — dropdown to show only pages or posts
- **Status filter** — dropdown to show only draft, published, or archived items
- **Pagination** — page navigation with "Prev/Next" links, preserves active filters across pages
- **Bulk actions** — select multiple items with checkboxes, then delete or change status in batch
- **Content editor** — two-column layout: main area (title, auto-generated slug, TinyMCE WYSIWYG body, excerpt) + sidebar (type, status, publish date, sort order, SEO meta fields, featured image URL)
- **TinyMCE WYSIWYG** — loaded from CDN, full toolbar with formatting, lists, links, images, tables, and code view
- **Slug auto-generation** — slug auto-populates from title as you type; manual edits are preserved
- **Draft/publish workflow** — set status to draft, published, or archived; `published_at` auto-sets when publishing without a date
- **Validation** — title required, type and status must be valid values; errors flash and redirect back
- **Security headers** — CSP allows `cdn.tiny.cloud` for TinyMCE on editor pages; `X-Frame-Options: DENY` on all content pages
- **Delete confirmation** — delete buttons use `data-confirm` attribute for browser confirmation dialog

### Media Management (Chunk 2.3)

The media management section (`/admin/media`) provides file upload and library browsing:
- **Upload zone** — drag & drop or click-to-select file upload with preview before submitting
- **File validation** — extension whitelist (jpg, jpeg, png, gif, webp, pdf), MIME type check via `finfo_file()`, and configurable size limit (default 5MB)
- **Randomized filenames** — uploaded files stored as `public/assets/uploads/YYYY/MM/{hash}.{ext}` with 32-char hex names
- **Media grid** — card-based layout showing thumbnails for images, file icon for PDFs, with original filename, MIME type, and uploader
- **Pagination** — page navigation with "Prev/Next" links when items exceed page size
- **Delete** — removes file from disk and database record, with browser confirmation dialog
- **Media browser modal** — accessible from TinyMCE toolbar button and Featured Image "Browse Media" button; shows image grid, click to select, insert into editor or set as featured image
- **TinyMCE image upload** — drag-and-drop images into the editor triggers AJAX upload to `/admin/media/upload`
- **Featured image picker** — Browse Media button opens modal, selected image shows preview, Remove button clears it; stored as hidden input URL
- **Security** — `.htaccess` in uploads directory disables PHP execution; CSP headers on all media pages; CSRF required on POST/DELETE
- **AJAX support** — upload and delete return JSON for AJAX requests, redirect with flash for form submissions

### User Management (Chunk 2.4)

The user management section (`/admin/users`) provides admin-only user CRUD:
- **User list** — table with username, email, role badge, created date, and edit/delete actions
- **Search** — filter users by username or email via search input
- **Pagination** — page navigation with "Prev/Next" links when users exceed page size
- **Create user** — form with username, email, role (admin/editor), and password fields
- **Edit user** — update username, email, role; change password (optional on edit)
- **Role enforcement** — every action requires admin role; editors get 403 Forbidden
- **Self-protection** — admins cannot delete their own account or change their own role
- **Password security** — changing own password requires entering current password; admin can reset other users' passwords without it
- **Session sync** — editing own username updates the sidebar display without re-login
- **Delete with reassignment** — deleting a user who has content prompts to reassign content to another user via modal
- **Validation** — username (alphanumeric+underscore, max 50, unique), email (valid format, unique), password (min 6 chars, required on create)
- **Security headers** — `X-Frame-Options: DENY` and `Content-Security-Policy` on all user management pages

### Public Website — Front Controller (Chunk 3.1)

The public-facing website is now functional with content-driven routing:
- **Homepage** (`/`) — displays "Welcome to LiteCMS" with recent published blog posts, author names, dates, and excerpts
- **Blog index** (`/blog`) — paginated listing of all published posts, ordered by publish date descending. Pagination via `?page=N`
- **Blog post** (`/blog/{slug}`) — single post with full content, author name, publish date, featured image, and article-type OG tags
- **Page** (`/{slug}`) — single published page with content, featured image, and website-type OG tags
- **404 page** — styled "Page Not Found" page with navigation and "Return to homepage" link
- **Auto-generated navigation** — header nav includes Home link, published pages sorted by sort_order, and Blog link
- **Active state** — current page is highlighted in navigation
- **SEO meta tags** — every page includes `<meta name="description">`, `<link rel="canonical">`, Open Graph tags (title, description, type, url, image)
- **Article OG tags** — blog posts additionally include `article:author` and `article:published_time` meta tags
- **Content scheduling** — content only visible if status='published' AND (published_at IS NULL OR published_at <= now)
- **Draft/archived protection** — draft and archived content returns 404 to public visitors
- **Canonical redirects** — posts accessed via `/{slug}` are 301-redirected to `/blog/{slug}`
- **Section/yield template system** — child templates can define named sections (head, scripts) that the layout yields

### Public Templates & Styling (Chunk 3.2)

The public site now has a complete, mobile-first responsive design:
- **Public stylesheet** (`/assets/css/style.css`) — CSS custom properties, mobile-first responsive layout with 768px breakpoint
- **Hero section** — homepage displays a centered hero with site tagline and "Read Our Blog" CTA button
- **Post cards** — blog index and homepage recent posts displayed as styled cards with featured images, author, date, excerpt
- **Contact page** (`/contact`) — CSRF-protected form with name, email, subject, message fields; server-side validation; PRG pattern; submissions stored in `contact_submissions` table
- **Archive template** — generic listing for custom content types with pagination (used by Chunk 5.1)
- **Cookie consent banner** — GDPR-compliant fixed-position banner with Accept/Decline buttons; stores choice in `litecms_consent` cookie for 365 days
- **Conditional Google Analytics** — GA gtag.js loads only after user clicks Accept; reads Measurement ID from `data-ga-id` on `<body>` (configured via settings table)
- **Mobile hamburger navigation** — `<button>` with SVG icon, `aria-expanded` attribute, toggles `.site-nav.open` class
- **Sticky header** — site header sticks to top with `position: sticky`
- **Navigation includes Contact** — Contact link appears in nav with active state highlighting
- **Settings-driven content** — tagline, cookie consent text, privacy link, GA ID read from `settings` table

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
_migrations          contact_submissions  content_types        media
ai_conversations     content              custom_fields        settings
users
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| "Class not found" errors | Run `composer dump-autoload` in project root |
| Port 8000 in use | Use a different port: `php -S localhost:8080 -t public` |
| No database file created | Check that `storage/` directory exists and is writable |
| Blank page / 500 error | Check PHP error log or run with `php -S localhost:8000 -t public 2>&1` |
