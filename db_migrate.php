<?php
/**
 * Database Migration System for TruckChecks
 *
 * Automatically detects and applies missing schema changes on page load.
 * Each migration checks if it's needed before applying, so it's safe to
 * run repeatedly. Called from db.php after connection is established.
 */

function run_migrations(PDO $db): void {
    $migrations = [
        // Core tables MUST be first — everything else depends on them
        'create_trucks_table',
        'create_lockers_table',
        'create_items_table',
        'create_checks_table',
        'create_check_items_table',

        // Incremental schema additions (order matters for dependencies)
        'add_relief_column',
        'add_notes_column_to_lockers',
        'add_ignore_check_column',
        'add_check_notes_table',
        'add_swap_tables',
        'add_protection_codes_table',
        'add_locker_item_deletion_log_table',
        'add_email_addresses_table',
        'add_audit_log_table',
        'add_login_log_table',

        // Triggers must come after all columns they reference exist
        'add_audit_triggers',
    ];

    // Buffer all output so migrations never leak text into AJAX responses
    ob_start();
    foreach ($migrations as $migration) {
        try {
            $func = 'migrate_' . $migration;
            if (function_exists($func)) {
                call_user_func($func, $db);
            }
        } catch (\Exception $e) {
            @error_log("Migration '$migration' failed: " . $e->getMessage() . "\n", 3, __DIR__ . '/db_errors.log');
        } catch (\Error $e) {
            @error_log("Migration '$migration' error: " . $e->getMessage() . "\n", 3, __DIR__ . '/db_errors.log');
        }
    }
    $output = ob_get_clean();
    if (!empty($output)) {
        @error_log("Migration output captured: " . $output . "\n", 3, __DIR__ . '/db_errors.log');
    }
}

// ─── Helpers ────────────────────────────────────────────────

function table_exists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
        $stmt->execute([DB_NAME, $table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (\Exception $e) {
        return false;
    }
}

function column_exists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([DB_NAME, $table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (\Exception $e) {
        return true;
    }
}

function trigger_exists(PDO $db, string $trigger): bool {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?");
        $stmt->execute([DB_NAME, $trigger]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (\Exception $e) {
        return true;
    }
}

function migration_log(string $message): void {
    @error_log(date('[Y-m-d H:i:s] ') . $message . "\n", 3, __DIR__ . '/db_errors.log');
}

function safe_exec(PDO $db, string $sql): bool {
    try {
        $db->exec($sql);
        return true;
    } catch (\Exception $e) {
        migration_log("SQL exec failed: " . $e->getMessage() . " | SQL: " . substr($sql, 0, 200));
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════
// CORE TABLE CREATION — these create the base schema from scratch
// ═══════════════════════════════════════════════════════════════

function migrate_create_trucks_table(PDO $db): void {
    if (table_exists($db, 'trucks')) return;
    safe_exec($db, "CREATE TABLE `trucks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `relief` BOOLEAN NOT NULL DEFAULT FALSE
    )");
}

function migrate_create_lockers_table(PDO $db): void {
    if (table_exists($db, 'lockers')) return;
    safe_exec($db, "CREATE TABLE `lockers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `truck_id` INT NOT NULL,
        `notes` TEXT,
        FOREIGN KEY (`truck_id`) REFERENCES `trucks`(`id`) ON DELETE CASCADE
    )");
}

function migrate_create_items_table(PDO $db): void {
    if (table_exists($db, 'items')) return;
    safe_exec($db, "CREATE TABLE `items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `locker_id` INT NOT NULL,
        FOREIGN KEY (`locker_id`) REFERENCES `lockers`(`id`) ON DELETE CASCADE
    )");
}

function migrate_create_checks_table(PDO $db): void {
    if (table_exists($db, 'checks')) return;
    safe_exec($db, "CREATE TABLE `checks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `locker_id` INT NOT NULL,
        `check_date` DATETIME NOT NULL,
        `checked_by` VARCHAR(255) NOT NULL,
        `ignore_check` BOOLEAN NOT NULL DEFAULT FALSE,
        FOREIGN KEY (`locker_id`) REFERENCES `lockers`(`id`) ON DELETE CASCADE
    )");
}

function migrate_create_check_items_table(PDO $db): void {
    if (table_exists($db, 'check_items')) return;
    safe_exec($db, "CREATE TABLE `check_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `check_id` INT NOT NULL,
        `item_id` INT NOT NULL,
        `is_present` BOOLEAN NOT NULL,
        FOREIGN KEY (`check_id`) REFERENCES `checks`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE
    )");
}

// ═══════════════════════════════════════════════════════════════
// INCREMENTAL MIGRATIONS — add columns, tables, triggers
// ═══════════════════════════════════════════════════════════════

function migrate_add_relief_column(PDO $db): void {
    if (column_exists($db, 'trucks', 'relief')) return;
    safe_exec($db, "ALTER TABLE `trucks` ADD `relief` BOOLEAN NOT NULL DEFAULT FALSE");
}

function migrate_add_notes_column_to_lockers(PDO $db): void {
    if (column_exists($db, 'lockers', 'notes')) return;
    safe_exec($db, "ALTER TABLE `lockers` ADD `notes` TEXT AFTER `truck_id`");
}

function migrate_add_ignore_check_column(PDO $db): void {
    if (column_exists($db, 'checks', 'ignore_check')) return;
    safe_exec($db, "ALTER TABLE `checks` ADD `ignore_check` BOOLEAN NOT NULL DEFAULT FALSE AFTER `checked_by`");
}

function migrate_add_check_notes_table(PDO $db): void {
    if (table_exists($db, 'check_notes')) return;
    safe_exec($db, "CREATE TABLE `check_notes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `check_id` INT NOT NULL,
        `note` TEXT NOT NULL,
        FOREIGN KEY (`check_id`) REFERENCES `checks`(`id`) ON DELETE CASCADE
    )");
}

function migrate_add_swap_tables(PDO $db): void {
    if (!table_exists($db, 'swap')) {
        safe_exec($db, "CREATE TABLE `swap` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `locker_id` INT DEFAULT NULL,
            `swapped_by` VARCHAR(255) DEFAULT NULL,
            `ignore_check` TINYINT(1) NOT NULL DEFAULT 0,
            `swap_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `locker_id` (`locker_id`)
        )");
    }
    if (!table_exists($db, 'swap_items')) {
        safe_exec($db, "CREATE TABLE `swap_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `swap_id` INT DEFAULT NULL,
            `item_id` INT DEFAULT NULL,
            `is_present` TINYINT(1) NOT NULL,
            KEY `swap_id` (`swap_id`),
            KEY `item_id` (`item_id`)
        )");
    }
    if (!table_exists($db, 'swap_notes')) {
        safe_exec($db, "CREATE TABLE `swap_notes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `swap_id` INT NOT NULL,
            `note` TEXT NOT NULL,
            KEY `swap_id` (`swap_id`)
        )");
    }
}

function migrate_add_protection_codes_table(PDO $db): void {
    if (table_exists($db, 'protection_codes')) return;
    if (safe_exec($db, "CREATE TABLE `protection_codes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(255) NOT NULL,
        `description` TEXT
    )")) {
        safe_exec($db, "INSERT INTO `protection_codes` (`code`, `description`) VALUES (SUBSTRING(MD5(RAND()) FROM 1 FOR 32), 'Initial code')");
    }
}

function migrate_add_locker_item_deletion_log_table(PDO $db): void {
    if (table_exists($db, 'locker_item_deletion_log')) return;
    safe_exec($db, "CREATE TABLE `locker_item_deletion_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `truck_name` VARCHAR(255),
        `locker_name` VARCHAR(255),
        `item_name` VARCHAR(255),
        `deleted_at` DATETIME
    )");
}

function migrate_add_email_addresses_table(PDO $db): void {
    if (table_exists($db, 'email_addresses')) return;
    safe_exec($db, "CREATE TABLE `email_addresses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `email` VARCHAR(255) NOT NULL
    )");
}

function migrate_add_audit_log_table(PDO $db): void {
    if (table_exists($db, 'audit_log')) return;
    safe_exec($db, "CREATE TABLE `audit_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `table_name` VARCHAR(50) NOT NULL,
        `record_id` INT NOT NULL,
        `deleted_data` TEXT NOT NULL,
        `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `deleted_by` VARCHAR(255) DEFAULT 'SYSTEM'
    )");
}

function migrate_add_login_log_table(PDO $db): void {
    if (table_exists($db, 'login_log')) return;
    safe_exec($db, "CREATE TABLE `login_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ip_address` VARCHAR(45) NOT NULL,
        `user_agent` TEXT,
        `login_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `success` BOOLEAN NOT NULL,
        `session_id` VARCHAR(255),
        `referer` VARCHAR(500),
        `accept_language` VARCHAR(255),
        `country` VARCHAR(100),
        `city` VARCHAR(100),
        `browser_info` TEXT,
        INDEX `idx_ip_address` (`ip_address`),
        INDEX `idx_login_time` (`login_time`),
        INDEX `idx_success` (`success`)
    )");
}

// ─── Audit triggers (must run after all referenced columns exist) ──
// Note: requires TRIGGER privilege — may fail on shared hosting
function migrate_add_audit_triggers(PDO $db): void {
    if (!table_exists($db, 'audit_log')) return;
    if (!table_exists($db, 'items') || !table_exists($db, 'lockers') || !table_exists($db, 'trucks') || !table_exists($db, 'checks')) return;

    if (!trigger_exists($db, 'audit_items_delete')) {
        safe_exec($db, "
            CREATE TRIGGER audit_items_delete
                BEFORE DELETE ON items
                FOR EACH ROW
            INSERT INTO audit_log (table_name, record_id, deleted_data)
            VALUES ('items', OLD.id, CONCAT(
                '{\"id\":', OLD.id,
                ',\"name\":\"', IFNULL(OLD.name, ''),
                '\",\"locker_id\":', IFNULL(OLD.locker_id, 'null'), '}'))
        ");
    }

    if (!trigger_exists($db, 'audit_lockers_delete')) {
        safe_exec($db, "
            CREATE TRIGGER audit_lockers_delete
                BEFORE DELETE ON lockers
                FOR EACH ROW
            INSERT INTO audit_log (table_name, record_id, deleted_data)
            VALUES ('lockers', OLD.id, CONCAT(
                '{\"id\":', OLD.id,
                ',\"name\":\"', IFNULL(OLD.name, ''),
                '\",\"truck_id\":', IFNULL(OLD.truck_id, 'null'),
                ',\"notes\":\"', IFNULL(REPLACE(OLD.notes, '\"', '\\\\\"'), ''), '\"}'))
        ");
    }

    if (!trigger_exists($db, 'audit_trucks_delete')) {
        safe_exec($db, "
            CREATE TRIGGER audit_trucks_delete
                BEFORE DELETE ON trucks
                FOR EACH ROW
            INSERT INTO audit_log (table_name, record_id, deleted_data)
            VALUES ('trucks', OLD.id, CONCAT(
                '{\"id\":', OLD.id,
                ',\"name\":\"', IFNULL(OLD.name, ''),
                '\",\"relief\":', IFNULL(OLD.relief, 'false'), '}'))
        ");
    }

    if (!trigger_exists($db, 'audit_checks_delete')) {
        safe_exec($db, "
            CREATE TRIGGER audit_checks_delete
                BEFORE DELETE ON checks
                FOR EACH ROW
            INSERT INTO audit_log (table_name, record_id, deleted_data)
            VALUES ('checks', OLD.id, CONCAT(
                '{\"id\":', OLD.id,
                ',\"locker_id\":', IFNULL(OLD.locker_id, 'null'),
                ',\"check_date\":\"', IFNULL(OLD.check_date, ''),
                '\",\"checked_by\":\"', IFNULL(OLD.checked_by, ''),
                '\",\"ignore_check\":', IFNULL(OLD.ignore_check, 'false'), '}'))
        ");
    }
}
