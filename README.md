# LiteCMS

A lightweight content management system built with PHP. Designed as a simpler alternative to WordPress for small business websites.

## Features

- Pages, blog posts, and custom content types
- WYSIWYG editor (TinyMCE)
- AI writing assistant (powered by Claude)
- Visual page builder with reusable elements
- Visual design editor (Pencil) with .pen file support
- Media library with image upload
- Light/dark theme support
- User management with role-based access (admin/editor)
- Contact form with admin message viewer
- SEO meta fields and Open Graph tags
- Cookie consent banner (GDPR)
- Google Analytics integration (consent-aware)
- Responsive, mobile-first design
- Multi-database support: SQLite, PostgreSQL, MariaDB

## Requirements

- PHP 8.1 or higher
- Composer
- One of: SQLite (default), PostgreSQL 14+, or MariaDB 10.6+
- Apache with mod_rewrite (or equivalent URL rewriting)

## Installation

1. Clone the repository:

```bash
git clone <repository-url> litecms
cd litecms
```

2. Install PHP dependencies:

```bash
composer install
```

3. Configure the application:

```bash
cp .env.example .env
```

Edit `config/app.php` or set environment variables to configure:
- `DB_DRIVER` — `sqlite` (default), `pgsql`, or `mysql`
- `SITE_NAME` — Your site name
- `SITE_URL` — Your site's base URL
- `APP_SECRET` — Change to a random string for security

4. Ensure the `storage/` and `public/assets/uploads/` directories are writable:

```bash
chmod -R 775 storage/
chmod -R 775 public/assets/uploads/
```

5. Point your web server's document root to the `public/` directory.

6. Visit your site URL. The database and tables will be created automatically on first request.

## First-Time Setup

1. Navigate to `/admin/login`
2. Log in with the default credentials:
   - Username: `admin`
   - Password: `admin`
3. **Change the default password immediately** via Settings or User Management
4. Configure your site name, URL, and other settings at `/admin/settings`

## Usage

### Content Management

- **Dashboard**: Overview of content, media, and user counts
- **Content**: Create and manage pages and blog posts
- **Media**: Upload and manage images and files
- **Content Types**: Define custom content types with custom fields

### Page Builder

- When editing content, switch between HTML editor and Page Builder modes
- Page Builder uses reusable elements (hero sections, feature grids, CTAs, etc.)
- Elements can be styled per-instance with visual controls or custom CSS

### AI Assistant

1. Go to Settings and enter your Claude API key
2. When editing content, open the AI panel to get writing assistance
3. Use the Page Generator to create entire pages through conversation

### Design Editor

- Access the visual design editor at Design > Design Editor
- Create and edit `.pen` design files
- Convert designs to HTML for use on the public site

### Settings

- **General**: Site name, URL, timezone, items per page
- **AI**: Claude API key, model selection
- **Design System**: Theme colors, fonts, spacing
- **Cookie Consent & Analytics**: GDPR consent banner, Google Analytics
- **Contact**: Notification email for contact form submissions

## Database

LiteCMS supports three database backends:

| Driver | Config Value | Notes |
|--------|-------------|-------|
| SQLite | `sqlite` | Default. Zero configuration. File stored at `storage/database.sqlite` |
| PostgreSQL | `pgsql` | Set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| MariaDB | `mysql` | Set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` |

Migrations run automatically on first request.

## File Structure

```
public/           Web root (point server here)
  index.php       Single entry point
  assets/         CSS, JS, uploads
app/              Application source code
  Core/           Framework core (router, config, request/response)
  Admin/          Admin controllers
  Auth/           Authentication
  AIAssistant/    AI integration
  Database/       Database layer
  PageBuilder/    Element and page rendering
  Templates/      Public site controller and template engine
config/           Configuration
templates/        PHP templates (admin + public)
migrations/       Database migration files
storage/          SQLite database, logs, cache
designs/          .pen design files
```

## License

MIT
