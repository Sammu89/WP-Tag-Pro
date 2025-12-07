<?php
/**
 * Updated Admin Interface Class - CORE LOGIC ONLY
 * Handles dual-mode interface, family management, year tags, and WordPress tags integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Add security nonce field for forms
     */
    public function add_security_nonce($action = 'autotag_admin_action') {
        wp_nonce_field($action, $action . '_nonce');
    }
    
    /**
     * Initialize admin hooks
     */
    private function init_hooks() {
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Post edit screen meta box
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_box_data']);
        
        // Bulk actions in post list
        add_filter('bulk_actions-edit-post', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_actions'], 10, 3);
        
        // Admin notices
        add_action('admin_notices', [$this, 'display_admin_notices']);
        
        // AJAX handlers for unified rules management - Updated for v2.1.0
        add_action('wp_ajax_autotag_save_rule', [$this, 'ajax_save_rule']);
        add_action('wp_ajax_autotag_delete_rule', [$this, 'ajax_delete_rule']);
        add_action('wp_ajax_autotag_get_rules', [$this, 'ajax_get_rules']);
        add_action('wp_ajax_autotag_check_conflicts', [$this, 'ajax_check_conflicts']);
        add_action('wp_ajax_autotag_save_quick_setup', [$this, 'ajax_save_quick_setup']);
        add_action('wp_ajax_autotag_save_dashboard_settings', [$this, 'ajax_save_dashboard_settings']);
        add_action('wp_ajax_autotag_import_config', [$this, 'ajax_import_config']);
        add_action('wp_ajax_autotag_export_config', [$this, 'ajax_export_config']);
        add_action('wp_ajax_autotag_preview_post', [$this, 'ajax_preview_post']);
        add_action('wp_ajax_autotag_generate_post', [$this, 'ajax_generate_post']);
        add_action('wp_ajax_autotag_apply_tags', [$this, 'ajax_apply_tags']);
        add_action('wp_ajax_autotag_apply_categories', [$this, 'ajax_apply_categories']);
        
        // Plugin action links
        add_filter('plugin_action_links_' . (defined('WP_AUTOTAG_PRO_PLUGIN_BASENAME') ? WP_AUTOTAG_PRO_PLUGIN_BASENAME : ''), [$this, 'add_plugin_action_links']);
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main settings page
        add_options_page(
            __('Auto-Tag Pro Settings', 'wp-auto-tag-pro'),
            __('Auto-Tag Pro', 'wp-auto-tag-pro'),
            'manage_options',
            'wp-auto-tag-pro',
            [$this, 'render_settings_page']
        );
        
        // Tools submenu for bulk operations
        add_management_page(
            __('Auto-Tag Pro Tools', 'wp-auto-tag-pro'),
            __('Auto-Tag Pro', 'wp-auto-tag-pro'),
            'manage_options',
            'wp-auto-tag-pro-tools',
            [$this, 'render_tools_page']
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'wp_autotag_pro_settings',
            'wp_autotag_pro_settings',
            [$this, 'sanitize_settings']
        );
        
        // Settings moved to distributed tabs - no longer needed
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (!in_array($hook, ['settings_page_wp-auto-tag-pro', 'tools_page_wp-auto-tag-pro-tools'])) {
            return;
        }
        
        wp_enqueue_script(
            'wp-autotag-pro-admin',
            WP_AUTOTAG_PRO_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery', 'wp-util'],
            WP_AUTOTAG_PRO_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wp-autotag-pro-admin',
            WP_AUTOTAG_PRO_PLUGIN_URL . 'admin/css/admin.css',
            [],
            WP_AUTOTAG_PRO_VERSION
        );
        
        // Localize script with proper nonce
        wp_localize_script('wp-autotag-pro-admin', 'autotagAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('autotag_admin_nonce'),
            'rollback_nonce' => wp_create_nonce('autotag_rollback_nonce'),
            'strings' => [
                'processing' => __('Processing...', 'wp-auto-tag-pro'),
                'error' => __('Error occurred', 'wp-auto-tag-pro'),
                'success' => __('Success', 'wp-auto-tag-pro'),
                'confirm_bulk' => __('Are you sure you want to process these posts?', 'wp-auto-tag-pro'),
                'confirm_mode_switch' => __('Switching modes will change your current configuration. Continue?', 'wp-auto-tag-pro'),
                'confirm_convert' => __('This will convert your current configuration. This action cannot be undone. Continue?', 'wp-auto-tag-pro'),
                'confirm_year_tags_retroactive' => __('This will add year tags to ALL existing posts. This may take a while. Continue?', 'wp-auto-tag-pro'),
                'config_saved' => __('Configuration saved successfully', 'wp-auto-tag-pro'),
                'family_added' => __('Family added successfully', 'wp-auto-tag-pro'),
                'family_removed' => __('Family removed successfully', 'wp-auto-tag-pro'),
                'import_success' => __('Configuration imported successfully', 'wp-auto-tag-pro'),
                'mode_switched' => __('Mode switched successfully', 'wp-auto-tag-pro'),
                'converted_successfully' => __('Configuration converted successfully', 'wp-auto-tag-pro')
            ]
        ]);
    }
    
    /**
     * Add meta boxes to post edit screens
     */
    public function add_meta_boxes() {
        // Security check - only for users who can edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }
        
        // Get settings with fallback
        $enabled_post_types = ['post'];
        if (function_exists('wp_autotag_pro')) {
            $settings = wp_autotag_pro()->get_settings();
            $enabled_post_types = $settings['enabled_post_types'] ?? ['post'];
        }
        
        foreach ($enabled_post_types as $post_type) {
            add_meta_box(
                'autotag-status',
                __('Auto-Tag Status', 'wp-auto-tag-pro'),
                [$this, 'render_post_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }
    
    /**
     * Get status display information - FIXED: Made public for HTML class
     */
    public function get_status_display($status, $processed_hash, $current_hash) {
        if ($status === 'disabled') {
            return ['text' => __('Disabled', 'wp-auto-tag-pro'), 'class' => 'disabled'];
        }
        
        if ($status === 'pending' || empty($processed_hash)) {
            return ['text' => __('Pending', 'wp-auto-tag-pro'), 'class' => 'pending'];
        }
        
        if (!empty($current_hash) && $processed_hash !== $current_hash) {
            return ['text' => __('Outdated', 'wp-auto-tag-pro'), 'class' => 'outdated'];
        }
        
        return ['text' => __('Up to Date', 'wp-auto-tag-pro'), 'class' => 'current'];
    }
    
    /**
     * Save meta box data - FIXED: Enhanced security validation
     */
    public function save_meta_box_data($post_id) {
        // Verify nonce
        if (!isset($_POST['autotag_meta_box_nonce']) || !wp_verify_nonce($_POST['autotag_meta_box_nonce'], 'autotag_meta_box')) {
            return;
        }
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Update status if provided
        if (isset($_POST['autotag_status'])) {
            $status = sanitize_text_field($_POST['autotag_status']);
            $allowed_statuses = ['pending', 'processed', 'disabled'];
            
            if (in_array($status, $allowed_statuses)) {
                update_post_meta($post_id, 'auto_tag_status', $status);
            }
        }
    }
    
    /**
     * Add bulk actions to post list
     */
    public function add_bulk_actions($actions) {
        $actions['autotag_process'] = __('Auto-Tag: Process', 'wp-auto-tag-pro');
        $actions['autotag_disable'] = __('Auto-Tag: Disable', 'wp-auto-tag-pro');
        $actions['autotag_enable'] = __('Auto-Tag: Enable', 'wp-auto-tag-pro');
        return $actions;
    }
    
    /**
     * Handle bulk actions - FIXED: Enhanced security and error handling
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        $allowed_actions = ['autotag_process', 'autotag_disable', 'autotag_enable'];
        
        if (!in_array($doaction, $allowed_actions)) {
            return $redirect_to;
        }
        
        if (!current_user_can('edit_posts')) {
            return $redirect_to;
        }
        
        if (!is_array($post_ids) || empty($post_ids)) {
            return $redirect_to;
        }
        
        $processed = 0;
        $errors = 0;
        
        foreach ($post_ids as $post_id) {
            $post_id = intval($post_id);
            
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                $errors++;
                continue;
            }
            
            try {
                switch ($doaction) {
                    case 'autotag_process':
                        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->processor) {
                            $result = wp_autotag_pro()->processor->process_post($post_id);
                            if (!is_wp_error($result)) {
                                $processed++;
                            } else {
                                $errors++;
                            }
                        }
                        break;
                        
                    case 'autotag_disable':
                        update_post_meta($post_id, 'auto_tag_status', 'disabled');
                        $processed++;
                        break;
                        
                    case 'autotag_enable':
                        update_post_meta($post_id, 'auto_tag_status', 'pending');
                        $processed++;
                        break;
                }
            } catch (Exception $e) {
                $errors++;
                if (function_exists('wp_autotag_pro')) {
                    wp_autotag_pro()->log_error('Bulk action error for post ' . $post_id . ': ' . $e->getMessage());
                }
            }
        }
        
        $redirect_to = add_query_arg('autotag_processed', $processed, $redirect_to);
        
        if ($errors > 0) {
            $redirect_to = add_query_arg('autotag_errors', $errors, $redirect_to);
        }
        
        return $redirect_to;
    }
    
    /**
     * Display admin notices - FIXED: Enhanced with error handling
     */
    public function display_admin_notices() {
        // Success notice
        if (isset($_GET['autotag_processed']) && $_GET['autotag_processed'] > 0) {
            $processed = intval($_GET['autotag_processed']);
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . sprintf(_n('Processed %d post.', 'Processed %d posts.', $processed, 'wp-auto-tag-pro'), $processed) . '</p>';
            echo '</div>';
        }
        
        // Error notice
        if (isset($_GET['autotag_errors']) && $_GET['autotag_errors'] > 0) {
            $errors = intval($_GET['autotag_errors']);
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . sprintf(_n('%d post could not be processed.', '%d posts could not be processed.', $errors, 'wp-auto-tag-pro'), $errors) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Sanitize settings - FIXED: Enhanced validation
     */
    public function sanitize_settings($input) {
        // Additional security checks
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to modify these settings.', 'wp-auto-tag-pro'));
        }

        // Verify nonce if available
        if (isset($_POST['autotag_settings_nonce']) && !wp_verify_nonce($_POST['autotag_settings_nonce'], 'autotag_settings_save')) {
            wp_die(__('Security verification failed. Please try again.', 'wp-auto-tag-pro'));
        }
        
        if (!is_array($input)) {
            return [];
        }
        
        $output = [];
        
        // Sanitize enabled post types
        if (isset($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
            $output['enabled_post_types'] = array_map('sanitize_text_field', $input['enabled_post_types']);
            // Ensure at least one post type is enabled
            if (empty($output['enabled_post_types'])) {
                $output['enabled_post_types'] = ['post'];
            }
        } else {
            $output['enabled_post_types'] = ['post'];
        }
        
        // Sanitize enabled taxonomies
        if (isset($input['enabled_taxonomies']) && is_array($input['enabled_taxonomies'])) {
            $output['enabled_taxonomies'] = array_map('sanitize_text_field', $input['enabled_taxonomies']);
            // Ensure at least one taxonomy is enabled
            if (empty($output['enabled_taxonomies'])) {
                $output['enabled_taxonomies'] = ['post_tag'];
            }
        } else {
            $output['enabled_taxonomies'] = ['post_tag'];
        }
        
        // Sanitize content areas
        if (isset($input['content_areas']) && is_array($input['content_areas'])) {
            $allowed_areas = ['title', 'content', 'excerpt', 'custom_fields'];
            $output['content_areas'] = array_intersect($input['content_areas'], $allowed_areas);
            // Ensure at least one content area is enabled
            if (empty($output['content_areas'])) {
                $output['content_areas'] = ['title', 'content'];
            }
        } else {
            $output['content_areas'] = ['title', 'content'];
        }
        
        // Sanitize boolean settings
        $boolean_settings = ['auto_process_on_save', 'case_sensitive', 'word_boundaries', 'include_existing_tags'];
        foreach ($boolean_settings as $setting) {
            $output[$setting] = !empty($input[$setting]);
        }
        
        // Sanitize existing tags strategy
        $allowed_strategies = ['merge', 'ignore', 'priority'];
        $output['existing_tags_strategy'] = in_array($input['existing_tags_strategy'] ?? '', $allowed_strategies) ? 
                                          $input['existing_tags_strategy'] : 'merge';
        
        // Sanitize numeric settings with proper bounds
        $output['batch_size'] = max(10, min(500, intval($input['batch_size'] ?? 50)));
        $output['max_execution_time'] = max(10, min(300, intval($input['max_execution_time'] ?? 30)));
        
        return $output;
    }
    
    /**
     * Add plugin action links
     */
    public function add_plugin_action_links($links) {
        // Security check
        if (!current_user_can('manage_options')) {
            return $links;
        }
        
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=wp-auto-tag-pro')) . '">' . esc_html__('Settings', 'wp-auto-tag-pro') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * AJAX: Save rule - Unified rules management with dual taxonomy support
     */
    public function ajax_save_rule() {
        // Security checks
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->config) {
            wp_send_json_error(__('Configuration not available', 'wp-auto-tag-pro'));
        }
        
        $rule_data = $_POST['rule'] ?? [];
        if (!is_array($rule_data)) {
            wp_send_json_error(__('Invalid rule data', 'wp-auto-tag-pro'));
        }
        
        // Sanitize rule data
        $rule_id = sanitize_text_field($rule_data['id'] ?? '');
        $rule_name = sanitize_text_field($rule_data['name'] ?? '');
        $keywords = array_map('sanitize_text_field', $rule_data['keywords'] ?? []);
        $tags = array_map('sanitize_text_field', $rule_data['tags'] ?? []);
        $categories = array_map('sanitize_text_field', $rule_data['categories'] ?? []);
        
        if (empty($rule_name) || empty($keywords)) {
            wp_send_json_error(__('Rule name and keywords are required', 'wp-auto-tag-pro'));
        }
        
        // Build rule structure
        $validated_rule = [
            'name' => $rule_name,
            'keywords' => array_filter($keywords),
            'tags' => array_filter($tags),
            'categories' => array_filter($categories),
            'enabled' => !empty($rule_data['enabled']),
            'case_sensitive' => !empty($rule_data['case_sensitive'])
        ];
        
        try {
            // Check for conflicts before saving
            $conflicts = wp_autotag_pro()->config->detect_rule_conflicts($validated_rule);
            
            // Save the rule
            $result = wp_autotag_pro()->config->add_rule($rule_id, $validated_rule);
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success([
                    'message' => __('Rule saved successfully', 'wp-auto-tag-pro'),
                    'rule_id' => $rule_id,
                    'conflicts' => $conflicts
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error(__('Error saving rule: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Delete rule
     */
    public function ajax_delete_rule() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->config) {
            wp_send_json_error(__('Configuration not available', 'wp-auto-tag-pro'));
        }
        
        $rule_id = sanitize_text_field($_POST['rule_id'] ?? '');
        if (empty($rule_id)) {
            wp_send_json_error(__('Rule ID is required', 'wp-auto-tag-pro'));
        }
        
        try {
            $result = wp_autotag_pro()->config->delete_rule($rule_id);
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            } else {
                wp_send_json_success(['message' => __('Rule deleted successfully', 'wp-auto-tag-pro')]);
            }
        } catch (Exception $e) {
            wp_send_json_error(__('Error deleting rule', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * AJAX: Get all rules
     */
    public function ajax_get_rules() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->config) {
            wp_send_json_error(__('Configuration not available', 'wp-auto-tag-pro'));
        }
        
        try {
            $rules = wp_autotag_pro()->config->get_rules();
            wp_send_json_success(['rules' => $rules]);
        } catch (Exception $e) {
            wp_send_json_error(__('Error retrieving rules', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * AJAX: Check for conflicts in existing terms
     */
    public function ajax_check_conflicts() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        try {
            // Get all existing tags and categories
            $tags = get_terms(['taxonomy' => 'post_tag', 'hide_empty' => false]);
            $categories = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
            
            $conflicts = [];
            $tag_names = wp_list_pluck($tags, 'name');
            $category_names = wp_list_pluck($categories, 'name');
            
            // Find overlaps between tags and categories
            $overlapping = array_intersect($tag_names, $category_names);
            
            foreach ($overlapping as $term_name) {
                $conflicts[] = [
                    'term' => $term_name,
                    'type' => 'tag_category_overlap',
                    'message' => sprintf(__('"%s" exists as both tag and category', 'wp-auto-tag-pro'), $term_name)
                ];
            }
            
            wp_send_json_success([
                'conflicts' => $conflicts,
                'total_conflicts' => count($conflicts),
                'total_tags' => count($tag_names),
                'total_categories' => count($category_names)
            ]);
        } catch (Exception $e) {
            wp_send_json_error(__('Error checking conflicts', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * AJAX: Save quick setup configuration
     */
    public function ajax_save_quick_setup() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        $setup_data = $_POST['setup'] ?? [];
        if (!is_array($setup_data)) {
            wp_send_json_error(__('Invalid setup data', 'wp-auto-tag-pro'));
        }
        
        try {
            $settings = wp_autotag_pro()->get_settings();
            
            // Update taxonomy settings
            if (isset($setup_data['enable_tags'])) {
                $enabled_taxonomies = $settings['enabled_taxonomies'] ?? [];
                if ($setup_data['enable_tags']) {
                    $enabled_taxonomies[] = 'post_tag';
                } else {
                    $enabled_taxonomies = array_diff($enabled_taxonomies, ['post_tag']);
                }
                $settings['enabled_taxonomies'] = array_unique($enabled_taxonomies);
            }
            
            if (isset($setup_data['enable_categories'])) {
                $enabled_taxonomies = $settings['enabled_taxonomies'] ?? [];
                if ($setup_data['enable_categories']) {
                    $enabled_taxonomies[] = 'category';
                } else {
                    $enabled_taxonomies = array_diff($enabled_taxonomies, ['category']);
                }
                $settings['enabled_taxonomies'] = array_unique($enabled_taxonomies);
            }
            
            // Save settings
            update_option('wp_autotag_pro_settings', $settings);
            
            wp_send_json_success(['message' => __('Quick setup saved successfully', 'wp-auto-tag-pro')]);
        } catch (Exception $e) {
            wp_send_json_error(__('Error saving quick setup', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * AJAX: Save all Dashboard settings (unified handler)
     */
    public function ajax_save_dashboard_settings() {
        if (!check_ajax_referer('autotag_dashboard_settings', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        $form_data = $_POST['form_data'] ?? [];
        if (!is_array($form_data)) {
            wp_send_json_error(__('Invalid form data', 'wp-auto-tag-pro'));
        }
        
        try {
            // Debug logging
            error_log('AutoTag Pro - Dashboard settings save started');
            error_log('AutoTag Pro - Received form data: ' . print_r($form_data, true));
            
            // Use direct WordPress option access instead of plugin function
            $settings = get_option('wp_autotag_pro_settings', [
                'enabled_post_types' => ['post'],
                'enabled_taxonomies' => ['post_tag'],
                'content_areas' => ['title', 'content'],
                'case_sensitive' => false
            ]);
            
            error_log('AutoTag Pro - Current settings: ' . print_r($settings, true));
            
            // Parse form data into organized settings
            $parsed_data = [];
            if (is_array($form_data)) {
                foreach ($form_data as $item) {
                    if (is_array($item) && isset($item['name']) && isset($item['value'])) {
                        $name = sanitize_text_field($item['name']);
                        $value = $item['value'];
                        
                        // Skip nonce fields
                        if (strpos($name, 'nonce') !== false) {
                            continue;
                        }
                        
                        // Handle arrays (checkboxes with same name)
                        if (isset($parsed_data[$name])) {
                            if (!is_array($parsed_data[$name])) {
                                $parsed_data[$name] = [$parsed_data[$name]];
                            }
                            $parsed_data[$name][] = sanitize_text_field($value);
                        } else {
                            $parsed_data[$name] = sanitize_text_field($value);
                        }
                    }
                }
            }
            
            error_log('AutoTag Pro - Parsed form data: ' . print_r($parsed_data, true));
            
            // Update Taxonomy Configuration
            error_log('AutoTag Pro - Processing taxonomy configuration');
            $enabled_taxonomies = [];
            if (isset($parsed_data['enable_tags']) && $parsed_data['enable_tags'] === '1') {
                $enabled_taxonomies[] = 'post_tag';
            }
            if (isset($parsed_data['enable_categories']) && $parsed_data['enable_categories'] === '1') {
                $enabled_taxonomies[] = 'category';
            }
            $settings['enabled_taxonomies'] = $enabled_taxonomies;
            error_log('AutoTag Pro - Enabled taxonomies: ' . print_r($enabled_taxonomies, true));
            
            // Update Enabled Post Types
            error_log('AutoTag Pro - Processing post types');
            if (isset($parsed_data['enabled_post_types'])) {
                if (is_array($parsed_data['enabled_post_types'])) {
                    $settings['enabled_post_types'] = array_map('sanitize_text_field', $parsed_data['enabled_post_types']);
                } else {
                    $settings['enabled_post_types'] = [sanitize_text_field($parsed_data['enabled_post_types'])];
                }
            } else {
                $settings['enabled_post_types'] = [];
            }
            error_log('AutoTag Pro - Enabled post types: ' . print_r($settings['enabled_post_types'], true));
            
            // Update Content Processing
            error_log('AutoTag Pro - Processing content areas');
            if (isset($parsed_data['content_areas'])) {
                if (is_array($parsed_data['content_areas'])) {
                    $settings['content_areas'] = array_map('sanitize_text_field', $parsed_data['content_areas']);
                } else {
                    $settings['content_areas'] = [sanitize_text_field($parsed_data['content_areas'])];
                }
            } else {
                $settings['content_areas'] = [];
            }
            
            $settings['case_sensitive'] = (isset($parsed_data['case_sensitive']) && $parsed_data['case_sensitive'] === '1');
            
            error_log('AutoTag Pro - Final settings before save: ' . print_r($settings, true));
            
            // Save all settings using direct WordPress option
            error_log('AutoTag Pro - About to save settings: ' . print_r($settings, true));
            $result = update_option('wp_autotag_pro_settings', $settings);
            error_log('AutoTag Pro - Settings save result: ' . ($result ? 'success' : 'failed'));
            
            // Clear any caches that might be interfering
            if (function_exists('wp_autotag_pro') && wp_autotag_pro() && method_exists(wp_autotag_pro(), 'clear_settings_cache')) {
                wp_autotag_pro()->clear_settings_cache();
            }
            
            // Verify the settings were actually saved
            $saved_settings = get_option('wp_autotag_pro_settings');
            error_log('AutoTag Pro - Verified saved settings: ' . print_r($saved_settings, true));
            
            wp_send_json_success([
                'message' => __('All settings saved successfully', 'wp-auto-tag-pro'),
                'enabled_taxonomies' => $enabled_taxonomies,
                'redirect' => admin_url('options-general.php?page=wp-auto-tag-pro&tab=dashboard&saved=1')
            ]);
            
        } catch (Exception $e) {
            // Log detailed error for debugging
            error_log('AutoTag Pro - Dashboard settings save error: ' . $e->getMessage());
            error_log('AutoTag Pro - Stack trace: ' . $e->getTraceAsString());
            error_log('AutoTag Pro - Form data: ' . print_r($form_data, true));
            
            wp_send_json_error(__('Error saving settings: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Apply tags to post
     */
    public function ajax_apply_tags() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $tags = $_POST['tags'] ?? [];
        
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(__('Invalid post or insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        try {
            // Apply only tags using TaxonomyApplier
            if (wp_autotag_pro()->processor && wp_autotag_pro()->processor->taxonomy_applier) {
                $result = wp_autotag_pro()->processor->taxonomy_applier->apply_tags($post_id, $tags);
                
                if (is_wp_error($result)) {
                    wp_send_json_error($result->get_error_message());
                } else {
                    wp_send_json_success([
                        'message' => __('Tags applied successfully', 'wp-auto-tag-pro'),
                        'applied_tags' => $tags
                    ]);
                }
            } else {
                wp_send_json_error(__('Taxonomy applier not available', 'wp-auto-tag-pro'));
            }
        } catch (Exception $e) {
            wp_send_json_error(__('Error applying tags', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * AJAX: Apply categories to post
     */
    public function ajax_apply_categories() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $categories = $_POST['categories'] ?? [];
        
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(__('Invalid post or insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        try {
            // Apply only categories using TaxonomyApplier
            if (wp_autotag_pro()->processor && wp_autotag_pro()->processor->taxonomy_applier) {
                $result = wp_autotag_pro()->processor->taxonomy_applier->apply_categories($post_id, $categories);
                
                if (is_wp_error($result)) {
                    wp_send_json_error($result->get_error_message());
                } else {
                    wp_send_json_success([
                        'message' => __('Categories applied successfully', 'wp-auto-tag-pro'),
                        'applied_categories' => $categories
                    ]);
                }
            } else {
                wp_send_json_error(__('Taxonomy applier not available', 'wp-auto-tag-pro'));
            }
        } catch (Exception $e) {
            wp_send_json_error(__('Error applying categories', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * AJAX: Import configuration
     */
    public function ajax_import_config() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->config) {
            wp_send_json_error(__('Configuration not available', 'wp-auto-tag-pro'));
        }
        
        $import_data = json_decode(stripslashes($_POST['data'] ?? ''), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(__('Invalid JSON data', 'wp-auto-tag-pro'));
        }
        
        $result = wp_autotag_pro()->config->import_configuration($import_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(['message' => __('Configuration imported successfully', 'wp-auto-tag-pro')]);
        }
    }
    
    /**
     * AJAX: Export configuration
     */
    public function ajax_export_config() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->config) {
            wp_send_json_error(__('Configuration not available', 'wp-auto-tag-pro'));
        }
        
        $export_data = wp_autotag_pro()->config->export_configuration();
        
        wp_send_json_success([
            'data' => $export_data,
            'filename' => 'autotag-config-' . date('Y-m-d-H-i-s') . '.json'
        ]);
    }
    
    /**
     * AJAX: Generate post taxonomies with dual taxonomy controls
     */
    public function ajax_generate_post() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->processor) {
            wp_send_json_error(__('Processor not available', 'wp-auto-tag-pro'));
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $taxonomy_selection = sanitize_text_field($_POST['taxonomy_selection'] ?? 'both');
        
        if (!$post_id) {
            wp_send_json_error(__('Invalid post ID', 'wp-auto-tag-pro'));
        }
        
        // Get current post taxonomies
        $current_tags = get_the_tags($post_id);
        $current_categories = get_the_category($post_id);
        
        // Process based on taxonomy selection
        $result = wp_autotag_pro()->processor->preview_post_tags($post_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Filter result based on taxonomy selection
        $filtered_result = [
            'generated_tags' => [],
            'generated_categories' => [],
            'current_tags' => $current_tags ? wp_list_pluck($current_tags, 'name') : [],
            'current_categories' => $current_categories ? wp_list_pluck($current_categories, 'name') : [],
            'matched_rules' => $result['matched_rules'] ?? [],
            'warnings' => []
        ];
        
        switch ($taxonomy_selection) {
            case 'tags':
                $filtered_result['generated_tags'] = $result['generated_tags'] ?? [];
                break;
            case 'categories':
                $filtered_result['generated_categories'] = $result['generated_categories'] ?? [];
                break;
            case 'both':
            default:
                $filtered_result['generated_tags'] = $result['generated_tags'] ?? [];
                $filtered_result['generated_categories'] = $result['generated_categories'] ?? [];
                break;
        }
        
        // Check for tag limits and create warnings
        $max_tags = get_option('wp_autotag_pro_settings', [])['max_tags_per_post'] ?? 15;
        if ($max_tags > 0 && count($filtered_result['generated_tags']) > $max_tags) {
            $truncated_tags = array_slice($filtered_result['generated_tags'], $max_tags);
            $filtered_result['generated_tags'] = array_slice($filtered_result['generated_tags'], 0, $max_tags);
            $filtered_result['warnings'][] = [
                'type' => 'tag_limit',
                'message' => sprintf(
                    __('WARNING: %d tags truncated (%s) due to max tags limit (%d).', 'wp-auto-tag-pro'),
                    count($truncated_tags),
                    implode(', ', $truncated_tags),
                    $max_tags
                )
            ];
        }
        
        wp_send_json_success($filtered_result);
    }
    
    /**
     * AJAX: Preview post tags (legacy support)
     */
    public function ajax_preview_post() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->processor) {
            wp_send_json_error(__('Processor not available', 'wp-auto-tag-pro'));
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (!$post_id) {
            wp_send_json_error(__('Invalid post ID', 'wp-auto-tag-pro'));
        }
        
        $result = wp_autotag_pro()->processor->preview_post_tags($post_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * AJAX: Enable year tags retroactively
     */
    public function ajax_enable_year_tags_retroactive() {
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'));
        }
        
        if (!function_exists('wp_autotag_pro') || !wp_autotag_pro()->config || !wp_autotag_pro()->bulk) {
            wp_send_json_error(__('Required components not available', 'wp-auto-tag-pro'));
        }
        
        // Enable year tags in configuration
        $config = wp_autotag_pro()->config->get_configuration();
        $config['y'] = true;
        
        $result = wp_autotag_pro()->config->set_configuration($config);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Schedule bulk processing for all posts
        $queue_id = wp_autotag_pro()->bulk->bulk_process_all_posts(['immediate' => false]);
        
        if (is_wp_error($queue_id)) {
            wp_send_json_error($queue_id->get_error_message());
        } else {
            wp_send_json_success([
                'message' => __('Year tags enabled and bulk processing started', 'wp-auto-tag-pro'),
                'queue_id' => $queue_id
            ]);
        }
    }
    
    // PLACEHOLDER METHODS FOR HTML RENDERING - These will be in the HTML part file
    public function render_settings_page() {
        // This method will be implemented in the HTML part
        require_once WP_AUTOTAG_PRO_PLUGIN_DIR . 'includes/class-autotag-admin-html.php';
        $html_renderer = new AutoTag_Admin_HTML($this);
        $html_renderer->render_settings_page();
    }
    
    public function render_post_meta_box($post) {
        require_once WP_AUTOTAG_PRO_PLUGIN_DIR . 'includes/class-autotag-admin-html.php';
        $html_renderer = new AutoTag_Admin_HTML($this);
        $html_renderer->render_post_meta_box($post);
    }
    
    public function render_tools_page() {
        require_once WP_AUTOTAG_PRO_PLUGIN_DIR . 'includes/class-autotag-admin-html.php';
        $html_renderer = new AutoTag_Admin_HTML($this);
        $html_renderer->render_tools_page();
    }
    
}