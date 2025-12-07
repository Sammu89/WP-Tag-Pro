<?php
/**
 * Plugin Settings Class
 * Centralized settings management for WordPress Auto-Tag Pro
 * Replaces scattered get_option/update_option calls throughout the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_PluginSettings {
    
    /**
     * Settings cache to avoid repeated database calls
     */
    private $settings_cache = null;
    private $config_cache = null;
    private $stats_cache = null;
    
    /**
     * WordPress option names
     */
    const SETTINGS_OPTION = 'wp_autotag_pro_settings';
    const CONFIG_OPTION = 'wp_autotag_pro_config';
    const CONFIG_HASH_OPTION = 'wp_autotag_pro_config_hash';
    const STATS_OPTION = 'wp_autotag_pro_stats';
    const VERSION_OPTION = 'wp_autotag_pro_version';

    
    /**
     * Get all plugin settings with caching
     */
    public function get_all_settings() {
        if ($this->settings_cache === null) {
            $this->settings_cache = get_option(self::SETTINGS_OPTION, $this->get_default_settings());
        }
        return $this->settings_cache;
    }
    
    /**
     * Get specific setting value
     */
    public function get_setting($key, $default = null) {
        $settings = $this->get_all_settings();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
    
    /**
     * Update plugin settings
     */
    public function update_settings($new_settings) {
        $current = $this->get_all_settings();
        $updated = array_merge($current, $new_settings);
        
        $result = update_option(self::SETTINGS_OPTION, $updated);
        
        if ($result) {
            $this->settings_cache = null; // Clear cache
        }
        
        return $result;
    }
    
    /**
     * Update a single setting
     */
    public function update_setting($key, $value) {
        return $this->update_settings([$key => $value]);
    }
    
    // =================================================================
    // SPECIFIC SETTING GETTERS (for easy usage throughout plugin)
    // =================================================================
    
    /**
     * Get enabled post types
     */
    public function get_enabled_post_types() {
        return $this->get_setting('enabled_post_types', ['post']);
    }
    
    /**
     * Get enabled taxonomies
     */
    public function get_enabled_taxonomies() {
        return $this->get_setting('enabled_taxonomies', ['post_tag']);
    }
    
    /**
     * Get content areas to analyze
     */
    public function get_content_areas() {
        return $this->get_setting('content_areas', ['title', 'content']);
    }
    
    
    /**
     * Check if case sensitive matching is enabled
     */
    public function is_case_sensitive() {
        return (bool) $this->get_setting('case_sensitive', false);
    }
    
    /**
     * Check if word boundaries are enforced
     */
    public function use_word_boundaries() {
        return (bool) $this->get_setting('word_boundaries', true);
    }
    
    /**
     * Check if auto-processing on save is enabled
     */
    public function is_auto_process_enabled() {
        return (bool) $this->get_setting('auto_process_on_save', true);
    }
    
    /**
     * Get batch processing size
     */
    public function get_batch_size() {
        return (int) $this->get_setting('batch_size', 50);
    }
    
    /**
     * Get maximum execution time for processing
     */
    public function get_max_execution_time() {
        return (int) $this->get_setting('max_execution_time', 30);
    }
    
    /**
     * Check if data should be removed on uninstall
     */
    public function remove_data_on_uninstall() {
        return (bool) $this->get_setting('remove_data_on_uninstall', false);
    }
    
    /**
     * Check if existing tags should be included
     */
    public function include_existing_tags() {
        return (bool) $this->get_setting('include_existing_tags', true);
    }
    
    /**
     * Get existing tags strategy
     */
    public function get_existing_tags_strategy() {
        return $this->get_setting('existing_tags_strategy', 'merge');
    }
    
    // =================================================================
    // CONFIGURATION MANAGEMENT (wp_autotag_pro_config)
    // =================================================================
    
    /**
     * Get plugin configuration with caching
     */
    public function get_config() {
        if ($this->config_cache === null) {
            $this->config_cache = get_option(self::CONFIG_OPTION, $this->get_default_config());
        }
        return $this->config_cache;
    }
    
    /**
     * Get configuration hash
     */
    public function get_config_hash() {
        return get_option(self::CONFIG_HASH_OPTION, '');
    }
    
    /**
     * Update configuration and hash
     */
    public function update_config($config, $hash = null) {
        if ($hash === null) {
            $hash = md5(serialize($config));
        }
        
        $config_updated = update_option(self::CONFIG_OPTION, $config);
        $hash_updated = update_option(self::CONFIG_HASH_OPTION, $hash);
        
        if ($config_updated || $hash_updated) {
            $this->config_cache = null; // Clear cache
        }
        
        return $config_updated;
    }
    
    /**
     * Get current processing mode
     */
    public function get_mode() {
        $config = $this->get_config();
        return $config['m'] ?? 'c'; // 'c' for classic, 'f' for families
    }
    
    /**
     * Check if year tags are enabled
     */
    public function are_year_tags_enabled() {
        $config = $this->get_config();
        return (bool) ($config['y'] ?? true);
    }
    
    /**
     * Get year tag strategy
     */
    public function get_year_strategy() {
        $config = $this->get_config();
        return $config['ys'] ?? 'r'; // 'r' for replace, 'a' for append
    }
    
    /**
     * Get main tag strategy
     */
    public function get_tag_strategy() {
        $config = $this->get_config();
        return $config['s'] ?? 'a'; // 'a' for append, 'r' for replace
    }
    
    /**
     * Get keywords (classic mode)
     */
    public function get_keywords() {
        $config = $this->get_config();
        return $config['k'] ?? [];
    }
    
    /**
     * Get families (families mode)
     */
    public function get_families() {
        $config = $this->get_config();
        return $config['f'] ?? [];
    }
    
    // =================================================================
    // STATISTICS MANAGEMENT (wp_autotag_pro_stats)
    // =================================================================
    
    /**
     * Get plugin statistics with caching
     */
    public function get_stats() {
        if ($this->stats_cache === null) {
            $this->stats_cache = get_option(self::STATS_OPTION, $this->get_default_stats());
        }
        return $this->stats_cache;
    }
    
    /**
     * Update statistics
     */
    public function update_stats($new_stats) {
        $current = $this->get_stats();
        $updated = array_merge($current, $new_stats);
        $updated['last_update'] = time();
        
        $result = update_option(self::STATS_OPTION, $updated);
        
        if ($result) {
            $this->stats_cache = null; // Clear cache
        }
        
        return $result;
    }
    
    /**
     * Increment a statistic counter
     */
    public function increment_stat($key, $amount = 1) {
        $stats = $this->get_stats();
        $current_value = isset($stats[$key]) ? (int) $stats[$key] : 0;
        
        return $this->update_stats([$key => $current_value + $amount]);
    }
    
    /**
     * Get specific statistic
     */
    public function get_stat($key, $default = 0) {
        $stats = $this->get_stats();
        return isset($stats[$key]) ? $stats[$key] : $default;
    }
    
    // =================================================================
    // VERSION MANAGEMENT
    // =================================================================
    
    /**
     * Get current plugin version from database
     */
    public function get_stored_version() {
        return get_option(self::VERSION_OPTION, '1.0.0');
    }
    
    /**
     * Update stored plugin version
     */
    public function update_version($version) {
        return update_option(self::VERSION_OPTION, $version);
    }
    
    /**
     * Check if plugin version has changed (needs migration)
     */
    public function version_changed() {
        $stored_version = $this->get_stored_version();
        $current_version = defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '1.0.0';
        
        return version_compare($stored_version, $current_version, '<');
    }
    

    
    // =================================================================
    // CACHE MANAGEMENT
    // =================================================================
    
    /**
     * Clear all settings caches
     */
    public function clear_cache() {
        $this->settings_cache = null;
        $this->config_cache = null;
        $this->stats_cache = null;
    }
    
    /**
     * Clear settings cache only
     */
    public function clear_settings_cache() {
        $this->settings_cache = null;
    }
    
    /**
     * Clear config cache only
     */
    public function clear_config_cache() {
        $this->config_cache = null;
    }
    
    /**
     * Clear stats cache only
     */
    public function clear_stats_cache() {
        $this->stats_cache = null;
    }
    
    // =================================================================
    // DEFAULT VALUES
    // =================================================================
    
    /**
     * Get default plugin settings
     */
    private function get_default_settings() {
        return [
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
    }
    
    /**
     * Get default configuration
     */
    private function get_default_config() {
        return [
            'rules' => [],      // unified rules
            'year_tags_enabled' => true,
            'year_strategy' => 'r',
            'default_tag_strategy' => 'merge',
            'default_category_strategy' => 'merge',
            'taxonomy_processing' => 'both',
            'auto_create_categories' => true,
            'conflict_warnings_enabled' => true
        ];
    }
    
    /**
     * Get default statistics
     */
    private function get_default_stats() {
        return [
            'total_processed' => 0,
            'total_tags_added' => 0,
            'year_tags_added' => 0,
            'last_bulk_run' => 0,
            'bulk_runs_count' => 0,
            'errors_count' => 0,
            'mode_switches' => 0,
            'last_update' => time()
        ];
    }
    
    // =================================================================
    // INITIALIZATION
    // =================================================================
    
    /**
     * Initialize default options (called on plugin activation)
     */
    public function initialize_defaults() {
        // Only add if options don't exist
        add_option(self::SETTINGS_OPTION, $this->get_default_settings());
        add_option(self::CONFIG_OPTION, $this->get_default_config());
        add_option(self::STATS_OPTION, $this->get_default_stats());
        add_option(self::VERSION_OPTION, defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '1.0.0');
    }
    
    /**
     * Clean up all plugin options (called on uninstall)
     */
    public function cleanup_options() {
        delete_option(self::SETTINGS_OPTION);
        delete_option(self::CONFIG_OPTION);
        delete_option(self::CONFIG_HASH_OPTION);
        delete_option(self::STATS_OPTION);
        delete_option(self::VERSION_OPTION);
        delete_option(self::LEGACY_KEYWORDS_OPTION);
        
        // Clear any backup options
        global $wpdb;
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s
        ", 'wp_autotag_pro_config_backup_%'));
    }
}