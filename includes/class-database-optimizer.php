<?php
/**
 * Database Optimizer Class for WordPress Auto-Tag Pro
 * Implements database query batching, optimization, and performance monitoring
 * Replaces individual database queries with batched operations for better performance
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_DatabaseOptimizer {
    
    /**
     * Batch configuration
     */
    private $batch_sizes = [
        'posts' => 100,         // Posts per batch for bulk operations
        'terms' => 50,          // Terms per batch for taxonomy operations
        'meta' => 200,          // Meta updates per batch
        'relationships' => 150  // Term relationships per batch
    ];
    
    /**
     * Query cache for batch operations
     */
    private $query_cache = [];
    private $pending_queries = [];
    
    /**
     * Performance metrics
     */
    private $metrics = [
        'queries_executed' => 0,
        'queries_batched' => 0,
        'time_saved' => 0,
        'cache_hits' => 0,
        'batch_operations' => 0
    ];
    
    /**
     * Memory monitor reference
     */
    private $memory_monitor = null;
    
    public function __construct() {
        $this->init_memory_integration();
        $this->init_hooks();
        $this->optimize_wordpress_queries();
    }
    
    /**
     * Initialize memory monitor integration
     */
    private function init_memory_integration() {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->memory_monitor) {
            $this->memory_monitor = wp_autotag_pro()->memory_monitor;
            $this->adjust_batch_sizes_for_memory();
        }
    }
    
    /**
     * Adjust batch sizes based on available memory
     */
    private function adjust_batch_sizes_for_memory() {
        if (!$this->memory_monitor) {
            return;
        }
        
        $memory_stats = $this->memory_monitor->get_memory_stats();
        $multiplier = 1.0;
        
        switch ($memory_stats['status']) {
            case 'emergency':
                $multiplier = 0.25;
                break;
            case 'critical':
                $multiplier = 0.5;
                break;
            case 'warning':
                $multiplier = 0.75;
                break;
            case 'normal':
            default:
                // Check if we have plenty of memory for larger batches
                if ($memory_stats['usage_percent'] < 40) {
                    $multiplier = 1.5;
                }
                break;
        }
        
        foreach ($this->batch_sizes as $type => $size) {
            $this->batch_sizes[$type] = max(10, intval($size * $multiplier));
        }
        
        $this->log_info("Adjusted batch sizes for memory status '{$memory_stats['status']}': " . json_encode($this->batch_sizes));
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Hook into bulk processing events
        add_action('autotag_before_bulk_processing', [$this, 'prepare_bulk_operations']);
        add_action('autotag_after_bulk_processing', [$this, 'finalize_bulk_operations']);
        
        // Hook into WordPress shutdown to execute any pending queries
        add_action('shutdown', [$this, 'execute_pending_queries'], 5);
        
        // Performance monitoring hooks
        add_action('autotag_database_query', [$this, 'track_query_performance']);
    }
    
    /**
     * Optimize WordPress queries for better performance
     */
    private function optimize_wordpress_queries() {
        // Optimize term queries
        add_filter('get_terms_args', [$this, 'optimize_term_queries'], 10, 2);
        
        // Optimize post queries
        add_filter('posts_pre_query', [$this, 'optimize_post_queries'], 10, 2);
        
        // Add query caching
        add_filter('posts_results', [$this, 'cache_posts_results'], 10, 2);
    }
    
    /**
     * Batch get posts for processing with optimized queries
     */
    public function get_posts_for_processing($args = []) {
        $start_time = microtime(true);
        
        $defaults = [
            'post_type' => ['post'],
            'post_status' => ['publish', 'draft', 'pending'],
            'posts_per_page' => $this->batch_sizes['posts'],
            'fields' => 'ids', // Only get IDs for memory efficiency
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters' => true
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Create cache key
        $cache_key = 'posts_processing_' . md5(serialize($args));
        $cached_result = $this->get_query_cache($cache_key);
        
        if ($cached_result !== null) {
            $this->metrics['cache_hits']++;
            return $cached_result;
        }
        
        // Execute optimized query
        $posts = get_posts($args);
        
        // Cache results for 10 minutes
        $this->set_query_cache($cache_key, $posts, 600);
        
        $execution_time = microtime(true) - $start_time;
        $this->metrics['queries_executed']++;
        
        $this->log_performance('get_posts_for_processing', count($posts), $execution_time);
        
        return $posts;
    }
    
    /**
     * Batch update post meta with single query
     */
    public function batch_update_post_meta($updates) {
        if (empty($updates) || !is_array($updates)) {
            return false;
        }
        
        $start_time = microtime(true);
        global $wpdb;
        
        // Group updates by meta_key for efficiency
        $grouped_updates = [];
        foreach ($updates as $update) {
            if (!isset($update['post_id'], $update['meta_key'], $update['meta_value'])) {
                continue;
            }
            
            $key = $update['meta_key'];
            if (!isset($grouped_updates[$key])) {
                $grouped_updates[$key] = [];
            }
            
            $grouped_updates[$key][] = [
                'post_id' => intval($update['post_id']),
                'meta_value' => $update['meta_value']
            ];
        }
        
        $total_updated = 0;
        
        foreach ($grouped_updates as $meta_key => $meta_updates) {
            // Batch updates in chunks
            $chunks = array_chunk($meta_updates, $this->batch_sizes['meta']);
            
            foreach ($chunks as $chunk) {
                $this->execute_batch_meta_update($meta_key, $chunk);
                $total_updated += count($chunk);
                
                // Check memory and yield if necessary
                if ($this->memory_monitor && $this->memory_monitor->is_memory_limit_approaching()) {
                    $this->log_warning('Memory limit approaching during batch meta update - yielding');
                    break 2;
                }
            }
        }
        
        $execution_time = microtime(true) - $start_time;
        $this->metrics['batch_operations']++;
        
        $this->log_performance('batch_update_post_meta', $total_updated, $execution_time);
        
        return $total_updated;
    }
    
    /**
     * Execute batch meta update with optimized query
     */
    private function execute_batch_meta_update($meta_key, $updates) {
        global $wpdb;
        
        if (empty($updates)) {
            return;
        }
        
        // Build VALUES for INSERT ... ON DUPLICATE KEY UPDATE
        $values = [];
        $placeholders = [];
        
        foreach ($updates as $update) {
            $values[] = intval($update['post_id']);
            $values[] = $meta_key;
            $values[] = maybe_serialize($update['meta_value']);
            $placeholders[] = '(%d, %s, %s)';
        }
        
        $values_sql = implode(', ', $placeholders);
        
        $query = "
            INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
            VALUES {$values_sql}
            ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
        ";
        
        $prepared_query = $wpdb->prepare($query, $values);
        $result = $wpdb->query($prepared_query);
        
        if ($result === false) {
            $this->log_error('Batch meta update failed: ' . $wpdb->last_error);
        }
        
        return $result;
    }
    
    /**
     * Batch update term relationships
     */
    public function batch_update_term_relationships($relationships) {
        if (empty($relationships) || !is_array($relationships)) {
            return false;
        }
        
        $start_time = microtime(true);
        global $wpdb;
        
        // Group by taxonomy for efficiency
        $grouped_relationships = [];
        foreach ($relationships as $rel) {
            if (!isset($rel['object_id'], $rel['term_taxonomy_id'])) {
                continue;
            }
            
            $taxonomy = $rel['taxonomy'] ?? 'post_tag';
            if (!isset($grouped_relationships[$taxonomy])) {
                $grouped_relationships[$taxonomy] = [];
            }
            
            $grouped_relationships[$taxonomy][] = [
                'object_id' => intval($rel['object_id']),
                'term_taxonomy_id' => intval($rel['term_taxonomy_id']),
                'term_order' => intval($rel['term_order'] ?? 0)
            ];
        }
        
        $total_updated = 0;
        
        foreach ($grouped_relationships as $taxonomy => $rels) {
            $chunks = array_chunk($rels, $this->batch_sizes['relationships']);
            
            foreach ($chunks as $chunk) {
                $updated = $this->execute_batch_relationship_update($chunk);
                $total_updated += $updated;
                
                // Memory check
                if ($this->memory_monitor && $this->memory_monitor->is_memory_limit_approaching()) {
                    break 2;
                }
            }
        }
        
        $execution_time = microtime(true) - $start_time;
        $this->metrics['batch_operations']++;
        
        $this->log_performance('batch_update_term_relationships', $total_updated, $execution_time);
        
        return $total_updated;
    }
    
    /**
     * Execute batch relationship update
     */
    private function execute_batch_relationship_update($relationships) {
        global $wpdb;
        
        if (empty($relationships)) {
            return 0;
        }
        
        $values = [];
        $placeholders = [];
        
        foreach ($relationships as $rel) {
            $values[] = $rel['object_id'];
            $values[] = $rel['term_taxonomy_id'];
            $values[] = $rel['term_order'];
            $placeholders[] = '(%d, %d, %d)';
        }
        
        $values_sql = implode(', ', $placeholders);
        
        $query = "
            INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order)
            VALUES {$values_sql}
            ON DUPLICATE KEY UPDATE term_order = VALUES(term_order)
        ";
        
        $prepared_query = $wpdb->prepare($query, $values);
        $result = $wpdb->query($prepared_query);
        
        if ($result === false) {
            $this->log_error('Batch relationship update failed: ' . $wpdb->last_error);
            return 0;
        }
        
        return $result;
    }
    
    /**
     * Batch get or create terms
     */
    public function batch_get_or_create_terms($terms, $taxonomy = 'post_tag') {
        if (empty($terms) || !is_array($terms)) {
            return [];
        }
        
        $start_time = microtime(true);
        
        // First, try to get existing terms in batch
        $existing_terms = $this->batch_get_terms_by_names($terms, $taxonomy);
        $existing_names = array_column($existing_terms, 'name');
        
        // Find terms that need to be created
        $terms_to_create = array_diff($terms, $existing_names);
        $created_terms = [];
        
        if (!empty($terms_to_create)) {
            $created_terms = $this->batch_create_terms($terms_to_create, $taxonomy);
        }
        
        // Combine existing and created terms
        $all_terms = array_merge($existing_terms, $created_terms);
        
        $execution_time = microtime(true) - $start_time;
        $this->log_performance('batch_get_or_create_terms', count($all_terms), $execution_time);
        
        return $all_terms;
    }
    
    /**
     * Batch get terms by names
     */
    private function batch_get_terms_by_names($names, $taxonomy) {
        global $wpdb;
        
        if (empty($names)) {
            return [];
        }
        
        $names = array_unique($names);
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        
        $query = "
            SELECT t.name, t.term_id, tt.term_taxonomy_id
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            WHERE tt.taxonomy = %s AND t.name IN ({$placeholders})
        ";
        
        $query_params = array_merge([$taxonomy], $names);
        $prepared_query = $wpdb->prepare($query, $query_params);
        $results = $wpdb->get_results($prepared_query, ARRAY_A);
        
        return $results ?: [];
    }
    
    /**
     * Batch create terms
     */
    private function batch_create_terms($terms, $taxonomy) {
        $created_terms = [];
        
        // WordPress doesn't have a direct batch insert for terms,
        // so we'll create them efficiently one by one with optimizations
        foreach ($terms as $term_name) {
            // Use wp_insert_term but with caching disabled during batch operations
            $term_result = wp_insert_term($term_name, $taxonomy);
            
            if (!is_wp_error($term_result)) {
                $created_terms[] = [
                    'name' => $term_name,
                    'term_id' => $term_result['term_id'],
                    'term_taxonomy_id' => $term_result['term_taxonomy_id']
                ];
            }
            
            // Memory check during creation
            if ($this->memory_monitor && $this->memory_monitor->is_memory_limit_approaching()) {
                $this->log_warning('Memory limit approaching during term creation - stopping batch');
                break;
            }
        }
        
        return $created_terms;
    }
    
    /**
     * Query cache management
     */
    private function get_query_cache($key) {
        if (isset($this->query_cache[$key])) {
            return $this->query_cache[$key]['data'];
        }
        return null;
    }
    
    private function set_query_cache($key, $data, $ttl = 300) {
        // Limit cache size to prevent memory issues
        if (count($this->query_cache) > 50) {
            // Remove oldest entries
            $oldest_keys = array_slice(array_keys($this->query_cache), 0, 25);
            foreach ($oldest_keys as $old_key) {
                unset($this->query_cache[$old_key]);
            }
        }
        
        $this->query_cache[$key] = [
            'data' => $data,
            'expires' => time() + $ttl
        ];
    }
    
    /**
     * Clean expired cache entries
     */
    public function clean_query_cache() {
        $current_time = time();
        $cleaned = 0;
        
        foreach ($this->query_cache as $key => $cache_data) {
            if ($cache_data['expires'] < $current_time) {
                unset($this->query_cache[$key]);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            $this->log_info("Cleaned {$cleaned} expired query cache entries");
        }
    }
    
    /**
     * Prepare for bulk operations
     */
    public function prepare_bulk_operations() {
        // Disable WordPress query caching during bulk operations
        wp_suspend_cache_addition(true);
        
        // Adjust batch sizes based on current memory
        $this->adjust_batch_sizes_for_memory();
        
        $this->log_info('Prepared database for bulk operations');
    }
    
    /**
     * Finalize bulk operations
     */
    public function finalize_bulk_operations() {
        // Execute any pending queries
        $this->execute_pending_queries();
        
        // Re-enable WordPress query caching
        wp_suspend_cache_addition(false);
        
        // Clean up query cache
        $this->clean_query_cache();
        
        $this->log_info('Finalized database bulk operations');
    }
    
    /**
     * Execute pending queries
     */
    public function execute_pending_queries() {
        if (empty($this->pending_queries)) {
            return;
        }
        
        foreach ($this->pending_queries as $query_type => $queries) {
            switch ($query_type) {
                case 'meta_updates':
                    $this->batch_update_post_meta($queries);
                    break;
                case 'term_relationships':
                    $this->batch_update_term_relationships($queries);
                    break;
            }
        }
        
        $this->pending_queries = [];
    }
    
    /**
     * Add query to pending queue
     */
    public function queue_query($type, $data) {
        if (!isset($this->pending_queries[$type])) {
            $this->pending_queries[$type] = [];
        }
        
        $this->pending_queries[$type][] = $data;
        
        // Auto-execute when batch size is reached
        if (count($this->pending_queries[$type]) >= $this->batch_sizes['meta']) {
            $queries = $this->pending_queries[$type];
            $this->pending_queries[$type] = [];
            
            switch ($type) {
                case 'meta_updates':
                    $this->batch_update_post_meta($queries);
                    break;
                case 'term_relationships':
                    $this->batch_update_term_relationships($queries);
                    break;
            }
        }
    }
    
    /**
     * Get performance metrics
     */
    public function get_performance_metrics() {
        return array_merge($this->metrics, [
            'batch_sizes' => $this->batch_sizes,
            'cache_size' => count($this->query_cache),
            'pending_queries' => array_map('count', $this->pending_queries)
        ]);
    }
    
    /**
     * Optimize term queries filter
     */
    public function optimize_term_queries($args, $taxonomies) {
        // Add optimizations for autotag-related queries
        if (in_array('post_tag', $taxonomies) || in_array('category', $taxonomies)) {
            $args['update_term_meta_cache'] = false;
        }
        
        return $args;
    }
    
    /**
     * Optimize post queries filter
     */
    public function optimize_post_queries($posts, $query) {
        // Return early if not our query or if we have a cached result
        if (!$query->get('autotag_processing')) {
            return $posts;
        }
        
        return $posts;
    }
    
    /**
     * Cache posts results
     */
    public function cache_posts_results($posts, $query) {
        if ($query->get('autotag_processing') && !empty($posts)) {
            $cache_key = 'autotag_posts_' . md5(serialize($query->query_vars));
            $this->set_query_cache($cache_key, $posts, 300);
        }
        
        return $posts;
    }
    
    /**
     * Track query performance
     */
    public function track_query_performance($query_data) {
        $this->metrics['queries_executed']++;
        
        if (isset($query_data['execution_time'])) {
            $this->metrics['time_saved'] += $query_data['time_saved'] ?? 0;
        }
    }
    
    /**
     * Log performance metrics
     */
    private function log_performance($operation, $count, $execution_time) {
        $this->log_info("DB Operation: {$operation} - Count: {$count} - Time: " . round($execution_time * 1000, 2) . "ms");
    }
    
    /**
     * Logging methods
     */
    private function log_info($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_info('[DB Optimizer] ' . $message);
        }
    }
    
    private function log_warning($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_error('[DB Optimizer WARNING] ' . $message);
        }
    }
    
    private function log_error($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_error('[DB Optimizer ERROR] ' . $message);
        }
    }
}