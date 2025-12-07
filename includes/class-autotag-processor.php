<?php
/**
 * AutoTag_Processor class - lightweight wrapper
 * 
 * This class has been refactored from 2,891 lines to ~100 lines.
 * The actual processing logic is now handled by focused classes:
 * - AutoTag_TextProcessor: Text analysis and normalization
 * - AutoTag_KeywordMatcher: Keyword/family matching
 * - AutoTag_TagApplier: WordPress tag application
 * - AutoTag_ProcessorCoordinator: Coordination and orchestration
 * 
 * This wrapper maintains API compatibility for existing code.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Processor {
    
    private $coordinator;
    
    public function __construct($config = null) {
        // Initialize the new processor coordinator
        $this->coordinator = new AutoTag_ProcessorCoordinator($config);
    }
    
    /**
     * Process a single post - forwards to ProcessorCoordinator
     */
    public function process_post($post_id, $force = false) {
        return $this->coordinator->process_post($post_id, $force);
    }
    
    /**
     * Maybe auto-process post on save - forwards to ProcessorCoordinator
     */
    public function maybe_auto_process_post($post_id, $post) {
        return $this->coordinator->maybe_auto_process_post($post_id, $post);
    }
    
    /**
     * Maybe auto-process new post on insert - forwards to ProcessorCoordinator
     */
    public function maybe_auto_process_new_post($post_id, $post) {
        return $this->coordinator->maybe_auto_process_new_post($post_id, $post);
    }
    
    /**
     * Process multiple posts - forwards to ProcessorCoordinator
     */
    public function process_multiple_posts($post_ids) {
        return $this->coordinator->process_multiple_posts($post_ids);
    }
    
    /**
     * Preview post processing - forwards to ProcessorCoordinator
     */
    public function preview_post_tags($post_id) {
        return $this->coordinator->preview_post_tags($post_id);
    }
    
    /**
     * Preview post processing (alternative method name) - forwards to ProcessorCoordinator
     */
    public function preview_post_processing($post_id) {
        return $this->coordinator->preview_post_processing($post_id);
    }
    
    /**
     * Get processing statistics - forwards to ProcessorCoordinator
     */
    public function get_processing_stats($post_id = null) {
        return $this->coordinator->get_processing_stats($post_id);
    }
    
    /**
     * Get posts needing processing - forwards to ProcessorCoordinator
     */
    public function get_posts_needing_processing($args = []) {
        return $this->coordinator->get_posts_needing_processing($args);
    }
    
    /**
     * Clear existing tags cache - forwards to ProcessorCoordinator
     */
    public function clear_existing_tags_cache() {
        return $this->coordinator->clear_existing_tags_cache();
    }
    
    /**
     * Set configuration - forwards to ProcessorCoordinator
     */
    public function set_config($config) {
        return $this->coordinator->set_config($config);
    }
    
    /**
     * Batch processing method
     */
    public function process_posts_batch($post_ids, $batch_size = 10) {
        // Convert to array if needed
        if (!is_array($post_ids)) {
            $post_ids = [$post_ids];
        }
        
        // Process in batches
        $results = [];
        $batches = array_chunk($post_ids, $batch_size);
        
        foreach ($batches as $batch) {
            $batch_results = $this->coordinator->process_multiple_posts($batch);
            $results = array_merge($results, $batch_results);
        }
        
        return $results;
    }
}