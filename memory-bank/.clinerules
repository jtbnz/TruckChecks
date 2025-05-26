# TruckChecks Project Intelligence

## Critical Implementation Patterns

### Authentication Pattern (ALWAYS FIRST)
```php
// This MUST be at the top of every protected page
if (!isset($_COOKIE['logged_in_' . DB_NAME]) || $_COOKIE['logged_in_' . DB_NAME] != 'true') {
    header('Location: login.php');
    exit;
}
```

### AJAX Handler Pattern (BEFORE HTML OUTPUT)
```php
// Handle AJAX requests FIRST, before any HTML output
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    // Process request and return JSON
    exit;
}
```

### Database Connection Pattern
```php
include('config.php');
include 'db.php';
$db = get_db_connection();
```

## User Preferences & Workflow

### Real-time Filtering Requirements
- User expects instant filtering without page reloads
- Truck selection should automatically populate locker dropdown
- Filter state should persist in URL for bookmarking
- Summary statistics should update in real-time

### Form Interaction Preferences
- Two-step selection for truck → locker → item workflow
- Clear labels and helpful placeholder text
- Touch-friendly buttons for mobile use
- Confirmation dialogs for destructive actions

### UI/UX Expectations
- "ALL" options in dropdowns instead of separate buttons
- Consistent styling across all maintenance pages
- Edit functionality should be in-place, not separate pages
- Cancel buttons should return to filtered state

## Critical Code Patterns

### CRUD Operations Standard
```php
// Add
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_item'])) {
    // Validation and insert
}

// Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_item'])) {
    // Validation and update
}

// Delete
if (isset($_GET['delete_item_id'])) {
    // Delete with confirmation
}
```

### AJAX Response Pattern
```javascript
function updateData() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', window.location.pathname + '?ajax=endpoint&param=value', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            const data = JSON.parse(xhr.responseText);
            // Update DOM elements
        }
    };
    xhr.send();
}
```

## Project-Specific Intelligence

### Database Relationships
- trucks (1) → lockers (many) → items (many)
- All foreign keys should cascade on delete
- Audit triggers needed for: items, lockers, trucks, checks

### Security Requirements
- All database queries MUST use prepared statements
- All output MUST be HTML escaped with htmlspecialchars()
- Cookie-based authentication with database name integration
- No direct SQL string concatenation EVER

### Performance Considerations
- AJAX endpoints should return minimal JSON data
- Database queries should use proper JOINs, not multiple queries
- Filter operations should be server-side, not client-side
- URL state management for bookmarkable filtered views

## Known Challenges & Solutions

### AJAX Implementation Issues
- **Problem**: AJAX returning HTML instead of JSON
- **Solution**: Handle AJAX requests before any HTML output
- **Pattern**: Check for $_GET['ajax'] at very top of file

### Authentication Issues
- **Problem**: Logout not working properly
- **Solution**: Use setcookie() with proper expiration and path
- **Pattern**: Set cookie to empty value with past expiration

### Edit Functionality
- **Problem**: Edit forms can be accidentally removed during fixes
- **Solution**: Always maintain separate add/edit form logic
- **Pattern**: Use $_GET['edit_id'] to trigger edit mode

## Development Workflow Intelligence

### File Modification Order
1. Always check authentication first
2. Handle AJAX requests before HTML
3. Process form submissions
4. Query database for display data
5. Render HTML with proper escaping

### Testing Checklist
- [ ] Login/logout functionality
- [ ] CRUD operations on all entities
- [ ] Real-time filtering works
- [ ] Mobile touch interface
- [ ] Browser console shows no errors
- [ ] URL state management works

### Code Review Points
- All database queries use prepared statements
- All user output is HTML escaped
- AJAX endpoints return proper JSON
- Authentication checks are present
- Error handling is comprehensive

## User Feedback Integration

### Implemented Requests
- ✅ Real-time truck/locker filtering
- ✅ Enhanced add item workflow
- ✅ Edit functionality restoration
- ✅ "ALL" options instead of buttons

### Pending Requests
- 🔄 Audit system for deletion tracking
- 🔄 Enhanced mobile optimization
- 🔄 Bulk operations capability

## Evolution of Project Decisions

### Authentication Evolution
- Started with simple login
- Added database name integration for multi-tenancy
- Fixed logout cookie handling issues

### Filtering Evolution
- Started with basic dropdowns
- Added real-time AJAX filtering
- Enhanced with URL state management
- Added guided add item workflow

### UI Evolution
- Started with basic forms
- Added touch-friendly buttons
- Implemented in-place editing
- Enhanced with real-time updates

## Critical Success Factors

### Technical
- Proper AJAX implementation with JSON responses
- Consistent authentication patterns
- Efficient database queries with proper relationships
- Mobile-friendly responsive design

### User Experience
- Instant feedback on all actions
- Logical workflow progression
- Minimal clicks to accomplish tasks
- Clear visual feedback and confirmations

### Maintenance
- Comprehensive memory bank documentation
- Consistent code patterns across files
- Proper error handling and logging
- Clear separation of concerns
