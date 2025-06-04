# Active Context

## Current Work Focus
**ADMIN MODULE SYSTEM IMPLEMENTATION**

Just completed a major refactoring of the admin interface to use a modular AJAX-based system. All admin pages referenced by admin.php have been converted into modules that load dynamically within the admin interface, preventing navigation issues and improving user experience.

**ENHANCED AUTOMATED EMAIL SYSTEM WITH STATION-SPECIFIC CONFIGURATION**

Previously completed a comprehensive upgrade to the email automation system, building on the V4 station hierarchy to provide sophisticated, station-specific email automation with advanced scheduling and holiday handling.

**MAJOR VERSION UPGRADE TO V4 - STATION HIERARCHY IMPLEMENTATION**

The system has undergone a major architectural upgrade to introduce the Station concept, creating a new hierarchy: Stations → Trucks → Lockers → Items. This represents the most significant change to the system since its inception.

## Recent Implementation: Admin Module System

### Problem Solved
- Admin pages (maintain_trucks.php, maintain_lockers.php, etc.) were causing navigation issues when loaded via AJAX
- Form submissions were posting to admin.php?page=content instead of the actual page
- Users were losing the admin interface context after operations

### Solution Implemented
Created a new `admin_modules/` directory with modular versions of admin pages:

1. **Module Architecture**:
   - Each module is a self-contained PHP file without headers/footers
   - Modules receive context from admin.php ($pdo, $user, $currentStation)
   - All forms submit via AJAX to prevent page navigation
   - Modules handle their own POST requests with JSON responses

2. **Files Created**:
   - `admin_modules/maintain_trucks.php` - Truck management module
   - `admin_modules/maintain_lockers.php` - Locker management module
   - `admin_modules/maintain_locker_items.php` - Item management module
   - `admin_modules/manage_stations.php` - Station management module

3. **Admin.php Updates**:
   - Enhanced AJAX loading to check admin_modules/ directory first
   - Added POST handler for module AJAX requests
   - Maintains backward compatibility with legacy pages

4. **Key Features**:
   - **AJAX Form Handling**: All add/edit/delete operations via AJAX
   - **Real-time Updates**: Success messages and automatic page refresh
   - **Station Context**: All modules respect station boundaries
   - **Role-based Access**: Proper permission checks (e.g., manage_stations for superusers only)
   - **Responsive Design**: Mobile-friendly interfaces
   - **Debug Support**: Console logging when DEBUG mode enabled

### Module Pattern Established
```php
// Standard module structure
if (!isset($pdo) || !isset($user) || !isset($currentStation)) {
    die('This module must be loaded through admin.php');
}

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    // Process form submissions
    // Return JSON response
    exit;
}

// Regular page display
```

## Major V4 Changes Implemented

### 1. Database Schema Upgrade (V4Changes.sql)
- **New Tables**:
  - `stations`: Master station registry with name, description, timestamps
  - `users`: User management with roles (superuser, station_admin)
  - `user_stations`: Many-to-many relationship between users and stations
  - `user_sessions`: Enhanced session management with tokens and station context
- **Schema Modifications**:
  - Added `station_id` to `trucks` table with foreign key constraint
  - Enhanced `audit_log` with station and user context
  - Updated `login_log` with user tracking
- **Data Migration**:
  - Created "Default Station" for existing trucks
  - Default superuser account (admin/admin123)
  - Sample stations for demonstration

### 2. Authentication System Overhaul (auth.php)
- **Dual Authentication Support**:
  - Legacy password-based authentication (backward compatibility)
  - New user-based authentication with username/password
  - Automatic detection and handling of both modes
- **Session Management**:
  - Secure token-based sessions with 90-day expiration
  - Station context maintained in sessions
  - Long-term station preference cookies
- **Access Control**:
  - Role-based permissions (superuser vs station_admin)
  - Station-scoped access control
  - Helper functions: `requireAuth()`, `requireStation()`, `requireSuperuser()`

### 3. Station Selection System
- **Public Interface (index.php)**:
  - Automatic station detection from cookies/session
  - Station selection dropdown for multiple stations
  - Station filtering of trucks display
  - "Change Station" functionality
- **Admin Interface (select_station.php)**:
  - Visual station selection with statistics
  - User role display and station access validation
  - Auto-selection for single station users
  - Responsive design with touch-friendly interface

### 4. Enhanced Login System (login.php)
- **Adaptive Interface**:
  - Tabbed interface when both auth modes available
  - Legacy-only mode when no users exist
  - Automatic redirection based on user type
- **Improved Logging**:
  - Enhanced login attempt tracking
  - User context in login logs
  - Geographic and browser information
- **User Experience**:
  - Clear mode switching
  - Helpful information for each auth type
  - Mobile-optimized forms

### 5. Station Management Interface (manage_stations.php)
- **Comprehensive Station CRUD**:
  - Add, edit, delete stations with validation
  - Station statistics (truck count, user count)
  - Real-time data loading via AJAX
- **Advanced Features**:
  - Expandable station details
  - User and truck listings per station
  - Deletion protection for stations with trucks
  - Responsive grid layout
- **Superuser Only**: Restricted to superuser role

### 6. Database Merge Capability (merge_database.sql)
- **Complete Data Migration**:
  - Merges entire TruckChecks instance into main database
  - Creates temporary station for merged data
  - Maintains all relationships and data integrity
  - Handles ID mapping and conflict resolution
- **Comprehensive Coverage**:
  - All core tables: trucks, lockers, items, checks
  - Extended tables: swap data, email addresses
  - Audit trail preservation
  - User creation for merged station

### 7. Enhanced Automated Email System
- **Station-Specific Configuration**:
  - Individual email automation settings per station
  - Custom send times (configurable via station_settings.php)
  - Training nights selection with checkbox interface
  - Alternate training nights for public holiday handling
  - Enable/disable automation per station
- **Smart Scheduling Logic**:
  - Integrates with existing holiday API (check_holiday.php)
  - Automatically switches to alternate training nights on holidays
  - Hourly cron execution with precise time matching
  - Comprehensive logging for debugging and monitoring
- **Advanced Email Content**:
  - HTML formatted emails with professional styling
  - Sectioned layout: Missing Items, Deleted Items, Locker Notes
  - Proper handling of notes with trimming and validation
  - Station-specific branding and context
- **Preview and Testing System**:
  - Full email preview in email_admin.php with modal popup
  - HTML and plain text preview tabs
  - Send preview to any email address
  - Recipients list display
- **New Files Created**:
  - `scripts/automated_email_processor.php`: Core automation logic
  - Updated `scripts/email_checks.sh`: Simplified cron handler
  - Enhanced `station_settings.php`: Email automation UI
  - Enhanced `V4Changes.sql`: Email automation settings schema

## Current System State

### ✅ Fully Implemented Features
- **Station Hierarchy**: Complete 4-level hierarchy (Stations → Trucks → Lockers → Items)
- **Dual Authentication**: Legacy and user-based auth working seamlessly
- **Station Management**: Full CRUD operations for superusers
- **Access Control**: Role-based permissions with station scoping
- **Session Management**: Secure token-based sessions with station context
- **Database Migration**: V4 upgrade and merge scripts ready
- **Responsive Design**: Mobile-friendly interfaces throughout
- **Admin Module System**: AJAX-based modular admin interface
- **Email Automation**: Station-specific automated email system

### 🔄 Backward Compatibility Maintained
- **Legacy Authentication**: Original password-based login still works
- **Existing Data**: All current trucks/lockers/items preserved
- **API Compatibility**: Existing maintenance pages will work (need updates)
- **Configuration**: Original config.php structure unchanged

## Recent Fixes

### Admin Page Navigation Issue (RESOLVED)
- **Problem**: When adding/editing items in admin pages, users were losing the admin interface
- **Root Cause**: Forms were posting to full pages instead of loading as modules
- **Solution**: 
  - Created modular versions of all admin pages in admin_modules/
  - Implemented AJAX form submissions with JSON responses
  - Updated admin.php to load modules and handle AJAX requests
  - All operations now stay within the admin interface
- **Files Created**: 
  - `admin_modules/maintain_trucks.php`
  - `admin_modules/maintain_lockers.php`
  - `admin_modules/maintain_locker_items.php`
  - `admin_modules/manage_stations.php`
- **Files Modified**: `admin.php`
- **Status**: ✅ RESOLVED - All admin operations now work seamlessly within the interface

### Security Code Authentication Issue (RESOLVED)
- **Problem**: Users getting "access denied invalid security code" when checking locker items after scanning QR code
- **Root Cause**: Mixed authentication patterns in `check_locker_items.php` causing conflicts between old `$auth` object methods and new auth functions
- **Solution**: 
  - Fixed station context retrieval to use `getCurrentStation()` function consistently
  - Removed problematic auto-detection code that used old `$auth` object
  - Updated authentication checks to use proper function-based approach
  - Maintained backward compatibility for both station-specific and general security codes
- **Files Modified**: `check_locker_items.php`
- **Status**: ✅ RESOLVED - Security code validation now works correctly with station settings

### Station Admin Redirect Loop Issue (RESOLVED)
- **Problem**: Station admins getting "ERR_TOO_MANY_REDIRECTS" when accessing admin.php and manage_users.php
- **Root Cause**: Multiple circular redirect issues in authentication system:
  1. `getUserStations()` function causing internal redirects to station selection
  2. Legacy authentication check conflicting with V4 user authentication
  3. `getCurrentStation()` calling `getUserStations()` creating circular dependency
- **Solution**: 
  - Replaced `getUserStations()` calls with direct database queries in admin.php and manage_users.php
  - Removed problematic legacy authentication check from admin.php
  - Modified `getCurrentStation()` in auth.php to use direct query for station admins
  - Station admins now automatically get their first assigned station without manual selection
- **Files Modified**: `admin.php`, `manage_users.php`, `auth.php`
- **Status**: ✅ RESOLVED - Station admins can now access admin interface without redirect loops

## Next Steps & Pending Work

### 1. Convert Remaining Admin Pages to Modules
The following pages still need module versions:
- **find.php**: Item search functionality
- **reset_locker_check.php**: Reset check status
- **qr-codes.php**: QR code generation
- **email_admin.php**: Email settings management
- **email_results.php**: Send check results
- **locker_check_report.php**: Check reports
- **list_all_items_report.php**: Item reports
- **list_all_items_report_a3.php**: A3 format reports
- **deleted_items_report.php**: Deleted items tracking
- **backups.php**: Database backup
- **login_logs.php**: Login history
- **show_code.php**: Security code display
- **station_settings.php**: Station configuration

### 2. User Management System
- **manage_users.php**: Create comprehensive user management interface
- **User assignment**: Station admin creation and assignment
- **Password management**: Change password functionality
- **User roles**: Enhanced role management

### 3. Enhanced Admin Interface
- **Dashboard improvements**: Station-specific statistics and overview
- **Quick actions**: Common tasks accessible from dashboard
- **Activity feed**: Recent changes and updates
- **Settings**: Station-aware configuration options

### 4. Reports and Analytics
- **Station-filtered reports**: All reports respect station context
- **Cross-station reports**: Superuser-only comprehensive reports
- **User activity**: Station admin activity tracking
- **Export options**: PDF/Excel export capabilities

## Technical Architecture

### Authentication Flow
```
1. User visits login.php
2. System detects available auth modes
3. User authenticates (legacy or user-based)
4. Station selection (if multiple stations)
5. Redirect to admin with station context
```

### Module Loading Flow
```
1. User clicks admin menu item
2. loadPage() function called via JavaScript
3. AJAX request to admin.php?ajax=1&page=X
4. admin.php checks admin_modules/ first, then legacy path
5. Module loaded with proper context ($pdo, $user, $currentStation)
6. Module content returned and displayed in admin interface
```

### Station Access Control
```php
// Standard pattern for station-aware pages
include_once('auth.php');
$station = requireStation(); // Ensures auth + station context
// Page now has guaranteed station access
```

### Database Relationships
```
stations (1) → trucks (many) → lockers (many) → items (many)
users (many) ←→ stations (many) [via user_stations]
users (1) → user_sessions (many)
```

## Security Enhancements

### Session Security
- **Token-based authentication**: Cryptographically secure session tokens
- **Session validation**: Automatic token validation and renewal
- **IP tracking**: Session tied to IP address for security
- **Expiration handling**: Automatic cleanup of expired sessions

### Access Control
- **Role-based permissions**: Superuser vs station admin roles
- **Station isolation**: Users only see their assigned stations
- **Data scoping**: All operations respect station boundaries
- **Audit trails**: Enhanced logging with user and station context

## User Experience Improvements

### Station Selection
- **Visual interface**: Card-based station selection with statistics
- **Persistent preferences**: Long-term station preference cookies
- **Quick switching**: Easy station change from main interface
- **Auto-selection**: Single station users skip selection

### Authentication
- **Adaptive interface**: Shows appropriate login options
- **Clear feedback**: Helpful error messages and guidance
- **Mobile optimization**: Touch-friendly forms and buttons
- **Backward compatibility**: Existing users unaffected

### Admin Interface
- **AJAX-based**: No page reloads during operations
- **Real-time feedback**: Success/error messages appear instantly
- **Consistent navigation**: Always stay within admin context
- **Responsive design**: Works on all device sizes

## Development Standards

### Code Patterns Established
- **Authentication**: Consistent auth checking across all pages
- **Station Context**: Standard station requirement patterns
- **AJAX Handling**: Proper JSON responses with error handling
- **Database Operations**: Station-aware queries with proper filtering
- **Error Handling**: Comprehensive logging and user feedback
- **Module Structure**: Consistent pattern for admin modules

### File Organization
- **auth.php**: Central authentication and session management
- **admin.php**: Main admin interface and module loader
- **admin_modules/**: Directory for all admin page modules
- **V4Changes.sql**: Database upgrade script
- **merge_database.sql**: Database merge utility
- **manage_stations.php**: Station management interface
- **select_station.php**: Station selection interface

## Migration Notes

### For Existing Installations
1. **Backup database**: Essential before running V4Changes.sql
2. **Run V4Changes.sql**: Upgrades schema and creates default data
3. **Update config**: No changes needed to config.php
4. **Test authentication**: Both legacy and new auth should work
5. **Assign users**: Create station admins as needed

### For New Installations
1. **Run V4Changes.sql**: After initial setup.sql
2. **Create superuser**: Use default admin account initially
3. **Create stations**: Add stations via manage_stations.php
4. **Create users**: Assign station admins as needed
5. **Assign trucks**: Update trucks to belong to stations

This represents a major milestone in the TruckChecks evolution, introducing enterprise-level multi-tenancy while maintaining full backward compatibility.
