# TruckChecks Module Documentation

Complete reference for all application modules, their responsibilities, data flows, and integration points. Use this document when planning enhancements or debugging cross-module issues.

---

## Table of Contents

1. [Core Infrastructure](#core-infrastructure)
2. [Authentication Module](#authentication-module)
3. [Dashboard Module](#dashboard-module)
4. [Maintenance Modules](#maintenance-modules)
5. [Check System Module](#check-system-module)
6. [Changeover Module](#changeover-module)
7. [Reporting Module](#reporting-module)
8. [QR Code Module](#qr-code-module)
9. [Search Module](#search-module)
10. [Email Module](#email-module)
11. [Quiz Module](#quiz-module)
12. [System Administration Module](#system-administration-module)
13. [Database Schema Reference](#database-schema-reference)
14. [AJAX API Reference](#ajax-api-reference)
15. [CSS Component Reference](#css-component-reference)

---

## 1. Core Infrastructure

### config.php (user-created from config_sample.php)
**Purpose**: Central configuration constants for the entire application.

| Constant | Type | Description |
|----------|------|-------------|
| `DB_HOST` | string | Database hostname |
| `DB_NAME` | string | Database name (also used in cookie naming for multi-tenancy) |
| `DB_USER` | string | Database username |
| `DB_PASS` | string | Database password |
| `PASSWORD` | string | Single admin password for all users |
| `EMAIL_HOST` | string | SMTP server hostname |
| `EMAIL_USER` | string | SMTP username |
| `EMAIL_PASS` | string | SMTP password |
| `EMAIL_PORT` | string | SMTP port (typically 587) |
| `TZ_OFFSET` | string | Timezone offset (e.g., `+12:00` for NZST) |
| `IS_DEMO` | bool | Enable demo mode (diagonal stripes, DEMO watermark) |
| `REFRESH` | int | Dashboard auto-refresh interval in ms (default 30000) |
| `RANDORDER` | bool | Randomize item order on check pages |
| `DEBUG` | bool | Enable PHP error display |
| `CHECKPROTECT` | bool | Require protection code to submit checks |
| `IP_API_KEY` | string | ipgeolocation.io API key (empty to disable) |

### db.php
**Purpose**: Database connection factory and version management.

**Functions**:
- `get_db_connection(): PDO` — Returns PDO connection with exception mode, associative fetch, native prepared statements.
- `getVersion(): string` — Reads version from `VERSION` file. Used for cache busting and display.

**Error handling**: Logs to `db_errors.log`, displays user-friendly message on connection failure.

### VERSION
Plain text file containing the current version string (e.g., `v3.2.01`). Read by `getVersion()` in `db.php`.

### templates/header.php
**Purpose**: Shared HTML head and opening body tag.
- Starts session if not already started
- Sets `$version` via `getVersion()`
- Outputs `<!DOCTYPE html>`, `<head>` with viewport meta, CSS link with version cache-bust
- Adds `demo-mode` class to `<body>` when `IS_DEMO` is true

### templates/footer.php
**Purpose**: Shared page footer.
- Displays "Return to Home" button
- Shows version number from `$_SESSION['version']`

---

## 2. Authentication Module

### login.php
**Purpose**: Single entry point for admin authentication.

**Flow**:
1. Displays username/password form
2. Validates password against `PASSWORD` constant
3. On success: sets cookie `logged_in_{DB_NAME}` = `'true'`
4. Logs attempt to `login_log` table with IP, user agent, browser/OS detection
5. Optional: Calls ipgeolocation.io API for country/city data

**IP Detection**: Identifies local IPs (192.168.*, 10.*, 127.0.0.1, ::1) and labels them "Local Network".

**Browser Detection**: Parses user agent for Chrome, Firefox, Safari, Edge, plus OS (Windows, macOS, Linux, Android, iOS).

### logout.php
**Purpose**: Session termination.
- Clears authentication cookie with past expiration
- Destroys PHP session
- Redirects to login.php

### Authentication Guard (pattern used on every protected page)
```php
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}
```

### login_logs.php
**Purpose**: Audit interface for login attempts.

**Features**:
- Filter by success/failure status
- Filter by IP address
- Filter by date range
- Pagination (50 per page)
- Displays: timestamp, IP, browser, OS, country, city, success status

---

## 3. Dashboard Module

### index.php
**Purpose**: Main landing page showing all trucks and their locker check status.

**Display Logic**:
- Fetches all trucks, groups lockers by truck
- For each locker, queries most recent check
- Color-coding:
  - **Green**: Recent check (< 7 days), all items present
  - **Orange**: Recent check with missing items
  - **Red**: No recent check or check older than 7 days
- Relief trucks displayed with gray indicator overlay

**Interactivity**:
- Click locker cell → modal popup showing last check details
- Modal includes: check date, checked by, missing items list
- "Check Now" button in modal links to `check_locker_items.php`
- Auto-refreshes every `REFRESH` milliseconds

**Layout**: CSS grid (`.locker-grid`) with `.locker-cell` elements.

---

## 4. Maintenance Modules

These three modules handle CRUD for the truck → locker → item hierarchy. All follow the same structural pattern.

### maintain_trucks.php
**Purpose**: Add, edit, delete trucks.

**Entity fields**: `name` (text input)

**Cascade warning**: Deleting a truck deletes all associated lockers and items.

**No AJAX** — simple form submission with page reload.

### maintain_lockers.php
**Purpose**: Add, edit, delete lockers with truck assignment.

**Entity fields**: `name` (text input), `truck_id` (dropdown)

**Display**: Lists lockers with truck name in parentheses.

**Cascade warning**: Deleting a locker deletes all associated items.

### maintain_locker_items.php
**Purpose**: Add, edit, delete items with advanced filtering. Most complex maintenance page.

**AJAX Endpoints** (handled before HTML output):
- `?ajax=get_lockers&truck_id=X` — Returns lockers for a truck (JSON array)
- `?ajax=get_items&truck_filter=X&locker_filter=Y` — Returns filtered items with counts and names

**Add Form**: Two-step truck → locker selection via AJAX.

**Filter Section**: Truck dropdown → populates locker dropdown → updates item list in real-time.

**URL State**: Filter params stored in URL via `history.replaceState` for bookmarking.

**Stats Section**: Shows total item count and current filter context.

### Common CRUD Pattern (all three pages)
```php
// Add: POST with named submit button
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_xxx'])) { ... }

// Edit: POST with hidden ID field
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_xxx'])) { ... }

// Delete: GET with ID parameter + confirm() dialog
if (isset($_GET['delete_xxx_id'])) { ... }

// Show edit form: GET edit_id parameter
if (isset($_GET['edit_id'])) { $edit_xxx = ...; }
```

---

## 5. Check System Module

### check_locker_items.php
**Purpose**: Interactive interface for performing locker inventory checks.

**Flow**:
1. Select truck → select locker
2. Display all items in locker as tappable cards (`.item-card`)
3. Tap card to mark as present (turns green with `.checked` class)
4. Optional: Add notes
5. Submit check (optionally requires protection code if `CHECKPROTECT` enabled)

**Item Order**: Randomized if `RANDORDER` is true (prevents muscle-memory checking).

**Data Storage**:
- Creates `checks` record (locker_id, check_date, checked_by)
- Creates `check_items` records for each item (item_id, is_present)
- Creates `check_notes` record if notes provided

**UI**: Touch-optimized item grid with large tap targets. Visual state toggle (gray → green).

### reset_locker_check.php
**Purpose**: Clear check history data. Available from admin page.

---

## 6. Changeover Module

### changeover.php
**Purpose**: Equipment transfer tracking between trucks during crew changes.

**Flow**:
1. Select source truck and destination truck
2. Display items from source truck's lockers
3. Mark items as transferred
4. Record who performed the changeover

**Data Storage**: `swap` and `swap_items` tables (parallel to checks/check_items structure).

### changeover_pdf.php
**Purpose**: Generate PDF report of a changeover using TCPDF library.

---

## 7. Reporting Module

### reports.php
**Purpose**: Hub page with links to all report types.

### locker_check_report.php
**Purpose**: Historical report of locker checks with date filtering.

### deleted_items_report.php
**Purpose**: Shows audit trail of deleted items from `audit_log` table.

### list_all_items_report.php
**Purpose**: Complete inventory listing grouped by truck/locker.

### list_all_items_report_a3.php
**Purpose**: A3-format layout of inventory listing for printing.

### locker_report.php
**Purpose**: Per-locker detailed report.

---

## 8. QR Code Module

### qr-codes.php
**Purpose**: Generate QR codes for locker identification.
- Uses endroid/qr-code library
- Each QR code links to the check page for that locker

### qr-codes-pdf.php
**Purpose**: Generate printable PDF sheet of QR codes using TCPDF.

---

## 9. Search Module

### find.php
**Purpose**: Search for items by name across all trucks/lockers.
- Text input with search button
- Results show item name, truck, and locker location

### search_item.php
**Purpose**: Alternative search interface (simpler implementation).

---

## 10. Email Module

### email_admin.php
**Purpose**: Manage email distribution list (CRUD on `email_addresses` table).

### email_results.php
**Purpose**: Email the results of the most recent check.
- Finds missing items from last check
- Sends formatted email via PHPMailer (SMTP)
- Uses email addresses from `email_addresses` table

### scripts/email_checks.sh
**Purpose**: Bash script for automated email sending via cron.
- Includes timezone handling
- Can be scheduled for automatic daily/weekly reports

---

## 11. Quiz Module

### quiz/quiz.php
**Purpose**: Interactive training quiz where users guess which locker contains an item.

### quiz/track_attempts.php
**Purpose**: Records quiz attempt scores to session or database.

### quiz/get_score.php
**Purpose**: Returns current quiz score for the session.

---

## 12. System Administration Module

### admin.php
**Purpose**: Admin control panel hub. All admin functions accessible via button grid.

**Sections**:
- Maintenance (trucks, lockers, items)
- Operations (find, reset checks, QR codes)
- Communication (email management)
- Reports and backups
- System (login logs, demo controls)

### backups.php
**Purpose**: Database backup download utility.

### show_code.php
**Purpose**: Display and manage protection codes (used when `CHECKPROTECT` is enabled).

### demo_clean_tables.php
**Purpose**: Reset check data in demo mode. Only visible when `IS_DEMO` is true.

### settings.php
**Purpose**: User settings management (e.g., color-blind mode toggle).

---

## 13. Database Schema Reference

### Core Tables

```sql
CREATE TABLE trucks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    relief TINYINT(1) DEFAULT 0
);

CREATE TABLE lockers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    truck_id INT NOT NULL,
    notes TEXT,
    FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE
);

CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    locker_id INT NOT NULL,
    FOREIGN KEY (locker_id) REFERENCES lockers(id) ON DELETE CASCADE
);

CREATE TABLE checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    locker_id INT NOT NULL,
    check_date DATETIME NOT NULL,
    checked_by VARCHAR(255),
    ignore_check TINYINT(1) DEFAULT 0,
    FOREIGN KEY (locker_id) REFERENCES lockers(id) ON DELETE CASCADE
);

CREATE TABLE check_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_id INT NOT NULL,
    item_id INT NOT NULL,
    is_present TINYINT(1) DEFAULT 0,
    FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

CREATE TABLE check_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_id INT NOT NULL,
    note TEXT,
    FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
);
```

### Swap Tables (mirror check structure)
```sql
swap (id, locker_id, swapped_by, ignore_check, swap_date)
swap_items (id, swap_id, item_id, is_present)
swap_notes (id, swap_id, note)
```

### System Tables
```sql
audit_log (id, table_name, record_id, deleted_data JSON, deleted_at, deleted_by)
login_log (id, ip_address, user_agent, login_time, success, browser_info, country, city)
email_addresses (id, email)
protection_codes (id, code, description)
relief_items (id, truck_name, locker_name, item_name, item_id, relief)
locker_item_deletion_log (id, truck_name, locker_name, item_name, deleted_at)
```

### Audit Triggers
Four BEFORE DELETE triggers save complete row data as JSON to `audit_log`:
- `audit_items_delete`
- `audit_lockers_delete`
- `audit_trucks_delete`
- `audit_checks_delete`

---

## 14. AJAX API Reference

All AJAX endpoints live within their respective PHP files, detected by `$_GET['ajax']`.

### maintain_locker_items.php

| Endpoint | Parameters | Response |
|----------|-----------|----------|
| `?ajax=get_lockers` | `truck_id` | `[{id, name}, ...]` |
| `?ajax=get_items` | `truck_filter`, `locker_filter` | `{items: [...], count, truck_name, locker_name}` |

### check_locker_items.php

| Endpoint | Parameters | Response |
|----------|-----------|----------|
| `?ajax=get_lockers` | `truck_id` | `[{id, name}, ...]` |
| `?ajax=get_items` | `locker_id` | `[{id, name}, ...]` |

---

## 15. CSS Component Reference

### Layout Classes
| Class | Purpose |
|-------|---------|
| `.item-grid` | Auto-fill grid for items (minmax 120px) |
| `.item-card` | Tappable card in item grid |
| `.item-card.checked` | Green checked state |
| `.locker-grid` | Auto-fill grid for lockers |
| `.locker-cell` | Locker status cell with color |
| `.truck-listing` | Truck section container |

### Interactive Classes
| Class | Purpose |
|-------|---------|
| `.button.touch-button` | Standard touch-friendly button (navy, 15-20px padding) |
| `.submit-button` | Form submit button (navy, centered) |
| `.modal` / `.modal-content` | Overlay dialog |
| `.close-button` | Modal close X |

### Form Classes
| Class | Purpose |
|-------|---------|
| `.input-container` | Form field wrapper (50% width) |
| `.button-container` | Button group wrapper (centered) |
| `.add-truck-form` | Flex form layout (max-width 800px, centered) |
| `.add-locker-form` | Flex form layout (max-width 800px, left-aligned) |
| `.filter-section` | Filter controls container |
| `.stats-section` | Summary statistics display |

### Status/Display Classes
| Class | Purpose |
|-------|---------|
| `.badge` | Count badge (cyan background, absolute positioned) |
| `.version-number` | Footer version display |
| `.demo-mode` | Body class for demo watermark/stripes |
| `.relief-truck-indicator` | Gray overlay for relief trucks |
| `.truck-button` | Large truck link button |

### Brand Colors
| Color | Hex | Usage |
|-------|-----|-------|
| Navy | `#12044C` | Primary buttons, truck buttons |
| Hover blue | `#0056b3` | Button hover state |
| Cancel gray | `#6c757d` | Cancel/secondary buttons |
| Green | `green` | Checked/present items |
| Light gray | `#f0f0f0` | Unchecked item cards |
| Alert yellow | `#fff3cd` | No-items warning |
| Info blue | `#e7f3ff` | Stats section background |

### Mobile Breakpoint
All responsive rules trigger at `max-width: 768px` with increased padding and font sizes for touch targets.
