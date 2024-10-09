<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Backup site function called by REST API
function wp_backup_site($request)
{
    log_message("Backup process started");

    // Backup the database
    $db_backup = backup_database();
    if (!$db_backup) {
        log_message("Database backup failed");
        return new WP_REST_Response('Database backup failed', 500);
    }

    log_message("Database backup completed");

    // Backup the files
    $file_backup = backup_files();
    if (!$file_backup) {
        log_message("File backup failed");
        return new WP_REST_Response('File backup failed', 500);
    }

    log_message("File backup completed");

    // Compress both backups into one file
    $backup_file = compress_backups($db_backup, $file_backup);
    if ($backup_file && validate_backup($backup_file)) {
        log_message("Backup successful");
        return new WP_REST_Response('Backup successful', 200);
    } else {
        log_message("Backup validation failed");
        return new WP_REST_Response('Backup failed', 500);
    }
}

// Function to back up the WordPress database
function backup_database()
{
    global $wpdb;

    $backup_file = WP_BACKUP_DIR . "db-backup-" . date("Y-m-d-H-i-s") . ".sql";

    $tables = $wpdb->get_col('SHOW TABLES');

    if (!$tables) {
        log_message("No tables found in the database");
        return false;
    }

    $sql_file = fopen($backup_file, 'w');
    if (!$sql_file) {
        log_message("Unable to create database backup file");
        return false;
    }

    foreach ($tables as $table) {
        // Get table creation statement
        $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        if ($create_table) {
            fwrite($sql_file, "\n\n" . $create_table[1] . ";\n\n");
        }

        // Get table data
        $entries = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        foreach ($entries as $entry) {
            $values = array_map(array($wpdb, 'escape'), array_values($entry));
            $values = array_map('addslashes', $values);
            $values = implode("', '", $values);
            $values = "'" . $values . "'";
            $sql = "INSERT INTO `$table` (`" . implode('`, `', array_keys($entry)) . "`) VALUES ($values);\n";
            fwrite($sql_file, $sql);
        }
    }

    fclose($sql_file);

    if (file_exists($backup_file)) {
        return $backup_file;
    } else {
        log_message("Database backup file does not exist after creation");
        return false;
    }
}

// Function to back up WordPress files
function backup_files()
{
    $wp_content = WP_CONTENT_DIR;
    $backup_file = WP_BACKUP_DIR . "wp-content-backup-" . date("Y-m-d-H-i-s") . ".zip";

    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE) !== TRUE) {
        log_message("Unable to create zip file for wp-content backup");
        return false;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wp_content, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        $file_path     = $file->getRealPath();
        $relative_path = substr($file_path, strlen($wp_content) + 1);

        if ($file->isDir()) {
            $zip->addEmptyDir($relative_path);
        } else {
            if (!$zip->addFile($file_path, $relative_path)) {
                log_message("Failed to add file to zip: $file_path");
            }
        }
    }

    $zip->close();

    if (file_exists($backup_file)) {
        return $backup_file;
    } else {
        log_message("File backup zip does not exist after creation");
        return false;
    }
}

// Function to combine the database and file backups into one compressed file
function compress_backups($db_backup, $file_backup)
{
    $zip_file = WP_BACKUP_DIR . "full-backup-" . date("Y-m-d-H-i-s") . ".zip";

    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE) !== TRUE) {
        log_message("Unable to create zip file for full backup");
        return false;
    }

    // Add database backup to zip
    if (file_exists($db_backup)) {
        $zip->addFile($db_backup, basename($db_backup));
    } else {
        log_message("Database backup file not found for compression");
        return false;
    }

    // Add file backup to zip
    if (file_exists($file_backup)) {
        $zip->addFile($file_backup, basename($file_backup));
    } else {
        log_message("File backup zip not found for compression");
        return false;
    }

    $zip->close();

    if (file_exists($zip_file)) {
        // Optionally, delete the individual backups to save space
        @unlink($db_backup);
        @unlink($file_backup);

        return $zip_file;
    } else {
        log_message("Full backup zip does not exist after creation");
        return false;
    }
}

// Function to validate the backup zip file
function validate_backup($zip_file)
{
    $zip = new ZipArchive();
    if ($zip->open($zip_file) === TRUE) {
        if ($zip->numFiles > 0) {
            $zip->close();
            return true;
        }
        $zip->close();
    }
    return false;
}

// Function to log messages (both errors and info)
function log_message($message)
{
    $log_file = WP_BACKUP_LOG_DIR . 'backup-log.txt';
    $timestamp = date("Y-m-d H:i:s");
    $log_message = "$timestamp - $message\n";

    // Ensure the log directory exists
    if (!file_exists(WP_BACKUP_LOG_DIR)) {
        mkdir(WP_BACKUP_LOG_DIR, 0755, true);
    }

    // Ensure the log file exists
    if (!file_exists($log_file)) {
        touch($log_file);
    }

    // Check if the log file is writable
    if (is_writable($log_file)) {
        file_put_contents($log_file, $log_message, FILE_APPEND);
    } else {
        // If not writable, log to PHP error log
        error_log("WP Backup Plugin: Unable to write to log file.");
    }
}
