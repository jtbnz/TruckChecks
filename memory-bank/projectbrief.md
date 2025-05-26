# TruckChecks Project Brief

## Project Overview
TruckChecks is a web-based inventory management system designed for tracking items stored in lockers across multiple trucks. The system provides comprehensive functionality for managing trucks, lockers, items, and performing various checks and reports.

## Core Purpose
- **Inventory Management**: Track items across multiple trucks and their associated lockers
- **Check System**: Perform locker checks and generate reports
- **Administrative Control**: Manage trucks, lockers, and items with full CRUD operations
- **Reporting**: Generate various reports for inventory status and checks

## Key Business Requirements
1. **Multi-Truck Support**: Handle multiple trucks, each with their own set of lockers
2. **Hierarchical Structure**: Trucks → Lockers → Items relationship
3. **Check Functionality**: Ability to perform and track locker checks
4. **User Authentication**: Secure access with login system
5. **Reporting System**: Generate PDF reports and various data exports
6. **Mobile-Friendly**: Touch-friendly interface for field use

## Target Users
- **Field Personnel**: Performing checks and managing items
- **Administrators**: Managing system configuration and generating reports
- **Supervisors**: Reviewing check results and inventory status

## Technology Stack
- **Backend**: PHP with PDO for database operations
- **Database**: MySQL/MariaDB
- **Frontend**: HTML, CSS, JavaScript with responsive design
- **Deployment**: Docker containerization
- **Authentication**: Cookie-based session management

## Project Scope
The system manages the complete lifecycle of truck inventory management from initial setup through daily operations and reporting. It provides both operational functionality for field use and administrative tools for system management.
