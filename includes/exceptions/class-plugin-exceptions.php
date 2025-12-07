<?php
/**
 * Plugin-specific Exception Classes
 * Provides specific, helpful exceptions for different types of errors
 * Replaces generic Exception usage throughout the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base AutoTag exception class
 */
class AutoTag_Exception extends Exception {
    
    /**
     * Error context for debugging
     */
    protected $error_context = [];
    
    /**
     * User-friendly error message
     */
    protected $user_message = '';
    
    /**
     * Error suggestions for users
     */
    protected $suggestions = [];
    
    /**
     * Error code for categorization
     */
    protected $error_code = '';
    
    /**
     * Constructor with additional context
     */
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous);
        $this->error_context = $context;
        
        // Set default user message if not provided
        if (empty($this->user_message)) {
            $this->user_message = $message;
        }
    }
    
    /**
     * Get error context for debugging
     */
    public function getContext() {
        return $this->error_context;
    }
    
    /**
     * Get user-friendly message
     */
    public function getUserMessage() {
        return $this->user_message ?: $this->getMessage();
    }
    
    /**
     * Get error suggestions
     */
    public function getSuggestions() {
        return $this->suggestions;
    }
    
    /**
     * Get error code
     */
    public function getErrorCode() {
        return $this->error_code ?: 'GENERIC_ERROR';
    }
    
    /**
     * Set user-friendly message
     */
    public function setUserMessage($message) {
        $this->user_message = $message;
        return $this;
    }
    
    /**
     * Add suggestion for user
     */
    public function addSuggestion($suggestion) {
        $this->suggestions[] = $suggestion;
        return $this;
    }
    
    /**
     * Convert to array for API responses
     */
    public function toArray() {
        return [
            'message' => $this->getUserMessage(),
            'suggestions' => $this->getSuggestions(),
            'error_code' => $this->getErrorCode(),
            'context' => $this->getContext()
        ];
    }
}

/**
 * Configuration-related exceptions
 */
class AutoTag_ConfigException extends AutoTag_Exception {
    
    protected $error_code = 'CONFIG_ERROR';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'Configuration error detected.';
        $this->suggestions = [
            'Check your Auto-Tag Pro settings on the WordPress admin page',
            'Verify your Keywords are properly formatted',
            'Try resetting to default configuration if the problem persists'
        ];
    }
}

/**
 * Processing-related exceptions
 */
class AutoTag_ProcessingException extends AutoTag_Exception {
    
    protected $error_code = 'PROCESSING_ERROR';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'Error occurred during post processing.';
        $this->suggestions = [
            'Try processing the post again',
            'Check if the post content is valid and not corrupted',
            'Verify your WordPress installation is not experiencing issues'
        ];
    }
}

/**
 * Memory-related exceptions
 */
class AutoTag_MemoryException extends AutoTag_Exception {
    
    protected $error_code = 'MEMORY_EXCEEDED';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'Not enough memory to complete this operation.';
        $this->suggestions = [
            'Try processing fewer posts at once',
            'Increase PHP memory limit in your hosting settings',
            'Contact your hosting provider if memory issues persist',
            'Consider using bulk processing with smaller batch sizes'
        ];
    }
}

/**
 * Database-related exceptions
 */
class AutoTag_DatabaseException extends AutoTag_Exception {
    
    protected $error_code = 'DATABASE_ERROR';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'Database error occurred.';
        $this->suggestions = [
            'Check your WordPress database connection',
            'Verify database tables are not corrupted',
            'Contact your hosting provider if database issues persist',
            'Try again in a few moments'
        ];
    }
}

/**
 * Validation-related exceptions
 */
class AutoTag_ValidationException extends AutoTag_Exception {
    
    protected $error_code = 'VALIDATION_FAILED';
    protected $validation_errors = [];
    
    public function __construct($message = '', $validation_errors = [], $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        $this->validation_errors = $validation_errors;
        
        // Default user-friendly message
        $this->user_message = 'Invalid data provided.';
        $this->suggestions = [
            'Check the highlighted fields and correct any errors',
            'Ensure all required fields are filled',
            'Verify data format matches the expected format',
            'Try again after making corrections'
        ];
    }
    
    /**
     * Get validation errors
     */
    public function getValidationErrors() {
        return $this->validation_errors;
    }
    
    /**
     * Add validation error
     */
    public function addValidationError($field, $message) {
        $this->validation_errors[$field] = $message;
        return $this;
    }
    
    /**
     * Convert to array with validation errors
     */
    public function toArray() {
        $array = parent::toArray();
        $array['validation_errors'] = $this->getValidationErrors();
        return $array;
    }
}

/**
 * Network/API-related exceptions
 */
class AutoTag_NetworkException extends AutoTag_Exception {
    
    protected $error_code = 'NETWORK_ERROR';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'Network or connectivity error occurred.';
        $this->suggestions = [
            'Check your internet connection',
            'Try again in a few moments',
            'Verify external services are accessible',
            'Contact support if network issues persist'
        ];
    }
}

/**
 * Permission/Security-related exceptions
 */
class AutoTag_SecurityException extends AutoTag_Exception {
    
    protected $error_code = 'SECURITY_ERROR';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'Security or permission error.';
        $this->suggestions = [
            'Verify you have sufficient permissions for this action',
            'Log out and log back in to refresh your session',
            'Contact your site administrator if permission issues persist',
            'Check if your user role has the necessary capabilities'
        ];
    }
}

/**
 * Cache-related exceptions
 */
class AutoTag_CacheException extends AutoTag_Exception {
    
    protected $error_code = 'CACHE_ERROR';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'Cache system error.';
        $this->suggestions = [
            'Clear the plugin cache from the settings page',
            'Try the operation again',
            'Check if your server supports transient caching',
            'Disable caching temporarily if issues persist'
        ];
    }
}

/**
 * File system-related exceptions
 */
class AutoTag_FileSystemException extends AutoTag_Exception {
    
    protected $error_code = 'FILESYSTEM_ERROR';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'File system error occurred.';
        $this->suggestions = [
            'Check file and directory permissions',
            'Verify disk space is available',
            'Contact your hosting provider for file system issues',
            'Try again after ensuring proper file permissions'
        ];
    }
}

/**
 * Content/Data-related exceptions
 */
class AutoTag_ContentException extends AutoTag_Exception {
    
    protected $error_code = 'CONTENT_ERROR';
    
    public function __construct($message = '', $code = 0, Exception $previous = null, $context = []) {
        parent::__construct($message, $code, $previous, $context);
        
        // Default user-friendly message
        $this->user_message = 'Content processing error.';
        $this->suggestions = [
            'Check if the content contains valid text',
            'Verify content is not corrupted or malformed',
            'Try processing different content to isolate the issue',
            'Check for special characters that might cause issues'
        ];
    }
}