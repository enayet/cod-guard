<?php
/**
 * COD Guard Payment Handler - FIXED VERSION (Session Safety)
 * Replace includes/class-payment-handler.php with this version
 * 
 * This handles payment gateway amount modifications without affecting display totals
 * Fixed: Added proper session checks to prevent null session errors in admin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Payment_Handler {
    
    public function __construct() {
        // Only hook payment modification on frontend checkout
        if (!is_admin()) {
            // Hook into specific payment gateway processing
            add_action('woocommerce_checkout_process', array($this, 'prepare_payment_modification'), 1);
            
            // Modify order total ONLY for payment gateways during processing
            add_filter('woocommerce_order_get_total', array($this, 'modify_order_total_for_payment'), 999, 2);
            
            // Hook into specific payment gateways to modify amounts
            add_action('woocommerce_before_pay_action', array($this, 'modify_payment_gateway_amount'));
            
            // Hook into payment processing for popular gateways
            $this->hook_payment_gateways();
        }
        
        // These can run everywhere
        add_filter('woocommerce_payment_complete_order_status', array($this, 'handle_payment_complete_status'), 10, 3);
    }
    
    /**
     * Check if session is available and active
     */
    private function is_session_available() {
        return !is_admin() && WC()->session && WC()->session instanceof WC_Session;
    }
    
    /**
     * Safely get session data
     */
    private function get_session_data($key, $default = null) {
        if (!$this->is_session_available()) {
            return $default;
        }
        
        return WC()->session->get($key, $default);
    }
    
    /**
     * Safely set session data
     */
    private function set_session_data($key, $value) {
        if (!$this->is_session_available()) {
            return false;
        }
        
        WC()->session->set($key, $value);
        return true;
    }
    
    /**
     * Prepare payment modification flags
     */
    public function prepare_payment_modification() {
        if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1') {
            $advance_amount = floatval($_POST['cod_guard_advance_amount']);
            $original_total = WC()->cart->get_total('edit');
            
            // Set flags for payment processing (only if session is available)
            $this->set_session_data('cod_guard_payment_processing', true);
            $this->set_session_data('cod_guard_gateway_amount', $advance_amount);
            $this->set_session_data('cod_guard_original_total', $original_total);
            
            error_log('COD Guard Payment Handler: Prepared for payment modification - Gateway: ' . $advance_amount . ', Original: ' . $original_total);
        }
    }
    
    /**
     * Modify order total ONLY when payment gateway requests it
     */
    public function modify_order_total_for_payment($total, $order) {
        // Skip if session not available (admin area, etc.)
        if (!$this->is_session_available()) {
            return $total;
        }
        
        // Only modify during payment processing
        if (!$this->get_session_data('cod_guard_payment_processing')) {
            return $total;
        }
        
        // Check if this order uses COD Guard
        if (!$order || !COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            return $total;
        }
        
        // Get the backtrace to see if we're in payment gateway processing
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        $in_payment_gateway = false;
        
        foreach ($backtrace as $trace) {
            if (isset($trace['class']) && isset($trace['function'])) {
                $class = $trace['class'];
                $function = $trace['function'];
                
                // Check for payment gateway processing
                if (strpos($class, 'WC_Gateway') !== false || 
                    strpos($class, 'Payment_Gateway') !== false ||
                    strpos($class, 'WC_Payment') !== false ||
                    $function === 'process_payment' ||
                    $function === 'payment_complete' ||
                    strpos($function, 'charge') !== false ||
                    strpos($function, 'pay') !== false) {
                    $in_payment_gateway = true;
                    break;
                }
            }
        }
        
        if ($in_payment_gateway) {
            $gateway_amount = $this->get_session_data('cod_guard_gateway_amount');
            if ($gateway_amount) {
                error_log('COD Guard Payment Handler: Modified order total for payment gateway from ' . $total . ' to ' . $gateway_amount);
                return $gateway_amount;
            }
        }
        
        return $total;
    }
    
    /**
     * Handle payment complete status
     */
    public function handle_payment_complete_status($status, $order_id, $order) {
        if (!$order || !COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            return $status;
        }
        
        $cod_amount = COD_Guard_WooCommerce::get_cod_amount($order);
        
        // If there's COD balance, set to advance-paid status
        if ($cod_amount > 0) {
            return 'advance-paid';
        }
        
        // If no COD balance, complete the order
        return 'completed';
    }
    
    /**
     * Modify payment gateway amount before payment action
     */
    public function modify_payment_gateway_amount() {
        if (!$this->get_session_data('cod_guard_payment_processing')) {
            return;
        }
        
        $gateway_amount = $this->get_session_data('cod_guard_gateway_amount');
        if ($gateway_amount) {
            // Hook into common payment gateway filters
            add_filter('woocommerce_paypal_args', array($this, 'modify_paypal_args'), 999);
            add_filter('woocommerce_stripe_payment_intent_args', array($this, 'modify_stripe_args'), 999);
        }
    }
    
    /**
     * Hook into popular payment gateways
     */
    private function hook_payment_gateways() {
        // PayPal Standard
        add_filter('woocommerce_paypal_args', array($this, 'modify_paypal_args'), 999);
        
        // Stripe
        add_filter('woocommerce_stripe_payment_intent_args', array($this, 'modify_stripe_args'), 999);
        add_filter('wc_stripe_payment_intent_args', array($this, 'modify_stripe_args'), 999);
        
        // Square
        add_filter('wc_square_payment_request_total', array($this, 'modify_square_total'), 999);
        
        // Razorpay
        add_filter('woocommerce_razorpay_checkout_data', array($this, 'modify_razorpay_args'), 999);
        
        // PayU
        add_filter('woocommerce_payu_payment_args', array($this, 'modify_payu_args'), 999);
        
        // General gateway args filter
        add_filter('woocommerce_gateway_payment_args', array($this, 'modify_gateway_args'), 999, 2);
    }
    
    /**
     * Modify PayPal payment arguments
     */
    public function modify_paypal_args($args) {
        if (!$this->get_session_data('cod_guard_payment_processing')) {
            return $args;
        }
        
        $gateway_amount = $this->get_session_data('cod_guard_gateway_amount');
        if ($gateway_amount && isset($args['amount'])) {
            $args['amount'] = number_format($gateway_amount, 2, '.', '');
            error_log('COD Guard Payment Handler: Modified PayPal amount to ' . $gateway_amount);
        }
        
        return $args;
    }
    
    /**
     * Modify Stripe payment arguments
     */
    public function modify_stripe_args($args) {
        if (!$this->get_session_data('cod_guard_payment_processing')) {
            return $args;
        }
        
        $gateway_amount = $this->get_session_data('cod_guard_gateway_amount');
        if ($gateway_amount && isset($args['amount'])) {
            // Stripe amounts are in cents/smallest currency unit
            $args['amount'] = intval($gateway_amount * 100);
            error_log('COD Guard Payment Handler: Modified Stripe amount to ' . $args['amount'] . ' (cents)');
        }
        
        return $args;
    }
    
    /**
     * Modify Square payment total
     */
    public function modify_square_total($total) {
        if (!$this->get_session_data('cod_guard_payment_processing')) {
            return $total;
        }
        
        $gateway_amount = $this->get_session_data('cod_guard_gateway_amount');
        if ($gateway_amount) {
            error_log('COD Guard Payment Handler: Modified Square total to ' . $gateway_amount);
            return $gateway_amount;
        }
        
        return $total;
    }
    
    /**
     * Modify Razorpay checkout data
     */
    public function modify_razorpay_args($data) {
        if (!$this->get_session_data('cod_guard_payment_processing')) {
            return $data;
        }
        
        $gateway_amount = $this->get_session_data('cod_guard_gateway_amount');
        if ($gateway_amount && isset($data['amount'])) {
            // Razorpay amounts are in smallest currency unit (paise for INR)
            $data['amount'] = intval($gateway_amount * 100);
            error_log('COD Guard Payment Handler: Modified Razorpay amount to ' . $data['amount']);
        }
        
        return $data;
    }
    
    /**
     * Modify PayU payment arguments
     */
    public function modify_payu_args($args) {
        if (!$this->get_session_data('cod_guard_payment_processing')) {
            return $args;
        }
        
        $gateway_amount = $this->get_session_data('cod_guard_gateway_amount');
        if ($gateway_amount && isset($args['amount'])) {
            $args['amount'] = number_format($gateway_amount, 2, '.', '');
            error_log('COD Guard Payment Handler: Modified PayU amount to ' . $gateway_amount);
        }
        
        return $args;
    }
    
    /**
     * Modify general gateway arguments
     */
    public function modify_gateway_args($args, $gateway_id) {
        if (!$this->get_session_data('cod_guard_payment_processing')) {
            return $args;
        }
        
        $gateway_amount = $this->get_session_data('cod_guard_gateway_amount');
        if ($gateway_amount) {
            // Try to modify common amount fields
            $amount_fields = array('amount', 'total', 'value', 'sum', 'payment_amount');
            
            foreach ($amount_fields as $field) {
                if (isset($args[$field])) {
                    $args[$field] = number_format($gateway_amount, 2, '.', '');
                    error_log('COD Guard Payment Handler: Modified ' . $gateway_id . ' ' . $field . ' to ' . $gateway_amount);
                }
            }
        }
        
        return $args;
    }
    
    /**
     * Clean up after payment processing
     */
    public function cleanup_payment_session() {
        if ($this->is_session_available()) {
            WC()->session->__unset('cod_guard_payment_processing');
            WC()->session->__unset('cod_guard_gateway_amount');
        }
    }
}