# Truck Checks Management System

This is a web-based application for managing and tracking the inspection of trucks and their lockers. The system allows users to check the items in each locker, record inspections, and view reports on previous inspections. It also includes an admin interface for maintaining the trucks, lockers, and items.

## Features

- **Check Locker Items**: Users can select a truck and a locker, inspect the items within, and record the inspection.
- **Days Since Last Check**: Displays the number of days since the locker was last checked.
- **Admin Interface**: Allows for managing trucks, lockers, and items.
- **Responsive Design**: The interface is optimized for both desktop and mobile devices.
- **Color Blindness Mode**: An option to toggle color blindness-friendly palettes is available.

## Installation

1. **Clone the repository**:
    ```bash
    git clone https://github.com/yourusername/truck-checks.git
    cd truck-checks
    ```

2. **Set up the MySQL database**:
    - Create a MySQL database and import the provided SQL file (`database.sql`).
    - Update the database connection details in `db.php` to match your MySQL credentials.

3. **Configure your web server**:
    - Ensure your web server (Apache, Nginx, etc.) points to the directory containing this project.
    - Make sure PHP is installed and configured correctly.

4. **Access the application**:
    - Open a web browser and navigate to the location where the application is hosted (e.g., `http://localhost/truck-checks`).

## File Structure

### `index.php`
The main landing page for the application. Displays a list of trucks with buttons to check each truck's lockers. The lockers are color-coded based on their inspection status (checked recently, not checked recently, or missing items).

### `check_locker_items.php`
The page where users can select a truck and a locker to inspect. It displays the items within the locker, allows the user to mark them as present or missing, and records the inspection. The last entered "Checked by" name is retained within the browser.

### `admin.php`
Admin interface where users can manage trucks, lockers, and locker items. The page also includes an option to toggle a color blindness-friendly mode.

### `maintain_trucks.php`
Page for adding, editing, and deleting trucks in the system.

### `maintain_lockers.php`
Page for adding, editing, and deleting lockers for a selected truck.

### `maintain_locker_items.php`
Page for adding, editing, and deleting items within a selected locker. Requires php-qrcode. Defaults to http so you may want to change this!
```composer2 require endroid/qr-code```

### `reports.php`
Provides reports based on previous inspections. Users can select a date and view which lockers were checked, who checked them, and any missing items.

### `qr-codes.php`
Generates QR Codes for each locker for printing 

### `db.php`
Handles the database connection. Update this file with your MySQL connection details.

### `templates/header.php` and `templates/footer.php`
Shared header and footer files included on every page to maintain a consistent layout and navigation.

### `styles.css`
Contains the CSS for styling the application. Ensures the application is responsive and looks good on both desktop and mobile devices.

### `database.sql`
A SQL file for setting up the initial database structure. Includes tables for trucks, lockers, items, checks, and related data.

## Usage

1. **Checking Lockers**:
   - On the main page (`index.php`), select a truck and click on the "Check Locker" button.
   - Choose a locker from the list, inspect the items, and record your inspection.

2. **Administering Trucks, Lockers, and Items**:
   - Navigate to the `Admin` page from the bottom of the main page.
   - Use the admin interface to manage trucks, lockers, and locker items.

3. **Viewing Reports**:
   - Go to the `Reports` page from the admin interface.
   - Select a date to view inspection reports for that day.

4. **Color Blindness Mode**:
   - Enable or disable color blindness-friendly mode from the `Admin` page.

## Contributing

Contributions are welcome! Please fork the repository and submit a pull request.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.