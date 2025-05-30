-- TruckChecks V4 Changes - Station Hierarchy Implementation
-- This script upgrades the database to support the Station concept

-- Create stations table
CREATE TABLE `stations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `description` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create users table for station admin management
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255),
    `role` ENUM('superuser', 'station_admin') NOT NULL DEFAULT 'station_admin',
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_login` DATETIME NULL,
    `created_by` INT NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
);

-- Create user_stations table for many-to-many relationship between users and stations
CREATE TABLE `user_stations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `station_id` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `unique_user_station` (`user_id`, `station_id`)
);

-- Add station_id to trucks table
ALTER TABLE `trucks` ADD COLUMN `station_id` INT NULL AFTER `relief`;
ALTER TABLE `trucks` ADD FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE SET NULL;

-- Create sessions table for better session management
CREATE TABLE `user_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `session_token` VARCHAR(255) NOT NULL UNIQUE,
    `station_id` INT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE SET NULL,
    INDEX `idx_session_token` (`session_token`),
    INDEX `idx_expires_at` (`expires_at`),
    INDEX `idx_user_id` (`user_id`)
);

-- Update audit_log table to include station context
ALTER TABLE `audit_log` ADD COLUMN `station_id` INT NULL AFTER `deleted_by`;
ALTER TABLE `audit_log` ADD COLUMN `user_id` INT NULL AFTER `station_id`;
ALTER TABLE `audit_log` ADD FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE SET NULL;
ALTER TABLE `audit_log` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Update login_log table to include user context
ALTER TABLE `login_log` ADD COLUMN `user_id` INT NULL AFTER `session_id`;
ALTER TABLE `login_log` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Create audit triggers for new tables

-- Trigger for stations table
DELIMITER $$
CREATE TRIGGER audit_stations_delete
    BEFORE DELETE ON stations
    FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, deleted_data, station_id)
    VALUES ('stations', OLD.id, CONCAT(
        '{"id":', OLD.id, 
        ',"name":"', IFNULL(OLD.name, ''), 
        '","description":"', IFNULL(REPLACE(OLD.description, '"', '\\"'), ''), 
        '","created_at":"', IFNULL(OLD.created_at, ''), 
        '","updated_at":"', IFNULL(OLD.updated_at, ''), '"}'
    ), OLD.id);
END$$
DELIMITER ;

-- Trigger for users table
DELIMITER $$
CREATE TRIGGER audit_users_delete
    BEFORE DELETE ON users
    FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, deleted_data, user_id)
    VALUES ('users', OLD.id, CONCAT(
        '{"id":', OLD.id, 
        ',"username":"', IFNULL(OLD.username, ''), 
        '","email":"', IFNULL(OLD.email, ''), 
        '","role":"', IFNULL(OLD.role, ''), 
        '","is_active":', IFNULL(OLD.is_active, 'false'),
        ',"created_at":"', IFNULL(OLD.created_at, ''), 
        '","last_login":"', IFNULL(OLD.last_login, ''), '"}'
    ), OLD.id);
END$$
DELIMITER ;

-- Update existing audit triggers to include station context
DROP TRIGGER IF EXISTS audit_trucks_delete;
DELIMITER $$
CREATE TRIGGER audit_trucks_delete
    BEFORE DELETE ON trucks
    FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, deleted_data, station_id)
    VALUES ('trucks', OLD.id, CONCAT(
        '{"id":', OLD.id, 
        ',"name":"', IFNULL(OLD.name, ''), 
        '","relief":', IFNULL(OLD.relief, 'false'),
        ',"station_id":', IFNULL(OLD.station_id, 'null'), '}'
    ), OLD.station_id);
END$$
DELIMITER ;

-- Insert default station for existing data
INSERT INTO `stations` (`name`, `description`) VALUES 
('Default Station', 'Default station for existing trucks and data migration');

-- Get the ID of the default station
SET @default_station_id = LAST_INSERT_ID();

-- Update all existing trucks to belong to the default station
UPDATE `trucks` SET `station_id` = @default_station_id WHERE `station_id` IS NULL;

-- Create default superuser (password: admin123 - should be changed immediately)
INSERT INTO `users` (`username`, `password_hash`, `email`, `role`) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', 'superuser');

-- Get the ID of the default admin user
SET @admin_user_id = LAST_INSERT_ID();

-- Assign the default admin to the default station
INSERT INTO `user_stations` (`user_id`, `station_id`, `created_by`) VALUES 
(@admin_user_id, @default_station_id, @admin_user_id);

-- Create indexes for performance
CREATE INDEX `idx_trucks_station_id` ON `trucks`(`station_id`);
CREATE INDEX `idx_user_stations_user_id` ON `user_stations`(`user_id`);
CREATE INDEX `idx_user_stations_station_id` ON `user_stations`(`station_id`);
CREATE INDEX `idx_audit_log_station_id` ON `audit_log`(`station_id`);
CREATE INDEX `idx_audit_log_user_id` ON `audit_log`(`user_id`);

-- Create station_settings table for station-specific configuration
CREATE TABLE `station_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `station_id` INT NOT NULL,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT,
    `setting_type` ENUM('string', 'integer', 'boolean', 'json') NOT NULL DEFAULT 'string',
    `description` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_station_setting` (`station_id`, `setting_key`),
    INDEX `idx_station_settings_station_id` (`station_id`),
    INDEX `idx_station_settings_key` (`setting_key`)
);

-- Insert default station settings for all existing stations
INSERT INTO `station_settings` (`station_id`, `setting_key`, `setting_value`, `setting_type`, `description`)
SELECT s.id, 'refresh_interval', '30000', 'integer', 'Page auto-refresh interval in milliseconds'
FROM `stations` s;

INSERT INTO `station_settings` (`station_id`, `setting_key`, `setting_value`, `setting_type`, `description`)
SELECT s.id, 'randomize_order', 'true', 'boolean', 'Randomize the order of locker items on check pages'
FROM `stations` s;

INSERT INTO `station_settings` (`station_id`, `setting_key`, `setting_value`, `setting_type`, `description`)
SELECT s.id, 'is_demo', 'false', 'boolean', 'Enable demo mode for this station'
FROM `stations` s;

INSERT INTO `station_settings` (`station_id`, `setting_key`, `setting_value`, `setting_type`, `description`)
SELECT s.id, 'ip_api_key', '', 'string', 'IP Geolocation API key for ipgeolocation.io'
FROM `stations` s;

-- Add sample stations for demonstration
INSERT INTO `stations` (`name`, `description`) VALUES 
('North Station', 'Northern depot operations'),
('South Station', 'Southern depot operations'),
('East Station', 'Eastern depot operations');

-- Add default settings for the new sample stations
INSERT INTO `station_settings` (`station_id`, `setting_key`, `setting_value`, `setting_type`, `description`)
SELECT s.id, 'refresh_interval', '30000', 'integer', 'Page auto-refresh interval in milliseconds'
FROM `stations` s WHERE s.name IN ('North Station', 'South Station', 'East Station');

INSERT INTO `station_settings` (`station_id`, `setting_key`, `setting_value`, `setting_type`, `description`)
SELECT s.id, 'randomize_order', 'true', 'boolean', 'Randomize the order of locker items on check pages'
FROM `stations` s WHERE s.name IN ('North Station', 'South Station', 'East Station');

INSERT INTO `station_settings` (`station_id`, `setting_key`, `setting_value`, `setting_type`, `description`)
SELECT s.id, 'is_demo', 'false', 'boolean', 'Enable demo mode for this station'
FROM `stations` s WHERE s.name IN ('North Station', 'South Station', 'East Station');

INSERT INTO `station_settings` (`station_id`, `setting_key`, `setting_value`, `setting_type`, `description`)
SELECT s.id, 'ip_api_key', '', 'string', 'IP Geolocation API key for ipgeolocation.io'
FROM `stations` s WHERE s.name IN ('North Station', 'South Station', 'East Station');

-- Create settings table for global system settings
CREATE TABLE `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_name` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `setting_type` ENUM('string', 'integer', 'boolean', 'json') NOT NULL DEFAULT 'string',
    `description` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_settings_name` (`setting_name`)
);

-- Insert default global settings
INSERT INTO `settings` (`setting_name`, `setting_value`, `setting_type`, `description`) VALUES 
('security_code', '', 'string', 'Global security code for additional verification'),
('system_name', 'TruckChecks', 'string', 'System name displayed in headers'),
('maintenance_mode', 'false', 'boolean', 'Enable maintenance mode to restrict access'),
('backup_retention_days', '30', 'integer', 'Number of days to retain backup files'),
('session_timeout', '3600', 'integer', 'Session timeout in seconds'),
('max_login_attempts', '5', 'integer', 'Maximum login attempts before lockout'),
('lockout_duration', '900', 'integer', 'Account lockout duration in seconds');

-- Add station_id to email_addresses table for per-station email management
ALTER TABLE `email_addresses` ADD COLUMN `station_id` INT NULL AFTER `email`;
ALTER TABLE `email_addresses` ADD FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE SET NULL;
CREATE INDEX `idx_email_addresses_station_id` ON `email_addresses`(`station_id`);

-- Add station_id to locker_item_deletion_log table for per-station tracking
ALTER TABLE `locker_item_deletion_log` ADD COLUMN `station_id` INT NULL AFTER `deleted_at`;
ALTER TABLE `locker_item_deletion_log` ADD FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE SET NULL;
CREATE INDEX `idx_locker_item_deletion_log_station_id` ON `locker_item_deletion_log`(`station_id`);

COMMIT;
