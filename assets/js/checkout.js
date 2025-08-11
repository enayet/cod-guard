/**
 * COD Guard Checkout JavaScript - FIXED VERSION
 * Replace assets/js/checkout.js with this version
 * 
 * Key Changes:
 * 1. NEVER modify the displayed order total
 * 2. Only show visual indicators that COD Guard is active
 * 3. Keep all totals as original cart total
 */

jQuery(document).ready(function($) {
    'use strict';
    
    var CODGuardCheckout = {
        
        init: function() {
            this.bindEvents();
            this.initializeDisplay();
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
                self.initializeDisplay();
            });
            
            // Validate before form submission
            $('form.checkout').on('checkout_place_order', function() {
                return self.validateOrder();
            });
        },
        
        initializeDisplay: function() {
            // Remove any existing COD Guard indicators
            $('.cod-guard-total-indicator').remove();
            
            // Check if COD Guard checkbox exists and is checked
            if ($('#cod_guard_enabled').length && $('#cod_guard_enabled').is(':checked')) {
                this.showCODGuardIndicator();
            }
        },
        
        handleCheckboxChange: function() {
            var isChecked = $('#cod_guard_enabled').is(':checked');
            
            if (isChecked) {
                $('#cod-guard-breakdown').slideDown(300);
                this.showCODGuardIndicator();
                this.addVisualFeedback();
            } else {
                $('#cod-guard-breakdown').slideUp(300);
                this.removeCODGuardIndicator();
                this.removeVisualFeedback();
            }
        },
        
        /**
         * Show COD Guard indicator WITHOUT modifying the order total
         */
        showCODGuardIndicator: function() {
            // Remove existing indicators
            this.removeCODGuardIndicator();
            
            var advanceAmount = $('.cod-guard-advance-amount').val();
            var codAmount = $('.cod-guard-cod-amount').val();
            var originalTotal = $('.cod-guard-original-total').val();
            
            if (advanceAmount && codAmount && originalTotal) {
                // Add indicator AFTER order total row, not modifying it
                var indicatorHtml = '<tr class="cod-guard-total-indicator">' +
                    '<th style="color: #28a745; border-top: 2px solid #28a745; font-weight: bold;">' +
                    '<span style="display: flex; align-items: center; gap: 5px;">' +
                    '<span>🛡️</span>' +
                    'COD Guard Active:' +
                    '</span>' +
                    '</th>' +
                    '<td style="color: #28a745; border-top: 2px solid #28a745; font-weight: bold;">' +
                    '<div style="font-size: 14px; line-height: 1.3;">' +
                    '<div>Pay Now: ' + this.formatPrice(parseFloat(advanceAmount)) + '</div>' +
                    '<div>Pay on Delivery: ' + this.formatPrice(parseFloat(codAmount)) + '</div>' +
                    '</div>' +
                    '</td>' +
                    '</tr>';
                
                $('.order-total').after(indicatorHtml);
            }
        },
        
        /**
         * Remove COD Guard indicator
         */
        removeCODGuardIndicator: function() {
            $('.cod-guard-total-indicator').remove();
        },
        
        /**
         * Add visual feedback to show COD Guard is active
         */
        addVisualFeedback: function() {
            $('#cod-guard-section').addClass('cod-guard-active');
            
            // Add subtle styling to order total to show it's split
            $('.order-total').addClass('cod-guard-split-payment');
        },
        
        /**
         * Remove visual feedback
         */
        removeVisualFeedback: function() {
            $('#cod-guard-section').removeClass('cod-guard-active');
            $('.order-total').removeClass('cod-guard-split-payment');
        },
        
        handlePaymentMethodChange: function() {
            var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
            
            // Check if COD is selected - show warning
            if (selectedPaymentMethod === 'cod' && $('#cod_guard_enabled').is(':checked')) {
                this.showWarning('COD cannot be used for advance payment when COD Guard is enabled. Please select a different payment method.');
            }
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
                
                // Simple formatting
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
    
    // Add CSS for better visual feedback - NEVER modifies totals
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            /* COD Guard Active State */
            #cod-guard-section.cod-guard-active {
                border-color: #28a745 !important;
                box-shadow: 0 0 0 1px #28a745;
            }
            
            /* Visual indicator for split payment - doesn't change total */
            .order-total.cod-guard-split-payment th,
            .order-total.cod-guard-split-payment td {
                position: relative;
            }
            
            .order-total.cod-guard-split-payment th::before {
                content: "🛡️";
                position: absolute;
                left: -25px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 16px;
            }
            
            /* COD Guard indicator styling */
            .cod-guard-total-indicator th,
            .cod-guard-total-indicator td {
                background: linear-gradient(135deg, #f0fff4 0%, #e8f5e8 100%) !important;
                font-size: 14px !important;
                padding: 12px !important;
            }
            
            .cod-guard-total-indicator th {
                width: 50%;
            }
            
            /* Loading state */
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
            
            /* Error and warning styles */
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
            
            .cod-guard-total-indicator {
                animation: cod-guard-fade-in 0.5s ease;
            }
            
            @keyframes cod-guard-fade-in {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            /* Responsive adjustments */
            @media (max-width: 768px) {
                .cod-guard-total-indicator th,
                .cod-guard-total-indicator td {
                    font-size: 12px !important;
                    padding: 8px !important;
                }
                
                .order-total.cod-guard-split-payment th::before {
                    left: -20px;
                    font-size: 14px;
                }
            }
            
            /* Print styles - hide COD Guard indicators */
            @media print {
                .cod-guard-total-indicator,
                .cod-guard-error,
                .cod-guard-warning {
                    display: none !important;
                }
            }
        `)
        .appendTo('head');
    
    // Make CODGuardCheckout available globally for debugging
    window.CODGuardCheckout = CODGuardCheckout;
    
    // Console log for debugging
    CODGuardCheckout.debug('Checkout script loaded successfully - Total display preservation mode');
});