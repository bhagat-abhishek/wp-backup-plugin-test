<?php

// Backup site function called by REST API
function wp_backup_site()
{
    // Backup the database
    $db_backup = backup_database();
    if (!$db_backup) {
        log_error("Database backup failed.");
        return new WP_REST_Response('Database backup failed', 500);
    }

    // Backup the files
    $file_backup = backup_files();
    if (!$file_backup) {
        log_error("File backup failed.");
        return new WP_REST_Response('File backup failed', 500);
    }

    // Compress both backups into one file
    $backup_file = compress_backups($db_backup, $file_backup);
    if ($backup_file && validate_backup($backup_file)) {
        return new WP_REST_Response('Backup successful', 200);
    } else {
        log_error("Backup validation failed.");
        return new WP_REST_Response('Backup failed', 500);
    }
}

// Function to back up the WordPress database
function backup_database()
{
    global $wpdb;

    $backup_file = WP_BACKUP_DIR . "db-backup-" . date("Y-m-d-H-i-s") . ".sql";
    $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
    $output = '';

    foreach ($tables as $table) {
        $table_name = $table[0];
        $create_table = $wpdb->get_row("SHOW CREATE TABLE $table_name", ARRAY_N);
        $output .= "\n\n" . $create_table[1] . ";\n\n";

        $table_data = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_N);
        foreach ($table_data as $row) {
            $output .= "INSERT INTO $table_name VALUES(";
            for ($i = 0; $i < count($row); $i++) {
                $output .= '"' . $wpdb->_real_escape($row[$i]) . '"';
                if ($i < count($row) - 1) {
                    $output .= ', ';
                }
            }
            $output .= ");\n";
        }
    }

    if (file_put_contents($backup_file, $output)) {
        return $backup_file;
    } else {
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
        return false;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wp_content),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($wp_content) + 1);
            $zip->addFile($file_path, $relative_path);
        }
    }

    $zip->close();

    if (file_exists($backup_file)) {
        return $backup_file;
    }

    return false;
}

// Function to combine the database and file backups into one compressed file
function compress_backups($db_backup, $file_backup)
{
    $zip_file = WP_BACKUP_DIR . "full-backup-" . date("Y-m-d-H-i-s") . ".zip";

    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE) !== TRUE) {
        return false;
    }

    if (file_exists($db_backup)) {
        $zip->addFile($db_backup, basename($db_backup));
    }

    if (file_exists($file_backup)) {
        $zip->addFile($file_backup, basename($file_backup));
    }

    $zip->close();

    if (file_exists($zip_file)) {
        return $zip_file;
    }

    return false;
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

// Function to log errors
function log_error($message)
{
    $log_file = WP_BACKUP_LOG_DIR . "backup-log.txt";
    $timestamp = date("Y-m-d H:i:s");
    $error_message = "$timestamp - ERROR: $message\n";
    file_put_contents($log_file, $error_message, FILE_APPEND);
}
