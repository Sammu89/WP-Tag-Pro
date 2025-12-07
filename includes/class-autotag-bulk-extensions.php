<?php
/**
 * Bulk Processing Extensions - Rollback System and Reporting
 * Extends the main bulk processing class with compliance features
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extension methods for the AutoTag_Bulk class
 * These methods implement the missing rollback system and enhanced reporting
 */
trait AutoTag_Bulk_Extensions {
    
    /**
     * ROLLBACK SYSTEM METHODS
     */
    
    /**
     * Enhanced process_post method with rollback data capture
     */
    private function process_post_safely_with_rollback($post_id) {
        // Capture terms before processing for rollback
        $terms_before = $this->get_post_current_terms($post_id);
        update_post_meta($post_id, '_autotag_terms_before_processing', $terms_before);
        
        // Process the post
        $result = $this->process_post_safely($post_id);
        
        // Clean up temporary meta after processing
        delete_post_meta($post_id, '_autotag_terms_before_processing');
        
        return $result;
    }
    
    /**
     * Store rollback data in database
     */
    private function store_rollback_data($rollback_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_rollback_data';
        $this->maybe_create_rollback_table();
        
        foreach ($rollback_data as $data) {
            $wpdb->insert(
                $table_name,
                [
                    'post_id' => $data['post_id'],
                    'queue_id' => $data['queue_id'],
                    'terms_before' => wp_json_encode($data['terms_before']),
                    'terms_after' => wp_json_encode($data['terms_after']),
                    'tags_added' => $data['tags_added'],
                    'processed_at' => $data['processed_at'],
                    'rollback_status' => $data['rollback_status']
                ],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%s']
            );
        }
        
        $this->cleanup_excess_rollback_data();
    }
    
    /**
     * Create rollback data table if it doesn't exist
     */
    private function maybe_create_rollback_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_rollback_data';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE {$table_name} (
                id int(11) NOT NULL AUTO_INCREMENT,
                post_id bigint(20) NOT NULL,
                queue_id varchar(100) NOT NULL,
                terms_before longtext,
                terms_after longtext,
                tags_added int(11) DEFAULT 0,
                processed_at int(11) NOT NULL,
                rollback_status varchar(20) DEFAULT 'available',
                rolled_back_at int(11) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY post_id (post_id),
                KEY queue_id (queue_id),
                KEY processed_at (processed_at),
                KEY rollback_status (rollback_status)
            ) {$charset_collate};";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * Cleanup excess rollback data to maintain limit
     */
    private function cleanup_excess_rollback_data() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_rollback_data';
        $current_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        if ($current_count > self::MAX_ROLLBACK_HISTORY) {
            $excess = $current_count - self::MAX_ROLLBACK_HISTORY;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table_name} ORDER BY processed_at ASC LIMIT %d",
                $excess
            ));
        }
    }
    
    /**
     * Process rollback for specific posts
     */
    public function rollback_posts($post_ids, $options = []) {
        if (empty($post_ids) || !is_array($post_ids)) {
            return new WP_Error('invalid_posts', __('Invalid post IDs provided', 'wp-auto-tag-pro'));
        }
        
        $rollback_queue_id = uniqid('rollback_' . wp_generate_password(8, false));
        $rollback_data = $this->get_rollback_data_for_posts($post_ids);
        
        if (empty($rollback_data)) {
            return new WP_Error('no_rollback_data', __('No rollback data found for specified posts', 'wp-auto-tag-pro'));
        }
        
        // Schedule rollback processing
        wp_schedule_single_event(time() + 5, 'autotag_rollback_batch', [
            $rollback_queue_id,
            $rollback_data,
            $options
        ]);
        
        return $rollback_queue_id;
    }
    
    /**
     * Get rollback data for specific posts
     */
    private function get_rollback_data_for_posts($post_ids) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_rollback_data';
        $post_ids_placeholder = implode(',', array_fill(0, count($post_ids), '%d'));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE post_id IN ({$post_ids_placeholder}) 
             AND rollback_status = 'available' 
             ORDER BY processed_at DESC",
            ...$post_ids
        ), ARRAY_A);
    }
    
    /**
     * Process rollback batch (WordPress cron callback)
     */
    public function process_rollback_batch($rollback_queue_id, $rollback_data, $options = []) {
        $results = [
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'details' => []
        ];
        
        foreach ($rollback_data as $data) {
            try {
                $result = $this->rollback_single_post($data);
                
                if (is_wp_error($result)) {
                    $results['errors']++;
                    $results['details'][$data['post_id']] = [
                        'status' => 'error',
                        'message' => $result->get_error_message()
                    ];
                } else {
                    $results['processed']++;
                    $results['details'][$data['post_id']] = [
                        'status' => 'success',
                        'terms_restored' => $result['terms_restored'] ?? 0
                    ];
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $results['details'][$data['post_id']] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                $this->log_error('Rollback error for post ' . $data['post_id'] . ': ' . $e->getMessage());
            }
        }
        
        // Create rollback report
        $this->create_rollback_report($rollback_queue_id, $results);
        
        // Trigger completion action
        do_action('autotag_rollback_completed', $rollback_queue_id, $results);
        
        return $results;
    }
    
    /**
     * Rollback a single post to its previous state
     */
    private function rollback_single_post($rollback_data) {
        $post_id = $rollback_data['post_id'];
        $terms_before = json_decode($rollback_data['terms_before'], true);
        
        if (!$terms_before || !is_array($terms_before)) {
            return new WP_Error('invalid_rollback_data', __('Invalid rollback data', 'wp-auto-tag-pro'));
        }
        
        $restored_count = 0;
        
        // Restore terms for each taxonomy
        foreach ($terms_before as $taxonomy => $terms) {
            if (taxonomy_exists($taxonomy) && is_array($terms)) {
                $result = wp_set_post_terms($post_id, $terms, $taxonomy, false);
                
                if (!is_wp_error($result)) {
                    $restored_count++;
                }
            }
        }
        
        // Mark rollback as completed in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'autotag_rollback_data';
        
        $wpdb->update(
            $table_name,
            [
                'rollback_status' => 'completed',
                'rolled_back_at' => time()
            ],
            ['id' => $rollback_data['id']],
            ['%s', '%d'],
            ['%d']
        );
        
        // Update post meta
        update_post_meta($post_id, 'auto_tag_rolled_back', time());
        delete_post_meta($post_id, 'auto_tag_processed_hash');
        
        return [
            'status' => 'success',
            'terms_restored' => $restored_count
        ];
    }
    
    /**
     * Get rollback history for posts
     */
    public function get_rollback_history($post_ids = [], $limit = 50) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_rollback_data';
        $where_clause = '';
        $params = [];
        
        if (!empty($post_ids)) {
            $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
            $where_clause = "WHERE post_id IN ({$placeholders})";
            $params = $post_ids;
        }
        
        $params[] = intval($limit);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} {$where_clause} ORDER BY processed_at DESC LIMIT %d",
            ...$params
        ), ARRAY_A);
    }
    
    /**
     * Cleanup old rollback data
     */
    public function cleanup_old_rollback_data() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_rollback_data';
        $cutoff_date = time() - (self::ROLLBACK_RETENTION_DAYS * DAY_IN_SECONDS);
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE processed_at < %d",
            $cutoff_date
        ));
        
        if ($deleted > 0) {
            $this->log_info("Cleaned up {$deleted} old rollback records");
        }
    }
    
    /**
     * PROCESSING REPORTS SYSTEM METHODS
     */
    
    /**
     * Create rollback report
     */
    private function create_rollback_report($rollback_queue_id, $results) {
        $report_data = [
            'report_id' => $rollback_queue_id,
            'type' => 'rollback',
            'created_at' => time(),
            'total_posts' => count($results['details']),
            'processed_posts' => $results['processed'],
            'errors' => $results['errors'],
            'skipped_posts' => $results['skipped'],
            'details' => wp_json_encode($results['details'])
        ];
        
        $this->store_processing_report($report_data);
    }
    
    /**
     * Store processing report in database
     */
    private function store_processing_report($report_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_processing_reports';
        $this->maybe_create_reports_table();
        
        $wpdb->insert(
            $table_name,
            $report_data,
            ['%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s']
        );
        
        $this->cleanup_excess_reports();
    }
    
    /**
     * Create processing reports table if it doesn't exist
     */
    private function maybe_create_reports_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_processing_reports';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE {$table_name} (
                id int(11) NOT NULL AUTO_INCREMENT,
                report_id varchar(100) NOT NULL,
                type varchar(20) NOT NULL,
                created_at int(11) NOT NULL,
                total_posts int(11) DEFAULT 0,
                processed_posts int(11) DEFAULT 0,
                errors int(11) DEFAULT 0,
                skipped_posts int(11) DEFAULT 0,
                details longtext,
                PRIMARY KEY (id),
                KEY report_id (report_id),
                KEY type (type),
                KEY created_at (created_at)
            ) {$charset_collate};";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * Cleanup excess reports to maintain limit
     */
    private function cleanup_excess_reports() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_processing_reports';
        $current_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        if ($current_count > self::MAX_REPORT_DETAILS) {
            $excess = $current_count - self::MAX_REPORT_DETAILS;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table_name} ORDER BY created_at ASC LIMIT %d",
                $excess
            ));
        }
    }
    
    /**
     * Generate comprehensive processing report
     */
    public function generate_processing_report($queue_id = null, $options = []) {
        $report = [
            'report_id' => uniqid('report_' . wp_generate_password(8, false)),
            'generated_at' => time(),
            'queue_statistics' => $this->get_queue_statistics($queue_id),
            'performance_metrics' => $this->get_performance_metrics(),
            'error_summary' => $this->get_error_summary(),
            'rollback_availability' => $this->get_rollback_availability(),
            'system_status' => $this->get_system_status()
        ];
        
        // Store detailed report
        $report_data = [
            'report_id' => $report['report_id'],
            'type' => 'comprehensive',
            'created_at' => time(),
            'total_posts' => $report['queue_statistics']['total_processed'] ?? 0,
            'processed_posts' => $report['queue_statistics']['successful'] ?? 0,
            'errors' => $report['queue_statistics']['errors'] ?? 0,
            'skipped_posts' => $report['queue_statistics']['skipped'] ?? 0,
            'details' => wp_json_encode($report)
        ];
        
        $this->store_processing_report($report_data);
        
        return $report;
    }
    
    /**
     * Get performance metrics
     */
    private function get_performance_metrics() {
        $active_queues = $this->get_active_queues();
        $metrics = [
            'average_processing_speed' => 0,
            'memory_usage' => memory_get_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'execution_time_limit' => ini_get('max_execution_time'),
            'total_processing_time' => 0
        ];
        
        $total_speed = 0;
        $queue_count = 0;
        
        foreach ($active_queues as $queue) {
            if (!empty($queue['posts_per_second'])) {
                $total_speed += $queue['posts_per_second'];
                $queue_count++;
            }
            
            $metrics['total_processing_time'] += ($queue['last_update'] ?? time()) - ($queue['start_time'] ?? time());
        }
        
        if ($queue_count > 0) {
            $metrics['average_processing_speed'] = $total_speed / $queue_count;
        }
        
        return $metrics;
    }
    
    /**
     * Get error summary
     */
    private function get_error_summary() {
        global $wpdb;
        
        $reports_table = $wpdb->prefix . 'autotag_processing_reports';
        
        $recent_errors = $wpdb->get_results($wpdb->prepare(
            "SELECT type, errors, created_at FROM {$reports_table} 
             WHERE errors > 0 AND created_at > %d 
             ORDER BY created_at DESC LIMIT 10",
            time() - (7 * DAY_IN_SECONDS)
        ), ARRAY_A);
        
        return [
            'recent_errors' => $recent_errors,
            'total_recent_errors' => array_sum(array_column($recent_errors, 'errors'))
        ];
    }
    
    /**
     * Get rollback availability status
     */
    private function get_rollback_availability() {
        global $wpdb;
        
        $rollback_table = $wpdb->prefix . 'autotag_rollback_data';
        
        $available_rollbacks = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$rollback_table} WHERE rollback_status = 'available'"
        );
        
        $total_rollbacks = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$rollback_table}"
        );
        
        return [
            'available_rollbacks' => intval($available_rollbacks),
            'total_rollback_records' => intval($total_rollbacks),
            'rollback_coverage' => $total_rollbacks > 0 ? ($available_rollbacks / $total_rollbacks) * 100 : 0
        ];
    }
    
    /**
     * Get system status for diagnostics
     */
    private function get_system_status() {
        return [
            'wordpress_version' => get_bloginfo('version'),
            'plugin_version' => defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '2.0.0',
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'scheduled_events' => $this->get_scheduled_events_count()
        ];
    }
    
    /**
     * Get count of scheduled events
     */
    private function get_scheduled_events_count() {
        $cron_jobs = wp_get_scheduled_events();
        $autotag_events = 0;
        
        foreach ($cron_jobs as $timestamp => $events) {
            foreach ($events as $hook => $event_data) {
                if (strpos($hook, 'autotag_') === 0) {
                    $autotag_events += count($event_data);
                }
            }
        }
        
        return $autotag_events;
    }
    
    /**
     * Cleanup old processing reports
     */
    public function cleanup_old_reports() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_processing_reports';
        $cutoff_date = time() - (self::REPORT_RETENTION_DAYS * DAY_IN_SECONDS);
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %d",
            $cutoff_date
        ));
        
        if ($deleted > 0) {
            $this->log_info("Cleaned up {$deleted} old processing reports");
        }
    }
    
    /**
     * Get processing reports with filtering
     */
    public function get_processing_reports($type = null, $limit = 20) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'autotag_processing_reports';
        $where_clause = '';
        $params = [];
        
        if ($type) {
            $where_clause = 'WHERE type = %s';
            $params[] = $type;
        }
        
        $params[] = intval($limit);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d",
            ...$params
        ), ARRAY_A);
    }
    
    /**
     * Send enhanced completion notification with detailed statistics
     */
    private function maybe_send_enhanced_completion_notification($queue, $report_id) {
        $settings = $this->get_settings_safely();
        
        if (empty($settings['bulk_completion_notifications'])) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }
        
        $subject = sprintf(__('[%s] Auto-Tag Pro Bulk Processing Complete - Detailed Report', 'wp-auto-tag-pro'), get_bloginfo('name'));
        
        $performance_summary = '';
        if (!empty($queue['posts_per_second'])) {
            $performance_summary = sprintf(
                __("\n\nPerformance Summary:\n- Processing Speed: %.2f posts/second\n- Average Tags per Post: %.2f\n- Memory Usage: %s", 'wp-auto-tag-pro'),
                $queue['posts_per_second'],
                $queue['tags_per_post'] ?? 0,
                size_format(memory_get_peak_usage(true))
            );
        }
        
        $rollback_info = '';
        $available_rollbacks = $this->get_rollback_availability();
        if ($available_rollbacks['available_rollbacks'] > 0) {
            $rollback_info = sprintf(
                __("\n\nRollback Information:\n- Rollback data available for %d posts\n- Use the plugin admin to rollback changes if needed", 'wp-auto-tag-pro'),
                $available_rollbacks['available_rollbacks']
            );
        }
        
        $message = sprintf(
            __("Bulk processing has been completed successfully.\n\nProcessing Details:\n- Action: %s\n- Posts Processed: %d/%d\n- Tags Added: %d\n- Year Tags Added: %d\n- Duration: %d seconds\n- Errors: %d%s%s\n\nQueue ID: %s\nReport ID: %s", 'wp-auto-tag-pro'),
            $queue['action'],
            $queue['processed_posts'],
            $queue['total_posts'],
            $queue['tags_added'],
            $queue['year_tags_added'] ?? 0,
            $queue['total_duration'],
            $queue['errors'],
            $performance_summary,
            $rollback_info,
            $queue['queue_id'],
            $report_id
        );
        
        wp_mail($admin_email, $subject, $message);
    }
}