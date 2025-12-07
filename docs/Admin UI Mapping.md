# Admin UI Mapping - Auto-Tag Pro Plugin

## Quick Reference for AI Agents

This document provides a comprehensive mapping of the Auto-Tag Pro admin interface structure, functions, and file locations for easy UI modifications.

---

## 📁 File Structure Overview

### Core Admin Files
- **Main Admin Logic**: `includes/class-autotag-admin.php`
- **HTML Rendering**: `includes/class-autotag-admin-html.php`  
- **Settings Management**: `includes/class-plugin-settings.php`

---

## 🎛️ Admin Interface Structure

### Current Tab Layout (2 Tabs)
1. **Dashboard** - Configuration and overview
2. **Keywords** - Rule management and testing
3. **Bulk Processing** - Advanced operations and processing

---

## 📋 Tab Content Mapping

### 1. Dashboard Tab
**Function**: `render_dashboard_tab()` in `class-autotag-admin-html.php` (lines 83-300+)

#### Sections:
- **Overview Cards** (lines 129-159)
  - **Classification Rules Card** (lines 130-136): Shows active rules count, link to Keywords tab
  - **Taxonomy Status Card** (lines 138-151): Shows Tags/Categories enabled status with visual indicators (✓/✗)
  - **Processing Status Card** (lines 153-158): Shows posts needing processing count, link to Bulk Processing tab

- **Configuration Cards** (lines 168-300+)
  - **Taxonomies Card** (lines 171-194): Enable Tags/Categories checkboxes
  - **Post Types Card** (lines 197-225): Checkbox list of all public post types
  - **Content Areas Card** (lines 227-259): Post Title, Content, Excerpt, Custom Fields checkboxes
  - **Processing Options Card** (lines 261-278): Case-sensitive matching checkbox
  - **Maintenance Tools Card** (lines 280+): Various maintenance operations

- **AJAX Handlers**: `ajax_save_dashboard_settings()`, `ajax_save_quick_setup()`

---

### 2. Keywords Tab
**Function**: `render_rules_tab()` in `class-autotag-admin-html.php` (lines 579-940)

#### Current Structure (as of latest redesign):

##### **Enhanced Families Section** (lines 614-696) - **MOVED TO TOP**
- **Header**: "Enhanced Families" with "Add new family" button (lines 615-620)
- **Family Listings**: Complex rules with inheritance, conflicts, children display (lines 622-695)
- **Rule Editor Modal**: Family name, keywords, taxonomy application, parent selection (lines 795+)

##### **Simple Keywords Section** (lines 698-804) - **REDESIGNED**
- **Header**: "Simple Keywords" (line 700)
- **Description**: Instructions for comma-separated keyword entry (line 701)

###### **Tags Keywords Container** (lines 705-734)
- **Structure**: WordPress native `tagsdiv` with `post_tag` ID (line 707)
- **Input**: Native `newtag` input field for tag keywords (lines 714-716)
- **Display**: `tagchecklist` showing existing tag keywords as pills with X buttons (lines 720-732)
- **Current Issue**: ⚠️ **NEEDS REDESIGN** - Tags display outside input container

###### **Categories Keywords Container** (lines 737-766)
- **Structure**: WordPress native `tagsdiv` with `category` ID (line 739)
- **Input**: Native `newtag` input field for category keywords (lines 746-748)
- **Display**: `tagchecklist` showing existing category keywords as pills with X buttons (lines 752-764)
- **Current Issue**: ⚠️ **NEEDS REDESIGN** - Tags display outside input container

##### **Save Action** (lines 768-779)
- **Save Button**: "Save Keywords" with spinner and feedback (lines 774-778)
- **AJAX Handler**: `autotag_save_keywords` action

##### **Keywords Status Summary** (lines 781-803)
- **Active counts**: Shows number of active tag/category keywords
- **Collapsible preview**: Details view of current keywords

#### **🚨 CRITICAL PENDING REDESIGN**

**Reference Implementation Needed**: WordPress native tag selector (see `TODO-tag-selector-implementation.md`)

**Target Structure**:
```html
<div class="keyword-input-container">
    <span class="keyword-tag">2013 <button>×</button></span>
    <span class="keyword-tag">episódios <button>×</button></span>
    <input type="text" class="keyword-input" />
</div>
```

**Required Changes**:
1. **HTML**: Unified input container with inline tag pills and input field
2. **CSS**: Bordered container, gray tag pills, proper X buttons
3. **JavaScript**: Comma/Enter key detection, real-time tag creation, X button removal
4. **AJAX**: Update save handlers for new tag data format

**Files to Modify**:
- `class-autotag-admin-html.php` (lines 705-766) - HTML structure
- `admin/css/admin.css` - Add tag selector styles
- `admin/js/tag-selector.js` - Create new JavaScript file
- `class-autotag-admin.php` - Update AJAX handlers

#### Test Classification Rules Section (lines 890+)
- **Test with Sample Content**: Textarea + Test button
- **Test with Existing Post**: Post ID input + Test button
- **Results Display**: Generated Tags, Categories, Matched Rules
- **AJAX Handlers**: `ajax_preview_post()`, `ajax_generate_post()`

#### AJAX Handlers (in admin class):
- `ajax_save_rule()` (line 416) - Save/update enhanced family rules
- `ajax_delete_rule()` (line 480) - Delete rules
- `ajax_get_rules()` (line 514) - Fetch all rules
- `ajax_check_conflicts()` (line 538) - Check term conflicts
- `ajax_save_keywords()` - **NEW HANDLER NEEDED** for simple keywords save

---

### 3. Bulk Processing Tab
**Function**: `render_bulk_tab()` → `render_tools_page_content()` (lines 941-1200+)

#### Sections:
- **Advanced Options** (lines 970-995)
  - **Batch Size**: Number input (10-500, default: 50) (lines 981-985)
  - **Max Execution Time**: Number input (10-300, default: 30) (lines 987-991)
  - Save Advanced Options button

- **Tag Management** (lines 1000+)
  - **Include existing WordPress tags**: Checkbox
  - **Existing Tags Strategy**: Radio buttons (Merge/Ignore/Priority)
  - Save Tag Management Settings button

- **Bulk Processing Operations** (lines 1050+)
  - Processing statistics display
  - **Process New Posts** button
  - **Update Outdated Posts** button
  - **Reprocess All Posts** button
  - Progress bar and results display

- **Rollback System** (lines 1100+)
  - View Rollback History button
  - Rollback Last Bulk Operation button
  - History modal with filters

- **Maintenance Tools** (lines 1150+)
  - Clean Up Orphaned Meta button
  - Reset All Processing Status button
  - Clear Plugin Cache button

#### AJAX Handlers (in admin class):
- `ajax_apply_tags()` (line 762) - Apply tags to posts
- `ajax_apply_categories()` (line 802) - Apply categories to posts
- `ajax_import_config()` (line 842) - Import configuration
- `ajax_export_config()` (line 873) - Export configuration
- `ajax_enable_year_tags_retroactive()` (line 1005) - Enable year tags retroactively
- Bulk processing operations
- Rollback operations
- Maintenance operations

---

## 🔧 Settings Management

### Settings Storage
**File**: `includes/class-plugin-settings.php`

#### Key Methods:
- `get_all_settings()` (line 34) - Retrieve all settings with caching
- `update_settings()` (line 52) - Update multiple settings
- `get_enabled_post_types()` (line 79) - Get enabled post types array
- `get_enabled_taxonomies()` (line 86) - **CRITICAL**: Get enabled taxonomies array - controls core processing logic
- `get_content_areas()` (line 93) - Get content areas to analyze
- `is_case_sensitive()` (line 100+) - Check case sensitivity setting
- `get_batch_size()` - Get bulk processing batch size
- `get_max_execution_time()` - Get processing time limit

#### WordPress Options (Constants in class):
- `SETTINGS_OPTION` = `wp_autotag_pro_settings` (line 24) - Main settings array
- `CONFIG_OPTION` = `wp_autotag_pro_config` (line 25) - Rules and configuration
- `STATS_OPTION` = `wp_autotag_pro_stats` (line 27) - Usage statistics
- `VERSION_OPTION` = `wp_autotag_pro_version` (line 28) - Plugin version

### 🚨 **Critical Logic: Taxonomy Configuration**
The `enabled_taxonomies` setting is fundamental to the plugin's operation:
- **Location**: `wp_autotag_pro_settings['enabled_taxonomies']` array
- **Values**: `['post_tag']` for tags only, `['category']` for categories only, `['post_tag', 'category']` for both
- **Processing Logic**: Located in `class-processor-coordinator.php:get_taxonomy_application_options()` (lines 550-635)
- **Flow**: Dashboard checkboxes → `enabled_taxonomies` setting → Core processor → Taxonomy application
- **Result**: Only enabled taxonomies will be processed, regardless of existing rules

---

## 🎨 Navigation Structure

### Tab Registration
**File**: `class-autotag-admin-html.php`
- **Allowed Tabs Array** (line 28): `['dashboard', 'rules', 'bulk']`
- **Tab Labels** (lines 42-52): Dashboard, Keywords, Bulk Processing
- **Tab Routing** (lines 62-72): Switch statement for tab rendering

### Menu Registration
**File**: `class-autotag-admin.php`
- **Settings Page** (lines 72-78): Options → Auto-Tag Pro
- **Tools Page** (lines 81-87): Tools → Auto-Tag Pro

---

## ⚡ Complete AJAX Handlers Reference

### AJAX Handler Registration
**Location**: `class-autotag-admin.php` lines 49-61 in `init_hooks()` method

### All Available AJAX Handlers:
- `ajax_save_rule()` (line 416) - Save/update classification rules
- `ajax_delete_rule()` (line 480) - Delete classification rules
- `ajax_get_rules()` (line 514) - Fetch all rules data
- `ajax_check_conflicts()` (line 538) - Check term conflicts
- `ajax_save_quick_setup()` (line 581) - Save quick setup configuration
- `ajax_save_dashboard_settings()` (line 631) - Save dashboard settings
- `ajax_apply_tags()` (line 762) - Apply tags to posts
- `ajax_apply_categories()` (line 802) - Apply categories to posts
- `ajax_import_config()` (line 842) - Import plugin configuration
- `ajax_export_config()` (line 873) - Export plugin configuration
- `ajax_generate_post()` (line 897) - Generate tags for specific post
- `ajax_preview_post()` (line 974) - Preview classification results
- `ajax_enable_year_tags_retroactive()` (line 1005) - Enable year tags retroactively

---

## 🔍 Search Locations for Common Changes

### Adding New Tab
1. Add to `$allowed_tabs` array (line 28)
2. Add case to tab label switch (lines 42-52)
3. Add case to tab routing switch (lines 62-72)
4. Create new `render_[tab]_tab()` method

### Adding New Settings Field
1. Add to Dashboard tab HTML (render_dashboard_tab method)
2. Add getter method in `class-plugin-settings.php`
3. Add to default settings array if needed
4. Add AJAX handler if dynamic

### Moving UI Elements
1. **From Location**: Find in respective `render_*_tab()` method
2. **To Location**: Copy HTML to target `render_*_tab()` method  
3. **Clean Up**: Remove from original location
4. **Update AJAX**: Ensure handlers still work

### Removing UI Elements
1. **Find**: Locate in `render_*_tab()` method
2. **Remove**: Delete HTML block and surrounding logic
3. **Clean**: Remove unused AJAX handlers and methods
4. **Test**: Ensure no broken references

---

## 🎯 Common Modification Patterns

### Form Elements Structure
```php
<div class="section-name">
    <h3><?php esc_html_e('Section Title', 'wp-auto-tag-pro'); ?></h3>
    <p class="description"><?php esc_html_e('Description text', 'wp-auto-tag-pro'); ?></p>
    
    <div class="options-container">
        <!-- Form fields here -->
    </div>
    
    <div class="actions">
        <button type="button" id="save-action" class="button button-primary">
            <?php esc_html_e('Save Settings', 'wp-auto-tag-pro'); ?>
        </button>
    </div>
</div>
```

### AJAX Integration Pattern
```php
// In render method - nonce field
<?php wp_nonce_field('action_name', 'action_nonce'); ?>

// In admin class - AJAX handler  
public function ajax_action_name() {
    // Security checks
    if (!check_ajax_referer('autotag_admin_nonce', 'nonce', false)) {
        wp_send_json_error(__('Security check failed', 'wp-auto-tag-pro'));
    }
    
    // Process and respond
    wp_send_json_success(['message' => __('Success', 'wp-auto-tag-pro')]);
}

// Hook registration in init_hooks()
add_action('wp_ajax_autotag_action_name', [$this, 'ajax_action_name']);
```

---

## 📝 Quick Reference Commands

### Find UI Element
- Search for text content in `class-autotag-admin-html.php`
- Look for `esc_html_e()` calls with the visible text

### Find AJAX Handler  
- Search for `wp_ajax_autotag_` in `class-autotag-admin.php`
- Look for corresponding JavaScript calls in frontend

### Find Settings
- Check `class-plugin-settings.php` for getter methods
- Look for WordPress option names in constants

### Find Form Processing
- Search for `$_POST` handling in AJAX methods
- Check `sanitize_settings()` method for form data processing

---

*Last Updated: December 2024 - Based on current v2.1.0 codebase with Keywords tab redesign pending*

**📋 RELATED DOCUMENTATION:**
- **TODO Implementation Guide**: `TODO-tag-selector-implementation.md` - Detailed implementation plan for WordPress native tag selector
- **Reference Image**: WordPress native tag selector with bordered input container and inline tag pills