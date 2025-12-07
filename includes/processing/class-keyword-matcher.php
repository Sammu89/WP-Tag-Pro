<?php
/**
 * Keyword Matching Class  
 * Handles keyword and family matching logic - extracted from AutoTag_Processor
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoTag_KeywordMatcher {
    
    /**
     * Cache instance for performance
     */
    private $cache;
    
    /**
     * Constructor
     */
    public function __construct() {
        if (function_exists('wp_autotag_pro') && wp_autotag_pro()->cache) {
            $this->cache = wp_autotag_pro()->cache;
        }
    }
    
    /**
     * Find keyword matches in normalized content (Classic mode)
     */
    public function find_keyword_matches($content, $config) {
        if (empty($content) || empty($config['k'])) {
            return [];
        }
        
        $matches = [];
        
        foreach ($config['k'] as $keyword) {
            if (empty($keyword)) continue;
            
            $keyword_lower = strtolower($keyword);
            
            // Simple substring search (case-insensitive)
            if (strpos($content, $keyword_lower) !== false) {
                $matches[] = $keyword;
            }
        }
        
        return array_unique($matches);
    }
    
    /**
     * Find family matches in normalized content (Family mode)
     */
    public function find_family_matches($content, $config) {
        if (empty($content) || empty($config['f'])) {
            return [];
        }
        
        $matches = [];
        
        foreach ($config['f'] as $family_name => $keywords) {
            if (empty($keywords) || !is_array($keywords)) continue;
            
            $family_matched = false;
            
            foreach ($keywords as $keyword) {
                if (empty($keyword)) continue;
                
                $keyword_lower = strtolower($keyword);
                
                if (strpos($content, $keyword_lower) !== false) {
                    $family_matched = true;
                    break; // Found match in this family, no need to check more
                }
            }
            
            if ($family_matched) {
                $matches[] = $family_name;
            }
        }
        
        return array_unique($matches);
    }
    
    /**
     * Find matches using unified rules structure (v2.1.0)
     */
    public function find_unified_rule_matches($content, $config) {
        if (empty($content) || empty($config['rules'])) {
            return [];
        }
        
        $matches = [];
        
        foreach ($config['rules'] as $rule_id => $rule) {
            // Skip disabled rules
            if (isset($rule['enabled']) && !$rule['enabled']) {
                continue;
            }
            
            // Check if rule has keywords to match
            if (empty($rule['keywords'])) {
                continue;
            }
            
            $rule_matched = false;
            $matching_keywords = [];
            
            foreach ((array)$rule['keywords'] as $keyword) {
                if (empty($keyword)) continue;
                
                $keyword_lower = strtolower(trim($keyword));
                
                // Simple substring search (case-insensitive)
                if (strpos($content, $keyword_lower) !== false) {
                    $rule_matched = true;
                    $matching_keywords[] = $keyword;
                    
                    // For single keyword rules, we found our match
                    if (count($rule['keywords']) === 1) {
                        break;
                    }
                }
            }
            
            if ($rule_matched) {
                $matches[] = [
                    'rule_id' => $rule_id,
                    'rule' => $rule,
                    'matching_keywords' => $matching_keywords,
                    'match_type' => 'unified_rule'
                ];
            }
        }
        
        return $matches;
    }
    
    /**
     * Find all matches based on current mode
     * v2.1.0 - Enhanced to support unified rules
     */
    public function find_all_matches($normalized_content, $config, $mode) {
        // Try to get from cache first
        if ($this->cache && function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
            $config_hash = wp_autotag_pro()->config->get_current_hash();
            $cached_matches = $this->cache->get_keyword_matches($normalized_content, $config_hash);
            
            if ($cached_matches !== null) {
                return $cached_matches;
            }
        }
        
        $matches = [];
        
        // v2.1.0 - Check for unified rules first
        if (isset($config['rules']) && !empty($config['rules'])) {
            $matches = $this->find_unified_rule_matches($normalized_content, $config);
        } else {
            // Fallback to basic matching if no unified rules
            $keyword_matches = $this->find_keyword_matches($normalized_content, $config);
            $matches = array_merge($matches, $keyword_matches);
        }
        
        $result = $matches; // Already unique from find_unified_rule_matches
        
        // Cache the result for future use
        if ($this->cache && function_exists('wp_autotag_pro') && wp_autotag_pro()->config) {
            $config_hash = wp_autotag_pro()->config->get_current_hash();
            $this->cache->cache_keyword_matches($normalized_content, $config_hash, $result);
        }
        
        return $result;
    }
    
    /**
     * Get matching statistics for debugging/reporting
     */
    public function get_match_stats($normalized_content, $config, $mode) {
        $stats = [
            'content_length' => strlen($normalized_content),
            'word_count' => str_word_count($normalized_content),
            'mode' => $mode,
            'matches' => []
        ];
        
        // Get unified rule matches if available
        if (isset($config['rules']) && !empty($config['rules'])) {
            $unified_matches = $this->find_unified_rule_matches($normalized_content, $config);
            $stats['matches']['unified_rules'] = $unified_matches;
            $stats['match_count'] = count($unified_matches);
        } else {
            // Fallback to basic keyword matching
            $keyword_matches = $this->find_keyword_matches($normalized_content, $config);
            $stats['matches']['keywords'] = $keyword_matches;
            $stats['match_count'] = count($keyword_matches);
        }
        
        return $stats;
    }
    
    /**
     * Validate that content has minimum requirements for processing
     */
    public function should_process_content($content, $min_length = 10) {
        if (empty($content)) {
            return false;
        }
        
        // Check minimum content length
        if (strlen(trim($content)) < $min_length) {
            return false;
        }
        
        // Check that content has actual words, not just special characters
        if (str_word_count($content) < 2) {
            return false;
        }
        
        return true;
    }
}