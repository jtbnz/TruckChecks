# Upgrading TruckChecks from PHP 7.4 to PHP 8.4

## Overview
This guide provides step-by-step instructions for upgrading your TruckChecks installation from PHP 7.4 to PHP 8.4.

## Pre-Upgrade Checklist

### 1. Backup Your System
- [ ] Backup your database
- [ ] Backup your application files
- [ ] Backup your configuration files
- [ ] Document your current PHP version: `php -v`

### 2. Review System Requirements
- [ ] Ensure your server supports PHP 8.4
- [ ] Verify MySQL/MariaDB compatibility (MySQL 5.7+ or MariaDB 10.3+)
- [ ] Check disk space availability

### 3. Review Dependencies
The application uses these PHP packages:
- `tecnickcom/tcpdf` - PDF generation
- `endroid/qr-code` - QR code generation
- `phpmailer/phpmailer` - Email functionality

All are compatible with PHP 8.4.

## Upgrade Methods

### Method 1: Docker Upgrade (Recommended)

If you're using Docker, the upgrade is straightforward:

1. **Pull the latest code:**
   ```bash
   cd /path/to/TruckChecks
   git pull origin main
   ```

2. **Rebuild the Docker container:**
   ```bash
   cd Docker
   docker-compose down
   docker-compose build --no-cache
   docker-compose up -d
   ```

3. **Verify the PHP version:**
   ```bash
   docker exec -it apache_php php -v
   ```
   You should see: `PHP 8.4.x`

4. **Check application logs:**
   ```bash
   docker logs apache_php
   ```

### Method 2: Manual Server Upgrade

If you're running on a traditional server:

#### For Ubuntu/Debian:

1. **Add PHP 8.4 repository:**
   ```bash
   sudo apt update
   sudo apt install software-properties-common
   sudo add-apt-repository ppa:ondrej/php
   sudo apt update
   ```

2. **Install PHP 8.4 and required extensions:**
   ```bash
   sudo apt install php8.4 php8.4-cli php8.4-fpm php8.4-mysql \
                    php8.4-xml php8.4-mbstring php8.4-curl \
                    php8.4-zip php8.4-gd php8.4-intl
   ```

3. **Update Apache/Nginx configuration:**
   
   For Apache:
   ```bash
   sudo a2dismod php7.4
   sudo a2enmod php8.4
   sudo systemctl restart apache2
   ```
   
   For Nginx with PHP-FPM:
   ```bash
   sudo systemctl stop php7.4-fpm
   sudo systemctl start php8.4-fpm
   sudo systemctl enable php8.4-fpm
   sudo systemctl restart nginx
   ```

4. **Update Composer dependencies:**
   ```bash
   cd /path/to/TruckChecks
   composer update
   ```

5. **Verify PHP version:**
   ```bash
   php -v
   ```

#### For CentOS/RHEL:

1. **Install Remi repository:**
   ```bash
   sudo yum install epel-release
   sudo yum install https://rpms.remirepo.net/enterprise/remi-release-8.rpm
   ```

2. **Enable PHP 8.4 module:**
   ```bash
   sudo dnf module reset php
   sudo dnf module enable php:remi-8.4
   sudo dnf install php php-cli php-fpm php-mysqlnd php-xml \
                    php-mbstring php-curl php-zip php-gd
   ```

3. **Restart web server:**
   ```bash
   sudo systemctl restart httpd  # Apache
   # OR
   sudo systemctl restart nginx php-fpm  # Nginx
   ```

## Post-Upgrade Testing

### 1. Basic Functionality Tests

Test these features in order:

1. **Login System:**
   - [ ] Navigate to `login.php`
   - [ ] Attempt login with valid credentials
   - [ ] Verify login logs are recorded (`login_logs.php`)

2. **Database Operations:**
   - [ ] View the main index page
   - [ ] Check that truck/locker information displays correctly

3. **Admin Functions:**
   - [ ] Access admin panel (`admin.php`)
   - [ ] Test maintain trucks/lockers/items pages
   - [ ] Verify data can be added/edited/deleted

4. **PDF Generation:**
   - [ ] Generate a changeover PDF (`changeover_pdf.php`)
   - [ ] Generate QR codes PDF (`qr-codes-pdf.php`)
   - [ ] Generate items report (`list_all_items_report_a3.php`)

5. **Email Functionality:**
   - [ ] Test email configuration
   - [ ] Send a test report via email (`email_results.php`)

6. **Backup Functionality:**
   - [ ] Create a database backup (`backups.php`)
   - [ ] Verify the backup downloads correctly

7. **Reports:**
   - [ ] Generate locker check report
   - [ ] Generate deleted items report
   - [ ] Verify all data displays correctly

### 2. Check Error Logs

Monitor your PHP error logs for any warnings or notices:

**Docker:**
```bash
docker logs apache_php
```

**Apache:**
```bash
sudo tail -f /var/log/apache2/error.log
```

**Nginx:**
```bash
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/php8.4-fpm.log
```

### 3. Performance Checks

- [ ] Page load times are acceptable
- [ ] Database queries complete without errors
- [ ] PDF generation completes successfully
- [ ] Email sending works properly

## Troubleshooting

### Common Issues and Solutions

#### Issue: "vendor/autoload.php not found"
**Solution:**
```bash
cd /path/to/TruckChecks
composer install
```

#### Issue: PDO connection errors
**Solution:**
Check that the MySQL PDO extension is installed:
```bash
php -m | grep -i pdo
```
If not listed, install it:
```bash
# Ubuntu/Debian
sudo apt install php8.4-mysql

# CentOS/RHEL
sudo dnf install php-mysqlnd
```

#### Issue: TCPDF errors
**Solution:**
Update TCPDF to the latest version:
```bash
composer update tecnickcom/tcpdf
```

#### Issue: Session warnings
**Solution:**
Ensure session directory has correct permissions:
```bash
sudo chown -R www-data:www-data /var/lib/php/sessions
sudo chmod 1733 /var/lib/php/sessions
```

#### Issue: "Headers already sent" errors
**Solution:**
This is usually caused by output buffering. Check that no whitespace exists before `<?php` tags in your files.

## Rollback Procedure

If you encounter critical issues and need to rollback:

### Docker:
```bash
cd Docker
# Edit dockerfile and change FROM php:8.4-apache to FROM php:7.4-apache
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Manual Server:
```bash
# Ubuntu/Debian
sudo a2dismod php8.4
sudo a2enmod php7.4
sudo systemctl restart apache2

# Restore your backup files if needed
```

## PHP 8.4 New Features You Can Use

After successfully upgrading, you can take advantage of PHP 8.4 features:

1. **Property Hooks** - Simplified property accessors
2. **Asymmetric Visibility** - Different visibility for read/write
3. **Array Find Functions** - `array_find()`, `array_find_key()`, `array_any()`, `array_all()`
4. **HTML5 Support** - Better HTML5 parsing in DOM
5. **Performance Improvements** - General speed improvements

## Code Changes Made

The following changes were made to ensure PHP 8.4 compatibility:

### 1. TCPDF Import Statements (Fixed)
**Files changed:**
- `changeover_pdf.php`
- `list_all_items_report_a3.php`
- `qr-codes-pdf.php`

**Change:**
```php
// Before:
use TCPDF;

// After:
// (removed - TCPDF is in global namespace)
```

### 2. Docker Configuration (Updated)
**File:** `Docker/dockerfile`
**Change:** Updated from `php:8.3-apache` to `php:8.4-apache`

### 3. Documentation (Updated)
**File:** `README.md`
**Change:** Updated minimum PHP version from 7.x to 8.0+

## Support and Resources

- **PHP 8.4 Migration Guide:** https://www.php.net/manual/en/migration84.php
- **PHP 8.0 Migration Guide:** https://www.php.net/manual/en/migration80.php
- **TruckChecks Repository:** https://github.com/jtbnz/TruckChecks

## Conclusion

The TruckChecks codebase is well-structured and already compatible with PHP 8.4. The upgrade should be smooth with minimal issues. The code follows modern PHP best practices and doesn't use any deprecated features.

If you encounter any issues not covered in this guide, please:
1. Check the error logs
2. Verify all dependencies are up to date
3. Ensure file permissions are correct
4. Review the PHP 8.4 migration guide for any edge cases

## Upgrade Timeline

Recommended schedule:
- **Day 1:** Backup and prepare (1-2 hours)
- **Day 2:** Perform upgrade in test/staging environment (2-4 hours)
- **Day 3-5:** Testing and validation (4-8 hours)
- **Day 6:** Production upgrade during maintenance window (1-2 hours)
- **Day 7:** Monitor and verify (ongoing)

**Total estimated time:** 8-17 hours spread over one week
