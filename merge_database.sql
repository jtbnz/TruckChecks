-- TruckChecks Database Merge Script
-- This script merges data from a separate TruckChecks instance into the main database
-- Usage: Replace 'source_db' with the actual name of the source database

-- Create a temporary station for merged data
INSERT INTO `stations` (`name`, `description`) VALUES 
('Merged Station', 'Station created for merged data from external database');

SET @merged_station_id = LAST_INSERT_ID();

-- Merge trucks from source database
INSERT INTO `trucks` (`name`, `relief`, `station_id`)
SELECT 
    CONCAT(name, ' (Merged)') as name,
    relief,
    @merged_station_id
FROM source_db.trucks
WHERE NOT EXISTS (
    SELECT 1 FROM trucks 
    WHERE trucks.name = CONCAT(source_db.trucks.name, ' (Merged)')
    AND trucks.station_id = @merged_station_id
);

-- Create mapping table for truck ID translation
CREATE TEMPORARY TABLE truck_id_mapping (
    old_id INT,
    new_id INT,
    PRIMARY KEY (old_id)
);

-- Populate truck ID mapping
INSERT INTO truck_id_mapping (old_id, new_id)
SELECT 
    st.id as old_id,
    t.id as new_id
FROM source_db.trucks st
JOIN trucks t ON t.name = CONCAT(st.name, ' (Merged)') 
    AND t.station_id = @merged_station_id;

-- Merge lockers from source database
INSERT INTO `lockers` (`name`, `truck_id`, `notes`)
SELECT 
    sl.name,
    tim.new_id,
    sl.notes
FROM source_db.lockers sl
JOIN truck_id_mapping tim ON sl.truck_id = tim.old_id;

-- Create mapping table for locker ID translation
CREATE TEMPORARY TABLE locker_id_mapping (
    old_id INT,
    new_id INT,
    PRIMARY KEY (old_id)
);

-- Populate locker ID mapping
INSERT INTO locker_id_mapping (old_id, new_id)
SELECT 
    sl.id as old_id,
    l.id as new_id
FROM source_db.lockers sl
JOIN truck_id_mapping tim ON sl.truck_id = tim.old_id
JOIN lockers l ON l.name = sl.name AND l.truck_id = tim.new_id;

-- Merge items from source database
INSERT INTO `items` (`name`, `locker_id`)
SELECT 
    si.name,
    lim.new_id
FROM source_db.items si
JOIN locker_id_mapping lim ON si.locker_id = lim.old_id;

-- Create mapping table for item ID translation
CREATE TEMPORARY TABLE item_id_mapping (
    old_id INT,
    new_id INT,
    PRIMARY KEY (old_id)
);

-- Populate item ID mapping
INSERT INTO item_id_mapping (old_id, new_id)
SELECT 
    si.id as old_id,
    i.id as new_id
FROM source_db.items si
JOIN locker_id_mapping lim ON si.locker_id = lim.old_id
JOIN items i ON i.name = si.name AND i.locker_id = lim.new_id;

-- Merge checks from source database
INSERT INTO `checks` (`locker_id`, `check_date`, `checked_by`, `ignore_check`)
SELECT 
    lim.new_id,
    sc.check_date,
    CONCAT(sc.checked_by, ' (Merged)'),
    sc.ignore_check
FROM source_db.checks sc
JOIN locker_id_mapping lim ON sc.locker_id = lim.old_id;

-- Create mapping table for check ID translation
CREATE TEMPORARY TABLE check_id_mapping (
    old_id INT,
    new_id INT,
    PRIMARY KEY (old_id)
);

-- Populate check ID mapping
INSERT INTO check_id_mapping (old_id, new_id)
SELECT 
    sc.id as old_id,
    c.id as new_id
FROM source_db.checks sc
JOIN locker_id_mapping lim ON sc.locker_id = lim.old_id
JOIN checks c ON c.locker_id = lim.new_id 
    AND c.check_date = sc.check_date 
    AND c.checked_by = CONCAT(sc.checked_by, ' (Merged)');

-- Merge check_items from source database
INSERT INTO `check_items` (`check_id`, `item_id`, `is_present`)
SELECT 
    cim.new_id,
    iim.new_id,
    sci.is_present
FROM source_db.check_items sci
JOIN check_id_mapping cim ON sci.check_id = cim.old_id
JOIN item_id_mapping iim ON sci.item_id = iim.old_id;

-- Merge check_notes from source database
INSERT INTO `check_notes` (`check_id`, `note`)
SELECT 
    cim.new_id,
    scn.note
FROM source_db.check_notes scn
JOIN check_id_mapping cim ON scn.check_id = cim.old_id;

-- Merge swap data if it exists
INSERT INTO `swap` (`locker_id`, `swapped_by`, `ignore_check`, `swap_date`)
SELECT 
    lim.new_id,
    CONCAT(ss.swapped_by, ' (Merged)'),
    ss.ignore_check,
    ss.swap_date
FROM source_db.swap ss
JOIN locker_id_mapping lim ON ss.locker_id = lim.old_id;

-- Create mapping table for swap ID translation
CREATE TEMPORARY TABLE swap_id_mapping (
    old_id INT,
    new_id INT,
    PRIMARY KEY (old_id)
);

-- Populate swap ID mapping
INSERT INTO swap_id_mapping (old_id, new_id)
SELECT 
    ss.id as old_id,
    s.id as new_id
FROM source_db.swap ss
JOIN locker_id_mapping lim ON ss.locker_id = lim.old_id
JOIN swap s ON s.locker_id = lim.new_id 
    AND s.swap_date = ss.swap_date 
    AND s.swapped_by = CONCAT(ss.swapped_by, ' (Merged)');

-- Merge swap_items from source database
INSERT INTO `swap_items` (`swap_id`, `item_id`, `is_present`)
SELECT 
    sim.new_id,
    iim.new_id,
    ssi.is_present
FROM source_db.swap_items ssi
JOIN swap_id_mapping sim ON ssi.swap_id = sim.old_id
JOIN item_id_mapping iim ON ssi.item_id = iim.old_id;

-- Merge swap_notes from source database
INSERT INTO `swap_notes` (`swap_id`, `note`)
SELECT 
    sim.new_id,
    ssn.note
FROM source_db.swap_notes ssn
JOIN swap_id_mapping sim ON ssn.swap_id = sim.old_id;

-- Merge email_addresses from source database (avoid duplicates)
INSERT INTO `email_addresses` (`email`)
SELECT DISTINCT se.email
FROM source_db.email_addresses se
WHERE NOT EXISTS (
    SELECT 1 FROM email_addresses e 
    WHERE e.email = se.email
);

-- Clean up temporary tables
DROP TEMPORARY TABLE truck_id_mapping;
DROP TEMPORARY TABLE locker_id_mapping;
DROP TEMPORARY TABLE item_id_mapping;
DROP TEMPORARY TABLE check_id_mapping;
DROP TEMPORARY TABLE swap_id_mapping;

-- Create a station admin user for the merged station
INSERT INTO `users` (`username`, `password_hash`, `email`, `role`, `created_by`) VALUES 
('merged_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'merged@example.com', 'station_admin', 1);

SET @merged_admin_id = LAST_INSERT_ID();

-- Assign the merged admin to the merged station
INSERT INTO `user_stations` (`user_id`, `station_id`, `created_by`) VALUES 
(@merged_admin_id, @merged_station_id, 1);

-- Summary of merge operation
SELECT 
    'Merge Summary' as operation,
    @merged_station_id as merged_station_id,
    @merged_admin_id as merged_admin_user_id,
    (SELECT COUNT(*) FROM trucks WHERE station_id = @merged_station_id) as trucks_merged,
    (SELECT COUNT(*) FROM lockers l JOIN trucks t ON l.truck_id = t.id WHERE t.station_id = @merged_station_id) as lockers_merged,
    (SELECT COUNT(*) FROM items i JOIN lockers l ON i.locker_id = l.id JOIN trucks t ON l.truck_id = t.id WHERE t.station_id = @merged_station_id) as items_merged;

COMMIT;

-- Instructions for use:
-- 1. Replace 'source_db' with the actual name of the source database
-- 2. Ensure the source database has the same table structure
-- 3. Run this script on the target database
-- 4. Review the merge summary output
-- 5. Update the merged admin user password immediately
-- 6. Assign appropriate users to the merged station
