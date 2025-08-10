<?php
/**
 * FIXED COD Guard Payment Gateway
 * Replace your class-payment-gateway.php with this version
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Payment_Gateway extends WC_Payment_Gateway {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'cod_guard';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = __('COD Guard (COD with Advance Payment)', 'cod-guard-wc');
        $this->method_description = __('Replaces standard COD with advance payment requirement.', 'cod-guard-wc');
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Get settings from COD Guard settings
        $settings = COD_Guard_WooCommerce::get_settings();
        $this->title = $settings['title'];
        $this->description = $settings['description'];
        $this->enabled = $settings['enabled'];
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        
        // CRITICAL FIX: Hook early to modify payment method selection
        add_action('woocommerce_checkout_process', array($this, 'fix_payment_method_early'), 1);
    }
    
    /**
     * CRITICAL FIX: Modify payment method before validation
     */
    public function fix_payment_method_early() {
        if (isset($_POST['payment_method']) && $_POST['payment_method'] === 'cod_guard') {
            if (isset($_POST['cod_guard_advance_method']) && !empty($_POST['cod_guard_advance_method'])) {
                // Switch to the selected advance payment method
                $_POST['payment_method'] = sanitize_text_field($_POST['cod_guard_advance_method']);
                
                // Store original selection for our processing
                $_POST['_original_payment_method'] = 'cod_guard';
                
                error_log('COD Guard: Switched payment method from cod_guard to ' . $_POST['payment_method']);
            }
        }
    }
    
    /**
     * Enqueue payment scripts and styles
     */
    public function payment_scripts() {
        if (!is_checkout()) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'cod-guard-checkout',
            COD_GUARD_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            COD_GUARD_VERSION
        );
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'cod-guard-checkout',
            COD_GUARD_PLUGIN_URL . 'assets/js/checkout.js',
            array('jquery', 'wc-checkout'),
            COD_GUARD_VERSION,
            true
        );
        
        // Localize script with data
        wp_localize_script('cod-guard-checkout', 'codGuardCheckout', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cod_guard_checkout'),
            'strings' => array(
                'select_payment_method' => __('Please select a payment method for advance payment.', 'cod-guard-wc'),
                'processing' => __('Processing...', 'cod-guard-wc'),
                'error' => __('An error occurred. Please try again.', 'cod-guard-wc'),
            ),
        ));
    }
    
    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'cod-guard-wc'),
                'type'    => 'checkbox',
                'label'   => __('Enable COD Guard', 'cod-guard-wc'),
                'default' => 'no',
                'description' => __('This will replace the default COD method.', 'cod-guard-wc'),
            )
        );
    }
    
    /**
     * Check if gateway is available
     */
    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }
        
        if (!WC()->cart || WC()->cart->is_empty()) {
            return false;
        }
        
        // Check minimum order amount
        $settings = COD_Guard_WooCommerce::get_settings();
        $minimum_amount = floatval($settings['minimum_order_amount']);
        
        if ($minimum_amount > 0 && WC()->cart->get_total('edit') < $minimum_amount) {
            return false;
        }
        
        // Check category restrictions
        if (!$this->is_available_for_cart_categories()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if available for cart categories
     */
    private function is_available_for_cart_categories() {
        $settings = COD_Guard_WooCommerce::get_settings();
        $allowed_categories = $settings['enable_for_categories'];
        
        if (empty($allowed_categories)) {
            return true;
        }
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_categories = wc_get_product_cat_ids($product->get_id());
            
            if (array_intersect($product_categories, $allowed_categories)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        // Display description
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }
        
        // Get payment breakdown
        $breakdown = $this->get_payment_breakdown();
        
        if ($breakdown) {
            echo '<div class="cod-guard-payment-info">';
            echo '<div class="cod-guard-breakdown" style="background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; border-radius: 4px; margin: 15px 0;">';
            
            echo '<h4 style="margin-top: 0; color: #495057;">' . __('Payment Breakdown', 'cod-guard-wc') . '</h4>';
            
            echo '<div style="display: flex; justify-content: space-between; margin: 8px 0;">';
            echo '<span>' . __('Order Total:', 'cod-guard-wc') . '</span>';
            echo '<strong>' . wc_price($breakdown['total']) . '</strong>';
            echo '</div>';
            
            echo '<hr style="margin: 10px 0;">';
            
            echo '<div style="display: flex; justify-content: space-between; margin: 8px 0; color: #28a745;">';
            echo '<span><strong>' . sprintf(__('Advance Payment (%s):', 'cod-guard-wc'), $breakdown['mode_label']) . '</strong></span>';
            echo '<strong>' . wc_price($breakdown['advance_amount']) . '</strong>';
            echo '</div>';
            
            echo '<div style="display: flex; justify-content: space-between; margin: 8px 0; color: #ffc107;">';
            echo '<span><strong>' . __('Balance on Delivery:', 'cod-guard-wc') . '</strong></span>';
            echo '<strong>' . wc_price($breakdown['cod_amount']) . '</strong>';
            echo '</div>';
            
            echo '<div style="background: #d1ecf1; padding: 10px; border-radius: 3px; margin-top: 15px;">';
            echo '<small><strong>' . __('How it works:', 'cod-guard-wc') . '</strong><br>';
            echo '1. ' . sprintf(__('Pay %s now to confirm your order', 'cod-guard-wc'), wc_price($breakdown['advance_amount'])) . '<br>';
            echo '2. ' . sprintf(__('Pay remaining %s to delivery person', 'cod-guard-wc'), wc_price($breakdown['cod_amount'])) . '</small>';
            echo '</div>';
            
            echo '</div>';
            
            // Show available payment methods for advance payment
            echo '<div class="cod-guard-advance-payment" style="margin-top: 20px;">';
            echo '<h4>' . __('Choose payment method for advance payment:', 'cod-guard-wc') . '</h4>';
            echo $this->get_advance_payment_methods_html();
            echo '</div>';
            
            // Hidden fields for processing
            echo '<input type="hidden" name="cod_guard_advance_amount" value="' . esc_attr($breakdown['advance_amount']) . '" />';
            echo '<input type="hidden" name="cod_guard_cod_amount" value="' . esc_attr($breakdown['cod_amount']) . '" />';
            echo '<input type="hidden" name="cod_guard_payment_mode" value="' . esc_attr($breakdown['payment_mode']) . '" />';
            
            echo '</div>';
        }
    }
    
    /**
     * Get available payment methods for advance payment
     */
    private function get_advance_payment_methods_html() {
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        $allowed_methods = get_option('cod_guard_advance_payment_methods', array());
        
        if (empty($allowed_methods)) {
            // Default to all available gateways except COD
            $allowed_methods = array();
            foreach ($available_gateways as $id => $gateway) {
                if ($id !== 'cod' && $id !== 'cod_guard') {
                    $allowed_methods[] = $id;
                }
            }
        }
        
        $html = '<div class="cod-guard-payment-methods">';
        
        if (empty($allowed_methods)) {
            $html .= '<p style="color: #dc3545;"><strong>' . __('No payment methods available for advance payment. Please configure them in COD Guard settings.', 'cod-guard-wc') . '</strong></p>';
        } else {
            foreach ($allowed_methods as $method_id) {
                if (isset($available_gateways[$method_id])) {
                    $gateway = $available_gateways[$method_id];
                    $html .= '<label style="display: block; margin: 8px 0; padding: 8px; border: 1px solid #ddd; border-radius: 3px; cursor: pointer;">';
                    $html .= '<input type="radio" name="cod_guard_advance_method" value="' . esc_attr($method_id) . '" style="margin-right: 8px;" required>';
                    $html .= '<strong>' . esc_html($gateway->get_title()) . '</strong>';
                    if ($gateway->get_description()) {
                        $html .= '<br><small>' . esc_html($gateway->get_description()) . '</small>';
                    }
                    $html .= '</label>';
                }
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get payment breakdown for current cart
     */
    public function get_payment_breakdown() {
        if (!WC()->cart) {
            return false;
        }
        
        $settings = COD_Guard_WooCommerce::get_settings();
        $payment_mode = $settings['payment_mode'];
        
        $total = WC()->cart->get_total('edit');
        $advance_amount = 0;
        $mode_label = '';
        
        switch ($payment_mode) {
            case 'percentage':
                $percentage = intval($settings['percentage_amount']);
                $advance_amount = ($total * $percentage) / 100;
                $mode_label = $percentage . '% ' . __('of total', 'cod-guard-wc');
                break;
                
            case 'shipping':
                $advance_amount = WC()->cart->get_shipping_total();
                $mode_label = __('Shipping charges', 'cod-guard-wc');
                break;
                
            case 'fixed':
                $advance_amount = floatval($settings['fixed_amount']);
                $mode_label = __('Fixed amount', 'cod-guard-wc');
                
                if ($advance_amount > $total) {
                    $advance_amount = $total;
                }
                break;
        }
        
        $cod_amount = $total - $advance_amount;
        
        return array(
            'total' => $total,
            'advance_amount' => max(0, $advance_amount),
            'cod_amount' => max(0, $cod_amount),
            'payment_mode' => $payment_mode,
            'mode_label' => $mode_label,
        );
    }
    
    /**
     * Validate payment fields
     */
    public function validate_fields() {
        if (!isset($_POST['cod_guard_advance_method'])) {
            wc_add_notice(__('Please select a payment method for the advance payment.', 'cod-guard-wc'), 'error');
            return false;
        }
        
        $selected_method = sanitize_text_field($_POST['cod_guard_advance_method']);
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        
        if (!isset($available_gateways[$selected_method])) {
            wc_add_notice(__('Selected payment method is not available.', 'cod-guard-wc'), 'error');
            return false;
        }
        
        return true;
    }
    
    /**
     * Process payment - THIS WON'T BE CALLED because we switch payment method early
     */
    public function process_payment($order_id) {
        // This method should never be called because we switch the payment method
        // But keeping it as backup
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return array(
                'result' => 'failure',
                'message' => __('Order not found.', 'cod-guard-wc')
            );
        }
        
        return array(
            'result' => 'failure',
            'message' => __('COD Guard payment method switched incorrectly.', 'cod-guard-wc')
        );
    }
    
    /**
     * Thank you page display
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_meta('_cod_guard_enabled') !== 'yes') {
            return;
        }
        
        $cod_amount = $order->get_meta('_cod_guard_cod_amount');
        
        if ($cod_amount > 0) {
            echo '<div class="cod-guard-thankyou-notice" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;">';
            echo '<h3 style="margin-top: 0; color: #856404;">' . __('Important: COD Balance Due', 'cod-guard-wc') . '</h3>';
            echo '<p style="margin-bottom: 0;">' . sprintf(
                __('Please pay %s to the delivery person when your order arrives.', 'cod-guard-wc'),
                '<strong>' . wc_price($cod_amount) . '</strong>'
            ) . '</p>';
            echo '</div>';
        }
    }
}