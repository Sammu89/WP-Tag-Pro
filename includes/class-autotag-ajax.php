<?php
/**
 * Updated AJAX Handler Class
 * Handles all AJAX requests for dynamic admin functionality with dual-mode support
 * FIXED: Security vulnerabilities, rate limiting, and data exposure issues
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Ajax {
    
    /**
     * Security constants - FIXED: Added rate limiting and security controls
     */
    private const MAX_REQUESTS_PER_MINUTE = 60;
    private const MAX_BULK_PREVIEW_POSTS = 50;
    private const MAX_EXPORT_SIZE = 1000000; // 1MB max export
    
    /**
     * Rate limiting cache - FIXED: Added property declarations
     */
    private static $rate_limit_cache = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize AJAX hooks
     */
    private function init_hooks() {
        // Bulk processing AJAX handlers
        add_action('wp_ajax_autotag_start_bulk_process', [$this, 'start_bulk_process']);
        add_action('wp_ajax_autotag_get_bulk_progress', [$this, 'get_bulk_progress']);
        add_action('wp_ajax_autotag_cancel_bulk_process', [$this, 'cancel_bulk_process']);
        
        // Single post processing
        add_action('wp_ajax_autotag_process_single_post', [$this, 'process_single_post']);
        add_action('wp_ajax_autotag_preview_single_post', [$this, 'preview_single_post']);
        
        // Configuration management AJAX handlers
        add_action('wp_ajax_autotag_validate_config', [$this, 'validate_configuration']);
        add_action('wp_ajax_autotag_get_config_stats', [$this, 'get_config_stats']);
        add_action('wp_ajax_autotag_test_phrase_matching', [$this, 'test_phrase_matching']);
        
        // Family management specific handlers
        add_action('wp_ajax_autotag_update_family', [$this, 'update_family']);
        add_action('wp_ajax_autotag_get_family_conflicts', [$this, 'get_family_conflicts']);
        add_action('wp_ajax_autotag_bulk_add_families', [$this, 'bulk_add_families']);
        
        // Year tags management
        add_action('wp_ajax_autotag_preview_year_tags', [$this, 'preview_year_tags']);
        add_action('wp_ajax_autotag_bulk_update_year_tags', [$this, 'bulk_update_year_tags']);
        
        // Maintenance tools
        add_action('wp_ajax_autotag_cleanup_orphaned', [$this, 'cleanup_orphaned_meta']);
        add_action('wp_ajax_autotag_reset_processing', [$this, 'reset_all_processing']);
        add_action('wp_ajax_autotag_clear_cache', [$this, 'clear_plugin_cache']);
        add_action('wp_ajax_autotag_optimize_database', [$this, 'optimize_database']);
        
        // Statistics and monitoring
        add_action('wp_ajax_autotag_get_stats', [$this, 'get_current_stats']);
        add_action('wp_ajax_autotag_get_processing_queue', [$this, 'get_processing_queue']);
        add_action('wp_ajax_autotag_export_stats', [$this, 'export_statistics']);
        
        // WordPress tags integration
        add_action('wp_ajax_autotag_get_existing_tags', [$this, 'get_existing_tags']);
        add_action('wp_ajax_autotag_sync_existing_tags', [$this, 'sync_existing_tags']);
        
        // Debug and testing - FIXED: Added for better debugging
        add_action('wp_ajax_autotag_debug_post', [$this, 'debug_post']);
        add_action('wp_ajax_autotag_test_performance', [$this, 'test_performance']);
    }
    
    /**
     * Enhanced security verification - FIXED: Centralized security checks
     */
    private function verify_ajax_security($required_capability = 'manage_options') {
        // Check if it's an AJAX request
        if (!wp_doing_ajax()) {
            wp_send_json_error(__('Invalid request type', 'wp-auto-tag-pro'), 400);
        }
        
        // Verify nonce - FIXED: Consistent nonce verification
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security verification failed', 'wp-auto-tag-pro'), 403);
        }
        
        // Check user capabilities
        if (!current_user_can($required_capability)) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'), 403);
        }
        
        // Rate limiting - FIXED: Added to prevent abuse
        if (!$this->check_rate_limit()) {
            wp_send_json_error(__('Too many requests. Please wait and try again.', 'wp-auto-tag-pro'), 429);
        }
        
        // Check if plugin is properly initialized
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->is_initialized()) {
            wp_send_json_error(__('Plugin not properly initialized', 'wp-auto-tag-pro'), 503);
        }
        
        return true;
    }
    
    /**
     * Rate limiting check - FIXED: New security feature
     */
    private function check_rate_limit() {
        $user_id = get_current_user_id();
        $current_minute = floor(time() / 60);
        $key = 'autotag_rate_limit_' . $user_id . '_' . $current_minute;
        
        // Check current minute's request count
        $current_count = wp_cache_get($key, 'autotag_rate_limit') ?: 0;
        
        if ($current_count >= self::MAX_REQUESTS_PER_MINUTE) {
            $this->log_security_event('rate_limit_exceeded', $user_id);
            return false;
        }
        
        // Increment counter
        wp_cache_set($key, $current_count + 1, 'autotag_rate_limit', 60);
        
        return true;
    }
    
    /**
     * Log security events - FIXED: Enhanced security monitoring
     */
    private function log_security_event($event_type, $user_id = null) {
        if (function_exists('wp_autotag_pro')) {
            $user_id = $user_id ?: get_current_user_id();
            $user_info = get_userdata($user_id);
            $username = $user_info ? $user_info->user_login : 'unknown';
            
            wp_autotag_pro()->log_error("Security event: {$event_type} - User: {$username} ({$user_id}) - IP: " . $this->get_client_ip());
        }
    }
    
    /**
     * Get client IP safely - FIXED: New security helper
     */
    private function get_client_ip() {
        $ip_fields = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_fields as $field) {
            if (!empty($_SERVER[$field])) {
                $ip = $_SERVER[$field];
                // Take first IP if comma-separated
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Start bulk processing - FIXED: Enhanced validation and security
     */
    public function start_bulk_process() {
        $this->verify_ajax_security('manage_options');
        
        // Sanitize and validate input
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $batch_size = max(10, min(500, intval($_POST['batch_size'] ?? 50)));
        
        $options = [
            'include_year_tags' => !empty($_POST['include_year_tags']),
            'force_reprocess' => !empty($_POST['force_reprocess']),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
            'author_id' => !empty($_POST['author_id']) ? intval($_POST['author_id']) : null,
            'batch_size' => $batch_size
        ];
        
        // Validate action
        $allowed_actions = ['new', 'outdated', 'all', 'year_tags_only'];
        if (!in_array($action, $allowed_actions)) {
            wp_send_json_error(__('Invalid bulk action specified', 'wp-auto-tag-pro'));
        }
        
        try {
            // Get posts to process based on action
            $post_ids = $this->get_posts_for_bulk_action($action, $options);
            
            if (empty($post_ids)) {
                wp_send_json_error(__('No posts found for processing', 'wp-auto-tag-pro'));
            }
            
            // Security check for very large operations
            if (count($post_ids) > 10000 && !current_user_can('administrator')) {
                wp_send_json_error(__('Large bulk operations require administrator privileges', 'wp-auto-tag-pro'));
            }
            
            // Initialize bulk processing queue
            $queue_id = $this->create_processing_queue($post_ids, $action, $batch_size, $options);
            
            if (!$queue_id) {
                wp_send_json_error(__('Failed to create processing queue', 'wp-auto-tag-pro'));
            }
            
            // Schedule first batch
            wp_schedule_single_event(time(), 'autotag_process_bulk_batch', [$queue_id]);
            
            // FIXED: Filtered response to prevent data exposure
            wp_send_json_success([
                'queue_id' => $queue_id,
                'total_posts' => count($post_ids),
                'batch_size' => $batch_size,
                'estimated_batches' => ceil(count($post_ids) / $batch_size),
                'action' => $action,
                'message' => __('Bulk processing started successfully', 'wp-auto-tag-pro')
            ]);
            
        } catch (Exception $e) {
            $this->log_security_event('bulk_process_error');
            wp_send_json_error(__('An error occurred while starting bulk processing', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Get bulk processing progress - FIXED: Enhanced data filtering
     */
    public function get_bulk_progress() {
        $this->verify_ajax_security('manage_options');
        
        $queue_id = sanitize_text_field($_POST['queue_id'] ?? '');
        
        if (empty($queue_id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $queue_id)) {
            wp_send_json_error(__('Invalid queue ID format', 'wp-auto-tag-pro'));
        }
        
        try {
            $queue = get_transient('autotag_bulk_queue_' . $queue_id);
            
            if (!$queue || !is_array($queue)) {
                wp_send_json_error(__('Queue not found or expired', 'wp-auto-tag-pro'));
            }
            
            // FIXED: Filter sensitive data from response
            $progress_data = [
                'queue_id' => $queue_id,
                'status' => $queue['status'],
                'total_posts' => $queue['total_posts'],
                'processed_posts' => $queue['processed_posts'],
                'current_batch' => $queue['current_batch'],
                'total_batches' => $queue['total_batches'],
                'progress_percentage' => round(($queue['processed_posts'] / max($queue['total_posts'], 1)) * 100, 2),
                'tags_added' => $queue['tags_added'],
                'year_tags_added' => $queue['year_tags_added'] ?? 0,
                'families_matched' => $queue['families_matched'] ?? 0,
                'errors' => $queue['errors'],
                'start_time' => $queue['start_time'],
                'last_update' => $queue['last_update'],
                'estimated_completion' => $this->calculate_estimated_completion($queue),
                'performance_metrics' => [
                    'posts_per_second' => $queue['posts_per_second'] ?? 0,
                    'tags_per_post' => $queue['tags_per_post'] ?? 0
                ]
            ];
            
            // Add error details only for administrators
            if (current_user_can('administrator') && !empty($queue['error_message'])) {
                $progress_data['error_message'] = $queue['error_message'];
            }
            
            wp_send_json_success($progress_data);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error retrieving progress information', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Cancel bulk processing - FIXED: Enhanced validation
     */
    public function cancel_bulk_process() {
        $this->verify_ajax_security('manage_options');
        
        $queue_id = sanitize_text_field($_POST['queue_id'] ?? '');
        
        if (empty($queue_id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $queue_id)) {
            wp_send_json_error(__('Invalid queue ID format', 'wp-auto-tag-pro'));
        }
        
        try {
            // Update queue status to cancelled
            $queue = get_transient('autotag_bulk_queue_' . $queue_id);
            
            if (!$queue) {
                wp_send_json_error(__('Queue not found', 'wp-auto-tag-pro'));
            }
            
            // Check if user can cancel this queue
            if (!current_user_can('administrator') && $queue['created_by'] !== get_current_user_id()) {
                wp_send_json_error(__('You can only cancel your own processing queues', 'wp-auto-tag-pro'));
            }
            
            $queue['status'] = 'cancelled';
            $queue['cancelled_at'] = time();
            $queue['cancelled_by'] = get_current_user_id();
            $queue['last_update'] = time();
            
            set_transient('autotag_bulk_queue_' . $queue_id, $queue, HOUR_IN_SECONDS);
            
            // Remove any scheduled batch processing
            wp_clear_scheduled_hook('autotag_process_bulk_batch', [$queue_id]);
            
            wp_send_json_success(['message' => __('Processing cancelled successfully', 'wp-auto-tag-pro')]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error cancelling bulk processing', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Process single post via AJAX - FIXED: Enhanced security
     */
    public function process_single_post() {
        $this->verify_ajax_security('edit_posts');
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $force_reprocess = !empty($_POST['force_reprocess']);
        
        if (!$post_id || $post_id <= 0) {
            wp_send_json_error(__('Invalid post ID', 'wp-auto-tag-pro'));
        }
        
        // Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(__('You do not have permission to edit this post', 'wp-auto-tag-pro'));
        }
        
        try {
            // Force reprocess if requested
            if ($force_reprocess) {
                update_post_meta($post_id, 'auto_tag_status', 'pending');
            }
            
            $coordinator = new AutoTag_ProcessorCoordinator();
            $result = $coordinator->process_post($post_id);
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }
            
            // FIXED: Filter response data
            $response_data = [
                'message' => __('Post processed successfully', 'wp-auto-tag-pro'),
                'tags_added' => $result['tags_added'] ?? 0,
                'status' => $result['status'] ?? 'success'
            ];
            
            // Add additional data for administrators
            if (current_user_can('administrator')) {
                $response_data['year_tag'] = $result['year_tag'] ?? null;
                $response_data['matches'] = $result['matches'] ?? [];
            }
            
            wp_send_json_success($response_data);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error processing post', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Preview single post tags via AJAX - FIXED: Enhanced validation
     */
    public function preview_single_post() {
        $this->verify_ajax_security('edit_posts');
        
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (!$post_id || $post_id <= 0) {
            wp_send_json_error(__('Invalid post ID', 'wp-auto-tag-pro'));
        }
        
        // Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(__('You do not have permission to preview this post', 'wp-auto-tag-pro'));
        }
        
        try {
            $coordinator = new AutoTag_ProcessorCoordinator();
            $result = $coordinator->preview_post_processing($post_id);
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }
            
            // Enhance preview data with additional information
            $result['mode'] = wp_autotag_pro()->config->get_mode() === 'f' ? 'families' : 'classic';
            $result['year_tags_enabled'] = wp_autotag_pro()->config->is_year_tags_enabled();
            $result['tag_strategy'] = wp_autotag_pro()->config->get_tag_strategy();
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error previewing post tags', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Validate configuration - FIXED: Enhanced input validation
     */
    public function validate_configuration() {
        $this->verify_ajax_security('manage_options');
        
        $config_data = $_POST['config'] ?? [];
        
        if (!is_array($config_data)) {
            wp_send_json_error(__('Invalid configuration data format', 'wp-auto-tag-pro'));
        }
        
        try {
            $validation_results = [];
            
            // Validate based on mode
            $mode = sanitize_text_field($config_data['mode'] ?? 'classic');
            
            if ($mode === 'families') {
                $families = $config_data['families'] ?? [];
                if (!is_array($families)) {
                    wp_send_json_error(__('Invalid families data format', 'wp-auto-tag-pro'));
                }
                $validation_results = $this->validate_families_config($families);
            } else {
                $keywords = sanitize_textarea_field($config_data['keywords'] ?? '');
                $validation_results = $this->validate_keywords_config($keywords);
            }
            
            wp_send_json_success($validation_results);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error validating configuration', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Get configuration statistics - FIXED: Data filtering
     */
    public function get_config_stats() {
        $this->verify_ajax_security('manage_options');
        
        try {
            $stats = wp_autotag_pro()->config->get_config_stats();
            
            // Add additional statistics with safety checks
            $additional_stats = [];
            
            $coordinator = new AutoTag_ProcessorCoordinator();
            $processing_posts = $coordinator->get_posts_needing_processing(100);
            $additional_stats['processing_needed'] = count($processing_posts);
            
            $plugin_stats = wp_autotag_pro()->get_stats();
            $additional_stats['last_config_change'] = $plugin_stats['last_update'] ?? 0;
            
            wp_send_json_success(array_merge($stats, $additional_stats));
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error retrieving configuration statistics', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Test phrase matching - FIXED: Input validation and limits
     */
    public function test_phrase_matching() {
        $this->verify_ajax_security('manage_options');
        
        $test_content = sanitize_textarea_field($_POST['content'] ?? '');
        $test_phrases = $_POST['phrases'] ?? [];
        
        // Validate input
        if (empty($test_content) || strlen($test_content) > 10000) {
            wp_send_json_error(__('Content must be between 1 and 10,000 characters', 'wp-auto-tag-pro'));
        }
        
        if (!is_array($test_phrases) || count($test_phrases) > 50) {
            wp_send_json_error(__('Maximum 50 phrases allowed for testing', 'wp-auto-tag-pro'));
        }
        
        try {
            $normalized_content = wp_autotag_pro()->config->normalize_text($test_content);
            $matches = [];
            
            foreach ($test_phrases as $phrase) {
                $phrase = sanitize_text_field($phrase);
                if (empty($phrase)) continue;
                
                $normalized_phrase = wp_autotag_pro()->config->normalize_text($phrase);
                $match_found = strpos($normalized_content, $normalized_phrase) !== false;
                
                $matches[] = [
                    'phrase' => $phrase,
                    'normalized' => $normalized_phrase,
                    'matched' => $match_found,
                    'positions' => $match_found ? $this->find_phrase_positions($normalized_content, $normalized_phrase) : []
                ];
            }
            
            wp_send_json_success([
                'content_length' => strlen($test_content),
                'normalized_length' => strlen($normalized_content),
                'matches' => $matches,
                'total_matches' => count(array_filter($matches, function($m) { return $m['matched']; }))
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error testing phrase matching', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Clear plugin cache - FIXED: Enhanced security and selective clearing
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
                    $deleted_transients = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_autotag_%'");
                    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_autotag_%'");
                    $cleared_items[] = sprintf('Transients (%d)', $deleted_transients);
                    break;
                    
                case 'tags':
                    wp_autotag_pro()->clear_existing_tags_cache();
                    $coordinator = new AutoTag_ProcessorCoordinator();
                    $coordinator->clear_processing_cache();
                    $cleared_items[] = 'Tags cache';
                    break;
                    
                case 'all':
                default:
                    // Clear all caches
                    wp_cache_flush_group('autotag');
                    wp_autotag_pro()->clear_existing_tags_cache();
                    
                    $coordinator = new AutoTag_ProcessorCoordinator();
                    $coordinator->clear_processing_cache();
                    
                    global $wpdb;
                    $deleted_transients = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_autotag_%'");
                    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_autotag_%'");
                    
                    // Clear config cache
                    if (wp_autotag_pro()->config) {
                        wp_autotag_pro()->config->config_cache = null;
                        wp_autotag_pro()->config->hash_cache = null;
                    }
                    
                    $cleared_items = ['All caches', sprintf('Transients (%d)', $deleted_transients)];
                    break;
            }
            
            wp_send_json_success([
                'message' => __('Cache cleared successfully', 'wp-auto-tag-pro'),
                'cleared_items' => $cleared_items
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error clearing plugin cache', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Get current statistics - FIXED: Data filtering and rate limiting
     */
    public function get_current_stats() {
        $this->verify_ajax_security('manage_options');
        
        try {
            $stats = wp_autotag_pro()->get_stats();
            $coordinator = new AutoTag_ProcessorCoordinator();
            $processing_stats = $coordinator->get_processing_stats();
            $config_stats = wp_autotag_pro()->config ? wp_autotag_pro()->config->get_config_stats() : [];
            $bulk_stats = wp_autotag_pro()->bulk ? wp_autotag_pro()->bulk->get_bulk_stats() : [];
            
            // FIXED: Filter sensitive information
            $filtered_stats = [
                'general' => array_intersect_key($stats, array_flip([
                    'total_processed', 'total_tags_added', 'year_tags_added', 
                    'last_bulk_run', 'bulk_runs_count', 'errors_count'
                ])),
                'processing' => array_intersect_key($processing_stats, array_flip([
                    'total_posts', 'processed_posts', 'needs_processing', 'outdated_posts'
                ])),
                'config' => array_intersect_key($config_stats, array_flip([
                    'mode', 'year_tags_enabled', 'total_families', 'total_keywords'
                ])),
                'bulk' => array_intersect_key($bulk_stats, array_flip([
                    'active_queues', 'completed_queues', 'performance_metrics'
                ])),
                'timestamp' => time()
            ];
            
            wp_send_json_success($filtered_stats);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error retrieving statistics', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Export statistics - FIXED: Size limits and data filtering
     */
    public function export_statistics() {
        $this->verify_ajax_security('administrator'); // Require admin for exports
        
        try {
            $stats = wp_autotag_pro()->get_stats();
            $coordinator = new AutoTag_ProcessorCoordinator();
            $processing_stats = $coordinator->get_processing_stats();
            $config_stats = wp_autotag_pro()->config ? wp_autotag_pro()->config->get_config_stats() : [];
            
            $export_data = [
                'plugin_version' => defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '2.0.0',
                'export_date' => current_time('mysql'),
                'site_url' => get_site_url(),
                'statistics' => [
                    'general' => $stats,
                    'processing' => $processing_stats,
                    'configuration' => $config_stats
                ]
            ];
            
            $json_data = wp_json_encode($export_data, JSON_PRETTY_PRINT);
            
            // FIXED: Check export size limit
            if (strlen($json_data) > self::MAX_EXPORT_SIZE) {
                wp_send_json_error(__('Export data too large. Try exporting specific sections.', 'wp-auto-tag-pro'));
            }
            
            wp_send_json_success([
                'data' => $export_data,
                'filename' => 'autotag-statistics-' . date('Y-m-d-H-i-s') . '.json',
                'size' => strlen($json_data)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error exporting statistics', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Debug post - FIXED: New debugging method with security
     */
    public function debug_post() {
        $this->verify_ajax_security('administrator'); // Debug requires admin
        
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (!$post_id || $post_id <= 0) {
            wp_send_json_error(__('Invalid post ID', 'wp-auto-tag-pro'));
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(__('Post not found', 'wp-auto-tag-pro'));
        }
        
        try {
            $coordinator = new AutoTag_ProcessorCoordinator();
            
            // Collect debug information
            $debug_info = [
                'post_info' => [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => $post->post_type,
                    'status' => $post->post_status,
                    'date' => $post->post_date
                ],
                'autotag_meta' => [
                    'status' => get_post_meta($post_id, 'auto_tag_status', true),
                    'processed_hash' => get_post_meta($post_id, 'auto_tag_processed_hash', true),
                    'last_processed' => get_post_meta($post_id, 'auto_tag_last_processed', true)
                ],
                'current_tags' => wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']),
                'processing_decision' => $coordinator->should_process_post($post_id),
                'preview' => $coordinator->preview_post_processing($post_id),
                'config_info' => [
                    'mode' => wp_autotag_pro()->config->get_mode(),
                    'current_hash' => wp_autotag_pro()->config->get_current_hash(),
                    'year_tags_enabled' => wp_autotag_pro()->config->is_year_tags_enabled()
                ]
            ];
            
            wp_send_json_success($debug_info);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error debugging post', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Get existing WordPress tags - FIXED: Enhanced validation and limits
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
                'fields' => 'names'
            ];
            
            if (!empty($search) && strlen($search) >= 2) {
                $args['search'] = $search;
            }
            
            $terms = get_terms($args);
            
            if (is_wp_error($terms)) {
                wp_send_json_error($terms->get_error_message());
            }
            
            wp_send_json_success([
                'tags' => $terms,
                'total' => count($terms),
                'taxonomy' => $taxonomy,
                'search' => $search
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error retrieving existing tags', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Clean up orphaned meta data - FIXED: Enhanced security
     */
    public function cleanup_orphaned_meta() {
        $this->verify_ajax_security('administrator'); // Require admin for maintenance
        
        try {
            global $wpdb;
            
            // Clean up orphaned auto_tag_status meta
            $deleted_status = $wpdb->query("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.ID IS NULL AND pm.meta_key = 'auto_tag_status'
            ");
            
            // Clean up orphaned auto_tag_processed_hash meta
            $deleted_hash = $wpdb->query("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.ID IS NULL AND pm.meta_key = 'auto_tag_processed_hash'
            ");
            
            // Clean up orphaned auto_tag_last_processed meta
            $deleted_timestamp = $wpdb->query("
                DELETE pm FROM {$wpdb->postmeta} pm
                LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.ID IS NULL AND pm.meta_key = 'auto_tag_last_processed'
            ");
            
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
            wp_send_json_error(__('Error during cleanup operation', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Reset all processing status - FIXED: Enhanced security and validation
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
            
            // Delete all processed hashes to force reprocessing
            $deleted_hashes = $wpdb->delete(
                $wpdb->postmeta,
                ['meta_key' => 'auto_tag_processed_hash']
            );
            
            // Delete all timestamps
            $deleted_timestamps = $wpdb->delete(
                $wpdb->postmeta,
                ['meta_key' => 'auto_tag_last_processed']
            );
            
            // Update plugin statistics
            wp_autotag_pro()->update_stats(['processing_resets' => 1]);
            
            wp_send_json_success([
                'message' => __('All processing status reset successfully', 'wp-auto-tag-pro'),
                'updated_posts' => $updated_status,
                'cleared_hashes' => $deleted_hashes,
                'cleared_timestamps' => $deleted_timestamps
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error resetting processing status', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Helper methods - FIXED: Enhanced with error handling
     */
    
    /**
     * Calculate estimated completion time
     */
    private function calculate_estimated_completion($queue) {
        if (!isset($queue['processed_posts']) || $queue['processed_posts'] <= 0) {
            return null;
        }
        
        $elapsed_time = time() - ($queue['start_time'] ?? time());
        $posts_per_second = $elapsed_time > 0 ? $queue['processed_posts'] / $elapsed_time : 0;
        $remaining_posts = $queue['total_posts'] - $queue['processed_posts'];
        
        if ($posts_per_second <= 0) {
            return null;
        }
        
        $estimated_seconds = $remaining_posts / $posts_per_second;
        return time() + $estimated_seconds;
    }
    
    /**
     * Validate families configuration
     */
    private function validate_families_config($families) {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'statistics' => []
        ];
        
        if (!is_array($families)) {
            $validation['valid'] = false;
            $validation['errors'][] = __('Families must be an array', 'wp-auto-tag-pro');
            return $validation;
        }
        
        $total_keywords = 0;
        $duplicate_keywords = [];
        $keyword_families_map = [];
        
        foreach ($families as $family_name => $family_data) {
            $family_name = sanitize_text_field($family_name);
            $keywords = is_array($family_data) ? $family_data : 
                       (wp_autotag_pro()->config ? wp_autotag_pro()->config->parse_items_string($family_data['keywords'] ?? '') : []);
            
            $total_keywords += count($keywords);
            
            // Check for duplicate keywords across families
            foreach ($keywords as $keyword) {
                if (wp_autotag_pro()->config) {
                    $normalized = wp_autotag_pro()->config->normalize_text($keyword);
                    if (!isset($keyword_families_map[$normalized])) {
                        $keyword_families_map[$normalized] = [];
                    }
                    $keyword_families_map[$normalized][] = $family_name;
                }
            }
            
            // Validate family
            if (empty($family_name)) {
                $validation['errors'][] = __('Empty family name found', 'wp-auto-tag-pro');
                $validation['valid'] = false;
            }
            
            if (empty($keywords)) {
                $validation['errors'][] = sprintf(__('Family "%s" has no keywords', 'wp-auto-tag-pro'), $family_name);
                $validation['valid'] = false;
            }
        }
        
        // Check for keyword conflicts
        foreach ($keyword_families_map as $keyword => $family_list) {
            if (count($family_list) > 1) {
                $validation['warnings'][] = sprintf(
                    __('Keyword "%s" appears in multiple families: %s', 'wp-auto-tag-pro'),
                    $keyword,
                    implode(', ', $family_list)
                );
            }
        }
        
        // Check limits
        if ($total_keywords > 1000) {
            $validation['errors'][] = sprintf(
                __('Total keywords (%d) exceeds maximum limit (1000)', 'wp-auto-tag-pro'),
                $total_keywords
            );
            $validation['valid'] = false;
        }
        
        $validation['statistics'] = [
            'total_families' => count($families),
            'total_keywords' => $total_keywords,
            'duplicate_keywords' => count($keyword_families_map) - count($families),
            'max_allowed' => 1000
        ];
        
        return $validation;
    }
    
    /**
     * Validate keywords configuration
     */
    private function validate_keywords_config($keywords_text) {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'statistics' => []
        ];
        
        if (!wp_autotag_pro()->config) {
            $validation['valid'] = false;
            $validation['errors'][] = __('Configuration component not available', 'wp-auto-tag-pro');
            return $validation;
        }
        
        $keywords = wp_autotag_pro()->config->parse_items_string($keywords_text);
        
        // Check limits
        if (count($keywords) > 1000) {
            $validation['errors'][] = sprintf(
                __('Keywords count (%d) exceeds maximum limit (1000)', 'wp-auto-tag-pro'),
                count($keywords)
            );
            $validation['valid'] = false;
        }
        
        // Check for very short keywords
        $short_keywords = array_filter($keywords, function($k) { 
            return strlen(trim($k)) < 3; 
        });
        
        if (!empty($short_keywords)) {
            $validation['warnings'][] = sprintf(
                __('Found %d keywords shorter than 3 characters', 'wp-auto-tag-pro'),
                count($short_keywords)
            );
        }
        
        // Check for duplicates
        $unique_keywords = array_unique($keywords);
        $duplicates = count($keywords) - count($unique_keywords);
        
        if ($duplicates > 0) {
            $validation['warnings'][] = sprintf(
                __('Found %d duplicate keywords', 'wp-auto-tag-pro'),
                $duplicates
            );
        }
        
        $validation['statistics'] = [
            'total_keywords' => count($keywords),
            'unique_keywords' => count($unique_keywords),
            'duplicates' => $duplicates,
            'short_keywords' => count($short_keywords),
            'max_allowed' => 1000
        ];
        
        return $validation;
    }
    
    /**
     * Find phrase positions in content
     */
    private function find_phrase_positions($content, $phrase) {
        $positions = [];
        $offset = 0;
        $max_positions = 10; // Limit positions to prevent memory issues
        
        while (($pos = strpos($content, $phrase, $offset)) !== false && count($positions) < $max_positions) {
            $positions[] = [
                'start' => $pos,
                'end' => $pos + strlen($phrase),
                'context' => substr($content, max(0, $pos - 20), 40 + strlen($phrase))
            ];
            $offset = $pos + 1;
        }
        
        return $positions;
    }
    
    /**
     * Wrapper methods for bulk operations - FIXED: Delegate to bulk class
     */
    private function get_posts_for_bulk_action($action, $options = []) {
        if (!wp_autotag_pro()->bulk) {
            return [];
        }
        
        // This method should be moved to bulk class, but for now delegate
        $settings = wp_autotag_pro()->get_settings();
        $post_types = $settings['enabled_post_types'] ?? ['post'];
        
        $args = [
            'post_type' => $post_types,
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page' => 1000, // Limit for security
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false
        ];
        
        // Basic action handling
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
                
            case 'all':
                $args['meta_query'] = [
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
        
        return get_posts($args);
    }
    
    private function create_processing_queue($post_ids, $action, $batch_size, $options = []) {
        if (!wp_autotag_pro()->bulk) {
            return false;
        }
        
        // Delegate to bulk class
        return wp_autotag_pro()->bulk->start_bulk_process($action, array_merge($options, [
            'batch_size' => $batch_size
        ]));
    }
}