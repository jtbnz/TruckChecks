# Active Context

## Current Work Focus
**MAJOR VERSION UPGRADE TO V4 - STATION HIERARCHY IMPLEMENTATION**

The system is undergoing a major architectural upgrade to introduce the Station concept, creating a new hierarchy: Stations → Trucks → Lockers → Items. This represents the most significant change to the system since its inception.

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

## Current System State

### ✅ Fully Implemented Features
- **Station Hierarchy**: Complete 4-level hierarchy (Stations → Trucks → Lockers → Items)
- **Dual Authentication**: Legacy and user-based auth working seamlessly
- **Station Management**: Full CRUD operations for superusers
- **Access Control**: Role-based permissions with station scoping
- **Session Management**: Secure token-based sessions with station context
- **Database Migration**: V4 upgrade and merge scripts ready
- **Responsive Design**: Mobile-friendly interfaces throughout

### 🔄 Backward Compatibility Maintained
- **Legacy Authentication**: Original password-based login still works
- **Existing Data**: All current trucks/lockers/items preserved
- **API Compatibility**: Existing maintenance pages will work (need updates)
- **Configuration**: Original config.php structure unchanged

## Recent Fixes

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

## Next Steps & Pending Work

### 1. Update Maintenance Pages (High Priority)
- **maintain_trucks.php**: Add station assignment and filtering
- **maintain_lockers.php**: Ensure station context validation
- **maintain_locker_items.php**: Update for station-aware operations
- **All pages**: Implement station access control

### 2. User Management System
- **manage_users.php**: Create comprehensive user management interface
- **User assignment**: Station admin creation and assignment
- **Password management**: Change password functionality
- **User roles**: Enhanced role management

### 3. Enhanced Admin Interface
- **admin.php**: Update for role-based menu options
- **Station switching**: Quick station change in admin area
- **Dashboard**: Station-specific statistics and overview
- **Settings**: Station-aware configuration options

### 4. Settings and Configuration
- **settings.php**: Add station selection and management
- **Station preferences**: User-specific station settings
- **Configuration**: Station-specific settings if needed

### 5. Reports and Analytics
- **Station-filtered reports**: All reports respect station context
- **Cross-station reports**: Superuser-only comprehensive reports
- **User activity**: Station admin activity tracking

## Technical Architecture

### Authentication Flow
```
1. User visits login.php
2. System detects available auth modes
3. User authenticates (legacy or user-based)
4. Station selection (if multiple stations)
5. Redirect to admin with station context
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

## Development Standards

### Code Patterns Established
- **Authentication**: Consistent auth checking across all pages
- **Station Context**: Standard station requirement patterns
- **AJAX Handling**: Proper JSON responses with error handling
- **Database Operations**: Station-aware queries with proper filtering
- **Error Handling**: Comprehensive logging and user feedback

### File Organization
- **auth.php**: Central authentication and session management
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
