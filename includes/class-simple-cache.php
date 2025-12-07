<?php
/**
 * Simple Cache System for WordPress Auto-Tag Pro
 * Two-tier caching: Memory (fastest) + WordPress Transients (persistent)
 * Prevents re-processing same content and improves performance
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_SimpleCache {
    
    /**
     * In-memory cache for current request
     */
    private $memory_cache = [];
    
    /**
     * Cache configuration
     */
    private $cache_prefix = 'autotag_cache_';
    private $default_ttl = 3600; // 1 hour
    private $max_memory_items = 100; // Prevent memory bloat
    private $memory_monitor = null;
    
    /**
     * Cache statistics
     */
    private $stats = [
        'hits' => 0,
        'misses' => 0,
        'memory_hits' => 0,
        'transient_hits' => 0,
        'sets' => 0,
        'deletes' => 0
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into config changes to clear cache
        add_action('autotag_config_changed', [$this, 'clear_all']);
        
        // Initialize memory monitoring integration
        $this->init_memory_monitoring();
        
        // Adjust cache limits based on memory availability
        $this->adjust_cache_limits();
    }
    
    /**
     * Initialize memory monitoring integration
     */
    private function init_memory_monitoring() {
        // Get memory monitor from main plugin if available
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->memory_monitor) {
            $this->memory_monitor = wp_autotag_pro()->memory_monitor;
        }
    }
    
    /**
     * Adjust cache limits based on available memory
     */
    private function adjust_cache_limits() {
        if ($this->memory_monitor) {
            $memory_stats = $this->memory_monitor->get_memory_stats();
            $cache_limits = $this->memory_monitor->get_cache_limits() ?? [];
            
            // Adjust based on memory pressure
            switch ($memory_stats['status']) {
                case 'emergency':
                case 'critical':
                    $this->max_memory_items = max(10, intval($this->max_memory_items / 4));
                    break;
                case 'warning':
                    $this->max_memory_items = max(25, intval($this->max_memory_items / 2));
                    break;
                case 'normal':
                default:
                    $this->max_memory_items = $cache_limits['post_processing'] ?? 100;
                    break;
            }
        } else {
            // Fallback: detect memory limit and adjust accordingly
            $memory_limit = ini_get('memory_limit');
            if ($memory_limit !== '-1') {
                $memory_bytes = $this->convert_to_bytes($memory_limit);
                if ($memory_bytes <= 128 * 1024 * 1024) { // 128MB or less
                    $this->max_memory_items = 50; // Reduced for shared hosting
                }
            }
        }
    }
    
    /**
     * Get cached value
     */
    public function get($key) {
        $prefixed_key = $this->cache_prefix . $key;
        
        // Check memory cache first (fastest)
        if (isset($this->memory_cache[$key])) {
            $this->stats['hits']++;
            $this->stats['memory_hits']++;
            return $this->memory_cache[$key];
        }
        
        // Check WordPress transient cache
        $cached = get_transient($prefixed_key);
        if ($cached !== false) {
            $this->stats['hits']++;
            $this->stats['transient_hits']++;
            
            // Store in memory for next time (if we have room)
            if (count($this->memory_cache) < $this->max_memory_items) {
                $this->memory_cache[$key] = $cached;
            }
            
            return $cached;
        }
        
        // Cache miss
        $this->stats['misses']++;
        return null;
    }
    
    /**
     * Set cached value with memory-aware storage
     */
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?? $this->default_ttl;
        $prefixed_key = $this->cache_prefix . $key;
        
        // Check memory status before caching
        $should_cache_in_memory = true;
        if ($this->memory_monitor) {
            $memory_status = $this->memory_monitor->get_memory_status();
            if (in_array($memory_status, ['emergency', 'critical'])) {
                // Skip memory caching under high memory pressure
                $should_cache_in_memory = false;
            } elseif ($memory_status === 'warning') {
                // Reduce memory cache size under moderate pressure
                $this->max_memory_items = max(10, intval($this->max_memory_items / 2));
            }
        }
        
        // Store in memory cache (with adaptive size limit)
        if ($should_cache_in_memory) {
            if (count($this->memory_cache) >= $this->max_memory_items) {
                // Intelligent cleanup - remove oldest items based on memory pressure
                $items_to_remove = $this->memory_monitor && 
                    $this->memory_monitor->is_memory_limit_approaching(0.70) ? 
                    intval($this->max_memory_items / 2) : 1;
                
                $keys_to_remove = array_slice(array_keys($this->memory_cache), 0, $items_to_remove);
                foreach ($keys_to_remove as $old_key) {
                    unset($this->memory_cache[$old_key]);
                }
            }
            
            $this->memory_cache[$key] = $value;
        }
        
        // Store in WordPress transient cache
        $result = set_transient($prefixed_key, $value, $ttl);
        
        if ($result) {
            $this->stats['sets']++;
        }
        
        return $result;
    }
    
    /**
     * Delete cached value
     */
    public function delete($key) {
        $prefixed_key = $this->cache_prefix . $key;
        
        // Remove from memory cache
        unset($this->memory_cache[$key]);
        
        // Remove from WordPress cache
        $result = delete_transient($prefixed_key);
        
        if ($result) {
            $this->stats['deletes']++;
        }
        
        return $result;
    }
    
    /**
     * Clear all cached values
     */
    public function clear_all() {
        // Clear memory cache
        $this->memory_cache = [];
        
        // Clear WordPress transients with our prefix
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s OR option_name LIKE %s
        ", 
            '_transient_' . $this->cache_prefix . '%',
            '_transient_timeout_' . $this->cache_prefix . '%'
        ));
        
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_info('Cache cleared', [
                'transients_deleted' => $deleted,
                'memory_items_cleared' => count($this->memory_cache)
            ]);
        }
        
        return $deleted;
    }
    
    /**
     * Generate cache key from content and configuration
     */
    public function generate_key($content, $config_hash = '', $extra_data = '') {
        // Create hash from content + config + extra data
        $key_data = $content . '|' . $config_hash . '|' . $extra_data;
        return md5($key_data);
    }
    
    /**
     * Generate cache key for post processing
     */
    public function generate_post_key($post_id, $config_hash = '') {
        // Get post content hash to detect changes
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }
        
        $content_hash = md5($post->post_title . $post->post_content . $post->post_excerpt);
        return $this->generate_key('post_' . $post_id, $config_hash, $content_hash);
    }
    
    /**
     * Cache text processing results
     */
    public function cache_text_processing($content, $config_hash, $result) {
        $key = $this->generate_key($content, $config_hash, 'text_processing');
        return $this->set($key, $result, 1800); // 30 minutes for text processing
    }
    
    /**
     * Get cached text processing results
     */
    public function get_text_processing($content, $config_hash) {
        $key = $this->generate_key($content, $config_hash, 'text_processing');
        return $this->get($key);
    }
    
    /**
     * Cache keyword matching results
     */
    public function cache_keyword_matches($normalized_content, $config_hash, $matches) {
        $key = $this->generate_key($normalized_content, $config_hash, 'keyword_matches');
        return $this->set($key, $matches, 3600); // 1 hour for keyword matches
    }
    
    /**
     * Get cached keyword matching results
     */
    public function get_keyword_matches($normalized_content, $config_hash) {
        $key = $this->generate_key($normalized_content, $config_hash, 'keyword_matches');
        return $this->get($key);
    }
    
    /**
     * Cache post processing results
     */
    public function cache_post_processing($post_id, $config_hash, $result) {
        $key = $this->generate_post_key($post_id, $config_hash);
        if ($key) {
            return $this->set($key, $result, 900); // 15 minutes for post processing
        }
        return false;
    }
    
    /**
     * Get cached post processing results
     */
    public function get_post_processing($post_id, $config_hash) {
        $key = $this->generate_post_key($post_id, $config_hash);
        if ($key) {
            return $this->get($key);
        }
        return null;
    }
    
    /**
     * Invalidate cache for specific post
     */
    public function invalidate_post_cache($post_id) {
        // We don't know the exact config hash, so we need to pattern match
        global $wpdb;
        
        $pattern = $this->cache_prefix . '%post_' . $post_id . '%';
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->options} 
            WHERE (option_name LIKE %s OR option_name LIKE %s) 
            AND (option_name LIKE %s OR option_name LIKE %s)
        ", 
            '_transient_' . $pattern,
            '_transient_timeout_' . $pattern,
            '%post_' . $post_id . '%',
            '%post_' . $post_id . '%'
        ));
        
        // Also clear from memory cache
        foreach ($this->memory_cache as $key => $value) {
            if (strpos($key, 'post_' . $post_id) !== false) {
                unset($this->memory_cache[$key]);
            }
        }
        
        return $deleted;
    }
    
    /**
     * Get cache statistics
     */
    public function get_stats() {
        $hit_rate = ($this->stats['hits'] + $this->stats['misses']) > 0 
            ? round(($this->stats['hits'] / ($this->stats['hits'] + $this->stats['misses'])) * 100, 2) 
            : 0;
        
        return array_merge($this->stats, [
            'hit_rate_percent' => $hit_rate,
            'memory_items' => count($this->memory_cache),
            'max_memory_items' => $this->max_memory_items
        ]);
    }
    
    /**
     * Reset cache statistics
     */
    public function reset_stats() {
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'memory_hits' => 0,
            'transient_hits' => 0,
            'sets' => 0,
            'deletes' => 0
        ];
    }
    
    /**
     * Get cache size information
     */
    public function get_cache_info() {
        global $wpdb;
        
        // Count transients with our prefix
        $transient_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE %s
        ", '_transient_' . $this->cache_prefix . '%'));
        
        return [
            'memory_items' => count($this->memory_cache),
            'transient_items' => (int) $transient_count,
            'cache_prefix' => $this->cache_prefix,
            'default_ttl' => $this->default_ttl,
            'max_memory_items' => $this->max_memory_items
        ];
    }
    
    /**
     * Warm up cache for recent posts
     */
    public function warm_cache($post_limit = 10) {
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->config) {
            return false;
        }
        
        $config_hash = wp_autotag_pro()->config->get_current_hash();
        
        // Get recent posts
        $recent_posts = get_posts([
            'posts_per_page' => $post_limit,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        $warmed = 0;
        foreach ($recent_posts as $post) {
            $cache_key = $this->generate_post_key($post->ID, $config_hash);
            
            // Only warm if not already cached
            if ($cache_key && $this->get($cache_key) === null) {
                // Pre-process and cache the post
                if (class_exists('AutoTag_ProcessorCoordinator')) {
                    $coordinator = new AutoTag_ProcessorCoordinator();
                    $result = $coordinator->preview_post_processing($post->ID);
                    
                    if ($result && !is_wp_error($result)) {
                        $this->cache_post_processing($post->ID, $config_hash, $result);
                        $warmed++;
                    }
                }
            }
        }
        
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_info('Cache warmed', [
                'posts_processed' => $warmed,
                'posts_checked' => count($recent_posts)
            ]);
        }
        
        return $warmed;
    }
    
    /**
     * Clean up expired transients (maintenance)
     */
    public function cleanup_expired() {
        global $wpdb;
        
        // WordPress automatically handles expired transients, but we can help
        $deleted = $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_timeout_%' 
            AND option_value < UNIX_TIMESTAMP()
        ");
        
        return $deleted;
    }
    
    /**
     * Convert memory string to bytes (helper method)
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
     * Get cache size limits for memory monitor integration
     */
    public function get_cache_limits() {
        return [
            'text_normalization' => 50,
            'keyword_matches' => 100,
            'post_processing' => $this->max_memory_items
        ];
    }
}