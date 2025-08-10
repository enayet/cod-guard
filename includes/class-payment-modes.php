<?php
/**
 * COD Guard Payment Modes - FIXED VERSION
 * 
 * Replace your class-payment-modes.php with this
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Payment_Modes {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize gateway immediately, not waiting for woocommerce_loaded
        add_action('plugins_loaded', array($this, 'init_gateway'), 11);
        
        // Also add it to woocommerce_loaded as backup
        add_action('woocommerce_loaded', array($this, 'init_gateway'));
        
        // Add admin notices for payment method configuration
        add_action('admin_notices', array($this, 'check_advance_payment_methods'));
    }
    
    /**
     * Initialize the gateway
     */
    public function init_gateway() {
        // Make sure WooCommerce is active
        if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) {
            return;
        }
        
        // Include the gateway class first
        $this->include_gateway_class();
        
        // Then add it to the list of gateways
        add_filter('woocommerce_payment_gateways', array($this, 'add_payment_gateway'));
    }
    
    /**
     * Include the gateway class
     */
    private function include_gateway_class() {
        if (!class_exists('COD_Guard_Payment_Gateway')) {
            require_once COD_GUARD_PLUGIN_PATH . 'includes/class-payment-gateway.php';
        }
    }
    
    /**
     * Add COD Guard payment gateway
     */
    public function add_payment_gateway($gateways) {
        if (class_exists('COD_Guard_Payment_Gateway')) {
            $settings = COD_Guard_WooCommerce::get_settings();
            $cod_behavior = get_option('cod_guard_cod_behavior', 'replace');
            
            // Add COD Guard gateway
            $gateways[] = 'COD_Guard_Payment_Gateway';
            
            // If set to replace, remove default COD when COD Guard is enabled
            if ($cod_behavior === 'replace' && $settings['enabled'] === 'yes') {
                // Remove COD from available gateways
                add_filter('woocommerce_available_payment_gateways', array($this, 'maybe_remove_cod'), 10, 1);
            }
        }
        return $gateways;
    }
    
    /**
     * Maybe remove COD gateway if COD Guard is replacing it
     */
    public function maybe_remove_cod($gateways) {
        $settings = COD_Guard_WooCommerce::get_settings();
        $cod_behavior = get_option('cod_guard_cod_behavior', 'replace');
        
        // Only remove COD if COD Guard is enabled and set to replace
        if ($settings['enabled'] === 'yes' && $cod_behavior === 'replace') {
            // Check if COD Guard is available for current cart
            if (isset($gateways['cod_guard']) && $gateways['cod_guard']->is_available()) {
                // Remove default COD
                if (isset($gateways['cod'])) {
                    unset($gateways['cod']);
                }
            }
        }
        
        return $gateways;
    }
    
    /**
     * Get advance amount for an order
     */
    public static function get_advance_amount($order) {
        if (!$order) {
            return 0;
        }
        
        return floatval($order->get_meta('_cod_guard_advance_amount'));
    }
    
    /**
     * Get COD amount for an order
     */
    public static function get_cod_amount($order) {
        if (!$order) {
            return 0;
        }
        
        return floatval($order->get_meta('_cod_guard_cod_amount'));
    }
    
    /**
     * Get payment mode for an order
     */
    public static function get_payment_mode($order) {
        if (!$order) {
            return '';
        }
        
        return $order->get_meta('_cod_guard_payment_mode');
    }
    
    /**
     * Check if order uses COD Guard
     */
    public static function is_cod_guard_order($order) {
        if (!$order) {
            return false;
        }
        
        return $order->get_meta('_cod_guard_enabled') === 'yes';
    }
    
    /**
     * Check if advance payment methods are configured
     */
    public function check_advance_payment_methods() {
        // Only show on WooCommerce admin pages
        if (!is_admin() || !function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('woocommerce_page_wc-settings', 'edit-shop_order', 'shop_order'))) {
            return;
        }
        
        $settings = COD_Guard_WooCommerce::get_settings();
        
        // Only check if COD Guard is enabled
        if ($settings['enabled'] !== 'yes') {
            return;
        }
        
        // Check if there are available payment methods for advance payment
        $available_gateways = array();
        if (class_exists('WooCommerce') && WC()->payment_gateways) {
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            foreach ($gateways as $id => $gateway) {
                if ($id !== 'cod' && $id !== 'cod_guard' && $gateway->enabled === 'yes') {
                    $available_gateways[] = $id;
                }
            }
        }
        
        if (empty($available_gateways)) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php _e('COD Guard Warning:', 'cod-guard-wc'); ?></strong>
                    <?php 
                    printf(
                        __('No payment methods are available for advance payments. Please enable at least one payment gateway (like Stripe, PayPal, etc.) in %1$sWooCommerce Settings%2$s.', 'cod-guard-wc'),
                        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">',
                        '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }
}