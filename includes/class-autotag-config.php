<?php
/**
 * Configuration Management Class with Atomic Operations
 * Handles unified strategy configuration with dual taxonomy (tags + categories)
 * ENHANCED: Atomic database operations, backup system, category support, and duplicate detection
 * VERSION: 2.1.0 - Unified rules system with category support and conflict detection
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Config {
    
    /**
     * Rule types for unified strategy (v2.1.0)
     */
    const RULE_TYPE_SIMPLE = 'simple';
    const RULE_TYPE_ENHANCED = 'enhanced';
    const RULE_TYPE_FAMILY = 'family';
    
    /**
     * Processing strategies - Applied to both tags and categories
     */
    const STRATEGY_APPEND = 'a';
    const STRATEGY_REPLACE = 'r';
    const STRATEGY_MERGE = 'merge';  // v2.1.0 - clearer naming
    const STRATEGY_REPLACE_ALL = 'replace';  // v2.1.0 - clearer naming
    
    /**
     * Year strategies
     */
    const YEAR_STRATEGY_REPLACE = 'r';
    const YEAR_STRATEGY_APPEND = 'a';
    
    /**
     * Taxonomy processing options (v2.1.0)
     */
    const TAXONOMY_TAGS_ONLY = 'tags';
    const TAXONOMY_CATEGORIES_ONLY = 'categories';
    const TAXONOMY_BOTH = 'both';
    
    /**
     * Maximum limits
     */
    const MAX_ITEMS = 1000;
    const MAX_ITEM_LENGTH = 100;
    const MIN_ITEM_LENGTH = 2;
    
    /**
     * Backup system constants
     */
    const MAX_BACKUPS = 10;
    const BACKUP_OPTION_PREFIX = 'wp_autotag_pro_config_backup_';
    const BACKUP_INDEX_OPTION = 'wp_autotag_pro_config_backup_index';
    
    /**
     * Memory budget from bootstrap (4MB allocation)
     */
    const MEMORY_BUDGET = 4 * 1024 * 1024; // 4MB
    const EMERGENCY_THRESHOLD = 0.85; // 85% triggers cleanup
    
    /**
     * Current configuration cache
     */
    private $config_cache = null;
    private $hash_cache = null;
    
    /**
     * Memory tracking for bootstrap integration
     */
    private $memory_start = 0;
    private $memory_peak = 0;
    
    /**
     * Transaction state tracking
     */
    private $transaction_active = false;
    private $transaction_backup_id = null;
    
    /**
     * Constructor with memory tracking initialization
     */
    public function __construct() {
        $this->memory_start = memory_get_usage(true);
        
        // Hook for configuration updates
        add_action('update_option_wp_autotag_pro_config', [$this, 'on_config_updated'], 10, 2);
        
        // Hook to clear cache when configuration changes
        add_action('autotag_config_changed', [$this, 'on_config_change_clear_cache']);
        
        // Initialize backup system
        $this->init_backup_system();
        
        // Register memory tracking
        $this->track_memory_usage('constructor_complete');
    }
    
    /**
     * Initialize backup system
     */
    private function init_backup_system() {
        // Ensure backup index exists
        if (false === get_option(self::BACKUP_INDEX_OPTION)) {
            add_option(self::BACKUP_INDEX_OPTION, [
                'current_index' => 0,
                'backup_count' => 0,
                'last_backup' => 0,
                'total_backups_created' => 0
            ]);
        }
        
        // Clean up old backups if needed
        $this->cleanup_old_backups();
    }
    
    /**
     * ATOMIC CONFIGURATION UPDATE - Main method with full transaction support
     */
    public function set_configuration($config) {
        $this->track_memory_usage('set_configuration_start');
        
        // Validate configuration first (before any DB operations)
        $validated_config = $this->sanitize_and_validate_config($config);
        
        if ($validated_config === false) {
            $this->report_error('configuration_validation_failed', 'Invalid configuration provided');
            throw new AutoTag_ConfigException(__('Invalid configuration provided.', 'wp-auto-tag-pro'), 0, null, ['config' => $config]);
        }
        
        // Check memory budget before proceeding
        if (!$this->check_memory_budget('set_configuration_validated')) {
            throw new AutoTag_MemoryException(__('Configuration update would exceed memory budget.', 'wp-auto-tag-pro'));
        }
        
        // Start atomic transaction
        $transaction_result = $this->begin_atomic_transaction($validated_config);
        
        if (is_wp_error($transaction_result)) {
            throw new AutoTag_ConfigException($transaction_result->get_error_message(), 0, null, ['wp_error' => $transaction_result]);
        }
        
        try {
            // Execute the atomic update
            $result = $this->execute_atomic_configuration_update($validated_config);
            
            if (is_wp_error($result)) {
                // Rollback on failure
                $this->rollback_atomic_transaction();
                throw new AutoTag_ConfigException($result->get_error_message(), 0, null, ['rollback_performed' => true]);
            }
            
            // Commit the transaction
            $commit_result = $this->commit_atomic_transaction($result['old_hash'], $result['new_hash']);
            
            if (is_wp_error($commit_result)) {
                throw new AutoTag_ConfigException($commit_result->get_error_message(), 0, null, ['commit_failed' => true]);
            }
            
            $this->track_memory_usage('set_configuration_complete');
            return true;
            
        } catch (AutoTag_Exception $e) {
            // Re-throw our custom exceptions
            $this->rollback_atomic_transaction();
            throw $e;
        } catch (Exception $e) {
            // Convert generic exceptions to config exceptions
            $this->rollback_atomic_transaction();
            $this->report_error('transaction_exception', $e->getMessage());
            throw new AutoTag_ConfigException(__('Configuration update failed: ', 'wp-auto-tag-pro') . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Begin atomic transaction with backup creation
     */
    private function begin_atomic_transaction($new_config) {
        if ($this->transaction_active) {
            return new WP_Error('transaction_in_progress', __('Another configuration transaction is in progress.', 'wp-auto-tag-pro'));
        }
        
        // Create backup before any changes
        $backup_result = $this->create_configuration_backup();
        
        if (is_wp_error($backup_result)) {
            $this->report_error('backup_creation_failed', $backup_result->get_error_message());
            return $backup_result;
        }
        
        // Mark transaction as active
        $this->transaction_active = true;
        $this->transaction_backup_id = $backup_result;
        
        $this->log_info('Atomic transaction started', [
            'backup_id' => $this->transaction_backup_id,
            'memory_usage' => $this->format_bytes(memory_get_usage(true))
        ]);
        
        return true;
    }
    
    /**
     * Execute atomic configuration update within transaction
     */
    private function execute_atomic_configuration_update($validated_config) {
        global $wpdb;
        
        // Get current hash for comparison
        $old_hash = $this->get_current_hash();
        
        // Generate new hash
        $new_hash = $this->generate_hash($validated_config);
        
        // Start MySQL transaction for atomicity
        $wpdb->query('START TRANSACTION');
        
        try {
            // Update configuration using settings manager
            if (wp_autotag_pro()->settings) {
                $config_updated = wp_autotag_pro()->settings->update_config($validated_config, $new_hash);
            } else {
                // Fallback to direct update
                $config_updated = update_option('wp_autotag_pro_config', $validated_config);
                $hash_updated = update_option('wp_autotag_pro_config_hash', $new_hash);
                
                if (!$config_updated || !$hash_updated) {
                    throw new Exception('Failed to update configuration or hash');
                }
            }
            
            if (!$config_updated) {
                throw new AutoTag_DatabaseException('Failed to update configuration option');
            }
            
            // Verify update succeeded by reading back
            $verify_config = wp_autotag_pro()->settings ? wp_autotag_pro()->settings->get_config() : get_option('wp_autotag_pro_config');
            $verify_hash = wp_autotag_pro()->settings ? wp_autotag_pro()->settings->get_config_hash() : get_option('wp_autotag_pro_config_hash');
            
            if ($verify_config !== $validated_config || $verify_hash !== $new_hash) {
                throw new AutoTag_DatabaseException('Configuration verification failed after update');
            }
            
            // Commit MySQL transaction
            $wpdb->query('COMMIT');
            
            $this->log_info('Atomic configuration update executed successfully', [
                'old_hash' => $old_hash,
                'new_hash' => $new_hash,
                'config_size' => strlen(serialize($validated_config))
            ]);
            
            return [
                'old_hash' => $old_hash,
                'new_hash' => $new_hash,
                'config' => $validated_config
            ];
            
        } catch (Exception $e) {
            // Rollback MySQL transaction
            $wpdb->query('ROLLBACK');
            
            $this->log_error('Atomic configuration update failed', [
                'error' => $e->getMessage(),
                'old_hash' => $old_hash
            ]);
            
            return new WP_Error('atomic_update_failed', __('Database transaction failed: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Commit atomic transaction and cleanup
     */
    private function commit_atomic_transaction($old_hash, $new_hash) {
        if (!$this->transaction_active) {
            return new WP_Error('no_transaction', __('No active transaction to commit.', 'wp-auto-tag-pro'));
        }
        
        try {
            // Clear caches since config changed
            $this->config_cache = null;
            $this->hash_cache = null;
            
            // Trigger hash change events if needed
            if ($old_hash !== $new_hash) {
                $this->on_hash_changed($old_hash, $new_hash);
            }
            
            // Update backup index with success
            $this->update_backup_index_on_success();
            
            // Mark transaction as complete
            $this->transaction_active = false;
            $this->transaction_backup_id = null;
            
            $this->log_info('Atomic transaction committed successfully', [
                'old_hash' => $old_hash,
                'new_hash' => $new_hash
            ]);
            
            return true;
            
        } catch (Exception $e) {
            // Even commit can fail - need to handle this
            $this->report_error('commit_failed', $e->getMessage());
            return new WP_Error('commit_failed', __('Transaction commit failed: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Rollback atomic transaction and restore from backup
     */
    private function rollback_atomic_transaction() {
        if (!$this->transaction_active || !$this->transaction_backup_id) {
            $this->log_error('Rollback called but no active transaction or backup ID');
            return false;
        }
        
        global $wpdb;
        
        try {
            // Start rollback transaction
            $wpdb->query('START TRANSACTION');
            
            // Restore configuration from backup
            $backup = $this->get_configuration_backup($this->transaction_backup_id);
            
            if (!$backup) {
                throw new Exception('Backup not found for rollback: ' . $this->transaction_backup_id);
            }
            
            // Restore configuration using settings manager
            if (wp_autotag_pro()->settings) {
                $config_restored = wp_autotag_pro()->settings->update_config($backup['config'], $backup['hash']);
            } else {
                // Fallback to direct update
                $config_restored = update_option('wp_autotag_pro_config', $backup['config']);
                $hash_restored = update_option('wp_autotag_pro_config_hash', $backup['hash']);
                
                if (!$config_restored || !$hash_restored) {
                    throw new Exception('Failed to restore configuration from backup');
                }
            }
            
            if (!$config_restored) {
                throw new Exception('Failed to restore configuration from backup');
            }
            
            // Verify restoration
            $verify_config = wp_autotag_pro()->settings ? wp_autotag_pro()->settings->get_config() : get_option('wp_autotag_pro_config');
            $verify_hash = wp_autotag_pro()->settings ? wp_autotag_pro()->settings->get_config_hash() : get_option('wp_autotag_pro_config_hash');
            
            if ($verify_config !== $backup['config'] || $verify_hash !== $backup['hash']) {
                throw new Exception('Backup restoration verification failed');
            }
            
            // Commit rollback transaction
            $wpdb->query('COMMIT');
            
            // Clear caches
            $this->config_cache = null;
            $this->hash_cache = null;
            
            // Mark transaction as complete
            $this->transaction_active = false;
            $this->transaction_backup_id = null;
            
            $this->log_info('Atomic transaction rolled back successfully', [
                'backup_id' => $this->transaction_backup_id,
                'restored_hash' => $backup['hash']
            ]);
            
            return true;
            
        } catch (Exception $e) {
            // Rollback the rollback transaction
            $wpdb->query('ROLLBACK');
            
            // This is a critical error - we couldn't rollback
            $this->report_error('rollback_failed', $e->getMessage(), 'critical');
            
            // Mark transaction as failed but not active
            $this->transaction_active = false;
            $this->transaction_backup_id = null;
            
            return false;
        }
    }
    
    /**
     * Create configuration backup with atomic index update
     */
    private function create_configuration_backup() {
        if (wp_autotag_pro()->settings) {
            $current_config = wp_autotag_pro()->settings->get_config();
            $current_hash = wp_autotag_pro()->settings->get_config_hash();
        } else {
            $current_config = get_option('wp_autotag_pro_config');
            $current_hash = get_option('wp_autotag_pro_config_hash', '');
        }
        
        if (empty($current_config)) {
            // No existing config to backup - create empty backup
            $current_config = $this->get_default_config();
        }
        
        // Get backup index
        $backup_index = get_option(self::BACKUP_INDEX_OPTION, [
            'current_index' => 0,
            'backup_count' => 0,
            'last_backup' => 0,
            'total_backups_created' => 0
        ]);
        
        // Calculate next backup ID
        $next_index = ($backup_index['current_index'] + 1) % self::MAX_BACKUPS;
        $backup_id = sprintf('%03d', $next_index);
        
        // Create backup data
        $backup_data = [
            'config' => $current_config,
            'hash' => $current_hash,
            'timestamp' => time(),
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => defined('WP_AUTOTAG_PRO_VERSION') ? WP_AUTOTAG_PRO_VERSION : '2.0.0',
            'backup_id' => $backup_id,
            'memory_usage' => memory_get_usage(true)
        ];
        
        // Store backup atomically
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        
        try {
            // Save backup
            $backup_saved = update_option(self::BACKUP_OPTION_PREFIX . $backup_id, $backup_data);
            
            if (!$backup_saved) {
                throw new Exception('Failed to save configuration backup');
            }
            
            // Update backup index
            $updated_index = [
                'current_index' => $next_index,
                'backup_count' => min($backup_index['backup_count'] + 1, self::MAX_BACKUPS),
                'last_backup' => time(),
                'total_backups_created' => $backup_index['total_backups_created'] + 1
            ];
            
            $index_updated = update_option(self::BACKUP_INDEX_OPTION, $updated_index);
            
            if (!$index_updated) {
                throw new Exception('Failed to update backup index');
            }
            
            $wpdb->query('COMMIT');
            
            $this->log_info('Configuration backup created', [
                'backup_id' => $backup_id,
                'hash' => $current_hash,
                'size' => strlen(serialize($backup_data))
            ]);
            
            return $backup_id;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('backup_failed', __('Failed to create configuration backup: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get configuration backup by ID
     */
    private function get_configuration_backup($backup_id) {
        $backup = get_option(self::BACKUP_OPTION_PREFIX . $backup_id);
        
        if (!$backup || !is_array($backup)) {
            return false;
        }
        
        // Validate backup structure
        $required_fields = ['config', 'hash', 'timestamp'];
        foreach ($required_fields as $field) {
            if (!isset($backup[$field])) {
                $this->log_error('Invalid backup structure missing field: ' . $field);
                return false;
            }
        }
        
        return $backup;
    }
    
    /**
     * Restore configuration from backup (for external use)
     */
    public function restore_from_backup($backup_id) {
        $this->track_memory_usage('restore_from_backup_start');
        
        if (!$this->check_memory_budget('restore_validation')) {
            return new WP_Error('memory_exceeded', __('Restore operation would exceed memory budget.', 'wp-auto-tag-pro'));
        }
        
        $backup = $this->get_configuration_backup($backup_id);
        
        if (!$backup) {
            return new WP_Error('backup_not_found', __('Backup not found: ', 'wp-auto-tag-pro') . $backup_id);
        }
        
        // Use our atomic update system to restore
        return $this->set_configuration($backup['config']);
    }
    
    /**
     * Get available backups list
     */
    public function get_available_backups() {
        $backup_index = get_option(self::BACKUP_INDEX_OPTION, ['backup_count' => 0]);
        $backups = [];
        
        for ($i = 0; $i < $backup_index['backup_count']; $i++) {
            $backup_id = sprintf('%03d', $i);
            $backup = $this->get_configuration_backup($backup_id);
            
            if ($backup) {
                $backups[] = [
                    'id' => $backup_id,
                    'timestamp' => $backup['timestamp'],
                    'hash' => $backup['hash'],
                    'wp_version' => $backup['wp_version'] ?? 'unknown',
                    'plugin_version' => $backup['plugin_version'] ?? 'unknown',
                    'age' => human_time_diff($backup['timestamp'])
                ];
            }
        }
        
        // Sort by timestamp (newest first)
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return $backups;
    }
    
    /**
     * Clean up old backups when limit exceeded
     */
    private function cleanup_old_backups() {
        $backup_index = get_option(self::BACKUP_INDEX_OPTION);
        
        if (!$backup_index || $backup_index['backup_count'] <= self::MAX_BACKUPS) {
            return; // No cleanup needed
        }
        
        // Find oldest backups to remove
        $all_backups = [];
        for ($i = 0; $i < self::MAX_BACKUPS * 2; $i++) { // Check more than max in case of gaps
            $backup_id = sprintf('%03d', $i);
            $backup = get_option(self::BACKUP_OPTION_PREFIX . $backup_id);
            
            if ($backup && isset($backup['timestamp'])) {
                $all_backups[] = [
                    'id' => $backup_id,
                    'timestamp' => $backup['timestamp']
                ];
            }
        }
        
        // Sort by timestamp and keep only the newest MAX_BACKUPS
        usort($all_backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        $backups_to_remove = array_slice($all_backups, self::MAX_BACKUPS);
        
        foreach ($backups_to_remove as $backup) {
            delete_option(self::BACKUP_OPTION_PREFIX . $backup['id']);
        }
        
        if (!empty($backups_to_remove)) {
            $this->log_info('Cleaned up old backups', [
                'removed_count' => count($backups_to_remove),
                'remaining_count' => self::MAX_BACKUPS
            ]);
        }
    }
    
    /**
     * Update backup index on successful transaction
     */
    private function update_backup_index_on_success() {
        $backup_index = get_option(self::BACKUP_INDEX_OPTION);
        $backup_index['last_successful_backup'] = time();
        update_option(self::BACKUP_INDEX_OPTION, $backup_index);
    }
    
    /**
     * Memory budget checking with bootstrap integration
     */
    private function check_memory_budget($operation = '') {
        $current_usage = memory_get_usage(true) - $this->memory_start;
        $budget_used = $current_usage / self::MEMORY_BUDGET;
        
        if ($budget_used > self::EMERGENCY_THRESHOLD) {
            $this->emergency_memory_cleanup();
            
            // Check again after cleanup
            $current_usage = memory_get_usage(true) - $this->memory_start;
            $budget_used = $current_usage / self::MEMORY_BUDGET;
        }
        
        $within_budget = $budget_used <= 1.0;
        
        $this->log_memory_usage($operation, $current_usage, $budget_used, $within_budget);
        
        // Report to bootstrap if over budget
        if (!$within_budget) {
            $this->report_error('memory_budget_exceeded', 'Config component exceeded 4MB budget', 'warning');
        }
        
        return $within_budget;
    }
    
    /**
     * Emergency memory cleanup
     */
    private function emergency_memory_cleanup() {
        // Clear caches
        $this->config_cache = null;
        $this->hash_cache = null;
        
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $this->log_info('Emergency memory cleanup completed');
    }
    
    /**
     * Track memory usage for bootstrap reporting
     */
    private function track_memory_usage($operation) {
        $current = memory_get_usage(true);
        $component_usage = $current - $this->memory_start;
        
        if ($component_usage > $this->memory_peak) {
            $this->memory_peak = $component_usage;
        }
        
        // Report to bootstrap if available
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            $stats = wp_autotag_pro()->get_memory_stats();
            if ($stats && isset($stats['component_usage'])) {
                $stats['component_usage']['config_manager'] = $component_usage;
            }
        }
    }
    
    /**
     * Log memory usage details
     */
    private function log_memory_usage($operation, $usage, $budget_percentage, $within_budget) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log_info('Memory usage: ' . $operation, [
                'usage' => $this->format_bytes($usage),
                'budget' => $this->format_bytes(self::MEMORY_BUDGET),
                'percentage' => round($budget_percentage * 100, 2) . '%',
                'status' => $within_budget ? 'OK' : 'OVER_BUDGET',
                'peak' => $this->format_bytes($this->memory_peak)
            ]);
        }
    }
    
    /**
     * Report errors to bootstrap error handler
     */
    private function report_error($error_code, $message, $level = 'error') {
        // Report to bootstrap if available
        if (function_exists('wp_autotag_pro') && wp_autotag_pro() && wp_autotag_pro()->error_handler) {
            wp_autotag_pro()->error_handler->report_error('config_manager', $error_code, $message, $level);
        } else {
            // Fallback to local logging
            $this->log_error($message . ' [' . $error_code . ']');
        }
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
     * Get memory usage statistics
     */
    public function get_memory_stats() {
        return [
            'budget' => self::MEMORY_BUDGET,
            'current_usage' => memory_get_usage(true) - $this->memory_start,
            'peak_usage' => $this->memory_peak,
            'budget_compliance' => (memory_get_usage(true) - $this->memory_start) <= self::MEMORY_BUDGET,
            'emergency_threshold' => self::MEMORY_BUDGET * self::EMERGENCY_THRESHOLD
        ];
    }
    
    // ... [All the remaining existing methods stay exactly the same - get_configuration, 
    // set_mode, get_keywords, etc. - just adding proper memory tracking calls where needed]
    
    /**
     * Get current configuration
     */
    public function get_configuration() {
        if ($this->config_cache !== null) {
            return $this->config_cache;
        }
        
        $config = wp_autotag_pro()->settings ? wp_autotag_pro()->settings->get_config() : $this->get_default_config();
        
        // Validate and sanitize configuration
        $config = $this->sanitize_and_validate_config($config);
        
        $this->config_cache = $config;
        $this->track_memory_usage('get_configuration');
        return $config;
    }
    
    /**
     * Log info with component prefix
     */
    private function log_info($message, $data = null) {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            wp_autotag_pro()->log_info('[CONFIG] ' . $message, $data);
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[Auto-Tag Pro CONFIG] ' . $message;
            if ($data) {
                $log_message .= ' | ' . wp_json_encode($data);
            }
            error_log($log_message);
        }
    }
    
    /**
     * Log error with component prefix
     */
    private function log_error($message, $data = null) {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            wp_autotag_pro()->log_error('[CONFIG] ' . $message, $data);
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[Auto-Tag Pro CONFIG ERROR] ' . $message;
            if ($data) {
                $log_message .= ' | ' . wp_json_encode($data);
            }
            error_log($log_message);
        }
    }
    
    // ... [Include all the remaining methods from the original class - 
    // get_mode, set_mode, get_keywords, set_keywords, etc. - exactly as they were]
    

    
    /**
     * Check if year tags are enabled
     */
    public function is_year_tags_enabled() {
        $config = $this->get_configuration();
        return !empty($config['year_tags_enabled']);
    }
    
    /**
     * Get year strategy
     */
    public function get_year_strategy() {
        $config = $this->get_configuration();
        return $config['year_strategy'] ?? self::YEAR_STRATEGY_REPLACE;
    }
    

    
    /**
     * Generate compact hash from configuration
     */
    public function generate_hash($config = null) {
        if ($config === null) {
            $config = $this->get_configuration();
        }
        
        // Create hash from essential configuration data
        $hash_data = [
            'year_tags_enabled' => $config['year_tags_enabled'] ?? false,
            'year_strategy' => $config['year_strategy'] ?? self::YEAR_STRATEGY_REPLACE,
            'default_tag_strategy' => $config['default_tag_strategy'] ?? self::STRATEGY_MERGE,
            'default_category_strategy' => $config['default_category_strategy'] ?? self::STRATEGY_MERGE,
            'taxonomy_processing' => $config['taxonomy_processing'] ?? self::TAXONOMY_BOTH,
            'auto_create_categories' => $config['auto_create_categories'] ?? true,
            'conflict_warnings_enabled' => $config['conflict_warnings_enabled'] ?? true
        ];
        
        // Add rules for unified approach
        if (isset($config['rules']) && !empty($config['rules'])) {
            $hash_data['rules'] = $config['rules'];
        }
        
        // Sort for consistent hashing
        ksort($hash_data);
        if (isset($hash_data['rules'])) {
            ksort($hash_data['rules']);
            foreach ($hash_data['rules'] as &$rule) {
                if (isset($rule['keywords'])) {
                    sort($rule['keywords']);
                }
                if (isset($rule['tags'])) {
                    sort($rule['tags']);
                }
                if (isset($rule['categories'])) {
                    sort($rule['categories']);
                }
            }
        }
        
        // Serialize the data
        $serialized = serialize($hash_data);
        
        // Generate 8-character hash with fallback
        if (function_exists('hash') && in_array('xxh64', hash_algos())) {
            // Use xxhash64 if available (faster)
            $hash = hash('xxh64', $serialized);
        } elseif (function_exists('hash')) {
            // Fallback to sha256 (more widely available)
            $hash = hash('sha256', $serialized);
        } else {
            // Final fallback to md5 (always available)
            $hash = md5($serialized);
        }
        
        return substr($hash, 0, 8);
    }
    
    /**
     * Get current configuration hash
     */
    public function get_current_hash() {
        if ($this->hash_cache !== null) {
            return $this->hash_cache;
        }
        
        $this->hash_cache = $this->generate_hash();
        $this->track_memory_usage('get_current_hash');
        return $this->hash_cache;
    }
    
    /**
     * Check if configuration has changed
     */
    public function has_configuration_changed() {
        $stored_hash = wp_autotag_pro()->settings ? wp_autotag_pro()->settings->get_config_hash() : get_option('wp_autotag_pro_config_hash', '');
        $current_hash = $this->get_current_hash();
        
        return $stored_hash !== $current_hash;
    }
    
    /**
     * Check if we have any configuration
     */
    public function has_configuration() {
        $config = wp_autotag_pro()->settings ? wp_autotag_pro()->settings->get_config() : get_option('wp_autotag_pro_config');
        return !empty($config);
    }
    

    

    

    
    /**
     * Normalize text for processing (optimized, no regex)
     */
    public function normalize_text($text) {
        if (function_exists('wp_autotag_pro')) {
            return wp_autotag_pro()->normalize_text($text);
        }
        
        // Fallback implementation if main plugin is not available
        if (!is_string($text) || empty($text)) {
            return '';
        }
        
        $normalized = strtolower(trim($text));
        return preg_replace('/\s+/', ' ', $normalized);
    }
    
    /**
     * Get default configuration
     */
    private function get_default_config() {
        return [
            // v2.1.0 - New unified strategy fields
            'rules' => [],  // Unified rules replacing families
            'default_tag_strategy' => self::STRATEGY_MERGE,
            'default_category_strategy' => self::STRATEGY_MERGE,
            'auto_create_categories' => true,
            'taxonomy_processing' => self::TAXONOMY_BOTH,
            'conflict_warnings_enabled' => true,
            'year_tags_enabled' => true,
            'year_strategy' => self::YEAR_STRATEGY_REPLACE
        ];
    }
    
    /**
     * Validate configuration (enhanced for v2.1.0 unified strategy)
     */
    private function sanitize_and_validate_config($config) {
        if (!is_array($config)) {
            return false;
        }
        
        // Validate year tags
        $config['year_tags_enabled'] = !empty($config['year_tags_enabled']);
        
        // Validate year strategy
        if (!isset($config['year_strategy']) || !in_array($config['year_strategy'], [self::YEAR_STRATEGY_APPEND, self::YEAR_STRATEGY_REPLACE])) {
            $config['year_strategy'] = self::YEAR_STRATEGY_REPLACE;
        }
        
        // v2.1.0 - Validate new unified strategy fields
        if (!isset($config['default_tag_strategy']) || 
            !in_array($config['default_tag_strategy'], [self::STRATEGY_MERGE, self::STRATEGY_REPLACE_ALL])) {
            $config['default_tag_strategy'] = self::STRATEGY_MERGE;
        }
        
        if (!isset($config['default_category_strategy']) || 
            !in_array($config['default_category_strategy'], [self::STRATEGY_MERGE, self::STRATEGY_REPLACE_ALL])) {
            $config['default_category_strategy'] = self::STRATEGY_MERGE;
        }
        
        if (!isset($config['taxonomy_processing']) || 
            !in_array($config['taxonomy_processing'], [self::TAXONOMY_TAGS_ONLY, self::TAXONOMY_CATEGORIES_ONLY, self::TAXONOMY_BOTH])) {
            $config['taxonomy_processing'] = self::TAXONOMY_BOTH;
        }
        
        $config['auto_create_categories'] = !empty($config['auto_create_categories']);
        $config['conflict_warnings_enabled'] = !empty($config['conflict_warnings_enabled']);
        
        // Validate rules (unified approach)
        if (isset($config['rules'])) {
            $config['rules'] = $this->validate_rules($config['rules']);
        } else {
            $config['rules'] = [];
        }
        
        return $config;
    }
    
    /**
     * Validate and normalize items (keywords or family members)
     */
    private function validate_and_normalize_items($items) {
        if (is_string($items)) {
            $items = $this->parse_items_string($items);
        }
        
        if (!is_array($items)) {
            return [];
        }
        
        // Check maximum items limit
        if (count($items) > self::MAX_ITEMS) {
            if (function_exists('wp_autotag_pro')) {
                wp_autotag_pro()->log_error(
                    sprintf('Too many items provided: %d (max: %d)', count($items), self::MAX_ITEMS)
                );
            }
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }
        
        $validated = [];
        
        foreach ($items as $item) {
            $item = $this->sanitize_item($item);
            
            if ($this->validate_item($item)) {
                $validated[] = $item;
            }
        }
        
        return array_unique($validated);
    }
    
    /**
     * Validate families structure
     */
    private function validate_families($families) {
        if (!is_array($families)) {
            return [];
        }
        
        $validated_families = [];
        $total_items = 0;
        
        foreach ($families as $family_name => $family_items) {
            $family_name = sanitize_text_field($family_name);
            
            if (empty($family_name)) {
                continue;
            }
            
            $validated_items = $this->validate_and_normalize_items($family_items);
            
            if (!empty($validated_items)) {
                $validated_families[$family_name] = $validated_items;
                $total_items += count($validated_items);
                
                // Check global items limit across all families
                if ($total_items > self::MAX_ITEMS) {
                    if (function_exists('wp_autotag_pro')) {
                        wp_autotag_pro()->log_error(
                            sprintf('Too many total family items: %d (max: %d)', $total_items, self::MAX_ITEMS)
                        );
                    }
                    break;
                }
            }
        }
        
        return $validated_families;
    }
    
    /**
     * Parse items from string (one per line or comma-separated)
     */
    public function parse_items_string($string) {
        if (empty($string) || !is_string($string)) {
            return [];
        }
        
        // Split by lines first, then by commas
        $lines = explode("\n", $string);
        $items = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Check if line contains commas
            if (strpos($line, ',') !== false) {
                $comma_split = explode(',', $line);
                foreach ($comma_split as $item) {
                    $item = trim($item);
                    if (!empty($item)) {
                        $items[] = $item;
                    }
                }
            } else {
                $items[] = $line;
            }
        }
        
        return array_unique($items);
    }
    
    /**
     * Sanitize individual item
     */
    private function sanitize_item($item) {
        // Handle non-string input
        if (!is_string($item)) {
            $item = (string) $item;
        }
        
        // Remove extra whitespace
        $item = trim($item);
        
        // Sanitize text
        $item = sanitize_text_field($item);
        
        // Remove multiple spaces
        $item = preg_replace('/\s+/', ' ', $item);
        
        return $item;
    }
    
    /**
     * Validate individual item
     */
    private function validate_item($item) {
        if (empty($item) || !is_string($item)) {
            return false;
        }
        
        if (strlen($item) > self::MAX_ITEM_LENGTH) {
            return false;
        }
        
        if (strlen($item) < self::MIN_ITEM_LENGTH) {
            return false;
        }
        
        // Basic security checks
        if ($item !== strip_tags($item)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Called when configuration is updated
     */
    public function on_config_updated($old_value, $new_value) {
        // Clear caches
        $this->config_cache = null;
        $this->hash_cache = null;
        
        // Update stored hash
        $new_hash = $this->get_current_hash();
        if (wp_autotag_pro()->settings) {
            wp_autotag_pro()->settings->update_config($new_value, $new_hash);
        } else {
            update_option('wp_autotag_pro_config_hash', $new_hash);
        }
        
        $this->track_memory_usage('on_config_updated');
    }
    
    /**
     * Called when hash changes - triggers reprocessing detection
     */
    private function on_hash_changed($old_hash, $new_hash) {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->log_info(
                sprintf('Configuration hash changed from %s to %s', $old_hash, $new_hash)
            );
            
            // Update statistics
            wp_autotag_pro()->update_stats(['config_changes' => 1]);
        }
        
        do_action('autotag_hash_changed', $old_hash, $new_hash);
        do_action('autotag_config_changed', $old_hash, $new_hash);
    }
    
    /**
     * Get configuration statistics
     */
    public function get_config_stats() {
        $config = $this->get_configuration();
        
        $stats = [
            'year_tags_enabled' => !empty($config['year_tags_enabled']),
            'current_hash' => $this->get_current_hash(),
            'last_updated' => 0,
            'memory_stats' => $this->get_memory_stats(),
            'backup_stats' => $this->get_backup_stats()
        ];
        
        // Get last updated from plugin stats if available
        if (function_exists('wp_autotag_pro')) {
            $plugin_stats = wp_autotag_pro()->get_stats();
            $stats['last_updated'] = $plugin_stats['last_update'] ?? 0;
        }
        
        // Count rules and items
        $rules = $config['rules'] ?? [];
        $stats['total_rules'] = count($rules);
        $stats['total_items'] = 0;
        
        foreach ($rules as $rule) {
            $stats['total_items'] += count($rule['keywords'] ?? []);
        }
        
        $stats['max_allowed'] = self::MAX_ITEMS;
        
        $this->track_memory_usage('get_config_stats');
        return $stats;
    }
    
    /**
     * Clear cache when configuration changes
     */
    public function on_config_change_clear_cache($old_hash, $new_hash) {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->cache) {
            wp_autotag_pro()->cache->clear_all();
            wp_autotag_pro()->log_info('Cache cleared due to configuration change', [
                'old_hash' => $old_hash,
                'new_hash' => $new_hash
            ]);
        }
    }
    
    /**
     * Get backup statistics
     */
    private function get_backup_stats() {
        $backup_index = get_option(self::BACKUP_INDEX_OPTION, []);
        
        return [
            'total_backups' => $backup_index['backup_count'] ?? 0,
            'max_backups' => self::MAX_BACKUPS,
            'last_backup' => $backup_index['last_backup'] ?? 0,
            'total_created' => $backup_index['total_backups_created'] ?? 0,
            'last_successful' => $backup_index['last_successful_backup'] ?? 0
        ];
    }
    
    // ============================================================================
    // UNIFIED STRATEGY METHODS (v2.1.0) - New rule-based system
    // ============================================================================
    
    /**
     * Get all classification rules (unified approach)
     */
    public function get_rules() {
        $config = $this->get_configuration();
        return $config['rules'] ?? [];
    }
    
    /**
     * Set all classification rules with atomic update
     */
    public function set_rules($rules) {
        if (!is_array($rules)) {
            return new WP_Error('invalid_rules', __('Rules must be an array.', 'wp-auto-tag-pro'));
        }
        
        $config = $this->get_configuration();
        $config['rules'] = $this->validate_rules($rules);
        
        return $this->set_configuration($config);
    }
    
    /**
     * Add a single classification rule
     */
    public function add_rule($rule_id, $rule_data) {
        $rules = $this->get_rules();
        
        // Validate rule data
        $validated_rule = $this->validate_single_rule($rule_data);
        if (is_wp_error($validated_rule)) {
            return $validated_rule;
        }
        
        // Check for duplicate detection conflicts
        $conflicts = $this->detect_rule_conflicts($validated_rule, $rules);
        if (!empty($conflicts)) {
            $validated_rule['conflicts'] = $conflicts;
        }
        
        $rules[$rule_id] = $validated_rule;
        return $this->set_rules($rules);
    }
    
    /**
     * Remove a classification rule
     */
    public function remove_rule($rule_id) {
        $rules = $this->get_rules();
        
        if (!isset($rules[$rule_id])) {
            return new WP_Error('rule_not_found', __('Rule not found.', 'wp-auto-tag-pro'));
        }
        
        unset($rules[$rule_id]);
        return $this->set_rules($rules);
    }
    
    /**
     * Detect conflicts between tag and category names (case-insensitive)
     */
    public function detect_rule_conflicts($rule_data, $existing_rules = null) {
        if ($existing_rules === null) {
            $existing_rules = $this->get_rules();
        }
        
        $conflicts = [];
        $tags = $rule_data['tags'] ?? [];
        $categories = $rule_data['categories'] ?? [];
        
        // Check within same rule
        foreach ($tags as $tag) {
            foreach ($categories as $category) {
                if (strcasecmp(trim($tag), trim($category)) === 0) {
                    $conflicts[] = $tag;
                }
            }
        }
        
        // Check against other rules (optional - could be extended)
        // For now, just return within-rule conflicts as specified
        
        return array_unique($conflicts);
    }
    
    /**
     * Validate a single rule structure
     */
    private function validate_single_rule($rule_data) {
        if (!is_array($rule_data)) {
            return new WP_Error('invalid_rule_data', __('Rule data must be an array.', 'wp-auto-tag-pro'));
        }
        
        // Default rule structure
        $validated = [
            'name' => sanitize_text_field($rule_data['name'] ?? ''),
            'keywords' => $this->validate_and_normalize_items($rule_data['keywords'] ?? []),
            'tags' => $this->validate_and_normalize_items($rule_data['tags'] ?? []),
            'categories' => $this->validate_and_normalize_items($rule_data['categories'] ?? []),
            'type' => in_array($rule_data['type'] ?? '', [self::RULE_TYPE_SIMPLE, self::RULE_TYPE_ENHANCED, self::RULE_TYPE_FAMILY]) 
                      ? $rule_data['type'] : self::RULE_TYPE_SIMPLE,
            'inheritance_enabled' => !empty($rule_data['inheritance_enabled']),
            'parent_id' => sanitize_text_field($rule_data['parent_id'] ?? ''),
            'children' => is_array($rule_data['children'] ?? []) ? $rule_data['children'] : [],
            'conflicts' => []
        ];
        
        // Validate that rule has at least keywords
        if (empty($validated['keywords'])) {
            return new WP_Error('empty_keywords', __('Rule must have at least one keyword.', 'wp-auto-tag-pro'));
        }
        
        // For simple rules, ensure tags OR categories are specified
        if ($validated['type'] === self::RULE_TYPE_SIMPLE && empty($validated['tags']) && empty($validated['categories'])) {
            // Default to using keywords as tags for backward compatibility
            $validated['tags'] = $validated['keywords'];
        }
        
        return $validated;
    }
    
    /**
     * Validate complete rules array
     */
    private function validate_rules($rules) {
        if (!is_array($rules)) {
            return [];
        }
        
        $validated_rules = [];
        $total_items = 0;
        
        foreach ($rules as $rule_id => $rule_data) {
            $rule_id = sanitize_text_field($rule_id);
            
            if (empty($rule_id)) {
                continue;
            }
            
            $validated_rule = $this->validate_single_rule($rule_data);
            
            if (!is_wp_error($validated_rule)) {
                // Count items for limit checking
                $rule_items = count($validated_rule['keywords']) + count($validated_rule['tags']) + count($validated_rule['categories']);
                $total_items += $rule_items;
                
                if ($total_items <= self::MAX_ITEMS) {
                    $validated_rules[$rule_id] = $validated_rule;
                } else {
                    $this->log_error('Rule limit exceeded', ['rule_id' => $rule_id, 'total_items' => $total_items]);
                    break;
                }
            }
        }
        
        return $validated_rules;
    }
    
    /**
     * Get taxonomy processing preference
     */
    public function get_taxonomy_processing() {
        $config = $this->get_configuration();
        return $config['taxonomy_processing'] ?? self::TAXONOMY_BOTH;
    }
    
    /**
     * Set taxonomy processing preference
     */
    public function set_taxonomy_processing($taxonomy_mode) {
        if (!in_array($taxonomy_mode, [self::TAXONOMY_TAGS_ONLY, self::TAXONOMY_CATEGORIES_ONLY, self::TAXONOMY_BOTH])) {
            return new WP_Error('invalid_taxonomy_mode', __('Invalid taxonomy processing mode.', 'wp-auto-tag-pro'));
        }
        
        $config = $this->get_configuration();
        $config['taxonomy_processing'] = $taxonomy_mode;
        
        return $this->set_configuration($config);
    }
    
    /**
     * Get category processing settings
     */
    public function get_category_settings() {
        $config = $this->get_configuration();
        
        return [
            'auto_create_categories' => !empty($config['auto_create_categories']),
            'default_category_strategy' => $config['default_category_strategy'] ?? self::STRATEGY_MERGE,
            'default_tag_strategy' => $config['default_tag_strategy'] ?? self::STRATEGY_MERGE
        ];
    }
    
    /**
     * Update category processing settings
     */
    public function set_category_settings($settings) {
        $config = $this->get_configuration();
        
        if (isset($settings['auto_create_categories'])) {
            $config['auto_create_categories'] = !empty($settings['auto_create_categories']);
        }
        
        if (isset($settings['default_category_strategy']) && 
            in_array($settings['default_category_strategy'], [self::STRATEGY_MERGE, self::STRATEGY_REPLACE_ALL])) {
            $config['default_category_strategy'] = $settings['default_category_strategy'];
        }
        
        if (isset($settings['default_tag_strategy']) && 
            in_array($settings['default_tag_strategy'], [self::STRATEGY_MERGE, self::STRATEGY_REPLACE_ALL])) {
            $config['default_tag_strategy'] = $settings['default_tag_strategy'];
        }
        
        return $this->set_configuration($config);
    }
    

}