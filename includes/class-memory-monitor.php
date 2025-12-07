<?php
/**
 * Memory Monitor Class for WordPress Auto-Tag Pro
 * Implements actual memory monitoring and automatic cleanup to replace placeholder constants
 * Provides adaptive batch sizing based on available memory
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_MemoryMonitor {
    
    /**
     * Memory monitoring thresholds
     */
    private $memory_limit_bytes = 0;
    private $warning_threshold = 0.75; // 75%
    private $critical_threshold = 0.85; // 85%
    private $emergency_threshold = 0.95; // 95%
    
    /**
     * Cache size tracking
     */
    private $cache_size_limits = [
        'text_normalization' => 50,
        'keyword_matches' => 100,
        'post_processing' => 25
    ];
    
    /**
     * Performance metrics
     */
    private $metrics = [
        'memory_checks' => 0,
        'cleanup_triggers' => 0,
        'cache_resets' => 0,
        'adaptive_adjustments' => 0
    ];
    
    /**
     * Current batch size adjustments
     */
    private $adaptive_batch_size = 50;
    private $min_batch_size = 5;
    private $max_batch_size = 200;
    
    public function __construct() {
        $this->init_memory_monitoring();
        $this->init_hooks();
    }
    
    /**
     * Initialize memory monitoring with actual system detection
     */
    private function init_memory_monitoring() {
        // Get actual PHP memory limit
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit === '-1') {
            // Unlimited memory - use reasonable defaults for shared hosting
            $this->memory_limit_bytes = 128 * 1024 * 1024; // 128MB default
        } else {
            $this->memory_limit_bytes = $this->convert_to_bytes($memory_limit);
        }
        
        // Adjust thresholds for shared hosting environments
        if ($this->memory_limit_bytes <= 128 * 1024 * 1024) { // 128MB or less
            $this->warning_threshold = 0.65;  // 65% for shared hosting
            $this->critical_threshold = 0.75; // 75%
            $this->emergency_threshold = 0.85; // 85%
            
            // Reduce cache limits for shared hosting
            $this->cache_size_limits = [
                'text_normalization' => 25,
                'keyword_matches' => 50,
                'post_processing' => 15
            ];
        }
        
        $this->log_info("Memory monitor initialized - Limit: " . $this->format_bytes($this->memory_limit_bytes) . 
                       ", Thresholds: {$this->warning_threshold}% / {$this->critical_threshold}% / {$this->emergency_threshold}%");
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Memory monitoring hooks
        add_action('autotag_before_processing', [$this, 'check_memory_before_processing']);
        add_action('autotag_after_processing', [$this, 'check_memory_after_processing']);
        
        // Adaptive processing hooks
        add_filter('autotag_batch_size', [$this, 'get_adaptive_batch_size'], 10, 2);
        add_filter('autotag_processing_timeout', [$this, 'get_adaptive_timeout'], 10, 2);
        
        // Emergency cleanup hooks
        add_action('wp_fatal_error_handler_enabled', [$this, 'emergency_memory_cleanup']);
        
        // Scheduled memory cleanup
        add_action('autotag_memory_cleanup', [$this, 'scheduled_memory_cleanup']);
        
        // Hook into WordPress memory management
        add_action('wp_loaded', [$this, 'register_memory_cleanup']);
    }
    
    /**
     * Register memory cleanup with WordPress
     */
    public function register_memory_cleanup() {
        if (!wp_next_scheduled('autotag_memory_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'autotag_memory_cleanup');
        }
    }
    
    /**
     * Get current memory usage statistics
     */
    public function get_memory_stats() {
        $current_usage = memory_get_usage(true);
        $peak_usage = memory_get_peak_usage(true);
        $usage_percent = ($current_usage / $this->memory_limit_bytes) * 100;
        $peak_percent = ($peak_usage / $this->memory_limit_bytes) * 100;
        
        return [
            'current_usage' => $current_usage,
            'current_usage_formatted' => $this->format_bytes($current_usage),
            'peak_usage' => $peak_usage,
            'peak_usage_formatted' => $this->format_bytes($peak_usage),
            'memory_limit' => $this->memory_limit_bytes,
            'memory_limit_formatted' => $this->format_bytes($this->memory_limit_bytes),
            'usage_percent' => round($usage_percent, 2),
            'peak_percent' => round($peak_percent, 2),
            'available' => $this->memory_limit_bytes - $current_usage,
            'available_formatted' => $this->format_bytes($this->memory_limit_bytes - $current_usage),
            'status' => $this->get_memory_status($usage_percent),
            'thresholds' => [
                'warning' => $this->warning_threshold * 100,
                'critical' => $this->critical_threshold * 100,
                'emergency' => $this->emergency_threshold * 100
            ]
        ];
    }
    
    /**
     * Get memory status based on current usage
     */
    public function get_memory_status($usage_percent = null) {
        if ($usage_percent === null) {
            $usage_percent = (memory_get_usage(true) / $this->memory_limit_bytes);
        } else {
            $usage_percent = $usage_percent / 100; // Convert to decimal
        }
        
        if ($usage_percent >= $this->emergency_threshold) {
            return 'emergency';
        } elseif ($usage_percent >= $this->critical_threshold) {
            return 'critical';
        } elseif ($usage_percent >= $this->warning_threshold) {
            return 'warning';
        } else {
            return 'normal';
        }
    }
    
    /**
     * Check if memory limit is approaching
     */
    public function is_memory_limit_approaching($threshold = null) {
        $threshold = $threshold ?? $this->warning_threshold;
        $current_usage = memory_get_usage(true);
        $usage_percent = $current_usage / $this->memory_limit_bytes;
        
        $this->metrics['memory_checks']++;
        
        return $usage_percent >= $threshold;
    }
    
    /**
     * Check memory before processing batch
     */
    public function check_memory_before_processing($batch_size = 1) {
        $status = $this->get_memory_status();
        
        if ($status === 'emergency') {
            $this->emergency_memory_cleanup();
            throw new AutoTag_MemoryException('Emergency: Memory usage too high to continue processing');
        }
        
        if ($status === 'critical') {
            $this->trigger_memory_cleanup();
            
            // Reduce batch size dramatically
            $this->adaptive_batch_size = max($this->min_batch_size, $batch_size / 4);
            $this->metrics['adaptive_adjustments']++;
            
            $this->log_warning('Critical memory usage detected - reduced batch size to ' . $this->adaptive_batch_size);
        } elseif ($status === 'warning') {
            // Moderate cleanup and batch size reduction
            $this->trigger_cache_cleanup();
            $this->adaptive_batch_size = max($this->min_batch_size, $batch_size / 2);
            $this->metrics['adaptive_adjustments']++;
        }
    }
    
    /**
     * Check memory after processing batch
     */
    public function check_memory_after_processing($processed_count = 1) {
        $stats = $this->get_memory_stats();
        
        // Adjust batch size based on memory usage after processing
        if ($stats['status'] === 'normal' && $this->adaptive_batch_size < $this->max_batch_size) {
            // Memory usage is good, can increase batch size slightly
            $this->adaptive_batch_size = min($this->max_batch_size, $this->adaptive_batch_size + 5);
        }
        
        // Log performance metrics
        $this->log_performance_metrics($stats, $processed_count);
    }
    
    /**
     * Get adaptive batch size based on current memory usage
     */
    public function get_adaptive_batch_size($default_size = 50, $context = '') {
        $status = $this->get_memory_status();
        
        switch ($status) {
            case 'emergency':
                return max($this->min_batch_size, $this->adaptive_batch_size / 8);
            case 'critical':
                return max($this->min_batch_size, $this->adaptive_batch_size / 4);
            case 'warning':
                return max($this->min_batch_size, $this->adaptive_batch_size / 2);
            case 'normal':
            default:
                return min($this->max_batch_size, $this->adaptive_batch_size);
        }
    }
    
    /**
     * Get adaptive timeout based on memory status
     */
    public function get_adaptive_timeout($default_timeout = 30, $context = '') {
        $status = $this->get_memory_status();
        
        switch ($status) {
            case 'emergency':
            case 'critical':
                return max(5, $default_timeout / 4); // Minimum processing time
            case 'warning':
                return max(10, $default_timeout / 2);
            case 'normal':
            default:
                return $default_timeout;
        }
    }
    
    /**
     * Trigger memory cleanup based on current status
     */
    public function trigger_memory_cleanup() {
        $this->metrics['cleanup_triggers']++;
        
        // Clear WordPress caches
        wp_cache_flush();
        
        // Clear our plugin caches
        $this->clear_plugin_caches();
        
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            $collected = gc_collect_cycles();
            $this->log_info("Garbage collection freed {$collected} memory cycles");
        }
        
        $this->log_info('Memory cleanup triggered - current usage: ' . $this->format_bytes(memory_get_usage(true)));
    }
    
    /**
     * Clear plugin-specific caches
     */
    public function clear_plugin_caches() {
        // Clear SimpleCache if available
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->cache) {
            wp_autotag_pro()->cache->clear_all();
        }
        
        // Clear static caches in main plugin file
        $this->clear_static_caches();
        
        // Clear WordPress object cache
        if (function_exists('wp_cache_delete_group')) {
            wp_cache_delete_group('autotag');
        }
        
        $this->log_info('Plugin caches cleared');
    }
    
    /**
     * Clear static caches using reflection to access private variables
     */
    private function clear_static_caches() {
        // Clear the normalize_text static cache
        if (function_exists('wp_autotag_pro')) {
            try {
                $plugin = wp_autotag_pro();
                $reflection = new ReflectionMethod($plugin, 'normalize_text');
                $reflection->setAccessible(true);
                
                // Call with empty string to trigger cache reset logic
                $plugin->normalize_text('');
                
            } catch (Exception $e) {
                $this->log_error('Failed to clear static caches: ' . $e->getMessage());
            }
        }
        
        $this->metrics['cache_resets']++;
    }
    
    /**
     * Trigger cache-specific cleanup
     */
    private function trigger_cache_cleanup() {
        // Clear only less critical caches
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->cache) {
            // Clear specific cache types selectively
            $cache = wp_autotag_pro()->cache;
            $cache_info = $cache->get_cache_info();
            
            if ($cache_info['memory_items'] > $this->cache_size_limits['text_normalization']) {
                $cache->clear_all();
                $this->log_info('Cache cleanup: cleared oversized memory cache');
            }
        }
    }
    
    /**
     * Emergency memory cleanup - most aggressive cleanup
     */
    public function emergency_memory_cleanup() {
        $this->log_error('EMERGENCY: Memory limit approaching - performing aggressive cleanup');
        
        // Clear all caches aggressively
        wp_cache_flush();
        $this->clear_plugin_caches();
        
        // Clear WordPress transients
        global $wpdb;
        $deleted = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
        $this->log_info("Emergency cleanup: removed {$deleted} transients");
        
        // Force multiple garbage collections
        for ($i = 0; $i < 3; $i++) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        // Set minimum batch sizes
        $this->adaptive_batch_size = $this->min_batch_size;
        
        $this->log_error('Emergency memory cleanup completed - current usage: ' . $this->format_bytes(memory_get_usage(true)));
    }
    
    /**
     * Scheduled memory cleanup (hourly)
     */
    public function scheduled_memory_cleanup() {
        $stats = $this->get_memory_stats();
        
        // Only cleanup if approaching limits
        if ($stats['status'] !== 'normal') {
            $this->trigger_memory_cleanup();
        }
        
        // Clean up old cache entries
        $this->cleanup_old_caches();
        
        $this->log_info('Scheduled memory cleanup completed');
    }
    
    /**
     * Clean up old cache entries
     */
    private function cleanup_old_caches() {
        global $wpdb;
        
        // Remove old autotag transients (older than 24 hours)
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->options} 
            WHERE (option_name LIKE %s OR option_name LIKE %s) 
            AND option_value < %d
        ", 
            '_transient_timeout_autotag_%',
            '_site_transient_timeout_autotag_%',
            time() - DAY_IN_SECONDS
        ));
        
        if ($deleted > 0) {
            $this->log_info("Cleaned up {$deleted} old cache entries");
        }
    }
    
    /**
     * Log performance metrics
     */
    private function log_performance_metrics($memory_stats, $processed_count) {
        $metrics = [
            'processed_items' => $processed_count,
            'memory_usage' => $memory_stats['current_usage_formatted'],
            'memory_percent' => $memory_stats['usage_percent'],
            'batch_size' => $this->adaptive_batch_size,
            'status' => $memory_stats['status']
        ];
        
        $this->log_info('Performance metrics: ' . json_encode($metrics));
    }
    
    /**
     * Get monitoring metrics for admin display
     */
    public function get_monitoring_metrics() {
        return array_merge($this->metrics, [
            'adaptive_batch_size' => $this->adaptive_batch_size,
            'cache_size_limits' => $this->cache_size_limits,
            'memory_stats' => $this->get_memory_stats()
        ]);
    }
    
    /**
     * Update cache size limits (for admin configuration)
     */
    public function update_cache_limits($limits) {
        if (is_array($limits)) {
            $this->cache_size_limits = array_merge($this->cache_size_limits, $limits);
            $this->log_info('Cache size limits updated: ' . json_encode($this->cache_size_limits));
        }
    }
    
    /**
     * Convert memory string to bytes
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
     * Format bytes for human reading
     */
    private function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Logging methods
     */
    private function log_info($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_info('[Memory Monitor] ' . $message);
        }
    }
    
    private function log_warning($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_error('[Memory Monitor WARNING] ' . $message);
        }
    }
    
    private function log_error($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_error('[Memory Monitor ERROR] ' . $message);
        }
    }
}

/**
 * Memory Exception Class
 */
class AutoTag_MemoryException extends Exception {
    public function __construct($message, $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}