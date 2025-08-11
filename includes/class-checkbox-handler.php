<?php
/**
 * COD Guard Checkbox Handler - COMPLETE FIX
 * Replace includes/class-checkbox-handler.php with this version
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
        
        // CRITICAL: Process order data AFTER order creation but BEFORE payment processing
        add_action('woocommerce_checkout_order_processed', array($this, 'process_cod_guard_order'), 5, 3);
        
        // Handle successful payment
        add_action('woocommerce_payment_complete', array($this, 'handle_advance_payment_complete'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'));
        
        // CRITICAL: Fix checkout completion and redirect
        add_action('woocommerce_checkout_order_processed', array($this, 'ensure_checkout_completion'), 999, 3);
        
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
        
        // Fix cart clearing
        add_action('woocommerce_checkout_update_order_meta', array($this, 'maybe_clear_cart'), 999);
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
        // If 'alongside', we keep COD available
        
        return $gateways;
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
                } else {
                    $('#cod-guard-breakdown').slideUp(300);
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Check if COD Guard is available - IMPROVED VERSION
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
        
        // Check category restrictions - IMPROVED LOGIC
        $allowed_categories = $settings['enable_for_categories'];
        if (!empty($allowed_categories)) {
            $has_allowed_category = false;
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product = $cart_item['data'];
                $product_categories = wc_get_product_cat_ids($product->get_id());
                
                // If ANY product in cart matches ANY allowed category, enable COD Guard
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
     * Validate COD Guard checkout - SIMPLIFIED VERSION
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
     * Process COD Guard order - HAPPENS AFTER ORDER CREATION
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
        
        // Store COD Guard meta data
        $order->update_meta_data('_cod_guard_enabled', 'yes');
        $order->update_meta_data('_cod_guard_advance_amount', $advance_amount);
        $order->update_meta_data('_cod_guard_cod_amount', $cod_amount);
        $order->update_meta_data('_cod_guard_payment_mode', $payment_mode);
        $order->update_meta_data('_cod_guard_original_total', $original_total);
        $order->update_meta_data('_cod_guard_advance_status', 'pending');
        $order->update_meta_data('_cod_guard_cod_status', 'pending');
        
        // CRITICAL: Adjust order total to advance amount only
        $order->set_total($advance_amount);
        
        // Adjust all order items proportionally
        $this->adjust_order_items_proportionally($order, $advance_amount, $original_total);
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('COD Guard enabled. Advance: %s, COD Balance: %s, Original Total: %s, Payment Mode: %s', 'cod-guard-wc'),
                wc_price($advance_amount),
                wc_price($cod_amount),
                wc_price($original_total),
                $payment_mode
            )
        );
        
        $order->save();
        
        error_log('COD Guard: Order ' . $order_id . ' processed. Total adjusted from ' . $original_total . ' to ' . $advance_amount);
    }
    
    /**
     * CRITICAL: Ensure checkout completion - runs last
     */
    public function ensure_checkout_completion($order_id, $posted_data, $order) {
        // Only handle COD Guard orders
        if (!isset($_POST['cod_guard_enabled']) || $_POST['cod_guard_enabled'] !== '1') {
            return;
        }
        
        if (!$order || !COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            return;
        }
        
        // Clear cart immediately for COD Guard orders
        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
        
        // Clear any error notices that might interfere
        $notices = wc_get_notices('error');
        if (!empty($notices)) {
            // Filter out generic processing errors
            $filtered_notices = array_filter($notices, function($notice) {
                $message = is_array($notice) ? $notice['notice'] : $notice;
                return strpos($message, 'error processing') === false && 
                       strpos($message, 'review your order') === false;
            });
            
            // Clear all notices and re-add only the filtered ones
            wc_clear_notices();
            foreach ($filtered_notices as $notice) {
                wc_add_notice($notice, 'error');
            }
        }
        
        // Clear session data
        WC()->session->__unset('cod_guard_enabled');
        WC()->session->__unset('cod_guard_original_total');
        
        // Force set order as successful for redirect
        if (!$order->is_paid() && $order->get_status() === 'pending') {
            // For orders that need payment processing, mark as processing
            $order->update_status('processing', __('COD Guard order awaiting payment.', 'cod-guard-wc'));
        }
        
        error_log('COD Guard: Checkout completion ensured for order ' . $order_id);
    }
    
    /**
     * Maybe clear cart - backup method
     */
    public function maybe_clear_cart($order_id) {
        $order = wc_get_order($order_id);
        
        if ($order && COD_Guard_WooCommerce::is_cod_guard_order($order)) {
            // Force cart clear for COD Guard orders
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
            
            error_log('COD Guard: Cart cleared for order ' . $order_id);
        }
    }
    
    /**
     * Adjust order items proportionally
     */
    private function adjust_order_items_proportionally($order, $new_total, $original_total) {
        if ($original_total <= 0) {
            return;
        }
        
        $ratio = $new_total / $original_total;
        
        // Adjust line items
        foreach ($order->get_items() as $item) {
            $original_item_total = $item->get_total();
            $original_item_subtotal = $item->get_subtotal();
            
            $item->set_total($original_item_total * $ratio);
            $item->set_subtotal($original_item_subtotal * $ratio);
            $item->save();
        }
        
        // Adjust shipping
        foreach ($order->get_shipping_methods() as $shipping_item) {
            $original_shipping_total = $shipping_item->get_total();
            $shipping_item->set_total($original_shipping_total * $ratio);
            $shipping_item->save();
        }
        
        // Adjust taxes proportionally
        foreach ($order->get_items('tax') as $tax_item) {
            $original_tax_total = $tax_item->get_tax_total();
            $original_shipping_tax = $tax_item->get_shipping_tax_total();
            
            $tax_item->set_tax_total($original_tax_total * $ratio);
            $tax_item->set_shipping_tax_total($original_shipping_tax * $ratio);
            $tax_item->save();
        }
        
        // Recalculate and save
        $order->calculate_totals();
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
        $original_total = $order->get_meta('_cod_guard_original_total');
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('COD Guard: Advance payment of %s completed. COD balance: %s', 'cod-guard-wc'),
                wc_price($advance_amount),
                wc_price($cod_amount)
            )
        );
        
        // Restore original order totals for record keeping
        $this->restore_original_order_totals($order, $original_total);
        
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
     * Restore original order totals
     */
    private function restore_original_order_totals($order, $original_total) {
        if (!$original_total) {
            return;
        }
        
        $current_total = $order->get_total();
        if ($current_total <= 0) {
            return;
        }
        
        $ratio = $original_total / $current_total;
        
        // Restore line items
        foreach ($order->get_items() as $item) {
            $current_item_total = $item->get_total();
            $current_item_subtotal = $item->get_subtotal();
            
            $item->set_total($current_item_total * $ratio);
            $item->set_subtotal($current_item_subtotal * $ratio);
            $item->save();
        }
        
        // Restore shipping
        foreach ($order->get_shipping_methods() as $shipping_item) {
            $current_shipping_total = $shipping_item->get_total();
            $shipping_item->set_total($current_shipping_total * $ratio);
            $shipping_item->save();
        }
        
        // Restore taxes
        foreach ($order->get_items('tax') as $tax_item) {
            $current_tax_total = $tax_item->get_tax_total();
            $current_shipping_tax = $tax_item->get_shipping_tax_total();
            
            $tax_item->set_tax_total($current_tax_total * $ratio);
            $tax_item->set_shipping_tax_total($current_shipping_tax * $ratio);
            $tax_item->save();
        }
        
        // Set original total
        $order->set_total($original_total);
        
        $order->add_order_note(
            sprintf(
                __('COD Guard: Order totals restored to original values (%s)', 'cod-guard-wc'),
                wc_price($original_total)
            )
        );
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
     * Display COD Guard info in admin
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
        
        ?>
        <div class="cod-guard-admin-info" style="background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #007cba;">
            <h3><?php _e('COD Guard Payment Info', 'cod-guard-wc'); ?></h3>
            <p><strong><?php _e('Original Order Total:', 'cod-guard-wc'); ?></strong> <?php echo wc_price($original_total); ?></p>
            <p><strong><?php _e('Advance Payment:', 'cod-guard-wc'); ?></strong> <?php echo wc_price($advance_amount); ?> <em style="color: #28a745;">(<?php echo esc_html(ucfirst($advance_status)); ?>)</em></p>
            <p><strong><?php _e('COD Balance:', 'cod-guard-wc'); ?></strong> <?php echo wc_price($cod_amount); ?> <em style="color: #ffc107;">(<?php echo esc_html(ucfirst($cod_status)); ?>)</em></p>
            <?php if ($cod_status !== 'completed' && $cod_amount > 0): ?>
            <p style="background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0;">
                <strong><?php _e('Note:', 'cod-guard-wc'); ?></strong> <?php printf(__('Customer needs to pay %s on delivery.', 'cod-guard-wc'), wc_price($cod_amount)); ?>
            </p>
            <?php endif; ?>
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
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        
        if ($cod_amount > 0 && $cod_status !== 'completed') {
            ?>
            <div class="cod-guard-customer-notice" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; color: #856404;"><?php _e('Important: COD Balance Due', 'cod-guard-wc'); ?></h3>
                <p style="margin-bottom: 0; color: #856404;">
                    <?php printf(__('Please pay %s to the delivery person when your order arrives.', 'cod-guard-wc'), '<strong>' . wc_price($cod_amount) . '</strong>'); ?>
                </p>
            </div>
            <?php
        } elseif ($cod_status === 'completed') {
            ?>
            <div class="cod-guard-customer-notice" style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; color: #155724;"><?php _e('Payment Completed', 'cod-guard-wc'); ?></h3>
                <p style="margin-bottom: 0; color: #155724;">
                    <?php _e('Thank you! Your order has been fully paid.', 'cod-guard-wc'); ?>
                </p>
            </div>
            <?php
        }
    }
}