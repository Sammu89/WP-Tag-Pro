<?php
/**
 * Plugin Name: WordPress Auto-Tag Pro
 * Plugin URI: https://wordpress.org/plugins/wp-auto-tag-pro/
 * Description: Intelligently auto-tag posts with classic keywords or tag families, automatic year tags, and existing WordPress tags integration.
 * Version: 1.0.0
 * Author: Sammu89
 * Author URI: https://profiles.wordpress.org/sammu89/
 * Text Domain: wp-auto-tag-pro
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.5
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: true
 *
 * @package WP_Auto_Tag_Pro
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define('WP_AUTOTAG_PRO_VERSION', '1.0.0');
define('WP_AUTOTAG_PRO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_AUTOTAG_PRO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_AUTOTAG_PRO_PLUGIN_FILE', __FILE__);
define('WP_AUTOTAG_PRO_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Enhanced autoloader with better error handling
spl_autoload_register(function ($class) {
    // Only handle our plugin classes
    $prefix = 'AutoTag_';
    $base_dir = WP_AUTOTAG_PRO_PLUGIN_DIR . 'includes/';
    
    // Check if class uses our prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // Get the relative class name and convert to filename
    $relative_class = substr($class, $len);
    
    // Map class names to actual filenames
    $class_map = [
        'Config' => 'class-autotag-config.php',
        'Processor' => 'class-autotag-processor.php', 
        'Admin' => 'class-autotag-admin.php',
        'Ajax' => 'class-autotag-ajax.php',
        'Bulk' => 'class-autotag-bulk.php',
        'AdminHtml' => 'class-autotag-admin-html.php',
        // Settings management class
        'PluginSettings' => 'class-plugin-settings.php',
        // Cache system
        'SimpleCache' => 'class-simple-cache.php',
        // Error handling
        'ErrorHandler' => 'class-error-handler.php',
        // Memory monitoring
        'MemoryMonitor' => 'class-memory-monitor.php',
        // Database optimization
        'DatabaseOptimizer' => 'class-database-optimizer.php',
        // Adaptive processing
        'AdaptiveProcessor' => 'class-adaptive-processor.php',
        // Performance testing
        'PerformanceTests' => 'class-performance-tests.php',
        // New processing classes
        'TextProcessor' => 'processing/class-text-processor.php',
        'KeywordMatcher' => 'processing/class-keyword-matcher.php',
        'TagApplier' => 'processing/class-tag-applier.php',
        'ProcessorCoordinator' => 'processing/class-processor-coordinator.php',
        // New AJAX handler classes
        'Config_Ajax' => 'ajax/class-config-ajax.php',
        'Processing_Ajax' => 'ajax/class-processing-ajax.php',
        'Bulk_Ajax' => 'ajax/class-bulk-ajax.php',
        'System_Ajax' => 'ajax/class-system-ajax.php',
        'Preview_Ajax' => 'ajax/class-preview-ajax.php',
        // Enhanced processing classes (v2.1.0)
        'TaxonomyApplier' => 'processing/class-taxonomy-applier.php',
        // WordPress integration classes (v2.1.0)
        'WP_Category_Manager' => 'integration/class-wp-category-manager.php',
        'WP_Hooks_Integration' => 'integration/class-wp-hooks-integration.php',
        // Rollback system (v2.1.0)
        'RollbackManager' => 'class-autotag-rollback-manager.php'
    ];
    
    if (isset($class_map[$relative_class])) {
        $file = $base_dir . $class_map[$relative_class];
    } else {
        // Check exceptions directory first for exception classes
        if (strpos($relative_class, 'Exception') !== false) {
            $exceptions_file = $base_dir . 'exceptions/class-plugin-exceptions.php';
            if (is_file($exceptions_file) && is_readable($exceptions_file)) {
                $file = $exceptions_file;
            }
        }
        
        if (!isset($file)) {
            // Check processing directory
            $processing_file = $base_dir . 'processing/class-autotag-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
            if (is_file($processing_file) && is_readable($processing_file)) {
                $file = $processing_file;
            } else {
                // Check ajax directory
                $ajax_file = $base_dir . 'ajax/class-autotag-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
                if (is_file($ajax_file) && is_readable($ajax_file)) {
                    $file = $ajax_file;
                } else {
                    // Fallback to old naming convention in includes
                    $file = $base_dir . 'class-autotag-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
                }
            }
        }
    }
    
    // Only require if file exists and is readable
    if (is_file($file) && is_readable($file)) {
        require_once $file;
        return true;
    }
    
    // Log missing file for debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[Auto-Tag Pro] Could not load class file: " . $file);
    }
    
    return false;
});

/**
 * Main plugin class - Singleton pattern
 */
class AutoTag_Plugin {
    
    private static $instance = null;
    
    /**
     * Plugin components
     */
    public $settings;
    public $admin;
    public $processor;
    public $config;
    public $ajax;
    public $bulk;
    public $memory_monitor;
    public $database_optimizer;
    public $adaptive_processor;
    public $cache;
    public $error_handler;
    
    /**
     * v2.1.0 - WordPress integration components
     */
    public $wp_hooks_integration;
    
    /**
     * Error tracking
     */
    private $init_errors = [];
    private $is_initialized = false;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', [$this, 'init'], 5);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Show initialization errors
        add_action('admin_notices', [$this, 'show_init_errors']);
    }
    
    /**
     * Initialize plugin with error handling
     */
    public function init() {
        try {
            // Check WordPress version compatibility first
            if (!$this->check_wp_version()) {
                return;
            }
            
            // Check system requirements
            if (!$this->check_requirements()) {
                return;
            }
            
            // Initialize components with error handling
            $this->init_components();
            
            // Check for configuration migration needs
            $this->maybe_migrate_configuration();
            
            // Mark as initialized if successful
            if (empty($this->init_errors)) {
                $this->is_initialized = true;
                do_action('autotag_init');
            }
            
        } catch (Exception $e) {
            $this->init_errors[] = 'Initialization error: ' . $e->getMessage();
            $this->log_error('Plugin initialization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize plugin components with comprehensive error handling
     */
    private function init_components() {
        // Settings component (always needed first)
        try {
            if (class_exists('AutoTag_PluginSettings')) {
                $this->settings = new AutoTag_PluginSettings();
            } else {
                throw new Exception('AutoTag_PluginSettings class not found. Check includes/class-plugin-settings.php file.');
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Settings component error: ' . $e->getMessage();
            return; // Cannot continue without settings
        }
        
        // Cache component for performance
        try {
            if (class_exists('AutoTag_SimpleCache')) {
                $this->cache = new AutoTag_SimpleCache();
            } else {
                $this->init_errors[] = 'Cache component not available (AutoTag_SimpleCache class not found)';
                // Cache is optional, so continue without it
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Cache component error: ' . $e->getMessage();
            // Cache is optional, so continue without it
        }
        
        // Error handling component
        try {
            if (class_exists('AutoTag_ErrorHandler')) {
                $this->error_handler = new AutoTag_ErrorHandler();
            } else {
                $this->init_errors[] = 'Error handler not available (AutoTag_ErrorHandler class not found)';
                // Error handler is optional, continue without it
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Error handler initialization error: ' . $e->getMessage();
            // Error handler is optional, continue without it
        }
        
        // Memory monitoring component
        try {
            if (class_exists('AutoTag_MemoryMonitor')) {
                $this->memory_monitor = new AutoTag_MemoryMonitor();
            } else {
                $this->init_errors[] = 'Memory monitor not available (AutoTag_MemoryMonitor class not found)';
                // Memory monitor is optional, continue without it
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Memory monitor initialization error: ' . $e->getMessage();
            // Memory monitor is optional, continue without it
        }
        
        // Database optimization component
        try {
            if (class_exists('AutoTag_DatabaseOptimizer')) {
                $this->database_optimizer = new AutoTag_DatabaseOptimizer();
            } else {
                $this->init_errors[] = 'Database optimizer not available (AutoTag_DatabaseOptimizer class not found)';
                // Database optimizer is optional, continue without it
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Database optimizer initialization error: ' . $e->getMessage();
            // Database optimizer is optional, continue without it
        }
        
        // Adaptive processing component (initialize after other components)
        try {
            if (class_exists('AutoTag_AdaptiveProcessor')) {
                $this->adaptive_processor = new AutoTag_AdaptiveProcessor();
            } else {
                $this->init_errors[] = 'Adaptive processor not available (AutoTag_AdaptiveProcessor class not found)';
                // Adaptive processor is optional, continue without it
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Adaptive processor initialization error: ' . $e->getMessage();
            // Adaptive processor is optional, continue without it
        }
        
        // Core configuration component (needed second)
        try {
            if (class_exists('AutoTag_Config')) {
                $this->config = new AutoTag_Config();
            } else {
                throw new Exception('AutoTag_Config class not found. Check includes/class-autotag-config.php file.');
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Config component error: ' . $e->getMessage();
            return; // Cannot continue without config
        }
        
        // Processing component (refactored architecture with backward compatibility)
        try {
            if (class_exists('AutoTag_Processor')) {
                $this->processor = new AutoTag_Processor($this->config);
            } else {
                $this->init_errors[] = 'AutoTag_Processor class not found. Check includes/class-autotag-processor.php';
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Processor component error: ' . $e->getMessage();
        }
        
        // Bulk processing component
        try {
            if (class_exists('AutoTag_Bulk')) {
                $this->bulk = new AutoTag_Bulk();
            } else {
                $this->init_errors[] = 'AutoTag_Bulk class not found. Check includes/class-autotag-bulk.php';
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'Bulk component error: ' . $e->getMessage();
        }
        
        // Admin components (only in admin area)
        if (is_admin()) {
            try {
                if (class_exists('AutoTag_Admin')) {
                    $this->admin = new AutoTag_Admin();
                } else {
                    $this->init_errors[] = 'AutoTag_Admin class not found. Check includes/class-autotag-admin.php';
                }
            } catch (Exception $e) {
                $this->init_errors[] = 'Admin component error: ' . $e->getMessage();
            }
            
            // Initialize main AJAX handler
            try {
                if (class_exists('AutoTag_Ajax')) {
                    $this->ajax = new AutoTag_Ajax();
                } else {
                    $this->init_errors[] = 'AutoTag_Ajax class not found. Check includes/class-autotag-ajax.php';
                }
            } catch (Exception $e) {
                $this->init_errors[] = 'AJAX component error: ' . $e->getMessage();
            }
            
            // Initialize specialized AJAX handlers (v2.1.0)
            try {
                if (class_exists('AutoTag_Config_Ajax')) {
                    $this->config_ajax = new AutoTag_Config_Ajax();
                }
                if (class_exists('AutoTag_Bulk_Ajax')) {
                    $this->bulk_ajax = new AutoTag_Bulk_Ajax();
                }
                if (class_exists('AutoTag_Processing_Ajax')) {
                    $this->processing_ajax = new AutoTag_Processing_Ajax();
                }
                if (class_exists('AutoTag_Preview_Ajax')) {
                    $this->preview_ajax = new AutoTag_Preview_Ajax();
                }
                if (class_exists('AutoTag_System_Ajax')) {
                    $this->system_ajax = new AutoTag_System_Ajax();
                }
                if (class_exists('AutoTag_RollbackManager')) {
                    $this->rollback_manager = new AutoTag_RollbackManager();
                }
                
                $this->log_info('Specialized AJAX handlers initialized successfully');
            } catch (Exception $e) {
                $this->init_errors[] = 'Specialized AJAX handlers error: ' . $e->getMessage();
            }
        }
        
        // WordPress integration component (v2.1.0) - Initialize after core components
        try {
            if (class_exists('AutoTag_WP_Hooks_Integration')) {
                $this->wp_hooks_integration = new AutoTag_WP_Hooks_Integration();
                $this->log_info('WordPress Hooks Integration initialized successfully');
            } else {
                $this->init_errors[] = 'WordPress Hooks Integration not available (AutoTag_WP_Hooks_Integration class not found)';
                // Integration is optional but recommended for full functionality
            }
        } catch (Exception $e) {
            $this->init_errors[] = 'WordPress Hooks Integration error: ' . $e->getMessage();
            // Integration is optional, continue without it
        }
    }
    
    /**
     * Show initialization errors in admin
     */
    public function show_init_errors() {
        if (!empty($this->init_errors) && current_user_can('manage_options')) {
            foreach ($this->init_errors as $error) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>Auto-Tag Pro Error:</strong> ' . esc_html($error) . '</p>';
                echo '</div>';
            }
        }
    }
    
    /**
     * Load plugin textdomain for internationalization
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wp-auto-tag-pro',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }
    
    /**
     * Check WordPress version compatibility
     */
    private function check_wp_version() {
        global $wp_version;
        
        if (version_compare($wp_version, '5.0', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p>' . sprintf(
                    __('WordPress Auto-Tag Pro requires WordPress version 5.0 or higher. You are running version %s.', 'wp-auto-tag-pro'),
                    esc_html($GLOBALS['wp_version'])
                ) . '</p>';
                echo '</div>';
            });
            return false;
        }
        
        return true;
    }
    
    /**
     * Check system requirements
     */
    private function check_requirements() {
        $errors = [];
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $errors[] = sprintf(
                __('WordPress Auto-Tag Pro requires PHP version 7.4 or higher. You are running version %s.', 'wp-auto-tag-pro'),
                PHP_VERSION
            );
        }
        
        // Check required WordPress functions
        if (!function_exists('wp_schedule_event') || !function_exists('wp_cron')) {
            $errors[] = __('WordPress Auto-Tag Pro requires WordPress cron functionality to be enabled.', 'wp-auto-tag-pro');
        }
        
        // Check required PHP extensions
        $required_extensions = ['json', 'mbstring'];
        $missing_extensions = [];
        
        foreach ($required_extensions as $extension) {
            if (!extension_loaded($extension)) {
                $missing_extensions[] = $extension;
            }
        }
        
        if (!empty($missing_extensions)) {
            $errors[] = sprintf(
                __('WordPress Auto-Tag Pro requires the following PHP extensions: %s', 'wp-auto-tag-pro'),
                implode(', ', $missing_extensions)
            );
        }
        
        // Display errors if any
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->init_errors[] = $error;
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * Maybe migrate from old configuration format
     */
    private function maybe_migrate_configuration() {
        // Only migrate if we have the config component
        if (!$this->config) {
            return;
        }
        

    }
    
    /**
     * Plugin activation with comprehensive error handling
     */
    public function activate() {
        try {
            // Check minimum requirements before activation
            if (!$this->check_requirements()) {
                deactivate_plugins(plugin_basename(__FILE__));
                wp_die(__('WordPress Auto-Tag Pro cannot be activated due to unmet requirements.', 'wp-auto-tag-pro'));
            }
            
            // Initialize settings and create default options
            if (class_exists('AutoTag_PluginSettings')) {
                $settings = new AutoTag_PluginSettings();
                $settings->initialize_defaults();
            } else {
                // Create default options if settings class not available
                $this->create_default_options();
                update_option('wp_autotag_pro_version', WP_AUTOTAG_PRO_VERSION);
            }
            
            // Schedule cleanup cron if not exists
            if (!wp_next_scheduled('autotag_cleanup_cron')) {
                wp_schedule_event(time(), 'daily', 'autotag_cleanup_cron');
            }
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
            do_action('autotag_activated');
            
        } catch (Exception $e) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('Activation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        try {
            // Clear scheduled crons
            wp_clear_scheduled_hook('autotag_cleanup_cron');
            wp_clear_scheduled_hook('autotag_bulk_process');
            wp_clear_scheduled_hook('autotag_process_bulk_batch');
            
            // Cancel any active bulk processing queues
            if ($this->bulk && method_exists($this->bulk, 'cancel_all_queues')) {
                $this->bulk->cancel_all_queues();
            }
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
            do_action('autotag_deactivated');
            
        } catch (Exception $e) {
            $this->log_error('Deactivation error: ' . $e->getMessage());
        }
    }
    
    /**
     * Create default plugin options
     */
    private function create_default_options() {
        $default_settings = [
            'enabled_post_types' => ['post'],
            'enabled_taxonomies' => ['post_tag'],
            'content_areas' => ['title', 'content'],
            'case_sensitive' => false,
            'word_boundaries' => true,
            'auto_process_on_save' => true,
            'batch_size' => 50,
            'max_execution_time' => 30,
            'remove_data_on_uninstall' => false,
            'include_existing_tags' => true,
            'existing_tags_strategy' => 'merge'
        ];
        
        // Default configuration (classic mode)
        $default_config = [
            'm' => 'c',        // mode: classic
            'y' => true,       // year_tags enabled
            'ys' => 'r',       // year_strategy: replace
            's' => 'a',        // tag_strategy: append
            'k' => []          // empty keywords
        ];
        
        // Only add if options don't exist
        add_option('wp_autotag_pro_settings', $default_settings);
        add_option('wp_autotag_pro_config', $default_config);
        add_option('wp_autotag_pro_stats', [
            'total_processed' => 0,
            'total_tags_added' => 0,
            'last_bulk_run' => 0,
            'errors_count' => 0,
            'mode_switches' => 0,
            'year_tags_added' => 0,
            'last_update' => time()
        ]);
    }
    
    /**
     * Get plugin settings safely
     */
    public function get_settings() {
        if ($this->settings) {
            return $this->settings->get_all_settings();
        }
        // Fallback to direct option access
        return get_option('wp_autotag_pro_settings', []);
    }
    
    /**
     * Get plugin statistics safely
     */
    public function get_stats() {
        if ($this->settings) {
            return $this->settings->get_stats();
        }
        // Fallback to direct option access
        return get_option('wp_autotag_pro_stats', []);
    }
    
    /**
     * Get plugin configuration safely
     */
    public function get_config() {
        if ($this->config && method_exists($this->config, 'get_configuration')) {
            return $this->config->get_configuration();
        }
        return [];
    }
    
    /**
     * Update statistics
     */
    public function update_stats($updates) {
        if ($this->settings) {
            $this->settings->update_stats($updates);
        } else {
            // Fallback to old method
            $stats = $this->get_stats();
            
            foreach ($updates as $key => $value) {
                if (isset($stats[$key])) {
                    $stats[$key] += $value;
                } else {
                    $stats[$key] = $value;
                }
            }
            
            $stats['last_update'] = time();
            
            if ($this->settings) {
                $this->settings->update_stats($stats);
            } else {
                update_option('wp_autotag_pro_stats', $stats);
            }
        }
    }
    
    /**
     * Log error to WordPress debug log
     */
    public function log_error($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[WP Auto-Tag Pro ERROR] ' . $message;
            if ($data) {
                $log_message .= ' | Data: ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            error_log($log_message);
        }
        
        // Update error count in stats
        $this->update_stats(['errors_count' => 1]);
    }
    
    /**
     * Log info to WordPress debug log
     */
    public function log_info($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[WP Auto-Tag Pro INFO] ' . $message;
            if ($data) {
                $log_message .= ' | Data: ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            error_log($log_message);
        }
    }
    
    /**
     * Check if current user can manage the plugin
     */
    public function current_user_can_manage() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if current user can edit posts
     */
    public function current_user_can_edit_posts() {
        return current_user_can('edit_posts');
    }
    
    /**
     * Get existing WordPress tags for performance
     */
    public function get_existing_tags($taxonomy = 'post_tag') {
        $cache_key = 'autotag_existing_tags_' . $taxonomy;
        $cached = wp_cache_get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'names'
        ]);
        
        if (is_wp_error($terms)) {
            $this->log_error('Failed to get existing tags for taxonomy: ' . $taxonomy, $terms->get_error_message());
            $terms = [];
        }
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $terms, '', 300);
        
        return $terms;
    }
    
    /**
     * Clear existing tags cache
     */
    public function clear_existing_tags_cache() {
        $settings = $this->get_settings();
        $taxonomies = $settings['enabled_taxonomies'] ?? ['post_tag'];
        
        foreach ($taxonomies as $taxonomy) {
            wp_cache_delete('autotag_existing_tags_' . $taxonomy);
        }
    }
    
/**
 * Normalize text for processing with enhanced error handling and improved cache management
 */
public function normalize_text($text) {
    // Input validation
    if (!is_string($text) || empty($text)) {
        return '';
    }
    
    // Use bounded cache with memory monitoring integration
    static $cache = [];
    static $cache_size = 0;
    static $max_cache_size = null;
    static $last_cleanup = 0;
    
    // Initialize max cache size based on memory monitor if available
    if ($max_cache_size === null) {
        if (isset($this->memory_monitor)) {
            $max_cache_size = $this->memory_monitor->get_cache_limits()['text_normalization'] ?? 50;
        } else {
            // Detect memory limit and adjust cache size accordingly
            $memory_limit = ini_get('memory_limit');
            if ($memory_limit === '-1' || $this->convert_to_bytes($memory_limit) > 256 * 1024 * 1024) {
                $max_cache_size = 100; // High memory environment
            } elseif ($this->convert_to_bytes($memory_limit) > 128 * 1024 * 1024) {
                $max_cache_size = 50;  // Medium memory environment
            } else {
                $max_cache_size = 25;  // Shared hosting / low memory
            }
        }
    }
    
    $cache_key = md5($text);
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    // Remove accents and special characters with better error handling
    $normalized = $text;
    
    // Try iconv first (if available)
    if (function_exists('iconv')) {
        $iconv_result = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($iconv_result !== false) {
            $normalized = $iconv_result;
        }
    }
    
    // Fallback to mb_convert_encoding if available
    if ($normalized === $text && function_exists('mb_convert_encoding')) {
        $normalized = @mb_convert_encoding($text, 'ASCII', 'UTF-8');
    }
    
    // Basic cleanup
    $normalized = strtolower(trim($normalized));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    
    // Intelligent cache management with memory awareness
    $current_time = time();
    if ($cache_size >= $max_cache_size || ($current_time - $last_cleanup) > 300) { // 5 minutes
        // Memory-aware cache cleanup
        if (isset($this->memory_monitor) && $this->memory_monitor->is_memory_limit_approaching(0.70)) {
            // Aggressive cleanup when memory is high
            $cache = [$cache_key => $normalized];
            $cache_size = 1;
            $this->log_info('Aggressive cache cleanup triggered in normalize_text due to memory pressure');
        } elseif ($cache_size >= $max_cache_size) {
            // LRU-style cleanup - remove oldest half of entries
            $keys_to_remove = array_slice(array_keys($cache), 0, intval($cache_size / 2));
            foreach ($keys_to_remove as $key) {
                unset($cache[$key]);
            }
            $cache_size = count($cache);
            $cache[$cache_key] = $normalized;
            $cache_size++;
        }
        $last_cleanup = $current_time;
    } else {
        // Normal cache addition
        $cache[$cache_key] = $normalized;
        $cache_size++;
    }
    
    return $normalized;
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
     * Check if post type supports tags
     */
    public function post_type_supports_tags($post_type, $taxonomy = 'post_tag') {
        return is_object_in_taxonomy($post_type, $taxonomy);
    }
    
    /**
     * Check if plugin is properly initialized
     */
    public function is_initialized() {
        return $this->is_initialized && !empty($this->config) && empty($this->init_errors);
    }
    
    /**
     * Get memory statistics for component tracking
     */
    public function get_memory_stats() {
        $current_usage = memory_get_usage(true);
        $peak_usage = memory_get_peak_usage(true);
        $memory_limit = ini_get('memory_limit');
        
        // Convert memory limit to bytes
        $limit_bytes = $this->convert_to_bytes($memory_limit);
        
        return [
            'current_usage' => $current_usage,
            'peak_usage' => $peak_usage,
            'memory_limit' => $limit_bytes,
            'usage_percentage' => $limit_bytes > 0 ? ($current_usage / $limit_bytes) * 100 : 0,
            'component_usage' => [] // Container for component-specific usage tracking
        ];
    }
}

// Initialize the plugin with error handling
function wp_autotag_pro() {
    try {
        return AutoTag_Plugin::get_instance();
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Auto-Tag Pro] Failed to initialize: ' . $e->getMessage());
        }
        return null;
    }
}

// Start the plugin
add_action('plugins_loaded', function() {
    wp_autotag_pro();
}, 10);