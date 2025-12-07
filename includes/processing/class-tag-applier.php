<?php
/**
 * Tag Application Class
 * Handles WordPress tag application and management - extracted from AutoTag_Processor  
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_TagApplier {
    
    /**
     * Apply tags to a post with strategy (append or replace)
     */
    public function apply_tags_to_post($post_id, $new_tags, $strategy = 'a', $year_tag = null) {
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
                'message' => 'Error applying tags: ' . $e->getMessage(),
                'tags_applied' => []
            ];
        }
    }
    
    /**
     * Get existing tags for a post
     */
    public function get_existing_tags($post_id) {
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        
        if (is_wp_error($tags)) {
            return [];
        }
        
        return $tags;
    }
    
    /**
     * Build final tag list based on strategy
     */
    public function build_final_tag_list($new_tags, $year_tag, $existing_tags, $strategy) {
        $final_tags = [];
        
        // Always add year tag first if provided
        if (!empty($year_tag)) {
            $final_tags[] = $year_tag;
        }
        
        // Handle strategy
        if ($strategy === 'a' || $strategy === 'append') {
            // Append mode: keep existing tags, add new ones
            $final_tags = array_merge($final_tags, $existing_tags);
            $final_tags = array_merge($final_tags, $new_tags);
        } else {
            // Replace mode: only use new tags (and year tag)
            $final_tags = array_merge($final_tags, $new_tags);
        }
        
        // Remove duplicates and empty values
        $final_tags = array_filter($final_tags);
        $final_tags = array_unique($final_tags);
        
        // Convert all to strings and trim
        $final_tags = array_map('trim', array_map('strval', $final_tags));
        
        // Remove empty strings after trimming
        $final_tags = array_filter($final_tags);
        
        return array_values($final_tags); // Re-index array
    }
    
    /**
     * Generate year tag from post date
     */
    public function generate_year_tag($post_id) {
        $post = get_post($post_id);
        
        if (!$post || is_wp_error($post)) {
            return null;
        }
        
        $post_date = $post->post_date;
        if (empty($post_date) || $post_date === '0000-00-00 00:00:00') {
            return null;
        }
        
        return date('Y', strtotime($post_date));
    }
    
    /**
     * Convert keyword/family matches to actual tag names
     */
    public function convert_matches_to_tags($matches, $config, $mode) {
        if (empty($matches)) {
            return [];
        }
        
        $tags = [];
        
        if ($mode === 'c' || $mode === 'classic') {
            // Classic mode: matches ARE the tag names
            $tags = $matches;
        } elseif ($mode === 'f' || $mode === 'families') {
            // Family mode: convert family names to actual tags
            foreach ($matches as $family_name) {
                if (isset($config['f'][$family_name]) && is_array($config['f'][$family_name])) {
                    // Use the family name as the tag
                    $tags[] = $family_name;
                }
            }
        }
        
        return array_unique($tags);
    }
    
    /**
     * Preview tags that would be applied (without actually applying)
     */
    public function preview_tags($post_id, $new_tags, $strategy = 'a', $year_tag = null) {
        $existing_tags = $this->get_existing_tags($post_id);
        $final_tags = $this->build_final_tag_list($new_tags, $year_tag, $existing_tags, $strategy);
        
        return [
            'existing_tags' => $existing_tags,
            'new_tags' => $new_tags,
            'year_tag' => $year_tag,
            'final_tags' => $final_tags,
            'strategy' => $strategy,
            'tags_to_add' => array_diff($final_tags, $existing_tags),
            'tags_to_remove' => ($strategy === 'r') ? array_diff($existing_tags, $final_tags) : []
        ];
    }
    
    /**
     * Validate tags before application
     */
    public function validate_tags($tags, $max_tags = 15) {
        if (!is_array($tags)) {
            return [
                'valid' => false,
                'errors' => ['Tags must be an array']
            ];
        }
        
        $errors = [];
        
        if (count($tags) > $max_tags) {
            $errors[] = "Too many tags: " . count($tags) . " (max: {$max_tags})";
        }
        
        foreach ($tags as $tag) {
            if (!is_string($tag) && !is_numeric($tag)) {
                $errors[] = "Invalid tag type: " . gettype($tag);
            }
            
            if (strlen(trim($tag)) === 0) {
                $errors[] = "Empty tag found";
            }
            
            if (strlen($tag) > 100) {
                $errors[] = "Tag too long: '" . substr($tag, 0, 20) . "...' (max: 100 chars)";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'tag_count' => count($tags)
        ];
    }
}