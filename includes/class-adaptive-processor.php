<?php
/**
 * Adaptive Processor Class for WordPress Auto-Tag Pro
 * Dynamically adjusts processing parameters based on server resources, memory usage, and performance
 * Provides intelligent batch sizing, timeout management, and resource-aware processing
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_AdaptiveProcessor {
    
    /**
     * Resource monitoring components
     */
    private $memory_monitor = null;
    private $database_optimizer = null;
    
    /**
     * Adaptive processing parameters
     */
    private $processing_profile = [
        'environment' => 'unknown',     // shared, vps, dedicated
        'memory_class' => 'low',        // low, medium, high
        'cpu_performance' => 'slow',    // slow, medium, fast
        'batch_size' => 50,
        'timeout' => 30,
        'max_concurrent' => 1
    ];
    
    /**
     * Performance baselines
     */
    private $baselines = [
        'posts_per_second' => 0,
        'memory_per_post' => 0,
        'db_queries_per_post' => 0,
        'average_processing_time' => 0
    ];
    
    /**
     * Runtime metrics
     */
    private $runtime_metrics = [
        'processed_posts' => 0,
        'total_processing_time' => 0,
        'peak_memory_usage' => 0,
        'db_queries_executed' => 0,
        'adaptations_made' => 0,
        'throttle_events' => 0
    ];
    
    /**
     * Server detection cache
     */
    private $server_info = null;
    private $environment_detected = false;
    
    public function __construct() {
        $this->init_components();
        $this->detect_server_environment();
        $this->init_adaptive_processing();
        $this->init_hooks();
    }
    
    /**
     * Initialize monitoring components
     */
    private function init_components() {
        if (function_exists('wp_autotag_pro')) {
            $plugin = wp_autotag_pro();
            $this->memory_monitor = $plugin->memory_monitor ?? null;
            $this->database_optimizer = $plugin->database_optimizer ?? null;
        }
    }
    
    /**
     * Detect server environment and capabilities
     */
    private function detect_server_environment() {
        if ($this->environment_detected) {
            return $this->server_info;
        }
        
        $this->server_info = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'memory_limit_bytes' => $this->convert_to_bytes(ini_get('memory_limit')),
            'max_execution_time' => ini_get('max_execution_time'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'php_sapi' => php_sapi_name(),
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status(),
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'multisite' => is_multisite(),
            'plugin_count' => count(get_option('active_plugins', [])),
            'theme_name' => get_template()
        ];
        
        // Detect environment type
        $this->detect_environment_type();
        
        // Classify memory availability
        $this->classify_memory_availability();
        
        // Estimate CPU performance
        $this->estimate_cpu_performance();
        
        $this->environment_detected = true;
        
        $this->log_info('Server environment detected: ' . json_encode([
            'environment' => $this->processing_profile['environment'],
            'memory_class' => $this->processing_profile['memory_class'],
            'cpu_performance' => $this->processing_profile['cpu_performance']
        ]));
        
        return $this->server_info;
    }
    
    /**
     * Detect hosting environment type
     */
    private function detect_environment_type() {
        $memory_limit = $this->server_info['memory_limit_bytes'];
        $server_software = strtolower($this->server_info['server_software']);
        
        // Shared hosting indicators
        if ($memory_limit <= 128 * 1024 * 1024 || // 128MB or less
            strpos($server_software, 'shared') !== false ||
            $this->server_info['max_execution_time'] <= 30) {
            $this->processing_profile['environment'] = 'shared';
        }
        // VPS indicators
        elseif ($memory_limit <= 1024 * 1024 * 1024 || // 1GB or less
                 strpos($server_software, 'apache') !== false ||
                 strpos($server_software, 'nginx') !== false) {
            $this->processing_profile['environment'] = 'vps';
        }
        // Dedicated server indicators
        else {
            $this->processing_profile['environment'] = 'dedicated';
        }
    }
    
    /**
     * Classify memory availability
     */
    private function classify_memory_availability() {
        $memory_limit = $this->server_info['memory_limit_bytes'];
        
        if ($memory_limit <= 128 * 1024 * 1024) { // 128MB or less
            $this->processing_profile['memory_class'] = 'low';
        } elseif ($memory_limit <= 512 * 1024 * 1024) { // 512MB or less
            $this->processing_profile['memory_class'] = 'medium';
        } else {
            $this->processing_profile['memory_class'] = 'high';
        }
    }
    
    /**
     * Estimate CPU performance through micro-benchmarks
     */
    private function estimate_cpu_performance() {
        $start_time = microtime(true);
        
        // Simple CPU benchmark - string operations and loops
        $test_string = str_repeat('WordPress Auto-Tag Pro Performance Test ', 1000);
        for ($i = 0; $i < 1000; $i++) {
            $normalized = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $test_string));
            $words = explode(' ', $normalized);
            array_filter($words);
        }
        
        $benchmark_time = microtime(true) - $start_time;
        
        // Classify performance based on benchmark
        if ($benchmark_time > 0.5) { // Slow
            $this->processing_profile['cpu_performance'] = 'slow';
        } elseif ($benchmark_time > 0.2) { // Medium
            $this->processing_profile['cpu_performance'] = 'medium';
        } else { // Fast
            $this->processing_profile['cpu_performance'] = 'fast';
        }
        
        $this->log_info("CPU benchmark completed in {$benchmark_time}s - classified as: {$this->processing_profile['cpu_performance']}");
    }
    
    /**
     * Initialize adaptive processing parameters
     */
    private function init_adaptive_processing() {
        // Set initial parameters based on detected environment
        $this->adjust_processing_parameters();
    }
    
    /**
     * Adjust processing parameters based on current conditions
     */
    private function adjust_processing_parameters() {
        $previous_batch_size = $this->processing_profile['batch_size'];
        
        // Base parameters on environment type
        switch ($this->processing_profile['environment']) {
            case 'shared':
                $base_batch_size = 25;
                $base_timeout = 20;
                $max_concurrent = 1;
                break;
            case 'vps':
                $base_batch_size = 75;
                $base_timeout = 45;
                $max_concurrent = 2;
                break;
            case 'dedicated':
                $base_batch_size = 150;
                $base_timeout = 120;
                $max_concurrent = 3;
                break;
            default:
                $base_batch_size = 50;
                $base_timeout = 30;
                $max_concurrent = 1;
        }
        
        // Adjust for memory class
        $memory_multiplier = 1.0;
        switch ($this->processing_profile['memory_class']) {
            case 'low':
                $memory_multiplier = 0.6;
                break;
            case 'medium':
                $memory_multiplier = 1.0;
                break;
            case 'high':
                $memory_multiplier = 1.5;
                break;
        }
        
        // Adjust for CPU performance
        $cpu_multiplier = 1.0;
        switch ($this->processing_profile['cpu_performance']) {
            case 'slow':
                $cpu_multiplier = 0.7;
                break;
            case 'medium':
                $cpu_multiplier = 1.0;
                break;
            case 'fast':
                $cpu_multiplier = 1.3;
                break;
        }
        
        // Apply memory monitor feedback
        if ($this->memory_monitor) {
            $memory_status = $this->memory_monitor->get_memory_status();
            switch ($memory_status) {
                case 'emergency':
                    $memory_multiplier *= 0.2;
                    break;
                case 'critical':
                    $memory_multiplier *= 0.4;
                    break;
                case 'warning':
                    $memory_multiplier *= 0.7;
                    break;
            }
        }
        
        // Calculate final parameters
        $this->processing_profile['batch_size'] = max(5, intval($base_batch_size * $memory_multiplier * $cpu_multiplier));
        $this->processing_profile['timeout'] = max(5, intval($base_timeout * $cpu_multiplier));
        $this->processing_profile['max_concurrent'] = $max_concurrent;
        
        // Track adaptations
        if ($previous_batch_size !== $this->processing_profile['batch_size']) {
            $this->runtime_metrics['adaptations_made']++;
            $this->log_info("Adapted batch size from {$previous_batch_size} to {$this->processing_profile['batch_size']}");
        }
    }
    
    /**
     * Initialize hooks for dynamic adaptation
     */
    private function init_hooks() {
        // Monitor processing performance
        add_action('autotag_processing_start', [$this, 'on_processing_start']);
        add_action('autotag_processing_end', [$this, 'on_processing_end']);
        add_action('autotag_batch_complete', [$this, 'on_batch_complete']);
        
        // Provide adaptive parameters
        add_filter('autotag_batch_size', [$this, 'get_adaptive_batch_size'], 20, 2);
        add_filter('autotag_processing_timeout', [$this, 'get_adaptive_timeout'], 20, 2);
        add_filter('autotag_max_concurrent', [$this, 'get_max_concurrent'], 20, 2);
        
        // Emergency throttling
        add_action('autotag_memory_warning', [$this, 'on_memory_warning']);
        add_action('autotag_performance_issue', [$this, 'on_performance_issue']);
    }
    
    /**
     * Get adaptive batch size
     */
    public function get_adaptive_batch_size($default_size = 50, $context = '') {
        // Real-time memory check
        if ($this->memory_monitor && $this->memory_monitor->is_memory_limit_approaching(0.75)) {
            return max(5, intval($this->processing_profile['batch_size'] / 2));
        }
        
        return $this->processing_profile['batch_size'];
    }
    
    /**
     * Get adaptive timeout
     */
    public function get_adaptive_timeout($default_timeout = 30, $context = '') {
        return $this->processing_profile['timeout'];
    }
    
    /**
     * Get maximum concurrent operations
     */
    public function get_max_concurrent($default_concurrent = 1, $context = '') {
        return $this->processing_profile['max_concurrent'];
    }
    
    /**
     * Monitor processing start
     */
    public function on_processing_start($data) {
        $this->runtime_metrics['processing_start_time'] = microtime(true);
        
        // Check if we need to throttle
        if ($this->should_throttle_processing()) {
            $this->apply_throttling();
        }
    }
    
    /**
     * Monitor processing end
     */
    public function on_processing_end($data) {
        if (isset($this->runtime_metrics['processing_start_time'])) {
            $processing_time = microtime(true) - $this->runtime_metrics['processing_start_time'];
            $this->runtime_metrics['total_processing_time'] += $processing_time;
            
            // Update performance baselines
            $this->update_performance_baselines($data, $processing_time);
            
            // Adjust parameters based on performance
            $this->adjust_based_on_performance($processing_time);
        }
    }
    
    /**
     * Monitor batch completion
     */
    public function on_batch_complete($batch_data) {
        $posts_processed = $batch_data['processed'] ?? 0;
        $this->runtime_metrics['processed_posts'] += $posts_processed;
        
        // Update peak memory usage
        $current_memory = memory_get_usage(true);
        if ($current_memory > $this->runtime_metrics['peak_memory_usage']) {
            $this->runtime_metrics['peak_memory_usage'] = $current_memory;
        }
        
        // Periodic re-evaluation of parameters
        if ($this->runtime_metrics['processed_posts'] % 100 === 0) {
            $this->adjust_processing_parameters();
        }
    }
    
    /**
     * Handle memory warnings
     */
    public function on_memory_warning($memory_data) {
        $this->runtime_metrics['throttle_events']++;
        
        // Aggressive batch size reduction
        $this->processing_profile['batch_size'] = max(5, intval($this->processing_profile['batch_size'] / 3));
        
        $this->log_warning('Memory warning detected - reduced batch size to ' . $this->processing_profile['batch_size']);
    }
    
    /**
     * Handle performance issues
     */
    public function on_performance_issue($performance_data) {
        $this->runtime_metrics['throttle_events']++;
        
        // Reduce processing parameters
        $this->processing_profile['batch_size'] = max(10, intval($this->processing_profile['batch_size'] * 0.8));
        $this->processing_profile['timeout'] = max(5, intval($this->processing_profile['timeout'] * 0.8));
        
        $this->log_warning('Performance issue detected - reduced processing parameters');
    }
    
    /**
     * Check if processing should be throttled
     */
    private function should_throttle_processing() {
        // Memory-based throttling
        if ($this->memory_monitor) {
            $memory_status = $this->memory_monitor->get_memory_status();
            if (in_array($memory_status, ['critical', 'emergency'])) {
                return true;
            }
        }
        
        // CPU load-based throttling (if available)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load[0] > 2.0) { // High CPU load
                return true;
            }
        }
        
        // Database performance-based throttling
        if ($this->database_optimizer) {
            $db_metrics = $this->database_optimizer->get_performance_metrics();
            if ($db_metrics['queries_executed'] > 1000) { // High query volume
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Apply throttling measures
     */
    private function apply_throttling() {
        $this->processing_profile['batch_size'] = max(3, intval($this->processing_profile['batch_size'] / 4));
        $this->processing_profile['timeout'] = max(5, intval($this->processing_profile['timeout'] / 2));
        
        // Add processing delay
        sleep(1);
        
        $this->runtime_metrics['throttle_events']++;
        $this->log_warning('Throttling applied - batch size: ' . $this->processing_profile['batch_size']);
    }
    
    /**
     * Update performance baselines
     */
    private function update_performance_baselines($data, $processing_time) {
        $posts_processed = $data['processed'] ?? 1;
        
        // Posts per second
        $posts_per_second = $posts_processed / max($processing_time, 0.001);
        $this->baselines['posts_per_second'] = $this->calculate_moving_average(
            $this->baselines['posts_per_second'], 
            $posts_per_second
        );
        
        // Average processing time
        $this->baselines['average_processing_time'] = $this->calculate_moving_average(
            $this->baselines['average_processing_time'],
            $processing_time
        );
        
        // Memory per post
        if ($posts_processed > 0) {
            $memory_per_post = memory_get_usage(true) / $posts_processed;
            $this->baselines['memory_per_post'] = $this->calculate_moving_average(
                $this->baselines['memory_per_post'],
                $memory_per_post
            );
        }
    }
    
    /**
     * Adjust parameters based on performance
     */
    private function adjust_based_on_performance($processing_time) {
        // If processing is consistently fast, increase batch size
        if ($processing_time < ($this->baselines['average_processing_time'] * 0.7)) {
            $this->processing_profile['batch_size'] = min(200, intval($this->processing_profile['batch_size'] * 1.1));
        }
        // If processing is consistently slow, decrease batch size
        elseif ($processing_time > ($this->baselines['average_processing_time'] * 1.5)) {
            $this->processing_profile['batch_size'] = max(5, intval($this->processing_profile['batch_size'] * 0.9));
        }
    }
    
    /**
     * Calculate moving average
     */
    private function calculate_moving_average($current_average, $new_value, $weight = 0.2) {
        if ($current_average === 0) {
            return $new_value;
        }
        
        return ($current_average * (1 - $weight)) + ($new_value * $weight);
    }
    
    /**
     * Get current processing profile
     */
    public function get_processing_profile() {
        return $this->processing_profile;
    }
    
    /**
     * Get performance metrics
     */
    public function get_performance_metrics() {
        return array_merge($this->runtime_metrics, [
            'baselines' => $this->baselines,
            'processing_profile' => $this->processing_profile,
            'server_info' => $this->server_info
        ]);
    }
    
    /**
     * Get server information
     */
    public function get_server_info() {
        return $this->server_info;
    }
    
    /**
     * Reset performance metrics
     */
    public function reset_metrics() {
        $this->runtime_metrics = [
            'processed_posts' => 0,
            'total_processing_time' => 0,
            'peak_memory_usage' => 0,
            'db_queries_executed' => 0,
            'adaptations_made' => 0,
            'throttle_events' => 0
        ];
        
        $this->baselines = [
            'posts_per_second' => 0,
            'memory_per_post' => 0,
            'db_queries_per_post' => 0,
            'average_processing_time' => 0
        ];
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
     * Logging methods
     */
    private function log_info($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_info('[Adaptive Processor] ' . $message);
        }
    }
    
    private function log_warning($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_error('[Adaptive Processor WARNING] ' . $message);
        }
    }
    
    private function log_error($message) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_error('[Adaptive Processor ERROR] ' . $message);
        }
    }
}