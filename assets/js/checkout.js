/**
 * COD Guard Checkout JavaScript - FIXED VERSION
 * Save as: assets/js/checkout.js
 */

jQuery(document).ready(function($) {
    'use strict';
    
    var CODGuardCheckout = {
        
        init: function() {
            this.bindEvents();
            this.updateDisplay();
        },
        
        bindEvents: function() {
            var self = this;
            
            // Handle checkbox change
            $('body').on('change', '#cod_guard_enabled', function() {
                self.handleCheckboxChange();
            });
            
            // Handle payment method changes
            $('body').on('change', 'input[name="payment_method"]', function() {
                self.handlePaymentMethodChange();
            });
            
            // Handle checkout updates
            $('body').on('updated_checkout', function() {
                self.updateBreakdown();
                self.updateDisplay();
            });
            
            // Validate before form submission
            $('form.checkout').on('checkout_place_order', function() {
                return self.validateOrder();
            });
            
            // CRITICAL: Force cart total update when checkbox changes
            $('body').on('change', '#cod_guard_enabled', function() {
                if ($(this).is(':checked')) {
                    // Trigger checkout update to recalculate totals
                    $('body').trigger('update_checkout');
                } else {
                    // Trigger checkout update to restore original totals
                    $('body').trigger('update_checkout');
                }
            });
        },
        
        handleCheckboxChange: function() {
            this.updateDisplay();
            this.updateBreakdown();
            
            // Show/hide breakdown section
            var isChecked = $('#cod_guard_enabled').is(':checked');
            
            if (isChecked) {
                $('#cod-guard-breakdown').slideDown(300);
                this.updateOrderTotalDisplay();
                
                // Add visual feedback
                $('#cod-guard-section').addClass('cod-guard-active');
            } else {
                $('#cod-guard-breakdown').slideUp(300);
                this.restoreOrderTotalDisplay();
                
                // Remove visual feedback
                $('#cod-guard-section').removeClass('cod-guard-active');
            }
        },
        
        handlePaymentMethodChange: function() {
            var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
            
            // Check if COD is selected - show warning
            if (selectedPaymentMethod === 'cod' && $('#cod_guard_enabled').is(':checked')) {
                this.showWarning('COD cannot be used for advance payment when COD Guard is enabled. Please select a different payment method.');
            }
            
            // Update breakdown if COD Guard is enabled
            if ($('#cod_guard_enabled').is(':checked')) {
                this.updateBreakdown();
            }
        },
        
        updateDisplay: function() {
            var isChecked = $('#cod_guard_enabled').is(':checked');
            
            if (isChecked) {
                $('#cod-guard-breakdown').show();
                this.updateOrderTotalDisplay();
            } else {
                $('#cod-guard-breakdown').hide();
                this.restoreOrderTotalDisplay();
            }
        },
        
        updateOrderTotalDisplay: function() {
            var advanceAmount = $('.cod-guard-advance-amount').val();
            
            if (advanceAmount && parseFloat(advanceAmount) > 0) {
                var $orderTotal = $('.order-total .woocommerce-Price-amount');
                
                if ($orderTotal.length) {
                    // Store original total if not already stored
                    if (!$orderTotal.data('original-total')) {
                        $orderTotal.data('original-total', $orderTotal.html());
                    }
                    
                    // Format advance amount using WooCommerce formatting
                    var formattedAmount = this.formatPrice(parseFloat(advanceAmount));
                    $orderTotal.html(formattedAmount);
                    
                    // Add notice below order total
                    if (!$('.cod-guard-total-notice').length) {
                        $('.order-total').after(
                            '<tr class="cod-guard-total-notice">' +
                            '<th style="color: #28a745; border-top: 1px solid #e2e8f0;">' + codGuardAjax.strings.pay_now_label + ':</th>' +
                            '<td style="color: #28a745; font-weight: bold; border-top: 1px solid #e2e8f0;">' + formattedAmount + '</td>' +
                            '</tr>'
                        );
                    } else {
                        $('.cod-guard-total-notice td').html(formattedAmount);
                    }
                }
            }
        },
        
        restoreOrderTotalDisplay: function() {
            var $orderTotal = $('.order-total .woocommerce-Price-amount');
            
            if ($orderTotal.length && $orderTotal.data('original-total')) {
                $orderTotal.html($orderTotal.data('original-total'));
            }
            
            $('.cod-guard-total-notice').remove();
        },
        
        updateBreakdown: function() {
            if (!$('#cod_guard_enabled').is(':checked')) {
                return;
            }
            
            // Show loading state
            $('#cod-guard-breakdown').addClass('cod-guard-loading');
            
            // In a real implementation, you might want to make an AJAX call here
            // For now, we'll use the values already calculated
            var advanceAmount = $('.cod-guard-advance-amount').val();
            var codAmount = $('.cod-guard-cod-amount').val();
            var originalTotal = $('.cod-guard-original-total').val();
            
            if (advanceAmount && codAmount && originalTotal) {
                // Update display with current values
                $('.advance-amount').text(this.formatPrice(parseFloat(advanceAmount)));
                $('.cod-amount').text(this.formatPrice(parseFloat(codAmount)));
                $('.original-total').text(this.formatPrice(parseFloat(originalTotal)));
                $('.advance-amount-text').text(this.formatPrice(parseFloat(advanceAmount)));
                $('.cod-amount-text').text(this.formatPrice(parseFloat(codAmount)));
                
                this.updateOrderTotalDisplay();
            }
            
            // Remove loading state
            setTimeout(function() {
                $('#cod-guard-breakdown').removeClass('cod-guard-loading');
            }, 500);
        },
        
        validateOrder: function() {
            var isChecked = $('#cod_guard_enabled').is(':checked');
            
            if (!isChecked) {
                return true; // Normal checkout
            }
            
            // Check if COD is selected
            var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
            
            if (selectedPaymentMethod === 'cod') {
                this.showError('COD cannot be used for advance payment when COD Guard is enabled. Please select a different payment method.');
                return false;
            }
            
            // Check if any payment method is selected
            if (!selectedPaymentMethod) {
                this.showError('Please select a payment method for your advance payment.');
                return false;
            }
            
            // Validate breakdown amounts
            var advanceAmount = parseFloat($('.cod-guard-advance-amount').val());
            var codAmount = parseFloat($('.cod-guard-cod-amount').val());
            
            if (advanceAmount <= 0) {
                this.showError('Invalid advance payment amount. Please refresh and try again.');
                return false;
            }
            
            return true;
        },
        
        formatPrice: function(amount) {
            // Use WooCommerce currency formatting if available
            if (typeof codGuardAjax !== 'undefined' && codGuardAjax.currency_symbol) {
                var formatted = parseFloat(amount).toFixed(2);
                var symbol = codGuardAjax.currency_symbol;
                
                // Simple formatting - you might want to enhance this based on WooCommerce settings
                return symbol + formatted;
            }
            
            // Fallback formatting
            return '$' + parseFloat(amount).toFixed(2);
        },
        
        showError: function(message) {
            // Remove existing errors
            $('.woocommerce-error, .cod-guard-error').remove();
            
            // Add error message
            var errorHtml = '<div class="woocommerce-error cod-guard-error" role="alert">' + message + '</div>';
            
            if ($('.woocommerce-checkout').length) {
                $('.woocommerce-checkout').prepend(errorHtml);
            } else if ($('form.checkout').length) {
                $('form.checkout').prepend(errorHtml);
            } else {
                $('#cod-guard-section').before(errorHtml);
            }
            
            // Scroll to error
            $('html, body').animate({
                scrollTop: $('.woocommerce-error').offset().top - 100
            }, 500);
        },
        
        showWarning: function(message) {
            // Remove existing warnings
            $('.cod-guard-warning').remove();
            
            // Add warning message
            var warningHtml = '<div class="woocommerce-message cod-guard-warning" style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404;">' + message + '</div>';
            
            $('#cod-guard-section').before(warningHtml);
            
            // Auto-remove warning after 5 seconds
            setTimeout(function() {
                $('.cod-guard-warning').fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        // Debug helper
        debug: function(message) {
            if (typeof console !== 'undefined' && console.log) {
                console.log('[COD Guard] ' + message);
            }
        }
    };
    
    // Initialize COD Guard checkout functionality
    CODGuardCheckout.init();
    
    // Add some CSS for better visual feedback
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            #cod-guard-section.cod-guard-active {
                border-color: #28a745 !important;
                box-shadow: 0 0 0 1px #28a745;
            }
            
            #cod-guard-breakdown.cod-guard-loading {
                opacity: 0.6;
                pointer-events: none;
                position: relative;
            }
            
            #cod-guard-breakdown.cod-guard-loading::after {
                content: "Updating...";
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(255, 255, 255, 0.9);
                padding: 10px 15px;
                border-radius: 3px;
                font-weight: bold;
                z-index: 10;
            }
            
            .cod-guard-total-notice th,
            .cod-guard-total-notice td {
                padding: 8px 0;
                font-size: 0.9em;
            }
            
            .cod-guard-error {
                margin: 15px 0;
                padding: 12px 15px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .cod-guard-error::before {
                content: "⚠️";
                font-size: 1.2em;
            }
            
            .cod-guard-warning {
                margin: 15px 0;
                padding: 12px 15px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .cod-guard-warning::before {
                content: "⚠️";
                font-size: 1.2em;
            }
            
            /* Animation for smooth transitions */
            #cod-guard-breakdown {
                transition: all 0.3s ease;
            }
            
            .order-total .woocommerce-Price-amount {
                transition: color 0.3s ease;
            }
            
            #cod-guard-section.cod-guard-active .order-total .woocommerce-Price-amount {
                color: #28a745;
            }
        `)
        .appendTo('head');
    
    // Make CODGuardCheckout available globally for debugging
    window.CODGuardCheckout = CODGuardCheckout;
    
    // Console log for debugging
    CODGuardCheckout.debug('Checkout script loaded successfully');
});