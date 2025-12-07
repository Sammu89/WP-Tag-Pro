<?php
/**
 * Bulk Processing AJAX Handler Class
 * Handles bulk processing AJAX requests - extracted from AutoTag_Ajax
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Bulk_Ajax {
    
    public function __construct() {
        // Bulk processing AJAX hooks
        add_action('wp_ajax_autotag_start_bulk_process', [$this, 'start_bulk_process']);
        add_action('wp_ajax_autotag_get_bulk_progress', [$this, 'get_bulk_progress']);
        add_action('wp_ajax_autotag_cancel_bulk_process', [$this, 'cancel_bulk_process']);
        
        // Year tags bulk processing
        add_action('wp_ajax_autotag_bulk_update_year_tags', [$this, 'bulk_update_year_tags']);
        
        // Queue management
        add_action('wp_ajax_autotag_get_processing_queue', [$this, 'get_processing_queue']);
    }
    
    /**
     * Start bulk processing operation
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
        $valid_actions = [
            'process_new_posts',
            'process_outdated_posts', 
            'process_all_posts',
            'process_year_tags_only'
        ];
        
        if (!in_array($action, $valid_actions)) {
            wp_send_json_error(__('Invalid bulk action specified', 'wp-auto-tag-pro'));
        }
        
        try {
            // Create unique queue ID
            $queue_id = 'bulk_' . time() . '_' . wp_rand(1000, 9999);
            
            // Get posts to process based on action
            $post_ids = $this->get_posts_for_bulk_action($action, $options);
            
            if (empty($post_ids)) {
                wp_send_json_error(__('No posts found matching the criteria', 'wp-auto-tag-pro'));
            }
            
            // Create queue data
            $queue_data = [
                'id' => $queue_id,
                'action' => $action,
                'total_posts' => count($post_ids),
                'processed_posts' => 0,
                'batch_size' => $batch_size,
                'status' => 'pending',
                'created_at' => time(),
                'updated_at' => time(),
                'post_ids' => $post_ids,
                'options' => $options,
                'errors' => [],
                'results' => []
            ];
            
            // Store queue (expires in 1 hour)
            $queue_stored = set_transient('autotag_bulk_queue_' . $queue_id, $queue_data, HOUR_IN_SECONDS);
            
            if (!$queue_stored) {
                wp_send_json_error(__('Failed to create processing queue', 'wp-auto-tag-pro'));
            }
            
            // Schedule first batch processing
            if (wp_autotag_pro()->bulk) {
                wp_schedule_single_event(time(), 'autotag_process_bulk_batch', [$queue_id]);
            }
            
            wp_send_json_success([
                'message' => sprintf(__('Bulk processing started for %d posts', 'wp-auto-tag-pro'), count($post_ids)),
                'queue_id' => $queue_id,
                'total_posts' => count($post_ids),
                'batch_size' => $batch_size,
                'action' => $action,
                'options' => $options
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error starting bulk process: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get bulk processing progress
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
            
            // Calculate progress percentage
            $progress_percentage = $queue['total_posts'] > 0 ? 
                round(($queue['processed_posts'] / $queue['total_posts']) * 100, 2) : 0;
            
            // Estimate time remaining
            $time_elapsed = time() - $queue['created_at'];
            $posts_per_second = $time_elapsed > 0 && $queue['processed_posts'] > 0 ? 
                $queue['processed_posts'] / $time_elapsed : 0;
            
            $estimated_remaining = 0;
            if ($posts_per_second > 0) {
                $posts_remaining = $queue['total_posts'] - $queue['processed_posts'];
                $estimated_remaining = round($posts_remaining / $posts_per_second);
            }
            
            wp_send_json_success([
                'queue_id' => $queue_id,
                'status' => $queue['status'],
                'total_posts' => $queue['total_posts'],
                'processed_posts' => $queue['processed_posts'],
                'progress_percentage' => $progress_percentage,
                'batch_size' => $queue['batch_size'],
                'errors_count' => count($queue['errors']),
                'created_at' => $queue['created_at'],
                'updated_at' => $queue['updated_at'],
                'time_elapsed' => $time_elapsed,
                'estimated_remaining' => $estimated_remaining,
                'recent_errors' => array_slice($queue['errors'], -5) // Last 5 errors
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error getting progress: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Cancel bulk processing operation
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
            
            $queue['status'] = 'cancelled';
            $queue['updated_at'] = time();
            
            $updated = set_transient('autotag_bulk_queue_' . $queue_id, $queue, HOUR_IN_SECONDS);
            
            if (!$updated) {
                wp_send_json_error(__('Failed to cancel queue', 'wp-auto-tag-pro'));
            }
            
            // Try to cancel scheduled events
            wp_clear_scheduled_hook('autotag_process_bulk_batch', [$queue_id]);
            
            wp_send_json_success([
                'message' => __('Bulk processing cancelled successfully', 'wp-auto-tag-pro'),
                'queue_id' => $queue_id,
                'processed_posts' => $queue['processed_posts'],
                'cancelled_at' => time()
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error cancelling bulk process: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Bulk update year tags only
     */
    public function bulk_update_year_tags() {
        $this->verify_ajax_security('manage_options');
        
        $batch_size = max(10, min(100, intval($_POST['batch_size'] ?? 25)));
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        
        try {
            // Get posts that need year tags
            $args = [
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $batch_size,
                'fields' => 'ids'
            ];
            
            if (!empty($date_from)) {
                $args['date_query']['after'] = $date_from;
            }
            if (!empty($date_to)) {
                $args['date_query']['before'] = $date_to;
            }
            
            $posts = get_posts($args);
            
            if (empty($posts)) {
                wp_send_json_error(__('No posts found for year tag update', 'wp-auto-tag-pro'));
            }
            
            $results = [];
            $success_count = 0;
            $error_count = 0;
            
            foreach ($posts as $post_id) {
                try {
                    // Use TagApplier if available
                    if (class_exists('AutoTag_TagApplier')) {
                        $tag_applier = new AutoTag_TagApplier();
                        $year_tag = $tag_applier->generate_year_tag($post_id);
                        
                        if ($year_tag) {
                            $existing_tags = $tag_applier->get_existing_tags($post_id);
                            
                            // Only add year tag if it doesn't exist
                            if (!in_array($year_tag, $existing_tags)) {
                                $result = $tag_applier->apply_tags_to_post($post_id, [], 'a', $year_tag);
                                
                                if ($result['success']) {
                                    $success_count++;
                                    $results[] = [
                                        'post_id' => $post_id,
                                        'year_tag' => $year_tag,
                                        'status' => 'success'
                                    ];
                                } else {
                                    $error_count++;
                                    $results[] = [
                                        'post_id' => $post_id,
                                        'year_tag' => $year_tag,
                                        'status' => 'error',
                                        'message' => $result['message']
                                    ];
                                }
                            } else {
                                $results[] = [
                                    'post_id' => $post_id,
                                    'year_tag' => $year_tag,
                                    'status' => 'skipped',
                                    'message' => 'Year tag already exists'
                                ];
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error_count++;
                    $results[] = [
                        'post_id' => $post_id,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }
            
            wp_send_json_success([
                'message' => sprintf(__('Year tags update completed: %d success, %d errors', 'wp-auto-tag-pro'), 
                                   $success_count, $error_count),
                'processed_posts' => count($posts),
                'success_count' => $success_count,
                'error_count' => $error_count,
                'results' => $results
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error updating year tags: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get current processing queue information
     */
    public function get_processing_queue() {
        $this->verify_ajax_security('manage_options');
        
        try {
            // Get all active queues
            global $wpdb;
            
            $transient_prefix = '_transient_autotag_bulk_queue_';
            $timeout_prefix = '_transient_timeout_autotag_bulk_queue_';
            
            // Find all queue transients
            $queue_options = $wpdb->get_results($wpdb->prepare("
                SELECT option_name, option_value 
                FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                AND option_name NOT LIKE %s
                ORDER BY option_id DESC
                LIMIT 10
            ", $transient_prefix . '%', $timeout_prefix . '%'));
            
            $active_queues = [];
            
            foreach ($queue_options as $option) {
                $queue_data = maybe_unserialize($option->option_value);
                if (is_array($queue_data) && isset($queue_data['id'])) {
                    // Check if queue is still valid (not expired)
                    $queue_id = str_replace($transient_prefix, '', $option->option_name);
                    $queue = get_transient('autotag_bulk_queue_' . $queue_id);
                    
                    if ($queue) {
                        $active_queues[] = [
                            'queue_id' => $queue_id,
                            'action' => $queue['action'],
                            'status' => $queue['status'],
                            'total_posts' => $queue['total_posts'],
                            'processed_posts' => $queue['processed_posts'],
                            'created_at' => $queue['created_at'],
                            'updated_at' => $queue['updated_at'],
                            'progress_percentage' => $queue['total_posts'] > 0 ? 
                                round(($queue['processed_posts'] / $queue['total_posts']) * 100, 2) : 0
                        ];
                    }
                }
            }
            
            wp_send_json_success([
                'active_queues' => $active_queues,
                'total_queues' => count($active_queues)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error getting processing queue: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get posts for bulk action
     */
    private function get_posts_for_bulk_action($action, $options) {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];
        
        // Date range filtering
        if (!empty($options['date_from']) || !empty($options['date_to'])) {
            $args['date_query'] = [];
            if (!empty($options['date_from'])) {
                $args['date_query']['after'] = $options['date_from'];
            }
            if (!empty($options['date_to'])) {
                $args['date_query']['before'] = $options['date_to'];
            }
        }
        
        // Author filtering
        if (!empty($options['author_id'])) {
            $args['author'] = $options['author_id'];
        }
        
        // Action-specific filtering
        switch ($action) {
            case 'process_new_posts':
                $args['meta_query'] = [
                    'relation' => 'OR',
                    [
                        'key' => 'auto_tag_status',
                        'compare' => 'NOT EXISTS'
                    ],
                    [
                        'key' => 'auto_tag_status',
                        'value' => 'pending',
                        'compare' => '='
                    ]
                ];
                break;
                
            case 'process_outdated_posts':
                $args['meta_query'] = [
                    [
                        'key' => 'auto_tag_status',
                        'value' => 'completed',
                        'compare' => '='
                    ]
                ];
                // Add logic to check if config hash has changed
                break;
                
            case 'process_all_posts':
                // No additional filtering, process all posts
                break;
                
            case 'process_year_tags_only':
                // Posts that might need year tags
                break;
        }
        
        return get_posts($args);
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