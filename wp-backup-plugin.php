<?php
/*
Plugin Name: WP Backup Plugin
Description: A plugin to backup the WordPress database and files on-demand.
Version: 1.0
Author: Abishek Bhagat
*/

// Define plugin constants
define('WP_BACKUP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_BACKUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_BACKUP_DIR', WP_BACKUP_PLUGIN_DIR . 'backup/');
define('WP_BACKUP_LOG_DIR', WP_BACKUP_PLUGIN_DIR . 'logs/');

// Include the backup functions
require_once WP_BACKUP_PLUGIN_DIR . 'includes/backup-functions.php';

// Create required folders on plugin activation
register_activation_hook(__FILE__, 'wp_backup_plugin_activate');

function wp_backup_plugin_activate()
{
    // Create backup and logs folder if they don't exist
    if (!file_exists(WP_BACKUP_DIR)) {
        mkdir(WP_BACKUP_DIR, 0755, true);
    }

    if (!file_exists(WP_BACKUP_LOG_DIR)) {
        mkdir(WP_BACKUP_LOG_DIR, 0755, true);
    }
}

// Register REST API route for initiating backups
add_action('rest_api_init', function () {
    register_rest_route('wp-backup/v1', '/backup', array(
        'methods' => 'GET',
        'callback' => 'wp_backup_site',
        'permission_callback' => '__return_true', // Update for secured access
    ));
});
