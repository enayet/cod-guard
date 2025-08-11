<?php
/**
 * OPTIONAL: Create includes/class-payment-handler.php
 * 
 * This handles payment gateway amount modifications separately
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Payment_Handler {
    
    public function __construct() {
        // Hook into payment processing
        add_filter('woocommerce_calculated_total', array($this, 'modify_payment_total'), 999, 2);
        add_action('woocommerce_checkout_process', array($this, 'set_payment_session'), 1);
        add_filter('woocommerce_cart_get_total', array($this, 'modify_cart_total_for_payment'), 999);
    }
    
    /**
     * Modify payment total for COD Guard orders
     */
    public function modify_payment_total($total, $cart) {
        // Only during checkout payment processing
        if (is_admin() || !is_checkout() || is_order_received_page()) {
            return $total;
        }
        
        // Check if COD Guard is active
        if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1') {
            $advance_amount = floatval($_POST['cod_guard_advance_amount']);
            
            if ($advance_amount > 0) {
                error_log('COD Guard Payment Handler: Modified total from ' . $total . ' to ' . $advance_amount);
                return $advance_amount;
            }
        }
        
        return $total;
    }
    
    /**
     * Set payment session data
     */
    public function set_payment_session() {
        if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1') {
            $advance_amount = floatval($_POST['cod_guard_advance_amount']);
            $original_total = WC()->cart->get_total('edit');
            
            WC()->session->set('cod_guard_payment_amount', $advance_amount);
            WC()->session->set('cod_guard_original_total', $original_total);
            
            error_log('COD Guard Payment Handler: Session set - Payment: ' . $advance_amount . ', Original: ' . $original_total);
        }
    }
    
    /**
     * Modify cart total for payment gateway
     */
    public function modify_cart_total_for_payment($total) {
        // Only during payment processing
        if (is_admin() || !doing_action('woocommerce_checkout_process')) {
            return $total;
        }
        
        $payment_amount = WC()->session->get('cod_guard_payment_amount');
        
        if ($payment_amount) {
            error_log('COD Guard Payment Handler: Cart total modified to ' . $payment_amount);
            return wc_price($payment_amount);
        }
        
        return $total;
    }
}

// Initialize the payment handler
new COD_Guard_Payment_Handler();