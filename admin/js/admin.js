/**
 * WordPress Auto-Tag Pro Admin JavaScript
 * Handles AJAX functionality, UI interactions, and real-time updates
 */

(function($) {
    'use strict';

    // Global variables
    let currentBulkQueue = null;
    let progressInterval = null;
    let configChanged = false;

    // Initialize when DOM is ready
    $(document).ready(function() {
        initializeAdmin();
    });

    /**
     * Initialize all admin functionality
     */
    function initializeAdmin() {
        initializeModeSelector();
        initializeYearTags();
        initializeConfigurationActions();
        initializeFamiliesInterface();
        initializeQuickSetup();
        initializeRulesManagement();
        initializePreview();
        initializeBulkOperations();
        initializeRollbackSystem();
        initializeMetaBox();
        initializeToolsPage();
        initializeFormValidation();
        initializeConfirmationDialogs();
        
        // Track unsaved changes
        trackConfigurationChanges();
        
        // Auto-save warning
        window.addEventListener('beforeunload', handleBeforeUnload);
    }

    /**
     * Mode selector functionality
     */
    function initializeModeSelector() {
        $('.autotag-mode-option input[type="radio"]').on('change', function() {
            const selectedMode = $(this).val();
            switchMode(selectedMode);
        });

        $('#convert-to-families').on('click', function() {
            if (confirm(autotagAdmin.strings.confirm_convert)) {
                convertMode('to_families');
            }
        });

        $('#convert-to-keywords').on('click', function() {
            if (confirm(autotagAdmin.strings.confirm_convert)) {
                convertMode('to_keywords');
            }
        });
    }

    /**
     * Switch between classic and families mode
     */
    function switchMode(mode) {
        if (configChanged && !confirm(autotagAdmin.strings.confirm_mode_switch)) {
            // Revert radio button selection
            $('input[name="autotag_mode"][value="' + (mode === 'families' ? 'classic' : 'families') + '"]').prop('checked', true);
            return;
        }

        // Update UI
        $('.autotag-mode-option').removeClass('active');
        $('input[name="autotag_mode"][value="' + mode + '"]').closest('.autotag-mode-option').addClass('active');

        // Show/hide mode interfaces
        if (mode === 'families') {
            $('#classic-mode-content').hide();
            $('#families-mode-content').show();
        } else {
            $('#families-mode-content').hide();
            $('#classic-mode-content').show();
        }

        // Send AJAX request to switch mode
        showLoading('Switching mode...');
        
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_switch_mode',
                mode: mode,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage(autotagAdmin.strings.mode_switched, 'success');
                    configChanged = false;
                    location.reload(); // Reload to update interface
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Convert between modes
     */
    function convertMode(direction) {
        showLoading('Converting configuration...');
        
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_convert_mode',
                direction: direction,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage(autotagAdmin.strings.converted_successfully, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Year tags functionality
     */
    function initializeYearTags() {
        $('#year-tags-enabled').on('change', function() {
            const enabled = $(this).is(':checked');
            
            if (enabled) {
                $('#enable-year-tags-retroactive').show();
            } else {
                $('#enable-year-tags-retroactive').hide();
            }
        });

        $('#enable-year-tags-retroactive').on('click', function() {
            if (confirm(autotagAdmin.strings.confirm_year_tags_retroactive)) {
                enableYearTagsRetroactive();
            }
        });
    }

    /**
     * Enable year tags retroactively
     */
    function enableYearTagsRetroactive() {
        showLoading('Enabling year tags and processing posts...');
        
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_enable_year_tags_retroactive',
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage('Year tags enabled and bulk processing started!', 'success');
                    if (response.data.queue_id) {
                        // Monitor the bulk processing
                        currentBulkQueue = response.data.queue_id;
                        startProgressMonitoring();
                    }
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Configuration actions
     */
    function initializeConfigurationActions() {
        $('#save-configuration').on('click', saveConfiguration);
        $('#import-configuration').on('click', importConfiguration);
        $('#export-configuration').on('click', exportConfiguration);
        $('#import-file').on('change', handleImportFile);
    }

    /**
     * Save configuration
     */
    function saveConfiguration() {
        const config = gatherConfiguration();
        
        if (!validateConfiguration(config)) {
            return;
        }

        showLoading('Saving configuration...');
        
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_save_config',
                config: config,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage(autotagAdmin.strings.config_saved, 'success');
                    configChanged = false;
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Gather configuration from form
     */
    function gatherConfiguration() {
        const config = {
            mode: $('input[name="autotag_mode"]:checked').val() || 'classic',
            year_tags: $('#year-tags-enabled').is(':checked'),
            year_strategy: $('input[name="year_strategy"]:checked').val() || 'replace',
            tag_strategy: $('input[name="tag_strategy"]:checked').val() || 'append'
        };

        if (config.mode === 'families') {
            config.families = {};
            $('.autotag-family-item').each(function() {
                const familyName = $(this).find('.family-name').text().trim();
                const keywords = $(this).find('.family-keywords-input').val().trim();
                
                if (familyName && keywords) {
                    config.families[familyName] = keywords;
                }
            });
        } else {
            config.keywords = $('#classic-keywords').val().trim();
        }

        return config;
    }

    /**
     * Validate configuration
     */
    function validateConfiguration(config) {
        if (config.mode === 'families') {
            if (Object.keys(config.families || {}).length === 0) {
                showMessage('Please add at least one family with keywords.', 'warning');
                return false;
            }
        } else {
            if (!config.keywords) {
                showMessage('Please add at least one keyword.', 'warning');
                return false;
            }
        }
        return true;
    }

    /**
     * Import configuration
     */
    function importConfiguration() {
        $('#import-file').click();
    }

    /**
     * Handle import file selection
     */
    function handleImportFile() {
        const file = this.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = JSON.parse(e.target.result);
                processImportData(data);
            } catch (error) {
                showMessage('Invalid JSON file format.', 'error');
            }
        };
        reader.readAsText(file);
    }

    /**
     * Process imported data
     */
    function processImportData(data) {
        showLoading('Importing configuration...');
        
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_import_config',
                data: JSON.stringify(data),
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage(autotagAdmin.strings.import_success, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Export configuration
     */
    function exportConfiguration() {
        showLoading('Preparing export...');
        
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_export_config',
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    downloadFile(response.data.data, response.data.filename);
                    showMessage('Configuration exported successfully!', 'success');
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Download file helper
     */
    function downloadFile(data, filename) {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }

    /**
     * Families interface functionality
     */
    function initializeFamiliesInterface() {
        $('#add-family').on('click', addFamily);
        $(document).on('click', '.remove-family', removeFamily);
        $(document).on('input', '.family-keywords-input', updateFamilyStats);
        $(document).on('input', '#classic-keywords', updateKeywordStats);
        
        // Update stats on page load
        updateFamilyStats();
        updateKeywordStats();
    }

    /**
     * Add new family
     */
    function addFamily() {
        const familyName = $('#new-family-name').val().trim();
        const keywords = $('#new-family-keywords').val().trim();

        if (!familyName) {
            showMessage('Please enter a family name.', 'warning');
            $('#new-family-name').focus();
            return;
        }

        if (!keywords) {
            showMessage('Please enter keywords for the family.', 'warning');
            $('#new-family-keywords').focus();
            return;
        }

        // Check if family already exists
        if ($('.family-name:contains("' + familyName + '")').length > 0) {
            showMessage('A family with this name already exists.', 'warning');
            $('#new-family-name').focus();
            return;
        }

        showLoading('Adding family...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_add_family',
                family_name: familyName,
                keywords: keywords,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    // Add family to UI
                    const familyHtml = createFamilyItemHTML(familyName, keywords);
                    $('#families-list').append(familyHtml);
                    
                    // Clear form
                    $('#new-family-name').val('');
                    $('#new-family-keywords').val('');
                    
                    showMessage(autotagAdmin.strings.family_added, 'success');
                    updateFamilyStats();
                    configChanged = true;
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Create family item HTML
     */
    function createFamilyItemHTML(familyName, keywords) {
        const keywordsArray = keywords.split(/[,\n]/).map(k => k.trim()).filter(k => k);
        const keywordsString = keywordsArray.join(', ');
        
        return `
            <div class="autotag-family-item" data-family="${familyName}">
                <div class="family-header">
                    <h4 class="family-name">${familyName}</h4>
                    <button type="button" class="remove-family button-link-delete">
                        ${autotagAdmin.strings.remove || 'Remove'}
                    </button>
                </div>
                <div class="family-keywords">
                    <textarea class="family-keywords-input" rows="2">${keywordsString}</textarea>
                    <p class="description">${keywordsArray.length} keywords</p>
                </div>
            </div>
        `;
    }

    /**
     * Remove family
     */
    function removeFamily() {
        const familyItem = $(this).closest('.autotag-family-item');
        const familyName = familyItem.data('family');

        if (confirm('Are you sure you want to remove the "' + familyName + '" family?')) {
            showLoading('Removing family...');

            $.ajax({
                url: autotagAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'autotag_remove_family',
                    family_name: familyName,
                    nonce: autotagAdmin.nonce
                },
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        familyItem.fadeOut(300, function() {
                            $(this).remove();
                            updateFamilyStats();
                        });
                        showMessage(autotagAdmin.strings.family_removed, 'success');
                        configChanged = true;
                    } else {
                        showMessage(response.data || autotagAdmin.strings.error, 'error');
                    }
                },
                error: function() {
                    hideLoading();
                    showMessage(autotagAdmin.strings.error, 'error');
                }
            });
        }
    }

    /**
     * Update family statistics
     */
    function updateFamilyStats() {
        let totalFamilies = $('.autotag-family-item').length;
        let totalKeywords = 0;

        $('.family-keywords-input').each(function() {
            const content = $(this).val() || '';
            const keywords = content.split(/[,\n]/).map(k => k.trim()).filter(k => k);
            totalKeywords += keywords.length;
            
            // Update individual family stats
            $(this).siblings('.description').text(keywords.length + ' keywords');
        });

        $('.autotag-families-stats').html(`
            <span>Families: ${totalFamilies}</span>
            <span>Total Keywords: ${totalKeywords} / 1000</span>
        `);
    }

    /**
     * Update keyword statistics
     */
    function updateKeywordStats() {
        const $element = $('#classic-keywords');
        if (!$element.length) {
            return; // Element doesn't exist on this page
        }
        
        const content = $element.val() || '';
        const keywords = content.split('\n').map(k => k.trim()).filter(k => k);
        
        $('.autotag-keywords-stats').html(`
            <span>Keywords: ${keywords.length} / 1000</span>
        `);
    }

    /**
     * Preview functionality
     */
    function initializePreview() {
        $('#preview-tags').on('click', previewTags);
        $('#test-sample-content').on('click', testSampleContent);
        $('#test-existing-post').on('click', testExistingPost);
    }

    /**
     * Preview tags for a post
     */
    function previewTags() {
        const postId = $('#preview-post-id').val();
        
        if (!postId || postId < 1) {
            showMessage('Please enter a valid post ID.', 'warning');
            return;
        }

        const $results = $('#preview-results');
        $results.html('<div class="autotag-loading"></div> Loading preview...').show();

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_preview_post',
                post_id: postId,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayPreviewResults(response.data);
                } else {
                    $results.html('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function() {
                $results.html('Error: Failed to load preview');
            }
        });
    }

    /**
     * Display preview results
     */
    function displayPreviewResults(data) {
        let html = '<h4>Preview Results</h4>';
        
        if (data.error) {
            html += '<p class="error">Error: ' + data.error + '</p>';
        } else {
            html += '<div class="preview-section">';
            
            if (data.year_tag) {
                html += '<p><strong>Year Tag:</strong> <span class="preview-tag year-tag">' + data.year_tag + '</span></p>';
            }
            
            if (data.matches && data.matches.length > 0) {
                html += '<p><strong>New Tags:</strong></p>';
                html += '<div class="preview-tags">';
                data.matches.forEach(function(tag) {
                    html += '<span class="preview-tag">' + tag + '</span>';
                });
                html += '</div>';
            } else {
                html += '<p><em>No new tags would be added.</em></p>';
            }
            
            if (data.existing_tags && data.existing_tags.length > 0) {
                html += '<p><strong>Existing Tags:</strong></p>';
                html += '<div class="preview-tags">';
                data.existing_tags.forEach(function(tag) {
                    html += '<span class="preview-tag existing">' + tag + '</span>';
                });
                html += '</div>';
            }
            
            if (data.final_tags && data.final_tags.length > 0) {
                html += '<p><strong>Final Tags (ordered):</strong></p>';
                html += '<div class="preview-tags">';
                data.final_tags.forEach(function(tag, index) {
                    let classes = 'preview-tag';
                    if (data.year_tag && tag === data.year_tag) {
                        classes += ' year-tag';
                    } else if (data.existing_tags && data.existing_tags.includes(tag)) {
                        classes += ' existing';
                    }
                    html += '<span class="' + classes + '">' + (index + 1) + '. ' + tag + '</span>';
                });
                html += '</div>';
            }
            
            html += '<p><small><strong>Mode:</strong> ' + (data.mode || 'classic') + '</small></p>';
            html += '<p><small><strong>Strategy:</strong> ' + (data.tag_strategy === 'r' ? 'replace' : 'append') + '</small></p>';
            
            html += '</div>';
        }
        
        $('#preview-results').html(html);
    }

    /**
     * Test sample content
     */
    function testSampleContent() {
        showLoading('Testing sample content...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_test_performance',
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    displayPreviewResults(response.data);
                    $('#preview-results').show();
                } else {
                    showMessage('Test failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage('Error: Test request failed', 'error');
            }
        });
    }

    /**
     * Test existing post
     */
    function testExistingPost() {
        const postId = $('#preview-post-id').val();
        
        if (!postId || postId < 1) {
            showMessage('Please enter a valid post ID.', 'warning');
            return;
        }

        showLoading('Testing existing post...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_preview_single_post',
                post_id: postId,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    displayPreviewResults(response.data);
                    $('#preview-results').show();
                } else {
                    showMessage('Test failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage('Error: Test request failed', 'error');
            }
        });
    }

    /**
     * Bulk operations functionality
     */
    function initializeBulkOperations() {
        $('.autotag-bulk-buttons button').on('click', function() {
            const action = $(this).data('action');
            if (action) {
                startBulkProcess(action);
            }
        });

        $('#autotag-cancel-bulk').on('click', cancelBulkProcess);
        
        // Add missing button handlers
        $('#check-conflicts').on('click', function() {
            checkConfigConflicts();
        });
        
        $('#autotag-cleanup-orphaned').on('click', function() {
            if (confirm('Are you sure you want to cleanup orphaned metadata? This action cannot be undone.')) {
                performCleanupAction('cleanup_orphaned');
            }
        });
        
        $('#autotag-clear-cache').on('click', function() {
            if (confirm('Are you sure you want to clear the plugin cache?')) {
                performCleanupAction('clear_cache');
            }
        });
        
        $('#autotag-reset-processing').on('click', function() {
            if (confirm('Are you sure you want to reset all processing data? This will clear all processing history.')) {
                performCleanupAction('reset_processing');
            }
        });
        
        $('#run-debug').on('click', function() {
            runPostDebug();
        });
    }

    /**
     * Start bulk processing
     */
    function startBulkProcess(action) {
        if (currentBulkQueue) {
            if (!confirm('Another bulk process is running. Cancel it and start a new one?')) {
                return;
            }
            cancelBulkProcess();
        }

        if (!confirm(autotagAdmin.strings.confirm_bulk)) {
            return;
        }

        const options = {
            bulk_action: action,
            batch_size: 50, // Default batch size
            include_year_tags: $('#year-tags-enabled').is(':checked'),
            force_reprocess: action === 'all'
        };

        showLoading('Starting bulk processing...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: $.extend({
                action: 'autotag_start_bulk_process',
                nonce: autotagAdmin.nonce
            }, options),
            success: function(response) {
                hideLoading();
                if (response.success) {
                    currentBulkQueue = response.data.queue_id;
                    showMessage('Bulk processing started!', 'success');
                    startProgressMonitoring();
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Start progress monitoring
     */
    function startProgressMonitoring() {
        if (!currentBulkQueue) return;

        $('#autotag-bulk-progress').show();
        $('#autotag-bulk-results').hide();

        progressInterval = setInterval(updateProgress, 2000);
        updateProgress(); // Initial update
    }

    /**
     * Update progress
     */
    function updateProgress() {
        if (!currentBulkQueue) {
            stopProgressMonitoring();
            return;
        }

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_get_bulk_progress',
                queue_id: currentBulkQueue,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayProgress(response.data);
                    
                    if (response.data.status === 'completed' || response.data.status === 'cancelled' || response.data.status === 'error') {
                        stopProgressMonitoring();
                        displayResults(response.data);
                    }
                } else {
                    stopProgressMonitoring();
                    showMessage('Error getting progress: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                stopProgressMonitoring();
                showMessage('Error monitoring progress', 'error');
            }
        });
    }

    /**
     * Display progress
     */
    function displayProgress(data) {
        const percentage = Math.min(100, data.progress_percentage || 0);
        
        $('.autotag-progress-fill').css('width', percentage + '%');
        $('.autotag-progress-text').html(`
            Processing: ${data.processed_posts} / ${data.total_posts} posts (${percentage.toFixed(1)}%)<br>
            Tags Added: ${data.tags_added || 0} | Errors: ${data.errors || 0}
        `);
    }

    /**
     * Stop progress monitoring
     */
    function stopProgressMonitoring() {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
        currentBulkQueue = null;
    }

    /**
     * Display results
     */
    function displayResults(data) {
        $('#autotag-bulk-progress').hide();
        
        let html = '<h4>Processing Complete</h4>';
        html += '<div class="autotag-results-summary">';
        html += '<p><strong>Status:</strong> ' + (data.status || 'Unknown') + '</p>';
        html += '<p><strong>Posts Processed:</strong> ' + (data.processed_posts || 0) + ' / ' + (data.total_posts || 0) + '</p>';
        html += '<p><strong>Tags Added:</strong> ' + (data.tags_added || 0) + '</p>';
        
        if (data.year_tags_added) {
            html += '<p><strong>Year Tags Added:</strong> ' + data.year_tags_added + '</p>';
        }
        
        if (data.families_matched) {
            html += '<p><strong>Families Matched:</strong> ' + data.families_matched + '</p>';
        }
        
        if (data.errors && data.errors > 0) {
            html += '<p class="error"><strong>Errors:</strong> ' + data.errors + '</p>';
        }
        
        const duration = data.last_update - data.start_time;
        html += '<p><strong>Duration:</strong> ' + formatDuration(duration) + '</p>';
        html += '</div>';
        
        $('#autotag-bulk-results .autotag-results-content').html(html);
        $('#autotag-bulk-results').show();
    }

    /**
     * Cancel bulk process
     */
    function cancelBulkProcess() {
        if (!currentBulkQueue) return;

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_cancel_bulk_process',
                queue_id: currentBulkQueue,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('Bulk processing cancelled.', 'info');
                    stopProgressMonitoring();
                } else {
                    showMessage('Error cancelling process: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                showMessage('Error cancelling process', 'error');
            }
        });
    }

    /**
     * Meta box functionality - Enhanced for v2.1.0 Post Editor Integration
     */
    function initializeMetaBox() {
        // New Generate button with dual taxonomy controls
        $('#autotag-generate').on('click', function() {
            const postId = $(this).data('post-id');
            const taxonomySelection = $('#autotag-taxonomy-selection').val();
            generatePostTaxonomies(postId, taxonomySelection);
        });

        // Legacy preview button support
        $('#autotag-preview-post').on('click', function() {
            const postId = $(this).data('post-id');
            previewPostTags(postId);
        });

        // Enhanced Apply buttons with strategy support
        $('#apply-tags').on('click', function() {
            const postId = $(this).data('post-id');
            const strategy = $('input[name="tag_strategy"]:checked').val() || 'merge';
            applyTaxonomy(postId, 'tags', strategy);
        });

        $('#apply-categories').on('click', function() {
            const postId = $(this).data('post-id');
            const strategy = $('input[name="category_strategy"]:checked').val() || 'merge';
            applyTaxonomy(postId, 'categories', strategy);
        });

        $('#apply-both').on('click', function() {
            const postId = $(this).data('post-id');
            const tagStrategy = $('input[name="tag_strategy"]:checked').val() || 'merge';
            const categoryStrategy = $('input[name="category_strategy"]:checked').val() || 'merge';
            applyBothTaxonomies(postId, tagStrategy, categoryStrategy);
        });

        // Legacy process button support
        $('#autotag-process-post').on('click', function() {
            const postId = $(this).data('post-id');
            processSinglePost(postId);
        });

        // Max tags checkbox handler
        $('#max-tags-enabled').on('change', function() {
            $('#max-tags-value').prop('disabled', !$(this).is(':checked'));
        });
    }

    /**
     * Preview post tags in meta box
     */
    function previewPostTags(postId) {
        const $preview = $('#autotag-post-preview');
        $preview.html('<div class="autotag-loading"></div> Loading preview...').show();

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_preview_single_post',
                post_id: postId,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayMetaBoxPreview(response.data);
                } else {
                    $preview.html('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function() {
                $preview.html('Error: Failed to load preview');
            }
        });
    }

    /**
     * Display meta box preview
     */
    function displayMetaBoxPreview(data) {
        let html = '';
        
        if (data.new_tags && data.new_tags.length > 0) {
            html += '<strong>New tags:</strong> ' + data.new_tags.join(', ') + '<br>';
        } else {
            html += '<em>No new tags would be added.</em><br>';
        }
        
        if (data.existing_tags && data.existing_tags.length > 0) {
            html += '<strong>Existing:</strong> ' + data.existing_tags.join(', ');
        }
        
        $('#autotag-post-preview').html(html);
    }

    /**
     * Process single post
     */
    function processSinglePost(postId) {
        showLoading('Processing post...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_process_single_post',
                post_id: postId,
                force_reprocess: true,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage('Post processed successfully! Added ' + (response.data.tags_added || 0) + ' tags.', 'success');
                    // Refresh meta box
                    location.reload();
                } else {
                    showMessage('Error: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage('Error processing post', 'error');
            }
        });
    }

    /**
     * Tools page functionality
     */
    function initializeToolsPage() {
        // Maintenance tools
        $('#autotag-cleanup-orphaned').on('click', cleanupOrphanedMeta);
        $('#autotag-reset-processing').on('click', resetProcessing);
        $('#autotag-clear-cache').on('click', clearCache);
        
        // Debug tools
        $('#autotag-debug-post').on('click', function() {
            $('.autotag-debug-controls').slideToggle();
        });
        
        $('#run-debug').on('click', runDebug);
    }

    /**
     * Cleanup orphaned meta
     */
    function cleanupOrphanedMeta() {
        if (!confirm('This will clean up orphaned meta data. Continue?')) {
            return;
        }

        showLoading('Cleaning up...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_cleanup_orphaned',
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage(response.data.message || 'Cleanup completed', 'success');
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Reset processing status
     */
    function resetProcessing() {
        if (!confirm('This will reset all processing status to pending. This action cannot be undone. Continue?')) {
            return;
        }

        showLoading('Resetting...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_reset_processing',
                confirm: 'yes',
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage('Processing status reset successfully', 'success');
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Clear cache
     */
    function clearCache() {
        showLoading('Clearing cache...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_clear_cache',
                cache_type: 'all',
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage('Cache cleared successfully', 'success');
                } else {
                    showMessage(response.data || autotagAdmin.strings.error, 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage(autotagAdmin.strings.error, 'error');
            }
        });
    }

    /**
     * Run debug on post
     */
    function runDebug() {
        const postId = $('#debug-post-id').val();
        
        if (!postId) {
            showMessage('Please enter a post ID', 'warning');
            return;
        }

        $('#autotag-debug-results').show().text('Running debug analysis...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_debug_post',
                post_id: postId,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#autotag-debug-results').text(JSON.stringify(response.data, null, 2));
                } else {
                    $('#autotag-debug-results').text('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function() {
                $('#autotag-debug-results').text('AJAX error occurred');
            }
        });
    }

    /**
     * Form validation
     */
    function initializeFormValidation() {
        // Validate family names
        $('#new-family-name').on('input', function() {
            const value = $(this).val();
            const invalid = /[<>\"'&]/.test(value);
            
            if (invalid) {
                $(this).addClass('invalid');
                showMessage('Family names cannot contain HTML characters', 'warning');
            } else {
                $(this).removeClass('invalid');
            }
        });

        // Validate keyword limits
        $('#classic-keywords').on('input', function() {
            const keywords = $(this).val().split('\n').filter(k => k.trim());
            
            if (keywords.length > 1000) {
                showMessage('Maximum 1000 keywords allowed', 'warning');
            }
        });
    }

    /**
     * Confirmation dialogs
     */
    function initializeConfirmationDialogs() {
        // Add confirmation to destructive actions
        $('.destructive-action').on('click', function(e) {
            if (!confirm('Are you sure? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }
        });
    }

    /**
     * Track configuration changes
     */
    function trackConfigurationChanges() {
        // Track changes in inputs
        $(document).on('input change', 'input, textarea, select', function() {
            if ($(this).closest('.autotag-configuration-section').length > 0) {
                configChanged = true;
            }
        });
    }

    /**
     * Handle before unload warning
     */
    function handleBeforeUnload(e) {
        if (configChanged) {
            const message = 'You have unsaved configuration changes. Are you sure you want to leave?';
            e.returnValue = message;
            return message;
        }
    }

    /**
     * Utility functions
     */

    /**
     * Show loading state
     */
    function showLoading(message) {
        message = message || autotagAdmin.strings.processing;
        
        if ($('#autotag-loading-overlay').length === 0) {
            $('body').append('<div id="autotag-loading-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:999999;display:flex;align-items:center;justify-content:center;color:white;font-size:16px;"><div><div class="autotag-loading"></div>' + message + '</div></div>');
        } else {
            $('#autotag-loading-overlay').show().find('div').html('<div class="autotag-loading"></div>' + message);
        }
    }

    /**
     * Hide loading state
     */
    function hideLoading() {
        $('#autotag-loading-overlay').hide();
    }

    /**
     * Show message
     */
    function showMessage(message, type) {
        type = type || 'info';
        
        const $message = $('<div class="autotag-message ' + type + '">' + message + '</div>');
        
        // Remove existing messages
        $('.autotag-message').remove();
        
        // Add new message
        $('.autotag-admin-content').prepend($message);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $message.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
        
        // Scroll to top to show message
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    /**
     * Format duration
     */
    function formatDuration(seconds) {
        if (seconds < 60) {
            return seconds + ' seconds';
        } else if (seconds < 3600) {
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            return minutes + 'm ' + remainingSeconds + 's';
        } else {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            return hours + 'h ' + minutes + 'm';
        }
    }

    /**
     * Debounce function for input handlers
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Initialize Quick Setup functionality
     */
    function initializeQuickSetup() {
        // Handle taxonomy enablement
        $('#enable-tags, #enable-categories').on('change', function() {
            const tagsEnabled = $('#enable-tags').is(':checked');
            const categoriesEnabled = $('#enable-categories').is(':checked');
            
            // Update auto-create checkboxes
            $('#auto-create-tags').prop('checked', tagsEnabled);
            $('#auto-create-categories').prop('checked', categoriesEnabled);
            
            // Show/hide auto-create options based on selection
            if (tagsEnabled || categoriesEnabled) {
                $('.auto-create-options').show();
            } else {
                $('.auto-create-options').hide();
            }
        });
        
        // Handle simple keywords addition
        $('#add-simple-keywords').on('click', function() {
            const keywords = $('#simple-keywords').val().trim();
            const tagsEnabled = $('#auto-create-tags').is(':checked');
            const categoriesEnabled = $('#auto-create-categories').is(':checked');
            
            if (!keywords) {
                showMessage('Please enter keywords to add.', 'error');
                return;
            }
            
            if (!tagsEnabled && !categoriesEnabled) {
                showMessage('Please select at least one taxonomy to create.', 'error');
                return;
            }
            
            // Parse keywords
            const keywordArray = keywords.split(',').map(k => k.trim()).filter(k => k.length > 0);
            
            if (keywordArray.length === 0) {
                showMessage('Please enter valid keywords separated by commas.', 'error');
                return;
            }
            
            // Show loading state
            const $button = $(this);
            const originalText = $button.text();
            $button.prop('disabled', true).text('Adding...');
            
            // Send AJAX request to add keywords
            $.ajax({
                url: autotagAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'autotag_add_simple_keywords',
                    keywords: keywordArray,
                    create_tags: tagsEnabled,
                    create_categories: categoriesEnabled,
                    nonce: autotagAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success feedback
                        const feedback = `✅ Added ${response.data.count} auto-classification families`;
                        $('#keywords-added-feedback .feedback-text').text(feedback);
                        $('#keywords-added-feedback').show();
                        
                        // Clear input
                        $('#simple-keywords').val('');
                        
                        // Show success message
                        showMessage(`Successfully added ${response.data.count} keywords as ${response.data.taxonomies.join(' and ')}.`, 'success');
                        
                        // Refresh page after short delay to show new rules
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showMessage(response.data || 'Failed to add keywords.', 'error');
                    }
                },
                error: function() {
                    showMessage('An error occurred while adding keywords.', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Handle save configuration
        $('#save-quick-setup').on('click', function() {
            const tagsEnabled = $('#enable-tags').is(':checked');
            const categoriesEnabled = $('#enable-categories').is(':checked');
            
            if (!tagsEnabled && !categoriesEnabled) {
                showMessage('Please enable at least one taxonomy.', 'error');
                return;
            }
            
            // Show loading state
            const $button = $(this);
            const originalText = $button.text();
            $button.prop('disabled', true).text('Saving...');
            
            // Send AJAX request to save configuration
            $.ajax({
                url: autotagAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'autotag_save_quick_setup',
                    tags_enabled: tagsEnabled,
                    categories_enabled: categoriesEnabled,
                    nonce: autotagAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showMessage('Configuration saved successfully!', 'success');
                    } else {
                        showMessage(response.data || 'Failed to save configuration.', 'error');
                    }
                },
                error: function() {
                    showMessage('An error occurred while saving configuration.', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    }
    
    /**
     * Initialize Rules Management functionality
     */
    function initializeRulesManagement() {
        // Handle add new rule button
        $('#add-new-rule').on('click', function() {
            openRuleModal();
        });
        
        // Handle edit rule buttons
        $(document).on('click', '.edit-rule', function() {
            const ruleId = $(this).closest('.rule-row').data('rule-id');
            openRuleModal(ruleId);
        });
        
        // Handle delete rule buttons
        $(document).on('click', '.delete-rule', function() {
            const ruleId = $(this).closest('.rule-row').data('rule-id');
            const ruleName = $(this).closest('.rule-row').find('.rule-name').text() || 'this rule';
            
            if (confirm(`Are you sure you want to delete ${ruleName}? This action cannot be undone.`)) {
                deleteRule(ruleId);
            }
        });
        
        // Handle modal close
        $('.modal-close, #cancel-rule').on('click', function() {
            closeRuleModal();
        });
        
        // Handle form validation
        $('#rule-apply-tags, #rule-apply-categories').on('change', function() {
            validateRuleForm();
        });
        
        // Handle save rule
        $('#save-rule').on('click', function() {
            saveRule();
        });
        
        // Handle parent family selection
        $('#rule-parent').on('change', function() {
            const parentId = $(this).val();
            if (parentId) {
                // Load parent family details for inheritance
                loadParentFamilyDetails(parentId);
            }
        });
        
        // Handle inheritance checkbox
        $('#rule-inheritance').on('change', function() {
            const hasChildren = $(this).is(':checked');
            if (hasChildren) {
                showMessage('Inheritance enabled. Child families will inherit tags and categories from this family.', 'info');
            }
        });
    }
    
    /**
     * Open rule editor modal
     */
    function openRuleModal(ruleId = null) {
        const $modal = $('#rule-editor-modal');
        const $form = $('#rule-form');
        
        if (ruleId) {
            // Edit existing rule
            loadRuleData(ruleId);
            $('#modal-title').text('Edit Existing Family');
        } else {
            // Add new rule
            $form[0].reset();
            $('#rule-id').val('');
            $('#modal-title').text('Add New Family');
        }
        
        $modal.show();
        $('body').addClass('modal-open');
    }
    
    /**
     * Close rule editor modal
     */
    function closeRuleModal() {
        $('#rule-editor-modal').hide();
        $('body').removeClass('modal-open');
        $('#conflict-warnings').hide();
        $('#validation-warning').hide();
    }
    
    /**
     * Load rule data for editing
     */
    function loadRuleData(ruleId) {
        // Show loading state
        $('#save-rule').prop('disabled', true).text('Loading...');
        
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_get_rule',
                rule_id: ruleId,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    const rule = response.data;
                    
                    // Populate form fields
                    $('#rule-id').val(rule.id);
                    $('#rule-name').val(rule.name);
                    $('#rule-keywords').val(rule.keywords.join(', '));
                    $('#rule-apply-tags').prop('checked', rule.tags && rule.tags.length > 0);
                    $('#rule-tags').val(rule.tags ? rule.tags.join(', ') : '');
                    $('#rule-apply-categories').prop('checked', rule.categories && rule.categories.length > 0);
                    $('#rule-categories').val(rule.categories ? rule.categories.join(', ') : '');
                    $('#rule-parent').val(rule.parent_id || '');
                    $('#rule-inheritance').prop('checked', rule.inheritance_enabled || false);
                    $('#rule-enabled').prop('checked', rule.enabled !== false);
                    $('#rule-case-sensitive').prop('checked', rule.case_sensitive || false);
                    
                    // Show conflicts if any
                    if (rule.conflicts && rule.conflicts.length > 0) {
                        showConflictWarnings(rule.conflicts);
                    }
                    
                    validateRuleForm();
                } else {
                    showMessage(response.data || 'Failed to load rule data.', 'error');
                }
            },
            error: function() {
                showMessage('An error occurred while loading rule data.', 'error');
            },
            complete: function() {
                $('#save-rule').prop('disabled', false).text('Save Family');
            }
        });
    }
    
    /**
     * Validate rule form
     */
    function validateRuleForm() {
        const tagsEnabled = $('#rule-apply-tags').is(':checked');
        const categoriesEnabled = $('#rule-apply-categories').is(':checked');
        const $warning = $('#validation-warning');
        
        if (!tagsEnabled && !categoriesEnabled) {
            $warning.show();
            $('#save-rule').prop('disabled', true);
        } else {
            $warning.hide();
            $('#save-rule').prop('disabled', false);
        }
    }
    
    /**
     * Show conflict warnings
     */
    function showConflictWarnings(conflicts) {
        const $warnings = $('#conflict-warnings');
        let warningHtml = '<h4>⚠️ CONFLICTS DETECTED</h4>';
        warningHtml += '<p>The following names appear as both tags and categories:</p>';
        warningHtml += '<ul>';
        
        conflicts.forEach(conflict => {
            warningHtml += `<li>"${conflict}" (tag + category)</li>`;
        });
        
        warningHtml += '</ul>';
        warningHtml += '<p>During processing, the system will apply both the tag and category for each conflict.</p>';
        
        $warnings.html(warningHtml).show();
    }
    
    /**
     * Load parent family details
     */
    function loadParentFamilyDetails(parentId) {
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_get_parent_family',
                parent_id: parentId,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    const parent = response.data;
                    if (parent.categories && parent.categories.length > 1) {
                        showMessage(`Warning: Parent family has multiple categories. Only the first ("${parent.categories[0]}") will be used as parent category for child categories. Adjust in WordPress Categories page if needed.`, 'warning');
                    }
                }
            }
        });
    }
    
    /**
     * Save rule
     */
    function saveRule() {
        if (!$('#save-rule').prop('disabled')) {
            const formData = {
                action: 'autotag_save_rule',
                rule_id: $('#rule-id').val(),
                rule_name: $('#rule-name').val(),
                rule_keywords: $('#rule-keywords').val(),
                rule_apply_tags: $('#rule-apply-tags').is(':checked'),
                rule_tags: $('#rule-tags').val(),
                rule_apply_categories: $('#rule-apply-categories').is(':checked'),
                rule_categories: $('#rule-categories').val(),
                rule_parent: $('#rule-parent').val(),
                rule_inheritance: $('#rule-inheritance').is(':checked'),
                rule_enabled: $('#rule-enabled').is(':checked'),
                rule_case_sensitive: $('#rule-case-sensitive').is(':checked'),
                nonce: autotagAdmin.nonce
            };
            
            // Validate required fields
            if (!formData.rule_name.trim()) {
                showMessage('Family name is required.', 'error');
                return;
            }
            
            if (!formData.rule_keywords.trim()) {
                showMessage('Keywords are required.', 'error');
                return;
            }
            
            // Show loading state
            const $button = $('#save-rule');
            const originalText = $button.text();
            $button.prop('disabled', true).text('Saving...');
            
            $.ajax({
                url: autotagAdmin.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        showMessage('Family saved successfully!', 'success');
                        closeRuleModal();
                        // Refresh page to show updated rules
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        showMessage(response.data || 'Failed to save family.', 'error');
                    }
                },
                error: function() {
                    showMessage('An error occurred while saving family.', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        }
    }
    
    /**
     * Delete rule
     */
    function deleteRule(ruleId) {
        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_delete_rule',
                rule_id: ruleId,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('Rule deleted successfully!', 'success');
                    // Remove from DOM
                    $(`.rule-row[data-rule-id="${ruleId}"]`).fadeOut(300, function() {
                        $(this).remove();
                        // Check if any rules remain
                        if ($('.rule-row').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    showMessage(response.data || 'Failed to delete rule.', 'error');
                }
            },
            error: function() {
                showMessage('An error occurred while deleting rule.', 'error');
            }
        });
    }

    /**
     * Generate post taxonomies with dual taxonomy controls (v2.1.0)
     */
    function generatePostTaxonomies(postId, taxonomySelection) {
        const $preview = $('#autotag-post-preview');
        $preview.html('<div class="autotag-loading"></div> Generating taxonomies...').show();

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_generate_post',
                post_id: postId,
                taxonomy_selection: taxonomySelection,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayEnhancedMetaBoxPreview(response.data);
                    showApplyButtons(response.data);
                    displayWarnings(response.data.warnings);
                } else {
                    $preview.html('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function() {
                $preview.html('Error: Failed to generate taxonomies');
            }
        });
    }

    /**
     * Display enhanced meta box preview with dual taxonomies
     */
    function displayEnhancedMetaBoxPreview(data) {
        // Show taxonomy previews
        if (data.generated_tags && data.generated_tags.length > 0) {
            $('#preview-tags-list').html(formatTaxonomyList(data.generated_tags));
            $('.tags-preview').show();
        } else {
            $('.tags-preview').hide();
        }

        if (data.generated_categories && data.generated_categories.length > 0) {
            $('#preview-categories-list').html(formatTaxonomyList(data.generated_categories));
            $('.categories-preview').show();
        } else {
            $('.categories-preview').hide();
        }

        // Show current taxonomies
        if (data.current_tags && data.current_tags.length > 0) {
            $('.current-tags-list').text(data.current_tags.join(', '));
            $('#current-tags-display').show();
        } else {
            $('#current-tags-display').hide();
        }

        if (data.current_categories && data.current_categories.length > 0) {
            $('.current-categories-list').text(data.current_categories.join(', '));
            $('#current-categories-display').show();
        } else {
            $('#current-categories-display').hide();
        }

        // Show current taxonomies container if any exist
        if (data.current_tags?.length > 0 || data.current_categories?.length > 0) {
            $('#current-taxonomies-display').show();
        }

        // Show matched rules
        if (data.matched_rules && data.matched_rules.length > 0) {
            const rulesHtml = data.matched_rules.map(rule => `<div class="rule-match">${rule}</div>`).join('');
            $('#matched-rules-list').html(rulesHtml);
            $('#matched-rules-display').show();
        } else {
            $('#matched-rules-display').hide();
        }

        // Show strategy controls and additional settings
        $('#strategy-controls').show();
        $('#additional-settings').show();
    }

    /**
     * Show apply buttons based on generated taxonomies
     */
    function showApplyButtons(data) {
        // Show individual taxonomy buttons
        if (data.generated_tags && data.generated_tags.length > 0) {
            $('#apply-tags').show();
        } else {
            $('#apply-tags').hide();
        }

        if (data.generated_categories && data.generated_categories.length > 0) {
            $('#apply-categories').show();
        } else {
            $('#apply-categories').hide();
        }

        // Show combined button if both taxonomies have generated items
        if (data.generated_tags?.length > 0 && data.generated_categories?.length > 0) {
            $('#apply-both').show();
        } else {
            $('#apply-both').hide();
        }
    }

    /**
     * Display warnings (tag limits, etc.)
     */
    function displayWarnings(warnings) {
        if (!warnings || warnings.length === 0) {
            $('#taxonomy-warnings').hide();
            return;
        }

        warnings.forEach(warning => {
            if (warning.type === 'tag_limit') {
                $('#tag-limit-warning .warning-text').text(warning.message);
                $('#tag-limit-warning').show();
            }
        });

        $('#taxonomy-warnings').show();
    }

    /**
     * Format taxonomy list for display
     */
    function formatTaxonomyList(items) {
        return items.map(item => `<span class="taxonomy-item">${item}</span>`).join(', ');
    }

    /**
     * Apply single taxonomy with strategy
     */
    function applyTaxonomy(postId, taxonomy, strategy) {
        const action = taxonomy === 'tags' ? 'autotag_apply_tags' : 'autotag_apply_categories';
        const items = taxonomy === 'tags' 
            ? $('#preview-tags-list').text().split(', ').map(s => s.trim()).filter(s => s)
            : $('#preview-categories-list').text().split(', ').map(s => s.trim()).filter(s => s);

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: action,
                post_id: postId,
                [taxonomy]: items,
                strategy: strategy,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage(`${taxonomy.charAt(0).toUpperCase() + taxonomy.slice(1)} applied successfully!`, 'success');
                } else {
                    showMessage('Error: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                showMessage(`Error: Failed to apply ${taxonomy}`, 'error');
            }
        });
    }

    /**
     * Apply both taxonomies with respective strategies
     */
    function applyBothTaxonomies(postId, tagStrategy, categoryStrategy) {
        const tags = $('#preview-tags-list').text().split(', ').map(s => s.trim()).filter(s => s);
        const categories = $('#preview-categories-list').text().split(', ').map(s => s.trim()).filter(s => s);

        // Apply both in sequence
        const promises = [];

        if (tags.length > 0) {
            promises.push($.ajax({
                url: autotagAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'autotag_apply_tags',
                    post_id: postId,
                    tags: tags,
                    strategy: tagStrategy,
                    nonce: autotagAdmin.nonce
                }
            }));
        }

        if (categories.length > 0) {
            promises.push($.ajax({
                url: autotagAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'autotag_apply_categories',
                    post_id: postId,
                    categories: categories,
                    strategy: categoryStrategy,
                    nonce: autotagAdmin.nonce
                }
            }));
        }

        Promise.all(promises)
            .then(responses => {
                const allSuccessful = responses.every(response => response.success);
                if (allSuccessful) {
                    showMessage('Tags and categories applied successfully!', 'success');
                } else {
                    showMessage('Some items failed to apply. Check the results.', 'warning');
                }
            })
            .catch(() => {
                showMessage('Error: Failed to apply taxonomies', 'error');
            });
    }

    /**
     * Initialize rollback system functionality
     */
    function initializeRollbackSystem() {
        // Show rollback history modal
        $('#autotag-show-rollback-history').on('click', function() {
            showRollbackHistory();
        });

        // Rollback last bulk operation
        $('#autotag-rollback-last-bulk').on('click', function() {
            if (confirm('Are you sure you want to rollback the last bulk operation? This cannot be undone.')) {
                rollbackLastBulk();
            }
        });

        // Modal close functionality
        $('.autotag-modal-close').on('click', function() {
            $('#autotag-rollback-modal').hide();
        });

        // Filter functionality
        $('#rollback-filter-apply').on('click', function() {
            loadRollbackHistory();
        });

        // Pagination
        $('#rollback-prev-page').on('click', function() {
            if (!$(this).prop('disabled')) {
                changePage(-1);
            }
        });

        $('#rollback-next-page').on('click', function() {
            if (!$(this).prop('disabled')) {
                changePage(1);
            }
        });
    }

    /**
     * Show rollback history modal
     */
    function showRollbackHistory() {
        $('#autotag-rollback-modal').show();
        loadRollbackHistory();
    }

    /**
     * Load rollback history
     */
    function loadRollbackHistory(page = 1) {
        const filterType = $('#rollback-filter-type').val();
        
        $('#autotag-rollback-history-list').html('<p>Loading rollback history...</p>');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_get_rollback_history',
                nonce: autotagAdmin.rollback_nonce,
                page: page,
                per_page: 10,
                operation_type: filterType
            },
            success: function(response) {
                if (response.success) {
                    displayRollbackHistory(response.data);
                } else {
                    $('#autotag-rollback-history-list').html('<p class="error">Failed to load rollback history: ' + (response.data || 'Unknown error') + '</p>');
                }
            },
            error: function() {
                $('#autotag-rollback-history-list').html('<p class="error">Error loading rollback history.</p>');
            }
        });
    }

    /**
     * Display rollback history in modal
     */
    function displayRollbackHistory(data) {
        let html = '';
        
        if (data.history && data.history.length > 0) {
            html += '<table class="autotag-rollback-table">';
            html += '<thead><tr><th>Date</th><th>Operation</th><th>Post/Posts</th><th>User</th><th>Actions</th></tr></thead>';
            html += '<tbody>';
            
            data.history.forEach(function(record) {
                html += '<tr>';
                html += '<td>' + new Date(record.created_at * 1000).toLocaleString() + '</td>';
                html += '<td>' + record.operation_type.charAt(0).toUpperCase() + record.operation_type.slice(1) + '</td>';
                html += '<td>';
                if (record.post_title) {
                    html += record.post_title + ' (ID: ' + record.post_id + ')';
                } else {
                    html += 'Post ID: ' + record.post_id;
                }
                html += '</td>';
                html += '<td>User ID: ' + record.user_id + '</td>';
                html += '<td>';
                html += '<button class="button button-small rollback-single-btn" data-rollback-id="' + record.rollback_id + '" data-post-id="' + record.post_id + '">Rollback</button>';
                html += '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            
            // Add event listeners for rollback buttons
            setTimeout(function() {
                $('.rollback-single-btn').on('click', function() {
                    const rollbackId = $(this).data('rollback-id');
                    const postId = $(this).data('post-id');
                    if (confirm('Are you sure you want to rollback this post? This cannot be undone.')) {
                        rollbackSinglePost(postId, rollbackId);
                    }
                });
            }, 100);
            
        } else {
            html = '<p>No rollback history found.</p>';
        }
        
        $('#autotag-rollback-history-list').html(html);
        
        // Update pagination
        updateRollbackPagination(data);
    }

    /**
     * Update rollback pagination controls
     */
    function updateRollbackPagination(data) {
        $('#rollback-page-info').text('Page ' + data.page + ' of ' + data.total_pages);
        
        $('#rollback-prev-page').prop('disabled', data.page <= 1);
        $('#rollback-next-page').prop('disabled', data.page >= data.total_pages);
        
        // Store current page for navigation
        window.currentRollbackPage = data.page;
        window.totalRollbackPages = data.total_pages;
    }

    /**
     * Change rollback history page
     */
    function changePage(direction) {
        const newPage = (window.currentRollbackPage || 1) + direction;
        if (newPage >= 1 && newPage <= (window.totalRollbackPages || 1)) {
            loadRollbackHistory(newPage);
        }
    }

    /**
     * Rollback single post
     */
    function rollbackSinglePost(postId, rollbackId) {
        showLoading('Rolling back post...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_rollback_post',
                post_id: postId,
                rollback_id: rollbackId,
                nonce: autotagAdmin.rollback_nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage('Post rolled back successfully!', 'success');
                    loadRollbackHistory(window.currentRollbackPage || 1);
                } else {
                    showMessage('Rollback failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage('Error: Rollback request failed', 'error');
            }
        });
    }

    /**
     * Rollback last bulk operation
     */
    function rollbackLastBulk() {
        showLoading('Starting bulk rollback...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_rollback_bulk',
                operation_id: 'last', // Backend should find the last bulk operation
                nonce: autotagAdmin.rollback_nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage('Bulk rollback started! This may take a few minutes.', 'success');
                } else {
                    showMessage('Rollback failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage('Error: Bulk rollback request failed', 'error');
            }
        });
    }

    /**
     * Check configuration conflicts
     */
    function checkConfigConflicts() {
        showLoading('Checking for conflicts...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_check_conflicts',
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    const conflicts = response.data;
                    if (conflicts && conflicts.length > 0) {
                        let message = 'Found ' + conflicts.length + ' conflicts:\n';
                        conflicts.forEach(function(conflict) {
                            message += '• ' + conflict + '\n';
                        });
                        alert(message);
                    } else {
                        showMessage('No conflicts found!', 'success');
                    }
                } else {
                    showMessage('Failed to check conflicts: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage('Error: Failed to check conflicts', 'error');
            }
        });
    }

    /**
     * Perform cleanup action
     */
    function performCleanupAction(action) {
        showLoading('Processing...');

        const actionMap = {
            'cleanup_orphaned': 'autotag_cleanup_orphaned',
            'clear_cache': 'autotag_clear_cache',
            'reset_processing': 'autotag_reset_processing'
        };

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: actionMap[action],
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showMessage(response.data || 'Operation completed successfully!', 'success');
                } else {
                    showMessage('Operation failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage('Error: Operation failed', 'error');
            }
        });
    }

    /**
     * Run post debug
     */
    function runPostDebug() {
        const postId = $('#debug-post-id').val();
        
        if (!postId) {
            alert('Please enter a post ID');
            return;
        }

        showLoading('Running debug...');

        $.ajax({
            url: autotagAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'autotag_debug_post',
                post_id: postId,
                nonce: autotagAdmin.nonce
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    const debug = response.data;
                    let html = '<h4>Debug Results for Post ID: ' + postId + '</h4>';
                    html += '<div style="background: #f9f9f9; padding: 10px; margin: 10px 0; border-radius: 4px;">';
                    html += '<pre>' + JSON.stringify(debug, null, 2) + '</pre>';
                    html += '</div>';
                    $('#autotag-debug-results').html(html).show();
                } else {
                    showMessage('Debug failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoading();
                showMessage('Error: Debug request failed', 'error');
            }
        });
    }

    // Export functions for global access
    window.autotagAdmin = window.autotagAdmin || {};
    window.autotagAdmin.showMessage = showMessage;
    window.autotagAdmin.showLoading = showLoading;
    window.autotagAdmin.hideLoading = hideLoading;

})(jQuery);