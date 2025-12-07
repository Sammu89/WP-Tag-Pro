<?php
/**
 * Text Processing Class
 * Handles content extraction and text normalization - extracted from AutoTag_Processor
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_TextProcessor {
    
    /**
     * Cache instance for performance
     */
    private $cache;
    
    /**
     * Constructor
     */
    public function __construct() {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->cache) {
            $this->cache = wp_autotag_pro()->cache;
        }
    }
    
    /**
     * Extract post content for analysis
     */
    public function extract_post_content($post) {
        if (!$post || is_wp_error($post)) {
            return '';
        }
        
        $content_parts = [];
        
        // Always include title
        if (!empty($post->post_title)) {
            $content_parts[] = $post->post_title;
        }
        
        // Include content
        if (!empty($post->post_content)) {
            // Strip shortcodes and HTML for cleaner analysis
            $clean_content = wp_strip_all_tags(do_shortcode($post->post_content));
            $content_parts[] = $clean_content;
        }
        
        // Include excerpt if available
        if (!empty($post->post_excerpt)) {
            $content_parts[] = $post->post_excerpt;
        }
        
        $combined_content = implode(' ', $content_parts);
        
        return $combined_content;
    }
    
    /**
     * Normalize content for keyword matching
     */
    public function normalize_content($content) {
        if (empty($content)) {
            return '';
        }
        
        // Convert to lowercase for case-insensitive matching
        $normalized = strtolower($content);
        
        // Remove extra whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        
        // Remove punctuation for cleaner matching
        $normalized = preg_replace('/[^\w\s]/', ' ', $normalized);
        
        // Remove extra spaces again
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        
        return $normalized;
    }
    
    /**
     * Extract and normalize content from post
     */
    public function process_post_content($post) {
        // Try to get from cache first
        if ($this->cache && function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
            $config_hash = wp_autotag_pro()->config->get_current_hash();
            $cached_result = $this->cache->get_text_processing(
                $post->post_title . '|' . $post->post_content . '|' . $post->post_excerpt, 
                $config_hash
            );
            
            if ($cached_result !== null) {
                return $cached_result;
            }
        }
        
        // Process content
        $raw_content = $this->extract_post_content($post);
        $normalized_content = $this->normalize_content($raw_content);
        
        $result = [
            'raw' => $raw_content,
            'normalized' => $normalized_content,
            'word_count' => str_word_count($raw_content),
            'char_count' => strlen($raw_content)
        ];
        
        // Cache the result for future use
        if ($this->cache && function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
            $config_hash = wp_autotag_pro()->config->get_current_hash();
            $this->cache->cache_text_processing(
                $post->post_title . '|' . $post->post_content . '|' . $post->post_excerpt,
                $config_hash,
                $result
            );
        }
        
        return $result;
    }
    
    /**
     * Extract year from post date
     */
    public function extract_year_from_post($post) {
        if (!$post || is_wp_error($post)) {
            return null;
        }
        
        $post_date = $post->post_date;
        if (empty($post_date) || $post_date === '0000-00-00 00:00:00') {
            return null;
        }
        
        return date('Y', strtotime($post_date));
    }
}