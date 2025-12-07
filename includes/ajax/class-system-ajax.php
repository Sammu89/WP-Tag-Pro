<?php
/**
 * System/Maintenance AJAX Handler Class
 * Handles system maintenance and statistics AJAX requests - extracted from AutoTag_Ajax
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_System_Ajax {
    
    public function __construct() {
        // System maintenance AJAX hooks
        add_action('wp_ajax_autotag_cleanup_orphaned', [$this, 'cleanup_orphaned_meta']);
        add_action('wp_ajax_autotag_reset_processing', [$this, 'reset_all_processing']);
        add_action('wp_ajax_autotag_clear_cache', [$this, 'clear_plugin_cache']);
        add_action('wp_ajax_autotag_optimize_database', [$this, 'optimize_database']);
        
        // Statistics and monitoring AJAX hooks
        add_action('wp_ajax_autotag_get_stats', [$this, 'get_current_stats']);
        add_action('wp_ajax_autotag_export_stats', [$this, 'export_statistics']);
        
        // WordPress tags integration
        add_action('wp_ajax_autotag_get_existing_tags', [$this, 'get_existing_tags']);
        add_action('wp_ajax_autotag_sync_existing_tags', [$this, 'sync_existing_tags']);
    }
    
    /**
     * Clean up orphaned post meta
     */
    public function cleanup_orphaned_meta() {
        $this->verify_ajax_security('administrator'); // Require admin for maintenance
        
        try {
            global $wpdb;
            
            // Clean up orphaned auto_tag_status meta
            $deleted_status = $wpdb->query($wpdb->prepare("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.ID IS NULL AND pm.meta_key = %s
            ", 'auto_tag_status'));
            
            // Clean up orphaned auto_tag_processed_hash meta
            $deleted_hash = $wpdb->query($wpdb->prepare("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.ID IS NULL AND pm.meta_key = %s
            ", 'auto_tag_processed_hash'));
            
            // Clean up orphaned auto_tag_last_processed meta
            $deleted_timestamp = $wpdb->query($wpdb->prepare("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.ID IS NULL AND pm.meta_key = %s
            ", 'auto_tag_last_processed'));
            
            $total_deleted = $deleted_status + $deleted_hash + $deleted_timestamp;
            
            wp_send_json_success([
                'message' => sprintf(__('Cleaned up %d orphaned meta entries', 'wp-auto-tag-pro'), $total_deleted),
                'deleted_count' => $total_deleted,
                'details' => [
                    'status_entries' => $deleted_status,
                    'hash_entries' => $deleted_hash,
                    'timestamp_entries' => $deleted_timestamp
                ]
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error cleaning up orphaned meta: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Reset all processing status
     */
    public function reset_all_processing() {
        $this->verify_ajax_security('administrator'); // Require admin for destructive operations
        
        // Additional confirmation check
        $confirm = sanitize_text_field($_POST['confirm'] ?? '');
        if ($confirm !== 'yes') {
            wp_send_json_error(__('Operation requires confirmation', 'wp-auto-tag-pro'));
        }
        
        try {
            global $wpdb;
            
            // Reset all auto_tag_status to pending
            $updated_status = $wpdb->update(
                $wpdb->postmeta,
                ['meta_value' => 'pending'],
                ['meta_key' => 'auto_tag_status']
            );
            
            // Delete processed hashes (force reprocessing)
            $deleted_hashes = $wpdb->delete(
                $wpdb->postmeta,
                ['meta_key' => 'auto_tag_processed_hash']
            );
            
            // Delete timestamps
            $deleted_timestamps = $wpdb->delete(
                $wpdb->postmeta,
                ['meta_key' => 'auto_tag_last_processed']
            );
            
            wp_send_json_success([
                'message' => sprintf(__('Reset processing status for %d posts', 'wp-auto-tag-pro'), $updated_status),
                'updated_posts' => $updated_status,
                'deleted_hashes' => $deleted_hashes,
                'deleted_timestamps' => $deleted_timestamps
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error resetting processing status: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Clear plugin cache
     */
    public function clear_plugin_cache() {
        $this->verify_ajax_security('manage_options');
        
        try {
            $cache_types = sanitize_text_field($_POST['cache_type'] ?? 'all');
            $cleared_items = [];
            
            switch ($cache_types) {
                case 'object':
                    wp_cache_flush_group('autotag');
                    $cleared_items[] = 'Object cache';
                    break;
                    
                case 'transients':
                    global $wpdb;
                    $deleted_transients = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_autotag_%'));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_autotag_%'));
                    $cleared_items[] = sprintf('Transients (%d)', $deleted_transients);
                    break;
                    
                case 'tags':
                    wp_autotag_pro()->clear_existing_tags_cache();
                    if (wp_autotag_pro()->processor) {
                        wp_autotag_pro()->processor->clear_content_cache();
                    }
                    $cleared_items[] = 'Tags cache';
                    break;
                    
                case 'all':
                default:
                    // Clear all caches
                    wp_cache_flush_group('autotag');
                    
                    if (wp_autotag_pro()->config) {
                        wp_autotag_pro()->config->config_cache = null;
                        wp_autotag_pro()->config->hash_cache = null;
                    }
                    
                    global $wpdb;
                    $deleted_transients = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_autotag_%'));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_autotag_%'));
                    
                    $cleared_items[] = 'All caches';
                    $cleared_items[] = sprintf('Transients (%d)', $deleted_transients);
                    break;
            }
            
            wp_send_json_success([
                'message' => __('Cache cleared successfully', 'wp-auto-tag-pro'),
                'cleared_items' => $cleared_items,
                'cache_type' => $cache_types
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error clearing cache: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Optimize database tables
     */
    public function optimize_database() {
        $this->verify_ajax_security('administrator');
        
        try {
            global $wpdb;
            
            $optimized_tables = [];
            $table_sizes_before = [];
            $table_sizes_after = [];
            
            // Get table sizes before optimization
            $tables_to_optimize = [$wpdb->posts, $wpdb->postmeta, $wpdb->options];
            
            foreach ($tables_to_optimize as $table) {
                $size_query = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb'
                    FROM information_schema.TABLES 
                    WHERE table_schema = %s AND table_name = %s
                ", DB_NAME, $table));
                
                $table_sizes_before[$table] = $size_query ? $size_query->size_mb : 0;
                
                // Optimize table - Validate table name for security
                if (in_array($table, [$wpdb->posts, $wpdb->postmeta, $wpdb->options])) {
                    $result = $wpdb->query("OPTIMIZE TABLE `" . esc_sql($table) . "`");
                } else {
                    $result = false;
                }
                if ($result !== false) {
                    $optimized_tables[] = $table;
                }
                
                // Get size after optimization
                $size_query_after = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb'
                    FROM information_schema.TABLES 
                    WHERE table_schema = %s AND table_name = %s
                ", DB_NAME, $table));
                
                $table_sizes_after[$table] = $size_query_after ? $size_query_after->size_mb : 0;
            }
            
            wp_send_json_success([
                'message' => sprintf(__('Optimized %d database tables', 'wp-auto-tag-pro'), count($optimized_tables)),
                'optimized_tables' => $optimized_tables,
                'sizes_before' => $table_sizes_before,
                'sizes_after' => $table_sizes_after,
                'space_saved' => array_sum($table_sizes_before) - array_sum($table_sizes_after)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error optimizing database: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get current plugin statistics
     */
    public function get_current_stats() {
        $this->verify_ajax_security('manage_options');
        
        try {
            $stats = wp_autotag_pro()->get_stats();
            $processing_stats = wp_autotag_pro()->processor ? wp_autotag_pro()->processor->get_processing_stats() : [];
            $config_stats = wp_autotag_pro()->config ? wp_autotag_pro()->config->get_config_stats() : [];
            $bulk_stats = wp_autotag_pro()->bulk ? wp_autotag_pro()->bulk->get_bulk_stats() : [];
            
            // Filter sensitive information
            $filtered_stats = [
                'general' => array_intersect_key($stats, array_flip([
                    'total_processed', 'total_tags_added', 'year_tags_added', 
                    'last_bulk_run', 'bulk_runs_count', 'errors_count'
                ])),
                'processing' => array_intersect_key($processing_stats, array_flip([
                    'total_posts', 'processed_posts', 'needs_processing', 'outdated_posts'
                ])),
                'configuration' => array_intersect_key($config_stats, array_flip([
                    'mode', 'keywords_count', 'families_count', 'last_updated'
                ])),
                'bulk' => $bulk_stats
            ];
            
            wp_send_json_success([
                'stats' => $filtered_stats,
                'generated_at' => current_time('mysql'),
                'plugin_version' => defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '1.0.0'
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error getting statistics: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Export plugin statistics
     */
    public function export_statistics() {
        $this->verify_ajax_security('administrator'); // Require admin for exports
        
        try {
            $stats = wp_autotag_pro()->get_stats();
            $processing_stats = wp_autotag_pro()->processor ? wp_autotag_pro()->processor->get_processing_stats() : [];
            $config_stats = wp_autotag_pro()->config ? wp_autotag_pro()->config->get_config_stats() : [];
            
            $export_data = [
                'plugin_version' => defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '1.0.0',
                'export_date' => current_time('mysql'),
                'site_url' => get_site_url(),
                'statistics' => [
                    'general' => $stats,
                    'processing' => $processing_stats,
                    'configuration' => $config_stats
                ]
            ];
            
            $filename = 'autotag-pro-stats-' . date('Y-m-d-H-i-s') . '.json';
            
            wp_send_json_success([
                'message' => __('Statistics exported successfully', 'wp-auto-tag-pro'),
                'filename' => $filename,
                'data' => $export_data,
                'download_url' => admin_url('admin-ajax.php?action=autotag_download_export&filename=' . $filename)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error exporting statistics: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get existing WordPress tags
     */
    public function get_existing_tags() {
        $this->verify_ajax_security('edit_posts');
        
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? 'post_tag');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $limit = max(1, min(200, intval($_POST['limit'] ?? 50)));
        
        // Validate taxonomy
        if (!taxonomy_exists($taxonomy)) {
            wp_send_json_error(__('Invalid taxonomy specified', 'wp-auto-tag-pro'));
        }
        
        try {
            $args = [
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'number' => $limit,
                'orderby' => 'count',
                'order' => 'DESC'
            ];
            
            if (!empty($search)) {
                $args['search'] = $search;
            }
            
            $terms = get_terms($args);
            
            if (is_wp_error($terms)) {
                wp_send_json_error($terms->get_error_message());
            }
            
            $formatted_terms = [];
            foreach ($terms as $term) {
                $formatted_terms[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'count' => $term->count,
                    'description' => $term->description
                ];
            }
            
            wp_send_json_success([
                'terms' => $formatted_terms,
                'total_found' => count($formatted_terms),
                'taxonomy' => $taxonomy,
                'search_term' => $search
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error getting existing tags: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Sync with existing WordPress tags
     */
    public function sync_existing_tags() {
        $this->verify_ajax_security('manage_options');
        
        try {
            // Get all existing tags
            $existing_tags = get_terms([
                'taxonomy' => 'post_tag',
                'hide_empty' => false,
                'fields' => 'names'
            ]);
            
            if (is_wp_error($existing_tags)) {
                wp_send_json_error($existing_tags->get_error_message());
            }
            
            // Update plugin's existing tags cache
            wp_autotag_pro()->sync_existing_tags($existing_tags);
            
            wp_send_json_success([
                'message' => sprintf(__('Synced %d existing tags', 'wp-auto-tag-pro'), count($existing_tags)),
                'synced_tags_count' => count($existing_tags),
                'sample_tags' => array_slice($existing_tags, 0, 10)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error syncing existing tags: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Security verification for AJAX requests
     */
    private function verify_ajax_security($required_capability = 'manage_options') {
        // Check if it's an AJAX request
        if (!wp_doing_ajax()) {
            wp_send_json_error(__('Invalid request type', 'wp-auto-tag-pro'), 400);
        }
        
        // Verify nonce
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security verification failed', 'wp-auto-tag-pro'), 403);
        }
        
        // Check user capabilities
        if (!current_user_can($required_capability)) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'), 403);
        }
    }
}