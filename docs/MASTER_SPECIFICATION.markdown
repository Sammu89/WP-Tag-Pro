# WordPress Auto-Tag Pro - Master Specification & Single Source of Truth

**Version:** 2.1.0 (Unified Strategy + Categories)  
**Date:** 2025-09-12 (Updated)  
**Status:** DEFINITIVE SPECIFICATION  
**Purpose:** Complete technical and functional specification for the unified auto-tagging and category classification system

---

## 🎯 **PLUGIN OVERVIEW**

WordPress Auto-Tag Pro is an intelligent content analysis system that automatically assigns relevant **tags and categories** to WordPress posts using a **unified configuration strategy**. The plugin allows users to create classification families that scale from simple keyword matching to complex hierarchical tag and category families.

### **Core Philosophy**
- **Single Universal Strategy:** No artificial "classic" vs "family" mode distinction
- **Dual Taxonomy Support:** Same logic applies to both tags and categories
- **Scalable Complexity:** Start simple, grow sophisticated as needed
- **User-Centric Design:** Think "when I see X, classify it with Y" - not technical implementation details
- **Automatic Processing:** True auto-classification with optional manual preview/control
- **WordPress Integration:** Leverages native category hierarchy while extending with custom inheritance

---

## 🏗️ **UNIFIED CONFIGURATION SYSTEM**

### **Family Types (Internal Classification Only)**

#### **1. Quick Setup (`simple`)**
- **User Input:** Single keywords transformed into tags or categories based on user selection.
- **Behavior:** Each keyword becomes its own tag or category (or both) as specified in the Quick Setup checkboxes.
- **Example:** Keywords: `"wordpress"` → Tag: `wordpress` or Category: `Wordpress` (or both if both checkboxes are selected).
- **Use Case:** Quick setup for basic auto-classification.

#### **2. Advanced Families (`enhanced`)**
- **User Input:** Keywords + custom tags + custom categories, creates a "Family" with optional inheritance.
- **Behavior:** Any keyword match applies all specified tags and/or categories. If "Has Children" is enabled, child Families inherit parent tags/categories.
- **Example:** Keywords: `"javascript, js, react"` → Tags: `javascript, programming, web-dev`, Categories: `Web Development, Programming`.
- **Use Case:** Rich classification with hierarchical relationships.

### **Key Features**
- **Dual Taxonomy Control:** Users choose tags only, categories only, or both per family.
- **Unified Interface:** Single configuration page handles all complexity levels for both taxonomies.
- **Natural Progression:** Quick Setup families can evolve into Advanced Families without reconfiguration.
- **WordPress Category Integration:** Respects WordPress native category hierarchy while extending functionality.
- **Duplicate Detection:** Warns users about potential tag/category name conflicts (case-insensitive).

---

## 📋 **ADMIN INTERFACE SPECIFICATION**

### **Main Configuration Page Layout**

```
┌─────────────────────────────────────────────────────────┐
│ WordPress Auto-Tag Pro Settings                         │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ 📊 DASHBOARD                                            │
│ • 1,247 posts processed • 23 Families configured       │
│ • Last bulk run: 2 hours ago • Status: All systems OK │
│                                                         │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ ⚡ QUICK SETUP                                          │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ Add simple keywords: wordpress, seo, blogging      │ │
│ │ Auto-create: [☑️] Tags  [☐] Categories            │ │
│ │                                    [Save]          │ │
│ └─────────────────────────────────────────────────────┘ │
│ ✅ Added 3 auto-classification families                │
│                                                         │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ 🏗️  FAMILIES           [+ Add New]            │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ [-] Technology (inheritance: ON)       [Edit][Del] │ │
│ │     ├── Keywords: javascript, js, react, node      │ │
│ │     ├── Tags: javascript, programming, web-dev     │ │
│ │     ├── Categories: Web Development, Programming   │ │
│ │     ├── ⚠️ Conflict: "programming" (tag + category)│ │
│ │     └── Children: None                             │ │
│ ├─────────────────────────────────────────────────────┤ │
│ │ [-] Content Marketing                  [Edit][Del] │ │
│ │     ├── Keywords: blogging, content, articles      │ │
│ │     ├── Tags: content-marketing, writing, strategy │ │
│ │     ├── Categories: Marketing, Content Strategy    │ │
│ │     └── Children: SEO, Social Media     │ │
│ └─────────────────────────────────────────────────────┘ │
│                                                         │
│ 🏗️ Tags [+ Add new keywords in Quick Setup]     │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ Direct Keyword → Tag match               │ │
│ │ [wordpress, seo, blogging]        [Edit][Delete]   │ │
│ └─────────────────────────────────────────────────────┘ │
│                                                         │
│ 🏗️ Categories [+ Add new keywords in Quick Setup]│
│ ┌─────────────────────────────────────────────────────┐ │
│ │ Direct Keyword ⇨ Category match         │ │
│ │ [Wordpress, SEO]                  [Edit][Delete]   │ │
│ └─────────────────────────────────────────────────────┘ │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ 🧪 TEST YOUR SETUP                                     │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ Test phrase: "JavaScript blogging tutorial"        │ │
│ │                                        [Test]       │ │
│ └─────────────────────────────────────────────────────┘ │
│                                                         │
│ 📋 Generated tags: 2024, content-marketing,           │
│                   javascript, programming, web-dev,   │
│                   writing                             │
│                                                         │
│ 📁 Generated categories: Web Development, Marketing,   │
│                         Programming                    │
│                                                         │
│ ⚠️ Potential doubles: "programming" (tag + category) │
│                                                         │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ 🚀 BULK APPLY RULES TO EXISTING CONTENT                      │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ Content to Process:                                 │ │
│ │ ☑️ Posts (1,247) ☑️ Pages (89) ☐ Products (0)     │ │
│ │                                                     │ │
│ │ What to process:                                   │ │
│ │ ☐ Tags only    ☐ Categories only    ☑️ Both       │ │
│ │                                                     │ │
│ │ Strategies:                                        │ │
│ │ Tags:       ● Merge    ○ Recreate                  │ │
│ │ Categories: ● Merge    ○ Recreate                  │ │
│ │                                                     │ │
│ │ Additional Options:                                │ │
│ │ ☑️ Add Year as tag (based on publication date)     │ │
│ │ ☑️ Auto-create missing categories                  │ │
│ │                                                     │ │
│ │ Processing Settings:                               │ │
│ │ Batch size: [50] posts  Timeout: [3 minutes]       │ │
│ │                                                     │ │
│ │              [Cancel]      [Start Processing]      │ │
│ └─────────────────────────────────────────────────────┘ │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ 📥 IMPORT/EXPORT RULES (Families+Tags+Categories)                        │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ [Export Configuration]   [Import Configuration]    │ │
│ └─────────────────────────────────────────────────────┘ │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ ⚙️ GLOBAL SETTINGS                                     │
│ • Max tags per post: [15] ☑️ (user can turn this off) │
│ • Auto-process new posts: ☑️                          │
│ • Include year tags: ☑️                               │
│ • Processing timeout: [3 minutes]                     │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

#### **Tags**
- Displays keywords from Quick Setup where the "Tags" checkbox was selected.
- Users can edit or delete keywords directly in this section.
- If a keyword is selected for both Tags and Categories, it appears in both "Tags" and "Categories" sections.

#### **Categories**
- Displays keywords from Quick Setup where the "Categories" checkbox was selected.
- Users can edit or delete keywords directly in this section.
- If a keyword is selected for both Tags and Categories, it appears in both "Tags" and "Categories" sections.

### **Family Add/Edit Modal**

```
┌─────────────────────────────────────────────────────────┐
│ Add New Family                              [X]         │
├─────────────────────────────────────────────────────────┤
│ Family Name (required): [Technology____________]         │
│                                                         │
│ Keywords (required): [javascript, js, react, node___]   │
│ ℹ️ Words that trigger this classification               │
│                                                         │
│ 📋 [☑️] Apply Tags (optional):                          │
│ [javascript, programming, web-dev________________]      │
│ ℹ️ If checked and empty, use keywords as tags; if unchecked, no tags are created │
│                                                         │
│ 📁 [☑️] Apply Categories (optional):                    │
│ [Web Development, Programming, Technology_______]       │
│ ℹ️ If checked and empty, use keywords as categories; if unchecked, no categories are created │
│                                                         │
│ ⚠️ WARNING: "programming" exists as both tag and        │
│    category. System will apply both during processing.  │
│    [Keep Both] [Remove Tag] [Remove Category]           │
│                                                         │
│ ⚠️ At least one of Tags or Categories must be selected  │
│    to validate a new family                            │
│                                                         │
│ Parent Family: [None ▼] (Select existing family as parent) │
│                                                         │
│ ☑️ This family has children                            │
│ Child: [None ▼] (Select existing family as child)      │
│   └─ ☑️ Apply parent tags/categories when children match │
│                                                         │
│ ⚠️ WARNING: Parent has multiple categories; only the    │
│    first ("Technology") will be used as parent category │
│    for child categories. Adjust in WordPress Categories │
│    page if needed.                                     │
│                                                         │
│                           [Cancel]    [Save Family]     │
└─────────────────────────────────────────────────────────┘
```

When editing, a more detailed window appears that lets users change the parent family. Parent Families can have multiple children, and children can also have children, but a child Family can only have one parent.

#### **Conflict Warning**
- If "Keep Both" is selected, the plugin applies both tag and category, relying on WordPress’s taxonomy system to handle duplicates via unique slugs. Users are advised to verify slugs in WordPress to avoid conflicts.

```
┌─────────────────────────────────────────────────────────┐
│ Edit Existing Family                        [X]         │
├─────────────────────────────────────────────────────────┤
│ Family Name (required): [Technology____________]         │
│ ℹ️ If this family is a child, show parent Family here,  │
│    with option to click to edit                        │
│ Parent Family: [None ▼] (Change existing family as parent) │
│                                                         │
│ Keywords (required): [javascript, js, react, node___]   │
│ ℹ️ Words that trigger this classification               │
│                                                         │
│ 📋 [☑️] Apply Tags (optional):                          │
│ [javascript, programming, web-dev________________]      │
│ ℹ️ If checked and empty, use keywords as tags; if unchecked, no tags are created │
│                                                         │
│ 📁 [☑️] Apply Categories (optional):                    │
│ [Web Development, Programming, Technology_______]       │
│ ℹ️ If checked and empty, use keywords as categories; if unchecked, no categories are created │
│                                                         │
│ ⚠️ WARNING: "programming" exists as both tag and        │
│    category. System will apply both during processing.  │
│    [Keep Both] [Remove Tag] [Remove Category]           │
│                                                         │
│ ⚠️ At least one of Tags or Categories must be selected  │
│    to validate a family                                │
│                                                         │
│ ☑️ This family has children                            │
│ Child: [None ▼] (Select existing family as child)      │
│   └─ ☑️ Apply parent tags/categories when children match │
│                                                         │
│ ⚠️ WARNING: Parent has multiple categories; only the    │
│    first ("Technology") will be used as parent category │
│    for child categories. Adjust in WordPress Categories │
│    page if needed.                                     │
│                                                         │
│                           [Cancel]    [Save Family]     │
└─────────────────────────────────────────────────────────┘
```

Users can select established families as children, and parent/child inheritance applies to both tags and categories.

### **Post Editor Integration**

```
┌─────────────────────────────────────────────────────────┐
│ Auto-Tag Pro                                    [⚙️]    │
├─────────────────────────────────────────────────────────┤
│ [🔍 Generate] Current: [Tags + Categories ▼]            │
│                                                         │
│ 📋 Generated tags for this post:                       │
│ • 2024, javascript, learning, programming, tutorial, web-dev │
│                                                         │
│ 📁 Generated categories for this post:                 │
│ • Web Development, Programming, Tutorials              │
│                                                         │
│ ⚠️ WARNING: 2 tags truncated (writing, strategy) due to │
│    max tags limit (15).                                │
│                                                         │
│ Tag strategy: [● Merge  ○ Replace]                     │
│ Category strategy: [● Merge  ○ Replace]                │
│                                                         │
│ Tags: current + generated, user can delete or edit     │
│ Categories: current + generated, user can delete or edit│
│                                                         │
│ [Save Tags] [Save Categories] [✨ Save Both]            │
│                                                         │
│ ☑️ Max tags: [15] (user defined value, optional)             │
│ ☑️ Include year tag ☑️ Auto-apply on saving post       │
└─────────────────────────────────────────────────────────┘
```

Users can delete/edit tags and categories after generation. Edits are preserved when applying. Users control merge vs replace per taxonomy. If tags are truncated due to the max tags limit, a warning lists the removed tags.

### **Import/Export Functionality**
- **Scope:** Exports/imports all configuration data, including Tags, Categories, and Advanced Families (`wp_autotag_pro_rules`) and global settings (`wp_autotag_pro_settings`).
- **Format:** JSON file with clear structure for families and settings.
- **UI Placement:** Dedicated buttons on the Main Configuration Page for "Export Configuration" and "Import Configuration."

---

## 🔄 **PROCESSING LOGIC SPECIFICATION**

### **Content Analysis Pipeline**

1. **Content Extraction**
   - Extract post title, content, excerpt.
   - Normalize text (lowercase, remove accents, clean spacing).
   - Tokenize into words and phrases.

2. **Keyword Matching**
   - Scan normalized content for all configured keywords.
   - Record all matches with their associated families.
   - Handle multiple matches per family and across families.

3. **Tag and Category Generation**
   - Apply classifications based on family configuration:
     - **Simple:** Keyword → same keyword as tag/category (if enabled).
     - **Enhanced:** Any keyword match → all specified tags and/or categories, with inheritance if enabled.
   - **Year Tag Processing:** If enabled, the year tag (e.g., `2024`) is added as the first tag, followed by other tags sorted alphabetically.

4. **Classification Consolidation**
   - Remove duplicate tags and categories separately.
   - Apply tag limit per post (user configurable, default: 15):
     - If active and inheritance enabled: Disable inheritance to reduce count.
     - If still over limit: Sort tags alphabetically (year tag remains first) and remove last tags until reaching limit.
     - **Warning:** Display a warning in the Post Editor Integration and Bulk Processing Report if tags are truncated, listing removed tags.
   - Handle WordPress category requirements (never remove last category).
   - Merge with existing classifications based on strategy (merge/replace per taxonomy).

5. **WordPress Integration**
   - Apply tags using `wp_set_post_tags()`.
   - Apply categories using `wp_set_post_categories()`.
   - Respect WordPress category hierarchy (auto-includes parents).
   - Handle category creation if auto-create is enabled.

### **Inheritance Logic (Tags and Categories)**

**Parent Family:** Technology (inheritance: ON)
- Keywords: `["technology", "tech"]`
- Tags: `["technology", "innovation"]`
- Categories: `["Technology", "Innovation"]`

**Child Family:** JavaScript
- Keywords: `["javascript", "js", "react"]`
- Tags: `["javascript", "programming"]`
- Categories: `["Web Development", "Programming"]`

**Content Match:** "I love JavaScript"
- Matches: JavaScript child family
- **Result with inheritance ON:**
  - Tags: `2024, innovation, javascript, programming, technology`
  - Categories: `Web Development, Programming, Technology, Innovation`
- **Result with inheritance OFF:**
  - Tags: `2024, javascript, programming`
  - Categories: `Web Development, Programming`

### **Multiple Family Matching**

**Content:** "JavaScript AI tutorial"
- Matches: JavaScript family + AI family + Tutorial family
- **Result:** Combined tags and categories from all matching families + year tag (first in tag list, followed by alphabetical order).
- **Deduplication:** Automatic removal of duplicate tags and categories separately.
- **WordPress Integration:** Categories automatically include WordPress hierarchy parents.

### **Category-Specific Processing**

**Default Category Handling:**
- If no categories match: Leave existing categories unchanged.
- If categories match: Add/replace based on user strategy.
- Never remove the last category from a post (WordPress requirement).
- WordPress automatically assigns parent categories when children are assigned.

**Category Creation:**
- Auto-create missing categories if enabled (like tags). Tags are lowercase; Categories have the first letter uppercase.
- Respect user choice: Create or skip missing categories.
- Categories in a Family are created as top-level unless the Family is a child. For child Families, the first category of the parent Family is assigned as the parent category in WordPress. If the parent Family has multiple categories, a warning is displayed in the Family Add/Edit Modal, and users can adjust hierarchy in WordPress’s native category editor.

---

## 🔧 **BACKGROUND PROCESSING SYSTEM**

### **Bulk Processing Specifications**

#### **Performance Parameters**
- **Batch Size:** 50 posts per batch (user configurable).
- **Processing Timeout:** 3 minutes per batch.
- **Memory Monitoring:** Processing stops if memory usage exceeds 99% for the entire `processing_timeout` (3 minutes). No shorter threshold is applied.
- **Auto-Resume:** After 30 seconds if processing stops unexpectedly.
- **Manual Resume:** Available via admin notices.

#### **WordPress Integration**
- **Cron System:** Uses `wp_schedule_single_event()` for batch processing.
- **Background Processing:** Non-blocking operation continues during navigation.
- **Admin Notices:** Persistent notifications across all admin pages.
- **Progress Updates:** AJAX polling every 5 seconds for real-time status.

#### **Tag Rollback System**
```php
// Rollback Data Structure
wp_autotag_rollback_data = [
    'operation_id' => 'bulk_20250903_143022',
    'timestamp' => 1693747822,
    'strategy' => 'merge', // or 'replace'
    'year_tags_enabled' => true,
    'post_changes' => [
        123 => [
            'before' => ['old-tag', 'manual-tag'],
            'after' => ['2024, javascript, manual-tag, old-tag']
        ],
        124 => [
            'before' => ['seo'],
            'after' => ['2024, seo, tutorial, wordpress']
        ]
    ]
]
```

#### **Rollback Capabilities**
- **Full Rollback:** Restores all posts to their pre-processing state for both tags and categories.
- **Individual Rollback:** Restores specific posts for both tags and categories, with no option to rollback only one taxonomy.
- **Granular Control:** Users can rollback specific operations by timestamp.

### **Admin Notification System**

#### **Processing Started**
```
┌─────────────────────────────────────────────────────────┐
│ 🔄 Auto-Tag Pro: Processing 1,336 posts in background  │
│    Progress: 245/1,336 (18%) • [View Details] [X]      │
└─────────────────────────────────────────────────────────┘
```

#### **Processing Complete**
```
┌─────────────────────────────────────────────────────────┐
│ ✅ Auto-Tag Pro: Bulk processing complete!             │
│    📊 1,336 posts • 4,827 tags added • 3 errors       │
│    [View Report] [Rollback Changes] [X]               │
└─────────────────────────────────────────────────────────┘
```

#### **Error/Pause Notice**
```
┌─────────────────────────────────────────────────────────┐
│ ⚠️ Auto-Tag Pro: Processing paused (high memory usage) │
│    📊 892/1,336 posts completed • [Resume] [X]         │
└─────────────────────────────────────────────────────────┘
```

### **Processing Status Dashboard**

```
┌─────────────────────────────────────────────────────────┐
│ 🔄 BACKGROUND PROCESSING STATUS                        │
├─────────────────────────────────────────────────────────┤
│ Operation: Bulk Apply Tags and Categories              │
│ Progress: [████████████████████░░░] 892/1,336 (67%)   │
│ Current: Processing batch 18/27                       │
│ Speed: ~45 posts/minute                               │
│ Est. time remaining: 9 minutes                        │
│                                                       │
│ 📊 Results so far:                                    │
│ • Posts processed: 892                                │
│ • Tags added: 3,247                                   │
│ • Categories added: 1,234                             │
│ • Errors: 0                                          │
│                                                       │
│                    [Pause Processing] [Stop & Rollback]│
└─────────────────────────────────────────────────────────┘
```

---

## 🗄️ **DATABASE SCHEMA SPECIFICATION**

### **Configuration Storage**

#### **Main Settings** (`wp_options`)
```php
'wp_autotag_pro_settings' => [
    'max_tags_per_post' => 15,
    'auto_process_new' => true,
    'include_year_tags' => true,
    'auto_create_categories' => true,
    'default_tag_strategy' => 'merge', // 'merge' or 'replace'
    'default_category_strategy' => 'merge', // 'merge' or 'replace'
    'processing_timeout' => 180, // 3 minutes in seconds
    'batch_size' => 50,
    'memory_limit_threshold' => 99, // percentage
    'memory_limit_duration' => 180 // 3 minutes in seconds
]
```

#### **Family Configuration** (`wp_options`)
```php
'wp_autotag_pro_rules' => [
    'family_id_1' => [
        'name' => 'Technology',
        'keywords' => ['technology', 'tech', 'innovation'],
        'tags' => ['technology', 'innovation'],
        'categories' => ['Technology', 'Innovation'],
        'type' => 'enhanced', // 'simple' or 'enhanced'
        'inheritance_enabled' => true,
        'parent_id' => null,
        'children' => ['family_id_2', 'family_id_3'],
        'conflicts' => ['technology'] // Case-insensitive matches between tags and categories
    ],
    'family_id_2' => [
        'name' => 'JavaScript',
        'keywords' => ['javascript', 'js', 'react', 'node'],
        'tags' => ['javascript', 'programming'],
        'categories' => ['Web Development', 'Programming'],
        'type' => 'enhanced',
        'parent_id' => 'family_id_1',
        'conflicts' => ['programming'] // Detected conflicts
    ],
    // Simple families created via Quick Add
    'family_id_4' => [
        'name' => null, // No name for simple families
        'keywords' => ['wordpress'],
        'tags' => ['wordpress'], // Same as keyword
        'categories' => ['Wordpress'], // Same as keyword
        'type' => 'simple',
        'conflicts' => []
    ]
]
```
- **Conflicts Field:** Stores case-insensitive matches between tags and categories (e.g., `['programming']`) to warn users during family creation/editing. Ignored conflicts do not alter processing; both tags and categories are applied as configured, relying on WordPress’s taxonomy system.

#### **Processing History** (`wp_options`)
```php
'wp_autotag_pro_operations' => [
    'bulk_20250903_143022' => [
        'type' => 'bulk_processing',
        'started' => 1693747822,
        'completed' => 1693749234,
        'status' => 'completed', // 'running', 'paused', 'error', 'completed'
        'tag_strategy' => 'merge', // 'merge' or 'replace'
        'category_strategy' => 'merge', // 'merge' or 'replace'
        'taxonomies_processed' => ['tags', 'categories'], // or ['tags'] or ['categories']
        'posts_total' => 1336,
        'posts_processed' => 1336,
        'posts_errors' => 3,
        'tags_added' => 4827,
        'categories_added' => 2143,
        'year_tags_enabled' => true,
        'auto_create_categories' => true,
        'rollback_available' => true
    ]
]
```

#### **Rollback Storage** (`wp_options`)
```php
'wp_autotag_rollback_bulk_20250903_143022' => [
    'operation_id' => 'bulk_20250903_143022',
    'expiry' => 1693834222, // 24 hours from completion
    'post_changes' => [
        123 => [
            'tags_before' => ['old-tag', 'manual-tag'],
            'tags_after' => ['2024, javascript, manual-tag, old-tag'],
            'categories_before' => ['Uncategorized'],
            'categories_after' => ['Uncategorized', 'Web Development', 'Programming']
        ]
        // ... up to thousands of posts
    ]
]
```

#### **Processing Queue** (`transients`)
```php
set_transient('autotag_queue_batch_18', [
    'operation_id' => 'bulk_20250903_143022',
    'batch_number' => 18,
    'post_ids' => [123, 124, 125, ...], // 50 posts
    'retry_count' => 0,
    'last_attempt' => null
], HOUR_IN_SECONDS);
```

---

## 🔐 **SECURITY SPECIFICATIONS**

### **Input Validation & Sanitization**
- **Keywords:** `sanitize_text_field()` for individual keywords.
- **Tags:** `sanitize_text_field()` + WordPress tag validation.
- **Categories:** `sanitize_text_field()` + WordPress category validation.
- **Family Names:** `sanitize_text_field()` with length limits.
- **Settings:** Type-specific validation (integers, booleans, arrays).
- **Duplicate Detection:** Case-insensitive comparison between tags and categories on family save.

### **Access Control**
- **Admin Pages:** Require `manage_options` capability.
- **AJAX Endpoints:** `check_ajax_referer()` with plugin-specific nonces.
- **Bulk Processing:** Additional capability checks before execution.
- **Rollback Operations:** Admin confirmation required.

### **Database Security**
- **All Queries:** Use `$wpdb->prepare()` for dynamic content.
- **Options API:** Leverage WordPress built-in sanitization.
- **Transients:** Automatic expiration prevents data accumulation.
- **Rollback Data:** Automatic cleanup after 24 hours.

### **Rate Limiting**
- **AJAX Requests:** Maximum 60 requests per minute per user.
- **Processing Operations:** One bulk operation at a time.
- **Test Functionality:** Limited to 10 tests per minute.

---

## 🎨 **USER EXPERIENCE SPECIFICATIONS**

### **Validation & Error Handling**

#### **Duplicate Keyword Detection**
```
A keyword belonging to different families will trigger all tags and categories within them.
Eg: "eggs" keyword in both "Cakes" and "Main Dishes" families → both family outputs applied.
```

#### **Tag/Category Conflict Detection**
```
Duplicate Detection System:
- Triggers: On family save (not real-time)
- Comparison: Case-insensitive between tags and categories
- Behavior: Warn but allow user to save
- Processing: Apply both tag and category as configured

Example Warning:
┌─────────────────────────────────────────────────────────┐
│ ⚠️ CONFLICTS DETECTED                                  │
├─────────────────────────────────────────────────────────┤
│ The following names appear as both tags and categories:│
│ • "programming" (family: Technology)                   │
│ • "web-development" (family: Frontend)                 │
│                                                        │
│ During processing, the system will apply both the tag  │
│ and category for each conflict.                        │
│                                                        │
│                    [Save Anyway] [Cancel]              │
└─────────────────────────────────────────────────────────┘
```

#### **Processing Error Report**
```
┌─────────────────────────────────────────────────────────┐
│ 📋 BULK PROCESSING REPORT                              │
├─────────────────────────────────────────────────────────┤
│ Completed: September 3, 2025 at 2:47 PM                │
│ Duration: 23 minutes, 14 seconds                       │
│                                                        │
│ ✅ Success: 1,333 posts processed                      │
│ ❌ Errors: 3 posts failed                             │
│                                                        │
│ 📊 Classification Statistics:                          │
│ • Tags added: 4,824 • Year tags: 1,333                │
│ • Categories added: 2,156 • Auto-created: 47          │
│ • Average tags per post: 3.6                          │
│ • Average categories per post: 1.6                    │
│ • Tags truncated: 124 posts (e.g., Post #123: [writing, strategy]) │
│                                                        │
│ ⚠️ Conflict Resolutions:                              │
│ • "programming" applied as both tag and category: 892  │
│ • "technology" applied as both tag and category: 445   │
│                                                        │
│ ❌ Error Details:                                      │
│ • Post #456: Memory limit exceeded                    │
│   [Retry Individual] [Rollback This Post]             │
│ • Post #789: Invalid content encoding                 │
│   [Retry Individual] [Rollback This Post]             │
│ • Post #123: Database connection timeout              │
│   [Retry Individual] [Rollback This Post]             │
│                                                        │
│            [Download Full Log] [Rollback All Changes]  │
└─────────────────────────────────────────────────────────┘
```

#### **Test Your Setup**
- Displays all generated tags and categories for the test phrase, sorted alphabetically (with year tag first for tags). If duplicate keywords trigger multiple families, all tags/categories are listed without indicating contributing families; users must review family configurations to understand sources.

### **Quick Setup Workflow**

#### **Step 1: Quick Add**
- User types: `"wordpress, seo, blogging"`
- System response: `✅ Added 3 auto-classification families`

#### **Step 2: Test**
- User tests: `"My WordPress SEO guide"`
- System shows: `📋 Generated tags: 2024, seo, wordpress`

#### **Step 3: Apply**
- User clicks bulk processing → Immediate background execution begins.

### **Advanced User Workflow**

#### **Step 1: Create Advanced Family**
- Add "Technology" family
- Keywords: `javascript, js, react`
- Tags: `javascript, programming, web-dev`
- Categories: `Web Development, Technology, Programming`
- System warns: "programming" conflict (tag + category)
- User saves anyway
- Enable inheritance

#### **Step 2: Add Child Families**
- Create "JavaScript Frameworks" child family
- Inherits Technology parent tags and categories automatically
- Applies to both taxonomies

#### **Step 3: Test Complex Scenario**
- Test: `"React JavaScript tutorial"`
- Tags result: `2024, javascript, programming, react-framework, tutorial, web-dev`
- Categories result: `Web Development, Technology, Programming, Tutorials`
- Note: "programming" appears in both taxonomies as intended

---

## 📊 **PERFORMANCE SPECIFICATIONS**

### **Memory Management**
- **Component Budget:** Each processor component limited to 8MB.
- **Cache Limits:** Text processing cache limited to 1000 entries with LRU eviction.
- **Emergency Cleanup:** Automatic cleanup at 99% memory usage (5-minute threshold).
- **Batch Optimization:** Adaptive batch sizing based on available memory.

### **Processing Efficiency**
- **Single Post:** Target < 2 seconds per post including database operations.
- **Batch Processing:** Target 45-60 posts per minute depending on content complexity.
- **Background Processing:** Non-blocking with 3-minute timeout per batch.
- **Database Optimization:** Bulk database operations where possible.

### **Caching Strategy**
- **Configuration Cache:** In-memory caching of family configuration during processing.
- **Content Analysis Cache:** Cache normalized text and keyword matches.
- **WordPress Integration:** Leverage WordPress object cache where available.
- **Cache Invalidation:** Automatic cache clearing on configuration changes.

### **Resource Monitoring**
- **Memory Tracking:** Real-time memory usage monitoring during processing.
- **Time Limits:** Respect PHP execution time limits with graceful degradation.
- **Server Load:** Monitor system load and pause processing if necessary.
- **Database Performance:** Track query execution time and optimize slow queries.

---

## 🧪 **TESTING SPECIFICATIONS**

### **Validation Testing**
- **Duplicate Detection:** Ensure no duplicate keywords across families.
- **Conflict Detection:** Test case-insensitive tag/category name conflicts.
- **Hierarchy Validation:** Verify parent-child relationships valid for both taxonomies.
- **Tag Limits:** Respect maximum tags per post settings.
- **Category Requirements:** Never remove last category from posts.
- **Input Sanitization:** All user inputs properly sanitized.
- **WordPress Integration:** Test category hierarchy and auto-creation.

### **Processing Testing**
- **Single Post Processing:** Test all family types with various content.
- **Bulk Processing:** Test with large datasets (1000+ posts).
- **Error Recovery:** Test processing interruption and resumption.
- **Memory Limits:** Test behavior at memory threshold limits.

### **Integration Testing**
- **WordPress Versions:** Test compatibility with WordPress 6.0+.
- **PHP Versions:** Test compatibility with PHP 7.4-8.3.
- **Theme Compatibility:** Ensure no conflicts with popular themes.
- **Plugin Compatibility:** Test with major caching and SEO plugins.

### **Performance Testing**
- **Processing Speed:** Benchmark single post and bulk processing performance.
- **Memory Usage:** Monitor memory consumption during extended operations.
- **Database Load:** Measure database query impact during bulk operations.
- **Background Processing:** Verify non-blocking operation functionality.

---

## 🚀 **DEPLOYMENT SPECIFICATIONS**

### **WordPress.org Compliance**
- **Complete readme.txt:** Professional format with all required sections.
- **Internationalization:** Full i18n support with .pot file generation.
- **Security Standards:** All WordPress security requirements met.
- **Coding Standards:** WordPress PHP coding standards compliance.
- **Accessibility:** Admin interface meets WCAG 2.1 guidelines.

### **Installation Requirements**
- **WordPress Version:** 6.0 minimum, tested to latest.
- **PHP Version:** 7.4 minimum, optimized for 8.0+.
- **Memory Requirements:** 64MB minimum, 128MB recommended.
- **Server Compatibility:** Shared hosting compatible.

### **Update Mechanism**
- **Configuration Migration:** Automatic migration of settings between versions.
- **Database Updates:** Versioned schema updates with rollback capability.
- **Backward Compatibility:** Maintain API compatibility for extensions.
- **Update Notifications:** In-admin update notifications and changelogs.

---

## 📋 **FEATURE IMPLEMENTATION PRIORITY**

### **Phase 1: Core Functionality (COMPLETED)**
- ✅ Security vulnerabilities fixed
- ✅ Architectural refactoring completed
- ✅ WordPress standards compliance achieved
- ✅ Performance optimization implemented

### **Phase 2: Unified Strategy + Category Support (CURRENT)**
- [ ] Unified family configuration system (tags + categories)
- [ ] New admin interface with Quick Add for both taxonomies
- [ ] Enhanced processing logic with inheritance for both taxonomies
- [ ] Duplicate detection and conflict warning system
- [ ] Test functionality showing both tags and categories
- [ ] Post editor integration with separate taxonomy controls
- [ ] WordPress category hierarchy integration

### **Phase 3: Bulk Processing System**
- [ ] Background processing with WordPress Cron
- [ ] Admin notification system implementation
- [ ] Tag rollback system with granular control
- [ ] Progress monitoring and error reporting
- [ ] Performance monitoring and resource management

### **Phase 4: User Experience Polish**
- [ ] Advanced validation and error messaging
- [ ] Processing statistics and analytics
- [ ] Import/export functionality for configurations
- [ ] Advanced settings and customization options
- [ ] Comprehensive documentation and help system

---

## 🎯 **SUCCESS CRITERIA**

### **Functional Requirements**
- [ ] Users can create classification families without understanding technical implementation.
- [ ] Quick Add allows instant setup for tags and/or categories.
- [ ] Advanced users can create complex hierarchies affecting both taxonomies.
- [ ] Duplicate detection warns about tag/category name conflicts.
- [ ] Test functionality validates both tags and categories before deployment.
- [ ] Bulk processing handles thousands of posts for both taxonomies without timeout.
- [ ] All processing operations are reversible via rollback system.
- [ ] Category creation and WordPress hierarchy integration works seamlessly.

### **Performance Requirements**
- [ ] Single post processing completes in < 2 seconds.
- [ ] Bulk processing sustains 45+ posts per minute.
- [ ] Plugin operates within 64MB memory limits on shared hosting.
- [ ] Background processing doesn't impact site performance.
- [ ] Admin interface remains responsive during bulk operations.

### **User Experience Requirements**
- [ ] New users can set up basic auto-tagging in < 2 minutes.
- [ ] Advanced users can configure complex hierarchies intuitively.
- [ ] Error messages provide actionable guidance.
- [ ] Processing status is always visible and informative.
- [ ] All operations can be undone if results are unsatisfactory.

### **Technical Requirements**
- [ ] Plugin passes all WordPress security standards.
- [ ] Compatible with WordPress 6.0+ and PHP 7.4+.
- [ ] Handles edge cases gracefully without fatal errors.
- [ ] Database operations are optimized for performance.
- [ ] Background processing is reliable and resumable.

---

## 📚 **API DOCUMENTATION**

### **Filter Hooks**
```php
// Modify generated tags before application
apply_filters('autotag_generated