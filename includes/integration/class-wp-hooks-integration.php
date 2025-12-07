<?php
/**
 * WordPress Hooks Integration Class - v2.1.0
 * Provides comprehensive WordPress hooks integration for category operations
 * Handles WordPress admin integration, category management screens, and AJAX operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_WP_Hooks_Integration {
    
    /**
     * @var AutoTag_WP_Category_Manager Category manager instance
     */
    private $category_manager;
    
    /**
     * @var AutoTag_TaxonomyApplier Taxonomy applier instance
     */
    private $taxonomy_applier;
    
    /**
     * @var array Hooks registry for debugging
     */
    private $hooks_registry = [];
    
    /**
     * Initialize WordPress hooks integration
     */
    public function __construct() {
        $this->init_dependencies();
        $this->init_wordpress_hooks();
        $this->init_admin_hooks();
        $this->init_ajax_hooks();
        $this->init_category_management_hooks();
    }
    
    /**
     * Initialize required dependencies
     */
    private function init_dependencies() {
        // Initialize Category Manager
        if (!class_exists('AutoTag_WP_Category_Manager')) {
            require_once plugin_dir_path(__FILE__) . 'class-wp-category-manager.php';
        }
        $this->category_manager = new AutoTag_WP_Category_Manager();
        
        // Initialize Taxonomy Applier
        if (!class_exists('AutoTag_TaxonomyApplier')) {
            require_once plugin_dir_path(__FILE__) . '../processing/class-taxonomy-applier.php';
        }
        $this->taxonomy_applier = new AutoTag_TaxonomyApplier();
    }
    
    /**
     * Initialize core WordPress hooks for category operations
     */
    private function init_wordpress_hooks() {
        // Hook into WordPress category creation/editing
        add_action('created_category', [$this, 'on_category_created'], 10, 2);
        add_action('edited_category', [$this, 'on_category_edited'], 10, 2);
        add_action('delete_category', [$this, 'on_category_deleted'], 10, 1);
        
        // Hook into post save for automatic category application
        add_action('save_post', [$this, 'on_post_save'], 20, 3);
        add_action('wp_insert_post', [$this, 'on_post_insert'], 20, 3);
        
        // Hook into taxonomy operations
        add_action('set_object_terms', [$this, 'on_set_object_terms'], 10, 6);
        
        // Category hierarchy changes
        add_action('edit_term_taxonomy', [$this, 'on_category_hierarchy_change'], 10, 2);
        
        $this->register_hook('WordPress Core Category Operations', [
            'created_category', 'edited_category', 'delete_category',
            'save_post', 'wp_insert_post', 'set_object_terms', 'edit_term_taxonomy'
        ]);
    }
    
    /**
     * Initialize WordPress admin hooks for category management
     */
    private function init_admin_hooks() {
        if (!is_admin()) {
            return;
        }
        
        // Add custom fields to category add/edit forms
        add_action('category_add_form_fields', [$this, 'add_category_form_fields'], 10, 1);
        add_action('category_edit_form_fields', [$this, 'edit_category_form_fields'], 10, 2);
        
        // Add category management to Auto-Tag Pro admin pages
        add_action('admin_menu', [$this, 'add_category_admin_menu'], 20);
        add_action('admin_init', [$this, 'register_category_settings']);
        
        // Enqueue category management scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_category_admin_scripts']);
        
        // Add category columns to admin lists
        add_filter('manage_edit-category_columns', [$this, 'add_category_admin_columns']);
        add_action('manage_category_custom_column', [$this, 'populate_category_admin_columns'], 10, 3);
        
        // Add category meta boxes to post edit screens
        add_action('add_meta_boxes', [$this, 'add_category_meta_boxes']);
        
        $this->register_hook('WordPress Admin Integration', [
            'category_add_form_fields', 'category_edit_form_fields', 'admin_menu',
            'admin_init', 'admin_enqueue_scripts', 'add_meta_boxes'
        ]);
    }
    
    /**
     * Initialize AJAX hooks for category operations
     */
    private function init_ajax_hooks() {
        // Category validation AJAX
        add_action('wp_ajax_autotag_validate_categories', [$this, 'ajax_validate_categories']);
        add_action('wp_ajax_autotag_create_category', [$this, 'ajax_create_category']);
        add_action('wp_ajax_autotag_get_category_hierarchy', [$this, 'ajax_get_category_hierarchy']);
        add_action('wp_ajax_autotag_preview_categories', [$this, 'ajax_preview_categories']);
        
        // Category search and selection AJAX
        add_action('wp_ajax_autotag_search_categories', [$this, 'ajax_search_categories']);
        add_action('wp_ajax_autotag_get_category_suggestions', [$this, 'ajax_get_category_suggestions']);
        
        // Bulk category operations AJAX
        add_action('wp_ajax_autotag_bulk_create_categories', [$this, 'ajax_bulk_create_categories']);
        add_action('wp_ajax_autotag_bulk_assign_categories', [$this, 'ajax_bulk_assign_categories']);
        
        $this->register_hook('AJAX Category Operations', [
            'wp_ajax_autotag_validate_categories', 'wp_ajax_autotag_create_category',
            'wp_ajax_autotag_get_category_hierarchy', 'wp_ajax_autotag_preview_categories',
            'wp_ajax_autotag_search_categories', 'wp_ajax_autotag_bulk_create_categories'
        ]);
    }
    
    /**
     * Initialize category-specific management hooks
     */
    private function init_category_management_hooks() {
        // Custom Auto-Tag Pro category hooks
        add_action('wp_autotag_category_created', [$this, 'on_autotag_category_created'], 10, 2);
        add_action('wp_autotag_categories_applied', [$this, 'on_categories_applied'], 10, 3);
        add_action('wp_autotag_hierarchy_detected', [$this, 'on_hierarchy_detected'], 10, 2);
        
        // Integration with other plugins
        add_filter('wp_autotag_category_validation', [$this, 'filter_category_validation'], 10, 2);
        add_filter('wp_autotag_category_creation', [$this, 'filter_category_creation'], 10, 3);
        
        $this->register_hook('Auto-Tag Pro Category Management', [
            'wp_autotag_category_created', 'wp_autotag_categories_applied',
            'wp_autotag_hierarchy_detected'
        ]);
    }
    
    // ============================================================================
    // WORDPRESS CORE HOOK HANDLERS
    // ============================================================================
    
    /**
     * Handle category creation in WordPress
     * 
     * @param int $term_id Created category ID
     * @param int $tt_id Term taxonomy ID
     */
    public function on_category_created($term_id, $tt_id) {
        $category = get_term($term_id, 'category');
        
        if ($category && !is_wp_error($category)) {
            $this->log_info('WordPress category created', [
                'term_id' => $term_id,
                'name' => $category->name,
                'parent' => $category->parent
            ]);
            
            // Clear category manager caches
            if ($this->category_manager) {
                $this->category_manager->clear_category_cache();
            }
            
            // Fire custom hook for other integrations
            do_action('wp_autotag_wp_category_created', $category, $tt_id);
        }
    }
    
    /**
     * Handle category editing in WordPress
     * 
     * @param int $term_id Edited category ID
     * @param int $tt_id Term taxonomy ID
     */
    public function on_category_edited($term_id, $tt_id) {
        $category = get_term($term_id, 'category');
        
        if ($category && !is_wp_error($category)) {
            $this->log_info('WordPress category edited', [
                'term_id' => $term_id,
                'name' => $category->name,
                'parent' => $category->parent
            ]);
            
            // Clear category manager caches
            if ($this->category_manager) {
                $this->category_manager->clear_category_cache();
            }
            
            // Fire custom hook
            do_action('wp_autotag_wp_category_edited', $category, $tt_id);
        }
    }
    
    /**
     * Handle category deletion in WordPress
     * 
     * @param int $term_id Deleted category ID
     */
    public function on_category_deleted($term_id) {
        $this->log_info('WordPress category deleted', ['term_id' => $term_id]);
        
        // Clear category manager caches
        if ($this->category_manager) {
            $this->category_manager->clear_category_cache();
        }
        
        // Fire custom hook
        do_action('wp_autotag_wp_category_deleted', $term_id);
    }
    
    /**
     * Handle post save for automatic category application
     * 
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $update Whether this is an update
     */
    public function on_post_save($post_id, $post, $update) {
        // Skip for autosaves, revisions, and bulk operations
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Only process published posts
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Check if Auto-Tag Pro should process this post type
        if (!$this->should_process_post_type($post->post_type)) {
            return;
        }
        
        // Check if automatic processing is enabled
        if (!$this->is_automatic_processing_enabled()) {
            return;
        }
        
        $this->log_info('Post save triggered automatic category processing', [
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'update' => $update
        ]);
        
        // Trigger Auto-Tag Pro processing (this would integrate with the main processor)
        do_action('wp_autotag_process_post_categories', $post_id, $post, $update);
    }
    
    /**
     * Handle category hierarchy changes
     * 
     * @param int $tt_id Term taxonomy ID
     * @param string $taxonomy Taxonomy name
     */
    public function on_category_hierarchy_change($tt_id, $taxonomy) {
        if ($taxonomy !== 'category') {
            return;
        }
        
        $this->log_info('Category hierarchy changed', ['tt_id' => $tt_id]);
        
        // Clear hierarchy caches
        if ($this->category_manager) {
            $this->category_manager->clear_category_cache();
        }
    }
    
    // ============================================================================
    // ADMIN INTEGRATION HANDLERS
    // ============================================================================
    
    /**
     * Add custom fields to category add form
     * 
     * @param string $taxonomy Taxonomy name
     */
    public function add_category_form_fields($taxonomy) {
        if ($taxonomy !== 'category') {
            return;
        }
        
        ?>
        <div class="form-field wp-autotag-category-fields">
            <label for="wp_autotag_auto_create"><?php _e('Auto-Tag Pro Integration', 'wp-auto-tag-pro'); ?></label>
            <input type="checkbox" name="wp_autotag_auto_create" id="wp_autotag_auto_create" value="1" checked>
            <p class="description">
                <?php _e('Allow Auto-Tag Pro to automatically create and manage this category.', 'wp-auto-tag-pro'); ?>
            </p>
        </div>
        
        <div class="form-field wp-autotag-hierarchy-detection">
            <label for="wp_autotag_hierarchy_rules"><?php _e('Hierarchy Detection Rules', 'wp-auto-tag-pro'); ?></label>
            <textarea name="wp_autotag_hierarchy_rules" id="wp_autotag_hierarchy_rules" rows="3" cols="40" placeholder="technology > web development&#10;news / sports&#10;parent : child"></textarea>
            <p class="description">
                <?php _e('Define hierarchy patterns that Auto-Tag Pro should recognize (one per line).', 'wp-auto-tag-pro'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Add custom fields to category edit form
     * 
     * @param WP_Term $term Category term
     * @param string $taxonomy Taxonomy name
     */
    public function edit_category_form_fields($term, $taxonomy) {
        if ($taxonomy !== 'category') {
            return;
        }
        
        $auto_create = get_term_meta($term->term_id, 'wp_autotag_auto_create', true);
        $hierarchy_rules = get_term_meta($term->term_id, 'wp_autotag_hierarchy_rules', true);
        
        ?>
        <tr class="form-field wp-autotag-category-fields">
            <th scope="row">
                <label for="wp_autotag_auto_create"><?php _e('Auto-Tag Pro Integration', 'wp-auto-tag-pro'); ?></label>
            </th>
            <td>
                <input type="checkbox" name="wp_autotag_auto_create" id="wp_autotag_auto_create" value="1" <?php checked($auto_create, '1'); ?>>
                <p class="description">
                    <?php _e('Allow Auto-Tag Pro to automatically create and manage this category.', 'wp-auto-tag-pro'); ?>
                </p>
            </td>
        </tr>
        
        <tr class="form-field wp-autotag-hierarchy-detection">
            <th scope="row">
                <label for="wp_autotag_hierarchy_rules"><?php _e('Hierarchy Detection Rules', 'wp-auto-tag-pro'); ?></label>
            </th>
            <td>
                <textarea name="wp_autotag_hierarchy_rules" id="wp_autotag_hierarchy_rules" rows="3" cols="50"><?php echo esc_textarea($hierarchy_rules); ?></textarea>
                <p class="description">
                    <?php _e('Define hierarchy patterns that Auto-Tag Pro should recognize (one per line).', 'wp-auto-tag-pro'); ?>
                </p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Add category management to admin menu
     */
    public function add_category_admin_menu() {
        add_submenu_page(
            'wp-auto-tag-pro',
            __('Category Management', 'wp-auto-tag-pro'),
            __('Categories', 'wp-auto-tag-pro'),
            'manage_categories',
            'wp-auto-tag-pro-categories',
            [$this, 'render_category_management_page']
        );
    }
    
    /**
     * Register category-related settings
     */
    public function register_category_settings() {
        register_setting('wp_autotag_pro_categories', 'wp_autotag_category_settings');
        
        add_settings_section(
            'wp_autotag_category_general',
            __('Category Settings', 'wp-auto-tag-pro'),
            [$this, 'render_category_settings_section'],
            'wp_autotag_pro_categories'
        );
        
        add_settings_field(
            'auto_create_categories',
            __('Auto-create Categories', 'wp-auto-tag-pro'),
            [$this, 'render_auto_create_setting'],
            'wp_autotag_pro_categories',
            'wp_autotag_category_general'
        );
        
        add_settings_field(
            'hierarchy_detection',
            __('Hierarchy Detection', 'wp-auto-tag-pro'),
            [$this, 'render_hierarchy_detection_setting'],
            'wp_autotag_pro_categories',
            'wp_autotag_category_general'
        );
    }
    
    /**
     * Enqueue category management admin scripts
     * Note: Category functionality is handled by main admin.js
     * 
     * @param string $hook Current admin page hook
     */
    public function enqueue_category_admin_scripts($hook) {
        // Category functionality is integrated into main admin scripts
        // No separate assets needed - main admin.js handles all functionality
        return;
    }
    
    // ============================================================================
    // AJAX HANDLERS
    // ============================================================================
    
    /**
     * AJAX handler for category validation
     */
    public function ajax_validate_categories() {
        check_ajax_referer('wp_autotag_category_nonce', 'nonce');
        
        $categories = sanitize_text_field($_POST['categories'] ?? '');
        $category_names = array_map('trim', explode(',', $categories));
        $category_names = array_filter($category_names);
        
        if (empty($category_names)) {
            wp_send_json_error('No categories provided');
        }
        
        $validation_result = $this->taxonomy_applier->validate_categories_against_wordpress($category_names);
        
        wp_send_json_success($validation_result);
    }
    
    /**
     * AJAX handler for category creation
     */
    public function ajax_create_category() {
        check_ajax_referer('wp_autotag_category_nonce', 'nonce');
        
        if (!current_user_can('manage_categories')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $category_name = sanitize_text_field($_POST['category_name'] ?? '');
        $parent_id = intval($_POST['parent_id'] ?? 0);
        $detect_hierarchy = $_POST['detect_hierarchy'] === 'true';
        
        if (empty($category_name)) {
            wp_send_json_error('Category name is required');
        }
        
        $options = [
            'auto_create' => true,
            'detect_hierarchy' => $detect_hierarchy,
            'parent_assignment' => 'manual'
        ];
        
        if ($parent_id > 0) {
            // Manual parent assignment
            $creation_result = $this->category_manager->create_category($category_name, $parent_id, $options);
        } else {
            // Use category manager processing
            $processing_result = $this->category_manager->process_single_category($category_name, $options);
            $creation_result = $processing_result;
        }
        
        if ($creation_result['success']) {
            wp_send_json_success($creation_result);
        } else {
            wp_send_json_error($creation_result['error']);
        }
    }
    
    /**
     * AJAX handler for getting category hierarchy
     */
    public function ajax_get_category_hierarchy() {
        check_ajax_referer('wp_autotag_category_nonce', 'nonce');
        
        $category_id = intval($_POST['category_id'] ?? 0);
        
        if ($category_id <= 0) {
            wp_send_json_error('Invalid category ID');
        }
        
        $hierarchy_info = $this->category_manager->get_hierarchy_info($category_id);
        $category_path = $this->category_manager->get_category_path($category_id);
        
        wp_send_json_success([
            'hierarchy' => $hierarchy_info,
            'path' => $category_path
        ]);
    }
    
    /**
     * AJAX handler for category preview
     */
    public function ajax_preview_categories() {
        check_ajax_referer('wp_autotag_category_nonce', 'nonce');
        
        $categories = sanitize_text_field($_POST['categories'] ?? '');
        $existing = sanitize_text_field($_POST['existing'] ?? '');
        $strategy = sanitize_text_field($_POST['strategy'] ?? 'merge');
        
        $category_names = array_map('trim', explode(',', $categories));
        $existing_categories = array_map('trim', explode(',', $existing));
        $category_names = array_filter($category_names);
        $existing_categories = array_filter($existing_categories);
        
        $options = ['category_strategy' => $strategy];
        $preview_result = $this->taxonomy_applier->preview_categories($category_names, $existing_categories, $options);
        
        wp_send_json_success($preview_result);
    }
    
    // ============================================================================
    // UTILITY METHODS
    // ============================================================================
    
    /**
     * Check if post type should be processed by Auto-Tag Pro
     * 
     * @param string $post_type Post type name
     * @return bool Whether to process this post type
     */
    private function should_process_post_type($post_type) {
        // Get Auto-Tag Pro settings
        $enabled_post_types = get_option('wp_autotag_post_types', ['post']);
        return in_array($post_type, $enabled_post_types);
    }
    
    /**
     * Check if automatic processing is enabled
     * 
     * @return bool Whether automatic processing is enabled
     */
    private function is_automatic_processing_enabled() {
        return get_option('wp_autotag_automatic_processing', true);
    }
    
    /**
     * Register hook for debugging purposes
     * 
     * @param string $group Hook group name
     * @param array $hooks Array of hook names
     */
    private function register_hook($group, $hooks) {
        $this->hooks_registry[$group] = $hooks;
    }
    
    /**
     * Get all registered hooks (for debugging)
     * 
     * @return array Hooks registry
     */
    public function get_registered_hooks() {
        return $this->hooks_registry;
    }
    
    /**
     * Custom hook handlers for Auto-Tag Pro category events
     */
    public function on_autotag_category_created($category, $parent_id) {
        $this->log_info('Auto-Tag Pro category created', [
            'category_name' => $category->name,
            'category_id' => $category->term_id,
            'parent_id' => $parent_id
        ]);
    }
    
    public function on_categories_applied($post_id, $categories_applied, $options) {
        $this->log_info('Categories applied to post', [
            'post_id' => $post_id,
            'categories_count' => count($categories_applied),
            'strategy' => $options['category_strategy'] ?? 'unknown'
        ]);
    }
    
    public function on_hierarchy_detected($category_name, $hierarchy_info) {
        $this->log_info('Category hierarchy detected', [
            'category' => $category_name,
            'hierarchy' => $hierarchy_info
        ]);
    }
    
    /**
     * Filter category validation
     */
    public function filter_category_validation($is_valid, $category_name) {
        // Allow other plugins to modify category validation
        return $is_valid;
    }
    
    /**
     * Filter category creation
     */
    public function filter_category_creation($should_create, $category_name, $options) {
        // Allow other plugins to prevent category creation
        return $should_create;
    }
    
    /**
     * Render category management admin page
     */
    public function render_category_management_page() {
        $stats = $this->taxonomy_applier->get_category_statistics();
        $selection_data = $this->taxonomy_applier->get_category_selection_data();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Auto-Tag Pro - Category Management', 'wp-auto-tag-pro'); ?></h1>
            
            <div class="wp-autotag-category-stats">
                <h2><?php _e('Category Statistics', 'wp-auto-tag-pro'); ?></h2>
                <table class="widefat">
                    <tr><td><?php _e('Total Categories', 'wp-auto-tag-pro'); ?></td><td><?php echo $stats['total_categories']; ?></td></tr>
                    <tr><td><?php _e('Categories with Posts', 'wp-auto-tag-pro'); ?></td><td><?php echo $stats['categories_with_posts']; ?></td></tr>
                    <tr><td><?php _e('Empty Categories', 'wp-auto-tag-pro'); ?></td><td><?php echo $stats['empty_categories']; ?></td></tr>
                    <tr><td><?php _e('Top-level Categories', 'wp-auto-tag-pro'); ?></td><td><?php echo $stats['top_level_categories']; ?></td></tr>
                    <tr><td><?php _e('Categories with Hierarchy', 'wp-auto-tag-pro'); ?></td><td><?php echo $stats['categories_with_hierarchy']; ?></td></tr>
                    <tr><td><?php _e('Max Hierarchy Depth', 'wp-auto-tag-pro'); ?></td><td><?php echo $stats['max_hierarchy_depth']; ?></td></tr>
                    <tr><td><?php _e('Recently Created', 'wp-auto-tag-pro'); ?></td><td><?php echo $stats['recently_created']; ?></td></tr>
                </table>
            </div>
            
            <div class="wp-autotag-category-tools">
                <h2><?php _e('Category Tools', 'wp-auto-tag-pro'); ?></h2>
                <p><?php _e('Use these tools to manage categories for Auto-Tag Pro.', 'wp-auto-tag-pro'); ?></p>
                
                <div class="wp-autotag-tool-section">
                    <h3><?php _e('Validate Categories', 'wp-auto-tag-pro'); ?></h3>
                    <input type="text" id="validate-categories" placeholder="<?php _e('Enter categories separated by commas', 'wp-auto-tag-pro'); ?>" style="width: 400px;">
                    <button type="button" id="validate-categories-btn" class="button"><?php _e('Validate', 'wp-auto-tag-pro'); ?></button>
                    <div id="validation-results"></div>
                </div>
                
                <div class="wp-autotag-tool-section">
                    <h3><?php _e('Create Category', 'wp-auto-tag-pro'); ?></h3>
                    <input type="text" id="create-category-name" placeholder="<?php _e('Category name', 'wp-auto-tag-pro'); ?>">
                    <select id="create-category-parent">
                        <option value="0"><?php _e('No parent (top-level)', 'wp-auto-tag-pro'); ?></option>
                        <?php foreach ($selection_data['hierarchy_options'] as $option): ?>
                            <option value="<?php echo $option['id']; ?>"><?php echo esc_html($option['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label><input type="checkbox" id="create-category-detect-hierarchy" checked> <?php _e('Detect hierarchy', 'wp-auto-tag-pro'); ?></label>
                    <button type="button" id="create-category-btn" class="button button-primary"><?php _e('Create', 'wp-auto-tag-pro'); ?></button>
                    <div id="creation-results"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render category settings section
     */
    public function render_category_settings_section() {
        echo '<p>' . __('Configure how Auto-Tag Pro handles WordPress categories.', 'wp-auto-tag-pro') . '</p>';
    }
    
    /**
     * Render auto-create setting
     */
    public function render_auto_create_setting() {
        $settings = get_option('wp_autotag_category_settings', []);
        $auto_create = $settings['auto_create'] ?? true;
        
        echo '<input type="checkbox" name="wp_autotag_category_settings[auto_create]" value="1" ' . checked($auto_create, true, false) . '>';
        echo '<p class="description">' . __('Automatically create categories that don\'t exist when processing rules.', 'wp-auto-tag-pro') . '</p>';
    }
    
    /**
     * Render hierarchy detection setting
     */
    public function render_hierarchy_detection_setting() {
        $settings = get_option('wp_autotag_category_settings', []);
        $hierarchy_detection = $settings['hierarchy_detection'] ?? true;
        
        echo '<input type="checkbox" name="wp_autotag_category_settings[hierarchy_detection]" value="1" ' . checked($hierarchy_detection, true, false) . '>';
        echo '<p class="description">' . __('Automatically detect and create category hierarchies from category names.', 'wp-auto-tag-pro') . '</p>';
    }
    
    /**
     * Log info with component prefix
     */
    private function log_info($message, $data = null) {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            wp_autotag_pro()->log_info('[WP_HOOKS_INTEGRATION] ' . $message, $data);
        }
    }
    
    /**
     * Log error with component prefix
     */
    private function log_error($message, $data = null) {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            wp_autotag_pro()->log_error('[WP_HOOKS_INTEGRATION] ' . $message, $data);
        }
    }
}