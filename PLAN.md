# LiteCMS — Master Implementation Plan

## Context

This plan breaks down the implementation of LiteCMS — a lightweight PHP CMS (WordPress alternative) — into manageable chunks. The full specification lives in `PROMPT.md`. The project is greenfield (empty repo).

The CMS targets small business owners who find WordPress too heavy. It must run on shared hosting with PHP 8.1+, use no frameworks, stay under 5,000 LOC and 10 Composer packages, and support SQLite/PostgreSQL/MariaDB via a single config switch.

Each chunk below is designed to be implemented in a single focused session with its own dedicated detailed plan. Chunks are sequential — each builds on the previous.

---

## Phase 1 — Foundation (Core + Database + Auth)

### Chunk 1.1: Project Scaffolding & Core Framework

**Description**: Set up the project directory structure, Composer autoloading, configuration system, and the micro-framework core (router, request/response, middleware pipeline, app container). This is the skeleton everything else is built on.

**Input Prerequisites**: Empty repository with PROMPT.md

**Key Files to Create**:
- `composer.json` — PSR-4 autoloading for `App\` namespace, minimal dependencies
- `config/app.php` — Configuration array with all key values (db_driver, site_name, etc.)
- `.env.example` — Environment template
- `public/index.php` — Single entry point, bootstraps the app
- `public/.htaccess` — Apache URL rewriting
- `.htaccess` (root) — Deny access to non-public files
- `app/Core/App.php` — Application container, route registration, middleware pipeline execution
- `app/Core/Router.php` — Regex-based router (GET, POST, PUT, DELETE)
- `app/Core/Request.php` — Request wrapper (input sanitization, method detection)
- `app/Core/Response.php` — Response builder (HTML, JSON, redirects)
- `app/Core/Config.php` — Config reader with typed getters
- `app/Core/Middleware.php` — Middleware pipeline interface and runner
- `app/Templates/TemplateEngine.php` — Basic render($template, $data) with layout support
- `templates/admin/layout.php` — Minimal placeholder admin layout
- `storage/` directory structure (logs/, cache/)

**Output Deliverables**: A working micro-framework that can route HTTP requests to controller closures/methods, render PHP templates, and return responses. Visiting any URL shows a routed response.

**Acceptance Tests**:
1. `composer install` succeeds with no errors; PSR-4 autoloading works
2. Visiting `http://localhost/` returns a 200 response with rendered HTML from the router
3. Visiting an undefined route returns a 404 response
4. POST/PUT/DELETE routes work; Request object correctly reads input and detects method
5. Middleware pipeline executes in order (test with a simple logging middleware)
6. Config values are readable via `Config::get('site_name')` with type safety

---

### Chunk 1.2: Database Layer & Migrations

**Description**: Build the database connection factory (supporting SQLite, PostgreSQL, MariaDB via config switch), the fluent query builder that abstracts SQL dialect differences, and the migration system. Run the initial migration to create all 7 tables.

**Input Prerequisites**: Chunk 1.1 complete (working app bootstrap, config system)

**Key Files to Create**:
- `app/Database/Connection.php` — PDO connection factory (reads driver from config, sets UTF-8, error mode)
- `app/Database/QueryBuilder.php` — Fluent builder: `select()`, `insert()`, `update()`, `delete()`, `where()`, `orderBy()`, `limit()`, `join()`, etc. Must abstract: AUTOINCREMENT vs SERIAL vs AUTO_INCREMENT, boolean types, datetime handling
- `app/Database/Migrator.php` — Reads numbered SQL files per driver, tracks applied migrations in a `_migrations` table
- `migrations/001_initial.sqlite.sql` — All 7 tables for SQLite
- `migrations/001_initial.pgsql.sql` — All 7 tables for PostgreSQL
- `migrations/001_initial.mysql.sql` — All 7 tables for MariaDB

**Schema tables**: users, content, content_types, custom_fields, media, settings, ai_conversations (exact columns defined in PROMPT.md)

**Output Deliverables**: A working database layer where migrations run automatically on first request (or via a bootstrap route). All 7 tables exist. The query builder can perform CRUD operations against any of the three database drivers.

**Acceptance Tests**:
1. With `db_driver=sqlite`: app auto-creates `storage/database.sqlite`, runs migration, all 7 tables exist
2. Query builder can insert a row into `users` and select it back
3. Query builder can perform `where()`, `orderBy()`, `limit()` chains correctly
4. Switching `db_driver` to `pgsql` or `mysql` in config connects to the respective database and migrations run correctly (if a test DB is available)
5. Running migrations twice is idempotent (already-applied migrations are skipped)

---

### Chunk 1.3: Authentication System

**Description**: Build the complete authentication system: login/logout controllers, session management with security hardening, CSRF protection middleware, rate limiting for failed logins, and a first-run admin user bootstrap. This chunk makes the admin area protected.

**Input Prerequisites**: Chunks 1.1 + 1.2 complete (router, database, query builder, users table)

**Key Files to Create**:
- `app/Auth/AuthController.php` — Login form (GET), login handler (POST), logout handler
- `app/Auth/AuthMiddleware.php` — Session check, redirects unauthenticated users to /admin/login
- `app/Auth/RoleMiddleware.php` — Checks user role for admin-only routes
- `templates/auth/login.php` — Login page (styled, with CSRF token)
- Bootstrap logic: on first run, if no users exist, create default admin (admin/admin with forced password change)

**Security implemented in this chunk**:
- bcrypt password hashing (`password_hash` / `password_verify`)
- Session ID regeneration on login
- Secure cookie flags (httponly, samesite=Lax, secure when HTTPS)
- CSRF token generation per session, validated on all POST/PUT/DELETE
- Rate limiting: track failed login attempts per IP (5 failures = 15-minute lockout)
- `htmlspecialchars()` escaping in all template output

**Output Deliverables**: Visiting `/admin/login` shows a login form. Default admin credentials work on first run. After login, session persists across requests. Unauthenticated access to `/admin/*` redirects to login. CSRF protection active on all forms.

**Acceptance Tests**:
1. First visit: default admin user is created; login with admin/admin works
2. After login, session persists; visiting `/admin/dashboard` (placeholder) works
3. Logging out destroys session; subsequent `/admin/*` access redirects to login
4. Submitting a form without a valid CSRF token returns 403
5. After 5 failed login attempts from same IP, further attempts are blocked for 15 minutes
6. Password is stored as bcrypt hash (not plain text) in the database

---

## Phase 2 — Content Management (Admin + Content CRUD)

### Chunk 2.1: Admin Layout & Dashboard

**Description**: Build the admin panel shell — sidebar navigation, responsive layout, and the dashboard page showing content statistics and recent items. This establishes the visual foundation for all admin pages.

**Input Prerequisites**: Phase 1 complete (auth working, database with all tables, template engine)

**Key Files to Create**:
- `app/Admin/DashboardController.php` — Dashboard with content counts, recent items
- `templates/admin/layout.php` — Full admin layout (sidebar nav, top bar with user info, main content area)
- `templates/admin/dashboard.php` — Dashboard page (stats cards, recent content table)
- `public/assets/css/admin.css` — Complete admin panel stylesheet (using CSS custom properties or PicoCSS)
- `public/assets/js/admin.js` — Basic admin JS (sidebar toggle, confirmations)

**Output Deliverables**: After login, admin sees a polished dashboard with sidebar navigation (links to Content, Media, Users, Settings — pages not yet built return placeholders), content statistics from the database, and recent content items.

**Acceptance Tests**:
1. Dashboard loads after login with correct content counts (0 for fresh install)
2. Sidebar navigation renders with all menu items; active item is highlighted
3. Layout is responsive — works on mobile (sidebar collapses)
4. Admin layout includes CSP headers (`Content-Security-Policy`, `X-Frame-Options: DENY`)
5. Username and logout link appear in the top bar

---

### Chunk 2.2: Content CRUD (Pages & Posts)

**Description**: Build the full content management interface — listing pages/posts with search, filtering, and pagination; creating and editing content with TinyMCE WYSIWYG editor; slug auto-generation; draft/publish/archive workflow; and bulk actions.

**Input Prerequisites**: Chunk 2.1 complete (admin layout, dashboard, CSS)

**Key Files to Create**:
- `app/Admin/ContentController.php` — Full CRUD: index (list), create, store, edit, update, delete, bulk actions
- `templates/admin/content/index.php` — Content list (sortable table, search, type/status filters, pagination)
- `templates/admin/content/edit.php` — Content editor (TinyMCE, slug field, status selector, meta fields, featured image)
- `public/assets/js/editor.js` — TinyMCE initialization and configuration (CDN load with fallback)

**Features**:
- Content listing with search by title, filter by type (page/post) and status (draft/published/archived)
- Pagination (configurable items_per_page from config)
- Slug auto-generation from title (JavaScript), with manual override
- TinyMCE WYSIWYG editor for body field
- Draft/Publish/Archive status workflow
- Scheduled publishing (set published_at to a future date)
- SEO fields: meta_title, meta_description
- Featured image selection (text input for now; media browser comes in Chunk 2.3)
- Bulk actions: delete selected, change status of selected

**Output Deliverables**: Admin can create, edit, list, search, filter, paginate, and delete pages and blog posts. Content is persisted in the database with all fields.

**Acceptance Tests**:
1. Create a new page with title, body (via TinyMCE), and publish it — appears in content list
2. Slug is auto-generated from title; editing slug manually persists the custom slug
3. Filter content list by type "page" — only pages shown; filter by status "draft" — only drafts shown
4. Search for a content item by title — correct results returned
5. Change a published item to "archived" via edit form — status updates in database
6. Bulk select 3 items, delete — all 3 removed from database
7. Create a post with published_at in the future — status logic handles scheduling

---

### Chunk 2.3: Media Management

**Description**: Build the media upload system and library browser — file upload with validation (type whitelist, MIME check, size limit), storage with randomized filenames, media library grid view, and deletion. Integrate media selection into the content editor.

**Input Prerequisites**: Chunk 2.2 complete (content CRUD, editor working)

**Key Files to Create**:
- `app/Admin/MediaController.php` — Upload, list (with pagination), delete, serve
- `templates/admin/media/index.php` — Media library (grid view with thumbnails, upload form)
- Update `templates/admin/content/edit.php` — Add media browser modal for featured image selection
- Update `public/assets/js/editor.js` — TinyMCE image upload integration
- `public/assets/uploads/.htaccess` — Disable script execution in uploads directory

**Security in this chunk**:
- Whitelist extensions: jpg, jpeg, png, gif, webp, pdf
- MIME validation with `finfo_file()`
- Rename uploaded files to random hash (preserving extension)
- Disable execution in uploads directory via .htaccess
- Max file size validation (configurable)

**Output Deliverables**: Admin can upload images/PDFs, browse the media library in a grid, delete media items. When editing content, TinyMCE allows inserting images from the media library. Featured image can be selected from the media browser.

**Acceptance Tests**:
1. Upload a JPG image — file saved to `public/assets/uploads/` with hashed filename, record in media table
2. Upload a `.php` file — rejected with error message
3. Upload a file with faked extension (`.jpg` but actually a PHP file) — rejected by MIME check
4. Media library grid displays uploaded images with thumbnails
5. Delete a media item — file removed from disk, record removed from database
6. In content editor, insert an image from media library into TinyMCE — image appears in editor

---

### Chunk 2.4: User Management

**Description**: Build the user management interface (admin-only) — list users, create new users, edit user details, change roles, delete users. Includes password change functionality.

**Input Prerequisites**: Chunk 2.1 complete (admin layout); Auth system from Phase 1

**Key Files to Create**:
- `app/Admin/UserController.php` — CRUD for users (index, create, store, edit, update, delete)
- `templates/admin/users/index.php` — User list table
- `templates/admin/users/edit.php` — User create/edit form (username, email, password, role)

**Features**:
- List all users with role badges
- Create new users (admin only) with role assignment (admin/editor)
- Edit user profile (username, email, role)
- Change password (requires current password for self, admin can reset others)
- Delete user (prevent deleting own account, reassign content to another user)
- Role-based access: only admins can access user management

**Output Deliverables**: Admin users can manage all users in the system. Editors cannot access user management pages.

**Acceptance Tests**:
1. Admin can create a new editor user — user appears in list, can log in
2. Admin can change a user's role from editor to admin
3. User can change own password (requires entering current password)
4. Attempting to delete own account shows error
5. Editor role user cannot access `/admin/users` — gets 403 or redirect
6. Deleting a user prompts for content reassignment

---

## Phase 3 — Public Site (Templates + Routing)

### Chunk 3.1: Template Engine & Front Controller

**Description**: Enhance the template engine with full layout/partial support, SEO meta tag injection, and auto-generated navigation. Build the front controller that maps public URLs to content from the database and renders the appropriate template.

**Input Prerequisites**: Phase 2 complete (content exists in database, template engine basics from Phase 1)

**Key Files to Create/Modify**:
- `app/Templates/FrontController.php` — Routes public URLs: `/` → homepage, `/blog` → blog listing, `/blog/{slug}` → blog post, `/{slug}` → page, fallback → 404
- Update `app/Templates/TemplateEngine.php` — Add partials support, section/yield blocks, auto-escaping helper, navigation builder, SEO meta helper
- `templates/public/layout.php` — Base HTML5 layout (head with SEO meta/OG tags, nav, main content area, footer)
- `templates/public/404.php` — Not found page

**Features**:
- URL-to-content mapping: slugs map to published content items
- Navigation auto-generated from published pages (sorted by sort_order)
- SEO: meta title/description from content fields, Open Graph tags, canonical URLs
- Breadcrumbs auto-generated from URL structure
- Layout system: templates extend layout, define sections
- Only published content is visible (draft/archived hidden from public)
- Scheduled posts: only shown if `published_at <= now`

**Output Deliverables**: Public URLs resolve to content from the database. A base layout wraps all public pages with navigation, SEO meta, and footer. Unpublished content returns 404.

**Acceptance Tests**:
1. Create a published page with slug "about" — visiting `/about` renders the page content
2. A draft page is not accessible publicly — returns 404
3. Navigation shows all published pages in sort_order
4. Page source includes correct meta title, description, Open Graph tags
5. Visiting a non-existent slug returns the 404 page
6. A post with `published_at` in the future is not visible on the public site

---

### Chunk 3.2: Public Templates & Styling

**Description**: Build all remaining public-facing page templates (home, page, blog index, blog post, contact, archive), the complete public CSS stylesheet, an EU cookie consent banner (GDPR/ePrivacy compliance), and conditional Google Analytics script injection. This makes the public site fully presentable and legally compliant out of the box.

**Input Prerequisites**: Chunk 3.1 complete (front controller, layout, navigation working)

**Key Files to Create**:
- `templates/public/home.php` — Homepage (hero section, featured content, CTA)
- `templates/public/page.php` — Generic page (title + body + featured image)
- `templates/public/blog-index.php` — Blog listing with pagination and excerpt cards
- `templates/public/blog-post.php` — Single blog post (title, meta info, featured image, body, author)
- `templates/public/contact.php` — Contact page with form (stores submissions or sends email via `mail()`)
- `templates/public/archive.php` — Generic content type listing
- `templates/public/partials/cookie-consent.php` — Cookie consent banner partial (included in layout)
- `public/assets/css/style.css` — Complete public site stylesheet (mobile-first responsive, semantic HTML5)
- `public/assets/js/cookie-consent.js` — Cookie consent logic (accept/decline, remembers choice, triggers GA load)

**Features**:
- Homepage with configurable hero section (from settings or a specific page)
- Blog index with excerpt cards, pagination, and publish date
- Blog post with author name, publish date, featured image, full body
- Contact form with CSRF protection (submissions stored in a simple table or emailed)
- Responsive mobile-first design using CSS custom properties
- All templates work without JavaScript for core functionality
- **EU Cookie Consent Banner**: Fixed-position banner shown to first-time visitors with Accept/Decline buttons. Choice stored in a cookie (e.g., `litecms_consent=accepted|declined`, 365-day expiry). Banner does not reappear after choice is made. Consent text configurable from admin settings (Chunk 5.2). No tracking cookies set until user explicitly accepts.
- **Google Analytics (conditional)**: If GA is enabled in admin settings and a GA Measurement ID is configured (Chunk 5.2), the gtag.js script is injected into the public layout — but ONLY after the user accepts cookies via the consent banner. If consent is declined or not yet given, no GA scripts are loaded. Uses Google's gtag.js loaded from CDN.

**Output Deliverables**: A fully styled, responsive public website with GDPR-compliant cookie consent. Visitors can browse the homepage, read pages, browse blog posts with pagination, read individual posts, and submit a contact form. Google Analytics tracking activates only after explicit consent.

**Acceptance Tests**:
1. Homepage renders with hero section and featured/recent content
2. Blog index shows published posts with pagination (e.g., 5 per page)
3. Blog post page displays title, author, date, featured image, and full body
4. Contact form submits successfully with CSRF protection; submission is stored/emailed
5. Site is responsive — renders correctly on mobile viewport (375px wide)
6. All pages use semantic HTML5 and pass basic accessibility checks (headings hierarchy, alt text)
7. First visit shows cookie consent banner; no GA script in page source before acceptance
8. Clicking "Accept" dismisses banner, sets consent cookie, and loads GA script (if GA is configured)
9. Clicking "Decline" dismisses banner, sets consent cookie, GA script is NOT loaded
10. Returning visitor who already accepted — no banner shown, GA loads automatically

---

## Phase 4 — AI Assistant Integration

### Chunk 4.1: Claude API Client & Backend

**Description**: Build the Claude API client (raw PHP curl, no SDK), conversation manager for persisting chat history per content item, and the AI controller that handles chat requests from the editor. Includes API key encryption at rest.

**Input Prerequisites**: Phase 2 complete (content editor exists, settings table exists)

**Key Files to Create**:
- `app/AIAssistant/ClaudeClient.php` — Thin wrapper around Claude Messages API (curl-based, handles auth headers, streaming optional, error handling)
- `app/AIAssistant/ConversationManager.php` — CRUD for ai_conversations table (create, append message, retrieve history per content item)
- `app/AIAssistant/AIController.php` — POST `/admin/ai/chat` endpoint: receives message + content context, calls Claude API, returns response, persists conversation
- `app/Admin/SettingsController.php` — Settings page with API key field (encrypt on save, decrypt on read)

**Features**:
- Claude Messages API integration (POST to `https://api.anthropic.com/v1/messages`)
- System prompt includes content context (type, title, current body excerpt)
- Conversation history sent with each request for context continuity
- API key stored encrypted in settings table (openssl_encrypt with app secret)
- Configurable model (default: claude-sonnet-4-20250514)
- Graceful error handling (network errors, API errors, missing key)
- Rate awareness (don't send if key is missing, show helpful message)

**Output Deliverables**: A working backend that accepts chat messages, calls Claude API with content context, returns AI responses, and persists conversation history. API key is securely stored.

**Acceptance Tests**:
1. Save Claude API key in settings — stored encrypted in database (not plain text)
2. POST to `/admin/ai/chat` with a message and content_id — returns Claude's response as JSON
3. Conversation history is persisted in ai_conversations table
4. Subsequent messages in the same conversation include prior history in the API call
5. With invalid/missing API key, endpoint returns a user-friendly error message (not a crash)
6. Settings page allows configuring the model name

---

### Chunk 4.2: AI Chat Panel Frontend

**Description**: Build the frontend chat panel UI integrated into the content editor — a collapsible side panel with message display, input field, "Insert into editor" functionality, and conversation history loading.

**Input Prerequisites**: Chunk 4.1 complete (AI backend endpoints working)

**Key Files to Create**:
- `public/assets/js/ai-assistant.js` — Chat panel logic (fetch API calls, message rendering, insert-to-editor, conversation loading)
- Update `templates/admin/content/edit.php` — Add collapsible AI assistant side panel
- Update `public/assets/css/admin.css` — AI panel styles (split view: editor + chat panel)

**Features**:
- Collapsible side panel (toggle button in editor toolbar)
- Chat interface: message bubbles (user/assistant), auto-scroll, loading indicator
- Text input with send button (Enter to send, Shift+Enter for newline)
- "Insert" button on each AI response — inserts HTML into TinyMCE at cursor or appends
- "Replace" button — replaces current editor content with AI response
- Conversation history loaded when editing existing content
- Current editor content sent as context with each message
- Error display (API errors, missing key with link to settings)

**Output Deliverables**: When editing content, the AI assistant panel is available. Users can chat with Claude, get writing suggestions, and insert/replace editor content with AI-generated text.

**Acceptance Tests**:
1. Toggle AI panel — panel opens/closes; editor width adjusts
2. Send a message — AI response appears in chat bubbles with loading indicator
3. Click "Insert" on an AI response — HTML is inserted into TinyMCE editor
4. Navigate away and back to same content — conversation history is restored
5. With no API key configured — panel shows a message with link to settings
6. Send multiple messages — conversation context is maintained (AI references earlier messages)

---

## Phase 5 — Custom Content Types + Polish

### Chunk 5.1: Custom Content Types

**Description**: Build the system for defining custom content types with custom fields. Admin can create new content types (e.g., "Products", "Team Members") with configurable fields (text, textarea, image, select, boolean). Content of custom types uses the same CRUD as pages/posts but with additional custom fields.

**Input Prerequisites**: Phases 1-3 complete (content CRUD, public templates, archive template)

**Key Files to Create**:
- `app/Admin/ContentTypeController.php` — CRUD for content types (define type slug, name, fields)
- `templates/admin/content-types/index.php` — List of custom content types
- `templates/admin/content-types/edit.php` — Content type editor (name, slug, field definitions with drag-reorder)
- Update `app/Admin/ContentController.php` — Support custom fields when editing content of custom types
- Update `templates/admin/content/edit.php` — Render custom fields below the main editor based on content type
- Update `templates/public/archive.php` — Render archive listing for custom content types

**Features**:
- Define custom content types with: slug, display name, has_archive flag
- Define custom fields per type: field key, label, type (text, textarea, image, select, boolean), required flag, options (for select)
- Fields stored as JSON in content_types.fields_json
- Field values stored in custom_fields table (key-value per content item)
- Content list filterable by custom type
- Public archive page for custom types (e.g., `/products/` lists all published products)

**Output Deliverables**: Admin can define new content types with custom fields. Content of those types can be created/edited with the custom fields rendered in the editor. Public archive pages show custom type listings.

**Acceptance Tests**:
1. Create a custom content type "Products" with fields: price (text), description (textarea), featured (boolean)
2. Create a new content item of type "Products" — custom fields appear in the editor
3. Save content with custom field values — values persisted in custom_fields table
4. Content list can be filtered to show only "Products" type
5. Public archive at `/products/` shows all published products
6. Delete a content type — prompts about existing content, handles gracefully

---

### Chunk 5.2: Settings Panel & Site Configuration

**Description**: Build the comprehensive settings panel — site name, URL, timezone, items per page, AI configuration, and other site-wide settings. Settings are stored in the database and override config file defaults.

**Input Prerequisites**: Admin layout complete (Phase 2), settings table exists

**Key Files to Create/Modify**:
- `app/Admin/SettingsController.php` — Full settings page (create if not done in Chunk 4.1, or expand it)
- `templates/admin/settings.php` — Settings form (grouped sections: General, AI, SEO, Advanced)
- Update `app/Core/Config.php` — Settings from DB override file-based config

**Settings sections**:
- General: site name, site URL, tagline, timezone, items per page
- AI: Claude API key (encrypted), model selection, system prompt customization
- SEO: default meta description, Open Graph default image
- **Cookie Consent & Analytics**: enable/disable cookie consent banner, consent banner text (customizable message), consent banner link (e.g., link to privacy policy page), Google Analytics enable/disable toggle, GA Measurement ID field (e.g., `G-XXXXXXXXXX`)
- **Contact Form**: notification email address (when set, new contact submissions trigger an email via `mail()`)
- Advanced: enable/disable registration, maintenance mode

**Output Deliverables**: Admin can configure all site settings through a web interface, including cookie consent text and Google Analytics integration. Settings persist in the database and take effect immediately.

**Acceptance Tests**:
1. Change site name in settings — public site reflects the new name immediately
2. Change items_per_page — blog listing pagination adjusts accordingly
3. API key field shows masked value (not the actual key)
4. Timezone setting affects displayed dates on the public site
5. Settings survive application restart (persisted in database, not session)
6. Enable Google Analytics and enter a Measurement ID — GA script appears on public site (after cookie consent)
7. Disable Google Analytics toggle — GA script no longer injected regardless of consent
8. Change cookie consent banner text — updated text appears on public site for new visitors
9. Setting a contact notification email address saves correctly and is retrievable via Config

---

### Chunk 5.3: AI Page Generator

**Description**: Build a conversational AI agent flow accessible from the admin panel that guides users through creating a new webpage. The agent asks iterative questions (purpose, sections, style, content details), generates HTML content and metadata, and inserts a complete content record into the database. The result is immediately editable via the standard content editor and AI chat companion.

**Input Prerequisites**: Chunks 4.1, 5.1, 5.2 complete (Claude API, custom types, settings)

**Key Files to Create**:
- `app/AIAssistant/PageGeneratorController.php` — Multi-step wizard endpoint (manages conversation state, calls Claude, creates content record on completion)
- `app/AIAssistant/GeneratorPrompts.php` — System prompts for the generator (requirement gathering prompt, HTML generation prompt, metadata extraction prompt)
- `templates/admin/generator/index.php` — Generator wizard UI (chat-style interface with progress steps, preview pane, "Create Page" button)
- `public/assets/js/page-generator.js` — Frontend logic (step management, API calls, live preview, content creation trigger)
- Update `templates/admin/layout.php` — Add "Generate Page" link to sidebar nav

**Features**:
- Conversational requirement gathering (what's the page for, what sections, what content, any style preferences)
- Smart iteration — agent identifies missing info and asks follow-up questions
- Supports all content types (pages, posts, and custom types from 5.1)
- Generates: title, slug, HTML body, excerpt, meta_title, meta_description
- Optionally generates custom field values for custom content types
- Live HTML preview before committing
- "Create as Draft" and "Create & Publish" options
- Generated content opens in the standard editor afterward for refinement
- Conversation context includes site name, existing pages (for nav consistency), and content type field definitions

**Output Deliverables**: Admin can click "Generate Page", have a guided conversation with the AI about what they need, preview the result, and create a fully-formed content record that's immediately editable in the standard editor.

**Acceptance Tests**:
1. Start generator, describe a simple "About Us" page — AI asks clarifying questions (company name, key info, tone)
2. After providing info, AI generates complete HTML with title, slug, excerpt, SEO fields
3. Preview shows rendered HTML before creation
4. Click "Create as Draft" — content record appears in content list as draft
5. Open the created page in editor — all fields populated, body editable in TinyMCE
6. Generator works with custom content types — custom fields are populated
7. Generated HTML is clean, semantic, and uses no inline styles (works with the site's CSS)

---

---

## Phase 6 — Element-Based Page Builder

### Chunk 6.1: Element Catalogue & Rendering Engine

**Description**: Build the database schema for reusable UI elements, the micro-mustache template engine (SlotRenderer) for rendering element templates with typed content slots, the PageRenderer for assembling element-based pages, the admin CRUD for managing the element catalogue, and seed the catalogue with 7 starter elements.

**Input Prerequisites**: Phases 1–5 complete (content CRUD, front controller, AI integration, custom types)

**Key Files Created**:
- `migrations/004_page_builder.sqlite.sql` — Elements, page_elements, element_proposals tables + editor_mode column
- `migrations/004_page_builder.mysql.sql` — MySQL variant
- `migrations/004_page_builder.pgsql.sql` — PostgreSQL variant
- `app/PageBuilder/SlotRenderer.php` — Micro-mustache template engine (`{{key}}`, `{{{key}}}`, `{{#key}}...{{/key}}`, `{{^key}}...{{/key}}`, `{{key.sub}}`)
- `app/PageBuilder/PageRenderer.php` — Renders element-based pages, collects scoped CSS
- `app/PageBuilder/SeedElements.php` — 7 starter elements (hero-section, text-section, feature-grid, cta-banner, image-text, testimonial-section, faq-section)
- `app/Admin/ElementController.php` — Full CRUD + preview API + apiList JSON endpoint
- `templates/admin/elements/index.php` — Catalogue grid view with category filters
- `templates/admin/elements/edit.php` — Element editor (meta, slots, HTML/CSS code editors, live preview)
- `public/assets/js/element-editor.js` — Slot builder UI + live preview

**Key Files Modified**:
- `app/Templates/FrontController.php` — Element-mode rendering branch in `page()` and `blogPost()`
- `templates/public/layout.php` — `<style id="litecms-element-styles">` block in `<head>`
- `public/index.php` — Element admin routes (CRUD + preview + apiList)
- `templates/admin/layout.php` — "Elements" nav item
- `public/assets/css/admin.css` — Element catalogue grid, editor layout, slot builder, code editor styles

**Slot Type System**: text, richtext, image, link, select, boolean, number, list (with sub_slots)

**Routes**:
```
GET    /admin/elements              → ElementController::index
GET    /admin/elements/create       → ElementController::create
POST   /admin/elements              → ElementController::store
GET    /admin/elements/{id}/edit    → ElementController::edit
GET    /admin/elements/{id}/preview → ElementController::preview
PUT    /admin/elements/{id}         → ElementController::update
DELETE /admin/elements/{id}         → ElementController::delete
GET    /admin/elements/api/list     → ElementController::apiList
```

**Output Deliverables**: Admin can create, edit, preview, and delete elements in the catalogue. Elements have HTML templates with typed content slots and scoped CSS. The front controller renders element-based pages (when `editor_mode = 'elements'`). 7 seed elements provide a starter kit.

**Acceptance Tests** (31 tests in `tests/chunk-6.1-verify.php`):
1. All PageBuilder classes autoloadable
2. Migration creates tables + editor_mode column
3. SeedElements populates 7 elements (idempotent)
4. SlotRenderer: escaping, raw HTML, conditionals, loops, inverted sections, dot notation
5. PageRenderer: instance wrapping, full page assembly with CSS
6. ElementController CRUD (create, read, update, delete, preview, apiList)
7. Validation (duplicate slot keys, slot JSON structure)
8. FrontController element-mode branch, public layout, admin nav, routes, CSS, JS

---

### Chunk 6.2: Content Editor Element Mode & Page Builder UI

**Description**: Add element-based editing mode to the content editor. When `editor_mode = 'elements'`, the editor shows a page builder panel instead of TinyMCE — with an element picker, slot data forms auto-generated from slot definitions, drag-and-drop reordering, and JSON serialization of page composition.

**Input Prerequisites**: Chunk 6.1 complete (element catalogue, SlotRenderer, PageRenderer)

**Key Files to Create**:
- `public/assets/js/page-builder.js` — Page builder UI: element picker modal, slot forms by type, drag-drop reorder, JSON serialization to hidden input

**Key Files to Modify**:
- `templates/admin/content/edit.php` — Editor mode toggle (HTML / Elements), page builder panel
- `app/Admin/ContentController.php` — Handle `editor_mode` in `readFormData()`, save/load `page_elements` in `store()`/`update()`/`edit()`
- `public/assets/css/admin.css` — Page builder styles (element cards, drag handles, picker modal, slot forms)

**Features**:
- Editor mode toggle: radio buttons — "HTML Editor" | "Page Builder"
- Page builder panel: ordered list of element instances as collapsible cards
- "Add Element" button → catalogue picker modal (searchable, categorized)
- Drag handle per element for reordering, remove button
- Slot forms auto-generated from `slots_json` (text input, textarea, media browser, link fields, select, checkbox, number, repeatable list groups)
- All data serialized to `elements_json` hidden input on form submit
- ContentController saves/loads `page_elements` rows with correct `sort_order` and `slot_data_json`

**Output Deliverables**: Admin can toggle between HTML editor and page builder when editing content. Page builder lets users compose pages from catalogue elements, fill slot data via auto-generated forms, reorder elements, and save. Existing HTML-mode content is unaffected.

**Acceptance Tests**:
1. Editor mode toggle switches UI panels
2. Element picker shows catalogue (searchable, categorized)
3. Slot forms render correctly for all slot types
4. Saving persists `page_elements` rows with correct sort_order and slot_data_json
5. Loading editor restores all element instances with filled slot data
6. Existing HTML-mode content completely unaffected

---

### Chunk 6.3: Per-Instance Element Styling

**Description**: Add Elementor-like style controls to the page builder. Each element instance gets a "Style" tab with GUI controls for spacing, background, typography, border, effects, and layout — plus a **Custom CSS** textarea for freeform CSS (scoped to the instance). Page-level wrappers (page-body, container, site-main) are also stylable with both GUI controls and custom CSS. A new `StyleRenderer` class handles CSS generation, scoping, and sanitization server-side. The custom CSS fields are the primary integration point for AI-driven styling in Chunk 6.4.

**Input Prerequisites**: Chunk 6.2 complete (page builder UI with element instances)

**Key Files to Create**:
- `migrations/005_element_styles.sqlite.sql` — Add `style_data_json` column to `page_elements`, create `page_styles` table
- `migrations/005_element_styles.mysql.sql` — MySQL variant
- `migrations/005_element_styles.pgsql.sql` — PostgreSQL variant
- `app/PageBuilder/StyleRenderer.php` — CSS generation + sanitization (buildInlineStyle, buildPageLayoutCss, sanitizeStyleData)
- `public/assets/js/page-styles-init.js` — Page-level style controls for layout wrappers

**Key Files to Modify**:
- `app/PageBuilder/PageRenderer.php` — Apply inline styles on wrapper divs, new `getPageLayoutCss()` method
- `app/Admin/ContentController.php` — Save/load `style_data_json` per instance, save/load page-level styles
- `app/Templates/FrontController.php` — Append page layout CSS to element CSS
- `public/assets/js/page-builder.js` — Tab system (Content/Style), style panel with accordion sections, serialize styleData
- `public/assets/js/page-builder-init.js` — Toggle page styles card visibility
- `templates/admin/content/edit.php` — Page Layout Styles sidebar card
- `public/assets/css/admin.css` — Tab bar, style panel, control styles

**Style Properties**: margin, padding, background (color/image), text color/size/align/weight, border (width/style/color/radius), box-shadow, opacity, max-width, min-height, custom CSS class, **custom CSS** (freeform per instance + per layout target)

**Security**: `StyleRenderer::sanitizeStyleData()` whitelists GUI properties; `sanitizeCustomCss()` strips XSS vectors (`@import`, `javascript:`, `</style>`, `<script>`); `scopeCustomCss()` isolates custom rules to the instance wrapper

**CSS Specificity Cascade**: Catalogue CSS → inline GUI styles → scoped custom CSS (each layer overrides the previous)

**Output Deliverables**: Each element instance in the page builder has a Style tab with visual controls and a Custom CSS textarea. Per-instance GUI styles render as inline `style` attributes. Per-instance custom CSS renders scoped in a `<style>` block. Page-level wrapper styles (GUI + custom CSS) render as a `<style>` block. All CSS values are sanitized server-side.

**Acceptance Tests**:
1. Migration applies cleanly (style_data_json column, page_styles table)
2. StyleRenderer::buildInlineStyle() generates correct CSS
3. StyleRenderer::sanitizeStyleData() blocks injection patterns
4. StyleRenderer::buildPageLayoutCss() generates valid CSS rules
5. ContentController saves/loads style_data alongside slot_data
6. ContentController saves/loads page_styles
7. PageRenderer::renderInstance() emits inline styles + data-instance-id on wrapper div
8. PageRenderer::getPageLayoutCss() returns CSS for page wrappers
9. Custom CSS per instance renders scoped in `<style>` block
10. Custom CSS sanitization blocks XSS, preserves legitimate CSS
11. Custom CSS scoping prefixes all rules with instance selector
12. page-builder.js has style-related functions (tab system, style panel, custom CSS textarea)

---

### Chunk 6.4: AI Element Integration

**Description**: Make the AI agent aware of the element catalogue — injecting catalogue context into generation prompts, generating element-based pages (reusing existing elements or proposing new ones), and providing an approval flow for AI-proposed elements.

**Input Prerequisites**: Chunk 6.3 complete (styled page builder), Chunk 5.3 complete (AI Page Generator)

**Key Files to Create**:
- `templates/admin/elements/proposals.php` — Proposal review UI

**Key Files to Modify**:
- `app/AIAssistant/GeneratorPrompts.php` — Add `formatElementCatalogue()`, `elementGenerationPrompt()`
- `app/AIAssistant/PageGeneratorController.php` — Handle element-based generation, create proposals for new elements
- `app/AIAssistant/AIController.php` — Include element catalogue in assistant system prompt
- `app/Admin/ElementController.php` — Add proposal review endpoints (list, approve, reject)
- `templates/admin/generator/index.php` — Editor mode toggle in step 1 (HTML vs Elements)
- `public/assets/js/page-generator.js` — Handle element-based preview with new element proposals
- `public/index.php` — Proposal routes

**AI Generation Format** (element mode):
```json
{
  "editor_mode": "elements",
  "elements": [
    {"element_slug": "hero-section", "slot_data": {"title": "Welcome"}},
    {"element_slug": "__new__", "new_element": {"name": "Team Section", "slug": "team-section", ...}, "slot_data": {...}}
  ]
}
```
- `element_slug` = existing slug → reuse from catalogue
- `element_slug` = `__new__` → AI proposes new element → goes to `element_proposals` table

**Routes**:
```
GET    /admin/element-proposals              → ElementController::proposals
POST   /admin/element-proposals/{id}/approve → ElementController::approveProposal
POST   /admin/element-proposals/{id}/reject  → ElementController::rejectProposal
```

**Output Deliverables**: AI page generator can produce element-based pages that reuse catalogue elements or propose new ones. Proposals go through an approval flow before entering the catalogue. The AI assistant in the content editor is also aware of available elements.

**Acceptance Tests**:
1. AI generation prompt includes element catalogue context
2. AI output with existing elements creates correct page_elements
3. AI output with `__new__` elements creates proposals in element_proposals table
4. Proposal approval creates element in catalogue
5. Full round-trip: generate → preview → create → view on frontend
6. HTML-mode generation completely unaffected

---

## Phase 7 — Final Polish

### Chunk 7.1: Final Polish, Error Handling & Documentation

**Description**: Final pass over the entire codebase — comprehensive error handling and logging, input validation tightening, performance verification, security audit, and production of README.md with installation guide.

**Input Prerequisites**: All previous chunks complete (Phases 1–6, including Chunk 6.4)

**Key Files to Create/Modify**:
- `app/Admin/ContactSubmissionsController.php` — List submissions with pagination, view individual submission, delete
- `templates/admin/contact-submissions/index.php` — Submissions list table (name, email, subject, date, truncated message)
- `templates/admin/contact-submissions/view.php` — Full submission detail view
- Update `templates/admin/layout.php` — Add "Messages" link to sidebar nav
- Update `public/index.php` — Register admin routes for contact submissions
- `storage/logs/` — Error logging implementation (file-based, rotation)
- Add error handling throughout: try/catch in controllers, user-friendly error pages, logging
- `templates/public/error.php` — Generic error page
- `README.md` — Project description, requirements, installation steps, usage guide
- `composer.json` — Final review of dependencies (ensure <= 10 packages)
- Review all files for: missing input validation, unescaped output, error edge cases

**Contact Submissions Admin UI**:
- List view at `/admin/contact-submissions` with pagination, sorted newest-first
- Each row shows: name, email, subject, date, truncated message preview
- Click to view full submission detail
- Delete individual submissions (with confirmation)
- Admin-only access (auth + role middleware)

**Contact Form Email Notification**:
- Update `FrontController::contactSubmit()` — after storing submission, if `contact_notification_email` setting is configured, send a notification email via `mail()`
- Email contains: sender name, email, subject, message, timestamp
- Fail silently if `mail()` fails (log the error, don't break the user-facing flow)

**Polish checklist**:
- All form inputs have server-side validation (not just client-side)
- All database errors are caught and logged (not shown to users)
- 404 and 500 error pages are styled and user-friendly
- All admin actions have success/error flash messages
- Page render time is under 50ms (test on a baseline)
- Total codebase is under 5,000 lines of PHP
- Total project size is under 5MB (excluding uploads)
- No TODO/FIXME comments remain in code

**Output Deliverables**: A production-ready, polished CMS. README with complete installation guide. All error cases handled gracefully. Performance within spec.

**Acceptance Tests**:
1. Fresh install: `git clone` -> `composer install` -> edit config -> visit URL -> setup works with no errors
2. Trigger a database error (e.g., corrupt query) — error is logged, user sees friendly error page
3. PHP error reporting set to off in production — no error details leaked to users
4. Load test: homepage renders in under 50ms (use `microtime()` measurement)
5. Run `find app/ -name '*.php' | xargs wc -l` — total under 5,000 lines
6. README covers: requirements, installation, configuration, first-time setup, usage basics
7. Contact submissions list at `/admin/contact-submissions` shows submitted messages with pagination
8. Viewing a single submission shows all fields (name, email, subject, message, IP, date)
9. Deleting a submission removes it from the database
10. With `contact_notification_email` set, submitting the contact form triggers an email (or logs gracefully if `mail()` is unavailable)

---

## Summary: Chunk Dependency Graph

```
1.1 Scaffolding & Core
 └── 1.2 Database Layer
      └── 1.3 Authentication
           └── 2.1 Admin Layout & Dashboard
                ├── 2.2 Content CRUD ──────┐
                │    └── 2.3 Media ────────┤ (parallel group B)
                ├── 2.4 User Management    │ (parallel with 2.2)
                └── 3.1 Front Controller ──┘
                     └── 3.2 Public Templates ─┐
                          │                    │ (parallel group C)
                          4.1 AI Backend ──────┘
                               └── 4.2 AI Chat Panel Frontend
                                    └── 5.1 Custom Content Types
                                         └── 5.2 Settings Panel
                                              └── 5.3 AI Page Generator
                                                   └── 6.1 Element Catalogue
                                                        └── 6.2 Page Builder UI
                                                             └── 6.3 Per-Instance Element Styling
                                                                  └── 6.4 AI Element Integration
                                                                       └── 7.1 Final Polish & Docs
```

**Total: 19 chunks across 7 phases**

## Parallel Execution Strategy

Sequential chunks MUST be completed in order. Parallel groups can run simultaneously with separate agents on git branches, merging back to `main` when done.

### Execution Timeline

```
Step 1 (sequential):  1.1 → 1.2 → 1.3 → 2.1
Step 2 (parallel A):  2.2 + 2.4           ← both depend only on 2.1, no shared files
Step 3 (parallel B):  2.3 + 3.1           ← 2.3 depends on 2.2; 3.1 depends on 2.1
Step 4 (parallel C):  3.2 + 4.1           ← 3.2 is public frontend; 4.1 is admin backend
Step 5 (sequential):  4.2 → 5.1 → 5.2 → 5.3
Step 6 (sequential):  6.1 → 6.2 → 6.3 → 6.4  ← element catalogue, page builder UI, styling, AI integration
Step 7 (sequential):  7.1                      ← final polish after all features complete
```

### Why These Groups Are Safe

| Group | Agent A | Agent B | File Overlap | Conflict Risk |
|-------|---------|---------|-------------|---------------|
| A | 2.2: ContentController, content templates | 2.4: UserController, user templates | None — different controllers, templates, routes | Low |
| B | 2.3: MediaController, media templates, editor.js update | 3.1: FrontController, public templates | None — admin vs public | Low |
| C | 3.2: Public CSS, public templates, cookie-consent | 4.1: ClaudeClient, AIController, ConversationManager | None — public frontend vs admin backend | Low |

### Coordination Mechanism

When running parallel agents, use lock files to claim chunks:

```
current_tasks/
  chunk-2.2.lock   # "agent-1, started 2026-02-07T10:00:00Z, branch: chunk/2.2"
  chunk-2.4.lock   # "agent-2, started 2026-02-07T10:00:00Z, branch: chunk/2.4"
```

Each agent:
1. Creates a lock file before starting work
2. Works on a dedicated git branch (`chunk/X.X`)
3. Runs `php tests/run-all.php --full` before merging
4. Merges to `main` and removes the lock file
5. If merge conflicts occur, the second agent resolves them

### When NOT to Parallelize

- Phase 1 (1.1 → 1.2 → 1.3): Each chunk directly extends the previous. Must be sequential.
- Step 5 (4.2 → 5.1 → 5.2 → 5.3): Heavy cross-cutting concerns. Must be sequential.
- If unsure about file overlap: run sequentially. The time saved by parallelism is lost if agents overwrite each other.

## Verification Strategy

After each chunk implementation, the agent will:
1. Run `php tests/chunk-X.X-verify.php` — all tests must show `[PASS]`
2. Run `php tests/run-all.php --full` — cumulative regression check, all previous chunks must still pass
3. Check that `composer install` still works cleanly
4. Confirm code follows the constraints: strict_types, no framework imports, parameterized queries

Test scripts live in `tests/` with standardized output:
- `[PASS] Description` — test passed
- `[FAIL] Description — reason` — test failed (agent must fix before proceeding)
- `[SKIP] Description` — test skipped (e.g., optional dependency not available)

Agents should use `--quick` mode during iterative development and `--full` before declaring a chunk complete.
