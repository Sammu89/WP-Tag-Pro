<?php
/**
 * Rollback Manager Class - v2.1.0
 * Handles rollback operations for bulk processing and individual post changes
 * Provides granular control over undoing tag/category applications
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Rollback_Manager {
    
    /**
     * Maximum rollback records to keep
     */
    const MAX_ROLLBACK_HISTORY = 100;
    
    /**
     * Days to keep rollback data
     */
    const ROLLBACK_RETENTION_DAYS = 30;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Rollback processing hooks
        add_action('autotag_process_rollback', [$this, 'process_rollback']);
        add_action('autotag_cleanup_rollback_data', [$this, 'cleanup_old_rollback_data']);
        
        // Admin AJAX hooks for rollback operations
        add_action('wp_ajax_autotag_rollback_post', [$this, 'ajax_rollback_post']);
        add_action('wp_ajax_autotag_rollback_bulk', [$this, 'ajax_rollback_bulk']);
        add_action('wp_ajax_autotag_get_rollback_history', [$this, 'ajax_get_rollback_history']);
    }
    
    /**
     * Store rollback data when processing posts
     * 
     * @param int $post_id Post ID
     * @param array $previous_tags Previous tags before processing
     * @param array $previous_categories Previous categories before processing
     * @param string $operation_type Type of operation (bulk, individual, etc.)
     * @param string $operation_id Unique operation identifier
     */
    public function store_rollback_data($post_id, $previous_tags, $previous_categories, $operation_type = 'individual', $operation_id = null) {
        $rollback_id = $operation_id ?: ('individual_' . time() . '_' . $post_id);
        
        $rollback_data = [
            'rollback_id' => $rollback_id,
            'post_id' => $post_id,
            'operation_type' => $operation_type,
            'previous_tags' => $previous_tags,
            'previous_categories' => $previous_categories,
            'post_title' => get_the_title($post_id),
            'created_at' => current_time('timestamp'),
            'user_id' => get_current_user_id()
        ];
        
        // Store in database
        $this->save_rollback_record($rollback_data);
        
        return $rollback_id;
    }
    
    /**
     * Roll back a single post to its previous state
     * 
     * @param int $post_id Post ID to rollback
     * @param string $rollback_id Specific rollback ID (optional)
     * @return array Result of rollback operation
     */
    public function rollback_post($post_id, $rollback_id = null) {
        // Get rollback data
        $rollback_data = $this->get_rollback_data($post_id, $rollback_id);
        
        if (!$rollback_data) {
            return [
                'success' => false,
                'message' => 'No rollback data found for this post'
            ];
        }
        
        try {
            // Restore previous tags
            $tags_result = wp_set_post_terms($post_id, $rollback_data['previous_tags'], 'post_tag');
            
            // Restore previous categories
            $categories_result = true;
            if (!empty($rollback_data['previous_categories'])) {
                // Convert category names to IDs
                $category_ids = [];
                foreach ($rollback_data['previous_categories'] as $cat_name) {
                    $cat = get_category_by_slug(sanitize_title($cat_name));
                    if ($cat) {
                        $category_ids[] = $cat->term_id;
                    }
                }
                if (!empty($category_ids)) {
                    $categories_result = wp_set_post_categories($post_id, $category_ids);
                }
            } else {
                // Clear categories if there were none before
                wp_set_post_categories($post_id, []);
            }
            
            if (is_wp_error($tags_result) || is_wp_error($categories_result)) {
                return [
                    'success' => false,
                    'message' => 'WordPress error during rollback: ' . 
                               (is_wp_error($tags_result) ? $tags_result->get_error_message() : 
                                (is_wp_error($categories_result) ? $categories_result->get_error_message() : 'Unknown error'))
                ];
            }
            
            // Log successful rollback
            $this->log_rollback_action($post_id, $rollback_data['rollback_id'], 'success');
            
            return [
                'success' => true,
                'message' => sprintf('Successfully rolled back post "%s" to previous state', get_the_title($post_id)),
                'post_id' => $post_id,
                'rollback_id' => $rollback_data['rollback_id']
            ];
            
        } catch (Exception $e) {
            $this->log_rollback_action($post_id, $rollback_data['rollback_id'], 'error', $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Exception during rollback: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Roll back multiple posts from a bulk operation
     * 
     * @param string $operation_id Bulk operation ID
     * @return array Result of bulk rollback
     */
    public function rollback_bulk_operation($operation_id) {
        // Get all rollback records for this operation
        $rollback_records = $this->get_bulk_rollback_data($operation_id);
        
        if (empty($rollback_records)) {
            return [
                'success' => false,
                'message' => 'No rollback data found for operation ID: ' . $operation_id
            ];
        }
        
        $results = [
            'success' => true,
            'total_posts' => count($rollback_records),
            'successful_rollbacks' => 0,
            'failed_rollbacks' => 0,
            'errors' => []
        ];
        
        foreach ($rollback_records as $record) {
            $rollback_result = $this->rollback_post($record['post_id'], $record['rollback_id']);
            
            if ($rollback_result['success']) {
                $results['successful_rollbacks']++;
            } else {
                $results['failed_rollbacks']++;
                $results['errors'][] = [
                    'post_id' => $record['post_id'],
                    'error' => $rollback_result['message']
                ];
            }
        }
        
        $results['message'] = sprintf(
            'Bulk rollback completed: %d successful, %d failed out of %d total posts',
            $results['successful_rollbacks'],
            $results['failed_rollbacks'],
            $results['total_posts']
        );
        
        return $results;
    }
    
    /**
     * Get rollback history for admin display
     * 
     * @param array $args Query arguments
     * @return array Rollback history records
     */
    public function get_rollback_history($args = []) {
        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'operation_type' => null,
            'post_id' => null,
            'date_from' => null,
            'date_to' => null
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        return $this->query_rollback_records($args);
    }
    
    /**
     * AJAX handler for single post rollback
     */
    public function ajax_rollback_post() {
        $this->verify_ajax_security('edit_posts');
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $rollback_id = sanitize_text_field($_POST['rollback_id'] ?? '');
        
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Insufficient permissions to rollback this post');
        }
        
        $result = $this->rollback_post($post_id, $rollback_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for bulk operation rollback
     */
    public function ajax_rollback_bulk() {
        $this->verify_ajax_security('manage_options');
        
        $operation_id = sanitize_text_field($_POST['operation_id'] ?? '');
        
        if (empty($operation_id)) {
            wp_send_json_error('Invalid operation ID');
        }
        
        // Start background rollback process
        wp_schedule_single_event(time(), 'autotag_process_rollback', [$operation_id]);
        
        wp_send_json_success([
            'message' => 'Bulk rollback process started',
            'operation_id' => $operation_id
        ]);
    }
    
    /**
     * AJAX handler for getting rollback history
     */
    public function ajax_get_rollback_history() {
        $this->verify_ajax_security('edit_posts');
        
        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = max(10, min(100, intval($_POST['per_page'] ?? 20)));
        
        $args = [
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
            'operation_type' => sanitize_text_field($_POST['operation_type'] ?? ''),
            'post_id' => intval($_POST['post_id'] ?? 0)
        ];
        
        $history = $this->get_rollback_history($args);
        $total = $this->count_rollback_records($args);
        
        wp_send_json_success([
            'history' => $history,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ]);
    }
    
    /**
     * Process background rollback
     * 
     * @param string $operation_id Operation ID to rollback
     */
    public function process_rollback($operation_id) {
        $result = $this->rollback_bulk_operation($operation_id);
        
        // Store rollback result for admin notification
        set_transient('autotag_rollback_result_' . $operation_id, $result, HOUR_IN_SECONDS);
    }
    
    /**
     * Clean up old rollback data
     */
    public function cleanup_old_rollback_data() {
        $cutoff_time = time() - (self::ROLLBACK_RETENTION_DAYS * DAY_IN_SECONDS);
        $this->delete_old_rollback_records($cutoff_time);
    }
    
    // Private helper methods
    
    /**
     * Verify AJAX security
     */
    private function verify_ajax_security($capability = 'manage_options') {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'autotag_rollback_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can($capability)) {
            wp_die('Insufficient permissions');
        }
    }
    
    /**
     * Save rollback record to database
     */
    private function save_rollback_record($data) {
        // Implementation would use WordPress database functions
        // For now, we'll use transients as a temporary storage
        $key = 'autotag_rollback_' . $data['rollback_id'];
        set_transient($key, $data, self::ROLLBACK_RETENTION_DAYS * DAY_IN_SECONDS);
    }
    
    /**
     * Get rollback data for a post
     */
    private function get_rollback_data($post_id, $rollback_id = null) {
        if ($rollback_id) {
            return get_transient('autotag_rollback_' . $rollback_id);
        }
        
        // Find most recent rollback for this post
        // This is a simplified implementation
        return null;
    }
    
    /**
     * Get bulk rollback data
     */
    private function get_bulk_rollback_data($operation_id) {
        // Implementation would query database for all records with this operation_id
        return [];
    }
    
    /**
     * Query rollback records
     */
    private function query_rollback_records($args) {
        // Implementation would query database
        return [];
    }
    
    /**
     * Count rollback records
     */
    private function count_rollback_records($args) {
        // Implementation would count database records
        return 0;
    }
    
    /**
     * Delete old rollback records
     */
    private function delete_old_rollback_records($cutoff_time) {
        // Implementation would delete old database records
    }
    
    /**
     * Log rollback action
     */
    private function log_rollback_action($post_id, $rollback_id, $status, $message = '') {
        if (function_exists('wp_autotag_pro') && method_exists(wp_autotag_pro(), 'log_info')) {
            wp_autotag_pro()->log_info('[ROLLBACK] ' . ucfirst($status), [
                'post_id' => $post_id,
                'rollback_id' => $rollback_id,
                'message' => $message
            ]);
        }
    }
}