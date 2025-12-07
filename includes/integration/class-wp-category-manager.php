<?php
/**
 * WordPress Category Management Class - v2.1.0
 * Handles WordPress-specific category operations including hierarchy, validation, and creation
 * Provides comprehensive WordPress category integration for the Auto-Tag Pro plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_WP_Category_Manager {
    
    /**
     * @var array Cache for category lookups to improve performance
     */
    private $category_cache = [];
    
    /**
     * @var array Cache for hierarchy relationships
     */
    private $hierarchy_cache = [];
    
    /**
     * @var array Track newly created categories during session
     */
    private $newly_created = [];
    
    /**
     * Initialize the category manager
     */
    public function __construct() {
        $this->init_hooks();
        $this->clear_cache_on_category_changes();
    }
    
    /**
     * Initialize WordPress hooks for category management
     */
    private function init_hooks() {
        // Clear cache when categories are modified
        add_action('created_category', [$this, 'clear_category_cache']);
        add_action('edited_category', [$this, 'clear_category_cache']);
        add_action('delete_category', [$this, 'clear_category_cache']);
        
        // Hook into category creation for logging
        add_action('wp_autotag_category_created', [$this, 'log_category_creation'], 10, 2);
    }
    
    /**
     * Find or create WordPress categories with hierarchy support
     * 
     * @param array $category_names Array of category names to process
     * @param array $options Processing options
     * @return array Results with category IDs and creation status
     */
    public function process_categories($category_names, $options = []) {
        $defaults = [
            'auto_create' => true,
            'detect_hierarchy' => true,
            'parent_assignment' => 'auto', // 'auto', 'manual', 'none'
            'validate_names' => true,
            'max_hierarchy_depth' => 5
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        $results = [
            'success' => true,
            'category_ids' => [],
            'category_data' => [],
            'created' => [],
            'found' => [],
            'errors' => [],
            'hierarchies_detected' => []
        ];
        
        if (empty($category_names)) {
            $results['success'] = false;
            $results['errors'][] = 'No category names provided';
            return $results;
        }
        
        foreach ($category_names as $category_name) {
            $category_result = $this->process_single_category($category_name, $options);
            
            if ($category_result['success']) {
                $results['category_ids'][] = $category_result['term_id'];
                $results['category_data'][] = $category_result['category_data'];
                
                if ($category_result['created']) {
                    $results['created'][] = $category_result['category_data'];
                } else {
                    $results['found'][] = $category_result['category_data'];
                }
                
                if (!empty($category_result['hierarchy_info'])) {
                    $results['hierarchies_detected'][] = $category_result['hierarchy_info'];
                }
            } else {
                $results['errors'][] = [
                    'category' => $category_name,
                    'error' => $category_result['error']
                ];
            }
        }
        
        if (empty($results['category_ids'])) {
            $results['success'] = false;
        }
        
        return $results;
    }
    
    /**
     * Process a single category with hierarchy detection and creation
     * 
     * @param string $category_name The category name to process
     * @param array $options Processing options
     * @return array Single category processing result
     */
    public function process_single_category($category_name, $options) {
        $category_name = $this->sanitize_category_name($category_name);
        
        if (empty($category_name)) {
            return [
                'success' => false,
                'error' => 'Invalid category name after sanitization'
            ];
        }
        
        // Check if category exists
        $existing_category = $this->find_category($category_name);
        
        if ($existing_category) {
            return [
                'success' => true,
                'term_id' => $existing_category->term_id,
                'category_data' => $this->format_category_data($existing_category),
                'created' => false,
                'hierarchy_info' => $this->get_hierarchy_info($existing_category->term_id)
            ];
        }
        
        // Category doesn't exist - handle creation or error
        if (!$options['auto_create']) {
            return [
                'success' => false,
                'error' => 'Category does not exist and auto_create is disabled'
            ];
        }
        
        // Detect hierarchy if enabled
        $parent_id = 0;
        $hierarchy_info = null;
        
        if ($options['detect_hierarchy'] && $options['parent_assignment'] === 'auto') {
            $hierarchy_detection = $this->detect_hierarchy($category_name);
            if ($hierarchy_detection['parent_detected']) {
                $parent_id = $hierarchy_detection['parent_id'];
                $hierarchy_info = $hierarchy_detection;
            }
        }
        
        // Create the category
        $creation_result = $this->create_category($category_name, $parent_id, $options);
        
        if ($creation_result['success']) {
            return [
                'success' => true,
                'term_id' => $creation_result['term_id'],
                'category_data' => $creation_result['category_data'],
                'created' => true,
                'hierarchy_info' => $hierarchy_info
            ];
        } else {
            return [
                'success' => false,
                'error' => $creation_result['error']
            ];
        }
    }
    
    /**
     * Find an existing WordPress category by name or slug
     * 
     * @param string $category_name The category name to find
     * @return WP_Term|null The found category or null
     */
    public function find_category($category_name) {
        // Check cache first
        $cache_key = md5(strtolower($category_name));
        if (isset($this->category_cache[$cache_key])) {
            return $this->category_cache[$cache_key];
        }
        
        $category = null;
        
        // Try finding by exact name match
        $category = get_term_by('name', $category_name, 'category');
        
        if (!$category) {
            // Try finding by slug
            $slug = sanitize_title($category_name);
            $category = get_term_by('slug', $slug, 'category');
        }
        
        if (!$category) {
            // Try case-insensitive name search
            $category = $this->find_category_case_insensitive($category_name);
        }
        
        // Cache the result (even if null)
        $this->category_cache[$cache_key] = $category;
        
        return $category;
    }
    
    /**
     * Create a new WordPress category with proper error handling
     * 
     * @param string $category_name The category name
     * @param int $parent_id Parent category ID (0 for top-level)
     * @param array $options Creation options
     * @return array Creation result
     */
    public function create_category($category_name, $parent_id = 0, $options = []) {
        // Validate category name
        if (!$this->validate_category_name($category_name)) {
            return [
                'success' => false,
                'error' => 'Invalid category name: ' . $category_name
            ];
        }
        
        // Validate parent if specified
        if ($parent_id > 0 && !term_exists($parent_id, 'category')) {
            return [
                'success' => false,
                'error' => 'Parent category ID does not exist: ' . $parent_id
            ];
        }
        
        // Prepare arguments for wp_insert_term
        $args = [
            'description' => '',
            'parent' => $parent_id
        ];
        
        // Add custom slug if needed
        if (!empty($options['slug'])) {
            $args['slug'] = sanitize_title($options['slug']);
        }
        
        // Attempt to create the category
        $result = wp_insert_term($category_name, 'category', $args);
        
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message(),
                'error_code' => $result->get_error_code()
            ];
        }
        
        // Get the created category data
        $new_category = get_term($result['term_id'], 'category');
        
        if (!$new_category || is_wp_error($new_category)) {
            return [
                'success' => false,
                'error' => 'Failed to retrieve created category'
            ];
        }
        
        // Track as newly created
        $this->newly_created[] = $new_category->term_id;
        
        // Clear cache
        $this->clear_category_cache();
        
        // Fire action hook
        do_action('wp_autotag_category_created', $new_category, $parent_id);
        
        return [
            'success' => true,
            'term_id' => $new_category->term_id,
            'category_data' => $this->format_category_data($new_category)
        ];
    }
    
    /**
     * Detect hierarchy patterns in category names
     * Examples: "Technology > Web Development", "News/Sports", "Parent: Child"
     * 
     * @param string $category_name The category name to analyze
     * @return array Hierarchy detection results
     */
    public function detect_hierarchy($category_name) {
        $result = [
            'parent_detected' => false,
            'parent_name' => null,
            'parent_id' => 0,
            'child_name' => $category_name,
            'separator' => null,
            'hierarchy_depth' => 1
        ];
        
        // Common hierarchy separators
        $separators = [
            ' > ' => ' > ',
            ' >> ' => ' >> ',
            ' / ' => ' / ',
            '/' => '/',
            ' : ' => ' : ',
            ' | ' => ' | ',
            ' - ' => ' - '
        ];
        
        foreach ($separators as $separator => $display_sep) {
            if (strpos($category_name, $separator) !== false) {
                $parts = explode($separator, $category_name);
                
                if (count($parts) >= 2) {
                    $parent_name = trim($parts[0]);
                    $child_name = trim(end($parts));
                    
                    // Find or create parent category
                    $parent_category = $this->find_category($parent_name);
                    
                    if ($parent_category) {
                        $result['parent_detected'] = true;
                        $result['parent_name'] = $parent_name;
                        $result['parent_id'] = $parent_category->term_id;
                        $result['child_name'] = $child_name;
                        $result['separator'] = $display_sep;
                        $result['hierarchy_depth'] = count($parts);
                        break;
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Get category hierarchy information
     * 
     * @param int $category_id The category ID
     * @return array Hierarchy information
     */
    public function get_hierarchy_info($category_id) {
        $cache_key = "hierarchy_{$category_id}";
        if (isset($this->hierarchy_cache[$cache_key])) {
            return $this->hierarchy_cache[$cache_key];
        }
        
        $category = get_term($category_id, 'category');
        if (!$category || is_wp_error($category)) {
            return null;
        }
        
        $hierarchy_info = [
            'category_id' => $category_id,
            'category_name' => $category->name,
            'parent_id' => $category->parent,
            'level' => 0,
            'ancestors' => [],
            'children' => []
        ];
        
        // Get ancestors
        $ancestors = get_ancestors($category_id, 'category');
        if (!empty($ancestors)) {
            $hierarchy_info['ancestors'] = array_reverse($ancestors); // Order from root to immediate parent
            $hierarchy_info['level'] = count($ancestors);
        }
        
        // Get direct children
        $children = get_term_children($category_id, 'category');
        if (!is_wp_error($children)) {
            $hierarchy_info['children'] = $children;
        }
        
        $this->hierarchy_cache[$cache_key] = $hierarchy_info;
        
        return $hierarchy_info;
    }
    
    /**
     * Get category path as a breadcrumb string
     * 
     * @param int $category_id The category ID
     * @param string $separator The separator between levels
     * @return string The category path
     */
    public function get_category_path($category_id, $separator = ' > ') {
        $hierarchy = $this->get_hierarchy_info($category_id);
        
        if (!$hierarchy) {
            return '';
        }
        
        $path_parts = [];
        
        // Add ancestors
        if (!empty($hierarchy['ancestors'])) {
            foreach ($hierarchy['ancestors'] as $ancestor_id) {
                $ancestor = get_term($ancestor_id, 'category');
                if ($ancestor && !is_wp_error($ancestor)) {
                    $path_parts[] = $ancestor->name;
                }
            }
        }
        
        // Add current category
        $path_parts[] = $hierarchy['category_name'];
        
        return implode($separator, $path_parts);
    }
    
    /**
     * Validate category name against WordPress restrictions
     * 
     * @param string $category_name The category name to validate
     * @return bool True if valid, false otherwise
     */
    public function validate_category_name($category_name) {
        if (empty($category_name)) {
            return false;
        }
        
        // Trim whitespace
        $category_name = trim($category_name);
        
        if (empty($category_name)) {
            return false;
        }
        
        // Check length (WordPress has no hard limit, but let's be reasonable)
        if (strlen($category_name) > 200) {
            return false;
        }
        
        // WordPress allows most characters, but let's check for problematic ones
        $invalid_chars = ['<', '>', '"', '&'];
        foreach ($invalid_chars as $char) {
            if (strpos($category_name, $char) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Sanitize category name for WordPress
     * 
     * @param string $category_name The raw category name
     * @return string The sanitized category name
     */
    public function sanitize_category_name($category_name) {
        if (empty($category_name)) {
            return '';
        }
        
        // Basic sanitization
        $category_name = wp_strip_all_tags($category_name);
        $category_name = trim($category_name);
        
        // Remove excessive whitespace
        $category_name = preg_replace('/\s+/', ' ', $category_name);
        
        // Decode HTML entities
        $category_name = html_entity_decode($category_name, ENT_QUOTES, 'UTF-8');
        
        return $category_name;
    }
    
    /**
     * Find category with case-insensitive search
     * 
     * @param string $category_name The category name to search for
     * @return WP_Term|null The found category or null
     */
    private function find_category_case_insensitive($category_name) {
        global $wpdb;
        
        $search_name = strtolower($category_name);
        
        $term_id = $wpdb->get_var($wpdb->prepare("
            SELECT t.term_id 
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            WHERE LOWER(t.name) = %s 
            AND tt.taxonomy = 'category'
            LIMIT 1
        ", $search_name));
        
        if ($term_id) {
            return get_term($term_id, 'category');
        }
        
        return null;
    }
    
    /**
     * Format category data for consistent output
     * 
     * @param WP_Term $category The category term object
     * @return array Formatted category data
     */
    private function format_category_data($category) {
        return [
            'term_id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'parent' => $category->parent,
            'count' => $category->count,
            'path' => $this->get_category_path($category->term_id),
            'hierarchy' => $this->get_hierarchy_info($category->term_id)
        ];
    }
    
    /**
     * Get categories that were created during this session
     * 
     * @return array Array of newly created category IDs
     */
    public function get_newly_created_categories() {
        return $this->newly_created;
    }
    
    /**
     * Clear the newly created categories tracking
     */
    public function clear_newly_created_tracking() {
        $this->newly_created = [];
    }
    
    /**
     * Clear category cache
     */
    public function clear_category_cache() {
        $this->category_cache = [];
        $this->hierarchy_cache = [];
    }
    
    /**
     * Clear cache when categories are modified
     */
    private function clear_cache_on_category_changes() {
        // This is handled by the init_hooks() method
    }
    
    /**
     * Log category creation for debugging/monitoring
     * 
     * @param WP_Term $category The created category
     * @param int $parent_id The parent category ID
     */
    public function log_category_creation($category, $parent_id) {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            wp_autotag_pro()->log_info('[WP_CATEGORY_MANAGER] Category created', [
                'category_id' => $category->term_id,
                'category_name' => $category->name,
                'parent_id' => $parent_id,
                'slug' => $category->slug
            ]);
        }
    }
    
    /**
     * Bulk process multiple categories with batch optimization
     * 
     * @param array $category_names Array of category names
     * @param array $options Processing options
     * @return array Batch processing results
     */
    public function bulk_process_categories($category_names, $options = []) {
        if (empty($category_names)) {
            return [
                'success' => false,
                'error' => 'No categories provided for bulk processing'
            ];
        }
        
        // Pre-populate cache for performance
        $this->preload_category_cache($category_names);
        
        $batch_results = [
            'success' => true,
            'total_requested' => count($category_names),
            'processed' => 0,
            'created' => 0,
            'found' => 0,
            'errors' => 0,
            'category_ids' => [],
            'details' => []
        ];
        
        foreach ($category_names as $category_name) {
            $result = $this->process_single_category($category_name, $options);
            
            $batch_results['processed']++;
            
            if ($result['success']) {
                $batch_results['category_ids'][] = $result['term_id'];
                
                if ($result['created']) {
                    $batch_results['created']++;
                } else {
                    $batch_results['found']++;
                }
            } else {
                $batch_results['errors']++;
                $batch_results['success'] = false;
            }
            
            $batch_results['details'][] = $result;
        }
        
        return $batch_results;
    }
    
    /**
     * Preload category cache for batch operations
     * 
     * @param array $category_names Array of category names to preload
     */
    private function preload_category_cache($category_names) {
        global $wpdb;
        
        if (empty($category_names)) {
            return;
        }
        
        // Prepare placeholders
        $placeholders = implode(',', array_fill(0, count($category_names), '%s'));
        
        // Query for all categories at once
        $query = $wpdb->prepare("
            SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            WHERE t.name IN ($placeholders) 
            AND tt.taxonomy = 'category'
        ", $category_names);
        
        $categories = $wpdb->get_results($query);
        
        // Populate cache
        foreach ($categories as $cat_data) {
            $category = new WP_Term((object)$cat_data);
            $cache_key = md5(strtolower($cat_data->name));
            $this->category_cache[$cache_key] = $category;
        }
    }
}