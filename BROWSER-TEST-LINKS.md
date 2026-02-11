# LiteCMS — Browser Test Links

## Starting the Dev Server

From the project root, run:

```bash
php -S localhost:8000 -t public
```

This starts PHP's built-in development server with `public/` as the document root.

> **First request will auto-run migrations** — the SQLite database file (`storage/database.sqlite`) is created automatically.

---

## Available Pages (Chunks 1.1 + 1.2 + 1.3 + 2.1 + 2.2 + 2.3 + 2.4 + 3.1 + 3.2 + 4.1 + 4.2 + 5.1 + 5.2 + 5.3 + 6.1 + 6.2 + 6.3 + 6.4 + 7.1 + 7.2)

| # | URL | Expected Result |
|---|-----|-----------------|
| 1 | [http://localhost:8000/](http://localhost:8000/) | Public homepage — hero section with tagline and CTA button, styled recent blog posts as post cards, mobile-first responsive CSS |
| 2 | [http://localhost:8000/admin/login](http://localhost:8000/admin/login) | Login page — centered card with username/password form and CSRF token |
| 3 | [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard) | Admin dashboard — styled sidebar nav, topbar, stats cards (Total Content, Published, Drafts, Users, Media Files), and recent content table. Redirects to `/admin/login` if not authenticated |
| 4 | [http://localhost:8000/admin/content](http://localhost:8000/admin/content) | Content list — filterable/searchable table with type and status filters, pagination, bulk actions, and "+ New Content" button |
| 5 | [http://localhost:8000/admin/content/create](http://localhost:8000/admin/content/create) | Content editor (create mode) — two-column layout with title, slug, editor mode toggle (HTML/Page Builder), TinyMCE body editor, excerpt, and sidebar with publish/SEO/image fields. Page Builder mode shows element picker and instance cards |
| 6 | [http://localhost:8000/admin/content/create?type=post](http://localhost:8000/admin/content/create?type=post) | Content editor (create mode) — pre-selects "Post" type in the sidebar |
| 7 | [http://localhost:8000/admin/content/1/edit](http://localhost:8000/admin/content/1/edit) | Content editor (edit mode) — loads existing content, shows "Update" button instead of "Create", includes `_method=PUT` hidden field. Editor mode toggle (HTML/Page Builder) with persistent mode. AI Assistant toggle button in header opens chat panel as third column |
| 8 | [http://localhost:8000/admin/media](http://localhost:8000/admin/media) | Media library — upload form with drag & drop zone, media grid with thumbnails, pagination, delete buttons. Sidebar highlights "Media" |
| 8a | [http://localhost:8000/admin/media/browse?type=image](http://localhost:8000/admin/media/browse?type=image) | JSON endpoint — returns paginated list of image media items (used by media browser modal) |
| 9 | [http://localhost:8000/admin/users](http://localhost:8000/admin/users) | User list — searchable table with username, email, role badge, created date, edit/delete actions, pagination, and "+ New User" button. Admin-only (editors get 403) |
| 9a | [http://localhost:8000/admin/users/create](http://localhost:8000/admin/users/create) | Create user form — username, email, role select, password fields. Admin-only |
| 9b | [http://localhost:8000/admin/users/1/edit](http://localhost:8000/admin/users/1/edit) | Edit user form — pre-filled fields, `_method=PUT`, current password required for own password change, role field disabled when editing self |
| 10 | [http://localhost:8000/admin/settings](http://localhost:8000/admin/settings) | Settings page — six sections: General (site name, URL, tagline, timezone, items per page), SEO (meta description, OG image), Cookie Consent & Analytics (enable banner, consent text, privacy link, enable GA, measurement ID), Contact Form (notification email), AI Assistant (API key, model, parameters), Advanced (registration, maintenance mode). Admin-only; DB settings override file config; API key stored encrypted |
| 11 | [http://localhost:8000/blog](http://localhost:8000/blog) | Blog index — paginated listing of published posts with author, date, excerpts. Pagination via `?page=N` |
| 11a | [http://localhost:8000/blog/hello-world](http://localhost:8000/blog/hello-world) | Single blog post — full post content with author name, date, featured image, and article-type OG tags |
| 12 | [http://localhost:8000/about](http://localhost:8000/about) | Single page — renders published page by slug with SEO meta tags (create an "about" page first via admin) |
| 14 | [http://localhost:8000/contact](http://localhost:8000/contact) | Contact form — CSRF-protected form with name, email, subject, message fields. Submits via POST, stores in DB, shows success via PRG pattern |
| 15 | [http://localhost:8000/nonexistent](http://localhost:8000/nonexistent) | Styled 404 page — "404 — Page Not Found" with navigation and "Return to homepage" link |
| 16 | [http://localhost:8000/admin/content-types](http://localhost:8000/admin/content-types) | Content type list — table with name, slug, field count, content count, archive status, and action buttons. Empty state on fresh install with "Create your first content type" link |
| 16a | [http://localhost:8000/admin/content-types/create](http://localhost:8000/admin/content-types/create) | Create content type form — name, slug (auto-generated), has_archive checkbox, dynamic field builder (add/remove/reorder fields with key, label, type, required, options) |
| 16b | [http://localhost:8000/admin/content-types/1/edit](http://localhost:8000/admin/content-types/1/edit) | Edit content type form — pre-filled fields, field builder with existing fields loaded, delete button (if no content references the type) |
| 17 | [http://localhost:8000/admin/content/create?type=products](http://localhost:8000/admin/content/create?type=products) | Content editor with custom type — type dropdown shows "Products" selected, custom fields section below excerpt (after creating a "Products" content type) |
| 18 | [http://localhost:8000/products](http://localhost:8000/products) | Custom type archive — paginated listing of published "Products" content items (requires has_archive enabled and published items) |
| 18a | [http://localhost:8000/products/widget-pro](http://localhost:8000/products/widget-pro) | Single custom type item — renders published content by slug under the custom type URL pattern |
| 19 | [http://localhost:8000/admin/generator](http://localhost:8000/admin/generator) | AI Page Generator — 4-step wizard (Setup → Describe → Preview → Done) with content type selector, editor mode toggle (HTML/Elements), chat interface, preview pane, and create buttons |
| 20 | [http://localhost:8000/admin/elements/1/edit](http://localhost:8000/admin/elements/1/edit) | Element editor with AI Assistant — "AI Assistant" toggle button in header opens chat panel as third column; chat supports Apply HTML, Apply CSS, Apply Both, and Copy actions |
| 21 | [http://localhost:8000/admin/element-proposals](http://localhost:8000/admin/element-proposals) | Element proposals list — filter tabs (Pending/Approved/Rejected), proposal cards with name, category, description, collapsible HTML/CSS preview, approve/reject buttons |
| 22 | [http://localhost:8000/admin/design/editor](http://localhost:8000/admin/design/editor) | Design Editor — Pencil visual editor embedded in an iframe with file selector toolbar, new file input, loading overlay, and status indicator. Figma-like canvas for creating/editing `.pen` design files |
| 22a | [http://localhost:8000/admin/design/list](http://localhost:8000/admin/design/list) | JSON endpoint — returns list of `.pen` design files in the designs/ directory |
| 22b | [http://localhost:8000/admin/design/load?path=my-design.pen](http://localhost:8000/admin/design/load?path=my-design.pen) | JSON endpoint — returns `.pen` file content (used by the editor bridge) |
| 23 | `POST /admin/design/convert` | JSON endpoint — converts a `.pen` file to HTML+CSS. Body: `{"path":"filename.pen"}` or `{"json":"..."}`. Returns `{"success":true,"html":"...","css":"..."}` |
| 23a | [http://localhost:8000/admin/design/preview?path=my-design.pen](http://localhost:8000/admin/design/preview?path=my-design.pen) | Preview endpoint — renders `.pen` file conversion as standalone HTML page with CSS in `<style>` tag |

### Authentication Flow

1. On first visit, a default admin user is auto-created: **username=`admin`**, **password=`admin`**
2. Visiting any `/admin/*` route (except `/admin/login`) redirects to the login page if not authenticated
3. After login, you're redirected to `/admin/dashboard` which shows a polished admin panel with sidebar navigation, stats cards, and recent content table
4. POST `/admin/logout` destroys the session and redirects back to the login page

### Admin Panel (Chunk 2.1)

After logging in, the admin panel features:
- **Sidebar navigation** with 7 links: Dashboard, Content, Media, Content Types, Generate Page, Users, Settings — grouped by section (Main, Content, System)
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

### AI Chat Panel Frontend (Chunk 4.2)

The content editor now includes an AI chat panel:
- **AI toggle button** — "AI Assistant" button in the content editor page header (both create and edit modes)
- **Collapsible panel** — opens as a third column alongside the editor and sidebar; 3-column grid layout (`1fr 320px 380px`)
- **Chat interface** — message bubbles: user (blue, right-aligned), assistant (gray, left-aligned), with typing indicator animation
- **Action buttons** — each AI response has "Insert" (at cursor), "Replace" (full editor content with confirm dialog), and "Copy" (plain text to clipboard)
- **New conversation** — "New" button clears chat and starts fresh conversation
- **Conversation persistence** — conversations load from backend when editing existing content (`GET /admin/ai/conversations`)
- **Enter/Shift+Enter** — Enter sends message, Shift+Enter inserts newline
- **Loading state** — animated typing indicator (bouncing dots), send button disabled during requests
- **Error handling** — network errors and API key issues displayed as red error bubbles with link to /admin/settings
- **Mobile responsive** — panel becomes full-screen overlay on viewports <=768px
- **CSRF protection** — sends `X-CSRF-Token` header with all fetch requests
- **XSS safety** — user messages rendered via `textContent`, assistant messages via `innerHTML` (AI HTML responses)

### Custom Content Types (Chunk 5.1)

The custom content types system (`/admin/content-types`) provides:
- **Content type list** — table with name, slug, field count, content item count, archive status, and edit/delete actions
- **Create/edit form** — name, auto-generated slug, has_archive toggle, and dynamic field builder
- **Field builder** — JavaScript-powered UI to add, remove, and reorder custom fields with key, label, type (text, textarea, image, select, boolean), required flag, and options (for select type)
- **Field JSON serialization** — fields stored as JSON in `content_types.fields_json`; serialized on form submit
- **Content integration** — content editor type dropdown dynamically includes custom types; custom fields section renders below excerpt with appropriate input types
- **Custom field persistence** — field values stored in `custom_fields` table; delete-and-reinsert on update (handles unchecked booleans)
- **Content list integration** — type filter dropdown includes custom types; type badges show custom type names
- **Dynamic public routes** — archive routes (`/{type-slug}`) and single item routes (`/{type-slug}/{slug}`) registered dynamically from `content_types` table
- **Slug cascade** — changing a content type's slug updates all content items referencing the old slug
- **Delete protection** — cannot delete a content type that has content items referencing it
- **Reserved slug validation** — slugs like `page`, `post`, `blog`, `admin`, `contact` are rejected
- **Field validation** — keys must be unique, lowercase alphanumeric with underscores; select type requires non-empty options array

### Settings & AI Backend (Chunk 4.1)

The settings page and AI backend provide:
- **Settings page** (`/admin/settings`) — admin-only page with AI Assistant section (Claude API key, model selection) and General section (site name)
- **API key encryption** — API key stored encrypted in `settings` table using AES-256-CBC; never displayed in browser, only shows a "configured" or "missing" status indicator
- **Model selection** — dropdown to choose between Claude Sonnet 4, Haiku 4.5, and Opus 4.6
- **AI chat endpoint** (`POST /admin/ai/chat`) — JSON API accepting `{message, content_id, conversation_id}`, calls Claude Messages API, returns AI response with conversation persistence
- **Conversation history** (`GET /admin/ai/conversations`) — JSON API returning past conversations filtered by content_id
- **Content context** — AI system prompt includes the content being edited (type, title, status, body excerpt) for context-aware assistance
- **CSRF for JSON APIs** — chat endpoint protected by header-based CSRF tokens (`X-CSRF-Token` header)
- **Conversation isolation** — each user has separate conversations per content item; users cannot access each other's conversations

### Settings Panel & Site Configuration (Chunk 5.2)

The settings page is now a comprehensive site configuration interface:
- **DB settings overlay** — `Config::loadDbSettings()` loads settings from the database and overrides file-based config values; protected keys (`db_*`, `app_secret`) never overridden by DB
- **General section** — site name, site URL (validated), tagline, timezone (grouped `<select>` with optgroups), items per page (clamped 1–100)
- **SEO section** — default meta description (max 300 chars), default Open Graph image
- **Cookie Consent & Analytics** — enable/disable consent banner (checkbox with hidden input pattern), consent text, privacy policy link, enable/disable Google Analytics, GA Measurement ID (validated `G-XXXXXXXXXX` format)
- **Contact Form section** — notification email (validated or empty to disable)
- **Advanced section** — enable user registration (checkbox), maintenance mode (checkbox)
- **Immediate effect** — `Config::reset()` after save ensures changes take effect on the next request without restarting
- **Conditional cookie consent** — public site conditionally shows/hides cookie consent banner based on `cookie_consent_enabled` setting
- **Timezone re-apply** — `App::__construct()` re-applies timezone after loading DB settings

### AI Page Generator (Chunk 5.3)

The AI page generator (`/admin/generator`) provides a conversational wizard for creating new pages:
- **4-step wizard** — Setup (content type selection) → Describe (chat with AI) → Preview (review generated content) → Done (success with edit link)
- **Content type selector** — choose from Page, Blog Post, or any custom content type
- **AI chat interface** — conversational requirement gathering with the AI asking 2-3 questions per turn
- **READY_TO_GENERATE marker** — AI signals when it has enough info; "Generate Page" button appears in chat
- **Two-phase AI conversation** — gathering prompt (asks questions) switches to generation prompt (outputs structured JSON)
- **Preview pane** — shows title, slug, excerpt, meta fields, and rendered HTML body before creating
- **Custom fields support** — custom content type fields included in AI context and populated in generated content
- **Create as Draft / Create & Publish** — choose status at creation time; published_at auto-set for published items
- **Content integration** — created content immediately appears in `/admin/content` list and is editable in the standard editor
- **Conversation persistence** — generator conversations stored in `ai_conversations` table with `content_id = null`
- **Error handling** — missing API key shows helpful error with link to Settings; network errors displayed in chat
- **Sidebar navigation** — "Generate Page" link with star icon (★) in the Content section

### Element Catalogue & Rendering Engine (Chunk 6.1)

The element system provides reusable UI components for page building:
- **Element catalogue** (`/admin/elements`) — grid view of all elements with name, category, slot count, usage count, and status
- **Element editor** — create/edit elements with HTML template, CSS, slot definitions (JSON), and live preview
- **SlotRenderer** — micro-mustache template engine supporting `{{key}}`, `{{{raw}}}`, `{{#section}}`, `{{^inverted}}`, loops, and dot notation
- **PageRenderer** — assembles element-based pages into HTML with deduplicated CSS
- **7 seed elements** — Hero Section, Text Section, Feature Grid, CTA Banner, Image + Text, Testimonial Section, FAQ Section
- **Public rendering** — element-mode pages rendered via PageRenderer with element CSS injected in `<head>`

### Content Editor Element Mode & Page Builder UI (Chunk 6.2)

The content editor now supports two editing modes:
- **Editor mode toggle** — radio buttons to switch between "HTML Editor" (TinyMCE) and "Page Builder" (element-based) modes
- **Page Builder panel** — when in elements mode, shows toolbar with "Add Element" button, element count badge, and instance list
- **Element picker modal** — searchable, categorized modal to browse and select elements from the catalogue
- **Instance cards** — each added element displays as a collapsible card with drag handle, element name, category badge, and slot form fields
- **Slot field types** — text input, textarea (richtext), image (with media browser), link (URL + text + target), select dropdown, checkbox (boolean), number input, and list (recursive sub-items)
- **Drag-and-drop reordering** — drag instance cards by their handle to reorder elements on the page
- **JSON serialization** — on form submit, all instance data serialized to `elements_json` hidden input
- **Server-side persistence** — `page_elements` rows saved with element_id, sort_order, and slot_data_json; invalid element_ids silently skipped
- **Mode coexistence** — switching modes preserves body content; TinyMCE hidden but not destroyed in elements mode
- **Media browser integration** — image slot fields reuse the existing media browser modal from the content editor

### AI Element Integration (Chunk 6.4)

The AI system is now connected to the element-based page builder:
- **Element editor AI panel** — "AI Assistant" toggle button opens a chat panel as a third column in the element editor grid
- **AI coding assistant** — chat with AI about the element's HTML template, CSS, and slots; AI sees current code context on first message
- **Apply actions** — AI responses have "Apply HTML", "Apply CSS", "Apply Both", and "Copy" buttons that extract fenced code blocks and populate editor fields
- **Element-aware page generation** — generator editor mode toggle (HTML/Elements) switches between TinyMCE HTML generation and element-based generation using the site's element catalogue
- **Element catalogue context** — AI gathering and generation prompts include the full element catalogue (names, slugs, descriptions, slots) so AI can reference and compose existing elements
- **New element proposals** — when AI generates a page with `__new__` elements not in the catalogue, they're saved as proposals in `element_proposals` for admin review
- **Proposal approval flow** — `/admin/element-proposals` page with filter tabs (Pending/Approved/Rejected), collapsible HTML/CSS preview, approve/reject buttons; approved proposals become real elements
- **Content editor catalogue context** — when editing element-mode content, the AI assistant system prompt includes the element catalogue for context-aware assistance
- **Conversation scoping** — element AI conversations are scoped by `element_id` (separate from content conversations) with `findOrCreateForElement()` in ConversationManager

### Per-Instance Element Styling (Chunk 6.3)

Each element instance in the page builder now has a **Style tab** with GUI controls and a Custom CSS textarea:
- **Content/Style tabs** — each instance card has tab buttons to switch between slot fields (Content) and style controls (Style)
- **Style panel** — accordion sections for Spacing (margin/padding with linked toggle + unit selector), Background (color, image URL, size/position/repeat), Typography (color, size, align, weight), Border (width, style, color, radius), Effects (box-shadow, opacity slider), Layout (max-width, min-height), Custom CSS (dark-theme monospace textarea), and Advanced (custom CSS class)
- **Inline styles** — GUI style values render as inline `style` attribute on the element wrapper div (e.g., `style="padding-top: 20px; background-color: #ff0000"`)
- **Custom CSS scoping** — per-instance custom CSS is scoped via `[data-instance-id="N"]` attribute selector, preventing CSS leaks between instances
- **Page Layout Styles** — sidebar card (visible in Page Builder mode) with target selector (Page Body, Container, Site Main) and the same GUI controls + custom CSS for each target
- **CSS specificity cascade** — catalogue CSS → inline GUI styles → scoped custom CSS (each layer overrides the previous)
- **CSS sanitization** — `@import`, `@charset`, `javascript:`, `expression()`, `behavior:`, `-moz-binding:`, `</style>`, `<script>`, and HTML comments are stripped from custom CSS
- **Native `<details>/<summary>`** — style panel sections use native HTML accordion (no JS for expand/collapse)
- **HTML-mode unaffected** — HTML-mode content creates no style_data or page_styles data

### Pencil Design Editor (Chunk 7.1)

The admin panel now includes an embedded Pencil visual design editor:
- **Design Editor page** (`/admin/design/editor`) — Figma-like canvas editor embedded in an iframe with toolbar, file selector, and loading overlay
- **IPC bridge** — `pencil-bridge.js` mocks the VS Code API (`window.vscodeapi`) and routes editor messages to PHP via `fetch()` calls
- **File I/O** — `.pen` files saved to `designs/` directory; load/save via JSON API endpoints (`/admin/design/load`, `/admin/design/save`)
- **File selector** — dropdown to pick existing `.pen` files or create new ones by typing a filename
- **Asset import** — data URI images saved to `public/assets/uploads/design/` with randomized filenames; external URLs passed through
- **WASM rendering** — CanvasKit/Skia WASM binary for smooth canvas rendering (WebAssembly + WebGL2)
- **Relaxed CSP** — editor page uses relaxed Content-Security-Policy (`unsafe-eval` for WASM, `unsafe-inline`, `worker-src blob:`, external font/image origins)
- **iframe sandbox** — `allow-scripts allow-same-origin allow-popups allow-forms allow-modals` for full editor functionality
- **CSRF token flow** — admin template → iframe query param → bridge reads via URLSearchParams → sent as `X-CSRF-Token` header on mutation requests
- **Path traversal prevention** — `sanitizePath()` rejects `..`, null bytes, non-`.pen` extensions, and non-safe characters
- **Sidebar navigation** — "Design Editor" link with pencil icon in the Design section
- **Status indicator** — shows "Ready" (blue) after load, "Saved" (green) after save, auto-resets after 2 seconds
- **Loading overlay** — spinner shown while editor initializes, hidden when bridge reports `editor-ready`

### .pen-to-HTML Converter (Chunk 7.2)

The PenConverter system converts `.pen` design files (from the Pencil editor) into semantic HTML + CSS:
- **PenStyleBuilder** — stateless utility converting `.pen` node properties to CSS declarations: fills (color, gradient, image), strokes (border), effects (shadow, blur, backdrop-blur), layout (flexbox), typography, sizing (`fill_container`/`fit_content`), position, corner radius, opacity, clip
- **PenNodeRenderer** — renders individual `.pen` node types (frame, text, rectangle, ellipse, path, line, polygon, ref, group, icon_font) to HTML+CSS pairs with semantic tag inference (frame names → `<header>`, `<footer>`, `<nav>`, `<main>`, `<section>`, `<article>`, `<aside>`; text fontSize → `<h1>`-`<h6>` or `<p>`)
- **PenConverter** — main orchestrator: reads `.pen` JSON, builds component registry (reusable nodes), resolves variables to `:root` CSS custom properties, recursively renders the node tree, and collects all CSS
- **Component resolution** — `ref` nodes reference reusable components; deep-cloned with root-level and descendant overrides via slash-separated ID paths; circular reference guard (max depth 10)
- **Variable/theme system** — `$--name` references become CSS `var(--name)` with `:root` declarations and `[data-theme-*]` selectors for theming
- **Text fill** — text node `fill` maps to CSS `color` (not `background-color`); gradient fills use the `-webkit-background-clip: text` trick
- **Icon fonts** — CDN imports for lucide, feather, Material Symbols, phosphor with deduplication
- **Convert endpoint** (`POST /admin/design/convert`) — accepts `.pen` path or raw JSON, returns HTML+CSS
- **Preview endpoint** (`GET /admin/design/preview?path=...`) — renders conversion as standalone HTML page
- **FrontController integration** — design_file check in page/blogPost/homepage methods (inert until `design_file` column added in Chunk 7.4)
- **PageRenderer::renderFromPen()** — thin delegation to PenConverter::convertFile()

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
_migrations          content              custom_fields        media              page_styles
ai_conversations     contact_submissions  element_proposals    page_elements      settings
content_types        elements             users
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| "Class not found" errors | Run `composer dump-autoload` in project root |
| Port 8000 in use | Use a different port: `php -S localhost:8080 -t public` |
| No database file created | Check that `storage/` directory exists and is writable |
| Blank page / 500 error | Check PHP error log or run with `php -S localhost:8000 -t public 2>&1` |
