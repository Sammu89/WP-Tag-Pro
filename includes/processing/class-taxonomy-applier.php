<?php
/**
 * Taxonomy Applier Class - v2.1.0 Enhanced for Dual Taxonomy
 * Handles WordPress tag and category application with unified strategy support
 * Replaces AutoTag_TagApplier with extended functionality for categories
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_TaxonomyApplier {
    
    /**
     * @var AutoTag_WP_Category_Manager WordPress category management instance
     */
    private $category_manager;
    
    /**
     * Maximum tags per post - Master Specification v2.1.0 requirement
     */
    const MAX_TAGS_PER_POST = 15;
    
    /**
     * Constructor - Initialize WordPress integrations
     */
    public function __construct() {
        // Initialize WordPress Category Manager
        if (!class_exists('AutoTag_WP_Category_Manager')) {
            require_once plugin_dir_path(__FILE__) . '../integration/class-wp-category-manager.php';
        }
        
        $this->category_manager = new AutoTag_WP_Category_Manager();
    }
    
    /**
     * Apply both tags and categories to a post based on unified rules
     */
    public function apply_taxonomies_to_post($post_id, $generated_tags, $generated_categories, $options = []) {
        $defaults = [
            'tag_strategy' => 'merge',
            'category_strategy' => 'merge',
            'year_tag' => null,
            'auto_create_categories' => true,
            'taxonomies' => 'both' // 'tags', 'categories', or 'both'
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        $results = [
            'success' => false,
            'tags_applied' => [],
            'categories_applied' => [],
            'tags_result' => null,
            'categories_result' => null,
            'message' => ''
        ];
        
        // Handle case where no taxonomies are enabled
        if ($options['taxonomies'] === 'none') {
            return [
                'success' => true,
                'tags_applied' => [],
                'categories_applied' => [],
                'tags_result' => null,
                'categories_result' => null,
                'message' => 'No taxonomies enabled for processing'
            ];
        }
        
        try {
            // Apply tags if requested
            if (in_array($options['taxonomies'], ['tags', 'both']) && (!empty($generated_tags) || !empty($options['year_tag']))) {
                $results['tags_result'] = $this->apply_tags_to_post(
                    $post_id, 
                    $generated_tags, 
                    $options['tag_strategy'], 
                    $options['year_tag']
                );
                
                if ($results['tags_result']['success']) {
                    $results['tags_applied'] = $results['tags_result']['tags_applied'];
                }
            }
            
            // Apply categories if requested
            if (in_array($options['taxonomies'], ['categories', 'both']) && !empty($generated_categories)) {
                $results['categories_result'] = $this->apply_categories_to_post(
                    $post_id,
                    $generated_categories,
                    $options['category_strategy'],
                    $options['auto_create_categories']
                );
                
                if ($results['categories_result']['success']) {
                    $results['categories_applied'] = $results['categories_result']['categories_applied'];
                }
            }
            
            // Overall success if at least one taxonomy was applied successfully
            $results['success'] = 
                ($results['tags_result']['success'] ?? false) || 
                ($results['categories_result']['success'] ?? false);
                
            if ($results['success']) {
                $applied_count = count($results['tags_applied']) + count($results['categories_applied']);
                $results['message'] = sprintf('Applied %d classifications successfully', $applied_count);
            } else {
                $results['message'] = 'No classifications were applied';
            }
            
        } catch (Exception $e) {
            $results['message'] = 'Error during taxonomy application: ' . $e->getMessage();
            $this->log_error('Taxonomy application failed', [
                'post_id' => $post_id,
                'error' => $e->getMessage()
            ]);
        }
        
        return $results;
    }
    
    /**
     * Apply tags to a post (enhanced from original TagApplier)
     */
    public function apply_tags_to_post($post_id, $new_tags, $strategy = 'merge', $year_tag = null) {
        if (empty($new_tags) && empty($year_tag)) {
            return [
                'success' => false,
                'message' => 'No tags to apply',
                'tags_applied' => []
            ];
        }
        
        try {
            // Get existing tags
            $existing_tags = $this->get_existing_tags($post_id);
            
            // Build final tag list based on strategy
            $final_tags = $this->build_final_tag_list($new_tags, $year_tag, $existing_tags, $strategy);
            
            // Apply tags to WordPress
            $result = wp_set_post_terms($post_id, $final_tags, 'post_tag');
            
            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'message' => 'WordPress error: ' . $result->get_error_message(),
                    'tags_applied' => []
                ];
            }
            
            // Verify application
            $applied_tags = $this->get_existing_tags($post_id);
            
            return [
                'success' => true,
                'message' => 'Tags applied successfully',
                'tags_applied' => $applied_tags,
                'tags_count' => count($applied_tags),
                'strategy_used' => $strategy
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'tags_applied' => []
            ];
        }
    }
    
    /**
     * Apply categories to a post (NEW for v2.1.0)
     */
    public function apply_categories_to_post($post_id, $new_categories, $strategy = 'merge', $auto_create = true) {
        if (empty($new_categories)) {
            return [
                'success' => false,
                'message' => 'No categories to apply',
                'categories_applied' => []
            ];
        }
        
        try {
            // Get existing categories
            $existing_categories = $this->get_existing_categories($post_id);
            
            // Process categories (create if needed, get IDs)
            $category_ids = $this->process_categories_for_application($new_categories, $auto_create);
            
            if (empty($category_ids)) {
                return [
                    'success' => false,
                    'message' => 'No valid categories to apply',
                    'categories_applied' => []
                ];
            }
            
            // Build final category list based on strategy
            $final_categories = $this->build_final_category_list($category_ids, $existing_categories, $strategy);
            
            // Apply categories to WordPress
            $result = wp_set_post_categories($post_id, $final_categories);
            
            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'message' => 'WordPress error: ' . $result->get_error_message(),
                    'categories_applied' => []
                ];
            }
            
            // Verify application and get names
            $applied_categories = $this->get_existing_categories($post_id);
            
            return [
                'success' => true,
                'message' => 'Categories applied successfully',
                'categories_applied' => $applied_categories,
                'categories_count' => count($applied_categories),
                'strategy_used' => $strategy,
                'auto_created' => $auto_create ? $this->get_newly_created_categories($new_categories) : []
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'categories_applied' => []
            ];
        }
    }
    
    /**
     * Convert unified rule matches to tags and categories
     */
    public function convert_matches_to_taxonomies($matches, $config) {
        $tags = [];
        $categories = [];
        
        if (empty($matches)) {
            return ['tags' => $tags, 'categories' => $categories];
        }
        
        // Handle unified rules structure
        if (isset($config['rules']) && !empty($config['rules'])) {
            foreach ($matches as $match_data) {
                $rule_id = $match_data['rule_id'] ?? null;
                $rule = $config['rules'][$rule_id] ?? null;
                
                if ($rule) {
                    // Add rule tags
                    if (!empty($rule['tags'])) {
                        $tags = array_merge($tags, (array)$rule['tags']);
                    }
                    
                    // Add rule categories
                    if (!empty($rule['categories'])) {
                        $categories = array_merge($categories, (array)$rule['categories']);
                    }
                    
                    // Handle inheritance
                    if (!empty($rule['inheritance_enabled']) && !empty($rule['parent_id'])) {
                        $parent_taxonomies = $this->get_parent_taxonomies($rule['parent_id'], $config['rules']);
                        $tags = array_merge($tags, $parent_taxonomies['tags']);
                        $categories = array_merge($categories, $parent_taxonomies['categories']);
                    }
                }
            }
        } 
        // Legacy support for families/keywords
        else {
            // Convert legacy matches to tags (existing behavior)
            $legacy_tags = $this->convert_legacy_matches_to_tags($matches, $config);
            $tags = array_merge($tags, $legacy_tags);
        }
        
        return [
            'tags' => array_unique($tags),
            'categories' => array_unique($categories)
        ];
    }
    
    /**
     * Get existing tags for a post
     */
    public function get_existing_tags($post_id) {
        $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        return is_array($tags) ? $tags : [];
    }
    
    /**
     * Get existing categories for a post
     */
    private function get_existing_categories($post_id) {
        $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
        return is_array($categories) ? $categories : [];
    }
    
    /**
     * Build final tag list based on strategy with tag limit enforcement
     */
    private function build_final_tag_list($new_tags, $year_tag, $existing_tags, $strategy) {
        $final_tags = [];
        
        // Add year tag first if present (year tags are prioritized)
        if (!empty($year_tag)) {
            $final_tags[] = $year_tag;
        }
        
        // Add new tags
        if (!empty($new_tags)) {
            $final_tags = array_merge($final_tags, (array)$new_tags);
        }
        
        // Apply strategy
        if ($strategy === 'merge') {
            $final_tags = array_merge($existing_tags, $final_tags);
        }
        // For 'replace', just use the new tags (already in $final_tags)
        
        // Remove duplicates
        $final_tags = array_unique($final_tags);
        
        // Enforce tag limits - Master Specification v2.1.0 requirement
        return $this->enforce_tag_limits($final_tags);
    }
    
    /**
     * Enforce maximum tag limits per Master Specification v2.1.0
     * 
     * @param array $tags Array of tag names
     * @return array Limited array of tags with overflow stored for warning
     */
    private function enforce_tag_limits($tags) {
        if (count($tags) <= self::MAX_TAGS_PER_POST) {
            return $tags;
        }
        
        // Store overflow tags for warning display
        $overflow_tags = array_slice($tags, self::MAX_TAGS_PER_POST);
        $truncated_tags = array_slice($tags, 0, self::MAX_TAGS_PER_POST);
        
        // Store overflow information for admin warnings
        if (!empty($overflow_tags)) {
            $this->store_tag_overflow_warning($overflow_tags, count($tags));
        }
        
        return $truncated_tags;
    }
    
    /**
     * Store tag overflow information for admin warnings
     * 
     * @param array $overflow_tags Tags that were truncated
     * @param int $original_count Original number of tags
     */
    private function store_tag_overflow_warning($overflow_tags, $original_count) {
        // Store in WordPress transient for admin notification
        $warning_data = [
            'overflow_tags' => $overflow_tags,
            'original_count' => $original_count,
            'limit' => self::MAX_TAGS_PER_POST,
            'timestamp' => current_time('timestamp')
        ];
        
        set_transient('autotag_tag_overflow_warning', $warning_data, 300); // 5 minute warning
        
        // Log the truncation
        $this->log_info('Tag limit enforced', [
            'original_count' => $original_count,
            'truncated_count' => count($overflow_tags),
            'limit' => self::MAX_TAGS_PER_POST
        ]);
    }
    
    /**
     * Build final category list based on strategy
     */
    private function build_final_category_list($new_category_ids, $existing_categories, $strategy) {
        // Get existing category IDs
        $existing_ids = [];
        foreach ($existing_categories as $cat_name) {
            $cat = get_category_by_slug(sanitize_title($cat_name));
            if ($cat) {
                $existing_ids[] = $cat->term_id;
            }
        }
        
        if ($strategy === 'merge') {
            $final_ids = array_merge($existing_ids, $new_category_ids);
        } else {
            $final_ids = $new_category_ids;
        }
        
        return array_unique($final_ids);
    }
    
    /**
     * Process category names and get/create WordPress category IDs with hierarchy support
     */
    private function process_categories_for_application($category_names, $auto_create = true) {
        if (empty($category_names)) {
            return [];
        }
        
        // Use WordPress Category Manager for enhanced processing
        $manager_options = [
            'auto_create' => $auto_create,
            'detect_hierarchy' => true,
            'parent_assignment' => 'auto',
            'validate_names' => true
        ];
        
        $processing_result = $this->category_manager->process_categories($category_names, $manager_options);
        
        if (!$processing_result['success']) {
            $this->log_error('Category processing failed', [
                'categories' => $category_names,
                'errors' => $processing_result['errors']
            ]);
            return [];
        }
        
        // Log creation details
        if (!empty($processing_result['created'])) {
            $this->log_info('Categories auto-created with hierarchy support', [
                'created_count' => count($processing_result['created']),
                'categories' => $processing_result['created'],
                'hierarchies' => $processing_result['hierarchies_detected']
            ]);
        }
        
        if (!empty($processing_result['found'])) {
            $this->log_info('Existing categories found', [
                'found_count' => count($processing_result['found']),
                'categories' => array_column($processing_result['found'], 'name')
            ]);
        }
        
        return $processing_result['category_ids'];
    }
    
    /**
     * Get parent taxonomies for inheritance
     */
    private function get_parent_taxonomies($parent_id, $rules) {
        $parent_rule = $rules[$parent_id] ?? null;
        
        if (!$parent_rule) {
            return ['tags' => [], 'categories' => []];
        }
        
        $taxonomies = [
            'tags' => (array)($parent_rule['tags'] ?? []),
            'categories' => (array)($parent_rule['categories'] ?? [])
        ];
        
        // Recursive inheritance
        if (!empty($parent_rule['parent_id'])) {
            $grandparent = $this->get_parent_taxonomies($parent_rule['parent_id'], $rules);
            $taxonomies['tags'] = array_merge($taxonomies['tags'], $grandparent['tags']);
            $taxonomies['categories'] = array_merge($taxonomies['categories'], $grandparent['categories']);
        }
        
        return $taxonomies;
    }
    
    /**
     * Legacy support - convert old matches to tags
     */
    private function convert_legacy_matches_to_tags($matches, $config) {
        $tags = [];
        
        if (empty($matches)) {
            return $tags;
        }
        
        $mode = $config['mode'] ?? 'c';
        
        if ($mode === 'c' || $mode === 'classic') {
            // Classic mode: matches are direct keywords
            foreach ($matches as $match) {
                if (is_string($match)) {
                    $tags[] = $match;
                }
            }
        } elseif ($mode === 'f' || $mode === 'families') {
            // Family mode: matches are family names, convert using family map
            $family_map = $config['f'] ?? [];
            
            foreach ($matches as $family_name) {
                if (is_string($family_name) && isset($family_map[$family_name])) {
                    $family_data = $family_map[$family_name];
                    
                    // If family has a direct tag association
                    if (isset($family_data['tag'])) {
                        $tags[] = $family_data['tag'];
                    } 
                    // If family should use the family name as tag
                    elseif (isset($family_data['use_name']) && $family_data['use_name']) {
                        $tags[] = $family_name;
                    }
                    // Default: use family name as tag
                    else {
                        $tags[] = $family_name;
                    }
                }
            }
        }
        
        return array_unique($tags);
    }
    
    /**
     * Get newly created categories during processing
     */
    private function get_newly_created_categories($requested_categories) {
        if (!$this->category_manager) {
            return [];
        }
        
        $newly_created_ids = $this->category_manager->get_newly_created_categories();
        $newly_created_categories = [];
        
        foreach ($newly_created_ids as $category_id) {
            $category = get_term($category_id, 'category');
            if ($category && !is_wp_error($category)) {
                $newly_created_categories[] = [
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'parent' => $category->parent,
                    'path' => $this->category_manager->get_category_path($category_id)
                ];
            }
        }
        
        return $newly_created_categories;
    }
    
    /**
     * Generate year tag based on post date
     */
    public function generate_year_tag($post_id) {
        $post = get_post($post_id);
        if (!$post) return null;
        
        return date('Y', strtotime($post->post_date));
    }
    
    /**
     * Preview what taxonomies would be applied without actually applying them
     */
    public function preview_taxonomies($post_id, $generated_tags, $generated_categories, $options = []) {
        $existing_tags = $this->get_existing_tags($post_id);
        $existing_categories = $this->get_existing_categories($post_id);
        
        $preview_tags = $this->build_final_tag_list(
            $generated_tags, 
            $options['year_tag'] ?? null, 
            $existing_tags, 
            $options['tag_strategy'] ?? 'merge'
        );
        
        // Enhanced category preview with hierarchy support
        $category_preview_data = $this->preview_categories($generated_categories, $existing_categories, $options);
        
        return [
            'tags' => [
                'existing' => $existing_tags,
                'generated' => $generated_tags,
                'final' => $preview_tags,
                'year_tag' => $options['year_tag'] ?? null
            ],
            'categories' => $category_preview_data
        ];
    }
    
    /**
     * Enhanced category preview with WordPress integration
     */
    public function preview_categories($generated_categories, $existing_categories, $options = []) {
        if (empty($generated_categories)) {
            return [
                'existing' => $existing_categories,
                'generated' => [],
                'final' => $existing_categories,
                'hierarchy_info' => [],
                'creation_needed' => []
            ];
        }
        
        // Use category manager to analyze categories without creating them
        $manager_options = [
            'auto_create' => false, // Preview mode - don't create
            'detect_hierarchy' => true,
            'validate_names' => true
        ];
        
        $analysis_result = $this->category_manager->process_categories($generated_categories, $manager_options);
        
        $preview_data = [
            'existing' => $existing_categories,
            'generated' => $generated_categories,
            'final' => [],
            'hierarchy_info' => [],
            'creation_needed' => [],
            'validation_errors' => []
        ];
        
        // Process successful category lookups
        if (!empty($analysis_result['category_data'])) {
            foreach ($analysis_result['category_data'] as $category_data) {
                $preview_data['final'][] = $category_data['name'];
                
                if (!empty($category_data['hierarchy'])) {
                    $preview_data['hierarchy_info'][] = [
                        'name' => $category_data['name'],
                        'path' => $category_data['path'],
                        'level' => $category_data['hierarchy']['level'],
                        'parent_id' => $category_data['hierarchy']['parent_id']
                    ];
                }
            }
        }
        
        // Track categories that would need creation
        if (!empty($analysis_result['errors'])) {
            foreach ($analysis_result['errors'] as $error) {
                if (strpos($error['error'], 'does not exist') !== false) {
                    $preview_data['creation_needed'][] = [
                        'name' => $error['category'],
                        'hierarchy_detected' => $this->category_manager->detect_hierarchy($error['category'])
                    ];
                } else {
                    $preview_data['validation_errors'][] = $error;
                }
            }
        }
        
        // Apply strategy for final preview
        $category_strategy = $options['category_strategy'] ?? 'merge';
        if ($category_strategy === 'merge') {
            $preview_data['final'] = array_unique(array_merge($existing_categories, $preview_data['final']));
        }
        // For 'replace', final is already set to just the new categories
        
        return $preview_data;
    }
    
    // ============================================================================
    // WORDPRESS INTEGRATION METHODS
    // Enhanced WordPress-specific integration methods for categories
    // ============================================================================
    
    /**
     * Get category validation status with WordPress taxonomy system
     * 
     * @param array $category_names Array of category names to validate
     * @return array Validation results
     */
    public function validate_categories_against_wordpress($category_names) {
        if (!$this->category_manager) {
            return ['valid' => false, 'error' => 'Category manager not initialized'];
        }
        
        $validation_results = [
            'all_valid' => true,
            'categories' => [],
            'errors' => []
        ];
        
        foreach ($category_names as $category_name) {
            $is_valid = $this->category_manager->validate_category_name($category_name);
            
            $category_result = [
                'name' => $category_name,
                'valid' => $is_valid,
                'sanitized' => $this->category_manager->sanitize_category_name($category_name),
                'exists' => false,
                'hierarchy_detected' => null
            ];
            
            if ($is_valid) {
                // Check if category exists
                $existing = $this->category_manager->find_category($category_name);
                $category_result['exists'] = !empty($existing);
                
                // Check for hierarchy patterns
                $hierarchy_detection = $this->category_manager->detect_hierarchy($category_name);
                if ($hierarchy_detection['parent_detected']) {
                    $category_result['hierarchy_detected'] = $hierarchy_detection;
                }
            } else {
                $validation_results['all_valid'] = false;
                $validation_results['errors'][] = "Invalid category name: {$category_name}";
            }
            
            $validation_results['categories'][] = $category_result;
        }
        
        return $validation_results;
    }
    
    /**
     * Get WordPress category selection data for UI integration
     * 
     * @param array $selected_categories Currently selected categories
     * @return array Category selection data for UI
     */
    public function get_category_selection_data($selected_categories = []) {
        $selection_data = [
            'existing_categories' => [],
            'selected_categories' => [],
            'hierarchy_options' => [],
            'creation_suggestions' => []
        ];
        
        // Get all existing categories with hierarchy
        $all_categories = get_categories([
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ]);
        
        foreach ($all_categories as $category) {
            $category_data = [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'parent' => $category->parent,
                'count' => $category->count,
                'path' => $this->category_manager->get_category_path($category->term_id),
                'level' => 0
            ];
            
            // Get hierarchy info
            $hierarchy_info = $this->category_manager->get_hierarchy_info($category->term_id);
            if ($hierarchy_info) {
                $category_data['level'] = $hierarchy_info['level'];
                $category_data['hierarchy_info'] = $hierarchy_info;
            }
            
            $selection_data['existing_categories'][] = $category_data;
            
            // Mark if selected
            if (in_array($category->name, $selected_categories)) {
                $selection_data['selected_categories'][] = $category_data;
            }
        }
        
        // Generate hierarchy options for parent selection
        $selection_data['hierarchy_options'] = $this->build_hierarchy_options($all_categories);
        
        return $selection_data;
    }
    
    /**
     * Build hierarchy options for category parent selection
     * 
     * @param array $categories Array of WordPress category objects
     * @return array Hierarchical options array
     */
    private function build_hierarchy_options($categories) {
        $hierarchy_options = [];
        
        // Build parent-child relationships
        $category_tree = [];
        $top_level = [];
        
        foreach ($categories as $category) {
            $category_tree[$category->term_id] = [
                'category' => $category,
                'children' => []
            ];
            
            if ($category->parent == 0) {
                $top_level[] = $category->term_id;
            }
        }
        
        // Build child relationships
        foreach ($categories as $category) {
            if ($category->parent > 0 && isset($category_tree[$category->parent])) {
                $category_tree[$category->parent]['children'][] = $category->term_id;
            }
        }
        
        // Build hierarchical display options
        foreach ($top_level as $category_id) {
            $this->build_category_hierarchy_recursive($category_tree, $category_id, $hierarchy_options, 0);
        }
        
        return $hierarchy_options;
    }
    
    /**
     * Recursively build category hierarchy for display
     * 
     * @param array $category_tree Complete category tree
     * @param int $category_id Current category ID
     * @param array &$hierarchy_options Output array
     * @param int $level Current hierarchy level
     */
    private function build_category_hierarchy_recursive($category_tree, $category_id, &$hierarchy_options, $level) {
        if (!isset($category_tree[$category_id])) {
            return;
        }
        
        $category = $category_tree[$category_id]['category'];
        $indent = str_repeat('— ', $level);
        
        $hierarchy_options[] = [
            'id' => $category->term_id,
            'name' => $category->name,
            'display_name' => $indent . $category->name,
            'level' => $level,
            'parent' => $category->parent,
            'count' => $category->count
        ];
        
        // Process children
        foreach ($category_tree[$category_id]['children'] as $child_id) {
            $this->build_category_hierarchy_recursive($category_tree, $child_id, $hierarchy_options, $level + 1);
        }
    }
    
    /**
     * Clear category manager caches (for testing/debugging)
     */
    public function clear_category_caches() {
        if ($this->category_manager) {
            $this->category_manager->clear_category_cache();
            $this->category_manager->clear_newly_created_tracking();
        }
    }
    
    /**
     * Get WordPress category statistics for monitoring
     * 
     * @return array Category usage statistics
     */
    public function get_category_statistics() {
        if (!$this->category_manager) {
            return ['error' => 'Category manager not available'];
        }
        
        $stats = [
            'total_categories' => 0,
            'categories_with_posts' => 0,
            'empty_categories' => 0,
            'top_level_categories' => 0,
            'categories_with_hierarchy' => 0,
            'max_hierarchy_depth' => 0,
            'recently_created' => count($this->category_manager->get_newly_created_categories())
        ];
        
        $all_categories = get_categories(['hide_empty' => false]);
        $stats['total_categories'] = count($all_categories);
        
        foreach ($all_categories as $category) {
            if ($category->count > 0) {
                $stats['categories_with_posts']++;
            } else {
                $stats['empty_categories']++;
            }
            
            if ($category->parent == 0) {
                $stats['top_level_categories']++;
            } else {
                $stats['categories_with_hierarchy']++;
            }
            
            // Calculate depth
            $hierarchy_info = $this->category_manager->get_hierarchy_info($category->term_id);
            if ($hierarchy_info && $hierarchy_info['level'] > $stats['max_hierarchy_depth']) {
                $stats['max_hierarchy_depth'] = $hierarchy_info['level'];
            }
        }
        
        return $stats;
    }
    
    /**
     * Log info with component prefix
     */
    private function log_info($message, $data = null) {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            wp_autotag_pro()->log_info('[TAXONOMY_APPLIER] ' . $message, $data);
        }
    }
    
    /**
     * Log error with component prefix
     */
    private function log_error($message, $data = null) {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            wp_autotag_pro()->log_error('[TAXONOMY_APPLIER] ' . $message, $data);
        }
    }
    
    // ============================================================================
    // BACKWARD COMPATIBILITY METHODS
    // Methods to maintain compatibility with existing AutoTag_TagApplier interface
    // ============================================================================
    
    /**
     * Legacy compatibility - convert matches to tags (backwards compatible)
     */
    public function convert_matches_to_tags($matches, $config, $mode) {
        // Use the legacy conversion method
        return $this->convert_legacy_matches_to_tags($matches, $config);
    }
    
    /**
     * Legacy compatibility - preview tags only
     */
    public function preview_tags($post_id, $new_tags, $strategy, $year_tag = null) {
        $existing_tags = $this->get_existing_tags($post_id);
        $final_tags = $this->build_final_tag_list($new_tags, $year_tag, $existing_tags, $strategy);
        
        return [
            'existing' => $existing_tags,
            'generated' => $new_tags,
            'final' => $final_tags,
            'year_tag' => $year_tag
        ];
    }
    
}