<?php
/**
 * Processor Coordinator Class - Enhanced for v2.1.0
 * Coordinates text processing, keyword matching, and dual taxonomy application (tags + categories)
 * Supports unified strategy with rule-based processing and conflict detection
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_ProcessorCoordinator {
    
    private $text_processor;
    private $keyword_matcher; 
    private $tag_applier;
    private $taxonomy_applier; // v2.1.0 - replaces tag_applier for dual taxonomy
    private $config;
    private $cache;
    
    public function __construct($config = null) {
        $this->text_processor = new AutoTag_TextProcessor();
        $this->keyword_matcher = new AutoTag_KeywordMatcher();
        
        // v2.1.0 - Use enhanced taxonomy applier for dual taxonomy support
        if (class_exists('AutoTag_TaxonomyApplier')) {
            $this->taxonomy_applier = new AutoTag_TaxonomyApplier();
        } else {
            // Fallback to tag applier for backward compatibility
            $this->tag_applier = new AutoTag_TagApplier();
        }
        
        $this->config = $config;
        
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->cache) {
            $this->cache = wp_autotag_pro()->cache;
        }
        
        // Initialize WordPress hooks for auto-processing
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks for automatic processing
     */
    private function init_hooks() {
        // Automatic processing hooks
        add_action('save_post', [$this, 'maybe_auto_process_post'], 20, 2);
        add_action('wp_insert_post', [$this, 'maybe_auto_process_new_post'], 20, 2);
        
        // Allow manual triggering via action
        add_action('autotag_process_post', [$this, 'process_post']);
        add_action('autotag_process_posts', [$this, 'process_multiple_posts']);
        
        // Clear caches when tags are modified
        add_action('created_term', [$this, 'clear_existing_tags_cache']);
        add_action('delete_term', [$this, 'clear_existing_tags_cache']);
    }
    
    /**
     * Process a single post - main entry point
     */
    public function process_post($post_id, $force = false) {
        try {
            // Check cache first (unless forced)
            if (!$force && $this->cache && function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
                $config_hash = wp_autotag_pro()->config->get_current_hash();
                $cached_result = $this->cache->get_post_processing($post_id, $config_hash);
                
                if ($cached_result !== null) {
                    return $cached_result;
                }
            }
            // Get post object
            $post = get_post($post_id);
            if (!$post || is_wp_error($post)) {
                return [
                    'success' => false,
                    'message' => 'Post not found',
                    'post_id' => $post_id
                ];
            }
            
            // Check if we should process this post
            if (!$force && !$this->should_process_post($post)) {
                return [
                    'success' => false,
                    'message' => 'Post does not meet processing criteria',
                    'post_id' => $post_id
                ];
            }
            
            // Get configuration
            $config = $this->get_processing_config();
            if (empty($config)) {
                return [
                    'success' => false,
                    'message' => 'No configuration available',
                    'post_id' => $post_id
                ];
            }
            
            // Step 1: Extract and normalize content
            $content_data = $this->text_processor->process_post_content($post);
            
            if (empty($content_data['normalized'])) {
                return [
                    'success' => false,
                    'message' => 'No content to process',
                    'post_id' => $post_id
                ];
            }
            
            // Step 2: Find keyword/family matches
            $mode = $config['mode'] ?? 'c';
            $matches = $this->keyword_matcher->find_all_matches($content_data['normalized'], $config, $mode);
            
            if (empty($matches)) {
                return [
                    'success' => false,
                    'message' => 'No keyword matches found',
                    'post_id' => $post_id,
                    'content_stats' => $content_data
                ];
            }
            
            // Step 3: Convert matches to taxonomies (tags + categories)
            if ($this->taxonomy_applier) {
                // v2.1.0 - Enhanced dual taxonomy processing
                $taxonomies = $this->taxonomy_applier->convert_matches_to_taxonomies($matches, $config);
                $new_tags = $taxonomies['tags'];
                $new_categories = $taxonomies['categories'];
            } else {
                // Fallback to tag-only processing
                $new_tags = $this->tag_applier->convert_matches_to_tags($matches, $config, $mode);
                $new_categories = [];
            }
            
            // Step 4: Generate year tag if enabled
            $year_tag = null;
            if ($config['year_tags'] ?? true) {
                if ($this->taxonomy_applier) {
                    $year_tag = $this->taxonomy_applier->generate_year_tag($post_id);
                } else {
                    $year_tag = $this->tag_applier->generate_year_tag($post_id);
                }
            }
            
            // Step 5: Apply taxonomies to post
            if ($this->taxonomy_applier) {
                $application_options = $this->get_taxonomy_application_options($config, $year_tag);
                $application_result = $this->taxonomy_applier->apply_taxonomies_to_post(
                    $post_id, 
                    $new_tags, 
                    $new_categories,
                    $application_options
                );
            } else {
                // Tag-only application
                $strategy = $config['strategy'] ?? 'a';
                $application_result = $this->tag_applier->apply_tags_to_post($post_id, $new_tags, $strategy, $year_tag);
                // Add empty categories for consistency
                $application_result['categories_applied'] = [];
            }
            
            // Step 6: Update processing metadata
            if ($application_result['success']) {
                $this->update_processing_metadata($post_id, $config, $content_data, $matches);
            }
            
            $result = [
                'success' => $application_result['success'],
                'message' => $application_result['message'],
                'post_id' => $post_id,
                'matches_found' => $matches,
                'tags_applied' => $application_result['tags_applied'] ?? [],
                'categories_applied' => $application_result['categories_applied'] ?? [],
                'year_tag' => $year_tag,
                'processing_stats' => [
                    'content_length' => $content_data['char_count'],
                    'word_count' => $content_data['word_count'],
                    'matches_count' => count($matches),
                    'tags_count' => count($application_result['tags_applied'] ?? []),
                    'categories_count' => count($application_result['categories_applied'] ?? []),
                    'mode' => $mode,
                    'strategy' => $this->taxonomy_applier ? 'unified' : ($strategy ?? 'tag-only')
                ]
            ];
            
            // Cache successful results
            if ($result['success'] && $this->cache && function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
                $config_hash = wp_autotag_pro()->config->get_current_hash();
                $this->cache->cache_post_processing($post_id, $config_hash, $result);
            }
            
            return $result;
            
        } catch (AutoTag_Exception $e) {
            // Handle our specific exceptions
            if (function_exists('wp_autotag_pro') && wp_autotag_pro()->error_handler) {
                wp_autotag_pro()->error_handler->handle_error($e, 'post_processing');
            }
            
            return [
                'success' => false,
                'message' => $e->getUserMessage(),
                'error_code' => $e->getErrorCode(),
                'suggestions' => $e->getSuggestions(),
                'post_id' => $post_id,
            ];
        } catch (Exception $e) {
            // Convert generic exceptions to processing exceptions
            $processing_exception = new AutoTag_ProcessingException($e->getMessage(), 0, $e, ['post_id' => $post_id]);
            
            if (function_exists('wp_autotag_pro') && wp_autotag_pro()->error_handler) {
                wp_autotag_pro()->error_handler->handle_error($processing_exception, 'post_processing');
            }
            
            return [
                'success' => false,
                'message' => $processing_exception->getUserMessage(),
                'error_code' => $processing_exception->getErrorCode(),
                'suggestions' => $processing_exception->getSuggestions(),
                'post_id' => $post_id,
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ];
        }
    }
    
    /**
     * Preview what taxonomies would be applied without actually applying them
     * v2.1.0 - Enhanced for dual taxonomy support (tags + categories)
     */
    public function preview_post_processing($post_id) {
        try {
            $post = get_post($post_id);
            if (!$post || is_wp_error($post)) {
                return ['success' => false, 'message' => 'Post not found'];
            }
            
            $config = $this->get_processing_config();
            if (empty($config)) {
                return ['success' => false, 'message' => 'No configuration available'];
            }
            
            // Process content and find matches
            $content_data = $this->text_processor->process_post_content($post);
            $mode = $config['mode'] ?? 'c';
            $matches = $this->keyword_matcher->find_all_matches($content_data['normalized'], $config, $mode);
            
            // Generate year tag
            $year_tag = null;
            if ($config['year_tags'] ?? true) {
                if ($this->taxonomy_applier) {
                    $year_tag = $this->taxonomy_applier->generate_year_tag($post_id);
                } else {
                    $year_tag = $this->tag_applier->generate_year_tag($post_id);
                }
            }
            
            // Get application options
            $application_options = $this->get_taxonomy_application_options($config, $year_tag);
            
            if ($this->taxonomy_applier) {
                // v2.1.0 - Enhanced dual taxonomy preview
                $taxonomies = $this->taxonomy_applier->convert_matches_to_taxonomies($matches, $config);
                $new_tags = $taxonomies['tags'];
                $new_categories = $taxonomies['categories'];
                
                $preview = $this->taxonomy_applier->preview_taxonomies(
                    $post_id, 
                    $new_tags, 
                    $new_categories, 
                    $application_options
                );
                
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'preview' => $preview,
                    'matches_found' => $matches,
                    'content_stats' => $content_data,
                    'mode' => $mode,
                    'application_options' => $application_options,
                    'taxonomy_type' => 'dual', // both tags and categories
                    'generated_tags' => $new_tags,
                    'generated_categories' => $new_categories
                ];
            } else {
                // Fallback to tag-only preview
                $new_tags = $this->tag_applier->convert_matches_to_tags($matches, $config, $mode);
                $strategy = $config['strategy'] ?? 'a';
                $preview = $this->tag_applier->preview_tags($post_id, $new_tags, $strategy, $year_tag);
                
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'preview' => ['tags' => $preview, 'categories' => ['existing' => [], 'generated' => [], 'final' => []]],
                    'matches_found' => $matches,
                    'content_stats' => $content_data,
                    'mode' => $mode,
                    'strategy' => $strategy,
                    'taxonomy_type' => 'tag-only', // tags only
                    'generated_tags' => $new_tags,
                    'generated_categories' => []
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Preview error: ' . $e->getMessage(),
                'post_id' => $post_id
            ];
        }
    }
    
    /**
     * Check if post should be processed
     */
    private function should_process_post($post) {
        // Check post type
        if (!in_array($post->post_type, ['post', 'page'])) {
            return false;
        }
        
        // Check post status  
        if (!in_array($post->post_status, ['publish', 'draft'])) {
            return false;
        }
        
        // Check if content meets minimum requirements
        $content_data = $this->text_processor->process_post_content($post);
        return $this->keyword_matcher->should_process_content($content_data['normalized']);
    }
    
    /**
     * Get processing configuration
     */
    private function get_processing_config() {
        if ($this->config) {
            return $this->config;
        }
        
        // Try to get from WordPress AutoTag Pro plugin
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
            return wp_autotag_pro()->config->get_config();
        }
        
        // Fallback to default config
        return $this->get_default_config();
    }
    
    /**
     * Get default configuration
     */
    private function get_default_config() {
        return [
            'year_tags_enabled' => true,
            'year_strategy' => 'r',
            'default_tag_strategy' => 'merge',
            'default_category_strategy' => 'merge',
            'taxonomy_processing' => 'both',
            'auto_create_categories' => true,
            'conflict_warnings_enabled' => true,
            'rules' => []
        ];
    }
    
    /**
     * Update processing metadata after successful processing
     */
    private function update_processing_metadata($post_id, $config, $content_data, $matches) {
        // Update processing status
        update_post_meta($post_id, 'auto_tag_status', 'completed');
        update_post_meta($post_id, 'auto_tag_last_processed', time());
        
        // Store processing hash to avoid reprocessing same content
        $content_hash = md5($content_data['normalized'] . serialize($config));
        update_post_meta($post_id, 'auto_tag_processed_hash', $content_hash);
        
        // Store processing stats
        update_post_meta($post_id, 'auto_tag_processing_stats', [
            'matches_found' => count($matches),
            'content_length' => $content_data['char_count'],
            'processed_at' => time()
        ]);
    }
    
    /**
     * Set custom configuration (for testing or special cases)
     */
    public function set_config($config) {
        $this->config = $config;
    }
    
    /**
     * Get processing statistics for a post
     */
    public function get_processing_stats($post_id = null) {
        if ($post_id) {
            // Post-specific stats
            return [
                'status' => get_post_meta($post_id, 'auto_tag_status', true),
                'last_processed' => get_post_meta($post_id, 'auto_tag_last_processed', true),
                'processed_hash' => get_post_meta($post_id, 'auto_tag_processed_hash', true),
                'processing_stats' => get_post_meta($post_id, 'auto_tag_processing_stats', true),
                'current_tags' => $this->taxonomy_applier ? 
                    $this->taxonomy_applier->get_existing_tags($post_id) : 
                    ($this->tag_applier ? $this->tag_applier->get_existing_tags($post_id) : []),
                'current_categories' => $this->taxonomy_applier ? 
                    wp_get_post_categories($post_id, ['fields' => 'names']) : []
            ];
        } else {
            // Global stats
            $plugin = function_exists('wp_autotag_pro') ? wp_autotag_pro() : null;
            if ($plugin && method_exists($plugin, 'get_stats')) {
                return $plugin->get_stats();
            }
            return [];
        }
    }
    
    /**
     * Compatibility method - alias for preview_post_processing
     */
    public function preview_post_tags($post_id) {
        return $this->preview_post_processing($post_id);
    }
    
    /**
     * Get posts that need processing based on configuration changes
     */
    public function get_posts_needing_processing($args = []) {
        $defaults = [
            'posts_per_page' => 50,
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_query' => []
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Get current configuration hash
        $current_hash = '';
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
            $current_hash = wp_autotag_pro()->config->get_current_hash();
        }
        
        // Find posts that haven't been processed with current config
        if ($current_hash) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => 'auto_tag_processed_hash',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => 'auto_tag_processed_hash',
                    'value' => $current_hash,
                    'compare' => '!='
                ]
            ];
        }
        
        return get_posts($args);
    }
    
    /**
     * Maybe auto-process post on save
     */
    public function maybe_auto_process_post($post_id, $post) {
        // Skip if this is an autosave or revision
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Check if auto-processing is enabled
        $plugin = function_exists('wp_autotag_pro') ? wp_autotag_pro() : null;
        if (!$plugin) {
            return;
        }
        
        $settings = $plugin->get_settings();
        if (!($settings['auto_process_on_save'] ?? true)) {
            return;
        }
        
        // Check if this post type should be processed
        $enabled_post_types = $settings['enabled_post_types'] ?? ['post'];
        if (!in_array($post->post_type, $enabled_post_types)) {
            return;
        }
        
        // Process the post
        $this->process_post($post_id);
    }
    
    /**
     * Maybe auto-process new post on insert
     */
    public function maybe_auto_process_new_post($post_id, $post) {
        // Skip if not published
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Use the same logic as maybe_auto_process_post
        $this->maybe_auto_process_post($post_id, $post);
    }
    
    /**
     * Process multiple posts (for bulk operations)
     */
    public function process_multiple_posts($post_ids) {
        $results = [];
        
        if (!is_array($post_ids)) {
            $post_ids = [$post_ids];
        }
        
        foreach ($post_ids as $post_id) {
            $results[$post_id] = $this->process_post($post_id);
        }
        
        return $results;
    }
    
    /**
     * Clear existing tags cache when terms are modified
     */
    public function clear_existing_tags_cache() {
        $plugin = function_exists('wp_autotag_pro') ? wp_autotag_pro() : null;
        if ($plugin && method_exists($plugin, 'clear_existing_tags_cache')) {
            $plugin->clear_existing_tags_cache();
        }
    }
    
    /**
     * Get taxonomy application options based on configuration and context
     * v2.1.0 - NEW method for dual taxonomy support
     */
    private function get_taxonomy_application_options($config, $year_tag = null) {
        $defaults = [
            'tag_strategy' => 'merge',
            'category_strategy' => 'merge',
            'year_tag' => $year_tag,
            'auto_create_categories' => true,
            'taxonomies' => 'both' // 'tags', 'categories', or 'both'
        ];
        
        // FIRST: Check enabled_taxonomies setting to respect user's taxonomy configuration
        $enabled_taxonomies = [];
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            $settings = wp_autotag_pro()->get_settings();
            $enabled_taxonomies = $settings['enabled_taxonomies'] ?? ['post_tag'];
        }
        
        $tags_enabled = in_array('post_tag', $enabled_taxonomies);
        $categories_enabled = in_array('category', $enabled_taxonomies);
        
        // Override taxonomies based on user configuration
        if ($tags_enabled && $categories_enabled) {
            $defaults['taxonomies'] = 'both';
        } elseif ($tags_enabled) {
            $defaults['taxonomies'] = 'tags';
        } elseif ($categories_enabled) {
            $defaults['taxonomies'] = 'categories';
        } else {
            // No taxonomies enabled - should not process anything
            $defaults['taxonomies'] = 'none';
        }
        
        // SECOND: Check for unified rules configuration (but respect enabled taxonomies)
        if (isset($config['rules'])) {
            // v2.1.0 unified rules mode - determine what taxonomies rules support
            $has_tags_rules = false;
            $has_categories_rules = false;
            
            foreach ($config['rules'] as $rule) {
                if (!empty($rule['tags'])) {
                    $has_tags_rules = true;
                }
                if (!empty($rule['categories'])) {
                    $has_categories_rules = true;
                }
            }
            
            // Only process taxonomies that are BOTH enabled AND have rules
            $process_tags = $tags_enabled && $has_tags_rules;
            $process_categories = $categories_enabled && $has_categories_rules;
            
            if ($process_tags && $process_categories) {
                $defaults['taxonomies'] = 'both';
            } elseif ($process_tags) {
                $defaults['taxonomies'] = 'tags';
            } elseif ($process_categories) {
                $defaults['taxonomies'] = 'categories';
            } else {
                $defaults['taxonomies'] = 'none';
            }
            
            // Apply unified rule specific settings
            if (!empty($config['tag_strategy'])) {
                $defaults['tag_strategy'] = $config['tag_strategy'];
            }
            if (!empty($config['category_strategy'])) {
                $defaults['category_strategy'] = $config['category_strategy'];
            }
            if (isset($config['auto_create_categories'])) {
                $defaults['auto_create_categories'] = (bool)$config['auto_create_categories'];
            }
        } else {
            // Legacy tag-only mode - but still respect enabled taxonomies
            if ($tags_enabled) {
                $defaults['taxonomies'] = 'tags';
            } else {
                $defaults['taxonomies'] = 'none';
            }
        }
        
        // Apply global overrides from config
        if (isset($config['taxonomy_options'])) {
            $defaults = array_merge($defaults, $config['taxonomy_options']);
        }
        
        return $defaults;
    }
}