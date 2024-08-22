# Truck Checks

Truck Checks is a web application designed to manage and monitor the inventory of truck lockers. It provides tools for administrators to maintain records, generate reports, and ensure compliance with inventory checks.

## Table of Contents
- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [Docker Support](#docker-support)
- [File Descriptions](#file-descriptions)
- [Contributing](#contributing)
- [License](#license)
- [Acknowledgements](#acknowledgements)

## Features

- **Locker Item Management**: Maintain and update the items stored in each truck locker.
- **Inventory Checks**: Perform and record checks of locker items to ensure inventory accuracy.
- **User Management**: Secure login and logout functionalities for administrators.
- **Reports**: Generate and email reports on inventory checks.
- **Demo Mode**: A special mode that allows users to explore the system without affecting real data.
- **QR Code Generation**: Generate QR codes for easy identification of lockers.
- **Database Management**: Includes tools for maintaining database backups and cleaning tables.
- **Interactive Quiz**: Users can participate in a quiz where they guess the location of an item. The quiz tracks the number of attempts and calculates a total score based on the number of correct guesses.


## Installation

### Prerequisites

- PHP 7.x or higher
- MySQL or MariaDB
- Docker (optional, for containerized deployment)

### Steps

1. **Clone the repository**:
    ```bash
    git clone https://github.com/jtbnz/TruckChecks.git
    cd TruckChecks
    ```

2. **Set up the database**:
    - Import the provided SQL file  to set up your database schema.
    - Update `config.php` with your database connection details.

3. **Install dependencies**:
    If you're using Docker, you can skip this step since dependencies will be handled by Docker.
    The main one you will need is qr-code to generate the qrcode image

        ```bash
        composer2 require endroid/qr-code
        ```
        
4. **Configure local files**:
- **db.php**: update with your username,password,database name - This also has the demo toggle field
- **config.php**: Configure Database Credentials
	Copy the config_sample.php file to config.php.

        ```
        cp config_sample.php config.php
        ```
    Open config.php and set your database credentials, admin password, and demo mode (unlikely!)

    ```php
            define('DB_HOST' ,'localhost');
            define('DB_NAME' , 'your_database_name');
            define('DB_USER' , 'your_username');
            define('DB_PASS','your_password');
            define('PASSWORD', 'YourSecurePassword');

            define('IS_DEMO',false);    
    ```

5. **Run the application**:
    - Point your web server to the `index.php` file or use Docker to run the application.

## Usage

1. **Login**:
    - Navigate to `login.php` to access the administrator panel.

2. **Perform Checks**:
    - Use the `check_locker_items.php` to perform and log inventory checks.

3. **Generate Reports**:
    - Access `reports.php` to generate and email inventory reports.

4. **Manage Data**:
    - Use various maintenance scripts (`maintain_lockers.php`, `maintain_trucks.php`, etc.) to manage lockers, trucks, and items.

## Docker Support

This project supports Docker for easy deployment.

1. **Build the Docker image**:
    ```bash
    docker-compose build
    ```

2. **Run the container**:
    ```bash
    docker-compose up -d
    ```

3. **Access the application**:
    - The application will be accessible at `http://localhost:8000` by default.

## File Descriptions

- **index.php**: The main entry point of the application.
- **login.php/logout.php**: Handles user authentication.
- **admin.php**: Admin panel for managing the application.
- **db.php**: Database connection settings.
- **check_locker_items.php**: Interface for performing locker checks.
- **reports.php**: Generate and email reports.
- **qr-codes.php**: Generate QR codes for lockers.
- **Docker/**: Contains Docker-related files for containerizing the application.
- **styles/**: CSS files for styling the application.
- **templates/**: HTML templates used throughout the application.
- **scripts/**: Local scripts used to email the checks automatically with timezone handling
- **Config Sample File**: Added instructions to copy and configure the `config.php` file from `config_sample.php`.
- **quiz/**: Quiz to locate the correct locker for an item


## Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository.
2. Create a new branch (`git checkout -b feature-branch`).
3. Make your changes.
4. Commit your changes (`git commit -m 'Add feature'`).
5. Push to the branch (`git push origin feature-branch`).
6. Open a pull request.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgements

- [Open Source Projects](https://opensource.org/)
- [PHP Documentation](https://www.php.net/docs.php)
- [Docker Documentation](https://docs.docker.com/)




