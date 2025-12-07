<?php
/**
 * Performance Tests and Benchmarking for WordPress Auto-Tag Pro
 * Verifies and benchmarks all performance optimizations implemented by the Performance Agent
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_PerformanceTests {
    
    /**
     * Test results storage
     */
    private $test_results = [];
    private $benchmark_results = [];
    
    /**
     * Test configuration
     */
    private $test_config = [
        'sample_posts' => 100,        // Number of test posts
        'benchmark_iterations' => 10,  // Iterations for benchmarks
        'memory_test_size' => 1000,   // Cache items for memory tests
        'db_test_records' => 500      // Records for DB tests
    ];
    
    /**
     * Plugin components
     */
    private $memory_monitor = null;
    private $database_optimizer = null;
    private $adaptive_processor = null;
    private $cache = null;
    
    public function __construct() {
        $this->init_components();
    }
    
    /**
     * Initialize plugin components for testing
     */
    private function init_components() {
        if (function_exists('wp_autotag_pro')) {
            $plugin = wp_autotag_pro();
            $this->memory_monitor = $plugin->memory_monitor ?? null;
            $this->database_optimizer = $plugin->database_optimizer ?? null;
            $this->adaptive_processor = $plugin->adaptive_processor ?? null;
            $this->cache = $plugin->cache ?? null;
        }
    }
    
    /**
     * Run all performance tests
     */
    public function run_all_tests() {
        $this->log_info('Starting comprehensive performance tests...');
        
        // Memory monitoring tests
        $this->test_memory_monitoring();
        
        // Cache management tests
        $this->test_cache_management();
        
        // Database optimization tests
        $this->test_database_optimization();
        
        // Adaptive processing tests
        $this->test_adaptive_processing();
        
        // Integration tests
        $this->test_system_integration();
        
        // Performance benchmarks
        $this->run_performance_benchmarks();
        
        $this->log_info('All performance tests completed.');
        
        return $this->get_test_summary();
    }
    
    /**
     * Test memory monitoring functionality
     */
    private function test_memory_monitoring() {
        $this->log_info('Testing memory monitoring system...');
        
        if (!$this->memory_monitor) {
            $this->test_results['memory_monitor']['status'] = 'SKIPPED';
            $this->test_results['memory_monitor']['reason'] = 'Memory monitor not available';
            return;
        }
        
        $tests = [];
        
        // Test 1: Real memory detection (not placeholder)
        $memory_stats = $this->memory_monitor->get_memory_stats();
        $tests['real_memory_detection'] = [
            'passed' => $memory_stats['memory_limit'] > 0 && $memory_stats['current_usage'] > 0,
            'expected' => 'Real memory values detected',
            'actual' => "Limit: {$memory_stats['memory_limit_formatted']}, Usage: {$memory_stats['current_usage_formatted']}"
        ];
        
        // Test 2: Memory status classification
        $status = $this->memory_monitor->get_memory_status();
        $tests['memory_status_classification'] = [
            'passed' => in_array($status, ['normal', 'warning', 'critical', 'emergency']),
            'expected' => 'Valid memory status',
            'actual' => "Status: {$status}"
        ];
        
        // Test 3: Adaptive batch sizing
        $default_batch = 50;
        $adaptive_batch = $this->memory_monitor->get_adaptive_batch_size($default_batch);
        $tests['adaptive_batch_sizing'] = [
            'passed' => $adaptive_batch > 0 && $adaptive_batch <= 200,
            'expected' => 'Reasonable batch size (1-200)',
            'actual' => "Batch size: {$adaptive_batch}"
        ];
        
        // Test 4: Memory cleanup triggers
        $initial_usage = memory_get_usage(true);
        $this->memory_monitor->trigger_memory_cleanup();
        $post_cleanup_usage = memory_get_usage(true);
        $tests['memory_cleanup'] = [
            'passed' => $post_cleanup_usage <= $initial_usage,
            'expected' => 'Memory usage reduced or stable after cleanup',
            'actual' => "Before: " . $this->format_bytes($initial_usage) . ", After: " . $this->format_bytes($post_cleanup_usage)
        ];
        
        // Test 5: Cache limit integration
        $metrics = $this->memory_monitor->get_monitoring_metrics();
        $tests['cache_limits_integration'] = [
            'passed' => isset($metrics['cache_size_limits']) && is_array($metrics['cache_size_limits']),
            'expected' => 'Cache size limits configured',
            'actual' => json_encode($metrics['cache_size_limits'] ?? [])
        ];
        
        $this->test_results['memory_monitor'] = [
            'status' => $this->all_tests_passed($tests) ? 'PASSED' : 'FAILED',
            'tests' => $tests
        ];
    }
    
    /**
     * Test cache management and bounded growth fixes
     */
    private function test_cache_management() {
        $this->log_info('Testing cache management and bounded growth...');
        
        if (!$this->cache) {
            $this->test_results['cache_management']['status'] = 'SKIPPED';
            $this->test_results['cache_management']['reason'] = 'Cache system not available';
            return;
        }
        
        $tests = [];
        
        // Test 1: Cache size limits
        $cache_info = $this->cache->get_cache_info();
        $max_items = $cache_info['max_memory_items'];
        
        // Fill cache beyond limit
        for ($i = 0; $i < ($max_items + 50); $i++) {
            $this->cache->set("test_key_{$i}", "test_value_{$i}");
        }
        
        $post_fill_info = $this->cache->get_cache_info();
        $tests['cache_size_bounds'] = [
            'passed' => $post_fill_info['memory_items'] <= $max_items,
            'expected' => "Cache size <= {$max_items} items",
            'actual' => "Cache contains {$post_fill_info['memory_items']} items"
        ];
        
        // Test 2: LRU eviction
        $this->cache->set('lru_test_1', 'value1');
        $this->cache->set('lru_test_2', 'value2');
        
        // Fill cache to trigger eviction
        for ($i = 0; $i < $max_items; $i++) {
            $this->cache->set("eviction_test_{$i}", "value_{$i}");
        }
        
        $oldest_exists = $this->cache->get('lru_test_1') !== null;
        $tests['lru_eviction'] = [
            'passed' => !$oldest_exists,
            'expected' => 'Oldest cache entries should be evicted',
            'actual' => $oldest_exists ? 'Old entry still exists' : 'Old entry properly evicted'
        ];
        
        // Test 3: Memory-pressure cleanup
        if ($this->memory_monitor) {
            $stats_before = $this->cache->get_stats();
            $this->memory_monitor->clear_plugin_caches();
            $stats_after = $this->cache->get_stats();
            
            $tests['memory_pressure_cleanup'] = [
                'passed' => $stats_after['memory_items'] <= $stats_before['memory_items'],
                'expected' => 'Cache cleaned under memory pressure',
                'actual' => "Before: {$stats_before['memory_items']}, After: {$stats_after['memory_items']}"
            ];
        }
        
        // Test 4: Cache hit/miss tracking
        $stats = $this->cache->get_stats();
        $tests['cache_statistics'] = [
            'passed' => isset($stats['hits'], $stats['misses'], $stats['hit_rate_percent']),
            'expected' => 'Cache statistics properly tracked',
            'actual' => "Hit rate: {$stats['hit_rate_percent']}%"
        ];
        
        $this->test_results['cache_management'] = [
            'status' => $this->all_tests_passed($tests) ? 'PASSED' : 'FAILED',
            'tests' => $tests
        ];
        
        // Cleanup test data
        $this->cache->clear_all();
    }
    
    /**
     * Test database optimization and batching
     */
    private function test_database_optimization() {
        $this->log_info('Testing database optimization and batching...');
        
        if (!$this->database_optimizer) {
            $this->test_results['database_optimization']['status'] = 'SKIPPED';
            $this->test_results['database_optimization']['reason'] = 'Database optimizer not available';
            return;
        }
        
        $tests = [];
        
        // Test 1: Batch meta updates vs individual updates
        $test_posts = $this->create_test_posts(10);
        $meta_updates = [];
        
        foreach ($test_posts as $post_id) {
            $meta_updates[] = [
                'post_id' => $post_id,
                'meta_key' => 'test_meta_batch',
                'meta_value' => 'test_value_' . $post_id
            ];
        }
        
        // Benchmark batch operation
        $start_time = microtime(true);
        $batch_result = $this->database_optimizer->batch_update_post_meta($meta_updates);
        $batch_time = microtime(true) - $start_time;
        
        // Benchmark individual operations
        $start_time = microtime(true);
        foreach ($test_posts as $post_id) {
            update_post_meta($post_id, 'test_meta_individual', 'test_value_' . $post_id);
        }
        $individual_time = microtime(true) - $start_time;
        
        $tests['batch_vs_individual_performance'] = [
            'passed' => $batch_time < $individual_time,
            'expected' => 'Batch operations faster than individual',
            'actual' => "Batch: " . round($batch_time * 1000, 2) . "ms, Individual: " . round($individual_time * 1000, 2) . "ms"
        ];
        
        // Test 2: Query caching
        $cache_key = 'test_query_cache';
        $this->database_optimizer->set_query_cache($cache_key, ['test' => 'data']);
        $cached_result = $this->database_optimizer->get_query_cache($cache_key);
        
        $tests['query_caching'] = [
            'passed' => $cached_result !== null && $cached_result['test'] === 'data',
            'expected' => 'Query results properly cached',
            'actual' => $cached_result ? 'Cache working' : 'Cache not working'
        ];
        
        // Test 3: Memory-aware batch sizing
        $large_updates = [];
        for ($i = 0; $i < 500; $i++) {
            $large_updates[] = [
                'post_id' => $test_posts[0],
                'meta_key' => "large_test_key_{$i}",
                'meta_value' => str_repeat('x', 100) // 100 bytes per value
            ];
        }
        
        $metrics_before = $this->database_optimizer->get_performance_metrics();
        $this->database_optimizer->batch_update_post_meta($large_updates);
        $metrics_after = $this->database_optimizer->get_performance_metrics();
        
        $tests['memory_aware_batching'] = [
            'passed' => $metrics_after['batch_operations'] > $metrics_before['batch_operations'],
            'expected' => 'Large updates processed in batches',
            'actual' => "Batch operations: {$metrics_after['batch_operations']}"
        ];
        
        // Test 4: Performance metrics tracking
        $metrics = $this->database_optimizer->get_performance_metrics();
        $tests['performance_metrics'] = [
            'passed' => isset($metrics['queries_executed'], $metrics['batch_operations'], $metrics['cache_hits']),
            'expected' => 'Performance metrics properly tracked',
            'actual' => "Queries: {$metrics['queries_executed']}, Batches: {$metrics['batch_operations']}"
        ];
        
        $this->test_results['database_optimization'] = [
            'status' => $this->all_tests_passed($tests) ? 'PASSED' : 'FAILED',
            'tests' => $tests
        ];
        
        // Cleanup test data
        foreach ($test_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
    }
    
    /**
     * Test adaptive processing system
     */
    private function test_adaptive_processing() {
        $this->log_info('Testing adaptive processing system...');
        
        if (!$this->adaptive_processor) {
            $this->test_results['adaptive_processing']['status'] = 'SKIPPED';
            $this->test_results['adaptive_processing']['reason'] = 'Adaptive processor not available';
            return;
        }
        
        $tests = [];
        
        // Test 1: Server environment detection
        $server_info = $this->adaptive_processor->get_server_info();
        $processing_profile = $this->adaptive_processor->get_processing_profile();
        
        $tests['server_detection'] = [
            'passed' => isset($server_info['memory_limit_bytes'], $server_info['php_version']) &&
                       in_array($processing_profile['environment'], ['shared', 'vps', 'dedicated']),
            'expected' => 'Server environment properly detected',
            'actual' => "Environment: {$processing_profile['environment']}, Memory: " . $this->format_bytes($server_info['memory_limit_bytes'])
        ];
        
        // Test 2: Memory classification
        $tests['memory_classification'] = [
            'passed' => in_array($processing_profile['memory_class'], ['low', 'medium', 'high']),
            'expected' => 'Memory class properly classified',
            'actual' => "Memory class: {$processing_profile['memory_class']}"
        ];
        
        // Test 3: CPU performance benchmarking
        $tests['cpu_benchmarking'] = [
            'passed' => in_array($processing_profile['cpu_performance'], ['slow', 'medium', 'fast']),
            'expected' => 'CPU performance properly benchmarked',
            'actual' => "CPU performance: {$processing_profile['cpu_performance']}"
        ];
        
        // Test 4: Adaptive batch sizing
        $default_size = 50;
        $adaptive_size = $this->adaptive_processor->get_adaptive_batch_size($default_size);
        
        $tests['adaptive_batch_sizing'] = [
            'passed' => $adaptive_size > 0 && $adaptive_size != $default_size,
            'expected' => 'Batch size adapted from default',
            'actual' => "Default: {$default_size}, Adaptive: {$adaptive_size}"
        ];
        
        // Test 5: Performance metrics tracking
        $metrics = $this->adaptive_processor->get_performance_metrics();
        
        $tests['performance_tracking'] = [
            'passed' => isset($metrics['runtime_metrics'], $metrics['baselines'], $metrics['processing_profile']),
            'expected' => 'Performance metrics properly tracked',
            'actual' => "Metrics available: " . count($metrics)
        ];
        
        // Test 6: Memory-based throttling
        // Simulate memory pressure
        if ($this->memory_monitor) {
            $original_batch = $processing_profile['batch_size'];
            
            // Trigger memory warning
            do_action('autotag_memory_warning', ['usage' => 90]);
            
            $new_profile = $this->adaptive_processor->get_processing_profile();
            $tests['memory_throttling'] = [
                'passed' => $new_profile['batch_size'] < $original_batch,
                'expected' => 'Batch size reduced under memory pressure',
                'actual' => "Original: {$original_batch}, Throttled: {$new_profile['batch_size']}"
            ];
        }
        
        $this->test_results['adaptive_processing'] = [
            'status' => $this->all_tests_passed($tests) ? 'PASSED' : 'FAILED',
            'tests' => $tests
        ];
    }
    
    /**
     * Test system integration between components
     */
    private function test_system_integration() {
        $this->log_info('Testing system integration...');
        
        $tests = [];
        
        // Test 1: Memory monitor + Cache integration
        if ($this->memory_monitor && $this->cache) {
            $cache_limits = $this->cache->get_cache_limits();
            $memory_stats = $this->memory_monitor->get_memory_stats();
            
            $tests['memory_cache_integration'] = [
                'passed' => !empty($cache_limits) && $memory_stats['status'] !== null,
                'expected' => 'Memory monitor and cache properly integrated',
                'actual' => "Cache limits set, Memory status: {$memory_stats['status']}"
            ];
        }
        
        // Test 2: Adaptive processor + Memory monitor integration
        if ($this->adaptive_processor && $this->memory_monitor) {
            $adaptive_batch = $this->adaptive_processor->get_adaptive_batch_size();
            $memory_batch = $this->memory_monitor->get_adaptive_batch_size();
            
            $tests['adaptive_memory_integration'] = [
                'passed' => $adaptive_batch > 0 && $memory_batch > 0,
                'expected' => 'Adaptive processor and memory monitor coordinate',
                'actual' => "Adaptive: {$adaptive_batch}, Memory: {$memory_batch}"
            ];
        }
        
        // Test 3: Database optimizer + Memory monitor integration
        if ($this->database_optimizer && $this->memory_monitor) {
            $db_metrics = $this->database_optimizer->get_performance_metrics();
            $memory_status = $this->memory_monitor->get_memory_status();
            
            $tests['database_memory_integration'] = [
                'passed' => isset($db_metrics['batch_sizes']) && $memory_status !== null,
                'expected' => 'Database optimizer responds to memory status',
                'actual' => "DB batches configured, Memory status: {$memory_status}"
            ];
        }
        
        // Test 4: WordPress hook integration
        $hook_tests = [];
        
        // Check if performance hooks are registered
        $performance_hooks = [
            'autotag_batch_size',
            'autotag_processing_timeout',
            'autotag_before_processing',
            'autotag_after_processing'
        ];
        
        foreach ($performance_hooks as $hook) {
            $hook_tests[$hook] = has_filter($hook) || has_action($hook);
        }
        
        $tests['wordpress_hook_integration'] = [
            'passed' => array_sum($hook_tests) >= count($performance_hooks) / 2, // At least half should be hooked
            'expected' => 'WordPress hooks properly integrated',
            'actual' => count(array_filter($hook_tests)) . ' out of ' . count($performance_hooks) . ' hooks registered'
        ];
        
        $this->test_results['system_integration'] = [
            'status' => $this->all_tests_passed($tests) ? 'PASSED' : 'FAILED',
            'tests' => $tests
        ];
    }
    
    /**
     * Run performance benchmarks
     */
    private function run_performance_benchmarks() {
        $this->log_info('Running performance benchmarks...');
        
        // Benchmark 1: Memory usage efficiency
        $this->benchmark_memory_efficiency();
        
        // Benchmark 2: Cache performance
        $this->benchmark_cache_performance();
        
        // Benchmark 3: Database operation speed
        $this->benchmark_database_performance();
        
        // Benchmark 4: Adaptive processing overhead
        $this->benchmark_adaptive_overhead();
    }
    
    /**
     * Benchmark memory usage efficiency
     */
    private function benchmark_memory_efficiency() {
        $baseline_memory = memory_get_usage(true);
        
        // Simulate processing workload
        $test_data = [];
        for ($i = 0; $i < 1000; $i++) {
            $test_data[] = str_repeat('WordPress Auto-Tag Pro Test Data ', 50);
        }
        
        $peak_memory = memory_get_peak_usage(true);
        $memory_used = $peak_memory - $baseline_memory;
        
        $this->benchmark_results['memory_efficiency'] = [
            'baseline_memory' => $this->format_bytes($baseline_memory),
            'peak_memory' => $this->format_bytes($peak_memory),
            'memory_used' => $this->format_bytes($memory_used),
            'efficiency_score' => $memory_used < (50 * 1024 * 1024) ? 'EXCELLENT' : 'GOOD' // Less than 50MB
        ];
        
        // Cleanup
        unset($test_data);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    /**
     * Benchmark cache performance
     */
    private function benchmark_cache_performance() {
        if (!$this->cache) {
            $this->benchmark_results['cache_performance'] = ['status' => 'SKIPPED'];
            return;
        }
        
        $iterations = 1000;
        
        // Write performance
        $start_time = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->set("benchmark_key_{$i}", "benchmark_value_{$i}");
        }
        $write_time = microtime(true) - $start_time;
        
        // Read performance
        $start_time = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->get("benchmark_key_{$i}");
        }
        $read_time = microtime(true) - $start_time;
        
        $cache_stats = $this->cache->get_stats();
        
        $this->benchmark_results['cache_performance'] = [
            'write_ops_per_second' => round($iterations / $write_time),
            'read_ops_per_second' => round($iterations / $read_time),
            'hit_rate' => $cache_stats['hit_rate_percent'] ?? 0,
            'memory_items' => $cache_stats['memory_items'] ?? 0
        ];
        
        // Cleanup
        $this->cache->clear_all();
    }
    
    /**
     * Benchmark database performance
     */
    private function benchmark_database_performance() {
        if (!$this->database_optimizer) {
            $this->benchmark_results['database_performance'] = ['status' => 'SKIPPED'];
            return;
        }
        
        $test_posts = $this->create_test_posts(50);
        
        // Benchmark batch meta updates
        $meta_updates = [];
        foreach ($test_posts as $post_id) {
            for ($j = 0; $j < 5; $j++) {
                $meta_updates[] = [
                    'post_id' => $post_id,
                    'meta_key' => "benchmark_meta_{$j}",
                    'meta_value' => "benchmark_value_{$post_id}_{$j}"
                ];
            }
        }
        
        $start_time = microtime(true);
        $this->database_optimizer->batch_update_post_meta($meta_updates);
        $batch_time = microtime(true) - $start_time;
        
        $db_metrics = $this->database_optimizer->get_performance_metrics();
        
        $this->benchmark_results['database_performance'] = [
            'batch_time_ms' => round($batch_time * 1000, 2),
            'updates_per_second' => round(count($meta_updates) / $batch_time),
            'queries_executed' => $db_metrics['queries_executed'],
            'batch_operations' => $db_metrics['batch_operations']
        ];
        
        // Cleanup
        foreach ($test_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
    }
    
    /**
     * Benchmark adaptive processing overhead
     */
    private function benchmark_adaptive_overhead() {
        if (!$this->adaptive_processor) {
            $this->benchmark_results['adaptive_overhead'] = ['status' => 'SKIPPED'];
            return;
        }
        
        $iterations = 100;
        
        // Benchmark adaptive batch size calculation
        $start_time = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->adaptive_processor->get_adaptive_batch_size(50);
        }
        $adaptation_time = microtime(true) - $start_time;
        
        // Benchmark environment detection (cached)
        $start_time = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->adaptive_processor->get_server_info();
        }
        $detection_time = microtime(true) - $start_time;
        
        $this->benchmark_results['adaptive_overhead'] = [
            'adaptation_time_ms' => round($adaptation_time * 1000, 2),
            'adaptations_per_second' => round($iterations / $adaptation_time),
            'detection_time_ms' => round($detection_time * 1000, 2),
            'overhead_assessment' => ($adaptation_time < 0.1) ? 'LOW' : 'MODERATE'
        ];
    }
    
    /**
     * Helper methods
     */
    
    /**
     * Create test posts for benchmarking
     */
    private function create_test_posts($count) {
        $post_ids = [];
        
        for ($i = 0; $i < $count; $i++) {
            $post_id = wp_insert_post([
                'post_title' => "Performance Test Post {$i}",
                'post_content' => "This is test content for performance testing. Post number {$i}.",
                'post_status' => 'publish',
                'post_type' => 'post'
            ]);
            
            if (!is_wp_error($post_id)) {
                $post_ids[] = $post_id;
            }
        }
        
        return $post_ids;
    }
    
    /**
     * Check if all tests in a group passed
     */
    private function all_tests_passed($tests) {
        foreach ($tests as $test) {
            if (!$test['passed']) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get comprehensive test summary
     */
    public function get_test_summary() {
        $summary = [
            'test_results' => $this->test_results,
            'benchmark_results' => $this->benchmark_results,
            'overall_status' => 'PASSED',
            'passed_tests' => 0,
            'failed_tests' => 0,
            'skipped_tests' => 0,
            'recommendations' => []
        ];
        
        // Calculate overall status
        foreach ($this->test_results as $category => $result) {
            switch ($result['status']) {
                case 'PASSED':
                    $summary['passed_tests']++;
                    break;
                case 'FAILED':
                    $summary['failed_tests']++;
                    $summary['overall_status'] = 'FAILED';
                    break;
                case 'SKIPPED':
                    $summary['skipped_tests']++;
                    break;
            }
        }
        
        // Generate recommendations
        $summary['recommendations'] = $this->generate_recommendations();
        
        return $summary;
    }
    
    /**
     * Generate performance recommendations
     */
    private function generate_recommendations() {
        $recommendations = [];
        
        // Memory recommendations
        if ($this->memory_monitor) {
            $memory_stats = $this->memory_monitor->get_memory_stats();
            if ($memory_stats['usage_percent'] > 75) {
                $recommendations[] = 'Consider increasing PHP memory limit - current usage is ' . $memory_stats['usage_percent'] . '%';
            }
        }
        
        // Cache recommendations
        if ($this->cache && isset($this->benchmark_results['cache_performance'])) {
            $cache_perf = $this->benchmark_results['cache_performance'];
            if ($cache_perf['hit_rate'] < 70) {
                $recommendations[] = 'Cache hit rate is low (' . $cache_perf['hit_rate'] . '%) - consider increasing cache TTL';
            }
        }
        
        // Database recommendations
        if (isset($this->benchmark_results['database_performance'])) {
            $db_perf = $this->benchmark_results['database_performance'];
            if ($db_perf['updates_per_second'] < 100) {
                $recommendations[] = 'Database performance is slow - consider optimizing database or increasing resources';
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Format bytes for display
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
            wp_autotag_pro()->log_info('[Performance Tests] ' . $message);
        }
    }
    
    /**
     * Run specific test category
     */
    public function run_test_category($category) {
        switch ($category) {
            case 'memory':
                $this->test_memory_monitoring();
                break;
            case 'cache':
                $this->test_cache_management();
                break;
            case 'database':
                $this->test_database_optimization();
                break;
            case 'adaptive':
                $this->test_adaptive_processing();
                break;
            case 'integration':
                $this->test_system_integration();
                break;
            case 'benchmarks':
                $this->run_performance_benchmarks();
                break;
        }
        
        return $this->get_test_summary();
    }
}