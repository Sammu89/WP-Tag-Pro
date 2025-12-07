<?php
/**
 * Configuration AJAX Handler Class
 * Handles configuration-related AJAX requests - extracted from AutoTag_Ajax
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Config_Ajax {
    
    public function __construct() {
        // Configuration validation and testing AJAX hooks
        add_action('wp_ajax_autotag_validate_config', [$this, 'validate_configuration']);
        add_action('wp_ajax_autotag_get_config_stats', [$this, 'get_config_stats']);
        add_action('wp_ajax_autotag_test_phrase_matching', [$this, 'test_phrase_matching']);
        
        // Family management AJAX hooks
        add_action('wp_ajax_autotag_update_family', [$this, 'update_family']);
        add_action('wp_ajax_autotag_get_family_conflicts', [$this, 'get_family_conflicts']);
        add_action('wp_ajax_autotag_bulk_add_families', [$this, 'bulk_add_families']);
        
        // Quick Setup and Rules Management AJAX hooks
        add_action('wp_ajax_autotag_add_simple_keywords', [$this, 'add_simple_keywords']);
        add_action('wp_ajax_autotag_save_quick_setup', [$this, 'save_quick_setup']);
        add_action('wp_ajax_autotag_get_rule', [$this, 'get_rule']);
        add_action('wp_ajax_autotag_save_rule', [$this, 'save_rule']);
        add_action('wp_ajax_autotag_delete_rule', [$this, 'delete_rule']);
        add_action('wp_ajax_autotag_get_parent_family', [$this, 'get_parent_family']);
    }
    
    /**
     * Validate configuration data
     */
    public function validate_configuration() {
        $this->verify_ajax_security('manage_options');
        
        $config_data = $_POST['config'] ?? [];
        
        if (!is_array($config_data)) {
            wp_send_json_error(__('Invalid configuration data format', 'wp-auto-tag-pro'));
        }
        
        try {
            $validation_results = [];
            
            // Validate based on mode
            $mode = sanitize_text_field($config_data['mode'] ?? 'classic');
            
            if ($mode === 'families') {
                $families = $config_data['families'] ?? [];
                if (!is_array($families)) {
                    wp_send_json_error(__('Invalid families data format', 'wp-auto-tag-pro'));
                }
                $validation_results = $this->validate_families_config($families);
            } elseif ($mode === 'classic') {
                $keywords = $config_data['keywords'] ?? [];
                if (!is_array($keywords)) {
                    wp_send_json_error(__('Invalid keywords data format', 'wp-auto-tag-pro'));
                }
                $validation_results = $this->validate_keywords_config($keywords);
            } else {
                wp_send_json_error(__('Invalid configuration mode', 'wp-auto-tag-pro'));
            }
            
            wp_send_json_success([
                'message' => __('Configuration validation completed', 'wp-auto-tag-pro'),
                'validation_results' => $validation_results,
                'mode' => $mode
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Validation error: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get configuration statistics
     */
    public function get_config_stats() {
        $this->verify_ajax_security('manage_options');
        
        try {
            $stats = wp_autotag_pro()->config->get_config_stats();
            
            // Add additional statistics with safety checks
            $additional_stats = [];
            
            if (wp_autotag_pro()->processor) {
                $processing_posts = wp_autotag_pro()->processor->get_posts_needing_processing(['posts_per_page' => 100]);
                $additional_stats['processing_needed'] = count($processing_posts);
            }
            
            $plugin_stats = wp_autotag_pro()->get_stats();
            $additional_stats['last_config_change'] = $plugin_stats['last_update'] ?? 0;
            
            wp_send_json_success([
                'config_stats' => $stats,
                'additional_stats' => $additional_stats
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error retrieving configuration stats', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Test phrase matching functionality
     */
    public function test_phrase_matching() {
        $this->verify_ajax_security('manage_options');
        
        $test_content = sanitize_textarea_field($_POST['content'] ?? '');
        $test_phrases = $_POST['phrases'] ?? [];
        
        // Validate input
        if (empty($test_content) || strlen($test_content) > 10000) {
            wp_send_json_error(__('Content must be between 1 and 10,000 characters', 'wp-auto-tag-pro'));
        }
        
        if (!is_array($test_phrases) || count($test_phrases) > 50) {
            wp_send_json_error(__('Maximum 50 phrases allowed for testing', 'wp-auto-tag-pro'));
        }
        
        try {
            $normalized_content = wp_autotag_pro()->config->normalize_text($test_content);
            $matches = [];
            
            foreach ($test_phrases as $phrase) {
                $phrase = sanitize_text_field($phrase);
                if (empty($phrase)) continue;
                
                $normalized_phrase = wp_autotag_pro()->config->normalize_text($phrase);
                $match_found = strpos($normalized_content, $normalized_phrase) !== false;
                
                $matches[] = [
                    'phrase' => $phrase,
                    'normalized' => $normalized_phrase,
                    'matched' => $match_found,
                    'positions' => $match_found ? $this->find_phrase_positions($normalized_content, $normalized_phrase) : []
                ];
            }
            
            wp_send_json_success([
                'content_length' => strlen($test_content),
                'normalized_length' => strlen($normalized_content),
                'matches' => $matches,
                'total_matches' => count(array_filter($matches, function($m) { return $m['matched']; }))
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error testing phrase matching', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Update family configuration
     */
    public function update_family() {
        $this->verify_ajax_security('manage_options');
        
        $family_name = sanitize_text_field($_POST['family_name'] ?? '');
        $keywords = $_POST['keywords'] ?? [];
        $action_type = sanitize_text_field($_POST['action'] ?? 'update');
        
        if (empty($family_name)) {
            wp_send_json_error(__('Family name is required', 'wp-auto-tag-pro'));
        }
        
        if (!is_array($keywords)) {
            wp_send_json_error(__('Keywords must be an array', 'wp-auto-tag-pro'));
        }
        
        try {
            $current_config = wp_autotag_pro()->config->get_config();
            $families = $current_config['f'] ?? [];
            
            if ($action_type === 'delete') {
                if (isset($families[$family_name])) {
                    unset($families[$family_name]);
                    $message = sprintf(__('Family "%s" deleted successfully', 'wp-auto-tag-pro'), $family_name);
                } else {
                    wp_send_json_error(__('Family not found', 'wp-auto-tag-pro'));
                }
            } else {
                // Sanitize keywords
                $sanitized_keywords = array_map('sanitize_text_field', $keywords);
                $sanitized_keywords = array_filter($sanitized_keywords);
                
                $families[$family_name] = $sanitized_keywords;
                $message = sprintf(__('Family "%s" updated successfully', 'wp-auto-tag-pro'), $family_name);
            }
            
            // Update configuration
            $current_config['f'] = $families;
            $save_result = wp_autotag_pro()->config->save_config($current_config);
            
            if (!$save_result) {
                wp_send_json_error(__('Failed to save configuration', 'wp-auto-tag-pro'));
            }
            
            wp_send_json_success([
                'message' => $message,
                'family_name' => $family_name,
                'keywords' => $sanitized_keywords ?? [],
                'total_families' => count($families)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error updating family: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get family conflicts (overlapping keywords)
     */
    public function get_family_conflicts() {
        $this->verify_ajax_security('manage_options');
        
        try {
            $config = wp_autotag_pro()->config->get_config();
            $families = $config['f'] ?? [];
            
            $conflicts = [];
            $all_keywords = [];
            
            // Build keyword to family mapping
            foreach ($families as $family_name => $keywords) {
                if (!is_array($keywords)) continue;
                
                foreach ($keywords as $keyword) {
                    $keyword_lower = strtolower(trim($keyword));
                    if (empty($keyword_lower)) continue;
                    
                    if (!isset($all_keywords[$keyword_lower])) {
                        $all_keywords[$keyword_lower] = [];
                    }
                    $all_keywords[$keyword_lower][] = $family_name;
                }
            }
            
            // Find conflicts (keywords in multiple families)
            foreach ($all_keywords as $keyword => $keyword_families) {
                if (count($keyword_families) > 1) {
                    $conflicts[] = [
                        'keyword' => $keyword,
                        'families' => $keyword_families,
                        'conflict_count' => count($keyword_families)
                    ];
                }
            }
            
            wp_send_json_success([
                'conflicts' => $conflicts,
                'total_conflicts' => count($conflicts),
                'total_families' => count($families),
                'total_keywords' => count($all_keywords)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error checking family conflicts', 'wp-auto-tag-pro'));
        }
    }
    
    /**
     * Bulk add multiple families
     */
    public function bulk_add_families() {
        $this->verify_ajax_security('manage_options');
        
        $families_data = $_POST['families'] ?? [];
        
        if (!is_array($families_data) || empty($families_data)) {
            wp_send_json_error(__('No families data provided', 'wp-auto-tag-pro'));
        }
        
        try {
            $current_config = wp_autotag_pro()->config->get_config();
            $families = $current_config['f'] ?? [];
            
            $added_count = 0;
            $updated_count = 0;
            $errors = [];
            
            foreach ($families_data as $family_data) {
                $family_name = sanitize_text_field($family_data['name'] ?? '');
                $keywords = $family_data['keywords'] ?? [];
                
                if (empty($family_name)) {
                    $errors[] = 'Empty family name skipped';
                    continue;
                }
                
                if (!is_array($keywords)) {
                    $errors[] = "Invalid keywords for family: {$family_name}";
                    continue;
                }
                
                $sanitized_keywords = array_map('sanitize_text_field', $keywords);
                $sanitized_keywords = array_filter($sanitized_keywords);
                
                if (empty($sanitized_keywords)) {
                    $errors[] = "No valid keywords for family: {$family_name}";
                    continue;
                }
                
                $is_new = !isset($families[$family_name]);
                $families[$family_name] = $sanitized_keywords;
                
                if ($is_new) {
                    $added_count++;
                } else {
                    $updated_count++;
                }
            }
            
            // Save updated configuration
            $current_config['f'] = $families;
            $save_result = wp_autotag_pro()->config->save_config($current_config);
            
            if (!$save_result) {
                wp_send_json_error(__('Failed to save configuration', 'wp-auto-tag-pro'));
            }
            
            wp_send_json_success([
                'message' => sprintf(__('%d families added, %d updated', 'wp-auto-tag-pro'), $added_count, $updated_count),
                'added_count' => $added_count,
                'updated_count' => $updated_count,
                'errors' => $errors,
                'total_families' => count($families)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error bulk adding families: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Security verification for AJAX requests
     */
    private function verify_ajax_security($required_capability = 'manage_options') {
        // Check if it's an AJAX request
        if (!wp_doing_ajax()) {
            wp_send_json_error(__('Invalid request type', 'wp-auto-tag-pro'), 400);
        }
        
        // Verify nonce
        if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
            wp_send_json_error(__('Security verification failed', 'wp-auto-tag-pro'), 403);
        }
        
        // Check user capabilities
        if (!current_user_can($required_capability)) {
            wp_send_json_error(__('Insufficient permissions', 'wp-auto-tag-pro'), 403);
        }
    }
    
    /**
     * Find positions of phrase matches in content
     */
    private function find_phrase_positions($content, $phrase) {
        $positions = [];
        $offset = 0;
        
        while (($pos = strpos($content, $phrase, $offset)) !== false) {
            $positions[] = $pos;
            $offset = $pos + 1;
        }
        
        return $positions;
    }
    
    /**
     * Validate families configuration
     */
    private function validate_families_config($families) {
        $results = [];
        
        foreach ($families as $family_name => $keywords) {
            $family_results = [
                'family' => $family_name,
                'valid' => true,
                'errors' => [],
                'keyword_count' => 0
            ];
            
            if (!is_array($keywords)) {
                $family_results['valid'] = false;
                $family_results['errors'][] = 'Keywords must be an array';
            } else {
                $family_results['keyword_count'] = count($keywords);
                
                if (empty($keywords)) {
                    $family_results['valid'] = false;
                    $family_results['errors'][] = 'Family must have at least one keyword';
                }
                
                foreach ($keywords as $keyword) {
                    if (empty(trim($keyword))) {
                        $family_results['errors'][] = 'Empty keyword found';
                        $family_results['valid'] = false;
                    }
                }
            }
            
            $results[] = $family_results;
        }
        
        return $results;
    }
    
    /**
     * Validate keywords configuration (classic mode)
     */
    private function validate_keywords_config($keywords) {
        $results = [];
        
        foreach ($keywords as $keyword => $tags) {
            $keyword_results = [
                'keyword' => $keyword,
                'valid' => true,
                'errors' => [],
                'tags_count' => 0
            ];
            
            if (!is_array($tags)) {
                $keyword_results['valid'] = false;
                $keyword_results['errors'][] = 'Tags must be an array';
            } else {
                $keyword_results['tags_count'] = count($tags);
                
                if (empty($tags)) {
                    $keyword_results['valid'] = false;
                    $keyword_results['errors'][] = 'Keyword must have at least one tag';
                }
            }
            
            $results[] = $keyword_results;
        }
        
        return $results;
    }
    
    /**
     * Add simple keywords via Quick Setup
     */
    public function add_simple_keywords() {
        $this->verify_ajax_security('manage_options');
        
        $keywords = $_POST['keywords'] ?? [];
        $create_tags = (bool) ($_POST['create_tags'] ?? false);
        $create_categories = (bool) ($_POST['create_categories'] ?? false);
        
        if (empty($keywords) || (!is_array($keywords))) {
            wp_send_json_error(__('Invalid keywords data', 'wp-auto-tag-pro'));
        }
        
        if (!$create_tags && !$create_categories) {
            wp_send_json_error(__('Please select at least one taxonomy to create', 'wp-auto-tag-pro'));
        }
        
        try {
            $rules = wp_autotag_pro()->config->get_rules();
            $added_count = 0;
            $taxonomies = [];
            
            foreach ($keywords as $keyword) {
                $keyword = sanitize_text_field(trim($keyword));
                if (empty($keyword)) continue;
                
                $rule_id = 'simple_' . sanitize_title($keyword) . '_' . time() . '_' . $added_count;
                $rule_data = [
                    'name' => null, // No name for simple families
                    'keywords' => [$keyword],
                    'type' => 'simple',
                    'enabled' => true,
                    'case_sensitive' => false,
                    'inheritance_enabled' => false,
                    'parent_id' => null,
                    'children' => [],
                    'conflicts' => []
                ];
                
                if ($create_tags) {
                    $rule_data['tags'] = [$keyword];
                    $taxonomies[] = 'tags';
                }
                
                if ($create_categories) {
                    $rule_data['categories'] = [ucfirst($keyword)]; // Capitalize for categories
                    $taxonomies[] = 'categories';
                }
                
                $rules[$rule_id] = $rule_data;
                $added_count++;
            }
            
            // Save updated rules
            wp_autotag_pro()->config->set_rules($rules);
            
            // Update settings to enable selected taxonomies
            $settings = wp_autotag_pro()->get_settings();
            $enabled_taxonomies = $settings['enabled_taxonomies'] ?? [];
            
            if ($create_tags && !in_array('post_tag', $enabled_taxonomies)) {
                $enabled_taxonomies[] = 'post_tag';
            }
            
            if ($create_categories && !in_array('category', $enabled_taxonomies)) {
                $enabled_taxonomies[] = 'category';
            }
            
            $settings['enabled_taxonomies'] = $enabled_taxonomies;
            wp_autotag_pro()->update_settings($settings);
            
            wp_send_json_success([
                'count' => $added_count,
                'taxonomies' => array_unique($taxonomies),
                'message' => sprintf(__('Successfully added %d keywords', 'wp-auto-tag-pro'), $added_count)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error adding keywords: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Save Quick Setup configuration
     */
    public function save_quick_setup() {
        $this->verify_ajax_security('manage_options');
        
        $tags_enabled = (bool) ($_POST['tags_enabled'] ?? false);
        $categories_enabled = (bool) ($_POST['categories_enabled'] ?? false);
        
        try {
            $settings = wp_autotag_pro()->get_settings();
            $enabled_taxonomies = [];
            
            if ($tags_enabled) {
                $enabled_taxonomies[] = 'post_tag';
            }
            
            if ($categories_enabled) {
                $enabled_taxonomies[] = 'category';
            }
            
            $settings['enabled_taxonomies'] = $enabled_taxonomies;
            wp_autotag_pro()->update_settings($settings);
            
            wp_send_json_success([
                'message' => __('Configuration saved successfully', 'wp-auto-tag-pro'),
                'enabled_taxonomies' => $enabled_taxonomies
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error saving configuration: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get rule data for editing
     */
    public function get_rule() {
        $this->verify_ajax_security('manage_options');
        
        $rule_id = sanitize_text_field($_POST['rule_id'] ?? '');
        
        if (empty($rule_id)) {
            wp_send_json_error(__('Rule ID is required', 'wp-auto-tag-pro'));
        }
        
        try {
            $rules = wp_autotag_pro()->config->get_rules();
            
            if (!isset($rules[$rule_id])) {
                wp_send_json_error(__('Rule not found', 'wp-auto-tag-pro'));
            }
            
            $rule = $rules[$rule_id];
            $rule['id'] = $rule_id;
            
            wp_send_json_success($rule);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error retrieving rule: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Save rule (create or update)
     */
    public function save_rule() {
        $this->verify_ajax_security('manage_options');
        
        $rule_id = sanitize_text_field($_POST['rule_id'] ?? '');
        $rule_name = sanitize_text_field($_POST['rule_name'] ?? '');
        $rule_keywords = sanitize_textarea_field($_POST['rule_keywords'] ?? '');
        $rule_apply_tags = (bool) ($_POST['rule_apply_tags'] ?? false);
        $rule_tags = sanitize_text_field($_POST['rule_tags'] ?? '');
        $rule_apply_categories = (bool) ($_POST['rule_apply_categories'] ?? false);
        $rule_categories = sanitize_text_field($_POST['rule_categories'] ?? '');
        $rule_parent = sanitize_text_field($_POST['rule_parent'] ?? '');
        $rule_inheritance = (bool) ($_POST['rule_inheritance'] ?? false);
        $rule_enabled = (bool) ($_POST['rule_enabled'] ?? true);
        $rule_case_sensitive = (bool) ($_POST['rule_case_sensitive'] ?? false);
        
        if (empty($rule_name)) {
            wp_send_json_error(__('Family name is required', 'wp-auto-tag-pro'));
        }
        
        if (empty($rule_keywords)) {
            wp_send_json_error(__('Keywords are required', 'wp-auto-tag-pro'));
        }
        
        if (!$rule_apply_tags && !$rule_apply_categories) {
            wp_send_json_error(__('At least one of Tags or Categories must be selected', 'wp-auto-tag-pro'));
        }
        
        try {
            $rules = wp_autotag_pro()->config->get_rules();
            
            // Parse keywords
            $keywords = array_map('trim', explode(',', $rule_keywords));
            $keywords = array_filter($keywords);
            
            // Parse tags and categories
            $tags = $rule_apply_tags ? array_map('trim', explode(',', $rule_tags)) : [];
            $categories = $rule_apply_categories ? array_map('trim', explode(',', $rule_categories)) : [];
            
            // If tags/categories are empty but enabled, use keywords
            if ($rule_apply_tags && empty($tags)) {
                $tags = $keywords;
            }
            
            if ($rule_apply_categories && empty($categories)) {
                $categories = array_map('ucfirst', $keywords); // Capitalize for categories
            }
            
            // Detect conflicts
            $conflicts = array_intersect(
                array_map('strtolower', $tags),
                array_map('strtolower', $categories)
            );
            
            // Create or update rule
            if (empty($rule_id)) {
                $rule_id = 'enhanced_' . sanitize_title($rule_name) . '_' . time();
            }
            
            $rule_data = [
                'name' => $rule_name,
                'keywords' => $keywords,
                'type' => 'enhanced',
                'enabled' => $rule_enabled,
                'case_sensitive' => $rule_case_sensitive,
                'inheritance_enabled' => $rule_inheritance,
                'parent_id' => $rule_parent ?: null,
                'children' => [],
                'tags' => $tags,
                'categories' => $categories,
                'conflicts' => array_values($conflicts)
            ];
            
            $rules[$rule_id] = $rule_data;
            
            // Update parent family if specified
            if ($rule_parent && isset($rules[$rule_parent])) {
                if (!in_array($rule_id, $rules[$rule_parent]['children'])) {
                    $rules[$rule_parent]['children'][] = $rule_id;
                }
            }
            
            // Save updated rules
            wp_autotag_pro()->config->set_rules($rules);
            
            wp_send_json_success([
                'message' => __('Family saved successfully', 'wp-auto-tag-pro'),
                'rule_id' => $rule_id,
                'conflicts' => array_values($conflicts)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error saving family: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Delete rule
     */
    public function delete_rule() {
        $this->verify_ajax_security('manage_options');
        
        $rule_id = sanitize_text_field($_POST['rule_id'] ?? '');
        
        if (empty($rule_id)) {
            wp_send_json_error(__('Rule ID is required', 'wp-auto-tag-pro'));
        }
        
        try {
            $rules = wp_autotag_pro()->config->get_rules();
            
            if (!isset($rules[$rule_id])) {
                wp_send_json_error(__('Rule not found', 'wp-auto-tag-pro'));
            }
            
            // Remove from parent family children
            foreach ($rules as $parent_id => $parent_rule) {
                if (in_array($rule_id, $parent_rule['children'])) {
                    $rules[$parent_id]['children'] = array_diff($parent_rule['children'], [$rule_id]);
                }
            }
            
            // Delete the rule
            unset($rules[$rule_id]);
            
            // Save updated rules
            wp_autotag_pro()->config->set_rules($rules);
            
            wp_send_json_success([
                'message' => __('Rule deleted successfully', 'wp-auto-tag-pro')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error deleting rule: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
    
    /**
     * Get parent family details
     */
    public function get_parent_family() {
        $this->verify_ajax_security('manage_options');
        
        $parent_id = sanitize_text_field($_POST['parent_id'] ?? '');
        
        if (empty($parent_id)) {
            wp_send_json_error(__('Parent ID is required', 'wp-auto-tag-pro'));
        }
        
        try {
            $rules = wp_autotag_pro()->config->get_rules();
            
            if (!isset($rules[$parent_id])) {
                wp_send_json_error(__('Parent family not found', 'wp-auto-tag-pro'));
            }
            
            $parent = $rules[$parent_id];
            
            wp_send_json_success($parent);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error retrieving parent family: ', 'wp-auto-tag-pro') . $e->getMessage());
        }
    }
}