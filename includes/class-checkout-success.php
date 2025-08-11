<?php
/**
 * COD Guard Checkout Success Handler - COMPLETE FIX
 * Replace includes/class-checkout-success.php with this version
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
        // CRITICAL: Handle checkout process early
        add_action('woocommerce_checkout_process', array($this, 'prepare_cod_guard_checkout'), 1);
        
        // Handle order creation and processing
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_order_processed'), 1, 3);
        
        // CRITICAL: Fix checkout redirect
        add_filter('woocommerce_checkout_no_payment_needed_redirect', array($this, 'fix_checkout_redirect'), 10, 2);
        add_filter('woocommerce_get_checkout_order_received_url', array($this, 'fix_received_url'), 10, 2);
        
        // Handle thank you page
        add_action('woocommerce_thankyou', array($this, 'display_success_message'), 1);
        
        // CRITICAL: Prevent error notices for COD Guard orders
        add_action('woocommerce_before_checkout_form', array($this, 'clear_error_notices'));
        add_action('wp_loaded', array($this, 'handle_checkout_redirect_check'));
        
        // Fix AJAX checkout
        add_action('wp_ajax_woocommerce_checkout', array($this, 'intercept_ajax_checkout'), 1);
        add_action('wp_ajax_nopriv_woocommerce_checkout', array($this, 'intercept_ajax_checkout'), 1);
        
        // Handle cart clearing
        add_action('woocommerce_checkout_update_order_meta', array($this, 'force_cart_clear'), 999, 2);
    }
    
    /**
     * CRITICAL: Prepare COD Guard checkout - runs very early
     */
    public function prepare_cod_guard_checkout() {
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        // Set session flag early
        WC()->session->set('cod_guard_processing', true);
        WC()->session->set('cod_guard_original_total', WC()->cart->get_total('edit'));
        
        // Clear any existing error notices that might interfere
        wc_clear_notices();
        
        error_log('COD Guard: Checkout preparation completed');
    }
    
    /**
     * CRITICAL: Handle order processed - runs first
     */
    public function handle_order_processed($order_id, $posted_data, $order) {
        // Only handle COD Guard orders
        if (!WC()->session->get('cod_guard_processing')) {
            return;
        }
        
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        // Mark order as COD Guard order
        $order->update_meta_data('_cod_guard_checkout_success', 'yes');
        $order->save();
        
        // CRITICAL: Clear cart immediately
        if (WC()->cart) {
            WC()->cart->empty_cart();
            error_log('COD Guard: Cart cleared for order ' . $order_id);
        }
        
        // Clear any error notices
        wc_clear_notices();
        
        // Set success session
        WC()->session->set('cod_guard_order_success', $order_id);
        WC()->session->set('cod_guard_success_timestamp', time());
        
        // Clear processing flag
        WC()->session->__unset('cod_guard_processing');
        
        error_log('COD Guard: Order ' . $order_id . ' processed successfully');
    }
    
    /**
     * CRITICAL: Fix checkout redirect
     */
    public function fix_checkout_redirect($redirect_url, $order) {
        if ($order && $order->get_meta('_cod_guard_checkout_success') === 'yes') {
            $redirect_url = $order->get_checkout_order_received_url();
            $redirect_url = add_query_arg('cod_guard_success', '1', $redirect_url);
            error_log('COD Guard: Redirect fixed to: ' . $redirect_url);
        }
        return $redirect_url;
    }
    
    /**
     * Fix received URL
     */
    public function fix_received_url($url, $order) {
        if ($order && COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            $url = add_query_arg('cod_guard_success', '1', $url);
        }
        return $url;
    }
    
    /**
     * CRITICAL: Clear error notices that interfere with COD Guard
     */
    public function clear_error_notices() {
        // Check if this is a COD Guard checkout completion
        $success_order_id = WC()->session->get('cod_guard_order_success');
        
        if ($success_order_id && WC()->cart->is_empty()) {
            // Clear all error notices for COD Guard orders
            wc_clear_notices();
            
            // Redirect to thank you page
            $order = wc_get_order($success_order_id);
            if ($order) {
                $redirect_url = add_query_arg('cod_guard_success', '1', $order->get_checkout_order_received_url());
                wp_redirect($redirect_url);
                exit;
            }
        }
    }
    
    /**
     * Handle checkout redirect check
     */
    public function handle_checkout_redirect_check() {
        // Check if we're on checkout page with empty cart but have COD Guard success
        if (is_checkout() && !is_order_received_page()) {
            $success_order_id = WC()->session->get('cod_guard_order_success');
            
            if ($success_order_id && WC()->cart->is_empty()) {
                $order = wc_get_order($success_order_id);
                if ($order && COD_Guard_WooCommerce::is_cod_guard_order($order)) {
                    // Clear notices and redirect
                    wc_clear_notices();
                    $redirect_url = add_query_arg('cod_guard_success', '1', $order->get_checkout_order_received_url());
                    wp_redirect($redirect_url);
                    exit;
                }
            }
        }
    }
    
    /**
     * CRITICAL: Intercept AJAX checkout for COD Guard orders
     */
    public function intercept_ajax_checkout() {
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        // Add filter to modify checkout result
        add_filter('woocommerce_checkout_posted_data', array($this, 'mark_ajax_checkout'));
        
        // Hook into order creation for AJAX
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_ajax_success'), 999, 3);
    }
    
    /**
     * Mark AJAX checkout
     */
    public function mark_ajax_checkout($data) {
        $data['_cod_guard_ajax'] = true;
        return $data;
    }
    
    /**
     * Handle AJAX success - send proper response
     */
    public function handle_ajax_success($order_id, $posted_data, $order) {
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        if (!wp_doing_ajax()) {
            return;
        }
        
        if ($order && COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            // Clear output buffer
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Send success response
            wp_send_json_success(array(
                'result' => 'success',
                'redirect' => add_query_arg('cod_guard_success', '1', $order->get_checkout_order_received_url())
            ));
        }
    }
    
    /**
     * Force cart clear for COD Guard orders
     */
    public function force_cart_clear($order_id, $data) {
        $order = wc_get_order($order_id);
        
        if ($order && COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            // Force empty cart
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
            
            // Clear any error notices
            wc_clear_notices();
            
            error_log('COD Guard: Force cart clear for order ' . $order_id);
        }
    }
    
    /**
     * Display success message on thank you page
     */
    public function display_success_message($order_id) {
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order || !COD_Guard_WooCommerce::is_cod_guard_order($order)) {
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
            
            error_log('COD Guard: Success message displayed for order ' . $order_id);
        }
    }
}