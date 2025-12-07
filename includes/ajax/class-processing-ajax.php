<?php
/**
 * Processing AJAX Handler Class
 * Handles single post processing AJAX requests - extracted from AutoTag_Ajax
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Processing_Ajax {
    
    public function __construct() {
        // Single post processing AJAX hooks
        add_action('wp_ajax_autotag_process_single_post', [$this, 'process_single_post']);
        add_action('wp_ajax_autotag_preview_single_post', [$this, 'preview_single_post']);
    }
    
    /**
     * Process a single post
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
                delete_post_meta($post_id, 'auto_tag_status');
                delete_post_meta($post_id, 'auto_tag_processed_hash');
                delete_post_meta($post_id, 'auto_tag_last_processed');
            }
            
            // Process the post using the coordinator
            $coordinator = new AutoTag_ProcessorCoordinator();
            $result = $coordinator->process_post($post_id, $force_reprocess);
            
            if ($result && isset($result['success']) && $result['success']) {
                wp_send_json_success([
                    'message' => __('Post processed successfully', 'wp-auto-tag-pro'),
                    'post_id' => $post_id,
                    'tags_applied' => $result['tags_applied'] ?? [],
                    'matches_found' => $result['matches_found'] ?? [],
                    'processing_stats' => $result['processing_stats'] ?? [],
                    'year_tag' => $result['year_tag'] ?? null,
                    'force_reprocessed' => $force_reprocess
                ]);
            } else {
                $error_message = $result['message'] ?? __('Processing failed for unknown reason', 'wp-auto-tag-pro');
                wp_send_json_error($error_message);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(__('Processing error: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Preview tags for a single post without applying them
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
            // Use the coordinator for preview
            $coordinator = new AutoTag_ProcessorCoordinator();
            $result = $coordinator->preview_post_processing($post_id);
            
            if ($result && isset($result['success']) && $result['success']) {
                wp_send_json_success([
                    'message' => __('Preview generated successfully', 'wp-auto-tag-pro'),
                    'post_id' => $post_id,
                    'preview' => $result['preview'] ?? [],
                    'matches_found' => $result['matches_found'] ?? [],
                    'content_stats' => $result['content_stats'] ?? [],
                    'mode' => $result['mode'] ?? 'unknown',
                    'strategy' => $result['strategy'] ?? 'unknown'
                ]);
            } else {
                $error_message = $result['message'] ?? __('Preview generation failed', 'wp-auto-tag-pro');
                wp_send_json_error($error_message);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(__('Preview error: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get processing status for a post
     */
    public function get_post_processing_status() {
        $this->verify_ajax_security('edit_posts');
        
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (!$post_id || $post_id <= 0) {
            wp_send_json_error(__('Invalid post ID', 'wp-auto-tag-pro'));
        }
        
        try {
            // Use coordinator to get stats
            $coordinator = new AutoTag_ProcessorCoordinator();
            $stats = $coordinator->get_processing_stats($post_id);
            
            wp_send_json_success([
                'post_id' => $post_id,
                'processing_stats' => $stats,
                'needs_processing' => empty($stats['status']) || $stats['status'] !== 'completed',
                'last_processed_date' => $stats['last_processed'] ? date('Y-m-d H:i:s', $stats['last_processed']) : null
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error getting processing status: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Reset processing status for a post
     */
    public function reset_post_processing() {
        $this->verify_ajax_security('edit_posts');
        
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (!$post_id || $post_id <= 0) {
            wp_send_json_error(__('Invalid post ID', 'wp-auto-tag-pro'));
        }
        
        // Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(__('You do not have permission to reset this post', 'wp-auto-tag-pro'));
        }
        
        try {
            // Clear processing metadata
            $deleted_status = delete_post_meta($post_id, 'auto_tag_status');
            $deleted_hash = delete_post_meta($post_id, 'auto_tag_processed_hash');
            $deleted_timestamp = delete_post_meta($post_id, 'auto_tag_last_processed');
            $deleted_stats = delete_post_meta($post_id, 'auto_tag_processing_stats');
            
            wp_send_json_success([
                'message' => __('Post processing status reset successfully', 'wp-auto-tag-pro'),
                'post_id' => $post_id,
                'metadata_cleared' => [
                    'status' => $deleted_status,
                    'hash' => $deleted_hash,
                    'timestamp' => $deleted_timestamp,
                    'stats' => $deleted_stats
                ]
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error resetting processing status: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Test processing on sample content
     */
    public function test_content_processing() {
        $this->verify_ajax_security('manage_options');
        
        $test_content = sanitize_textarea_field($_POST['content'] ?? '');
        $test_title = sanitize_text_field($_POST['title'] ?? '');
        
        if (empty($test_content) || strlen($test_content) > 10000) {
            wp_send_json_error(__('Content must be between 1 and 10,000 characters', 'wp-auto-tag-pro'));
        }
        
        try {
            // Create mock post object for testing
            $mock_post = (object) [
                'ID' => 0,
                'post_title' => $test_title,
                'post_content' => $test_content,
                'post_excerpt' => '',
                'post_date' => current_time('mysql'),
                'post_type' => 'post',
                'post_status' => 'publish'
            ];
            
            // Use text processor to analyze content
            if (class_exists('AutoTag_TextProcessor')) {
                $text_processor = new AutoTag_TextProcessor();
                $content_data = $text_processor->process_post_content($mock_post);
                
                // Use keyword matcher to find matches
                if (class_exists('AutoTag_KeywordMatcher')) {
                    $keyword_matcher = new AutoTag_KeywordMatcher();
                    $config = wp_autotag_pro()->config->get_config();
                    $mode = $config['mode'] ?? 'c';
                    
                    $matches = $keyword_matcher->find_all_matches($content_data['normalized'], $config, $mode);
                    $match_stats = $keyword_matcher->get_match_stats($content_data['normalized'], $config, $mode);
                    
                    wp_send_json_success([
                        'message' => __('Content processing test completed', 'wp-auto-tag-pro'),
                        'content_stats' => $content_data,
                        'matches_found' => $matches,
                        'match_stats' => $match_stats,
                        'mode' => $mode
                    ]);
                } else {
                    wp_send_json_error(__('KeywordMatcher class not available', 'wp-auto-tag-pro'));
                }
            } else {
                wp_send_json_error(__('TextProcessor class not available', 'wp-auto-tag-pro'));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(__('Content processing test error: ', 'wp-auto-tag-pro') . $e->getMessage());
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