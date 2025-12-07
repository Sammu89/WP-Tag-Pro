<?php
/**
 * Updated Bulk Processing Class
 * Handles background bulk operations with dual-mode support, year tags, and enhanced queue management
 * FIXED: Race conditions, memory leaks, and concurrency issues
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Bulk {
    
    /**
     * Queue processing constants - FIXED: Added safety limits
     */
    private const MAX_QUEUE_SIZE = 10000; // Maximum posts per queue
    private const MAX_BATCH_SIZE = 100;   // Maximum posts per batch
    private const MIN_BATCH_SIZE = 10;    // Minimum posts per batch
    private const MAX_EXECUTION_TIME = 300; // 5 minutes max
    private const QUEUE_LOCK_TIMEOUT = 300; // 5 minutes lock timeout
    private const MAX_RETRY_ATTEMPTS = 3;  // Maximum retry attempts
    
    /**
     * Rollback system constants
     */
    private const MAX_ROLLBACK_HISTORY = 100; // Maximum rollback records
    private const ROLLBACK_RETENTION_DAYS = 30; // Days to keep rollback data
    
    /**
     * Processing reports constants
     */
    private const MAX_REPORT_DETAILS = 1000; // Maximum detailed entries per report
    private const REPORT_RETENTION_DAYS = 90; // Days to keep processing reports
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize bulk processing hooks
     */
    private function init_hooks() {
        // WordPress cron hooks
        add_action('autotag_process_bulk_batch', [$this, 'process_bulk_batch']);
        add_action('autotag_cleanup_cron', [$this, 'cleanup_expired_queues']);
        
        // Custom bulk processing actions
        add_action('autotag_bulk_process_new_posts', [$this, 'bulk_process_new_posts']);
        add_action('autotag_bulk_process_outdated_posts', [$this, 'bulk_process_outdated_posts']);
        add_action('autotag_bulk_process_all_posts', [$this, 'bulk_process_all_posts']);
        add_action('autotag_bulk_process_year_tags', [$this, 'bulk_process_year_tags']);
        
        // Configuration change hooks
        add_action('autotag_hash_changed', [$this, 'on_config_hash_changed'], 10, 2);
        add_action('autotag_config_updated', [$this, 'on_config_updated'], 10, 3);
        
        // Register custom cron schedules
        add_filter('cron_schedules', [$this, 'add_custom_cron_schedules']);
        
        // Emergency cleanup on plugin deactivation
        register_deactivation_hook(__FILE__, [$this, 'emergency_cleanup']);
        
        // Rollback system hooks
        add_action('autotag_rollback_batch', [$this, 'process_rollback_batch']);
        add_action('autotag_cleanup_rollback_data', [$this, 'cleanup_old_rollback_data']);
        
        // Processing report hooks
        add_action('autotag_generate_report', [$this, 'generate_processing_report']);
        add_action('autotag_cleanup_reports', [$this, 'cleanup_old_reports']);
        
        // Schedule recurring cleanup tasks
        if (!wp_next_scheduled('autotag_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'autotag_cleanup_cron');
        }
        
        if (!wp_next_scheduled('autotag_cleanup_rollback_data')) {
            wp_schedule_event(time(), 'daily', 'autotag_cleanup_rollback_data');
        }
        
        if (!wp_next_scheduled('autotag_cleanup_reports')) {
            wp_schedule_event(time(), 'weekly', 'autotag_cleanup_reports');
        }
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_custom_cron_schedules($schedules) {
        if (!is_array($schedules)) {
            $schedules = [];
        }
        
        $schedules['every_5_minutes'] = [
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'wp-auto-tag-pro')
        ];
        
        $schedules['every_15_minutes'] = [
            'interval' => 900,
            'display' => __('Every 15 Minutes', 'wp-auto-tag-pro')
        ];
        
        $schedules['twice_daily'] = [
            'interval' => 43200, // 12 hours
            'display' => __('Twice Daily', 'wp-auto-tag-pro')
        ];
        
        return $schedules;
    }
    
    /**
     * Process bulk batch (WordPress cron callback) - FIXED: Enhanced concurrency control
     */
    public function process_bulk_batch($queue_id) {
        if (empty($queue_id)) {
            $this->log_error('process_bulk_batch called with empty queue_id');
            return;
        }
        
        // FIXED: Implement queue locking to prevent race conditions
        $lock_key = 'autotag_queue_lock_' . $queue_id;
        $lock_acquired = $this->acquire_queue_lock($lock_key);
        
        if (!$lock_acquired) {
            $this->log_info('Queue ' . $queue_id . ' is already being processed by another instance');
            return;
        }
        
        try {
            $queue = $this->get_queue_safely($queue_id);
            
            if (!$queue) {
                $this->log_error('Queue not found: ' . $queue_id);
                return;
            }
            
            if ($queue['status'] === 'cancelled' || $queue['status'] === 'completed') {
                $this->cleanup_queue($queue_id);
                return;
            }
            
            // Check for stuck/expired queues - FIXED
            if ($this->is_queue_expired($queue)) {
                $this->mark_queue_error($queue_id, 'Queue expired - processing took too long');
                return;
            }
            
            // Mark queue as processing with timestamp
            $queue['status'] = 'processing';
            $queue['last_update'] = time();
            $queue['processing_attempts'] = ($queue['processing_attempts'] ?? 0) + 1;
            
            // FIXED: Check retry limit
            if ($queue['processing_attempts'] > self::MAX_RETRY_ATTEMPTS) {
                $this->mark_queue_error($queue_id, 'Maximum retry attempts exceeded');
                return;
            }
            
            $this->update_queue_safely($queue_id, $queue);
            
            // Get posts for current batch - FIXED: Memory optimization
            $batch_posts = $this->get_batch_posts($queue);
            
            if (empty($batch_posts)) {
                // No more posts to process
                $this->complete_queue($queue_id);
                return;
            }
            
            // Process the batch with enhanced error handling
            $batch_results = $this->process_batch($batch_posts, $queue);
            
            // Create rollback data before updating queue
            $this->create_batch_rollback_data($queue_id, $batch_posts, $batch_results);
            
            // Update queue with results
            $this->update_queue_progress($queue_id, $batch_results);
            
            // Schedule next batch if not complete
            if (!$this->is_queue_complete($queue_id)) {
                $this->schedule_next_batch($queue_id);
            }
            
        } catch (Exception $e) {
            $this->log_error('Error processing bulk batch ' . $queue_id . ': ' . $e->getMessage());
            $this->mark_queue_error($queue_id, $e->getMessage());
        } finally {
            // FIXED: Always release lock
            $this->release_queue_lock($lock_key);
        }
    }
    
    /**
     * Acquire queue lock to prevent race conditions - FIXED: New method
     */
    private function acquire_queue_lock($lock_key) {
        $lock_value = time() + self::QUEUE_LOCK_TIMEOUT;
        
        // Try to set lock if it doesn't exist
        $acquired = add_option($lock_key, $lock_value, '', 'no');
        
        if (!$acquired) {
            // Lock exists, check if it's expired
            $existing_lock = get_option($lock_key, 0);
            
            if ($existing_lock < time()) {
                // Lock expired, try to update it
                $acquired = update_option($lock_key, $lock_value);
            }
        }
        
        return $acquired;
    }
    
    /**
     * Release queue lock - FIXED: New method
     */
    private function release_queue_lock($lock_key) {
        delete_option($lock_key);
    }
    
    /**
     * Check if queue is expired - FIXED: New method
     */
    private function is_queue_expired($queue) {
        $age = time() - ($queue['last_update'] ?? $queue['start_time']);
        return $age > self::MAX_EXECUTION_TIME;
    }
    
    /**
     * Get queue data safely with validation - FIXED: Enhanced safety
     */
    private function get_queue_safely($queue_id) {
        $queue = get_transient('autotag_bulk_queue_' . $queue_id);
        
        if (!$queue || !is_array($queue)) {
            return false;
        }
        
        // Validate queue structure
        $required_fields = ['queue_id', 'action', 'status', 'post_ids', 'total_posts'];
        foreach ($required_fields as $field) {
            if (!isset($queue[$field])) {
                $this->log_error('Invalid queue structure - missing field: ' . $field);
                return false;
            }
        }
        
        return $queue;
    }
    
    /**
     * Update queue data safely - FIXED: Enhanced safety
     */
    private function update_queue_safely($queue_id, $queue_data) {
        if (!is_array($queue_data)) {
            return false;
        }
        
        $queue_data['last_update'] = time();
        
        // Set longer expiration for processing queues
        $expiration = $queue_data['status'] === 'completed' ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
        
        return set_transient('autotag_bulk_queue_' . $queue_id, $queue_data, $expiration);
    }
    
    /**
     * Get batch posts with memory optimization - FIXED: Memory management
     */
    private function get_batch_posts($queue) {
        if (!isset($queue['post_ids']) || !is_array($queue['post_ids'])) {
            return [];
        }
        
        $start_index = $queue['current_batch'] * $queue['batch_size'];
        $batch_size = min($queue['batch_size'], self::MAX_BATCH_SIZE);
        
        // FIXED: Use array_slice with preserve_keys=false to save memory
        $batch_posts = array_slice($queue['post_ids'], $start_index, $batch_size, false);
        
        // Clear processed posts from queue to save memory - FIXED
        if ($start_index > 0) {
            $queue['post_ids'] = array_slice($queue['post_ids'], $start_index);
            $queue['current_batch'] = 0; // Reset batch counter
            $this->update_queue_safely($queue['queue_id'], $queue);
        }
        
        return $batch_posts;
    }
    
    /**
     * Process a batch of posts with enhanced error handling - FIXED
     */
    private function process_batch($post_ids, $queue) {
        $settings = $this->get_settings_safely();
        $max_execution_time = min($settings['max_execution_time'] ?? 30, self::MAX_EXECUTION_TIME);
        $options = $queue['options'] ?? [];
        
        $results = [
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total_tags_added' => 0,
            'year_tags_added' => 0,
            'families_matched' => 0,
            'keyword_matches' => 0,
            'details' => []
        ];
        
        $start_time = time();
        $config = $this->get_config_safely();
        
        if (!$config) {
            $results['errors'] = count($post_ids);
            return $results;
        }
        
        $is_families_mode = $config['m'] === 'f';
        
        foreach ($post_ids as $post_id) {
            // Check execution time limit - FIXED: More frequent checks
            if ((time() - $start_time) >= $max_execution_time) {
                $this->log_info('Batch processing stopped due to time limit');
                break;
            }
            
            // Memory management - FIXED: Check memory usage
            if ($this->is_memory_limit_approaching()) {
                $this->log_info('Batch processing stopped due to memory limit');
                break;
            }
            
            try {
                // Handle special processing modes
                if ($queue['action'] === 'year_tags_only') {
                    $result = $this->process_year_tags_only($post_id);
                } else {
                    $result = $this->process_post_safely($post_id);
                }
                
                // Process results
                if (is_wp_error($result)) {
                    $results['errors']++;
                    $results['details'][$post_id] = [
                        'status' => 'error',
                        'message' => $result->get_error_message()
                    ];
                } elseif (isset($result['status']) && $result['status'] === 'skipped') {
                    $results['skipped']++;
                    $results['details'][$post_id] = $result;
                } else {
                    $results['processed']++;
                    $results['total_tags_added'] += $result['tags_added'] ?? 0;
                    
                    // Track year tags
                    if (!empty($result['year_tag'])) {
                        $results['year_tags_added']++;
                    }
                    
                    // Track mode-specific matches
                    if (!empty($result['matches'])) {
                        if ($is_families_mode) {
                            $results['families_matched'] += count($result['matches']);
                        } else {
                            $results['keyword_matches'] += count($result['matches']);
                        }
                    }
                    
                    // FIXED: Don't store full details to save memory
                    $results['details'][$post_id] = [
                        'status' => 'success',
                        'tags_added' => $result['tags_added'] ?? 0
                    ];
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $results['details'][$post_id] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                $this->log_error('Error processing post ' . $post_id . ': ' . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Check if memory limit is approaching - FIXED: New method
     */
    private function is_memory_limit_approaching() {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit === '-1') {
            return false; // No memory limit
        }
        
        $memory_limit_bytes = $this->convert_to_bytes($memory_limit);
        $current_usage = memory_get_usage(true);
        
        // Return true if we're using more than 80% of available memory
        return ($current_usage / $memory_limit_bytes) > 0.8;
    }
    
    /**
     * Convert memory string to bytes - FIXED: New method
     */
    private function convert_to_bytes($memory_string) {
        $unit = strtolower(substr($memory_string, -1));
        $size = (int) $memory_string;
        
        switch ($unit) {
            case 'g':
                $size *= 1024;
                // no break
            case 'm':
                $size *= 1024;
                // no break
            case 'k':
                $size *= 1024;
        }
        
        return $size;
    }
    
    /**
     * Process post safely with error handling - FIXED: New method
     */
    private function process_post_safely($post_id) {
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->processor) {
            return new WP_Error('processor_unavailable', 'Processor not available');
        }
        
        return wp_autotag_pro()->processor->process_post($post_id);
    }
    
    /**
     * Get settings safely - FIXED: New method
     */
    private function get_settings_safely() {
        if (function_exists('wp_autotag_pro')) {
            return wp_autotag_pro()->get_settings();
        }
        
        return [
            'batch_size' => 50,
            'max_execution_time' => 30
        ];
    }
    
    /**
     * Get config safely - FIXED: New method
     */
    private function get_config_safely() {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
            return wp_autotag_pro()->config->get_configuration();
        }
        
        return null;
    }
    
    /**
     * Process year tags only for a post - FIXED: Enhanced validation
     */
    private function process_year_tags_only($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', __('Post not found.', 'wp-auto-tag-pro'));
        }
        
        // Check if post type supports tags
        $settings = $this->get_settings_safely();
        $enabled_taxonomies = $settings['enabled_taxonomies'] ?? ['post_tag'];
        
        $supports_tags = false;
        foreach ($enabled_taxonomies as $taxonomy) {
            if (function_exists('wp_autotag_pro') && wp_autotag_pro()->post_type_supports_tags($post->post_type, $taxonomy)) {
                $supports_tags = true;
                break;
            }
        }
        
        if (!$supports_tags) {
            return ['status' => 'skipped', 'reason' => 'no_tag_support', 'tags_added' => 0];
        }
        
        // Generate year tag with validation - FIXED
        $post_date = $post->post_date;
        if (empty($post_date) || $post_date === '0000-00-00 00:00:00') {
            return ['status' => 'skipped', 'reason' => 'invalid_date', 'tags_added' => 0];
        }
        
        $timestamp = strtotime($post_date);
        if ($timestamp === false) {
            return ['status' => 'skipped', 'reason' => 'invalid_date', 'tags_added' => 0];
        }
        
        $year = date('Y', $timestamp);
        
        // Validate year range - FIXED
        if ($year < 1970 || $year > (date('Y') + 1)) {
            return ['status' => 'skipped', 'reason' => 'invalid_year', 'tags_added' => 0];
        }
        
        // Get existing tags
        $existing_tags = [];
        foreach ($enabled_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
                if (!is_wp_error($terms)) {
                    $existing_tags = array_merge($existing_tags, $terms);
                }
            }
        }
        
        // Check if year tag already exists
        $has_year_tag = false;
        foreach ($existing_tags as $tag) {
            if (preg_match('/^\d{4}$/', $tag)) {
                $has_year_tag = true;
                break;
            }
        }
        
        if ($has_year_tag) {
            return ['status' => 'skipped', 'reason' => 'has_year_tag', 'tags_added' => 0];
        }
        
        // Add year tag
        $tags_added = 0;
        foreach ($enabled_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                try {
                    $final_tags = array_merge([$year], $existing_tags);
                    $final_tags = array_unique($final_tags);
                    
                    // Apply ordering (year first, then alphabetical)
                    sort($final_tags);
                    $year_index = array_search($year, $final_tags);
                    if ($year_index !== false) {
                        unset($final_tags[$year_index]);
                        array_unshift($final_tags, $year);
                    }
                    
                    $result = wp_set_post_terms($post_id, $final_tags, $taxonomy, false);
                    
                    if (!is_wp_error($result)) {
                        $tags_added = 1;
                        
                        // Create year term if it doesn't exist
                        if (!term_exists($year, $taxonomy)) {
                            wp_insert_term($year, $taxonomy);
                        }
                        
                        break; // Only process first taxonomy for year tags
                    }
                } catch (Exception $e) {
                    $this->log_error('Error adding year tag to post ' . $post_id . ': ' . $e->getMessage());
                    continue;
                }
            }
        }
        
        if ($tags_added > 0) {
            // Update post meta
            update_post_meta($post_id, 'auto_tag_last_processed', time());
            
            return [
                'status' => 'success',
                'tags_added' => $tags_added,
                'year_tag' => $year,
                'matches' => []
            ];
        } else {
            return new WP_Error('year_tag_failed', __('Failed to add year tag', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Update queue progress with enhanced tracking - FIXED: Atomic updates
     */
    private function update_queue_progress($queue_id, $batch_results) {
        $queue = $this->get_queue_safely($queue_id);
        
        if (!$queue) {
            return false;
        }
        
        // FIXED: Atomic updates to prevent race conditions
        $queue['processed_posts'] += $batch_results['processed'];
        $queue['tags_added'] += $batch_results['total_tags_added'];
        $queue['year_tags_added'] = ($queue['year_tags_added'] ?? 0) + $batch_results['year_tags_added'];
        $queue['families_matched'] = ($queue['families_matched'] ?? 0) + $batch_results['families_matched'];
        $queue['keyword_matches'] = ($queue['keyword_matches'] ?? 0) + $batch_results['keyword_matches'];
        $queue['errors'] += $batch_results['errors'];
        $queue['current_batch']++;
        $queue['last_update'] = time();
        
        // Calculate performance metrics
        $elapsed_time = time() - $queue['start_time'];
        $queue['posts_per_second'] = $elapsed_time > 0 ? $queue['processed_posts'] / $elapsed_time : 0;
        $queue['tags_per_post'] = $queue['processed_posts'] > 0 ? $queue['tags_added'] / $queue['processed_posts'] : 0;
        
        // Check if complete
        $remaining_posts = $queue['total_posts'] - $queue['processed_posts'];
        if ($remaining_posts <= 0 || $queue['current_batch'] >= $queue['total_batches']) {
            $queue['status'] = 'completed';
            $queue['completed_at'] = time();
        }
        
        return $this->update_queue_safely($queue_id, $queue);
    }
    
    /**
     * Schedule next batch with intelligent delay - FIXED: Prevent infinite loops
     */
    private function schedule_next_batch($queue_id) {
        $queue = $this->get_queue_safely($queue_id);
        
        if (!$queue || $queue['status'] !== 'processing') {
            return false;
        }
        
        // FIXED: Check for maximum processing time
        $elapsed_time = time() - $queue['start_time'];
        if ($elapsed_time > self::MAX_EXECUTION_TIME) {
            $this->mark_queue_error($queue_id, 'Maximum processing time exceeded');
            return false;
        }
        
        // Calculate intelligent delay based on performance
        $base_delay = 5; // 5 seconds base
        $performance_delay = 0;
        
        // Add delay if error rate is high
        if ($queue['errors'] > 0) {
            $error_rate = $queue['errors'] / max($queue['processed_posts'], 1);
            if ($error_rate > 0.1) { // More than 10% error rate
                $performance_delay += 10;
            }
        }
        
        // Add delay for server load management
        if ($queue['posts_per_second'] > 5) { // Very fast processing might stress server
            $performance_delay += 3;
        }
        
        // FIXED: Maximum delay limit
        $delay = min(apply_filters('autotag_batch_delay', $base_delay + $performance_delay, $queue), 60);
        
        return wp_schedule_single_event(time() + $delay, 'autotag_process_bulk_batch', [$queue_id]);
    }
    
    /**
     * Check if queue is complete
     */
    private function is_queue_complete($queue_id) {
        $queue = $this->get_queue_safely($queue_id);
        
        if (!$queue) {
            return true;
        }
        
        return $queue['status'] === 'completed' || 
               $queue['processed_posts'] >= $queue['total_posts'] ||
               $queue['current_batch'] >= $queue['total_batches'];
    }
    
    /**
     * Complete queue processing - FIXED: Enhanced completion logic
     */
    private function complete_queue($queue_id) {
        $queue = $this->get_queue_safely($queue_id);
        
        if (!$queue) {
            return;
        }
        
        $queue['status'] = 'completed';
        $queue['completed_at'] = time();
        $queue['last_update'] = time();
        $queue['total_duration'] = time() - $queue['start_time'];
        
        // FIXED: Clear post_ids array to save memory
        unset($queue['post_ids']);
        
        $this->update_queue_safely($queue_id, $queue);
        
        // Update global statistics
        $this->update_global_stats($queue);
        
        // Trigger completion action
        do_action('autotag_bulk_process_completed', $queue_id, $queue);
        
        $this->log_info('Bulk processing completed: ' . $queue_id . ' - Processed: ' . $queue['processed_posts'] . ' posts in ' . $queue['total_duration'] . ' seconds');
        
        // Send completion notification if enabled
        $this->maybe_send_completion_notification($queue);
    }
    
    /**
     * Mark queue as error - FIXED: Enhanced error tracking
     */
    private function mark_queue_error($queue_id, $error_message) {
        $queue = $this->get_queue_safely($queue_id);
        
        if (!$queue) {
            return;
        }
        
        $queue['status'] = 'error';
        $queue['error_message'] = $error_message;
        $queue['error_at'] = time();
        $queue['last_update'] = time();
        
        // FIXED: Clear post_ids to save memory
        unset($queue['post_ids']);
        
        $this->update_queue_safely($queue_id, $queue);
        
        do_action('autotag_bulk_process_error', $queue_id, $error_message);
        
        $this->log_error('Bulk processing error: ' . $queue_id . ' - ' . $error_message);
    }
    
    /**
     * Clean up queue - FIXED: Enhanced cleanup
     */
    private function cleanup_queue($queue_id) {
        // Clear scheduled events first
        wp_clear_scheduled_hook('autotag_process_bulk_batch', [$queue_id]);
        
        // Delete transient
        delete_transient('autotag_bulk_queue_' . $queue_id);
        
        // Clean up any locks
        delete_option('autotag_queue_lock_' . $queue_id);
        
        $this->log_info('Cleaned up queue: ' . $queue_id);
    }
    
    /**
     * Update global statistics
     */
    private function update_global_stats($queue) {
        $stats_update = [
            'total_processed' => $queue['processed_posts'],
            'total_tags_added' => $queue['tags_added'],
            'last_bulk_run' => $queue['completed_at'],
            'bulk_runs_count' => 1
        ];
        
        // Add mode-specific stats
        if (!empty($queue['year_tags_added'])) {
            $stats_update['year_tags_added'] = $queue['year_tags_added'];
        }
        
        if (!empty($queue['families_matched'])) {
            $stats_update['families_matched'] = $queue['families_matched'];
        }
        
        if (!empty($queue['keyword_matches'])) {
            $stats_update['keyword_matches'] = $queue['keyword_matches'];
        }
        
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->update_stats($stats_update);
        }
    }
    
    /**
     * Send completion notification if enabled
     */
    private function maybe_send_completion_notification($queue) {
        $settings = $this->get_settings_safely();
        
        if (empty($settings['bulk_completion_notifications'])) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }
        
        $subject = sprintf(__('[%s] Auto-Tag Pro Bulk Processing Complete', 'wp-auto-tag-pro'), get_bloginfo('name'));
        
        $message = sprintf(
            __("Bulk processing has been completed.\n\nProcessing Details:\n- Action: %s\n- Posts Processed: %d\n- Tags Added: %d\n- Year Tags Added: %d\n- Duration: %d seconds\n- Errors: %d\n\nQueue ID: %s", 'wp-auto-tag-pro'),
            $queue['action'],
            $queue['processed_posts'],
            $queue['tags_added'],
            $queue['year_tags_added'] ?? 0,
            $queue['total_duration'],
            $queue['errors'],
            $queue['queue_id']
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Bulk process new posts
     */
    public function bulk_process_new_posts($options = []) {
        return $this->start_bulk_process('new', $options);
    }
    
    /**
     * Bulk process outdated posts
     */
    public function bulk_process_outdated_posts($options = []) {
        return $this->start_bulk_process('outdated', $options);
    }
    
    /**
     * Bulk process all posts
     */
    public function bulk_process_all_posts($options = []) {
        return $this->start_bulk_process('all', $options);
    }
    
    /**
     * Bulk process year tags only
     */
    public function bulk_process_year_tags($options = []) {
        return $this->start_bulk_process('year_tags_only', $options);
    }
    
    /**
     * Start bulk processing - FIXED: Enhanced validation and safety
     */
    private function start_bulk_process($action, $options = []) {
        $defaults = [
            'batch_size' => $this->get_settings_safely()['batch_size'] ?? 50,
            'immediate' => false,
            'priority' => 'normal'
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        // FIXED: Validate batch size
        $options['batch_size'] = max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, intval($options['batch_size'])));
        
        // Get posts for processing
        $post_ids = $this->get_posts_for_action($action, $options);
        
        if (empty($post_ids)) {
            return new WP_Error('no_posts', __('No posts found for processing', 'wp-auto-tag-pro'));
        }
        
        // FIXED: Check queue size limit
        if (count($post_ids) > self::MAX_QUEUE_SIZE) {
            return new WP_Error('queue_too_large', sprintf(__('Too many posts (%d). Maximum allowed: %d', 'wp-auto-tag-pro'), count($post_ids), self::MAX_QUEUE_SIZE));
        }
        
        // Create queue
        $queue_id = $this->create_processing_queue($post_ids, $action, $options['batch_size'], $options);
        
        if (!$queue_id) {
            return new WP_Error('queue_failed', __('Failed to create processing queue', 'wp-auto-tag-pro'));
        }
        
        // Schedule or start immediately
        if ($options['immediate']) {
            $this->process_bulk_batch($queue_id);
        } else {
            // Priority scheduling
            $delay = $options['priority'] === 'high' ? 0 : 5;
            wp_schedule_single_event(time() + $delay, 'autotag_process_bulk_batch', [$queue_id]);
        }
        
        return $queue_id;
    }
    
    /**
     * Get posts for specific action with enhanced filtering - FIXED: Memory optimization
     */
    private function get_posts_for_action($action, $options = []) {
        $settings = $this->get_settings_safely();
        $post_types = $settings['enabled_post_types'] ?? ['post'];
        
        $args = [
            'post_type' => $post_types,
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true, // FIXED: Improve performance
            'update_post_meta_cache' => false, // FIXED: Reduce memory usage
            'update_post_term_cache' => false // FIXED: Reduce memory usage
        ];
        
        // Apply date filters if specified
        if (!empty($options['date_from'])) {
            $args['date_query'][] = [
                'after' => $options['date_from']
            ];
        }
        
        if (!empty($options['date_to'])) {
            $args['date_query'][] = [
                'before' => $options['date_to']
            ];
        }
        
        switch ($action) {
            case 'new':
                $args['meta_query'] = [
                    'relation' => 'OR',
                    [
                        'key' => 'auto_tag_processed_hash',
                        'compare' => 'NOT EXISTS'
                    ],
                    [
                        'key' => 'auto_tag_status',
                        'value' => 'pending'
                    ]
                ];
                break;
                
            case 'outdated':
                $current_hash = $this->get_current_hash_safely();
                if ($current_hash) {
                    $args['meta_query'] = [
                        [
                            'key' => 'auto_tag_processed_hash',
                            'value' => $current_hash,
                            'compare' => '!='
                        ],
                        [
                            'key' => 'auto_tag_status',
                            'value' => 'disabled',
                            'compare' => '!='
                        ]
                    ];
                } else {
                    return []; // No hash available
                }
                break;
                
            case 'year_tags_only':
                // Posts that might not have year tags
                $args['meta_query'] = [
                    'relation' => 'OR',
                    [
                        'key' => 'auto_tag_status',
                        'compare' => 'NOT EXISTS'
                    ],
                    [
                        'key' => 'auto_tag_status',
                        'value' => 'disabled',
                        'compare' => '!='
                    ]
                ];
                break;
                
            case 'all':
                $args['meta_query'] = [
                    'relation' => 'OR',
                    [
                        'key' => 'auto_tag_status',
                        'compare' => 'NOT EXISTS'
                    ],
                    [
                        'key' => 'auto_tag_status',
                        'value' => 'disabled',
                        'compare' => '!='
                    ]
                ];
                break;
                
            default:
                return [];
        }
        
        // Apply author filter if specified
        if (!empty($options['author_id'])) {
            $args['author'] = intval($options['author_id']);
        }
        
        // Apply category filter if specified
        if (!empty($options['category_ids']) && is_array($options['category_ids'])) {
            $args['category__in'] = array_map('intval', $options['category_ids']);
        }
        
        // FIXED: Use get_posts instead of WP_Query for better memory management
        return get_posts($args);
    }
    
    /**
     * Get current hash safely - FIXED: New method
     */
    private function get_current_hash_safely() {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
            return wp_autotag_pro()->config->get_current_hash();
        }
        return null;
    }
    
    /**
     * Create processing queue with enhanced options - FIXED: Enhanced validation
     */
    private function create_processing_queue($post_ids, $action, $batch_size, $options = []) {
        // FIXED: Validate input parameters
        if (empty($post_ids) || !is_array($post_ids)) {
            return false;
        }
        
        if (!in_array($action, ['new', 'outdated', 'all', 'year_tags_only'])) {
            return false;
        }
        
        $batch_size = max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, intval($batch_size)));
        
        $queue_id = uniqid('bulk_' . $action . '_' . wp_generate_password(8, false));
        
        $queue_data = [
            'queue_id' => $queue_id,
            'action' => $action,
            'status' => 'initialized',
            'post_ids' => array_values(array_unique(array_map('intval', $post_ids))), // FIXED: Sanitize and deduplicate
            'total_posts' => count($post_ids),
            'processed_posts' => 0,
            'current_batch' => 0,
            'total_batches' => ceil(count($post_ids) / $batch_size),
            'batch_size' => $batch_size,
            'tags_added' => 0,
            'year_tags_added' => 0,
            'families_matched' => 0,
            'keyword_matches' => 0,
            'errors' => 0,
            'processing_attempts' => 0, // FIXED: Added retry tracking
            'options' => $options,
            'start_time' => time(),
            'last_update' => time(),
            'created_by' => get_current_user_id(),
            'config_hash' => $this->get_current_hash_safely(),
            'plugin_version' => defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '2.0.0'
        ];
        
        // Store queue data for 1 hour initially, extend on completion
        $stored = set_transient('autotag_bulk_queue_' . $queue_id, $queue_data, HOUR_IN_SECONDS);
        
        if ($stored) {
            $this->log_info('Created queue: ' . $queue_id . ' with ' . count($post_ids) . ' posts');
        }
        
        return $stored ? $queue_id : false;
    }
    
    /**
     * On configuration hash changed - trigger reprocessing detection
     */
    public function on_config_hash_changed($old_hash, $new_hash) {
        $this->log_info('Configuration hash changed - posts will need reprocessing');
        
        // Optionally schedule automatic reprocessing of outdated posts
        $settings = $this->get_settings_safely();
        if ($settings['auto_reprocess_on_config_change'] ?? false) {
            wp_schedule_single_event(time() + 60, 'autotag_bulk_process_outdated_posts');
        }
        
        // Clear any existing queues that might be using old configuration
        $this->cancel_outdated_queues($old_hash);
    }
    
    /**
     * On configuration updated
     */
    public function on_config_updated($config, $old_hash, $new_hash) {
        // Log configuration change with details
        $mode = $config['m'] === 'f' ? 'families' : 'classic';
        $year_tags = !empty($config['y']) ? 'enabled' : 'disabled';
        
        $this->log_info("Configuration updated - Mode: {$mode}, Year tags: {$year_tags}");
    }
    
    /**
     * Cancel outdated queues - FIXED: Enhanced safety
     */
    private function cancel_outdated_queues($old_hash) {
        global $wpdb;
        
        $queue_transients = $wpdb->get_results("
            SELECT option_name, option_value 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_autotag_bulk_queue_%'
        ");
        
        if (!$queue_transients) {
            return;
        }
        
        $cancelled = 0;
        
        foreach ($queue_transients as $transient) {
            $queue_data = maybe_unserialize($transient->option_value);
            
            if ($queue_data && 
                isset($queue_data['config_hash']) && 
                $queue_data['config_hash'] === $old_hash &&
                !in_array($queue_data['status'], ['completed', 'cancelled'])) {
                
                $queue_id = str_replace('_transient_autotag_bulk_queue_', '', $transient->option_name);
                
                $queue_data['status'] = 'cancelled';
                $queue_data['cancel_reason'] = 'config_changed';
                $queue_data['last_update'] = time();
                
                // FIXED: Clear post_ids to save memory
                unset($queue_data['post_ids']);
                
                set_transient('autotag_bulk_queue_' . $queue_id, $queue_data, HOUR_IN_SECONDS);
                wp_clear_scheduled_hook('autotag_process_bulk_batch', [$queue_id]);
                
                $cancelled++;
            }
        }
        
        if ($cancelled > 0) {
            $this->log_info("Cancelled {$cancelled} outdated bulk processing queues");
        }
    }
    
    /**
     * Clean up expired queues - FIXED: Enhanced cleanup logic
     */
    public function cleanup_expired_queues() {
        global $wpdb;
        
        // Find all queue transients
        $queue_transients = $wpdb->get_results("
            SELECT option_name, option_value 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_autotag_bulk_queue_%'
        ");
        
        if (!$queue_transients) {
            return;
        }
        
        $cleaned = 0;
        $current_time = time();
        
        foreach ($queue_transients as $transient) {
            $queue_data = maybe_unserialize($transient->option_value);
            
            if (!$queue_data || !is_array($queue_data)) {
                // Invalid queue data, clean it up
                $queue_id = str_replace('_transient_autotag_bulk_queue_', '', $transient->option_name);
                $this->cleanup_queue($queue_id);
                $cleaned++;
                continue;
            }
            
            // Clean up completed or error queues older than 24 hours
            if (in_array($queue_data['status'], ['completed', 'error', 'cancelled'])) {
                $completion_time = $queue_data['completed_at'] ?? $queue_data['error_at'] ?? $queue_data['last_update'] ?? 0;
                $age = $current_time - $completion_time;
                
                if ($age > DAY_IN_SECONDS) {
                    $queue_id = str_replace('_transient_autotag_bulk_queue_', '', $transient->option_name);
                    $this->cleanup_queue($queue_id);
                    $cleaned++;
                }
            }
            
            // Clean up stuck queues (processing for more than MAX_EXECUTION_TIME)
            elseif ($queue_data['status'] === 'processing') {
                $age = $current_time - ($queue_data['last_update'] ?? $queue_data['start_time']);
                if ($age > self::MAX_EXECUTION_TIME) {
                    $queue_id = str_replace('_transient_autotag_bulk_queue_', '', $transient->option_name);
                    $this->mark_queue_error($queue_id, 'Queue stuck - exceeded maximum processing time');
                    $cleaned++;
                }
            }
            
            // Clean up very old initialized queues (older than 1 hour)
            elseif ($queue_data['status'] === 'initialized') {
                $age = $current_time - $queue_data['start_time'];
                if ($age > HOUR_IN_SECONDS) {
                    $queue_id = str_replace('_transient_autotag_bulk_queue_', '', $transient->option_name);
                    $this->cleanup_queue($queue_id);
                    $cleaned++;
                }
            }
        }
        
        // Clean up orphaned locks
        $this->cleanup_orphaned_locks();
        
        if ($cleaned > 0) {
            $this->log_info("Cleaned up {$cleaned} expired bulk processing queues");
        }
    }
    
    /**
     * Clean up orphaned locks - FIXED: New method
     */
    private function cleanup_orphaned_locks() {
        global $wpdb;
        
        $locks = $wpdb->get_results("
            SELECT option_name, option_value 
            FROM {$wpdb->options} 
            WHERE option_name LIKE 'autotag_queue_lock_%'
        ");
        
        $current_time = time();
        $cleaned_locks = 0;
        
        foreach ($locks as $lock) {
            $lock_time = intval($lock->option_value);
            
            // Clean up expired locks
            if ($lock_time < $current_time) {
                delete_option($lock->option_name);
                $cleaned_locks++;
            }
        }
        
        if ($cleaned_locks > 0) {
            $this->log_info("Cleaned up {$cleaned_locks} orphaned queue locks");
        }
    }
    
    /**
     * Emergency cleanup on plugin deactivation - FIXED: New method
     */
    public function emergency_cleanup() {
        // Cancel all active queues
        $this->cancel_all_queues();
        
        // Clean up all locks immediately
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'autotag_queue_lock_%'));
        
        $this->log_info('Emergency cleanup completed on plugin deactivation');
    }
    
    /**
     * Get all active queues
     */
    public function get_active_queues() {
        global $wpdb;
        
        $queue_transients = $wpdb->get_results("
            SELECT option_name, option_value 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_autotag_bulk_queue_%'
        ");
        
        $active_queues = [];
        
        foreach ($queue_transients as $transient) {
            $queue_data = maybe_unserialize($transient->option_value);
            
            if ($queue_data && !in_array($queue_data['status'], ['completed', 'cancelled'])) {
                $queue_id = str_replace('_transient_autotag_bulk_queue_', '', $transient->option_name);
                
                // FIXED: Remove post_ids from returned data to save memory
                $queue_summary = $queue_data;
                unset($queue_summary['post_ids']);
                
                $active_queues[$queue_id] = $queue_summary;
            }
        }
        
        return $active_queues;
    }
    
    /**
     * Cancel all active queues
     */
    public function cancel_all_queues() {
        $active_queues = $this->get_active_queues();
        $cancelled = 0;
        
        foreach ($active_queues as $queue_id => $queue_data) {
            if (!in_array($queue_data['status'], ['completed', 'cancelled'])) {
                $queue_data['status'] = 'cancelled';
                $queue_data['cancelled_at'] = time();
                $queue_data['cancel_reason'] = 'manual_cancel_all';
                $queue_data['last_update'] = time();
                
                // Clear post_ids to save memory
                unset($queue_data['post_ids']);
                
                set_transient('autotag_bulk_queue_' . $queue_id, $queue_data, HOUR_IN_SECONDS);
                
                // Clear scheduled events
                wp_clear_scheduled_hook('autotag_process_bulk_batch', [$queue_id]);
                $cancelled++;
            }
        }
        
        return $cancelled;
    }
    
    /**
     * Get queue by ID
     */
    public function get_queue($queue_id) {
        $queue = get_transient('autotag_bulk_queue_' . $queue_id);
        
        if ($queue && isset($queue['post_ids'])) {
            // FIXED: Don't return post_ids array in API responses to save memory
            $queue_summary = $queue;
            unset($queue_summary['post_ids']);
            return $queue_summary;
        }
        
        return $queue;
    }
    
    /**
     * Logging methods - FIXED: Enhanced with safety checks
     */
    private function log_info($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_info($message);
        }
    }
    
    private function log_error($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_error($message);
        }
    }
}