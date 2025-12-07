<?php
/**
 * Bulk Processing Integration File
 * Integrates rollback system and reporting into the main bulk processing
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include the extensions trait
require_once __DIR__ . '/class-autotag-bulk-extensions.php';

/**
 * Modified AutoTag_Bulk class with extended functionality
 * This file provides integration methods to work with the main bulk class
 */
class AutoTag_Bulk_Enhanced extends AutoTag_Bulk {
    
    // Use the extensions trait to add rollback and reporting functionality
    use AutoTag_Bulk_Extensions;
    
    /**
     * Enhanced complete_queue method with detailed reporting
     */
    protected function complete_queue_with_enhanced_reporting($queue_id) {
        $queue = $this->get_queue_safely($queue_id);
        
        if (!$queue) {
            return;
        }
        
        // Complete the queue using parent method
        $this->complete_queue($queue_id);
        
        // Generate comprehensive completion report
        $report_data = [
            'report_id' => $queue_id . '_completion',
            'type' => 'completion',
            'created_at' => time(),
            'total_posts' => $queue['total_posts'],
            'processed_posts' => $queue['processed_posts'],
            'errors' => $queue['errors'],
            'skipped_posts' => $queue['total_posts'] - $queue['processed_posts'] - $queue['errors'],
            'details' => wp_json_encode([
                'action' => $queue['action'],
                'batch_size' => $queue['batch_size'],
                'total_batches' => $queue['total_batches'],
                'tags_added' => $queue['tags_added'],
                'year_tags_added' => $queue['year_tags_added'] ?? 0,
                'families_matched' => $queue['families_matched'] ?? 0,
                'keyword_matches' => $queue['keyword_matches'] ?? 0,
                'duration' => $queue['total_duration'] ?? 0,
                'posts_per_second' => $queue['posts_per_second'] ?? 0,
                'tags_per_post' => $queue['tags_per_post'] ?? 0
            ])
        ];
        
        $this->store_processing_report($report_data);
        
        // Send enhanced notification with report link
        $this->maybe_send_enhanced_completion_notification($queue, $report_data['report_id']);
    }
    
    /**
     * Enhanced batch processing with rollback data creation
     */
    protected function process_batch_with_rollback($post_ids, $queue) {
        $settings = $this->get_settings_safely();
        $max_execution_time = min($settings['max_execution_time'] ?? 30, self::MAX_EXECUTION_TIME);
        $options = $queue['options'] ?? [];
        
        $results = [
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total_tags_added' => 0,
            'year_tags_added' => 0,
            'families_matched' => 0,
            'keyword_matches' => 0,
            'details' => []
        ];
        
        $start_time = time();
        $config = $this->get_config_safely();
        
        if (!$config) {
            $results['errors'] = count($post_ids);
            return $results;
        }
        
        $is_families_mode = $config['m'] === 'f';
        
        foreach ($post_ids as $post_id) {
            // Check execution time limit
            if ((time() - $start_time) >= $max_execution_time) {
                $this->log_info('Batch processing stopped due to time limit');
                break;
            }
            
            // Memory management
            if ($this->is_memory_limit_approaching()) {
                $this->log_info('Batch processing stopped due to memory limit');
                break;
            }
            
            try {
                // Handle special processing modes
                if ($queue['action'] === 'year_tags_only') {
                    $result = $this->process_year_tags_only($post_id);
                } else {
                    $result = $this->process_post_safely($post_id);
                }
                
                // Process results
                if (is_wp_error($result)) {
                    $results['errors']++;
                    $results['details'][$post_id] = [
                        'status' => 'error',
                        'message' => $result->get_error_message()
                    ];
                } elseif (isset($result['status']) && $result['status'] === 'skipped') {
                    $results['skipped']++;
                    $results['details'][$post_id] = $result;
                } else {
                    $results['processed']++;
                    $results['total_tags_added'] += $result['tags_added'] ?? 0;
                    
                    // Track year tags
                    if (!empty($result['year_tag'])) {
                        $results['year_tags_added']++;
                    }
                    
                    // Track mode-specific matches
                    if (!empty($result['matches'])) {
                        if ($is_families_mode) {
                            $results['families_matched'] += count($result['matches']);
                        } else {
                            $results['keyword_matches'] += count($result['matches']);
                        }
                    }
                    
                    $results['details'][$post_id] = [
                        'status' => 'success',
                        'tags_added' => $result['tags_added'] ?? 0
                    ];
                }
                
                // Clean up temporary meta after processing
                delete_post_meta($post_id, '_autotag_terms_before_processing');
                
            } catch (Exception $e) {
                $results['errors']++;
                $results['details'][$post_id] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                $this->log_error('Error processing post ' . $post_id . ': ' . $e->getMessage());
                
                // Clean up temporary meta on error
                delete_post_meta($post_id, '_autotag_terms_before_processing');
            }
        }
        
        // Create rollback data after processing
        $this->create_batch_rollback_data($queue['queue_id'], $post_ids, $results);
        
        return $results;
    }
    
    /**
     * WordPress Cron Management Methods
     */
    
    /**
     * Schedule regular maintenance tasks
     */
    public function schedule_maintenance_tasks() {
        // Schedule daily cleanup if not already scheduled
        if (!wp_next_scheduled('autotag_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'autotag_cleanup_cron');
        }
        
        // Schedule rollback cleanup
        if (!wp_next_scheduled('autotag_cleanup_rollback_data')) {
            wp_schedule_event(time(), 'daily', 'autotag_cleanup_rollback_data');
        }
        
        // Schedule report cleanup
        if (!wp_next_scheduled('autotag_cleanup_reports')) {
            wp_schedule_event(time(), 'weekly', 'autotag_cleanup_reports');
        }
        
        $this->log_info('Maintenance tasks scheduled successfully');
    }
    
    /**
     * Unschedule all maintenance tasks (for plugin deactivation)
     */
    public function unschedule_maintenance_tasks() {
        wp_clear_scheduled_hook('autotag_cleanup_cron');
        wp_clear_scheduled_hook('autotag_cleanup_rollback_data');
        wp_clear_scheduled_hook('autotag_cleanup_reports');
        wp_clear_scheduled_hook('autotag_process_bulk_batch');
        wp_clear_scheduled_hook('autotag_rollback_batch');
        
        $this->log_info('All maintenance tasks unscheduled');
    }
    
    /**
     * Get WordPress Cron status and diagnostics
     */
    public function get_cron_diagnostics() {
        $diagnostics = [
            'wp_cron_enabled' => !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON,
            'scheduled_events' => [],
            'next_execution' => [],
            'failed_events' => []
        ];
        
        // Get all autotag scheduled events
        $cron_jobs = wp_get_scheduled_events();
        foreach ($cron_jobs as $timestamp => $events) {
            foreach ($events as $hook => $event_data) {
                if (strpos($hook, 'autotag_') === 0) {
                    $diagnostics['scheduled_events'][] = [
                        'hook' => $hook,
                        'timestamp' => $timestamp,
                        'time_until' => $timestamp - time(),
                        'args' => $event_data
                    ];
                    
                    if (empty($diagnostics['next_execution'][$hook]) || $timestamp < $diagnostics['next_execution'][$hook]) {
                        $diagnostics['next_execution'][$hook] = $timestamp;
                    }
                }
            }
        }
        
        return $diagnostics;
    }
    
    /**
     * Force run maintenance tasks (for testing/debugging)
     */
    public function force_maintenance_run() {
        $this->log_info('Forcing maintenance tasks execution');
        
        // Run cleanup tasks
        $this->cleanup_expired_queues();
        $this->cleanup_old_rollback_data();
        $this->cleanup_old_reports();
        
        return [
            'status' => 'completed',
            'timestamp' => time(),
            'message' => 'Maintenance tasks executed successfully'
        ];
    }
    
    /**
     * Progress monitoring methods
     */
    
    /**
     * Get real-time progress for active queues
     */
    public function get_realtime_progress() {
        $active_queues = $this->get_active_queues();
        $progress_data = [];
        
        foreach ($active_queues as $queue_id => $queue) {
            $progress_percentage = ($queue['total_posts'] > 0) ? 
                ($queue['processed_posts'] / $queue['total_posts']) * 100 : 0;
            
            $estimated_completion = 0;
            if (!empty($queue['posts_per_second']) && $queue['posts_per_second'] > 0) {
                $remaining_posts = $queue['total_posts'] - $queue['processed_posts'];
                $estimated_completion = time() + ($remaining_posts / $queue['posts_per_second']);
            }
            
            $progress_data[$queue_id] = [
                'queue_id' => $queue_id,
                'action' => $queue['action'],
                'status' => $queue['status'],
                'progress_percentage' => round($progress_percentage, 2),
                'processed_posts' => $queue['processed_posts'],
                'total_posts' => $queue['total_posts'],
                'tags_added' => $queue['tags_added'],
                'errors' => $queue['errors'],
                'posts_per_second' => $queue['posts_per_second'] ?? 0,
                'estimated_completion' => $estimated_completion,
                'elapsed_time' => time() - $queue['start_time'],
                'memory_usage' => memory_get_usage(true),
                'last_update' => $queue['last_update']
            ];
        }
        
        return $progress_data;
    }
    
    /**
     * Get processing statistics summary
     */
    public function get_processing_statistics() {
        $active_queues = $this->get_active_queues();
        
        $stats = [
            'active_queues' => count($active_queues),
            'total_posts_processing' => 0,
            'total_posts_processed' => 0,
            'total_tags_added' => 0,
            'total_errors' => 0,
            'average_speed' => 0,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'rollback_availability' => $this->get_rollback_availability()
        ];
        
        $total_speed = 0;
        $speed_count = 0;
        
        foreach ($active_queues as $queue) {
            $stats['total_posts_processing'] += $queue['total_posts'];
            $stats['total_posts_processed'] += $queue['processed_posts'];
            $stats['total_tags_added'] += $queue['tags_added'];
            $stats['total_errors'] += $queue['errors'];
            
            if (!empty($queue['posts_per_second'])) {
                $total_speed += $queue['posts_per_second'];
                $speed_count++;
            }
        }
        
        if ($speed_count > 0) {
            $stats['average_speed'] = $total_speed / $speed_count;
        }
        
        return $stats;
    }
}

// Initialize the enhanced bulk processor
if (class_exists('AutoTag_Bulk')) {
    // Replace the standard bulk processor with the enhanced version
    function wp_autotag_pro_init_enhanced_bulk() {
        if (function_exists('wp_autotag_pro')) {
            wp_autotag_pro()->bulk_processor = new AutoTag_Bulk_Enhanced();
        }
    }
    
    // Hook into WordPress initialization
    add_action('init', 'wp_autotag_pro_init_enhanced_bulk', 20);
}