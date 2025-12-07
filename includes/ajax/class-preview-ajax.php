<?php
/**
 * Preview/Testing AJAX Handler Class
 * Handles preview and testing AJAX requests - extracted from AutoTag_Ajax
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Preview_Ajax {
    
    public function __construct() {
        // Preview and testing AJAX hooks
        add_action('wp_ajax_autotag_preview_year_tags', [$this, 'preview_year_tags']);
        add_action('wp_ajax_autotag_debug_post', [$this, 'debug_post']);
        add_action('wp_ajax_autotag_test_performance', [$this, 'test_performance']);
    }
    
    /**
     * Preview year tags for posts
     */
    public function preview_year_tags() {
        $this->verify_ajax_security('manage_options');
        
        $limit = max(1, min(100, intval($_POST['limit'] ?? 20)));
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        
        try {
            $args = [
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'orderby' => 'date',
                'order' => 'DESC'
            ];
            
            if (!empty($date_from) || !empty($date_to)) {
                $args['date_query'] = [];
                if (!empty($date_from)) {
                    $args['date_query']['after'] = $date_from;
                }
                if (!empty($date_to)) {
                    $args['date_query']['before'] = $date_to;
                }
            }
            
            $posts = get_posts($args);
            
            if (empty($posts)) {
                wp_send_json_error(__('No posts found for preview', 'wp-auto-tag-pro'));
            }
            
            $previews = [];
            
            foreach ($posts as $post) {
                $year = date('Y', strtotime($post->post_date));
                $current_tags = wp_get_post_terms($post->ID, 'post_tag', ['fields' => 'names']);
                
                if (is_wp_error($current_tags)) {
                    $current_tags = [];
                }
                
                $has_year_tag = in_array($year, $current_tags);
                
                $previews[] = [
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'post_date' => $post->post_date,
                    'year' => $year,
                    'current_tags' => $current_tags,
                    'has_year_tag' => $has_year_tag,
                    'would_add_year_tag' => !$has_year_tag,
                    'preview_tags' => $has_year_tag ? $current_tags : array_merge([$year], $current_tags)
                ];
            }
            
            $stats = [
                'total_posts' => count($previews),
                'posts_with_year_tags' => count(array_filter($previews, function($p) { return $p['has_year_tag']; })),
                'posts_needing_year_tags' => count(array_filter($previews, function($p) { return !$p['has_year_tag']; }))
            ];
            
            wp_send_json_success([
                'message' => sprintf(__('Generated year tags preview for %d posts', 'wp-auto-tag-pro'), count($previews)),
                'previews' => $previews,
                'stats' => $stats,
                'date_range' => [
                    'from' => $date_from,
                    'to' => $date_to
                ]
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error generating year tags preview: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Debug post processing
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
            // Collect debug information
            $debug_info = [
                'post_info' => [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'status' => $post->post_status,
                    'type' => $post->post_type,
                    'date' => $post->post_date,
                    'content_length' => strlen($post->post_content),
                    'excerpt_length' => strlen($post->post_excerpt)
                ],
                'current_meta' => [
                    'auto_tag_status' => get_post_meta($post_id, 'auto_tag_status', true),
                    'auto_tag_processed_hash' => get_post_meta($post_id, 'auto_tag_processed_hash', true),
                    'auto_tag_last_processed' => get_post_meta($post_id, 'auto_tag_last_processed', true),
                    'auto_tag_processing_stats' => get_post_meta($post_id, 'auto_tag_processing_stats', true)
                ],
                'current_tags' => wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names'])
            ];
            
            // Test content processing if new classes are available
            if (class_exists('AutoTag_TextProcessor')) {
                $text_processor = new AutoTag_TextProcessor();
                $content_data = $text_processor->process_post_content($post);
                $debug_info['content_analysis'] = $content_data;
                
                if (class_exists('AutoTag_KeywordMatcher')) {
                    $keyword_matcher = new AutoTag_KeywordMatcher();
                    $config = wp_autotag_pro()->config->get_config();
                    $mode = $config['mode'] ?? 'c';
                    
                    $matches = $keyword_matcher->find_all_matches($content_data['normalized'], $config, $mode);
                    $match_stats = $keyword_matcher->get_match_stats($content_data['normalized'], $config, $mode);
                    
                    $debug_info['keyword_analysis'] = [
                        'matches' => $matches,
                        'match_stats' => $match_stats,
                        'mode' => $mode
                    ];
                    
                    if (class_exists('AutoTag_TagApplier')) {
                        $tag_applier = new AutoTag_TagApplier();
                        $new_tags = $tag_applier->convert_matches_to_tags($matches, $config, $mode);
                        $year_tag = $tag_applier->generate_year_tag($post_id);
                        
                        $strategy = $config['strategy'] ?? 'a';
                        $preview = $tag_applier->preview_tags($post_id, $new_tags, $strategy, $year_tag);
                        
                        $debug_info['tag_analysis'] = [
                            'generated_tags' => $new_tags,
                            'year_tag' => $year_tag,
                            'strategy' => $strategy,
                            'preview' => $preview
                        ];
                    }
                }
            } else {
                $debug_info['processing_note'] = 'New processing classes not available, using fallback methods';
            }
            
            // Configuration info
            $debug_info['configuration'] = [
                'plugin_version' => defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '1.0.0',
                'config_mode' => wp_autotag_pro()->config ? wp_autotag_pro()->config->get_config()['mode'] ?? 'unknown' : 'unavailable',
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true)
            ];
            
            wp_send_json_success([
                'message' => sprintf(__('Debug information collected for post: %s', 'wp-auto-tag-pro'), $post->post_title),
                'debug_info' => $debug_info,
                'generated_at' => current_time('mysql')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Debug error: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Test plugin performance
     */
    public function test_performance() {
        $this->verify_ajax_security('administrator');
        
        $test_size = max(1, min(100, intval($_POST['test_size'] ?? 10)));
        $include_processing = !empty($_POST['include_processing']);
        
        try {
            $start_time = microtime(true);
            $start_memory = memory_get_usage(true);
            
            $performance_results = [
                'test_params' => [
                    'test_size' => $test_size,
                    'include_processing' => $include_processing,
                    'start_time' => $start_time,
                    'start_memory' => $start_memory
                ],
                'tests' => []
            ];
            
            // Test 1: Configuration loading
            $config_start = microtime(true);
            $config = wp_autotag_pro()->config->get_config();
            $config_time = microtime(true) - $config_start;
            
            $performance_results['tests']['config_loading'] = [
                'time_ms' => round($config_time * 1000, 2),
                'config_size' => strlen(serialize($config)),
                'memory_used' => memory_get_usage(true) - $start_memory
            ];
            
            // Test 2: Text processing
            if (class_exists('AutoTag_TextProcessor')) {
                $text_processor = new AutoTag_TextProcessor();
                $text_start = microtime(true);
                
                $sample_text = str_repeat('This is a sample text about technology, artificial intelligence, and machine learning. ', 100);
                $mock_post = (object) [
                    'post_title' => 'Sample Test Post',
                    'post_content' => $sample_text,
                    'post_excerpt' => 'Sample excerpt',
                    'post_date' => current_time('mysql')
                ];
                
                for ($i = 0; $i < $test_size; $i++) {
                    $content_data = $text_processor->process_post_content($mock_post);
                }
                
                $text_time = microtime(true) - $text_start;
                $performance_results['tests']['text_processing'] = [
                    'time_ms' => round($text_time * 1000, 2),
                    'average_per_post_ms' => round(($text_time / $test_size) * 1000, 2),
                    'posts_per_second' => round($test_size / $text_time, 2)
                ];
            }
            
            // Test 3: Keyword matching
            if (class_exists('AutoTag_KeywordMatcher')) {
                $keyword_matcher = new AutoTag_KeywordMatcher();
                $keyword_start = microtime(true);
                
                $normalized_content = 'this is sample content about technology artificial intelligence machine learning digital transformation innovation';
                
                for ($i = 0; $i < $test_size; $i++) {
                    $matches = $keyword_matcher->find_all_matches($normalized_content, $config, $config['mode'] ?? 'c');
                }
                
                $keyword_time = microtime(true) - $keyword_start;
                $performance_results['tests']['keyword_matching'] = [
                    'time_ms' => round($keyword_time * 1000, 2),
                    'average_per_post_ms' => round(($keyword_time / $test_size) * 1000, 2),
                    'posts_per_second' => round($test_size / $keyword_time, 2)
                ];
            }
            
            // Test 4: Full processing (if requested)
            if ($include_processing && class_exists('AutoTag_ProcessorCoordinator')) {
                $coordinator = new AutoTag_ProcessorCoordinator();
                $processing_start = microtime(true);
                
                // Get actual posts for testing
                $test_posts = get_posts([
                    'post_type' => 'post',
                    'posts_per_page' => min($test_size, 5), // Limit to 5 for performance
                    'fields' => 'ids'
                ]);
                
                foreach ($test_posts as $post_id) {
                    $result = $coordinator->preview_post_processing($post_id);
                }
                
                $processing_time = microtime(true) - $processing_start;
                $performance_results['tests']['full_processing'] = [
                    'time_ms' => round($processing_time * 1000, 2),
                    'posts_tested' => count($test_posts),
                    'average_per_post_ms' => count($test_posts) > 0 ? round(($processing_time / count($test_posts)) * 1000, 2) : 0,
                    'posts_per_second' => $processing_time > 0 ? round(count($test_posts) / $processing_time, 2) : 0
                ];
            }
            
            // Final metrics
            $total_time = microtime(true) - $start_time;
            $total_memory = memory_get_usage(true) - $start_memory;
            $peak_memory = memory_get_peak_usage(true);
            
            $performance_results['summary'] = [
                'total_time_ms' => round($total_time * 1000, 2),
                'memory_used_mb' => round($total_memory / 1024 / 1024, 2),
                'peak_memory_mb' => round($peak_memory / 1024 / 1024, 2),
                'overall_performance' => $this->calculate_performance_score($performance_results['tests'])
            ];
            
            wp_send_json_success([
                'message' => sprintf(__('Performance test completed for %d operations', 'wp-auto-tag-pro'), $test_size),
                'results' => $performance_results
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Performance test error: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Calculate overall performance score
     */
    private function calculate_performance_score($tests) {
        $scores = [];
        
        foreach ($tests as $test_name => $test_data) {
            if (isset($test_data['posts_per_second'])) {
                // Higher posts per second = better score
                $scores[] = min(100, $test_data['posts_per_second'] * 10);
            } elseif (isset($test_data['time_ms'])) {
                // Lower time = better score
                $scores[] = max(0, 100 - ($test_data['time_ms'] / 10));
            }
        }
        
        if (empty($scores)) {
            return 'N/A';
        }
        
        $average_score = array_sum($scores) / count($scores);
        
        if ($average_score >= 80) return 'Excellent';
        if ($average_score >= 60) return 'Good';
        if ($average_score >= 40) return 'Average';
        if ($average_score >= 20) return 'Poor';
        return 'Very Poor';
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