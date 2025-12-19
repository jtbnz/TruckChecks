# PHP 8.4 Compatibility Test Plan

## Test Environment Setup

### Option 1: Docker Testing (Recommended)
```bash
cd /path/to/TruckChecks/Docker
docker-compose down
docker-compose build --no-cache
docker-compose up -d

# Verify PHP version
docker exec -it apache_php php -v
```

### Option 2: Local PHP 8.4 Testing
```bash
# Install PHP 8.4 (see UPGRADE_TO_PHP_8.md for instructions)
php -v  # Should show PHP 8.4.x

# Set up test database
mysql -u root -p < Docker/setup.sql

# Configure application
cp config_sample.php config.php
# Edit config.php with your test database credentials
```

## Pre-Test Checklist
- [ ] Backup production database
- [ ] Set up test environment with PHP 8.4
- [ ] Configure test database
- [ ] Install Composer dependencies: `composer install`
- [ ] Verify all dependencies are installed
- [ ] Set DEBUG mode to true in config.php for testing

## Functional Test Cases

### 1. Core Application Tests

#### 1.1 Session Management
- [ ] **Test:** Navigate to index.php
- [ ] **Expected:** Page loads without errors
- [ ] **Verify:** No session-related warnings in error log
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 1.2 Database Connection
- [ ] **Test:** Load any page that uses database
- [ ] **Expected:** Database connects successfully with PDO
- [ ] **Verify:** No PDO connection errors
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 2. Authentication Tests

#### 2.1 Login Functionality
- [ ] **Test:** Navigate to login.php
- [ ] **Test:** Enter valid credentials
- [ ] **Expected:** Successful login and redirect
- [ ] **Verify:** Session is created, cookie is set
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 2.2 Login Logging
- [ ] **Test:** Check login_logs.php after login
- [ ] **Expected:** Login attempt is recorded with browser info
- [ ] **Verify:** IP geolocation data (if configured), browser/OS detection
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 2.3 Logout
- [ ] **Test:** Click logout
- [ ] **Expected:** Session destroyed, redirected to login
- [ ] **Verify:** Cannot access admin pages after logout
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 3. Admin Panel Tests

#### 3.1 Access Admin Panel
- [ ] **Test:** Navigate to admin.php (while logged in)
- [ ] **Expected:** Admin panel loads with all buttons
- [ ] **Verify:** No PHP errors, version displayed correctly
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 3.2 Maintain Trucks
- [ ] **Test:** Add a new truck
- [ ] **Expected:** Truck added successfully
- [ ] **Test:** Edit truck name
- [ ] **Expected:** Changes saved
- [ ] **Test:** Delete truck (if no lockers attached)
- [ ] **Expected:** Truck removed
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 3.3 Maintain Lockers
- [ ] **Test:** Add a new locker to a truck
- [ ] **Expected:** Locker created successfully
- [ ] **Test:** Edit locker details
- [ ] **Expected:** Changes saved
- [ ] **Test:** Delete empty locker
- [ ] **Expected:** Locker removed
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 3.4 Maintain Locker Items
- [ ] **Test:** Add item to locker
- [ ] **Expected:** Item created successfully
- [ ] **Test:** Filter items by truck
- [ ] **Expected:** Correct items displayed
- [ ] **Test:** Filter items by locker
- [ ] **Expected:** Correct items displayed
- [ ] **Test:** Edit item name
- [ ] **Expected:** Changes saved
- [ ] **Test:** Delete item
- [ ] **Expected:** Item removed and logged in audit_log
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 4. Check Functionality Tests

#### 4.1 Perform Locker Check
- [ ] **Test:** Navigate to check_locker_items.php
- [ ] **Test:** Select a truck and locker
- [ ] **Test:** Mark items as present/missing
- [ ] **Test:** Add notes
- [ ] **Test:** Submit check
- [ ] **Expected:** Check recorded in database
- [ ] **Verify:** check_items table updated
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 4.2 View Check History
- [ ] **Test:** View index.php after check
- [ ] **Expected:** Latest check status displayed
- [ ] **Verify:** Correct color coding (green/red)
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 5. PDF Generation Tests

#### 5.1 Changeover PDF
- [ ] **Test:** Navigate to changeover_pdf.php
- [ ] **Test:** Select truck and locker
- [ ] **Expected:** PDF generates and downloads
- [ ] **Verify:** PDF opens correctly, contains expected data
- [ ] **Check:** No TCPDF warnings in error log
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 5.2 QR Codes PDF
- [ ] **Test:** Navigate to qr-codes-pdf.php
- [ ] **Expected:** PDF with QR codes generates
- [ ] **Verify:** QR codes are scannable
- [ ] **Check:** No TCPDF warnings
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 5.3 Items Report (A3)
- [ ] **Test:** Navigate to list_all_items_report_a3.php
- [ ] **Test:** Select a truck
- [ ] **Expected:** A3-sized PDF generates
- [ ] **Verify:** All items listed correctly
- [ ] **Check:** No TCPDF warnings
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 6. QR Code Tests

#### 6.1 Generate QR Codes
- [ ] **Test:** Navigate to qr-codes.php
- [ ] **Expected:** QR codes display for all lockers
- [ ] **Test:** Scan a QR code with phone
- [ ] **Expected:** Redirects to correct check page
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 7. Email Tests

#### 7.1 Email Configuration
- [ ] **Test:** Navigate to email_admin.php
- [ ] **Test:** Add email address
- [ ] **Expected:** Email saved successfully
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 7.2 Send Email Report
- [ ] **Test:** Navigate to email_results.php
- [ ] **Expected:** Email sent with missing items report
- [ ] **Verify:** Email received with correct content
- [ ] **Check:** No PHPMailer errors
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 8. Report Tests

#### 8.1 Locker Check Report
- [ ] **Test:** Navigate to locker_check_report.php
- [ ] **Test:** Select date range
- [ ] **Expected:** Report displays check history
- [ ] **Verify:** Data accuracy
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 8.2 Locker Report
- [ ] **Test:** Navigate to locker_report.php
- [ ] **Test:** Select date range
- [ ] **Expected:** Report displays locker status
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 8.3 Deleted Items Report
- [ ] **Test:** Navigate to deleted_items_report.php
- [ ] **Expected:** Shows audit log of deleted items
- [ ] **Verify:** JSON data displays correctly
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 8.4 All Items Report
- [ ] **Test:** Navigate to list_all_items_report.php
- [ ] **Expected:** Lists all items in system
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 9. Changeover Tests

#### 9.1 Changeover Management
- [ ] **Test:** Navigate to changeover.php
- [ ] **Test:** Toggle relief state for truck
- [ ] **Expected:** Relief state updates
- [ ] **Test:** Mark items for relief
- [ ] **Expected:** Items marked correctly
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 10. Backup Tests

#### 10.1 Database Backup
- [ ] **Test:** Navigate to backups.php
- [ ] **Test:** Click "Download Backup"
- [ ] **Expected:** SQL file downloads
- [ ] **Verify:** File contains valid SQL
- [ ] **Test:** Try to import backup
- [ ] **Expected:** Import succeeds
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 11. Search Tests

#### 11.1 Find Item
- [ ] **Test:** Navigate to find.php
- [ ] **Test:** Search for an item
- [ ] **Expected:** Results display correctly
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 11.2 Search Item (AJAX)
- [ ] **Test:** Use search_item.php endpoint
- [ ] **Expected:** JSON results returned
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 12. Quiz Tests

#### 12.1 Quiz Functionality
- [ ] **Test:** Navigate to quiz/quiz.php
- [ ] **Test:** Play the item location quiz
- [ ] **Expected:** Quiz works correctly
- [ ] **Verify:** Scores calculated
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 13. Demo Mode Tests (If Enabled)

#### 13.1 Demo Clean
- [ ] **Test:** Enable demo mode in config
- [ ] **Test:** Navigate to demo_clean_tables.php
- [ ] **Test:** Clean demo data
- [ ] **Expected:** Check data removed
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 14. Settings Tests

#### 14.1 Security Code
- [ ] **Test:** Navigate to show_code.php
- [ ] **Test:** Set security code
- [ ] **Expected:** Code saved
- [ ] **Test:** Verify code on check page
- [ ] **Expected:** Code required before check
- [ ] **Status:** ⬜ Pass / ⬜ Fail

#### 14.2 Settings Page
- [ ] **Test:** Navigate to settings.php
- [ ] **Test:** Modify settings
- [ ] **Expected:** Settings saved
- [ ] **Status:** ⬜ Pass / ⬜ Fail

## PHP 8.4 Specific Tests

### 15. Type Safety Tests
- [ ] **Test:** Pass null to functions expecting strings
- [ ] **Expected:** Proper error handling or type coercion
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 16. Array/String Access Tests
- [ ] **Test:** Access array elements with various methods
- [ ] **Expected:** No deprecation warnings
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 17. Null Coalescing Tests
- [ ] **Test:** Access `$_GET` and `$_POST` arrays
- [ ] **Expected:** Proper null handling with `isset()` or `??`
- [ ] **Status:** ⬜ Pass / ⬜ Fail

## Performance Tests

### 18. Load Time Tests
- [ ] **Test:** Measure page load times
- [ ] **Compare:** PHP 7.4 vs PHP 8.4 performance
- [ ] **Expected:** Same or better performance
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 19. Memory Usage Tests
- [ ] **Test:** Monitor memory usage during operations
- [ ] **Expected:** Reasonable memory consumption
- [ ] **Status:** ⬜ Pass / ⬜ Fail

## Error Log Review

### 20. Check for Warnings/Notices
- [ ] Review error logs after all tests
- [ ] Look for:
  - [ ] Deprecation warnings
  - [ ] Type errors
  - [ ] Null pointer warnings
  - [ ] Array access warnings
- [ ] **Status:** ⬜ Pass / ⬜ Fail

## Browser Compatibility Tests

### 21. Cross-Browser Testing
Test in multiple browsers:
- [ ] Chrome/Chromium
- [ ] Firefox
- [ ] Safari
- [ ] Edge
- [ ] Mobile browsers (iOS Safari, Chrome Android)

## Security Tests

### 22. SQL Injection Tests
- [ ] Test input validation on all forms
- [ ] Verify prepared statements are used
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 23. XSS Tests
- [ ] Test HTML output escaping
- [ ] Verify user input is sanitized
- [ ] **Status:** ⬜ Pass / ⬜ Fail

### 24. Session Security
- [ ] Verify session regeneration
- [ ] Check session timeout
- [ ] Test logout functionality
- [ ] **Status:** ⬜ Pass / ⬜ Fail

## Test Results Summary

### Critical Issues Found
_List any critical issues that block deployment:_
- 

### Non-Critical Issues Found
_List minor issues or improvements needed:_
-

### Performance Notes
_Document any performance observations:_
-

### Recommendations
_List any recommendations for deployment:_
-

## Sign-Off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Tester | | | |
| Developer | | | |
| Manager | | | |

## Conclusion

Overall Test Status: ⬜ PASS / ⬜ FAIL / ⬜ PASS WITH ISSUES

Ready for Production: ⬜ YES / ⬜ NO / ⬜ WITH MODIFICATIONS

Notes:
