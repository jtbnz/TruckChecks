# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

TruckChecks is a PHP/MySQL web application for managing truck locker inventory. Trucks contain lockers, lockers contain items. Field crews perform checks to verify item presence, with a color-coded dashboard showing status (green=OK, orange=missing items, red=overdue).

**Current version**: Managed via `VERSION` file (read by `db.php`'s `getVersion()`).

## Technology Stack

- **Backend**: PHP 7.4+, PDO with MySQL/MariaDB, no framework
- **Frontend**: Vanilla JavaScript, vanilla CSS3 (no frameworks/libraries)
- **AJAX**: XMLHttpRequest (not Fetch API)
- **Dependencies**: endroid/qr-code, tecnickcom/tcpdf, phpmailer/phpmailer (via Composer)
- **Deployment**: Docker Compose (Apache + MySQL 8.0)

## Running the Application

```bash
# Docker (primary)
cd Docker && docker-compose up -d
# Access at http://localhost:8000

# Local: Point Apache/Nginx to project root
# Copy config_sample.php to config.php and configure credentials
# Import Docker/setup.sql for database schema
```

No build step — PHP is interpreted directly. Version string is appended to CSS/JS URLs for cache busting.

## Architecture

**Page-based MVC hybrid** — each PHP file is its own controller+view. No centralized router.

### Critical file ordering within each page:
1. `include('config.php'); include 'db.php';` — configuration and DB connection
2. Authentication check (cookie-based)
3. AJAX handlers (`$_GET['ajax']`) — **must come before any HTML output**
4. Form processing (POST handlers for add/edit, GET for delete)
5. Data queries for display
6. `include 'templates/header.php';` then HTML output then `include 'templates/footer.php';`

### Authentication pattern (required on every protected page):
```php
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}
```
Single admin password defined in `config.php`. Cookie name includes DB_NAME for multi-tenant support.

### AJAX pattern:
```php
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    // process and echo json_encode(...)
    exit;
}
```

### CRUD pattern:
- **Add**: `POST` with `isset($_POST['add_xxx'])`
- **Edit**: `POST` with `isset($_POST['edit_xxx'])`, triggered by `?edit_id=XX` in URL
- **Delete**: `GET` with `isset($_GET['delete_xxx_id'])`, with `confirm()` dialog

## Database Migrations

**Any schema change must be added to `db_migrate.php`** — never rely on manual SQL. Migrations run automatically on every `get_db_connection()` call. Each migration is idempotent (checks `information_schema` before applying).

To add a new migration:
1. Add a function `migrate_your_change(PDO $db)` in `db_migrate.php`
2. Use `table_exists()`, `column_exists()`, or `trigger_exists()` to check before applying
3. Add the function name (without `migrate_` prefix) to the `$migrations` array in `run_migrations()`

This ensures all deployed instances (which may be at different schema versions) stay in sync automatically.

## Database Schema (key relationships)

```
trucks (id, name, relief)
  → lockers (id, name, truck_id, notes) [CASCADE]
    → items (id, name, locker_id) [CASCADE]
      → check_items (id, check_id, item_id, is_present) [CASCADE]
checks (id, locker_id, check_date, checked_by, ignore_check)
```

DELETE triggers automatically log to `audit_log` table with full row data as JSON.

## Security Requirements

- **All** database queries use PDO prepared statements — never concatenate SQL
- **All** user-facing output uses `htmlspecialchars()`
- Config file (`config.php`) is gitignored; use `config_sample.php` as template

## Key Conventions

- CSS classes: `.button.touch-button` for buttons, `.input-container` for form fields, `.item-grid`/`.item-card` for grid layouts
- Primary brand color: `#12044C` (dark navy)
- Mobile breakpoint: `768px` with touch-friendly sizing (15-20px padding on buttons)
- URL state management: filter params persist in URL via `history.replaceState`
- Templates: `templates/header.php` (includes `<head>`, opens `<body>`) and `templates/footer.php` (version display, closes `<body>`)
- Version displayed via `$version = getVersion()` in header, `$_SESSION['version']` in footer

## Project Documentation

Detailed documentation lives in `memory-bank/` — see `systemPatterns.md` for architecture, `techContext.md` for technical details, `progress.md` for feature status.
