/**
 * COD Guard Admin JavaScript
 * 
 * Handles admin interface interactions, settings, and order management
 */

(function($) {
    'use strict';
    
    var CODGuardAdmin = {
        
        // Initialize
        init: function() {
            this.bindEvents();
            this.initSettingsPage();
            this.initOrderManagement();
            this.updateCalculationPreview();
        },
        
        // Bind events
        bindEvents: function() {
            var self = this;
            
            // Settings page events
            $(document).on('change', '#cod_guard_payment_mode', this.handlePaymentModeChange.bind(this));
            $(document).on('input change', '#cod_guard_percentage_amount', this.updateCalculationPreview.bind(this));
            $(document).on('input change', '#cod_guard_fixed_amount', this.updateCalculationPreview.bind(this));
            $(document).on('change', '#cod_guard_enabled', this.handleEnableToggle.bind(this));
            
            // Order management events
            $(document).on('click', '.cod-guard-mark-paid', this.markCODPaid.bind(this));
            $(document).on('click', '.cod-guard-adjust-amount', this.showAdjustAmountModal.bind(this));
            $(document).on('click', '.cod-guard-send-reminder', this.sendCODReminder.bind(this));
            
            // Bulk actions
            $(document).on('change', '#bulk-action-selector-top, #bulk-action-selector-bottom', this.handleBulkActions.bind(this));
            
            // Real-time validation
            $(document).on('blur', '.cod-guard-amount-input', this.validateAmountInput.bind(this));
            
            // Settings form submission
            $(document).on('submit', '.cod-guard-settings-form', this.validateSettingsForm.bind(this));
        },
        
        // Initialize settings page
        initSettingsPage: function() {
            if (!$('#cod_guard_payment_mode').length) {
                return;
            }
            
            // Initialize payment mode visibility
            this.togglePaymentModeFields();
            
            // Initialize enhanced selects
            this.initEnhancedSelects();
            
            // Add settings preview
            this.addSettingsPreview();
            
            // Initialize tooltips
            this.initTooltips();
        },
        
        // Handle payment mode changes
        handlePaymentModeChange: function() {
            this.togglePaymentModeFields();
            this.updateCalculationPreview();
            this.addSettingsAnimation();
        },
        
        // Toggle payment mode specific fields
        togglePaymentModeFields: function() {
            var paymentMode = $('#cod_guard_payment_mode').val();
            var $percentageField = $('.cod-guard-percentage-field').closest('tr');
            var $fixedField = $('.cod-guard-fixed-field').closest('tr');
            
            // Hide all mode-specific fields first
            $percentageField.add($fixedField).addClass('cod-guard-field-hidden');
            
            // Show relevant field with animation
            setTimeout(function() {
                if (paymentMode === 'percentage') {
                    $percentageField.removeClass('cod-guard-field-hidden');
                } else if (paymentMode === 'fixed') {
                    $fixedField.removeClass('cod-guard-field-hidden');
                }
            }, 200);
        },
        
        // Update calculation preview
        updateCalculationPreview: function() {
            var paymentMode = $('#cod_guard_payment_mode').val();
            var percentage = parseInt($('#cod_guard_percentage_amount').val()) || 25;
            var fixedAmount = parseFloat($('#cod_guard_fixed_amount').val()) || 10;
            
            // Sample order values
            var sampleSubtotal = 90;
            var sampleShipping = 10;
            var sampleTotal = sampleSubtotal + sampleShipping;
            
            var advanceAmount = 0;
            var modeLabel = '';
            
            switch (paymentMode) {
                case 'percentage':
                    advanceAmount = (sampleTotal * percentage) / 100;
                    modeLabel = percentage + '%';
                    break;
                case 'shipping':
                    advanceAmount = sampleShipping;
                    modeLabel = 'Shipping';
                    break;
                case 'fixed':
                    advanceAmount = Math.min(fixedAmount, sampleTotal);
                    modeLabel = 'Fixed';
                    break;
            }
            
            var codAmount = sampleTotal - advanceAmount;
            
            this.displayCalculationPreview({
                mode: paymentMode,
                modeLabel: modeLabel,
                subtotal: sampleSubtotal,
                shipping: sampleShipping,
                total: sampleTotal,
                advanceAmount: advanceAmount,
                codAmount: codAmount
            });
        },
        
        // Display calculation preview
        displayCalculationPreview: function(data) {
            var $preview = $('.cod-guard-settings-preview');
            
            if ($preview.length === 0) {
                this.addSettingsPreview();
                $preview = $('.cod-guard-settings-preview');
            }
            
            var previewHTML = `
                <div class="cod-guard-preview-title">Preview - Sample Order (${data.modeLabel} Mode)</div>
                <div class="cod-guard-calculation-example">
                    <div class="example-line">
                        <span>Order Subtotal:</span>
                        <span>${this.formatCurrency(data.subtotal)}</span>
                    </div>
                    <div class="example-line">
                        <span>Shipping:</span>
                        <span>${this.formatCurrency(data.shipping)}</span>
                    </div>
                    <div class="example-line total">
                        <span><strong>Order Total:</strong></span>
                        <span><strong>${this.formatCurrency(data.total)}</strong></span>
                    </div>
                    <div class="example-line">
                        <span>Pay Now (${data.modeLabel}):</span>
                        <span class="highlight">${this.formatCurrency(data.advanceAmount)}</span>
                    </div>
                    <div class="example-line">
                        <span>Pay on Delivery:</span>
                        <span class="highlight">${this.formatCurrency(data.codAmount)}</span>
                    </div>
                </div>
            `;
            
            $preview.html(previewHTML);
            
            // Add update animation
            $preview.addClass('cod-guard-updated');
            setTimeout(function() {
                $preview.removeClass('cod-guard-updated');
            }, 1000);
        },
        
        // Add settings preview section
        addSettingsPreview: function() {
            var $modeField = $('#cod_guard_payment_mode').closest('tr');
            
            if ($('.cod-guard-settings-preview').length === 0) {
                $modeField.after(`
                    <tr>
                        <td colspan="2">
                            <div class="cod-guard-settings-preview"></div>
                        </td>
                    </tr>
                `);
            }
        },
        
        // Handle enable/disable toggle
        handleEnableToggle: function() {
            var isEnabled = $('#cod_guard_enabled').is(':checked');
            var $settingsRows = $('.cod-guard-settings-row');
            
            if (isEnabled) {
                $settingsRows.removeClass('cod-guard-field-hidden');
                this.showNotice('COD Guard enabled. Configure your payment settings below.', 'success');
            } else {
                $settingsRows.addClass('cod-guard-field-hidden');
                this.showNotice('COD Guard disabled. Orders will use standard payment methods.', 'warning');
            }
        },
        
        // Initialize order management
        initOrderManagement: function() {
            // Add quick action buttons to order list
            this.addQuickActionButtons();
            
            // Initialize order details enhancements
            this.initOrderDetailsEnhancements();
            
            // Add COD Guard column sorting
            this.initColumnSorting();
        },
        
        // Add quick action buttons
        addQuickActionButtons: function() {
            $('.cod-guard-status.pending').each(function() {
                var $status = $(this);
                var orderId = $status.closest('tr').find('.order_number a').attr('href').match(/post=(\d+)/);
                
                if (orderId && orderId[1]) {
                    $status.append(`
                        <div class="cod-guard-quick-actions">
                            <button class="button button-small cod-guard-mark-paid" 
                                    data-order-id="${orderId[1]}" 
                                    title="Mark COD as Paid">
                                ✓ Mark Paid
                            </button>
                        </div>
                    `);
                }
            });
        },
        
        // Mark COD as paid
        markCODPaid: function(e) {
            e.preventDefault();
            
            var $button = $(e.target);
            var orderId = $button.data('order-id');
            
            if (!orderId) {
                return;
            }
            
            if (!confirm('Mark COD payment as completed for this order?')) {
                return;
            }
            
            $button.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cod_guard_mark_cod_paid',
                    order_id: orderId,
                    nonce: codGuardAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        CODGuardAdmin.showNotice('COD payment marked as completed.', 'success');
                        location.reload();
                    } else {
                        CODGuardAdmin.showNotice(response.data || 'Error updating payment status.', 'error');
                        $button.prop('disabled', false).text('✓ Mark Paid');
                    }
                },
                error: function() {
                    CODGuardAdmin.showNotice('Network error. Please try again.', 'error');
                    $button.prop('disabled', false).text('✓ Mark Paid');
                }
            });
        },
        
        // Show adjust amount modal
        showAdjustAmountModal: function(e) {
            e.preventDefault();
            
            var orderId = $(e.target).data('order-id');
            var currentAmount = $(e.target).data('current-amount');
            
            var modal = `
                <div class="cod-guard-modal-overlay">
                    <div class="cod-guard-modal">
                        <div class="cod-guard-modal-header">
                            <h3>Adjust COD Amount</h3>
                            <button class="cod-guard-modal-close">&times;</button>
                        </div>
                        <div class="cod-guard-modal-body">
                            <p>Order #${orderId}</p>
                            <label for="new-cod-amount">New COD Amount:</label>
                            <input type="number" id="new-cod-amount" step="0.01" min="0" 
                                   value="${currentAmount}" class="regular-text">
                            <p class="description">Enter the new COD amount to collect on delivery.</p>
                        </div>
                        <div class="cod-guard-modal-footer">
                            <button class="button button-primary cod-guard-save-amount" data-order-id="${orderId}">
                                Update Amount
                            </button>
                            <button class="button cod-guard-modal-close">Cancel</button>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modal);
            $('#new-cod-amount').focus().select();
        },
        
        // Send COD reminder
        sendCODReminder: function(e) {
            e.preventDefault();
            
            var orderId = $(e.target).data('order-id');
            var $button = $(e.target);
            
            $button.prop('disabled', true).text('Sending...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cod_guard_send_reminder',
                    order_id: orderId,
                    nonce: codGuardAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        CODGuardAdmin.showNotice('Reminder email sent successfully.', 'success');
                    } else {
                        CODGuardAdmin.showNotice(response.data || 'Error sending reminder.', 'error');
                    }
                    $button.prop('disabled', false).text('Send Reminder');
                },
                error: function() {
                    CODGuardAdmin.showNotice('Network error. Please try again.', 'error');
                    $button.prop('disabled', false).text('Send Reminder');
                }
            });
        },
        
        // Initialize enhanced selects
        initEnhancedSelects: function() {
            if (typeof $.fn.select2 !== 'undefined') {
                $('.wc-enhanced-select').select2({
                    minimumResultsForSearch: 10,
                    width: '100%'
                });
            }
        },
        
        // Initialize tooltips
        initTooltips: function() {
            $('.cod-guard-tooltip').each(function() {
                $(this).attr('title', $(this).data('tooltip'));
            });
        },
        
        // Validate amount input
        validateAmountInput: function(e) {
            var $input = $(e.target);
            var value = parseFloat($input.val());
            var min = parseFloat($input.attr('min')) || 0;
            var max = parseFloat($input.attr('max')) || Infinity;
            
            if (isNaN(value) || value < min || value > max) {
                $input.addClass('cod-guard-invalid');
                // Validate amount input
        validateAmountInput: function(e) {
            var $input = $(e.target);
            var value = parseFloat($input.val());
            var min = parseFloat($input.attr('min')) || 0;
            var max = parseFloat($input.attr('max')) || Infinity;
            
            if (isNaN(value) || value < min || value > max) {
                $input.addClass('cod-guard-invalid');
                this.showFieldError($input, 'Please enter a valid amount between ' + min + ' and ' + max);
            } else {
                $input.removeClass('cod-guard-invalid');
                this.hideFieldError($input);
            }
        },
        
        // Validate settings form
        validateSettingsForm: function(e) {
            var isValid = true;
            var errors = [];
            
            // Validate percentage
            var percentage = parseInt($('#cod_guard_percentage_amount').val());
            if ($('#cod_guard_payment_mode').val() === 'percentage') {
                if (isNaN(percentage) || percentage < 10 || percentage > 90) {
                    errors.push('Advance percentage must be between 10% and 90%.');
                    isValid = false;
                }
            }
            
            // Validate fixed amount
            var fixedAmount = parseFloat($('#cod_guard_fixed_amount').val());
            if ($('#cod_guard_payment_mode').val() === 'fixed') {
                if (isNaN(fixedAmount) || fixedAmount < 0) {
                    errors.push('Fixed amount must be a positive number.');
                    isValid = false;
                }
            }
            
            // Validate minimum order amount
            var minimumOrder = parseFloat($('#cod_guard_minimum_order_amount').val());
            if (isNaN(minimumOrder) || minimumOrder < 0) {
                errors.push('Minimum order amount must be a positive number or zero.');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                this.showNotice(errors.join(' '), 'error');
                return false;
            }
            
            return true;
        },
        
        // Handle bulk actions
        handleBulkActions: function(e) {
            var action = $(e.target).val();
            
            if (action === 'cod_guard_mark_cod_paid') {
                var $checkedBoxes = $('input[name="post[]"]:checked');
                
                if ($checkedBoxes.length === 0) {
                    this.showNotice('Please select orders to process.', 'warning');
                    return;
                }
                
                if (!confirm('Mark COD as paid for ' + $checkedBoxes.length + ' selected orders?')) {
                    $(e.target).val('');
                    return;
                }
            }
        },
        
        // Initialize order details enhancements
        initOrderDetailsEnhancements: function() {
            // Add COD Guard info panel animations
            $('.cod-guard-order-info').hide().fadeIn(500);
            
            // Add interactive elements to payment breakdown
            $('.cod-guard-breakdown-table tr').hover(
                function() {
                    $(this).addClass('cod-guard-row-hover');
                },
                function() {
                    $(this).removeClass('cod-guard-row-hover');
                }
            );
            
            // Add copy-to-clipboard functionality
            this.addCopyToClipboard();
        },
        
        // Add copy to clipboard functionality
        addCopyToClipboard: function() {
            $('.cod-guard-info-item').each(function() {
                var $item = $(this);
                var value = $item.find('span').text().trim();
                
                $item.append('<button class="cod-guard-copy-btn" data-value="' + value + '" title="Copy to clipboard">📋</button>');
            });
            
            $(document).on('click', '.cod-guard-copy-btn', function(e) {
                e.preventDefault();
                var value = $(this).data('value');
                
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(value).then(function() {
                        CODGuardAdmin.showNotice('Copied to clipboard!', 'success', 2000);
                    });
                } else {
                    // Fallback for older browsers
                    var $temp = $('<input>').val(value);
                    $('body').append($temp);
                    $temp.select();
                    document.execCommand('copy');
                    $temp.remove();
                    CODGuardAdmin.showNotice('Copied to clipboard!', 'success', 2000);
                }
            });
        },
        
        // Initialize column sorting
        initColumnSorting: function() {
            var $codGuardHeader = $('.column-cod_guard_status');
            
            if ($codGuardHeader.length) {
                $codGuardHeader.css('cursor', 'pointer').on('click', function() {
                    CODGuardAdmin.sortByCODStatus();
                });
            }
        },
        
        // Sort by COD status
        sortByCODStatus: function() {
            var $tbody = $('.wp-list-table tbody');
            var rows = $tbody.find('tr').get();
            
            rows.sort(function(a, b) {
                var aStatus = $(a).find('.cod-guard-status').hasClass('pending') ? 1 : 0;
                var bStatus = $(b).find('.cod-guard-status').hasClass('pending') ? 1 : 0;
                return bStatus - aStatus; // Pending orders first
            });
            
            $.each(rows, function(index, row) {
                $tbody.append(row);
            });
            
            this.showNotice('Orders sorted by COD status (pending first).', 'success', 3000);
        },
        
        // Add settings animation
        addSettingsAnimation: function() {
            $('.form-table tr').addClass('cod-guard-settings-updated');
            setTimeout(function() {
                $('.form-table tr').removeClass('cod-guard-settings-updated');
            }, 1000);
        },
        
        // Format currency
        formatCurrency: function(amount) {
            if (typeof codGuardAdmin !== 'undefined' && codGuardAdmin.currency_symbol) {
                var formatted = parseFloat(amount).toFixed(codGuardAdmin.decimals || 2);
                var symbol = codGuardAdmin.currency_symbol;
                var position = codGuardAdmin.currency_position || 'left';
                
                switch (position) {
                    case 'left':
                        return symbol + formatted;
                    case 'right':
                        return formatted + symbol;
                    case 'left_space':
                        return symbol + ' ' + formatted;
                    case 'right_space':
                        return formatted + ' ' + symbol;
                    default:
                        return symbol + formatted;
                }
            }
            
            return ' + parseFloat(amount).toFixed(2);
        },
        
        // Show notice
        showNotice: function(message, type, duration) {
            type = type || 'info';
            duration = duration || 5000;
            
            var $notice = $('<div class="cod-guard-admin-notice ' + type + '"><p>' + message + '</p></div>');
            
            // Remove existing notices
            $('.cod-guard-admin-notice').remove();
            
            // Add new notice
            if ($('.wrap h1').length) {
                $('.wrap h1').after($notice);
            } else {
                $('.wrap').prepend($notice);
            }
            
            // Auto-remove after duration
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $notice.remove();
                });
            }, duration);
        },
        
        // Show field error
        showFieldError: function($field, message) {
            this.hideFieldError($field);
            
            var $error = $('<div class="cod-guard-field-error">' + message + '</div>');
            $field.after($error);
        },
        
        // Hide field error
        hideFieldError: function($field) {
            $field.next('.cod-guard-field-error').remove();
        },
        
        // Modal handling
        handleModalEvents: function() {
            // Close modal
            $(document).on('click', '.cod-guard-modal-close, .cod-guard-modal-overlay', function(e) {
                if (e.target === this) {
                    $('.cod-guard-modal-overlay').remove();
                }
            });
            
            // Save amount
            $(document).on('click', '.cod-guard-save-amount', function() {
                var orderId = $(this).data('order-id');
                var newAmount = $('#new-cod-amount').val();
                
                if (!newAmount || parseFloat(newAmount) < 0) {
                    CODGuardAdmin.showNotice('Please enter a valid amount.', 'error');
                    return;
                }
                
                CODGuardAdmin.updateCODAmount(orderId, newAmount);
            });
            
            // ESC key to close modal
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) { // ESC key
                    $('.cod-guard-modal-overlay').remove();
                }
            });
        },
        
        // Update COD amount
        updateCODAmount: function(orderId, newAmount) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cod_guard_update_cod_amount',
                    order_id: orderId,
                    new_amount: newAmount,
                    nonce: codGuardAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        CODGuardAdmin.showNotice('COD amount updated successfully.', 'success');
                        $('.cod-guard-modal-overlay').remove();
                        location.reload();
                    } else {
                        CODGuardAdmin.showNotice(response.data || 'Error updating amount.', 'error');
                    }
                },
                error: function() {
                    CODGuardAdmin.showNotice('Network error. Please try again.', 'error');
                }
            });
        },
        
        // Initialize dashboard widgets
        initDashboardWidgets: function() {
            if ($('#cod-guard-dashboard-widget').length) {
                this.loadDashboardStats();
            }
        },
        
        // Load dashboard statistics
        loadDashboardStats: function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cod_guard_dashboard_stats',
                    nonce: codGuardAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#cod-guard-dashboard-widget .inside').html(response.data);
                    }
                }
            });
        },
        
        // Debug mode helpers
        debug: function(message, data) {
            if (typeof codGuardAdmin !== 'undefined' && codGuardAdmin.debug) {
                console.log('[COD Guard Admin] ' + message, data || '');
            }
        },
        
        // Performance monitoring
        trackPerformance: function(action, startTime) {
            if (typeof performance !== 'undefined') {
                var duration = performance.now() - startTime;
                this.debug('Performance: ' + action + ' took ' + duration.toFixed(2) + 'ms');
            }
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        var startTime = performance.now();
        
        CODGuardAdmin.init();
        CODGuardAdmin.handleModalEvents();
        CODGuardAdmin.initDashboardWidgets();
        
        CODGuardAdmin.trackPerformance('Admin initialization', startTime);
        CODGuardAdmin.debug('COD Guard Admin initialized successfully');
    });
    
    // Make CODGuardAdmin available globally for debugging
    window.CODGuardAdmin = CODGuardAdmin;
    
})(jQuery);

// Additional CSS for modals and animations
jQuery(document).ready(function($) {
    var modalCSS = `
        <style>
        .cod-guard-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .cod-guard-modal {
            background: white;
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow: auto;
        }
        
        .cod-guard-modal-header {
            padding: 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .cod-guard-modal-header h3 {
            margin: 0;
            color: #1d2327;
        }
        
        .cod-guard-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .cod-guard-modal-body {
            padding: 20px;
        }
        
        .cod-guard-modal-footer {
            padding: 20px;
            border-top: 1px solid #ddd;
            text-align: right;
        }
        
        .cod-guard-modal-footer .button {
            margin-left: 10px;
        }
        
        .cod-guard-quick-actions {
            margin-top: 5px;
        }
        
        .cod-guard-quick-actions .button {
            font-size: 11px;
            padding: 2px 8px;
            height: auto;
            line-height: 1.2;
        }
        
        .cod-guard-copy-btn {
            background: none;
            border: none;
            font-size: 12px;
            cursor: pointer;
            opacity: 0.6;
            margin-left: 8px;
            padding: 2px;
            border-radius: 2px;
        }
        
        .cod-guard-copy-btn:hover {
            opacity: 1;
            background: #f0f0f0;
        }
        
        .cod-guard-field-error {
            color: #d63638;
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }
        
        .cod-guard-invalid {
            border-color: #d63638 !important;
            box-shadow: 0 0 0 1px #d63638;
        }
        
        .cod-guard-settings-updated {
            animation: cod-guard-admin-highlight 1s ease;
        }
        
        .cod-guard-row-hover {
            background: #f0f6fc !important;
            transform: translateX(5px);
            transition: all 0.3s ease;
        }
        </style>
    `;
    
    $('head').append(modalCSS);
});