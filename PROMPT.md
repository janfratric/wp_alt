# LiteCMS — Optimized Build Prompt

> Use this prompt with an AI coding agent (Claude, Cursor, etc.) to build the CMS.
> Copy everything below the line into your agent's input.

---

<task>
You are a senior PHP developer and software architect. Build a complete, lightweight CMS (Content Management System) intended as a WordPress alternative for small business websites. The CMS must be functional, secure, and deployable by a non-technical user with minimal configuration.
</task>

<context>
The target users are small business owners and their web administrators who find WordPress too heavy, complex, and plugin-dependent. They need a CMS that is fast to set up, easy to manage, and does not require deep technical knowledge. The system will be used to manage business websites with pages, blog posts, and custom content types. An AI writing assistant (powered by Claude) is a first-class feature, not an afterthought.
</context>

<definition_of_lightweight>
"Lightweight" means ALL of the following constraints MUST be met:
- Zero heavy frameworks: No Laravel, Symfony, or similar. Use plain PHP 8.1+ with a thin custom micro-framework layer (router, simple DI container, template engine)
- Minimal Composer dependencies: Maximum 10 total packages. Every dependency must be justified
- Fast cold start: Page render under 50ms on modest shared hosting (no opcode cache)
- Small footprint: Total codebase under 5,000 lines of PHP (excluding vendor/). Total project under 5MB without media uploads
- No Node.js build step: Frontend assets (CSS, JS) are vanilla or use pre-built CDN libraries only
- Single entry point: All requests route through index.php
- Works on shared hosting: PHP 8.1+, no shell access required, no Redis/Memcached required
</definition_of_lightweight>

<tech_stack>
<backend>
- Language: PHP 8.1+ (strict types enabled in every file)
- Routing: Custom lightweight router (regex-based, single file)
- Templating: Plain PHP templates with a simple layout/partial system (no Twig/Blade)
- Authentication: Session-based with bcrypt password hashing and CSRF protection
- Database abstraction: PDO with a thin query builder that generates compatible SQL for both SQLite and PostgreSQL/MariaDB
</backend>

<database>
Dual-mode database system switchable via a single config value:
- Development/simple deployments: SQLite (single file, zero configuration)
- Production/scaling: PostgreSQL 14+ OR MariaDB 10.6+
- The query builder MUST abstract differences (e.g., AUTOINCREMENT vs SERIAL vs AUTO_INCREMENT, datetime handling, boolean types)
- Migrations system: Numbered SQL files per driver (e.g., migrations/001_initial.sqlite.sql, migrations/001_initial.pgsql.sql, migrations/001_initial.mysql.sql)
</database>

<frontend>
- Admin UI: Clean, modern, responsive design using vanilla CSS (custom properties) or a single lightweight CSS framework (PicoCSS or similar, under 15KB)
- WYSIWYG Editor: TinyMCE 6 (loaded from CDN with self-hosted fallback option)
- AI Assistant Panel: Side panel in the editor view with a chat interface to Claude API
- Public-facing templates: Semantic HTML5, minimal CSS, no JavaScript required for core functionality
</frontend>
</tech_stack>

<modules>
Build the CMS in these modules. Each module should be in its own directory under /app/.

<module name="Core">
Purpose: Application bootstrap, routing, configuration, request/response handling.
Files:
- public/index.php — Single entry point, bootstraps the app
- app/Core/App.php — Application container, registers routes and middleware
- app/Core/Router.php — Lightweight regex router (GET, POST, PUT, DELETE)
- app/Core/Request.php — Request wrapper (input sanitization, method detection)
- app/Core/Response.php — Response builder (HTML, JSON, redirects)
- app/Core/Config.php — Reads config/app.php, provides typed getters
- app/Core/Middleware.php — Simple middleware pipeline (auth check, CSRF)
- config/app.php — Returns associative array with all config values

Key config values: db_driver, db_path/db_host/db_name/db_user/db_pass, site_name, site_url, timezone, claude_api_key, items_per_page
</module>

<module name="Database">
Purpose: Database connection, query building, migrations.
Files:
- app/Database/Connection.php — PDO connection factory
- app/Database/QueryBuilder.php — Fluent query builder compatible with all 3 drivers
- app/Database/Migrator.php — Reads numbered migration files, tracks applied migrations
- migrations/ — Numbered SQL files per driver

Schema tables:

Table: users
- id (PK, auto-increment)
- username (VARCHAR 50, UNIQUE)
- email (VARCHAR 255, UNIQUE)
- password_hash (VARCHAR 255)
- role VARCHAR(20) with CHECK constraint ('admin', 'editor')
- created_at, updated_at (TIMESTAMP)

Table: content
- id (PK, auto-increment)
- type VARCHAR(50) — 'page', 'post', or custom type slug
- title VARCHAR(255)
- slug VARCHAR(255, UNIQUE)
- body TEXT — stores HTML from WYSIWYG editor
- excerpt TEXT (nullable)
- status VARCHAR(20) with CHECK ('draft', 'published', 'archived')
- author_id (FK -> users.id)
- template VARCHAR(100) (nullable)
- sort_order INT DEFAULT 0
- meta_title VARCHAR(255) (nullable)
- meta_description TEXT (nullable)
- featured_image VARCHAR(500) (nullable)
- created_at, updated_at, published_at (TIMESTAMP)

Table: content_types
- id (PK, auto-increment)
- slug VARCHAR(50, UNIQUE)
- name VARCHAR(100)
- fields_json TEXT — JSON definition of custom fields
- has_archive BOOLEAN DEFAULT true
- created_at (TIMESTAMP)

Table: custom_fields
- id (PK, auto-increment)
- content_id (FK -> content.id, CASCADE DELETE)
- field_key VARCHAR(100)
- field_value TEXT

Table: media
- id (PK, auto-increment)
- filename VARCHAR(255)
- original_name VARCHAR(255)
- mime_type VARCHAR(100)
- size_bytes INT
- uploaded_by (FK -> users.id)
- created_at (TIMESTAMP)

Table: settings
- key VARCHAR(100, PK)
- value TEXT

Table: ai_conversations
- id (PK, auto-increment)
- user_id (FK -> users.id)
- content_id (FK -> content.id, nullable)
- messages_json TEXT — JSON array of {role, content, timestamp}
- created_at, updated_at (TIMESTAMP)
</module>

<module name="Auth">
Purpose: Login, logout, session management, authorization.
Files:
- app/Auth/AuthController.php — Login form (GET), login handler (POST), logout
- app/Auth/AuthMiddleware.php — Checks session, redirects to login if unauthenticated
- app/Auth/RoleMiddleware.php — Checks user role for admin-only routes
- templates/auth/login.php — Login page template

Security requirements:
- Passwords: bcrypt via password_hash() / password_verify()
- Sessions: Regenerate ID on login, secure cookie flags (httponly, samesite, secure when HTTPS)
- CSRF: Token generated per session, validated on all POST/PUT/DELETE
- Rate limiting: Track failed login attempts per IP, lock after 5 failures for 15 minutes
- Input sanitization: htmlspecialchars() on all output, parameterized queries for all DB
</module>

<module name="Admin">
Purpose: Admin dashboard and content management interface.
Files:
- app/Admin/DashboardController.php — Dashboard with content counts, recent items
- app/Admin/ContentController.php — Full CRUD for content (pages, posts, custom types)
- app/Admin/MediaController.php — File upload, media library browser, deletion
- app/Admin/UserController.php — User management (admin only)
- app/Admin/ContentTypeController.php — Define and manage custom content types
- app/Admin/SettingsController.php — Site settings, AI API key configuration
- templates/admin/layout.php — Admin layout with sidebar navigation
- templates/admin/dashboard.php
- templates/admin/content/index.php — Content list with search, filter, pagination
- templates/admin/content/edit.php — Content editor with TinyMCE and AI panel
- templates/admin/media/index.php — Media library grid view
- templates/admin/settings.php

Features:
- Content list: Sortable table with bulk actions (delete, change status)
- Content editor: TinyMCE WYSIWYG with image upload to media library
- Slug auto-generation from title (with manual override)
- Draft/publish workflow with scheduled publishing (published_at in future)
- Custom content types: Create new types and define custom fields (text, textarea, image, select, boolean)
</module>

<module name="AIAssistant">
Purpose: Claude-powered AI writing assistant integrated into the content editor.
Files:
- app/AIAssistant/AIController.php — Handles chat API requests from the editor panel
- app/AIAssistant/ClaudeClient.php — Thin wrapper around Claude Messages API (PHP curl, no SDK)
- app/AIAssistant/ConversationManager.php — Stores/retrieves conversation history
- public/assets/js/ai-assistant.js — Frontend chat panel logic

How it works:
1. Admin configures Claude API key in Settings (stored encrypted in settings table)
2. When editing content, a collapsible side panel shows a chat interface
3. User types requests like: "Write a professional About Us page for a bakery", "Make this text more concise", "Add SEO keywords about organic bread"
4. Frontend sends the message + current editor content to AIController via fetch()
5. AIController calls Claude API with a system prompt that includes context about the content being edited (type, title, current body)
6. Response displays in the chat panel
7. User can click "Insert" on any AI response to replace or append to editor content
8. Conversation history persists per content item

Claude API integration:
- Use Claude Messages API (POST https://api.anthropic.com/v1/messages)
- Model: claude-sonnet-4-20250514 (configurable in settings)
- System prompt: "You are a professional content writing assistant for a business website CMS. Help the user write, edit, and improve web content. When the user shares their current page content, reference it in your suggestions. Keep responses practical and ready to paste into a web page. Format output as clean HTML suitable for a WYSIWYG editor."
- Send current editor content as context with each message
- Handle API errors gracefully (user-friendly message, log error)
</module>

<module name="Templates">
Purpose: Public-facing page templates and theme system.
Files:
- app/Templates/TemplateEngine.php — Simple render($template, $data), supports layouts and partials
- app/Templates/FrontController.php — Routes public URLs to content, renders template
- templates/public/layout.php — Base HTML5 layout (head, nav, footer, SEO meta)
- templates/public/home.php — Homepage (hero section, featured content, CTA)
- templates/public/page.php — Generic page (title + body)
- templates/public/blog-index.php — Blog listing with pagination and excerpt cards
- templates/public/blog-post.php — Single blog post (title, meta, image, body, author)
- templates/public/contact.php — Contact page with form (stores submissions or sends email)
- templates/public/archive.php — Generic listing for custom content types
- templates/public/404.php — Not found page
- public/assets/css/style.css — Public site stylesheet
- public/assets/css/admin.css — Admin panel stylesheet

Features:
- SEO: meta title/description from content fields, Open Graph tags, canonical URLs
- Responsive: Mobile-first design, works without JavaScript
- Navigation: Auto-generated from published pages (sorted by sort_order)
- Breadcrumbs: Auto-generated based on URL structure
</module>
</modules>

<file_structure>
project-root/
├── public/                     # Web root (point Apache/Nginx here)
│   ├── index.php               # Single entry point
│   ├── .htaccess               # Apache URL rewriting
│   └── assets/
│       ├── css/
│       │   ├── style.css       # Public site styles
│       │   └── admin.css       # Admin panel styles
│       ├── js/
│       │   ├── admin.js        # Admin functionality
│       │   ├── editor.js       # TinyMCE init and config
│       │   └── ai-assistant.js # AI chat panel
│       └── uploads/            # User-uploaded media (writable)
├── app/
│   ├── Core/
│   ├── Database/
│   ├── Auth/
│   ├── Admin/
│   ├── AIAssistant/
│   └── Templates/
├── config/
│   └── app.php                 # Main configuration
├── migrations/
│   ├── 001_initial.sqlite.sql
│   ├── 001_initial.pgsql.sql
│   └── 001_initial.mysql.sql
├── templates/
│   ├── auth/
│   ├── admin/
│   └── public/
├── storage/
│   ├── database.sqlite         # SQLite DB (auto-created)
│   ├── logs/
│   └── cache/
├── composer.json
├── .htaccess                   # Deny access to non-public files
├── .env.example
└── README.md
</file_structure>

<security_requirements>
Non-negotiable. Every feature must comply:
- ALL database queries use PDO prepared statements with named parameters
- ALL user output escaped with htmlspecialchars($var, ENT_QUOTES, 'UTF-8')
- CSRF token on every form and AJAX mutation request
- File uploads: whitelist extensions (jpg, jpeg, png, gif, webp, pdf), validate MIME with finfo, rename to random hash, disable execution in uploads/
- Admin routes protected by auth middleware
- API key stored encrypted at rest (openssl_encrypt with app secret)
- Content Security Policy headers on admin pages
- X-Frame-Options: DENY header
- No directory listing
- SQL injection: impossible by design (parameterized queries everywhere)
- XSS: impossible by design (escape by default in templates)
</security_requirements>

<implementation_phases>
Implement in this exact order. Each phase must be fully working before starting the next.

Phase 1 — Foundation (Core + Database + Auth):
Build the application bootstrap, router, config loader, database connection with dual-mode support (SQLite + PostgreSQL/MariaDB), query builder, migration system, and authentication (login, logout, session, CSRF). Include a bootstrap that creates the first admin user. End state: user can visit /admin/login, log in, and see a placeholder dashboard.

Phase 2 — Content Management (Admin + Content CRUD):
Build the admin dashboard, content listing with pagination and filters, content creation/editing with TinyMCE WYSIWYG editor, media upload and library, slug auto-generation, draft/publish workflow, and user management. End state: admin can create, edit, publish, and delete pages and blog posts.

Phase 3 — Public Site (Templates + Routing):
Build the public-facing template engine, front controller that maps URLs to content, all page templates (home, page, blog listing, blog post, contact, 404), navigation generation, SEO meta tags, and responsive CSS. End state: visitors can browse the live website.

Phase 4 — AI Assistant Integration:
Build the Claude API client, AI chat controller, conversation persistence, and the frontend chat panel in the content editor. End state: editors can use the AI assistant to help write and improve content.

Phase 5 — Custom Content Types + Polish:
Build the custom content type system (admin can define new types with custom fields), archive templates for custom types, settings panel, and final polish (error handling, logging, input validation, performance). End state: feature-complete and production-ready.
</implementation_phases>

<output_format>
For EACH phase, produce ALL of the following:

1. Every file listed in that phase, with COMPLETE source code (no placeholders, no "// TODO", no abbreviated code). Every file must be copy-paste ready and functional.
2. SQL migration files for all three database drivers (SQLite, PostgreSQL, MariaDB) when schema changes are introduced.
3. A brief testing checklist: what to verify manually after the phase is built.

After ALL phases are complete, produce:
4. A README.md with: project description, requirements, installation steps, and usage guide.
5. composer.json with the minimal required dependencies.
</output_format>

<constraints>
- Do NOT use any ORM (no Eloquent, Doctrine). Use the custom query builder with raw PDO.
- Do NOT require Node.js, npm, webpack, or any JavaScript build tools.
- Do NOT use any PHP framework (no Laravel, Symfony, Slim). Build the micro-framework from scratch.
- TinyMCE MUST be loaded from CDN with a local fallback configuration option.
- Every PHP file MUST start with declare(strict_types=1).
- Use PSR-4 autoloading via Composer for the app/ namespace.
- All dates in UTC. Display timezone conversion happens in templates.
- File encoding: UTF-8 throughout. Database connections set to UTF-8.
- The entire application MUST work immediately after: git clone, composer install, edit config/app.php, visit the URL in browser. No additional build steps.
</constraints>
