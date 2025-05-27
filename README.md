# TruckChecks V4

TruckChecks is a web application designed to manage and monitor the inventory of truck lockers across multiple stations. Version 4 introduces a comprehensive station hierarchy system with multi-tenancy support, allowing organizations to manage multiple depots or locations independently.

## Table of Contents
- [What's New in V4](#whats-new-in-v4)
- [Features](#features)
- [Installation](#installation)
- [Upgrading from V3](#upgrading-from-v3)
- [Data Import from Multiple Instances](#data-import-from-multiple-instances)
- [Usage](#usage)
- [Station Management](#station-management)
- [Docker Support](#docker-support)
- [File Descriptions](#file-descriptions)
- [Contributing](#contributing)
- [License](#license)
- [Acknowledgements](#acknowledgements)

## What's New in V4

### Station Hierarchy System
- **Multi-Station Support**: Organize trucks into stations (depots/locations)
- **Station Hierarchy**: Station → Trucks → Lockers → Items
- **Station-Specific Settings**: Each station has independent configuration
- **User Management**: Station admins and superusers with role-based access

### Enhanced Authentication
- **User-Based Authentication**: Move beyond simple password protection
- **Role-Based Access Control**: Superusers and station administrators
- **Session Management**: Secure token-based sessions with long-term persistence
- **Station Context**: Automatic station detection and selection

### Station-Specific Configuration
- **Independent Settings**: Each station can have different refresh intervals, demo modes, etc.
- **Customizable Behavior**: Station-specific randomization, API keys, and preferences
- **Centralized Management**: Superusers can manage all stations and users

## Features

- **Multi-Station Management**: Organize operations across multiple depots or locations
- **Station Hierarchy**: Station → Trucks → Lockers → Items organization
- **Role-Based Access Control**: Superusers and station administrators with appropriate permissions
- **Station-Specific Settings**: Independent configuration for each station
- **Automatic Station Detection**: Smart station selection based on truck/locker context
- **Locker Item Management**: Maintain and update the items stored in each truck locker
- **Inventory Checks**: Perform and record checks of locker items to ensure inventory accuracy
- **User Management**: Comprehensive user management with station assignments
- **Login Logging & Security**: Enhanced login attempt logging with IP geolocation and browser detection
- **Reports**: Generate and email reports on inventory checks (station-filtered)
- **Demo Mode**: Station-specific demo mode for training and demonstrations
- **QR Code Generation**: Generate QR codes for easy identification of lockers
- **Database Management**: Tools for maintaining database backups and cleaning tables
- **Interactive Quiz**: Location-based quiz system for training
- **Changeover Management**: Equipment transfer tracking between trucks
- **Audit Trail**: Complete audit logging with station context
- **Data Import**: Merge multiple TruckChecks instances into a single V4 installation

## Installation

### Prerequisites

- PHP 7.4 or higher (PHP 8.x recommended)
- MySQL 5.7+ or MariaDB 10.3+
- Web server (Apache/Nginx)
- Composer (for dependencies)
- Docker (optional, for containerized deployment)

### Fresh V4 Installation

1. **Clone the repository**:
    ```bash
    git clone https://github.com/jtbnz/TruckChecks.git
    cd TruckChecks
    ```

2. **Install PHP dependencies**:
    ```bash
    composer install
    ```
    
    Or install individual packages:
    ```bash
    composer require endroid/qr-code
    composer require tecnickcom/tcpdf
    composer require phpmailer/phpmailer
    ```

3. **Configure database connection**:
    Copy the sample configuration file:
    ```bash
    cp config_sample.php config.php
    ```
    
    Edit `config.php` with your database credentials:
    ```php
    if (!defined('DB_HOST'))   define('DB_HOST'  , 'localhost');
    if (!defined('DB_NAME'))   define('DB_NAME'  , 'truckchecks_v4');
    if (!defined('DB_USER'))   define('DB_USER'  , 'your_username');
    if (!defined('DB_PASS'))   define('DB_PASS'  , 'your_password');
    
    // Email configuration (optional)
    if (!defined('EMAIL_HOST'))   define("EMAIL_HOST" ,"smtp.example.com");
    if (!defined('EMAIL_USER'))   define('EMAIL_USER', 'your_email@example.com');
    if (!defined('EMAIL_PASS'))   define('EMAIL_PASS', 'your_email_password');
    if (!defined('EMAIL_PORT'))   define('EMAIL_PORT' , 587);
    
    // Legacy password for backward compatibility
    if (!defined('PASSWORD'))  define('PASSWORD' , 'YourSecurePassword');
    
    // Optional: IP Geolocation API key
    if (!defined('IP_API_KEY')) define('IP_API_KEY', 'your_ipgeolocation_api_key');
    ```

4. **Run the installation wizard**:
    Navigate to `install.php` in your web browser:
    ```
    http://your-domain.com/install.php
    ```
    
    The installation wizard will guide you through:
    - Database creation (if needed)
    - Schema setup
    - Creating your first superuser
    - Setting up initial stations
    - Creating station administrators

5. **Complete setup**:
    - Follow the wizard prompts to create your superuser account
    - Create your first station(s)
    - Assign station administrators
    - Configure station-specific settings

### Database Setup Options

The installation wizard provides several options:

#### Option 1: Create New Database
- The installer can create a new database for you
- Requires database user with CREATE privileges
- Recommended for fresh installations

#### Option 2: Use Existing Empty Database
- Point to an existing empty database
- The installer will create all necessary tables
- Good for shared hosting environments

#### Option 3: Manual Database Setup
If you prefer manual setup:
```sql
CREATE DATABASE truckchecks_v4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Then run the installation wizard.

## Upgrading from V3

### Automatic Upgrade Process

1. **Backup your V3 database**:
    ```bash
    mysqldump -u username -p your_v3_database > v3_backup.sql
    ```

2. **Update your code**:
    ```bash
    git pull origin main
    # or download the latest V4 release
    ```

3. **Run the upgrade wizard**:
    Navigate to `install.php` - the system will automatically detect your V3 installation and offer upgrade options.

4. **Follow upgrade prompts**:
    - The wizard will detect your existing V3 schema
    - Apply V4 database changes automatically
    - Migrate all existing data
    - Create default station for existing trucks
    - Set up user accounts

### Manual Upgrade Process

If you prefer manual upgrade:

1. **Apply V4 database changes**:
    ```bash
    mysql -u username -p your_database < V4Changes.sql
    ```

2. **Verify upgrade**:
    - Check that all tables were created successfully
    - Verify existing data is intact
    - Test login functionality

### Post-Upgrade Steps

1. **Create user accounts**:
    - Use the installation wizard or admin panel
    - Create superuser and station admin accounts
    - Assign users to appropriate stations

2. **Configure station settings**:
    - Access station settings via admin panel
    - Set station-specific preferences
    - Configure refresh intervals, demo modes, etc.

3. **Test functionality**:
    - Verify all existing features work
    - Test new station-based features
    - Confirm user access controls

## Data Import from Multiple Instances

V4 includes powerful data import capabilities to merge multiple TruckChecks instances into a single installation.

### Using the Import Wizard

1. **Access the import feature**:
    Navigate to `install.php` and select "Import Data from Another Instance"

2. **Prepare source databases**:
    - Ensure source databases are accessible
    - Have connection credentials ready
    - Consider creating read-only database users for import

3. **Import process**:
    - Enter source database connection details
    - Select which data to import (trucks, lockers, items, checks)
    - Choose destination station for imported data
    - Review import summary before proceeding

### Manual Data Import

For advanced users, you can use the `merge_database.sql` script:

1. **Prepare the merge script**:
    Edit `merge_database.sql` with your source database details

2. **Run the import**:
    ```bash
    mysql -u username -p target_database < merge_database.sql
    ```

3. **Verify import**:
    - Check data integrity
    - Verify station assignments
    - Test imported functionality

### Import Considerations

- **Station Assignment**: Imported trucks will be assigned to a specified station
- **Data Integrity**: The import process maintains referential integrity
- **Conflict Resolution**: Duplicate names are handled automatically
- **Audit Trail**: Import activities are logged for tracking
- **Backup Recommended**: Always backup before importing data

### Supported Import Sources

- **TruckChecks V3**: Full compatibility with V3 databases
- **TruckChecks V2**: Basic data import (trucks, lockers, items)
- **Custom Databases**: Manual mapping may be required

## Usage

### Initial Setup

1. **Login as superuser**:
    - Use the account created during installation
    - Access the admin panel

2. **Create stations**:
    - Add stations for each depot/location
    - Configure station descriptions and settings

3. **Create users**:
    - Add station administrators
    - Assign users to appropriate stations
    - Set user roles and permissions

4. **Configure trucks**:
    - Assign existing trucks to stations
    - Add new trucks as needed
    - Set up lockers and items

### Daily Operations

1. **Station Selection**:
    - Users are automatically assigned to their station context
    - QR codes automatically set station based on truck/locker
    - Manual station switching available when needed

2. **Perform Checks**:
    - Use `check_locker_items.php` for inventory checks
    - Station-specific settings apply automatically
    - Results are filtered by station context

3. **Generate Reports**:
    - Access station-specific reports
    - Email reports to station personnel
    - Superusers can access cross-station reports

4. **Manage Settings**:
    - Station admins configure their station settings
    - Superusers manage global settings and users
    - Settings apply immediately to station operations

## Station Management

### Station Hierarchy

```
Organization
├── Station A (North Depot)
│   ├── Truck 1
│   │   ├── Locker A
│   │   └── Locker B
│   └── Truck 2
│       └── Locker C
└── Station B (South Depot)
    ├── Truck 3
    └── Truck 4
```

### User Roles

- **Superuser**: 
  - Access to all stations
  - Can create/manage stations and users
  - Global system administration

- **Station Admin**:
  - Access to assigned station(s) only
  - Can manage station settings
  - Can create additional station admins for their station

### Station Settings

Each station can independently configure:
- **Auto-refresh interval**: How often pages refresh automatically
- **Item randomization**: Whether to randomize item order during checks
- **Demo mode**: Enable/disable demo mode for training
- **API keys**: Station-specific integrations (e.g., IP geolocation)

Access station settings via: `station_settings.php`

## Security Features

- **Role-Based Access Control**: Superusers and station administrators
- **Station Isolation**: Users only access their assigned stations
- **Session Management**: Secure token-based authentication
- **Login Logging**: Comprehensive login attempt tracking
- **IP Geolocation**: Track login locations (optional)
- **Audit Trail**: Complete audit logging with station context
- **Data Isolation**: Station data is properly segregated

## Docker Support

V4 maintains full Docker compatibility:

1. **Build the Docker image**:
    ```bash
    docker-compose build
    ```

2. **Run the container**:
    ```bash
    docker-compose up -d
    ```

3. **Access the application**:
    - Application available at `http://localhost:8000`
    - Run installation wizard as normal

4. **Environment variables**:
    Configure database and settings via Docker environment variables

## File Descriptions

### Core Files
- **index.php**: Main entry point with station selection
- **install.php**: Installation and upgrade wizard
- **auth.php**: Authentication and session management
- **select_station.php**: Station selection interface

### V4 New Files
- **manage_stations.php**: Station management interface
- **station_settings.php**: Station-specific configuration
- **merge_database.sql**: Data import script
- **V4Changes.sql**: Database upgrade script

### Legacy Files (Enhanced for V4)
- **login.php/logout.php**: Enhanced with user authentication
- **admin.php**: Updated with station context
- **check_locker_items.php**: Station-aware with auto-detection
- **reports.php**: Station-filtered reporting
- **maintain_*.php**: Station-scoped maintenance tools

### Configuration
- **config_sample.php**: Sample configuration file
- **config.php**: Your configuration (copy from sample)

### Database
- **db.php**: Database connection handling
- **Docker/setup.sql**: Docker database initialization

## API and Integration

### Station Context API
V4 provides helper functions for station-aware development:
- `requireStation()`: Ensure station context is set
- `getStationSetting()`: Retrieve station-specific settings
- `hasStationAccess()`: Check user station permissions

### Backward Compatibility
- Legacy password authentication still supported
- Existing URLs continue to work
- Gradual migration path available

## Troubleshooting

### Common Installation Issues

1. **Database Connection Errors**:
   - Verify database credentials in `config.php`
   - Ensure database server is running
   - Check user permissions

2. **Permission Errors**:
   - Ensure web server can write to session directories
   - Check file permissions on uploaded files

3. **Missing Dependencies**:
   - Run `composer install` to install PHP packages
   - Verify PHP extensions are installed

### Upgrade Issues

1. **V3 to V4 Upgrade Fails**:
   - Backup database before attempting upgrade
   - Check error logs for specific issues
   - Ensure sufficient database permissions

2. **Station Assignment Problems**:
   - Verify trucks are assigned to stations
   - Check user station assignments
   - Review station access permissions

### Import Issues

1. **Data Import Fails**:
   - Verify source database connectivity
   - Check for data conflicts or constraints
   - Review import logs for specific errors

2. **Missing Data After Import**:
   - Verify station assignments
   - Check import mapping configuration
   - Review audit logs for import activities

## Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/station-enhancement`)
3. Make your changes
4. Test with both fresh install and upgrade scenarios
5. Commit your changes (`git commit -m 'Add station enhancement'`)
6. Push to the branch (`git push origin feature/station-enhancement`)
7. Open a pull request

### Development Guidelines

- Maintain backward compatibility where possible
- Test with multiple station configurations
- Follow existing code patterns for station context
- Update documentation for new features

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgements

- [Open Source Projects](https://opensource.org/)
- [PHP Documentation](https://www.php.net/docs.php)
- [Docker Documentation](https://docs.docker.com/)
- [ipgeolocation.io](https://ipgeolocation.io/) - IP Geolocation API service
- Community contributors and testers

## Support

For support and questions:
- Check the troubleshooting section above
- Review installation logs and error messages
- Create an issue on GitHub with detailed information
- Include your PHP version, database version, and any error messages

---

**TruckChecks V4** - Multi-Station Inventory Management System
