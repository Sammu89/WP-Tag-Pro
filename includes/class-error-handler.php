<?php
/**
 * Error Handler Class
 * Provides centralized error handling with user-friendly messages
 * Integrates with custom exception classes for better error reporting
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_ErrorHandler {
    
    /**
     * Error logging enabled
     */
    private $logging_enabled = true;
    
    /**
     * Debug mode flag
     */
    private $debug_mode = false;
    
    /**
     * Error statistics
     */
    private $error_stats = [
        'total_errors' => 0,
        'error_types' => [],
        'recent_errors' => []
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logging_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        $this->debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        
        // Hook into WordPress error handling
        if (function_exists('add_action')) {
            add_action('wp_ajax_nopriv_autotag_error_report', [$this, 'handle_ajax_error_report']);
            add_action('wp_ajax_autotag_error_report', [$this, 'handle_ajax_error_report']);
        }
    }
    
    /**
     * Handle AJAX errors with user-friendly responses
     */
    public function handle_ajax_error($exception, $additional_data = []) {
        // Ensure we have a proper exception
        if (!($exception instanceof Exception)) {
            $exception = new AutoTag_Exception('Unknown error occurred', 0, null, ['data' => $exception]);
        }
        
        // Convert generic exceptions to our base exception
        if (!($exception instanceof AutoTag_Exception)) {
            $exception = new AutoTag_Exception($exception->getMessage(), $exception->getCode(), $exception);
        }
        
        // Log the error for debugging
        $this->log_error($exception, $additional_data);
        
        // Prepare user response based on exception type
        $response = $this->prepare_user_response($exception, $additional_data);
        
        // Send JSON error response
        if (function_exists('wp_send_json_error')) {
            wp_send_json_error($response);
        } else {
            // Fallback for testing
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'data' => $response]);
            exit;
        }
    }
    
    /**
     * Handle general errors (non-AJAX)
     */
    public function handle_error($exception, $context = 'general') {
        // Ensure we have a proper exception
        if (!($exception instanceof Exception)) {
            $exception = new AutoTag_Exception('Unknown error occurred', 0, null, ['data' => $exception]);
        }
        
        // Convert generic exceptions to our base exception
        if (!($exception instanceof AutoTag_Exception)) {
            $exception = new AutoTag_Exception($exception->getMessage(), $exception->getCode(), $exception);
        }
        
        // Log the error
        $this->log_error($exception, ['context' => $context]);
        
        // Update error statistics
        $this->update_error_stats($exception);
        
        // Return formatted error info for display
        return $this->prepare_user_response($exception);
    }
    
    /**
     * Prepare user-friendly response based on exception type
     */
    private function prepare_user_response($exception, $additional_data = []) {
        $response = [
            'message' => $exception->getUserMessage(),
            'suggestions' => $exception->getSuggestions(),
            'error_code' => $exception->getErrorCode(),
            'timestamp' => current_time('mysql'),
        ];
        
        // Add validation errors if available
        if ($exception instanceof AutoTag_ValidationException) {
            $response['validation_errors'] = $exception->getValidationErrors();
        }
        
        // Add debug information if in debug mode
        if ($this->debug_mode) {
            $response['debug'] = [
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'context' => $exception->getContext(),
                'additional_data' => $additional_data
            ];
        }
        
        // Add specific handling for different exception types
        if ($exception instanceof AutoTag_MemoryException) {
            $response['memory_info'] = [
                'current_usage' => $this->format_bytes(memory_get_usage(true)),
                'peak_usage' => $this->format_bytes(memory_get_peak_usage(true)),
                'limit' => ini_get('memory_limit')
            ];
        }
        
        if ($exception instanceof AutoTag_DatabaseException) {
            global $wpdb;
            if (isset($wpdb->last_error) && !empty($wpdb->last_error)) {
                if ($this->debug_mode) {
                    $response['debug']['db_error'] = $wpdb->last_error;
                }
                // Don't expose DB errors to regular users
                $response['suggestions'][] = 'Database connectivity issue detected. Please contact support.';
            }
        }
        
        return $response;
    }
    
    /**
     * Log error for debugging and monitoring
     */
    private function log_error($exception, $additional_data = []) {
        if (!$this->logging_enabled) {
            return;
        }
        
        $log_data = [
            'timestamp' => current_time('mysql'),
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'user_message' => $exception->getUserMessage(),
            'error_code' => $exception->getErrorCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context' => $exception->getContext(),
            'additional_data' => $additional_data,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        // Add trace in debug mode
        if ($this->debug_mode) {
            $log_data['trace'] = $exception->getTraceAsString();
        }
        
        // Format log message
        $log_message = sprintf(
            '[AutoTag Pro] %s: %s in %s:%d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
        
        // Add context if available
        if (!empty($exception->getContext())) {
            $log_message .= ' | Context: ' . wp_json_encode($exception->getContext());
        }
        
        // Log to WordPress error log
        if (function_exists('error_log')) {
            error_log($log_message);
        }
        
        // Also log to plugin-specific log if enabled
        $this->log_to_plugin_log($log_data);
        
        // Update error statistics
        $this->update_error_stats($exception);
    }
    
    /**
     * Log to plugin-specific log file
     */
    private function log_to_plugin_log($log_data) {
        // Only log to plugin log in debug mode to avoid excessive logging
        if (!$this->debug_mode) {
            return;
        }
        
        $log_file = WP_CONTENT_DIR . '/autotag-pro-errors.log';
        
        // Prepare log entry
        $log_entry = date('Y-m-d H:i:s') . ' | ' . wp_json_encode($log_data) . PHP_EOL;
        
        // Append to log file (create if doesn't exist)
        if (function_exists('file_put_contents')) {
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
        
        // Rotate log file if it gets too large (>10MB)
        if (file_exists($log_file) && filesize($log_file) > 10 * 1024 * 1024) {
            $this->rotate_log_file($log_file);
        }
    }
    
    /**
     * Rotate log file to prevent it from getting too large
     */
    private function rotate_log_file($log_file) {
        $backup_file = $log_file . '.old';
        
        // Remove old backup if exists
        if (file_exists($backup_file)) {
            unlink($backup_file);
        }
        
        // Move current log to backup
        rename($log_file, $backup_file);
        
        // Create fresh log file
        file_put_contents($log_file, '# AutoTag Pro Error Log - Rotated on ' . date('Y-m-d H:i:s') . PHP_EOL);
    }
    
    /**
     * Update error statistics
     */
    private function update_error_stats($exception) {
        $this->error_stats['total_errors']++;
        
        $exception_class = get_class($exception);
        if (!isset($this->error_stats['error_types'][$exception_class])) {
            $this->error_stats['error_types'][$exception_class] = 0;
        }
        $this->error_stats['error_types'][$exception_class]++;
        
        // Keep only recent 50 errors
        $this->error_stats['recent_errors'][] = [
            'timestamp' => current_time('mysql'),
            'class' => $exception_class,
            'message' => $exception->getMessage(),
            'error_code' => $exception->getErrorCode()
        ];
        
        // Trim to last 50 errors
        if (count($this->error_stats['recent_errors']) > 50) {
            $this->error_stats['recent_errors'] = array_slice($this->error_stats['recent_errors'], -50);
        }
        
        // Update plugin stats if available
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            wp_autotag_pro()->update_stats(['errors_count' => 1]);
        }
    }
    
    /**
     * Get error statistics
     */
    public function get_error_stats() {
        return $this->error_stats;
    }
    
    /**
     * Clear error statistics
     */
    public function clear_error_stats() {
        $this->error_stats = [
            'total_errors' => 0,
            'error_types' => [],
            'recent_errors' => []
        ];
    }
    
    /**
     * Handle AJAX error report (for frontend error reporting)
     */
    public function handle_ajax_error_report() {
        // Verify nonce for security
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security verification failed']);
        }
        
        // Get error data from request
        $error_data = [
            'message' => sanitize_text_field($_POST['message'] ?? ''),
            'error_code' => sanitize_text_field($_POST['error_code'] ?? ''),
            'context' => sanitize_textarea_field($_POST['context'] ?? ''),
            'user_agent' => sanitize_text_field($_POST['user_agent'] ?? ''),
            'url' => sanitize_url($_POST['url'] ?? ''),
            'timestamp' => current_time('mysql')
        ];
        
        // Create exception from reported data
        $exception = new AutoTag_Exception(
            $error_data['message'],
            0,
            null,
            ['frontend_report' => $error_data]
        );
        
        // Log the reported error
        $this->log_error($exception, ['source' => 'frontend_report']);
        
        wp_send_json_success(['message' => 'Error report received and logged']);
    }
    
    /**
     * Create exception from WordPress error
     */
    public function create_exception_from_wp_error($wp_error, $exception_class = 'AutoTag_Exception') {
        if (!is_wp_error($wp_error)) {
            return new AutoTag_Exception('Invalid error object provided');
        }
        
        $message = $wp_error->get_error_message();
        $code = $wp_error->get_error_code();
        $data = $wp_error->get_error_data();
        
        // Determine appropriate exception class based on error code
        $exception_class = $this->determine_exception_class($code, $message);
        
        return new $exception_class($message, 0, null, ['wp_error_data' => $data]);
    }
    
    /**
     * Determine appropriate exception class based on error patterns
     */
    private function determine_exception_class($code, $message) {
        $message_lower = strtolower($message);
        
        // Memory-related
        if (strpos($message_lower, 'memory') !== false || strpos($code, 'memory') !== false) {
            return 'AutoTag_MemoryException';
        }
        
        // Database-related
        if (strpos($message_lower, 'database') !== false || strpos($message_lower, 'mysql') !== false) {
            return 'AutoTag_DatabaseException';
        }
        
        // Validation-related
        if (strpos($message_lower, 'invalid') !== false || strpos($message_lower, 'validation') !== false) {
            return 'AutoTag_ValidationException';
        }
        
        // Permission-related
        if (strpos($message_lower, 'permission') !== false || strpos($message_lower, 'unauthorized') !== false) {
            return 'AutoTag_SecurityException';
        }
        
        // Configuration-related
        if (strpos($message_lower, 'config') !== false || strpos($message_lower, 'setting') !== false) {
            return 'AutoTag_ConfigException';
        }
        
        // Network-related
        if (strpos($message_lower, 'network') !== false || strpos($message_lower, 'timeout') !== false || strpos($message_lower, 'connection') !== false) {
            return 'AutoTag_NetworkException';
        }
        
        // Default to generic exception
        return 'AutoTag_Exception';
    }
    
    /**
     * Format bytes for human reading
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
    
    /**
     * Check if error handling is working properly (for testing)
     */
    public function test_error_handling() {
        $test_results = [];
        
        try {
            // Test basic exception
            throw new AutoTag_Exception('Test exception');
        } catch (AutoTag_Exception $e) {
            $test_results['basic_exception'] = 'PASS';
        }
        
        try {
            // Test validation exception
            throw new AutoTag_ValidationException('Test validation error', ['field1' => 'Required field missing']);
        } catch (AutoTag_ValidationException $e) {
            $test_results['validation_exception'] = count($e->getValidationErrors()) > 0 ? 'PASS' : 'FAIL';
        }
        
        try {
            // Test memory exception
            throw new AutoTag_MemoryException('Memory test');
        } catch (AutoTag_MemoryException $e) {
            $test_results['memory_exception'] = $e->getErrorCode() === 'MEMORY_EXCEEDED' ? 'PASS' : 'FAIL';
        }
        
        // Test error logging
        $test_exception = new AutoTag_ConfigException('Test config error');
        $this->log_error($test_exception, ['test' => true]);
        $test_results['error_logging'] = 'PASS';
        
        return $test_results;
    }
}