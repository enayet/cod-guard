<?php
/**
 * COD Guard Checkout Success Handler
 * Save as: includes/class-checkout-success.php
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Checkout_Success {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Checkout completion and redirect handling
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_order_success'), 999, 3);
        
        // Fix checkout redirect
        add_filter('woocommerce_checkout_no_payment_needed_redirect', array($this, 'fix_checkout_redirect'), 10, 2);
        
        // Handle thank you page
        add_action('woocommerce_thankyou', array($this, 'display_success_message'), 1);
        
        // Handle cart page success messages
        add_action('woocommerce_before_cart', array($this, 'handle_cart_success_message'));
        
        // Fix checkout form errors
        add_action('woocommerce_before_checkout_form', array($this, 'handle_checkout_errors'));
        
        // Handle AJAX checkout responses
        add_action('wp_ajax_woocommerce_checkout', array($this, 'handle_ajax_checkout'), 1);
        add_action('wp_ajax_nopriv_woocommerce_checkout', array($this, 'handle_ajax_checkout'), 1);
        
        // Clean up checkout process
        add_action('woocommerce_checkout_process', array($this, 'cleanup_checkout_process'), 1);
        
        // Handle checkout posted data
        add_filter('woocommerce_checkout_posted_data', array($this, 'mark_cod_guard_checkout'));
    }
    
    /**
     * Handle order success - final step
     */
    public function handle_order_success($order_id, $posted_data, $order) {
        // Only handle COD Guard orders
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        if (!$order || $order->get_meta('_cod_guard_enabled') !== 'yes') {
            return;
        }
        
        // CRITICAL: Clear cart immediately
        if (WC()->cart && !WC()->cart->is_empty()) {
            WC()->cart->empty_cart();
            error_log('COD Guard Success: Cart cleared for order ' . $order_id);
        }
        
        // Clear any existing error notices
        wc_clear_notices();
        
        // Set session flag for success
        WC()->session->set('cod_guard_order_success', $order_id);
        WC()->session->set('cod_guard_success_timestamp', time());
        
        error_log('COD Guard Success: Order success handled for ' . $order_id);
    }
    
    /**
     * Fix checkout redirect
     */
    public function fix_checkout_redirect($redirect_url, $order) {
        // Check if this is a COD Guard order
        if ($order && $order->get_meta('_cod_guard_enabled') === 'yes') {
            // Force redirect to thank you page
            $redirect_url = $order->get_checkout_order_received_url();
            $redirect_url = add_query_arg('cod_guard_success', '1', $redirect_url);
            error_log('COD Guard Success: Redirect URL set to: ' . $redirect_url);
        }
        return $redirect_url;
    }
    
    /**
     * Display success message on thank you page
     */
    public function display_success_message($order_id) {
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_cod_guard_enabled') !== 'yes') {
            return;
        }
        
        // Check if this was just processed or has success parameter
        $success_order_id = WC()->session->get('cod_guard_order_success');
        $has_success_param = isset($_GET['cod_guard_success']);
        
        if ($success_order_id == $order_id || $has_success_param) {
            // Clear the session flag
            WC()->session->__unset('cod_guard_order_success');
            WC()->session->__unset('cod_guard_success_timestamp');
            
            // Remove any error notices
            wc_clear_notices();
            
            // Display success message
            $cod_amount = $order->get_meta('_cod_guard_cod_amount');
            $advance_amount = $order->get_meta('_cod_guard_advance_amount');
            
            echo '<div class="cod-guard-success-notice" style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; margin: 20px 0; border-radius: 8px; text-align: center;">';
            echo '<h2 style="margin-top: 0; color: #155724;">🎉 ' . __('Payment Successful!', 'cod-guard-wc') . '</h2>';
            
            echo '<div style="background: white; padding: 15px; border-radius: 6px; margin: 15px 0;">';
            echo '<h3 style="margin-top: 0; color: #333;">' . __('Payment Summary', 'cod-guard-wc') . '</h3>';
            echo '<p><strong>' . __('Advance Payment Paid:', 'cod-guard-wc') . '</strong> ' . wc_price($advance_amount) . ' ✅</p>';
            
            if ($cod_amount > 0) {
                echo '<p><strong>' . __('Balance on Delivery:', 'cod-guard-wc') . '</strong> ' . wc_price($cod_amount) . ' 📦</p>';
                echo '<div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0;">';
                echo '<p style="margin: 0; color: #856404;"><strong>' . __('Important:', 'cod-guard-wc') . '</strong> ';
                printf(__('Please pay %s to the delivery person when your order arrives.', 'cod-guard-wc'), '<strong>' . wc_price($cod_amount) . '</strong>');
                echo '</p></div>';
            } else {
                echo '<p style="color: #28a745;"><strong>' . __('Order fully paid!', 'cod-guard-wc') . '</strong> ✅</p>';
            }
            echo '</div>';
            
            echo '<p><a href="' . esc_url($order->get_view_order_url()) . '" class="button button-primary" style="background: #28a745; border-color: #28a745;">' . __('View Order Details', 'cod-guard-wc') . '</a></p>';
            echo '</div>';
            
            error_log('COD Guard Success: Success message displayed for order ' . $order_id);
        }
    }
    
    /**
     * Handle cart page success messages
     */
    public function handle_cart_success_message() {
        // Check if there's a success message to show
        $success_order_id = WC()->session->get('cod_guard_order_success');
        $success_timestamp = WC()->session->get('cod_guard_success_timestamp');
        
        // Only show if the success is recent (within 5 minutes)
        if ($success_order_id && $success_timestamp && (time() - $success_timestamp) < 300) {
            $order = wc_get_order($success_order_id);
            if ($order && $order->get_meta('_cod_guard_enabled') === 'yes') {
                $cod_amount = $order->get_meta('_cod_guard_cod_amount');
                
                // Clear the session
                WC()->session->__unset('cod_guard_order_success');
                WC()->session->__unset('cod_guard_success_timestamp');
                
                // Show success message and redirect to thank you page
                echo '<div class="woocommerce-message cod-guard-cart-success" style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 20px 0; border-radius: 5px;">';
                echo '<h3 style="margin-top: 0;">✅ ' . __('Order Completed Successfully!', 'cod-guard-wc') . '</h3>';
                echo '<p style="margin-bottom: 10px;">';
                printf(
                    __('Your advance payment has been processed for Order #%s. Please pay %s to the delivery person when your order arrives.', 'cod-guard-wc'),
                    $order->get_order_number(),
                    '<strong>' . wc_price($cod_amount) . '</strong>'
                );
                echo '</p>';
                echo '<p><a href="' . esc_url($order->get_checkout_order_received_url()) . '" class="button">' . __('View Order Details', 'cod-guard-wc') . '</a></p>';
                echo '</div>';
                
                // Auto redirect to thank you page after 3 seconds
                echo '<script>
                setTimeout(function() {
                    window.location.href = "' . esc_url($order->get_checkout_order_received_url() . '?cod_guard_success=1') . '";
                }, 3000);
                </script>';
            }
        }
    }
    
    /**
     * Handle checkout errors
     */
    public function handle_checkout_errors() {
        // If cart is empty but there are error notices, it might be a COD Guard order
        if (WC()->cart->is_empty()) {
            $notices = wc_get_notices('error');
            if (!empty($notices)) {
                // Check if any of the notices are about processing errors
                $has_processing_error = false;
                foreach ($notices as $notice) {
                    $message = is_array($notice) ? $notice['notice'] : $notice;
                    if (strpos($message, 'error processing') !== false || 
                        strpos($message, 'review your order') !== false) {
                        $has_processing_error = true;
                        break;
                    }
                }
                
                if ($has_processing_error) {
                    // Clear the error and redirect to cart
                    wc_clear_notices();
                    wc_add_notice(__('Your order may have been processed. Please check your order history.', 'cod-guard-wc'), 'notice');
                    wp_redirect(wc_get_cart_url());
                    exit;
                }
            }
        }
    }
    
    /**
     * Handle AJAX checkout
     */
    public function handle_ajax_checkout() {
        if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1') {
            // Add filter to modify the checkout result
            add_filter('woocommerce_checkout_posted_data', array($this, 'mark_cod_guard_ajax'));
            
            // Hook into checkout completion for AJAX
            add_action('woocommerce_checkout_order_processed', array($this, 'handle_ajax_completion'), 1000, 3);
        }
    }
    
    /**
     * Handle AJAX checkout completion
     */
    public function handle_ajax_completion($order_id, $posted_data, $order) {
        if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1' && wp_doing_ajax()) {
            // Clear any output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Ensure order exists and is COD Guard
            if ($order && $order->get_meta('_cod_guard_enabled') === 'yes') {
                // Send success response
                wp_send_json_success(array(
                    'result' => 'success',
                    'redirect' => add_query_arg('cod_guard_success', '1', $order->get_checkout_order_received_url())
                ));
            }
        }
    }
    
    /**
     * Clean up checkout process
     */
    public function cleanup_checkout_process() {
        if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1') {
            // Clear any conflicting notices
            $notices = WC()->session->get('wc_notices', array());
            if (isset($notices['error'])) {
                $notices['error'] = array_filter($notices['error'], function($notice) {
                    $notice_text = is_array($notice) ? $notice['notice'] : $notice;
                    return strpos($notice_text, 'processing your order') === false;
                });
                WC()->session->set('wc_notices', $notices);
            }
        }
    }
    
    /**
     * Mark COD Guard checkout
     */
    public function mark_cod_guard_checkout($data) {
        if (isset($data['cod_guard_enabled']) && $data['cod_guard_enabled'] === '1') {
            $data['_cod_guard_checkout'] = '1';
        }
        return $data;
    }
    
    /**
     * Mark COD Guard AJAX
     */
    public function mark_cod_guard_ajax($data) {
        $data['_is_cod_guard_ajax'] = true;
        return $data;
    }
}