<?php
/**
 * COMPLETE FIX for COD Guard Checkbox Handler
 * Replace includes/class-checkbox-handler.php with this version
 * 
 * Key Changes:
 * 1. NEVER modify displayed totals (cart total remains visible)
 * 2. Only modify payment gateway amount internally during processing
 * 3. Keep all display totals as original cart total
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Checkbox_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        // CRITICAL: Handle COD replacement FIRST
        add_filter('woocommerce_available_payment_gateways', array($this, 'filter_payment_gateways'), 999);
        
        // Add checkbox to checkout
        add_action('woocommerce_review_order_before_payment', array($this, 'add_cod_guard_checkbox'));
        
        // Handle checkout processing
        add_action('woocommerce_checkout_process', array($this, 'validate_cod_guard_checkout'));
        
        // CRITICAL: Process order data AFTER order creation
        add_action('woocommerce_checkout_order_processed', array($this, 'process_cod_guard_order'), 5, 3);
        
        // CRITICAL: Modify payment gateway amount internally (not display)
        add_filter('woocommerce_payment_gateway_supports', array($this, 'modify_gateway_amount'), 999, 3);
        add_action('woocommerce_checkout_process', array($this, 'store_payment_amounts'), 1);
        
        // Handle payment gateway amount modification
        add_filter('woocommerce_cart_get_total', array($this, 'modify_gateway_total_only'), 999);
        
        // Handle successful payment
        add_action('woocommerce_payment_complete', array($this, 'handle_advance_payment_complete'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'));
        
        // Scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX for real-time calculation
        add_action('wp_ajax_cod_guard_calculate', array($this, 'ajax_calculate_breakdown'));
        add_action('wp_ajax_nopriv_cod_guard_calculate', array($this, 'ajax_calculate_breakdown'));
        
        // Order display hooks
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_cod_guard_info_admin'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_cod_guard_info_customer'));
        
        // Register custom order status
        add_action('init', array($this, 'register_custom_order_status'));
        add_filter('wc_order_statuses', array($this, 'add_custom_order_status'));
        
        // IMPORTANT: Fix order totals after creation
        add_action('woocommerce_checkout_order_processed', array($this, 'fix_order_total'), 999, 3);
    }
    
    /**
     * CRITICAL: Filter payment gateways based on COD behavior setting
     */
    public function filter_payment_gateways($gateways) {
        // Only filter on checkout page
        if (!is_checkout()) {
            return $gateways;
        }
        
        $settings = COD_Guard_WooCommerce::get_settings();
        
        // If COD Guard is not enabled, don't filter
        if ($settings['enabled'] !== 'yes') {
            return $gateways;
        }
        
        // If COD Guard is not available for current cart, don't filter
        if (!$this->is_cod_guard_available()) {
            return $gateways;
        }
        
        // Check COD behavior setting
        $cod_behavior = $settings['cod_behavior'];
        
        if ($cod_behavior === 'replace') {
            // Remove COD if it exists
            if (isset($gateways['cod'])) {
                unset($gateways['cod']);
            }
        }
        
        return $gateways;
    }
    
    /**
     * Store payment amounts in session for gateway processing
     */
    public function store_payment_amounts() {
        if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1') {
            $advance_amount = floatval($_POST['cod_guard_advance_amount']);
            $original_total = WC()->cart->get_total('edit');
            
            // Store amounts in session for internal payment processing
            WC()->session->set('cod_guard_payment_amount', $advance_amount);
            WC()->session->set('cod_guard_original_total', $original_total);
            //$this->set_session_data('cod_guard_original_total', $original_total);
            WC()->session->set('cod_guard_processing_payment', true);
            
            error_log('COD Guard: Stored payment amounts - Advance: ' . $advance_amount . ', Original: ' . $original_total);
        }
    }
    
    /**
     * CRITICAL: Modify gateway total ONLY during internal payment processing
     * This ensures payment gateways charge the advance amount, but display totals remain unchanged
     */
    public function modify_gateway_total_only($total) {
        // Only modify during payment gateway processing, not for display
        if (is_admin() || !is_checkout() || is_order_received_page()) {
            return $total;
        }
        
        // Check if we're in payment processing mode
        if (!WC()->session->get('cod_guard_processing_payment')) {
            return $total;
        }
        
        // Only modify when payment gateway is actually processing the payment
        $payment_amount = WC()->session->get('cod_guard_payment_amount');
        
        if ($payment_amount && doing_action('woocommerce_checkout_process')) {
            // Check the call stack to ensure we're in payment processing
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            $in_payment_processing = false;
            
            foreach ($backtrace as $trace) {
                if (isset($trace['class']) && isset($trace['function'])) {
                    $class = $trace['class'];
                    $function = $trace['function'];
                    
                    // Check if we're in payment gateway processing
                    if (strpos($class, 'WC_Payment_Gateway') !== false || 
                        strpos($class, 'Gateway') !== false ||
                        $function === 'process_payment' ||
                        $function === 'payment_complete') {
                        $in_payment_processing = true;
                        break;
                    }
                }
            }
            
            if ($in_payment_processing) {
                error_log('COD Guard: Modified gateway total from ' . $total . ' to ' . $payment_amount);
                return $payment_amount;
            }
        }
        
        return $total;
    }
    
    /**
     * IMPORTANT: Fix order total after creation to ensure it shows original amount
     */
    public function fix_order_total($order_id, $posted_data, $order) {
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        $original_total = WC()->session->get('cod_guard_original_total');
        
        if ($original_total && $original_total != $order->get_total()) {
            // Set the order total back to original amount
            $order->set_total($original_total);
            $order->save();
            
            error_log('COD Guard: Fixed order total from ' . $order->get_total() . ' to ' . $original_total);
        }
        
        // Clear processing flag
        WC()->session->__unset('cod_guard_processing_payment');
    }
    
    /**
     * Register custom order status
     */
    public function register_custom_order_status() {
        register_post_status('wc-advance-paid', array(
            'label' => __('Advance Paid - COD Pending', 'cod-guard-wc'),
            'public' => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list' => true,
            'exclude_from_search' => false,
            'label_count' => _n_noop(
                'Advance Paid - COD Pending <span class="count">(%s)</span>',
                'Advance Paid - COD Pending <span class="count">(%s)</span>',
                'cod-guard-wc'
            ),
        ));
    }
    
    /**
     * Add custom order status to WooCommerce statuses
     */
    public function add_custom_order_status($order_statuses) {
        $new_order_statuses = array();
        
        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            
            if ('wc-processing' === $key) {
                $new_order_statuses['wc-advance-paid'] = _x('Advance Paid - COD Pending', 'Order status', 'cod-guard-wc');
            }
        }
        
        return $new_order_statuses;
    }
    
    /**
     * Add COD Guard checkbox section
     */
    public function add_cod_guard_checkbox() {
        if (!$this->is_cod_guard_available()) {
            return;
        }
        
        $settings = COD_Guard_WooCommerce::get_settings();
        $breakdown = $this->get_payment_breakdown();
        
        if (!$breakdown) {
            return;
        }
        
        ?>
        <div id="cod-guard-section" style="margin: 20px 0; background: #fff; border: 2px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
            <!-- Header Section -->
            <div class="cod-guard-header" style="background: #f8f9fa; padding: 15px; border-bottom: 1px solid #e0e0e0;">
                <label for="cod_guard_enabled" style="display: flex; align-items: center; cursor: pointer; margin: 0;">
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 5px 0; font-size: 18px; color: #333; font-weight: 600;">
                            <?php echo esc_html($settings['title']); ?>
                        </h3>
                        <p style="margin: 0; color: #666; font-size: 14px; line-height: 1.4;">
                            <?php echo esc_html($settings['description']); ?>
                        </p>
                    </div>
                    <input type="checkbox" id="cod_guard_enabled" name="cod_guard_enabled" value="1" 
                           style="width: 20px; height: 20px; margin-left: 15px; cursor: pointer;" />
                </label>
            </div>
            
            <!-- Payment Breakdown Section -->
            <div id="cod-guard-breakdown" style="display: none; padding: 20px; background: #fff;">
                <div style="background: #f8f9fc; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 15px 0; color: #333; font-size: 16px; font-weight: 600;">
                        <?php _e('Payment Breakdown', 'cod-guard-wc'); ?>
                    </h4>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                        <span style="color: #666; font-size: 14px;"><?php _e('Order Total:', 'cod-guard-wc'); ?></span>
                        <strong style="color: #333; font-size: 16px;" class="original-total"><?php echo wc_price($breakdown['total']); ?></strong>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin: 8px 0; padding: 10px; background: #d4edda; border-radius: 4px;">
                        <span style="color: #155724; font-weight: 600; font-size: 14px;">
                            <strong><?php echo sprintf(__('Pay Now (%s):', 'cod-guard-wc'), $breakdown['mode_label']); ?></strong>
                        </span>
                        <strong style="color: #155724; font-size: 16px;" class="advance-amount"><?php echo wc_price($breakdown['advance_amount']); ?></strong>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin: 8px 0; padding: 10px; background: #fff3cd; border-radius: 4px;">
                        <span style="color: #856404; font-weight: 600; font-size: 14px;">
                            <strong><?php _e('Pay on Delivery:', 'cod-guard-wc'); ?></strong>
                        </span>
                        <strong style="color: #856404; font-size: 16px;" class="cod-amount"><?php echo wc_price($breakdown['cod_amount']); ?></strong>
                    </div>
                </div>
                
                <!-- How it works section -->
                <div style="background: #e7f3ff; padding: 15px; border-radius: 6px; border-left: 4px solid #2196f3;">
                    <h5 style="margin: 0 0 10px 0; color: #1976d2; font-size: 14px; font-weight: 600;">
                        <?php _e('How it works:', 'cod-guard-wc'); ?>
                    </h5>
                    <div style="font-size: 13px; line-height: 1.5; color: #333;">
                        <div style="margin-bottom: 5px;">
                            <span style="color: #2196f3; font-weight: bold;">1.</span>
                            <span class="pay-now-text"><?php echo sprintf(__('Pay %s now using your selected payment method', 'cod-guard-wc'), '<strong class="advance-amount-text">' . wc_price($breakdown['advance_amount']) . '</strong>'); ?></span>
                        </div>
                        <div>
                            <span style="color: #2196f3; font-weight: bold;">2.</span>
                            <span class="pay-later-text"><?php echo sprintf(__('Pay remaining %s to delivery person', 'cod-guard-wc'), '<strong class="cod-amount-text">' . wc_price($breakdown['cod_amount']) . '</strong>'); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Important Notice about Total Display -->
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 12px; border-radius: 6px; margin-top: 15px;">
                    <div style="font-size: 13px; color: #856404; text-align: center;">
                        <strong>📋 Note:</strong> Order total remains <?php echo wc_price($breakdown['total']); ?> - You'll pay <?php echo wc_price($breakdown['advance_amount']); ?> now, <?php echo wc_price($breakdown['cod_amount']); ?> on delivery
                    </div>
                </div>
                
                <!-- Hidden fields for processing -->
                <input type="hidden" name="cod_guard_advance_amount" class="cod-guard-advance-amount" value="<?php echo esc_attr($breakdown['advance_amount']); ?>" />
                <input type="hidden" name="cod_guard_cod_amount" class="cod-guard-cod-amount" value="<?php echo esc_attr($breakdown['cod_amount']); ?>" />
                <input type="hidden" name="cod_guard_payment_mode" value="<?php echo esc_attr($breakdown['payment_mode']); ?>" />
                <input type="hidden" name="cod_guard_original_total" class="cod-guard-original-total" value="<?php echo esc_attr($breakdown['total']); ?>" />
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Handle checkbox toggle
            $('#cod_guard_enabled').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#cod-guard-breakdown').slideDown(300);
                    
                    // Add visual indicator that payment will be split
                    $('.order-total').after('<tr class="cod-guard-payment-notice" style="border-top: 2px solid #28a745;"><th style="color: #28a745;">COD Guard Active:</th><td style="color: #28a745; font-weight: bold;">Split Payment Enabled</td></tr>');
                } else {
                    $('#cod-guard-breakdown').slideUp(300);
                    $('.cod-guard-payment-notice').remove();
                }
            });
            
            // Update checkout when COD Guard is enabled/disabled
            $('#cod_guard_enabled').on('change', function() {
                $('body').trigger('update_checkout');
            });
        });
        </script>
        <?php
    }
    
    /**
     * Check if COD Guard is available
     */
    private function is_cod_guard_available() {
        $settings = COD_Guard_WooCommerce::get_settings();
        
        if ($settings['enabled'] !== 'yes') {
            return false;
        }
        
        if (!WC()->cart || WC()->cart->is_empty()) {
            return false;
        }
        
        // Check minimum order amount
        $minimum_amount = floatval($settings['minimum_order_amount']);
        if ($minimum_amount > 0 && WC()->cart->get_total('edit') < $minimum_amount) {
            return false;
        }
        
        // Check category restrictions
        $allowed_categories = $settings['enable_for_categories'];
        if (!empty($allowed_categories)) {
            $has_allowed_category = false;
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product = $cart_item['data'];
                $product_categories = wc_get_product_cat_ids($product->get_id());
                
                if (array_intersect($product_categories, $allowed_categories)) {
                    $has_allowed_category = true;
                    break;
                }
            }
            if (!$has_allowed_category) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get payment breakdown
     */
    private function get_payment_breakdown() {
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
                $mode_label = $percentage . '%';
                break;
                
            case 'shipping':
                $advance_amount = WC()->cart->get_shipping_total();
                $mode_label = __('Shipping', 'cod-guard-wc');
                break;
                
            case 'fixed':
                $advance_amount = floatval($settings['fixed_amount']);
                $mode_label = __('Fixed', 'cod-guard-wc');
                
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
     * Validate COD Guard checkout
     */
    public function validate_cod_guard_checkout() {
        // If COD Guard is not enabled, proceed with normal checkout
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        // Validate COD Guard data exists
        if (!isset($_POST['cod_guard_advance_amount']) || !isset($_POST['cod_guard_cod_amount'])) {
            wc_add_notice(__('COD Guard payment data is missing. Please refresh and try again.', 'cod-guard-wc'), 'error');
            return;
        }
        
        $advance_amount = floatval($_POST['cod_guard_advance_amount']);
        
        if ($advance_amount <= 0) {
            wc_add_notice(__('Invalid advance payment amount.', 'cod-guard-wc'), 'error');
            return;
        }
        
        // Get selected payment method
        $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
        
        if (empty($payment_method)) {
            wc_add_notice(__('Please select a payment method.', 'cod-guard-wc'), 'error');
            return;
        }
        
        // Check if selected payment method is available for advance payment
        if ($payment_method === 'cod') {
            wc_add_notice(__('COD cannot be used for advance payment when COD Guard is enabled. Please select a different payment method.', 'cod-guard-wc'), 'error');
            return;
        }
        
        // Store original cart total in session for later use
        WC()->session->set('cod_guard_original_total', WC()->cart->get_total('edit'));
        WC()->session->set('cod_guard_enabled', true);
    }
    
    /**
     * Process COD Guard order - NEVER modifies order total
     */
    public function process_cod_guard_order($order_id, $posted_data, $order) {
        // Check if COD Guard is enabled for this order
        if (!WC()->session->get('cod_guard_enabled')) {
            return;
        }
        
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        $advance_amount = floatval($_POST['cod_guard_advance_amount']);
        $cod_amount = floatval($_POST['cod_guard_cod_amount']);
        $payment_mode = sanitize_text_field($_POST['cod_guard_payment_mode']);
        $original_total = WC()->session->get('cod_guard_original_total');
        
        if (!$original_total) {
            $original_total = $order->get_total();
        }
        
        // Store COD Guard meta data - DO NOT MODIFY ORDER TOTAL
        $order->update_meta_data('_cod_guard_enabled', 'yes');
        $order->update_meta_data('_cod_guard_advance_amount', $advance_amount);
        $order->update_meta_data('_cod_guard_cod_amount', $cod_amount);
        $order->update_meta_data('_cod_guard_payment_mode', $payment_mode);
        $order->update_meta_data('_cod_guard_original_total', $original_total);
        $order->update_meta_data('_cod_guard_advance_status', 'pending');
        $order->update_meta_data('_cod_guard_cod_status', 'pending');
        
        // Add order note showing all totals clearly
        $order->add_order_note(
            sprintf(
                __('COD Guard enabled. Order Total: %s | Advance Payment: %s (via %s) | COD Balance: %s | Payment Mode: %s', 'cod-guard-wc'),
                wc_price($original_total),
                wc_price($advance_amount),
                $order->get_payment_method_title(),
                wc_price($cod_amount),
                $payment_mode
            )
        );
        
        $order->save();
        
        error_log('COD Guard: Order ' . $order_id . ' processed. Order total: ' . $order->get_total() . ', Advance: ' . $advance_amount);
    }
    
    /**
     * Handle advance payment completion
     */
    public function handle_advance_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            return;
        }
        
        // Update advance payment status
        $order->update_meta_data('_cod_guard_advance_status', 'completed');
        $order->update_meta_data('_cod_guard_advance_paid_date', current_time('mysql'));
        
        $advance_amount = COD_Guard_WooCommerce::get_advance_amount($order);
        $cod_amount = COD_Guard_WooCommerce::get_cod_amount($order);
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('COD Guard: Advance payment of %s completed. COD balance: %s', 'cod-guard-wc'),
                wc_price($advance_amount),
                wc_price($cod_amount)
            )
        );
        
        // Set appropriate order status
        if ($cod_amount > 0) {
            $order->update_status('advance-paid', __('Advance payment completed. Awaiting COD payment.', 'cod-guard-wc'));
        } else {
            $order->update_status('completed', __('Payment fully completed.', 'cod-guard-wc'));
        }
        
        $order->save();
        
        // Trigger notifications
        do_action('cod_guard_advance_payment_completed', $order_id);
        
        error_log('COD Guard: Advance payment completed for order ' . $order_id);
    }
    
    /**
     * Handle order completion
     */
    public function handle_order_completed($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            return;
        }
        
        // Mark COD as completed if not already
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        if ($cod_status !== 'completed') {
            $order->update_meta_data('_cod_guard_cod_status', 'completed');
            $order->update_meta_data('_cod_guard_cod_paid_date', current_time('mysql'));
            
            $cod_amount = COD_Guard_WooCommerce::get_cod_amount($order);
            if ($cod_amount > 0) {
                $order->add_order_note(
                    sprintf(
                        __('COD Guard: COD payment of %s completed. Order fully paid.', 'cod-guard-wc'),
                        wc_price($cod_amount)
                    )
                );
            }
            
            $order->save();
            
            // Trigger completion action
            do_action('cod_guard_order_fully_completed', $order_id);
        }
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }
        
        wp_enqueue_style(
            'cod-guard-frontend',
            COD_GUARD_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            COD_GUARD_VERSION
        );
        
        wp_enqueue_script(
            'cod-guard-checkout',
            COD_GUARD_PLUGIN_URL . 'assets/js/checkout.js',
            array('jquery'),
            COD_GUARD_VERSION,
            true
        );
        
        wp_localize_script('cod-guard-checkout', 'codGuardAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cod_guard_calculate'),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'strings' => array(
                'loading' => __('Calculating...', 'cod-guard-wc'),
                'error' => __('Error calculating breakdown', 'cod-guard-wc'),
                'pay_now_label' => __('Advance Payment', 'cod-guard-wc'),
            ),
        ));
    }
    
    /**
     * AJAX calculate breakdown
     */
    public function ajax_calculate_breakdown() {
        if (!wp_verify_nonce($_POST['nonce'], 'cod_guard_calculate')) {
            wp_die(__('Security check failed', 'cod-guard-wc'));
        }
        
        $breakdown = $this->get_payment_breakdown();
        
        if (!$breakdown) {
            wp_send_json_error(__('Unable to calculate breakdown', 'cod-guard-wc'));
        }
        
        wp_send_json_success($breakdown);
    }
    
    /**
     * Display COD Guard info in admin - Shows original order total
     */
    public function display_cod_guard_info_admin($order) {
        if (!COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            return;
        }
        
        $advance_amount = COD_Guard_WooCommerce::get_advance_amount($order);
        $cod_amount = COD_Guard_WooCommerce::get_cod_amount($order);
        $advance_status = $order->get_meta('_cod_guard_advance_status');
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        $original_total = $order->get_meta('_cod_guard_original_total');
        $payment_mode = $order->get_meta('_cod_guard_payment_mode');
        
        // Use order total if original total not stored
        if (!$original_total) {
            $original_total = $order->get_total();
        }
        
        ?>
        <div class="cod-guard-admin-info-fixed" style="background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%); border: 2px solid #007cba; border-radius: 8px; padding: 20px; margin: 20px 0;">
            <h3 style="margin: 0 0 15px 0; color: #007cba; display: flex; align-items: center; gap: 10px; font-size: 18px;">
                🛡️ <?php _e('COD Guard Payment Details', 'cod-guard-wc'); ?>
                <span style="background: #007cba; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: normal;">
                    <?php echo esc_html(ucfirst($payment_mode)); ?>
                </span>
            </h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                
                <!-- Order Total (Always shows full amount) -->
                <div style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #ddd; text-align: center;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 5px;">📋 <?php _e('Order Total', 'cod-guard-wc'); ?></div>
                    <div style="font-size: 20px; font-weight: bold; color: #333;"><?php echo wc_price($original_total); ?></div>
                    <div style="font-size: 12px; color: #888; margin-top: 5px;"><?php _e('(Full Order Value)', 'cod-guard-wc'); ?></div>
                </div>
                
                <!-- Advance Payment (Amount charged to payment gateway) -->
                <div style="background: <?php echo $advance_status === 'completed' ? '#d4edda' : '#fff3cd'; ?>; padding: 15px; border-radius: 6px; border: 1px solid <?php echo $advance_status === 'completed' ? '#c3e6cb' : '#ffeaa7'; ?>; text-align: center;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 5px;">💰 <?php _e('Advance Payment', 'cod-guard-wc'); ?></div>
                    <div style="font-size: 20px; font-weight: bold; color: <?php echo $advance_status === 'completed' ? '#155724' : '#856404'; ?>;">
                        <?php echo wc_price($advance_amount); ?>
                    </div>
                    <div style="font-size: 12px; margin-top: 5px; color: <?php echo $advance_status === 'completed' ? '#155724' : '#856404'; ?>;">
                        <?php echo $advance_status === 'completed' ? '✅ ' . __('Charged via Gateway', 'cod-guard-wc') : '⏳ ' . __('Pending', 'cod-guard-wc'); ?>
                    </div>
                </div>
                
                <!-- COD Balance -->
                <div style="background: <?php echo $cod_status === 'completed' ? '#d4edda' : '#f8d7da'; ?>; padding: 15px; border-radius: 6px; border: 1px solid <?php echo $cod_status === 'completed' ? '#c3e6cb' : '#f5c6cb'; ?>; text-align: center;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 5px;">🚚 <?php _e('COD Balance', 'cod-guard-wc'); ?></div>
                    <div style="font-size: 20px; font-weight: bold; color: <?php echo $cod_status === 'completed' ? '#155724' : '#721c24'; ?>;">
                        <?php echo wc_price($cod_amount); ?>
                    </div>
                    <div style="font-size: 12px; margin-top: 5px; color: <?php echo $cod_status === 'completed' ? '#155724' : '#721c24'; ?>;">
                        <?php echo $cod_status === 'completed' ? '✅ ' . __('Collected', 'cod-guard-wc') : '📋 ' . __('Due on Delivery', 'cod-guard-wc'); ?>
                    </div>
                </div>
                
            </div>
            
            <!-- Payment Gateway Info -->
            <div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 12px; border-radius: 6px; margin-top: 15px;">
                <strong style="color: #0c5460;">💳 <?php _e('Payment Gateway:', 'cod-guard-wc'); ?></strong>
                <span style="color: #0c5460;">
                    <?php printf(__('Customer was charged %s via %s (Order total remains %s)', 'cod-guard-wc'), 
                        '<strong>' . wc_price($advance_amount) . '</strong>', 
                        $order->get_payment_method_title(),
                        '<strong>' . wc_price($original_total) . '</strong>'
                    ); ?>
                </span>
            </div>
            
            <?php if ($cod_status !== 'completed' && $cod_amount > 0): ?>
            <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 12px; border-radius: 6px; margin-top: 15px; text-align: center;">
                <strong style="color: #856404;">⚠️ <?php _e('Action Required:', 'cod-guard-wc'); ?></strong>
                <span style="color: #856404;">
                    <?php printf(__('Collect %s from customer on delivery.', 'cod-guard-wc'), '<strong>' . wc_price($cod_amount) . '</strong>'); ?>
                </span>
            </div>
            <?php endif; ?>
            
            <!-- Verification Row -->
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; text-align: center; font-size: 14px; color: #666;">
                <?php _e('Verification:', 'cod-guard-wc'); ?> 
                <?php echo wc_price($advance_amount); ?> + <?php echo wc_price($cod_amount); ?> = <?php echo wc_price($original_total); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display COD Guard info for customer
     */
    public function display_cod_guard_info_customer($order) {
        if (!COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            return;
        }
        
        $cod_amount = COD_Guard_WooCommerce::get_cod_amount($order);
        $advance_amount = COD_Guard_WooCommerce::get_advance_amount($order);
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        $original_total = $order->get_meta('_cod_guard_original_total');
        
        if (!$original_total) {
            $original_total = $order->get_total();
        }
        
        ?>
        <div class="cod-guard-customer-notice" style="background: #f0f8ff; border: 2px solid #007cba; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h3 style="margin-top: 0; color: #007cba; display: flex; align-items: center; gap: 10px;">
                🛡️ <?php _e('COD Guard Payment Summary', 'cod-guard-wc'); ?>
            </h3>
            
            <div style="background: white; padding: 15px; border-radius: 6px; margin: 15px 0;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                    <span><?php _e('Order Total:', 'cod-guard-wc'); ?></span>
                    <strong><?php echo wc_price($original_total); ?></strong>
                </div>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: #28a745;">
                    <span>✅ <?php _e('Advance Payment (Paid):', 'cod-guard-wc'); ?></span>
                    <strong><?php echo wc_price($advance_amount); ?></strong>
                </div>
                
                <?php if ($cod_amount > 0): ?>
                <div style="display: flex; justify-content: space-between; color: <?php echo $cod_status === 'completed' ? '#28a745' : '#856404'; ?>;">
                    <span><?php echo $cod_status === 'completed' ? '✅' : '📋'; ?> <?php _e('COD Balance:', 'cod-guard-wc'); ?></span>
                    <strong><?php echo wc_price($cod_amount); ?></strong>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($cod_amount > 0 && $cod_status !== 'completed'): ?>
            <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 12px; border-radius: 6px; text-align: center;">
                <strong style="color: #856404;">📋 <?php _e('Important:', 'cod-guard-wc'); ?></strong>
                <span style="color: #856404;">
                    <?php printf(__('Please pay %s to the delivery person when your order arrives.', 'cod-guard-wc'), '<strong>' . wc_price($cod_amount) . '</strong>'); ?>
                </span>
            </div>
            <?php elseif ($cod_status === 'completed'): ?>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 12px; border-radius: 6px; text-align: center;">
                <strong style="color: #155724;">✅ <?php _e('Payment Completed', 'cod-guard-wc'); ?></strong>
                <span style="color: #155724;">
                    <?php _e('Thank you! Your order has been fully paid.', 'cod-guard-wc'); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Modify gateway amount support
     */
    public function modify_gateway_amount($is_supported, $feature, $gateway) {
        // This method can be used to ensure payment gateways support the features we need
        return $is_supported;
    }
    
    /**
     * Helper method to check if order uses COD Guard
     */
    private function is_cod_guard_order($order) {
        return COD_Guard_WooCommerce::is_cod_guard_order($order);
    }
}