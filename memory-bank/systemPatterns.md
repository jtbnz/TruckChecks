# System Patterns & Architecture

## Database Architecture

### Core Entity Relationships
```
trucks (id, name)
  ↓ 1:many
lockers (id, name, truck_id)
  ↓ 1:many
items (id, name, locker_id)
  ↓ 1:many
checks (id, item_id, status, timestamp, notes)
```

### Key Tables
- **trucks**: Master truck registry
- **lockers**: Locker assignments per truck
- **items**: Individual inventory items
- **checks**: Check history and status
- **audit_log**: Audit trail for deletions (recently added)

### Database Patterns
- **Foreign Key Constraints**: Maintain referential integrity
- **Cascading Deletes**: Automatic cleanup of dependent records
- **Audit Triggers**: Track data changes for compliance
- **Indexed Lookups**: Optimized queries for filtering

## Application Architecture

### File Structure Patterns
```
/
├── config.php              # Database configuration
├── db.php                   # Database connection utilities
├── index.php               # Main dashboard
├── login.php               # Authentication
├── admin.php               # Administrative hub
├── maintain_*.php          # CRUD operations
├── *_report.php            # Report generation
├── templates/              # Shared UI components
├── styles/                 # CSS styling
├── scripts/                # Utility scripts
├── Docker/                 # Containerization
└── memory-bank/            # Documentation
```

### Code Organization Patterns

#### Authentication Pattern
```php
// Standard auth check in all protected pages
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}
```

#### Database Connection Pattern
```php
include('config.php');
include 'db.php';
$db = get_db_connection();
```

#### AJAX Handler Pattern
```php
// Handle AJAX requests FIRST, before any HTML output
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    // Process request and return JSON
    exit;
}
```

## UI/UX Patterns

### Responsive Design
- **Mobile-First**: Touch-friendly buttons and inputs
- **Flexible Layouts**: Adapt to different screen sizes
- **Consistent Styling**: Shared CSS classes across pages

### Form Patterns
- **Input Containers**: Consistent form field styling
- **Button Containers**: Standardized button layouts
- **Validation**: Client and server-side validation
- **Error Handling**: User-friendly error messages

### Navigation Patterns
- **Breadcrumb Navigation**: Clear page hierarchy
- **Action Buttons**: Consistent placement and styling
- **Filter Sections**: Standardized filtering interfaces

## Data Flow Patterns

### CRUD Operations
1. **Create**: Form submission → Validation → Database insert → Redirect
2. **Read**: Database query → Data processing → Template rendering
3. **Update**: Form pre-population → Submission → Validation → Database update
4. **Delete**: Confirmation → Database delete → Redirect

### Real-time Filtering
1. **User Selection**: Dropdown change event
2. **AJAX Request**: Send filter parameters
3. **Server Processing**: Query database with filters
4. **JSON Response**: Return filtered data
5. **DOM Update**: Update page content without reload

### Report Generation
1. **Parameter Collection**: User selects report criteria
2. **Data Query**: Execute complex database queries
3. **Processing**: Format data for output
4. **Output Generation**: Create PDF/HTML/CSV
5. **Delivery**: Download or email report

## Security Patterns

### Input Validation
- **Prepared Statements**: Prevent SQL injection
- **HTML Escaping**: Prevent XSS attacks
- **Parameter Validation**: Check data types and ranges

### Authentication
- **Cookie-based Sessions**: Simple session management
- **Database Name Integration**: Multi-tenant support
- **Logout Functionality**: Secure session termination

### Access Control
- **Page-level Protection**: Authentication checks on all pages
- **Admin Functions**: Restricted administrative operations
- **Data Isolation**: Users only see their data

## Performance Patterns

### Database Optimization
- **Indexed Queries**: Fast lookups on foreign keys
- **Efficient JOINs**: Minimize database round trips
- **Query Caching**: Reuse common query results

### Frontend Optimization
- **AJAX Loading**: Reduce page reloads
- **Lazy Loading**: Load data as needed
- **Client-side Caching**: Store frequently used data

### Resource Management
- **Connection Pooling**: Efficient database connections
- **Memory Management**: Clean up resources properly
- **Error Handling**: Graceful degradation on failures
