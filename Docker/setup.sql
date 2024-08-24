--USE Database `db`

-- Drop existing tables if they exist
DROP TABLE IF EXISTS `check_items`;
DROP TABLE IF EXISTS `checks`;
DROP TABLE IF EXISTS `items`;
DROP TABLE IF EXISTS `lockers`;
DROP TABLE IF EXISTS `trucks`;
DROP TABLE IF EXISTS `email_addresses`;
DROP TABLE IF EXISTS `locker_item_deletion_log`;

-- Create the `trucks` table
CREATE TABLE `trucks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL
);

-- Create the `lockers` table
CREATE TABLE `lockers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `truck_id` INT NOT NULL,
    `notes` TEXT,
    FOREIGN KEY (`truck_id`) REFERENCES `trucks`(`id`) ON DELETE CASCADE
);

-- Create the `items` table
CREATE TABLE `items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `locker_id` INT NOT NULL,
    FOREIGN KEY (`locker_id`) REFERENCES `lockers`(`id`) ON DELETE CASCADE
);

-- Create the `checks` table
CREATE TABLE `checks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `locker_id` INT NOT NULL,
    `check_date` DATETIME NOT NULL,
    `checked_by` VARCHAR(255) NOT NULL,
    FOREIGN KEY (`locker_id`) REFERENCES `lockers`(`id`) ON DELETE CASCADE
);

-- Create the `check_items` table
CREATE TABLE `check_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `check_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `is_present` BOOLEAN NOT NULL,
    FOREIGN KEY (`check_id`) REFERENCES `checks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE
);

CREATE TABLE locker_item_deletion_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    truck_name VARCHAR(255),
    locker_name VARCHAR(255),
    item_name VARCHAR(255),
    deleted_at DATETIME
);


CREATE TABLE `email_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ;

ALTER TABLE `checks` ADD `ignore_check` BOOLEAN NOT NULL DEFAULT FALSE AFTER `checked_by`;


-- Insert sample data into `trucks` table
INSERT INTO `trucks` (`name`) VALUES 
('Truck 1'),
('Truck 2'),
('Truck 3');

-- Insert sample data into `lockers` table
INSERT INTO `lockers` (`name`, `truck_id`, `notes`) VALUES 
('Offside Front', 1, 'First locker in Truck 1'),
('Offside Rear', 1, 'Rear locker in Truck 1'),
('Offside Middle', 1, 'Middle locker in Truck 1'),
('Nearside Front', 1, 'Front locker in Truck 1'),
('Nearside Rear', 1, 'Rear locker in Truck 1'),
('Nearside Middle', 1, 'Middle locker in Truck 1'),
('Rear', 1, 'Rear locker in Truck 1'),
('Cab', 1, 'Cab locker in Truck 1'),
('Coffin', 1, 'Coffin locker in Truck 1'),

('Offside Front', 2, 'First locker in Truck 2'),
('Offside Rear', 2, 'Rear locker in Truck 2'),
('Offside Middle', 2, 'Middle locker in Truck 2'),
('Nearside Front', 2, 'Front locker in Truck 2'),
('Nearside Rear', 2, 'Rear locker in Truck 2'),
('Nearside Middle', 2, 'Middle locker in Truck 2'),
('Rear', 2, 'Rear locker in Truck 2'),
('Cab', 2, 'Cab locker in Truck 2'),
('Coffin', 2, 'Coffin locker in Truck 2'),

('Offside Front', 3, 'First locker in Truck 3'),
('Offside Rear', 3, 'Rear locker in Truck 3'),
('Offside Middle', 3, 'Middle locker in Truck 3'),
('Nearside Front', 3, 'Front locker in Truck 3'),
('Nearside Rear', 3, 'Rear locker in Truck 3'),
('Nearside Middle', 3, 'Middle locker in Truck 3'),
('Rear', 3, 'Rear locker in Truck 3'),
('Cab', 3, 'Cab locker in Truck 3'),
('Coffin', 3, 'Coffin locker in Truck 3');

-- Insert sample data into `items` table
INSERT INTO `items` (`name`, `locker_id`) VALUES 
('Item 1', 1),
('Item 2', 1),
('Item 3', 1),
('Item 1', 2),
('Item 2', 2),
('Item 1', 3),
('Item 2', 3),
('Item 3', 3),
('Item 1', 4),
('Item 2', 4);

-- Insert sample check data into `checks` and `check_items` tables
INSERT INTO `checks` (`locker_id`, `check_date`, `checked_by`) VALUES 
(1, '2023-08-01 10:00:00', 'John Doe'),
(2, '2023-08-02 11:00:00', 'Jane Smith');

INSERT INTO `check_items` (`check_id`, `item_id`, `is_present`) VALUES 
(1, 1, true),
(1, 2, true),
(1, 3, false),  -- Item 3 missing in this check
(2, 4, true),
(2, 5, true);


	
