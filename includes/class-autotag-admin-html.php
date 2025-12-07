<?php
/**
 * Admin HTML Rendering Class
 * Handles all HTML output for admin pages - separated for better organization
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_Admin_HTML {
    
    private $admin_instance;
    
    public function __construct($admin_instance = null) {
        $this->admin_instance = $admin_instance;
    }
    
    /**
     * Render unified settings page per Master Specification v2.1.0
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-auto-tag-pro'));
        }
        
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';
        $allowed_tabs = ['dashboard', 'rules', 'bulk'];
        
        if (!in_array($active_tab, $allowed_tabs)) {
            $active_tab = 'dashboard';
        }
        ?>
        <div class="wrap autotag-unified-interface">
            <h1><?php echo esc_html(get_admin_page_title()); ?> <span class="version">v2.1.0</span></h1>
            
            <nav class="nav-tab-wrapper autotag-unified-nav">
                <?php foreach ($allowed_tabs as $tab): ?>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=wp-auto-tag-pro&tab=' . $tab)); ?>" 
                       class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>">
                        <?php
                        switch ($tab) {
                            case 'dashboard':
                                esc_html_e('Dashboard', 'wp-auto-tag-pro');
                                break;

                            case 'rules':
                                esc_html_e('Keywords', 'wp-auto-tag-pro');
                                break;
                            case 'bulk':
                                esc_html_e('Bulk Processing', 'wp-auto-tag-pro');
                                break;
                        }
                        ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            
            <div class="autotag-admin-content">
                <?php wp_nonce_field('autotag_admin_nonce', 'autotag_admin_nonce'); ?>
                <?php
                switch ($active_tab) {
                    case 'rules':
                        $this->render_rules_tab();
                        break;
                    case 'bulk':
                        $this->render_bulk_tab();
                        break;
                    default:
                        $this->render_dashboard_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    
    /**
     * Render dashboard tab - Master Specification unified interface 
     */
    private function render_dashboard_tab() {
        $rules_count = 0;
        $tags_enabled = false;
        $categories_enabled = false;
        $processing_stats = [];
        
        // Get WordPress core taxonomy labels for localization
        $tag_taxonomy = get_taxonomy('post_tag');
        $category_taxonomy = get_taxonomy('category');
        
        // Plural forms (for lists, overviews, multiple items)
        $tag_label_plural = $tag_taxonomy ? $tag_taxonomy->labels->name : __('Tags');
        $category_label_plural = $category_taxonomy ? $category_taxonomy->labels->name : __('Categories');
        
        // Singular forms (for individual items, actions on single item)
        $tag_label_singular = $tag_taxonomy ? $tag_taxonomy->labels->singular_name : __('Tag');
        $category_label_singular = $category_taxonomy ? $category_taxonomy->labels->singular_name : __('Category');
        
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            $rules = wp_autotag_pro()->config->get_rules();
            $rules_count = count($rules);
            $settings = wp_autotag_pro()->get_settings();
            $enabled_taxonomies = $settings['enabled_taxonomies'] ?? [];
            $tags_enabled = in_array('post_tag', $enabled_taxonomies);
            $categories_enabled = in_array('category', $enabled_taxonomies);
            
            if (wp_autotag_pro()->processor) {
                $processing_stats = wp_autotag_pro()->processor->get_processing_stats();
            }
        }
        
        // Show success message if redirected after save
        if (isset($_GET['saved']) && $_GET['saved'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully!', 'wp-auto-tag-pro') . '</p></div>';
        }
        ?>
        <form id="dashboard-settings-form" method="post">
            <?php wp_nonce_field('autotag_dashboard_settings', 'autotag_dashboard_settings_nonce'); ?>
            
        <div class="autotag-dashboard">
            <!-- Overview Section -->
            <div class="dashboard-overview">
                <h2 class="section-title"><?php esc_html_e('Overview', 'wp-auto-tag-pro'); ?></h2>
                <p class="section-description"><?php esc_html_e('Current status and statistics for your Auto-Tag Pro configuration.', 'wp-auto-tag-pro'); ?></p>
            </div>
            
            <div class="dashboard-cards">
                <div class="dashboard-card">
                    <h3><?php esc_html_e('Classification Rules', 'wp-auto-tag-pro'); ?></h3>
                    <div class="card-value"><?php echo number_format($rules_count); ?></div>
                    <p class="card-description"><?php esc_html_e('Active rules for content classification', 'wp-auto-tag-pro'); ?></p>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=wp-auto-tag-pro&tab=rules')); ?>" class="button button-secondary"><?php esc_html_e('Manage Keywords
', 'wp-auto-tag-pro'); ?></a>
                </div>
                
                <div class="dashboard-card">
                    <h3><?php esc_html_e('Taxonomy Status', 'wp-auto-tag-pro'); ?></h3>
                    <div class="taxonomy-status">
                        <span class="status-item tags <?php echo $tags_enabled ? 'enabled' : 'disabled'; ?>" id="tags-status">
                            <?php echo esc_html($tag_label_plural); ?>
                            <span class="status-indicator"><?php echo $tags_enabled ? '✓' : '✗'; ?></span>
                        </span>
                        <span class="status-item categories <?php echo $categories_enabled ? 'enabled' : 'disabled'; ?>" id="categories-status">
                            <?php echo esc_html($category_label_plural); ?>
                            <span class="status-indicator"><?php echo $categories_enabled ? '✓' : '✗'; ?></span>
                        </span>
                    </div>
                    <p class="card-description"><?php esc_html_e('Configure taxonomies below', 'wp-auto-tag-pro'); ?></p>
                </div>
                
                <div class="dashboard-card">
                    <h3><?php esc_html_e('Processing Status', 'wp-auto-tag-pro'); ?></h3>
                    <div class="card-value"><?php echo number_format($processing_stats['needs_processing'] ?? 0); ?></div>
                    <p class="card-description"><?php esc_html_e('Posts needing processing', 'wp-auto-tag-pro'); ?></p>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=wp-auto-tag-pro&tab=bulk')); ?>" class="button button-secondary"><?php esc_html_e('Bulk Process', 'wp-auto-tag-pro'); ?></a>
                </div>
            </div>
            
            <!-- Configuration Section -->
            <div class="dashboard-configuration">
                <h2 class="section-title"><?php esc_html_e('Configuration', 'wp-auto-tag-pro'); ?></h2>
                <p class="section-description"><?php esc_html_e('Configure how Auto-Tag Pro processes your content and applies taxonomies.', 'wp-auto-tag-pro'); ?></p>
            </div>
            
            <!-- Dashboard Settings Cards -->
            <div class="dashboard-settings-cards">
                
                <!-- Card 1: Taxonomies -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php esc_html_e('Taxonomies', 'wp-auto-tag-pro'); ?></h2>
                    </div>
                    <div class="inside">
                        <p class="description"><?php esc_html_e('Enable the taxonomies you want Auto-Tag Pro to manage.', 'wp-auto-tag-pro'); ?></p>
                        
                        <div class="options-list">
                            <div class="option-item">
                                <label>
                                    <input type="checkbox" id="enable-tags" name="enable_tags" value="1" <?php checked($tags_enabled); ?>>
                                    <?php echo esc_html($tag_label_plural); ?>
                                </label>
                            </div>
                            
                            <div class="option-item">
                                <label>
                                    <input type="checkbox" id="enable-categories" name="enable_categories" value="1" <?php checked($categories_enabled); ?>>
                                    <?php echo esc_html($category_label_plural); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                
                <!-- Card 2: Post Types -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php esc_html_e('Post Types', 'wp-auto-tag-pro'); ?></h2>
                    </div>
                    <div class="inside">
                        <p class="description"><?php esc_html_e('Select which post types should be processed.', 'wp-auto-tag-pro'); ?></p>
                        
                        <div class="options-list">
                            <?php 
                            $settings = function_exists('wp_autotag_pro') ? wp_autotag_pro()->get_settings() : [];
                            $enabled = $settings['enabled_post_types'] ?? ['post'];
                            $post_types = get_post_types(['public' => true], 'objects');
                            
                            foreach ($post_types as $post_type) {
                                $checked = in_array($post_type->name, $enabled) ? 'checked' : '';
                                ?>
                                <div class="option-item">
                                    <label>
                                        <input type="checkbox" name="enabled_post_types[]" value="<?php echo esc_attr($post_type->name); ?>" <?php echo $checked; ?>>
                                        <?php echo esc_html($post_type->label); ?>
                                    </label>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Card 3: Content Areas -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php esc_html_e('Content Areas', 'wp-auto-tag-pro'); ?></h2>
                    </div>
                    <div class="inside">
                        <p class="description"><?php esc_html_e('Choose which content areas to analyze for keywords.', 'wp-auto-tag-pro'); ?></p>
                        
                        <div class="options-list">
                            <?php 
                            $content_enabled = $settings['content_areas'] ?? ['title', 'content'];
                            $areas = [
                                'title' => __('Post Title', 'wp-auto-tag-pro'),
                                'content' => __('Post Content', 'wp-auto-tag-pro'),
                                'excerpt' => __('Post Excerpt', 'wp-auto-tag-pro'),
                                'custom_fields' => __('Custom Fields', 'wp-auto-tag-pro')
                            ];
                            
                            foreach ($areas as $area => $label) {
                                $checked = in_array($area, $content_enabled) ? 'checked' : '';
                                ?>
                                <div class="option-item">
                                    <label>
                                        <input type="checkbox" name="content_areas[]" value="<?php echo esc_attr($area); ?>" <?php echo $checked; ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Card 4: Processing Options (Full Width) -->
                <div class="postbox processing-options-card">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php esc_html_e('Processing Options', 'wp-auto-tag-pro'); ?></h2>
                    </div>
                    <div class="inside">
                        <p class="description"><?php esc_html_e('Advanced settings for content processing and keyword matching.', 'wp-auto-tag-pro'); ?></p>
                        
                        <div class="processing-options-grid">
                            <div class="processing-option">
                                <label>
                                    <input type="checkbox" name="case_sensitive" value="1" <?php checked($settings['case_sensitive'] ?? false, true); ?>>
                                    <?php esc_html_e('Enable case-sensitive keyword matching', 'wp-auto-tag-pro'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Card 5: Maintenance Tools -->
                <div class="postbox maintenance-tools-card">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php esc_html_e('Maintenance Tools', 'wp-auto-tag-pro'); ?></h2>
                    </div>
                    <div class="inside">
                        <p class="description"><?php esc_html_e('Clean up data and reset processing status when needed.', 'wp-auto-tag-pro'); ?></p>
                        
                        <div class="maintenance-tools-grid">
                            <?php wp_nonce_field('autotag_maintenance', 'autotag_maintenance_nonce'); ?>
                            <button type="button" id="autotag-cleanup-orphaned" class="button">
                                <?php esc_html_e('Clean Up Orphaned Meta', 'wp-auto-tag-pro'); ?>
                            </button>
                            <button type="button" id="autotag-reset-processing" class="button">
                                <?php esc_html_e('Reset All Processing Status', 'wp-auto-tag-pro'); ?>
                            </button>
                            <button type="button" id="autotag-clear-cache" class="button">
                                <?php esc_html_e('Clear Plugin Cache', 'wp-auto-tag-pro'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
            </div>
            
        </div>
        
        <!-- Unified Save Settings Button -->
        <div class="dashboard-save-section">
            <button type="button" id="save-dashboard-settings" class="button button-primary button-large">
                <?php esc_html_e('Save Settings', 'wp-auto-tag-pro'); ?>
            </button>
            <p class="description"><?php esc_html_e('Save all configuration changes. Taxonomy status will update after successful save.', 'wp-auto-tag-pro'); ?></p>
        </div>
        
        </form>
        
        <style type="text/css">
        /* Section Headers */
        .dashboard-overview, .dashboard-configuration {
            margin: 30px 0 20px 0;
            padding-bottom: 15px;
            border-bottom: 1px solid #dcdcde;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 8px 0;
            color: #1d2327;
        }
        
        .section-description {
            font-size: 14px;
            color: #646970;
            margin: 0;
            line-height: 1.4;
        }
        
        /* Overview Cards (Read-only) */
        .dashboard-cards {
            margin-bottom: 30px;
        }
        
        /* Dashboard Settings Cards Responsive Layout */
        .dashboard-settings-cards {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            grid-gap: 20px;
            margin: 0 0 30px 0;
            padding: 20px;
            background: #f6f7f7;
            border-radius: 4px;
            border: 1px solid #e1e1e1;
        }
        
        /* Processing Options Card - Full Width */
        .processing-options-card {
            grid-column: 1 / -1;
        }
        
        /* Maintenance Tools Card - Full Width */
        .maintenance-tools-card {
            grid-column: 1 / -1;
        }
        
        /* Card Content Styling */
        .dashboard-settings-cards .postbox {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .dashboard-settings-cards .postbox-header {
            border-bottom: 1px solid #c3c4c7;
        }
        
        .dashboard-settings-cards .hndle {
            font-size: 14px;
            font-weight: 600;
            margin: 0;
            padding: 8px 12px;
            line-height: 1.4;
        }
        
        .dashboard-settings-cards .inside {
            padding: 12px;
        }
        
        /* Option Groups */
        .options-list {
            margin-top: 10px;
        }
        
        .dashboard-settings-cards .option-item {
            margin-bottom: 8px;
            padding: 0 !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
            transition: none !important;
        }
        
        .dashboard-settings-cards .option-item label {
            font-weight: 600;
            display: flex;
            align-items: flex-start;
            cursor: pointer;
            margin-bottom: 0;
        }
        
        .dashboard-settings-cards .option-item input[type="checkbox"] {
            margin: 2px 8px 0 0;
        }
        
        /* Override hover effects for consistency */
        .dashboard-settings-cards .option-item:hover {
            border-color: transparent !important;
            box-shadow: none !important;
            background: transparent !important;
        }
        
        
        /* Processing Options Grid */
        .processing-options-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            grid-gap: 20px;
            align-items: start;
            margin-top: 10px;
        }
        
        .processing-option label {
            font-weight: 600;
            margin-bottom: 4px;
            display: block;
        }
        
        .input-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Maintenance Tools Grid */
        .maintenance-tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            grid-gap: 12px;
            margin-top: 10px;
        }
        
        .maintenance-tools-grid .button {
            width: 100%;
            text-align: center;
            font-size: 13px;
            padding: 8px 12px;
        }
        
        .input-group .small-text {
            width: 60px;
        }
        
        .input-suffix {
            color: #646970;
            font-size: 13px;
        }
        
        /* Responsive Design */
        @media screen and (max-width: 1199px) {
            .dashboard-settings-cards {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media screen and (max-width: 767px) {
            .dashboard-settings-cards {
                grid-template-columns: 1fr;
                grid-gap: 15px;
            }
            
            .processing-options-grid {
                grid-template-columns: 1fr;
                grid-gap: 15px;
            }
            
            .maintenance-tools-grid {
                grid-template-columns: 1fr;
                grid-gap: 10px;
            }
            
            .dashboard-settings-cards .inside {
                padding: 10px;
            }
        }
        
        @media screen and (max-width: 480px) {
            .dashboard-settings-cards {
                grid-gap: 12px;
                margin: 15px 0;
            }
            
            .dashboard-settings-cards .hndle {
                font-size: 13px;
                padding: 8px 10px;
            }
            
            .dashboard-settings-cards .inside {
                padding: 8px;
            }
        }
        </style>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Handle unified save button
            $('#save-dashboard-settings').on('click', function() {
                var $button = $(this);
                var $form = $('#dashboard-settings-form');
                
                // Disable button and show loading
                $button.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'wp-auto-tag-pro')); ?>');
                
                // Serialize form data
                var formData = $form.serializeArray();
                console.log('AutoTag Pro - Form data being sent:', formData);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'autotag_save_dashboard_settings',
                        nonce: $('input[name="autotag_dashboard_settings_nonce"]').val(),
                        form_data: formData
                    },
                    success: function(response) {
                        console.log('AutoTag Pro - AJAX response:', response);
                        if (response.success) {
                            // Show success message
                            $button.after('<div class="notice notice-success is-dismissible" style="margin-left: 10px; display: inline-block; padding: 5px 12px;"><p>' + response.data.message + '</p></div>');
                            
                            // Redirect to refresh the page and update status
                            if (response.data.redirect) {
                                window.location.href = response.data.redirect;
                            }
                        } else {
                            console.error('AutoTag Pro - Save failed:', response.data);
                            // Show error message
                            $button.after('<div class="notice notice-error is-dismissible" style="margin-left: 10px; display: inline-block; padding: 5px 12px;"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AutoTag Pro - AJAX error:', {xhr: xhr, status: status, error: error});
                        console.error('AutoTag Pro - Response text:', xhr.responseText);
                        $button.after('<div class="notice notice-error is-dismissible" style="margin-left: 10px; display: inline-block; padding: 5px 12px;"><p><?php echo esc_js(__('Error saving settings', 'wp-auto-tag-pro')); ?> - Check console for details</p></div>');
                    },
                    complete: function() {
                        // Re-enable button
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Save Settings', 'wp-auto-tag-pro')); ?>');
                        
                        // Auto-hide notices after 5 seconds
                        setTimeout(function() {
                            $('.notice.is-dismissible').fadeOut();
                        }, 5000);
                    }
                });
            });
        });
        </script>
        
        <?php
    }
    

    
    /**
     * Render rules tab - Unified rules management interface  
     */
    private function render_rules_tab() {
        $rules = [];
        $simple_tags = [];
        $simple_categories = [];
        
        // Get WordPress core taxonomy labels for localization
        $tag_taxonomy = get_taxonomy('post_tag');
        $category_taxonomy = get_taxonomy('category');
        
        // Plural forms (for lists, overviews, multiple items)
        $tag_label_plural = $tag_taxonomy ? $tag_taxonomy->labels->name : __('Tags');
        $category_label_plural = $category_taxonomy ? $category_taxonomy->labels->name : __('Categories');
        
        // Singular forms (for individual items, actions on single item)
        $tag_label_singular = $tag_taxonomy ? $tag_taxonomy->labels->singular_name : __('Tag');
        $category_label_singular = $category_taxonomy ? $category_taxonomy->labels->singular_name : __('Category');
        
        if (function_exists('wp_autotag_pro') && wp_autotag_pro() && wp_autotag_pro()->config) {
            $rules = wp_autotag_pro()->config->get_rules();
            
            // Separate simple and enhanced rules
            foreach ($rules as $rule_id => $rule_data) {
                if (($rule_data['type'] ?? 'enhanced') === 'simple') {
                    if (!empty($rule_data['tags'])) {
                        $simple_tags[$rule_id] = $rule_data;
                    }
                    if (!empty($rule_data['categories'])) {
                        $simple_categories[$rule_id] = $rule_data;
                    }
                }
            }
        }
        ?>
        <div class="autotag-rules-management">

            <!-- Enhanced Families Section Header -->
            <div class="rules-header">
                <h2><?php esc_html_e('Enhanced Families', 'wp-auto-tag-pro'); ?></h2>
                <button type="button" id="add-new-rule" class="button button-primary">
                    <?php esc_html_e('Add new family', 'wp-auto-tag-pro'); ?>
                </button>
            </div>

            <!-- Families Section -->
            <div class="rules-section enhanced-families">
                <h3>🏗️ <?php esc_html_e('Families', 'wp-auto-tag-pro'); ?></h3>
                <div class="rules-list">
                    <?php
                    $enhanced_rules = array_filter($rules, function($rule) {
                        return ($rule['type'] ?? 'enhanced') === 'enhanced';
                    });

                    if (empty($enhanced_rules)): ?>
                        <div class="no-rules">
                            <p><?php esc_html_e('No Families configured yet.', 'wp-auto-tag-pro'); ?></p>
                            <p><?php esc_html_e('Create your first family to start building complex classification hierarchies.', 'wp-auto-tag-pro'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="rules-table enhanced-rules">
                            <?php foreach ($enhanced_rules as $rule_id => $rule_data): ?>
                                <div class="rule-row enhanced-rule" data-rule-id="<?php echo esc_attr($rule_id); ?>">
                                    <div class="rule-header">
                                        <span class="rule-name"><?php echo esc_html($rule_data['name'] ?? ''); ?></span>
                                        <span class="inheritance-status <?php echo ($rule_data['inheritance_enabled'] ?? false) ? 'enabled' : 'disabled'; ?>">
                                            <?php echo ($rule_data['inheritance_enabled'] ?? false) ? '✓ inheritance: ON' : '✗ inheritance: OFF'; ?>
                                        </span>
                                        <span class="rule-actions">
                                            <button type="button" class="edit-rule button-link"><?php esc_html_e('Edit', 'wp-auto-tag-pro'); ?></button>
                                            <button type="button" class="delete-rule button-link"><?php esc_html_e('Delete', 'wp-auto-tag-pro'); ?></button>
                                        </span>
                                    </div>

                                    <div class="rule-details">
                                        <div class="rule-keywords">
                                            <strong><?php esc_html_e('Keywords:', 'wp-auto-tag-pro'); ?></strong>
                                            <?php echo esc_html(implode(', ', $rule_data['keywords'] ?? [])); ?>
                                        </div>

                                        <div class="rule-tags">
                                            <strong><?php printf('%s:', esc_html($tag_label_plural)); ?></strong>
                                            <?php echo esc_html(implode(', ', $rule_data['tags'] ?? [])); ?>
                                        </div>

                                        <div class="rule-categories">
                                            <strong><?php printf('%s:', esc_html($category_label_plural)); ?></strong>
                                            <?php echo esc_html(implode(', ', $rule_data['categories'] ?? [])); ?>
                                        </div>

                                        <?php if (!empty($rule_data['conflicts'])): ?>
                                            <div class="rule-conflicts">
                                                <strong>⚠️ <?php esc_html_e('Conflicts:', 'wp-auto-tag-pro'); ?></strong>
                                                <?php
                                                $conflict_names = array_map(function($conflict) {
                                                    return '"' . esc_html($conflict) . '" (tag + category)';
                                                }, $rule_data['conflicts']);
                                                echo implode(', ', $conflict_names);
                                                ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="rule-inheritance">
                                            <strong><?php esc_html_e('Children:', 'wp-auto-tag-pro'); ?></strong>
                                            <?php
                                            $children = $rule_data['children'] ?? [];
                                            if (empty($children)) {
                                                echo esc_html__('None', 'wp-auto-tag-pro');
                                            } else {
                                                echo esc_html(implode(', ', $children));
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Simple Keywords Section -->
            <div class="simple-keywords-section">
                <h2><?php esc_html_e('Simple Keywords', 'wp-auto-tag-pro'); ?></h2>
                <p class="description"><?php esc_html_e('Add keywords that will automatically become tags or categories. Type keywords separated by commas.', 'wp-auto-tag-pro'); ?></p>

                <div class="keywords-manager">
                    <!-- Tags Keywords Box -->
                    <div class="keyword-taxonomy-box">
                        <h3><?php echo esc_html($tag_label_plural); ?></h3>
                        <div class="tagsdiv" id="post_tag">
                            <div class="jaxtag">
                                <div class="nojs-tags hide-if-js">
                                    <label for="tax-input-post_tag"><?php echo esc_html($tag_label_plural); ?></label>
                                    <textarea name="tax_input[post_tag]" rows="3" cols="40" id="tax-input-post_tag"></textarea>
                                </div>
                                <div class="ajaxtag hide-if-no-js">
                                    <label class="screen-reader-text" for="new-tag-post_tag"><?php printf(esc_html__('Add New %s', 'wp-auto-tag-pro'), $tag_label_singular); ?></label>
                                    <div class="taghint"><?php printf(esc_html__('Add New %s', 'wp-auto-tag-pro'), $tag_label_singular); ?></div>
                                    <p><input type="text" id="new-tag-post_tag" name="newtag[post_tag]" class="newtag form-input-tip" size="16" autocomplete="off" aria-describedby="new-tag-post_tag-desc" value="" /></p>
                                </div>
                                <p class="howto" id="new-tag-post_tag-desc"><?php esc_html_e('Separate keywords with commas', 'wp-auto-tag-pro'); ?></p>
                            </div>
                            <div class="tagchecklist" role="list">
                                <?php
                                // Get existing tag keywords and display them as tags
                                $tag_keywords = [];
                                foreach ($simple_tags as $rule_data) {
                                    $tag_keywords = array_merge($tag_keywords, $rule_data['keywords'] ?? []);
                                }
                                $tag_keywords = array_unique($tag_keywords);

                                foreach ($tag_keywords as $keyword): ?>
                                    <span role="listitem"><a id="post_tag-check-num-<?php echo esc_attr(sanitize_title($keyword)); ?>" class="ntdelbutton" data-keyword="<?php echo esc_attr($keyword); ?>" data-type="tag">X</a>&nbsp;<?php echo esc_html($keyword); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Categories Keywords Box -->
                    <div class="keyword-taxonomy-box">
                        <h3><?php echo esc_html($category_label_plural); ?></h3>
                        <div class="tagsdiv" id="category">
                            <div class="jaxtag">
                                <div class="nojs-tags hide-if-js">
                                    <label for="tax-input-category"><?php echo esc_html($category_label_plural); ?></label>
                                    <textarea name="tax_input[category]" rows="3" cols="40" id="tax-input-category"></textarea>
                                </div>
                                <div class="ajaxtag hide-if-no-js">
                                    <label class="screen-reader-text" for="new-tag-category"><?php printf(esc_html__('Add New %s', 'wp-auto-tag-pro'), $category_label_singular); ?></label>
                                    <div class="taghint"><?php printf(esc_html__('Add New %s', 'wp-auto-tag-pro'), $category_label_singular); ?></div>
                                    <p><input type="text" id="new-tag-category" name="newtag[category]" class="newtag form-input-tip" size="16" autocomplete="off" aria-describedby="new-tag-category-desc" value="" /></p>
                                </div>
                                <p class="howto" id="new-tag-category-desc"><?php esc_html_e('Separate keywords with commas', 'wp-auto-tag-pro'); ?></p>
                            </div>
                            <div class="tagchecklist" role="list">
                                <?php
                                // Get existing category keywords and display them as tags
                                $category_keywords = [];
                                foreach ($simple_categories as $rule_data) {
                                    $category_keywords = array_merge($category_keywords, $rule_data['keywords'] ?? []);
                                }
                                $category_keywords = array_unique($category_keywords);

                                foreach ($category_keywords as $keyword): ?>
                                    <span role="listitem"><a id="category-check-num-<?php echo esc_attr(sanitize_title($keyword)); ?>" class="ntdelbutton" data-keyword="<?php echo esc_attr($keyword); ?>" data-type="category">X</a>&nbsp;<?php echo esc_html($keyword); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="setup-actions">
                        <?php wp_nonce_field('autotag_save_keywords', 'autotag_save_keywords_nonce'); ?>
                        <button type="button" id="save-simple-keywords" class="button button-primary">
                            <?php esc_html_e('Save Keywords', 'wp-auto-tag-pro'); ?>
                        </button>
                        <span class="spinner"></span>
                        <div id="keywords-save-feedback" class="keywords-feedback" style="display: none;"></div>
                    </div>
                </div>
                <!-- Current Keywords Status -->
                <div class="keywords-status">
                    <div class="keywords-summary">
                        <div class="status-item">
                            <span class="status-label"><?php printf(esc_html__('Active %s Keywords:', 'wp-auto-tag-pro'), $tag_label_plural); ?></span>
                            <span class="status-count"><?php echo count($simple_tags); ?></span>
                        </div>
                        <div class="status-item">
                            <span class="status-label"><?php printf(esc_html__('Active %s Keywords:', 'wp-auto-tag-pro'), $category_label_plural); ?></span>
                            <span class="status-count"><?php echo count($simple_categories); ?></span>
                        </div>
                    </div>

                    <?php if (!empty($simple_tags) || !empty($simple_categories)): ?>
                        <div class="keywords-preview">
                            <details>
                                <summary><?php esc_html_e('View Current Keywords', 'wp-auto-tag-pro'); ?></summary>
                                <div class="keywords-lists">
                                    <?php if (!empty($simple_tags)): ?>
                                        <div class="keyword-type">
                                            <strong><?php echo esc_html($tag_label_plural); ?>:</strong>
                                            <?php foreach ($simple_tags as $rule_data): ?>
                                                <span class="keyword-list"><?php echo esc_html(implode(', ', $rule_data['keywords'] ?? [])); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($simple_categories)): ?>
                                        <div class="keyword-type">
                                            <strong><?php echo esc_html($category_label_plural); ?>:</strong>
                                            <?php foreach ($simple_categories as $rule_data): ?>
                                                <span class="keyword-list"><?php echo esc_html(implode(', ', $rule_data['keywords'] ?? [])); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
           
            
            <!-- Rule Editor Modal -->
            <div id="rule-editor-modal" class="autotag-modal" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 id="modal-title"><?php esc_html_e('Add New Family', 'wp-auto-tag-pro'); ?></h3>
                        <button type="button" class="modal-close">&times;</button>
                    </div>
                    
                    <div class="modal-body">
                        <form id="rule-form">
                            <?php wp_nonce_field('autotag_save_rule', 'autotag_save_rule_nonce'); ?>
                            <input type="hidden" id="rule-id" name="rule_id" value="">
                            <input type="hidden" id="rule-type" name="rule_type" value="enhanced">
                            
                            <div class="form-group">
                                <label for="rule-name"><?php esc_html_e('Family Name (required)', 'wp-auto-tag-pro'); ?></label>
                                <input type="text" id="rule-name" name="rule_name" required>
                                <p class="description"><?php esc_html_e('A descriptive name for this classification family.', 'wp-auto-tag-pro'); ?></p>
                            </div>
                            
                            <div class="form-group">
                                <label for="rule-keywords"><?php esc_html_e('Keywords (required)', 'wp-auto-tag-pro'); ?></label>
                                <textarea id="rule-keywords" name="rule_keywords" rows="4" required placeholder="<?php esc_attr_e('javascript, js, react, node', 'wp-auto-tag-pro'); ?>"></textarea>
                                <p class="description"><?php esc_html_e('Words that trigger this classification', 'wp-auto-tag-pro'); ?></p>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="rule-apply-tags" name="rule_apply_tags" checked>
                                    <?php printf(esc_html__('Apply %s (optional)', 'wp-auto-tag-pro'), $tag_label_plural); ?>
                                </label>
                                <input type="text" id="rule-tags" name="rule_tags" placeholder="<?php esc_attr_e('javascript, programming, web-dev', 'wp-auto-tag-pro'); ?>">
                                <p class="description"><?php printf(esc_html__('If checked and empty, use keywords as %s; if unchecked, no %s are created', 'wp-auto-tag-pro'), strtolower($tag_label_plural), strtolower($tag_label_plural)); ?></p>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="rule-apply-categories" name="rule_apply_categories" checked>
                                    <?php printf(esc_html__('Apply %s (optional)', 'wp-auto-tag-pro'), $category_label_plural); ?>
                                </label>
                                <input type="text" id="rule-categories" name="rule_categories" placeholder="<?php esc_attr_e('Web Development, Programming, Technology', 'wp-auto-tag-pro'); ?>">
                                <p class="description"><?php printf(esc_html__('If checked and empty, use keywords as %s; if unchecked, no %s are created', 'wp-auto-tag-pro'), strtolower($category_label_plural), strtolower($category_label_plural)); ?></p>
                            </div>
                            
                            <div class="form-group">
                                <label for="rule-parent"><?php esc_html_e('Parent Family', 'wp-auto-tag-pro'); ?></label>
                                <select id="rule-parent" name="rule_parent">
                                    <option value=""><?php esc_html_e('None', 'wp-auto-tag-pro'); ?></option>
                                    <?php foreach ($enhanced_rules as $rule_id => $rule_data): ?>
                                        <option value="<?php echo esc_attr($rule_id); ?>"><?php echo esc_html($rule_data['name'] ?? ''); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Select existing family as parent', 'wp-auto-tag-pro'); ?></p>
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="rule-inheritance" name="rule_inheritance">
                                    <?php esc_html_e('This family has children', 'wp-auto-tag-pro'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Enable inheritance for child families', 'wp-auto-tag-pro'); ?></p>
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="rule-enabled" name="rule_enabled" checked>
                                    <?php esc_html_e('Enable this family', 'wp-auto-tag-pro'); ?>
                                </label>
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="rule-case-sensitive" name="rule_case_sensitive">
                                    <?php esc_html_e('Case sensitive matching', 'wp-auto-tag-pro'); ?>
                                </label>
                            </div>
                            
                            <div id="conflict-warnings" class="conflict-warnings" style="display: none;">
                                <!-- Conflict warnings will be inserted here -->
                            </div>
                            
                            <div id="validation-warning" class="validation-warning" style="display: none;">
                                <p>⚠️ <?php printf(esc_html__('At least one of %s or %s must be selected to validate a new family', 'wp-auto-tag-pro'), $tag_label_plural, $category_label_plural); ?></p>
                            </div>
                        </form>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="button" id="cancel-rule"><?php esc_html_e('Cancel', 'wp-auto-tag-pro'); ?></button>
                        <button type="button" class="button button-primary" id="save-rule"><?php esc_html_e('Save Family', 'wp-auto-tag-pro'); ?></button>
                    </div>
                </div>
            </div>
            
            <!-- Test Classification Rules Section -->
            <div class="autotag-test-interface">
                <h2><?php esc_html_e('Test Classification Rules', 'wp-auto-tag-pro'); ?></h2>
                <p class="description"><?php esc_html_e('Test your rules with sample content or by analyzing existing posts.', 'wp-auto-tag-pro'); ?></p>
                
                <div class="test-options">
                    <div class="test-option">
                        <h3><?php esc_html_e('Test with Sample Content', 'wp-auto-tag-pro'); ?></h3>
                        <?php wp_nonce_field('autotag_test_content', 'autotag_test_content_nonce'); ?>
                        <textarea id="test-content" rows="8" placeholder="<?php esc_attr_e('Enter test content here...', 'wp-auto-tag-pro'); ?>"></textarea>
                        <button type="button" id="test-sample-content" class="button button-primary">
                            <?php esc_html_e('Test Rules', 'wp-auto-tag-pro'); ?>
                        </button>
                    </div>
                    
                    <div class="test-option">
                        <h3><?php esc_html_e('Test with Existing Post', 'wp-auto-tag-pro'); ?></h3>
                        <?php wp_nonce_field('autotag_test_post', 'autotag_test_post_nonce'); ?>
                        <input type="number" id="test-post-id" placeholder="<?php esc_attr_e('Enter Post ID', 'wp-auto-tag-pro'); ?>" min="1">
                        <button type="button" id="test-existing-post" class="button button-primary">
                            <?php esc_html_e('Test Rules', 'wp-auto-tag-pro'); ?>
                        </button>
                    </div>
                </div>
                
                <div id="test-results" class="test-results" style="display: none;">
                    <h3><?php esc_html_e('Test Results', 'wp-auto-tag-pro'); ?></h3>
                    
                    <div class="results-section">
                        <h4><?php printf(esc_html__('Generated %s', 'wp-auto-tag-pro'), $tag_label_plural); ?></h4>
                        <div id="generated-tags" class="taxonomy-output"></div>
                    </div>
                    
                    <div class="results-section">
                        <h4><?php printf(esc_html__('Generated %s', 'wp-auto-tag-pro'), $category_label_plural); ?></h4>
                        <div id="generated-categories" class="taxonomy-output"></div>
                    </div>
                    
                    <div class="results-section">
                        <h4><?php esc_html_e('Matched Rules', 'wp-auto-tag-pro'); ?></h4>
                        <div id="matched-rules" class="rules-output"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    
    /**
     * Render bulk processing tab
     */
    private function render_bulk_tab() {
        // Use existing tools page content but integrate it here
        $this->render_tools_page_content();
    }
    
    /**
     * Render tools page content - used by both tools page and bulk tab
     */
    private function render_tools_page_content() {
        // Get WordPress core taxonomy labels for localization
        $tag_taxonomy = get_taxonomy('post_tag');
        $category_taxonomy = get_taxonomy('category');
        
        // Plural forms (for lists, overviews, multiple items)
        $tag_label_plural = $tag_taxonomy ? $tag_taxonomy->labels->name : __('Tags');
        $category_label_plural = $category_taxonomy ? $category_taxonomy->labels->name : __('Categories');
        
        // Singular forms (for individual items, actions on single item)
        $tag_label_singular = $tag_taxonomy ? $tag_taxonomy->labels->singular_name : __('Tag');
        $category_label_singular = $category_taxonomy ? $category_taxonomy->labels->singular_name : __('Category');
        
        // Get processing stats with fallback
        $processing_stats = [];
        if (function_exists('wp_autotag_pro') && wp_autotag_pro() && wp_autotag_pro()->processor) {
            $processing_stats = wp_autotag_pro()->processor->get_processing_stats();
        }
        ?>
        <div class="autotag-tools-content">
            <!-- Advanced Options Section -->
            <div class="bulk-advanced-options">
                <h2><?php esc_html_e('Advanced Options', 'wp-auto-tag-pro'); ?></h2>
                <p class="description"><?php esc_html_e('Advanced performance and processing options.', 'wp-auto-tag-pro'); ?></p>
                
                <div class="advanced-options-fields">
                    <?php 
                    $settings = function_exists('wp_autotag_pro') ? wp_autotag_pro()->get_settings() : [];
                    $batch_size = $settings['batch_size'] ?? 50;
                    $max_execution_time = $settings['max_execution_time'] ?? 30;
                    ?>
                    
                    <div class="advanced-option">
                        <label for="bulk-batch-size"><?php esc_html_e('Batch Size', 'wp-auto-tag-pro'); ?></label>
                        <input type="number" id="bulk-batch-size" name="batch_size" value="<?php echo esc_attr($batch_size); ?>" min="10" max="500">
                        <span><?php esc_html_e('posts per batch', 'wp-auto-tag-pro'); ?></span>
                    </div>
                    
                    <div class="advanced-option">
                        <label for="bulk-max-execution-time"><?php esc_html_e('Max Execution Time', 'wp-auto-tag-pro'); ?></label>
                        <input type="number" id="bulk-max-execution-time" name="max_execution_time" value="<?php echo esc_attr($max_execution_time); ?>" min="10" max="300">
                        <span><?php esc_html_e('seconds', 'wp-auto-tag-pro'); ?></span>
                    </div>
                </div>
                
                <div class="advanced-options-actions">
                    <button type="button" id="save-advanced-options" class="button button-primary">
                        <?php esc_html_e('Save Advanced Options', 'wp-auto-tag-pro'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Tag Management Section -->
            <div class="tag-management-section">
                <h2><?php printf(esc_html__('%s Management', 'wp-auto-tag-pro'), $tag_label_singular); ?></h2>
                <p class="description"><?php printf(esc_html__('Configure how %s are managed and integrated with existing WordPress %s.', 'wp-auto-tag-pro'), strtolower($tag_label_plural), strtolower($tag_label_plural)); ?></p>
                
                <div class="tag-management-options">
                    <?php 
                    $settings = function_exists('wp_autotag_pro') ? wp_autotag_pro()->get_settings() : [];
                    $include_existing = $settings['include_existing_tags'] ?? true;
                    $strategy = $settings['existing_tags_strategy'] ?? 'merge';
                    ?>
                    
                    <div class="existing-tags-option">
                        <label>
                            <input type="checkbox" name="include_existing_tags" value="1" <?php checked($include_existing, true); ?>>
                            <?php printf(esc_html__('Include existing WordPress %s when processing posts', 'wp-auto-tag-pro'), strtolower($tag_label_plural)); ?>
                        </label>
                        <p class="description"><?php printf(esc_html__('When enabled, existing %s on posts will be preserved according to the strategy below.', 'wp-auto-tag-pro'), strtolower($tag_label_plural)); ?></p>
                    </div>
                    
                    <div class="existing-tags-strategy">
                        <h3><?php printf(esc_html__('Existing %s Strategy', 'wp-auto-tag-pro'), $tag_label_plural); ?></h3>
                        <?php 
                        $strategies = [
                            'merge' => sprintf(__('Merge with new %s', 'wp-auto-tag-pro'), strtolower($tag_label_plural)),
                            'ignore' => sprintf(__('Ignore existing %s', 'wp-auto-tag-pro'), strtolower($tag_label_plural)),
                            'priority' => sprintf(__('Prioritize existing %s', 'wp-auto-tag-pro'), strtolower($tag_label_plural))
                        ];
                        
                        foreach ($strategies as $value => $label) {
                            ?>
                            <div class="strategy-option">
                                <label>
                                    <input type="radio" name="existing_tags_strategy" value="<?php echo esc_attr($value); ?>" <?php checked($strategy, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
                
                <div class="tag-management-actions">
                    <button type="button" id="save-tag-management" class="button button-primary">
                        <?php printf(esc_html__('Save %s Management Settings', 'wp-auto-tag-pro'), $tag_label_singular); ?>
                    </button>
                </div>
            </div>
            
            <div class="autotag-bulk-actions">
                <h2><?php esc_html_e('Bulk Processing', 'wp-auto-tag-pro'); ?></h2>
                
                <div class="autotag-bulk-stats">
                    <p><?php printf(esc_html__('Posts needing processing: %s', 'wp-auto-tag-pro'), '<strong>' . number_format($processing_stats['needs_processing'] ?? 0) . '</strong>'); ?></p>
                    <p><?php printf(esc_html__('Outdated posts: %s', 'wp-auto-tag-pro'), '<strong>' . number_format($processing_stats['outdated_posts'] ?? 0) . '</strong>'); ?></p>
                </div>
                
                <div class="autotag-bulk-buttons">
                    <?php wp_nonce_field('autotag_bulk_process', 'autotag_bulk_process_nonce'); ?>
                    <button type="button" id="autotag-process-new" class="button button-primary" data-action="new">
                        <?php esc_html_e('Process New Posts', 'wp-auto-tag-pro'); ?>
                    </button>
                    <button type="button" id="autotag-process-outdated" class="button button-secondary" data-action="outdated">
                        <?php esc_html_e('Update Outdated Posts', 'wp-auto-tag-pro'); ?>
                    </button>
                    <button type="button" id="autotag-process-all" class="button" data-action="all">
                        <?php esc_html_e('Reprocess All Posts', 'wp-auto-tag-pro'); ?>
                    </button>
                </div>
                
                <div id="autotag-bulk-progress" style="display: none;">
                    <div class="autotag-progress-bar">
                        <div class="autotag-progress-fill"></div>
                    </div>
                    <div class="autotag-progress-text"></div>
                </div>
                
                <div id="autotag-bulk-results" style="display: none;">
                    <h3><?php esc_html_e('Processing Results', 'wp-auto-tag-pro'); ?></h3>
                    <div class="autotag-results-content"></div>
                </div>
                
                <!-- Rollback System Controls -->
                <div class="autotag-rollback-section">
                    <h2><?php esc_html_e('Rollback System', 'wp-auto-tag-pro'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Undo bulk processing operations or individual post changes. Recent operations are stored for rollback.', 'wp-auto-tag-pro'); ?>
                    </p>
                    
                    <div class="autotag-rollback-controls">
                        <button type="button" id="autotag-show-rollback-history" class="button button-secondary">
                            <?php esc_html_e('View Rollback History', 'wp-auto-tag-pro'); ?>
                        </button>
                        <button type="button" id="autotag-rollback-last-bulk" class="button" style="margin-left: 10px;">
                            <?php esc_html_e('Rollback Last Bulk Operation', 'wp-auto-tag-pro'); ?>
                        </button>
                    </div>
                    
                    <!-- Rollback History Modal -->
                    <div id="autotag-rollback-modal" class="autotag-modal" style="display: none;">
                        <div class="autotag-modal-content">
                            <div class="autotag-modal-header">
                                <h3><?php esc_html_e('Rollback History', 'wp-auto-tag-pro'); ?></h3>
                                <button type="button" class="autotag-modal-close">&times;</button>
                            </div>
                            <div class="autotag-modal-body">
                                <div class="autotag-rollback-filters">
                                    <label>
                                        <?php esc_html_e('Operation Type:', 'wp-auto-tag-pro'); ?>
                                        <select id="rollback-filter-type">
                                            <option value=""><?php esc_html_e('All Operations', 'wp-auto-tag-pro'); ?></option>
                                            <option value="bulk"><?php esc_html_e('Bulk Operations', 'wp-auto-tag-pro'); ?></option>
                                            <option value="individual"><?php esc_html_e('Individual Posts', 'wp-auto-tag-pro'); ?></option>
                                        </select>
                                    </label>
                                    <button type="button" id="rollback-filter-apply" class="button button-small">
                                        <?php esc_html_e('Apply Filter', 'wp-auto-tag-pro'); ?>
                                    </button>
                                </div>
                                
                                <div id="autotag-rollback-history-list">
                                    <p><?php esc_html_e('Loading rollback history...', 'wp-auto-tag-pro'); ?></p>
                                </div>
                                
                                <div class="autotag-rollback-pagination">
                                    <button type="button" id="rollback-prev-page" class="button button-small" disabled>
                                        <?php esc_html_e('Previous', 'wp-auto-tag-pro'); ?>
                                    </button>
                                    <span id="rollback-page-info">Page 1 of 1</span>
                                    <button type="button" id="rollback-next-page" class="button button-small" disabled>
                                        <?php esc_html_e('Next', 'wp-auto-tag-pro'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="autotag-modal-footer">
                                <button type="button" class="button autotag-modal-close">
                                    <?php esc_html_e('Close', 'wp-auto-tag-pro'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            
            <div class="autotag-debugging">
                <h2><?php esc_html_e('Debugging & Logs', 'wp-auto-tag-pro'); ?></h2>
                
                <div class="autotag-debug-actions">
                    <button type="button" id="autotag-debug-post" class="button">
                        <?php esc_html_e('Debug Specific Post', 'wp-auto-tag-pro'); ?>
                    </button>
                </div>
                
                <div class="autotag-debug-controls" style="display: none;">
                    <input type="number" id="debug-post-id" placeholder="<?php esc_attr_e('Enter Post ID to debug', 'wp-auto-tag-pro'); ?>" min="1">
                    <button type="button" id="run-debug" class="button">
                        <?php esc_html_e('Run Debug', 'wp-auto-tag-pro'); ?>
                    </button>
                </div>
                
                <div id="autotag-debug-results" style="display: none;">
                    <!-- Debug results will be loaded here -->
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render statistics tab with enhanced safety checks
     */
    private function render_statistics_tab() {
        // Get statistics with fallbacks
        $stats = [];
        $processing_stats = [];
        $config_stats = [];
        
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()) {
            $stats = wp_autotag_pro()->get_stats();
            
            if (wp_autotag_pro()->processor) {
                $processing_stats = wp_autotag_pro()->processor->get_processing_stats();
            }
            
            if (wp_autotag_pro()->config) {
                $config_stats = wp_autotag_pro()->config->get_config_stats();
            }
        }
        ?>
        <div class="autotag-statistics">
            <div class="autotag-stats-grid">
                <div class="autotag-stat-card">
                    <h3><?php esc_html_e('Processing Statistics', 'wp-auto-tag-pro'); ?></h3>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Total Posts Processed:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo number_format($stats['total_processed'] ?? 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Total Tags Added:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo number_format($stats['total_tags_added'] ?? 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Year Tags Added:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo number_format($stats['year_tags_added'] ?? 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Errors Count:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo number_format($stats['errors_count'] ?? 0); ?></span>
                    </div>
                </div>
                
                <div class="autotag-stat-card">
                    <h3><?php esc_html_e('Posts Status', 'wp-auto-tag-pro'); ?></h3>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Total Eligible Posts:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo number_format($processing_stats['total_posts'] ?? 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Processed Posts:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo number_format($processing_stats['processed_posts'] ?? 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Needs Processing:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo number_format($processing_stats['needs_processing'] ?? 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Outdated Posts:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo number_format($processing_stats['outdated_posts'] ?? 0); ?></span>
                    </div>
                </div>
                
                <div class="autotag-stat-card">
                    <h3><?php esc_html_e('Configuration', 'wp-auto-tag-pro'); ?></h3>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Mode:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value">
                            <?php 
                            $mode = $config_stats['mode'] ?? 'classic';
                            echo $mode === 'families' ? esc_html__('Tag Families', 'wp-auto-tag-pro') : esc_html__('Classic Keywords', 'wp-auto-tag-pro'); 
                            ?>
                        </span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php printf(esc_html__('Year %s:', 'wp-auto-tag-pro'), $tag_label_plural); ?></span>
                        <span class="stat-value">
                            <?php echo ($config_stats['year_tags_enabled'] ?? false) ? esc_html__('Enabled', 'wp-auto-tag-pro') : esc_html__('Disabled', 'wp-auto-tag-pro'); ?>
                        </span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">
                            <?php echo ($config_stats['mode'] ?? 'classic') === 'families' ? 
                                esc_html__('Total Families:', 'wp-auto-tag-pro') : 
                                esc_html__('Total Keywords:', 'wp-auto-tag-pro'); ?>
                        </span>
                        <span class="stat-value">
                            <?php echo number_format($config_stats['total_families'] ?? $config_stats['total_keywords'] ?? 0); ?>
                        </span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php esc_html_e('Config Hash:', 'wp-auto-tag-pro'); ?></span>
                        <span class="stat-value"><?php echo esc_html($config_stats['current_hash'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render post meta box with dual taxonomy controls - Master Specification v2.1.0
     */
    public function render_post_meta_box($post) {
        if (!$post || !is_object($post)) {
            return;
        }
        
        wp_nonce_field('autotag_meta_box', 'autotag_meta_box_nonce');
        
        // Get WordPress core taxonomy labels for localization
        $tag_taxonomy = get_taxonomy('post_tag');
        $category_taxonomy = get_taxonomy('category');
        
        // Plural forms (for lists, overviews, multiple items)
        $tag_label_plural = $tag_taxonomy ? $tag_taxonomy->labels->name : __('Tags');
        $category_label_plural = $category_taxonomy ? $category_taxonomy->labels->name : __('Categories');
        
        // Singular forms (for individual items, actions on single item)
        $tag_label_singular = $tag_taxonomy ? $tag_taxonomy->labels->singular_name : __('Tag');
        $category_label_singular = $category_taxonomy ? $category_taxonomy->labels->singular_name : __('Category');
        
        $status = get_post_meta($post->ID, 'auto_tag_status', true);
        $processed_hash = get_post_meta($post->ID, 'auto_tag_processed_hash', true);
        $last_processed = get_post_meta($post->ID, 'auto_tag_last_processed', true);
        
        // Get enabled taxonomies
        $settings = function_exists('wp_autotag_pro') ? wp_autotag_pro()->get_settings() : [];
        $enabled_taxonomies = $settings['enabled_taxonomies'] ?? [];
        $tags_enabled = in_array('post_tag', $enabled_taxonomies);
        $categories_enabled = in_array('category', $enabled_taxonomies);
        
        // Get current hash safely
        $current_hash = '';
        if (function_exists('wp_autotag_pro') && wp_autotag_pro() && wp_autotag_pro()->config) {
            $current_hash = wp_autotag_pro()->config->get_current_hash();
        }
        
        // Determine status display
        $status_display = $this->get_status_display($status, $processed_hash, $current_hash);
        
        ?>
        <div class="autotag-meta-box autotag-unified">
            <div class="autotag-status-display">
                <strong><?php esc_html_e('Status:', 'wp-auto-tag-pro'); ?></strong>
                <span class="autotag-status autotag-status-<?php echo esc_attr($status_display['class']); ?>">
                    <?php echo esc_html($status_display['text']); ?>
                </span>
            </div>
            
            <?php if ($last_processed && is_numeric($last_processed)): ?>
                <div class="autotag-last-processed">
                    <strong><?php esc_html_e('Last Processed:', 'wp-auto-tag-pro'); ?></strong>
                    <?php echo esc_html(human_time_diff($last_processed) . ' ' . __('ago', 'wp-auto-tag-pro')); ?>
                </div>
            <?php endif; ?>
            
            <div class="autotag-controls">
                <select name="autotag_status" id="autotag-status-select">
                    <option value="pending" <?php selected($status, 'pending'); ?>>
                        <?php esc_html_e('Pending Processing', 'wp-auto-tag-pro'); ?>
                    </option>
                    <option value="processed" <?php selected($status, 'processed'); ?>>
                        <?php esc_html_e('Processed', 'wp-auto-tag-pro'); ?>
                    </option>
                    <option value="disabled" <?php selected($status, 'disabled'); ?>>
                        <?php esc_html_e('Disabled', 'wp-auto-tag-pro'); ?>
                    </option>
                </select>
            </div>
            
            <div class="autotag-actions">
                <div class="autotag-generate-section">
                    <button type="button" id="autotag-generate" class="button button-primary" data-post-id="<?php echo esc_attr($post->ID); ?>">
                        🔍 <?php esc_html_e('Generate', 'wp-auto-tag-pro'); ?>
                    </button>
                    <span class="current-selection-label"><?php esc_html_e('Current:', 'wp-auto-tag-pro'); ?></span>
                    <select id="autotag-taxonomy-selection" class="autotag-taxonomy-dropdown">
                        <?php if ($tags_enabled && $categories_enabled): ?>
                            <option value="both"><?php printf(esc_html__('%s + %s', 'wp-auto-tag-pro'), $tag_label_plural, $category_label_plural); ?></option>
                            <option value="tags"><?php printf(esc_html__('%s Only', 'wp-auto-tag-pro'), $tag_label_plural); ?></option>
                            <option value="categories"><?php printf(esc_html__('%s Only', 'wp-auto-tag-pro'), $category_label_plural); ?></option>
                        <?php elseif ($tags_enabled): ?>
                            <option value="tags"><?php printf(esc_html__('%s Only', 'wp-auto-tag-pro'), $tag_label_plural); ?></option>
                        <?php elseif ($categories_enabled): ?>
                            <option value="categories"><?php printf(esc_html__('%s Only', 'wp-auto-tag-pro'), $category_label_plural); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            
            <div id="autotag-post-preview" style="display: none;">
                <div class="taxonomy-previews">
                    <?php if ($tags_enabled): ?>
                        <div class="taxonomy-preview tags-preview">
                            <h4><?php printf(esc_html__('Generated %s', 'wp-auto-tag-pro'), $tag_label_plural); ?></h4>
                            <div id="preview-tags-list" class="taxonomy-list"></div>
                            <button type="button" id="apply-tags" class="button button-secondary" data-post-id="<?php echo esc_attr($post->ID); ?>" style="display: none;">
                                <?php printf(esc_html__('Save %s', 'wp-auto-tag-pro'), $tag_label_plural); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($categories_enabled): ?>
                        <div class="taxonomy-preview categories-preview">
                            <h4><?php printf(esc_html__('Generated %s', 'wp-auto-tag-pro'), $category_label_plural); ?></h4>
                            <div id="preview-categories-list" class="taxonomy-list"></div>
                            <button type="button" id="apply-categories" class="button button-secondary" data-post-id="<?php echo esc_attr($post->ID); ?>" style="display: none;">
                                <?php printf(esc_html__('Save %s', 'wp-auto-tag-pro'), $category_label_plural); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="strategy-controls" id="strategy-controls" style="display: none;">
                        <div class="strategy-section">
                            <h4><?php esc_html_e('Application Strategies', 'wp-auto-tag-pro'); ?></h4>
                            <?php if ($tags_enabled): ?>
                                <div class="strategy-row">
                                    <label><?php printf(esc_html__('%s strategy:', 'wp-auto-tag-pro'), $tag_label_singular); ?></label>
                                    <div class="strategy-options">
                                        <label>
                                            <input type="radio" name="tag_strategy" value="merge" checked>
                                            ● <?php esc_html_e('Merge', 'wp-auto-tag-pro'); ?>
                                        </label>
                                        <label>
                                            <input type="radio" name="tag_strategy" value="replace">
                                            ○ <?php esc_html_e('Replace', 'wp-auto-tag-pro'); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($categories_enabled): ?>
                                <div class="strategy-row">
                                    <label><?php printf(esc_html__('%s strategy:', 'wp-auto-tag-pro'), $category_label_singular); ?></label>
                                    <div class="strategy-options">
                                        <label>
                                            <input type="radio" name="category_strategy" value="merge" checked>
                                            ● <?php esc_html_e('Merge', 'wp-auto-tag-pro'); ?>
                                        </label>
                                        <label>
                                            <input type="radio" name="category_strategy" value="replace">
                                            ○ <?php esc_html_e('Replace', 'wp-auto-tag-pro'); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="taxonomy-warnings" id="taxonomy-warnings" style="display: none;">
                        <div class="tag-limit-warning" id="tag-limit-warning" style="display: none;">
                            ⚠️ <span class="warning-text"></span>
                        </div>
                    </div>
                    
                    <div class="current-taxonomies-display" id="current-taxonomies-display" style="display: none;">
                        <div class="current-tags" id="current-tags-display" style="display: none;">
                            <strong><?php printf('%s:', esc_html($tag_label_plural)); ?></strong>
                            <span class="current-tags-list"></span>
                        </div>
                        <div class="current-categories" id="current-categories-display" style="display: none;">
                            <strong><?php printf('%s:', esc_html($category_label_plural)); ?></strong>
                            <span class="current-categories-list"></span>
                        </div>
                    </div>
                    
                    <?php if ($tags_enabled && $categories_enabled): ?>
                        <div class="taxonomy-actions-combined">
                            <button type="button" id="apply-both" class="button button-primary" data-post-id="<?php echo esc_attr($post->ID); ?>" style="display: none;">
                                ✨ <?php esc_html_e('Save Both', 'wp-auto-tag-pro'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="additional-settings" id="additional-settings" style="display: none;">
                        <?php 
                        // Get max tags setting
                        $settings = function_exists('wp_autotag_pro') ? wp_autotag_pro()->get_settings() : [];
                        $max_tags = $settings['max_tags_per_post'] ?? 15;
                        $include_year_tags = $settings['include_year_tags'] ?? true;
                        $auto_apply = $settings['auto_process_on_save'] ?? false;
                        ?>
                        <div class="settings-row">
                            <label>
                                <input type="checkbox" id="max-tags-enabled" <?php echo $max_tags > 0 ? 'checked' : ''; ?>>
                                <?php esc_html_e('Max tags:', 'wp-auto-tag-pro'); ?>
                            </label>
                            <input type="number" id="max-tags-value" value="<?php echo esc_attr($max_tags); ?>" min="1" max="50" <?php echo $max_tags > 0 ? '' : 'disabled'; ?>>
                        </div>
                        <div class="settings-row">
                            <label>
                                <input type="checkbox" id="include-year-tags" <?php echo $include_year_tags ? 'checked' : ''; ?>>
                                <?php esc_html_e('Include year tag', 'wp-auto-tag-pro'); ?>
                            </label>
                        </div>
                        <div class="settings-row">
                            <label>
                                <input type="checkbox" id="auto-apply" <?php echo $auto_apply ? 'checked' : ''; ?>>
                                <?php esc_html_e('Auto-apply on saving post', 'wp-auto-tag-pro'); ?>
                            </label>
                        </div>
                    </div>
                    
                    <?php if (!$tags_enabled && !$categories_enabled): ?>
                        <div class="no-taxonomies-enabled">
                            <p><?php esc_html_e('No taxonomies enabled.', 'wp-auto-tag-pro'); ?></p>
                            <a href="<?php echo esc_url(admin_url('options-general.php?page=wp-auto-tag-pro&tab=quick-setup')); ?>" class="button button-secondary">
                                <?php esc_html_e('Enable Taxonomies', 'wp-auto-tag-pro'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="matched-rules" id="matched-rules-display" style="display: none;">
                    <h4><?php esc_html_e('Matched Rules', 'wp-auto-tag-pro'); ?></h4>
                    <div id="matched-rules-list"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get status display information
     */
    private function get_status_display($status, $processed_hash, $current_hash) {
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
     * Render tools page - delegates to centralized content method
     */
    public function render_tools_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-auto-tag-pro'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php wp_nonce_field('autotag_tools_nonce', 'autotag_tools_nonce'); ?>
            <?php $this->render_tools_page_content(); ?>
        </div>
        <?php
    }
}