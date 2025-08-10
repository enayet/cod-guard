// COD Guard Checkbox JavaScript
// Save as assets/js/cod-guard-checkbox.js

jQuery(document).ready(function($) {
    'use strict';
    
    var CODGuardCheckbox = {
        
        init: function() {
            this.bindEvents();
            this.updateDisplay();
        },
        
        bindEvents: function() {
            // Handle checkbox change
            $('body').on('change', '#cod_guard_enabled', this.handleCheckboxChange.bind(this));
            
            // Handle payment method changes to recalculate
            $('body').on('change', 'input[name="payment_method"]', this.handlePaymentMethodChange.bind(this));
            
            // Handle checkout updates
            $('body').on('updated_checkout', this.updateBreakdown.bind(this));
            
            // Validate before form submission
            $('form.checkout').on('checkout_place_order', this.validateOrder.bind(this));
        },
        
        handleCheckboxChange: function() {
            this.updateDisplay();
            this.updateBreakdown();
        },
        
        handlePaymentMethodChange: function() {
            var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
            
            // Hide COD Guard if COD is selected (since COD can't be used for advance payment)
            if (selectedPaymentMethod === 'cod') {
                $('#cod-guard-checkbox-section').hide();
                $('#cod_guard_enabled').prop('checked', false);
                this.updateDisplay();
            } else {
                $('#cod-guard-checkbox-section').show();
            }
            
            // Update breakdown if COD Guard is enabled
            if ($('#cod_guard_enabled').is(':checked')) {
                this.updateBreakdown();
            }
        },
        
        updateDisplay: function() {
            var isChecked = $('#cod_guard_enabled').is(':checked');
            
            if (isChecked) {
                $('#cod-guard-breakdown').slideDown(300);
                this.updateOrderTotalDisplay();
            } else {
                $('#cod-guard-breakdown').slideUp(300);
                this.restoreOrderTotalDisplay();
            }
        },
        
        updateOrderTotalDisplay: function() {
            // Update the main order total display to show advance amount
            var advanceAmount = $('.cod-guard-advance-amount').val();
            
            if (advanceAmount) {
                var $orderTotal = $('.order-total .woocommerce-Price-amount');
                
                if ($orderTotal.length) {
                    // Store original total if not already stored
                    if (!$orderTotal.data('original-total')) {
                        $orderTotal.data('original-total', $orderTotal.html());
                    }
                    
                    // Format advance amount
                    var formattedAmount = this.formatPrice(parseFloat(advanceAmount));
                    $orderTotal.html(formattedAmount);
                    
                    // Add CSS for loading state and animations
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            #cod-guard-breakdown.loading {
                opacity: 0.6;
                pointer-events: none;
            }
            
            #cod-guard-breakdown.loading::after {
                content: "Updating...";
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: rgba(255, 255, 255, 0.9);
                padding: 5px 10px;
                border-radius: 3px;
                font-size: 0.8em;
                font-weight: bold;
            }
            
            .cod-guard-checkbox-wrapper {
                transition: background-color 0.2s ease;
                padding: 5px;
                border-radius: 4px;
            }
            
            .cod-guard-checkbox-wrapper:hover {
                background-color: #f0f8ff !important;
            }
            
            .cod-guard-total-notice th,
            .cod-guard-total-notice td {
                border-top: 1px solid #e2e8f0;
                padding: 8px 0;
                font-size: 0.9em;
            }
            
            .breakdown-row {
                transition: color 0.2s ease;
            }
            
            .woocommerce-error {
                background-color: #fee;
                border: 1px solid #fcc;
                border-radius: 4px;
                padding: 10px 15px;
                margin: 10px 0;
                color: #c33;
            }
        `)
        .appendTo('head');
    
    // Console log for debugging
    if (typeof window.console !== 'undefined') {
        console.log('COD Guard checkbox script loaded');
    }
});d notice
                    if (!$('.cod-guard-total-notice').length) {
                        $('.order-total').after(
                            '<tr class="cod-guard-total-notice">' +
                            '<th style="color: #28a745;">Pay Now (Advance):</th>' +
                            '<td style="color: #28a745; font-weight: bold;">' + formattedAmount + '</td>' +
                            '</tr>'
                        );
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
            
            // Show loading
            $('#cod-guard-breakdown').addClass('loading');
            
            $.ajax({
                url: codGuardAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cod_guard_calculate',
                    nonce: codGuardAjax.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        CODGuardCheckbox.updateBreakdownDisplay(response.data);
                    } else {
                        console.error('COD Guard: Failed to calculate breakdown');
                    }
                },
                error: function() {
                    console.error('COD Guard: AJAX error');
                },
                complete: function() {
                    $('#cod-guard-breakdown').removeClass('loading');
                }
            });
        },
        
        updateBreakdownDisplay: function(breakdown) {
            // Update breakdown amounts
            $('.original-total').text(this.formatPrice(breakdown.total));
            $('.advance-amount').text(this.formatPrice(breakdown.advance_amount));
            $('.cod-amount').text(this.formatPrice(breakdown.cod_amount));
            $('.advance-amount-text').text(this.formatPrice(breakdown.advance_amount));
            $('.cod-amount-text').text(this.formatPrice(breakdown.cod_amount));
            
            // Update hidden fields
            $('.cod-guard-advance-amount').val(breakdown.advance_amount);
            $('.cod-guard-cod-amount').val(breakdown.cod_amount);
            $('.cod-guard-original-total').val(breakdown.total);
            
            // Update mode label
            $('.breakdown-row:first span').text('Pay Now (' + breakdown.mode_label + '):');
            
            // Update order total display
            this.updateOrderTotalDisplay();
        },
        
        formatPrice: function(amount) {
            // Basic price formatting - you might want to enhance this based on WooCommerce settings
            return '$' + parseFloat(amount).toFixed(2);
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
        
        showError: function(message) {
            // Remove existing errors
            $('.woocommerce-error').remove();
            
            // Add error message
            var errorHtml = '<div class="woocommerce-error" role="alert">' + message + '</div>';
            
            if ($('.woocommerce-checkout').length) {
                $('.woocommerce-checkout').prepend(errorHtml);
            } else {
                $('form.checkout').prepend(errorHtml);
            }
            
            // Scroll to error
            $('html, body').animate({
                scrollTop: $('.woocommerce-error').offset().top - 100
            }, 500);
        }
    };
    
    // Initialize
    CODGuardCheckbox.init();
    
    // Ad