<?php
/**
 * WordPress Auto-Tag Pro Uninstall
 *
 * Uninstalling WordPress Auto-Tag Pro deletes all plugin data.
 * This includes settings, statistics, transients, scheduled events,
 * and post meta data.
 *
 * @package WP_Auto_Tag_Pro
 * @version 1.0.0
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Security check - make sure this is a legitimate uninstall
if (!current_user_can('delete_plugins')) {
    exit;
}

/**
 * Clean up plugin options
 */
function wp_autotag_pro_cleanup_options() {
    // Delete main plugin options
    delete_option('wp_autotag_pro_settings');
    delete_option('wp_autotag_pro_config');
    delete_option('wp_autotag_pro_stats');
    delete_option('wp_autotag_pro_version');

    // Delete any activation/installation flags
    delete_option('wp_autotag_pro_installed');
    delete_option('wp_autotag_pro_activation_time');
    delete_option('wp_autotag_pro_first_run');

    // Delete bulk processing options
    delete_option('wp_autotag_pro_bulk_queue');
    delete_option('wp_autotag_pro_bulk_status');
    delete_option('wp_autotag_pro_bulk_progress');



    // Delete cache settings and statistics
    delete_option('wp_autotag_pro_cache_stats');
    delete_option('wp_autotag_pro_performance_stats');
    delete_option('wp_autotag_pro_error_log');

    // Delete admin interface state
    delete_option('wp_autotag_pro_admin_notices_dismissed');
    delete_option('wp_autotag_pro_wizard_completed');

    // For multisite installations
    if (is_multisite()) {
        delete_site_option('wp_autotag_pro_network_settings');
        delete_site_option('wp_autotag_pro_network_activated');
        delete_site_option('wp_autotag_pro_network_version');

        // Clean up site-specific options across all sites
        global $wpdb;
        $sites = get_sites();
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);

            // Delete options for each site
            delete_option('wp_autotag_pro_settings');
            delete_option('wp_autotag_pro_config');
            delete_option('wp_autotag_pro_stats');
            delete_option('wp_autotag_pro_bulk_queue');
            delete_option('wp_autotag_pro_bulk_status');

            restore_current_blog();
        }
    }
}

/**
 * Clean up plugin transients
 */
function wp_autotag_pro_cleanup_transients() {
    global $wpdb;
    
    // Delete all plugin-related transients
    $wpdb->query($wpdb->prepare("
        DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE %s 
        OR option_name LIKE %s
        OR option_name LIKE %s
        OR option_name LIKE %s
    ", 
        '_transient_autotag_%',
        '_transient_timeout_autotag_%', 
        '_transient_autotag_bulk_queue_%',
        '_transient_timeout_autotag_bulk_queue_%'
    ));
    
    // For multisite installations
    if (is_multisite()) {
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->sitemeta} 
            WHERE meta_key LIKE %s 
            OR meta_key LIKE %s
        ", 
            '_site_transient_autotag_%',
            '_site_transient_timeout_autotag_%'
        ));
    }
}

/**
 * Clean up scheduled events (cron jobs)
 */
function wp_autotag_pro_cleanup_scheduled_events() {
    // Clear scheduled hooks
    wp_clear_scheduled_hook('autotag_cleanup_cron');
    wp_clear_scheduled_hook('autotag_process_bulk_batch');
    wp_clear_scheduled_hook('autotag_resume_processing');
    wp_clear_scheduled_hook('autotag_bulk_process');
    wp_clear_scheduled_hook('autotag_cache_cleanup');
    wp_clear_scheduled_hook('autotag_stats_update');
    
    // Clear any single scheduled events
    $scheduled_events = [
        'autotag_cleanup_cron',
        'autotag_process_bulk_batch',
        'autotag_resume_processing',
        'autotag_bulk_process',
        'autotag_cache_cleanup',
        'autotag_stats_update',
        'autotag_daily_maintenance',
        'autotag_weekly_cleanup'
    ];
    
    foreach ($scheduled_events as $hook) {
        // Clear all scheduled instances of this hook
        while ($timestamp = wp_next_scheduled($hook)) {
            wp_unschedule_event($timestamp, $hook);
        }
        
        // Clear hook with arguments if any exist
        $cron_array = get_option('cron');
        if (is_array($cron_array)) {
            foreach ($cron_array as $timestamp => $cron) {
                if (isset($cron[$hook])) {
                    foreach ($cron[$hook] as $key => $event) {
                        wp_unschedule_event($timestamp, $hook, $event['args']);
                    }
                }
            }
        }
    }
    
    // Clean up any remaining plugin-related cron entries
    if (is_array($cron_array)) {
        foreach ($cron_array as $timestamp => $cron) {
            foreach ($cron as $hook => $events) {
                if (strpos($hook, 'autotag_') === 0 || strpos($hook, 'wp_autotag_pro_') === 0) {
                    foreach ($events as $key => $event) {
                        wp_unschedule_event($timestamp, $hook, $event['args']);
                    }
                }
            }
        }
    }
}

/**
 * Clean up post meta data
 */
function wp_autotag_pro_cleanup_post_meta() {
    global $wpdb;
    
    // Delete all plugin-related post meta
    $meta_keys = [
        'auto_tag_status',
        'auto_tag_processed_hash',
        'auto_tag_last_processed',
        'auto_tag_processing_lock',
        'auto_tag_processing_attempts',
        'auto_tag_skip_reason',
        'auto_tag_tags_applied',
        'auto_tag_tags_count',
        'auto_tag_processing_time',
        'auto_tag_version_processed',
        'auto_tag_error_count',
        'auto_tag_year_tags_added',
        '_wp_autotag_pro_processed',
        '_wp_autotag_pro_tags_added',
        '_wp_autotag_pro_last_run'
    ];
    
    foreach ($meta_keys as $meta_key) {
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta} 
            WHERE meta_key = %s
        ", $meta_key));
        
        // Also clean up any meta keys that start with our prefix
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta} 
            WHERE meta_key LIKE %s
        ", 'auto_tag_%'));
        
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta} 
            WHERE meta_key LIKE %s
        ", '_wp_autotag_pro_%'));
    }
    
    // Clean up term meta if any exists
    $wpdb->query($wpdb->prepare("
        DELETE FROM {$wpdb->termmeta} 
        WHERE meta_key LIKE %s
        OR meta_key LIKE %s
    ", 'auto_tag_%', '_wp_autotag_pro_%'));
}

/**
 * Clean up user meta data
 */
function wp_autotag_pro_cleanup_user_meta() {
    global $wpdb;
    
    // Delete user preferences and settings
    $user_meta_keys = [
        'wp_autotag_pro_dismissed_notices',
        'wp_autotag_pro_user_preferences'
    ];
    
    foreach ($user_meta_keys as $meta_key) {
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->usermeta} 
            WHERE meta_key = %s
        ", $meta_key));
    }
}

/**
 * Clean up custom database tables
 */
function wp_autotag_pro_cleanup_database_tables() {
    global $wpdb;
    
    // List of potential custom tables created by the plugin
    $custom_tables = [
        $wpdb->prefix . 'autotag_processing_queue',
        $wpdb->prefix . 'autotag_processing_log',
        $wpdb->prefix . 'autotag_cache_data',
        $wpdb->prefix . 'autotag_statistics'
    ];
    
    foreach ($custom_tables as $table_name) {
        $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
    }
}

/**
 * Clean up temporary files and caches
 */
function wp_autotag_pro_cleanup_files() {
    // Clear any object cache groups
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('autotag');
        wp_cache_flush_group('wp_autotag_pro');
    }
    
    // Clear WordPress object cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // If there are any log files or temporary files, clean them up here
    $upload_dir = wp_upload_dir();
    $plugin_temp_dir = $upload_dir['basedir'] . '/wp-auto-tag-pro/';
    
    if (is_dir($plugin_temp_dir)) {
        // Recursively delete temporary files directory
        wp_autotag_pro_delete_directory($plugin_temp_dir);
    }
    
    // Clean up any log files in wp-content
    $log_files = [
        WP_CONTENT_DIR . '/wp-auto-tag-pro.log',
        WP_CONTENT_DIR . '/debug-wp-auto-tag-pro.log',
        WP_CONTENT_DIR . '/autotag-processing.log'
    ];
    
    foreach ($log_files as $log_file) {
        if (file_exists($log_file)) {
            unlink($log_file);
        }
    }
}

/**
 * Helper function to recursively delete a directory
 */
function wp_autotag_pro_delete_directory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            wp_autotag_pro_delete_directory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

/**
 * Main uninstall function
 */
function wp_autotag_pro_uninstall() {
    // Clean up options
    wp_autotag_pro_cleanup_options();
    
    // Clean up transients
    wp_autotag_pro_cleanup_transients();
    
    // Clean up scheduled events
    wp_autotag_pro_cleanup_scheduled_events();
    
    // Clean up post meta
    wp_autotag_pro_cleanup_post_meta();
    
    // Clean up user meta
    wp_autotag_pro_cleanup_user_meta();
    
    // Clean up custom database tables
    wp_autotag_pro_cleanup_database_tables();
    
    // Clean up files and caches
    wp_autotag_pro_cleanup_files();
    
    // Flush rewrite rules to clean up any custom rewrite rules
    flush_rewrite_rules();
    
    // Clear any remaining WordPress caches
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // Log the successful uninstall if debugging is enabled
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log('[WP Auto-Tag Pro] Plugin successfully uninstalled and all data cleaned up.');
    }
}

// Run the uninstall
wp_autotag_pro_uninstall();