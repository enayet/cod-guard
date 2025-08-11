<?php
/**
 * COD Guard Order Management - FIXED VERSION
 * 
 * Replace your class-order-management.php with this version
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Order_Management {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register custom order status
        add_action('init', array($this, 'register_custom_order_status'));
        add_filter('wc_order_statuses', array($this, 'add_custom_order_status'));
        
        // Admin order page enhancements
        //add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_cod_guard_info'));
        //add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_payment_breakdown'));
        
        // Order list columns
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_list_columns'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'display_order_list_columns'), 10, 2);
        
        // Order actions
        add_filter('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_cod_guard_mark_cod_paid', array($this, 'mark_cod_paid'));
        
        // My Account order display
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_customer_cod_info'));
        add_filter('woocommerce_my_account_my_orders_columns', array($this, 'add_my_account_columns'));
        add_action('woocommerce_my_account_my_orders_column_cod-balance', array($this, 'display_my_account_cod_balance'));
        
        // Order status colors
        add_action('admin_head', array($this, 'custom_order_status_styles'));
        
        // Order search enhancement
        add_filter('woocommerce_shop_order_search_fields', array($this, 'add_search_fields'));
        
        // Bulk actions
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_actions'), 10, 3);
    }
    
    /**
     * Register custom order status
     */
    public function register_custom_order_status() {
        register_post_status('wc-advance-paid', array(
            'label'                     => _x('Advance Paid - COD Pending', 'Order status', 'cod-guard-wc'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
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
        
        // Add custom status after 'processing'
        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            
            if ('wc-processing' === $key) {
                $new_order_statuses['wc-advance-paid'] = _x('Advance Paid - COD Pending', 'Order status', 'cod-guard-wc');
            }
        }
        
        return $new_order_statuses;
    }
    
    /**
     * Display COD Guard info on admin order page
     */
    public function display_cod_guard_info($order) {
        if (!$this->is_cod_guard_order($order)) {
            return;
        }
        
        $payment_summary = $this->get_order_payment_summary($order);
        
        if (!$payment_summary) {
            return;
        }
        
        ?>
        <div class="cod-guard-order-info">
            <h3><?php _e('COD Guard Payment Information', 'cod-guard-wc'); ?></h3>
            <div class="cod-guard-info-grid">
                <div class="cod-guard-info-item">
                    <strong><?php _e('Payment Mode:', 'cod-guard-wc'); ?></strong>
                    <span><?php echo esc_html(ucfirst($payment_summary['payment_mode'])); ?></span>
                </div>
                
                <div class="cod-guard-info-item">
                    <strong><?php _e('Original Total:', 'cod-guard-wc'); ?></strong>
                    <span><?php echo wc_price($payment_summary['original_total']); ?></span>
                </div>
                
                <div class="cod-guard-info-item">
                    <strong><?php _e('Advance Amount:', 'cod-guard-wc'); ?></strong>
                    <span class="<?php echo esc_attr($payment_summary['advance_status']); ?>">
                        <?php echo wc_price($payment_summary['advance_amount']); ?>
                        <em>(<?php echo esc_html(ucfirst($payment_summary['advance_status'])); ?>)</em>
                    </span>
                </div>
                
                <div class="cod-guard-info-item">
                    <strong><?php _e('COD Balance:', 'cod-guard-wc'); ?></strong>
                    <span class="<?php echo esc_attr($payment_summary['cod_status']); ?>">
                        <?php echo wc_price($payment_summary['cod_amount']); ?>
                        <em>(<?php echo esc_html(ucfirst($payment_summary['cod_status'])); ?>)</em>
                    </span>
                </div>
                
                <?php if ($payment_summary['advance_paid_date']): ?>
                <div class="cod-guard-info-item">
                    <strong><?php _e('Advance Paid Date:', 'cod-guard-wc'); ?></strong>
                    <span><?php echo esc_html(wc_format_datetime(new WC_DateTime($payment_summary['advance_paid_date']))); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($payment_summary['cod_paid_date']): ?>
                <div class="cod-guard-info-item">
                    <strong><?php _e('COD Paid Date:', 'cod-guard-wc'); ?></strong>
                    <span><?php echo esc_html(wc_format_datetime(new WC_DateTime($payment_summary['cod_paid_date']))); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .cod-guard-order-info {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .cod-guard-order-info h3 {
            margin-top: 0;
            color: #495057;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 10px;
        }
        
        .cod-guard-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
        }
        
        .cod-guard-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .cod-guard-info-item strong {
            flex: 0 0 40%;
        }
        
        .cod-guard-info-item span {
            flex: 1;
            text-align: right;
        }
        
        .cod-guard-info-item span em {
            font-size: 0.85em;
            color: #6c757d;
        }
        
        .cod-guard-info-item span.completed {
            color: #28a745;
        }
        
        .cod-guard-info-item span.pending {
            color: #ffc107;
        }
        </style>
        <?php
    }
    
    /**
     * Display payment breakdown in order details
     */
    public function display_payment_breakdown($order) {
        if (!$this->is_cod_guard_order($order)) {
            return;
        }
        
        $payment_summary = $this->get_order_payment_summary($order);
        
        if (!$payment_summary) {
            return;
        }
        
        ?>
        <div class="cod-guard-payment-breakdown">
            <h4><?php _e('Payment Breakdown', 'cod-guard-wc'); ?></h4>
            <table class="cod-guard-breakdown-table">
                <tr>
                    <td><?php _e('Advance Payment:', 'cod-guard-wc'); ?></td>
                    <td><?php echo wc_price($payment_summary['advance_amount']); ?></td>
                    <td class="status-<?php echo esc_attr($payment_summary['advance_status']); ?>">
                        <?php echo esc_html(ucfirst($payment_summary['advance_status'])); ?>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('COD Balance:', 'cod-guard-wc'); ?></td>
                    <td><?php echo wc_price($payment_summary['cod_amount']); ?></td>
                    <td class="status-<?php echo esc_attr($payment_summary['cod_status']); ?>">
                        <?php echo esc_html(ucfirst($payment_summary['cod_status'])); ?>
                    </td>
                </tr>
                <tr class="total-row">
                    <td><strong><?php _e('Total:', 'cod-guard-wc'); ?></strong></td>
                    <td><strong><?php echo wc_price($payment_summary['original_total']); ?></strong></td>
                    <td></td>
                </tr>
            </table>
        </div>
        
        <style>
        .cod-guard-payment-breakdown {
            margin: 15px 0;
        }
        
        .cod-guard-breakdown-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .cod-guard-breakdown-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
        }
        
        .cod-guard-breakdown-table .total-row {
            background: #f8f9fa;
        }
        
        .status-completed {
            color: #28a745;
            font-weight: bold;
        }
        
        .status-pending {
            color: #ffc107;
            font-weight: bold;
        }
        </style>
        <?php
    }
    
    /**
     * Add columns to order list
     */
    public function add_order_list_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            // Add COD Guard column after order status
            if ($key === 'order_status') {
                $new_columns['cod_guard_status'] = __('COD Guard', 'cod-guard-wc');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Display order list columns
     */
    public function display_order_list_columns($column, $post_id) {
        if ($column !== 'cod_guard_status') {
            return;
        }
        
        $order = wc_get_order($post_id);
        
        if (!$order || !$this->is_cod_guard_order($order)) {
            echo '<span class="na">—</span>';
            return;
        }
        
        $cod_amount = $this->get_cod_amount($order);
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        
        if ($cod_amount > 0) {
            $status_class = $cod_status === 'completed' ? 'completed' : 'pending';
            echo '<span class="cod-guard-status ' . esc_attr($status_class) . '">';
            echo wc_price($cod_amount);
            echo '<br><small>(' . esc_html(ucfirst($cod_status)) . ')</small>';
            echo '</span>';
        } else {
            echo '<span class="cod-guard-status completed">';
            echo __('Fully Paid', 'cod-guard-wc');
            echo '</span>';
        }
    }
    
    /**
     * Add order actions
     */
    public function add_order_actions($actions) {
        global $post;
        
        if (!$post || !isset($post->ID)) {
            return $actions;
        }
        
        $order = wc_get_order($post->ID);
        
        if (!$order || !$this->is_cod_guard_order($order)) {
            return $actions;
        }
        
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        $cod_amount = $this->get_cod_amount($order);
        
        if ($cod_status !== 'completed' && $cod_amount > 0) {
            $actions['cod_guard_mark_cod_paid'] = __('Mark COD as Paid', 'cod-guard-wc');
        }
        
        return $actions;
    }
    
    /**
     * Mark COD as paid
     */
    public function mark_cod_paid($order) {
        if (!$this->is_cod_guard_order($order)) {
            return;
        }
        
        $cod_amount = $this->get_cod_amount($order);
        
        // Update COD status
        $order->update_meta_data('_cod_guard_cod_status', 'completed');
        $order->update_meta_data('_cod_guard_cod_paid_date', current_time('mysql'));
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('COD payment of %s marked as paid manually by admin.', 'cod-guard-wc'),
                wc_price($cod_amount)
            )
        );
        
        // Update order status if needed
        if ($order->get_status() === 'advance-paid') {
            $order->update_status('completed', __('COD payment completed.', 'cod-guard-wc'));
        }
        
        $order->save();
        
        // Show admin notice
        WC_Admin_Meta_Boxes::add_success(__('COD payment marked as completed.', 'cod-guard-wc'));
    }
    
    /**
     * Display customer COD info on order details page
     */
    public function display_customer_cod_info($order) {
        if (!$this->is_cod_guard_order($order)) {
            return;
        }
        
        $payment_summary = $this->get_order_payment_summary($order);
        
        if (!$payment_summary || $payment_summary['cod_amount'] <= 0) {
            return;
        }
        
        ?>
        <section class="woocommerce-cod-guard-info">
            <h2 class="woocommerce-column__title"><?php _e('Payment Information', 'cod-guard-wc'); ?></h2>
            
            <div class="cod-guard-customer-info">
                <?php if ($payment_summary['cod_status'] === 'pending'): ?>
                    <div class="woocommerce-message woocommerce-message--info">
                        <strong><?php _e('Important:', 'cod-guard-wc'); ?></strong>
                        <?php printf(
                            __('Please pay %s to the delivery person when your order arrives.', 'cod-guard-wc'),
                            '<strong>' . wc_price($payment_summary['cod_amount']) . '</strong>'
                        ); ?>
                    </div>
                <?php else: ?>
                    <div class="woocommerce-message woocommerce-message--success">
                        <?php _e('COD payment completed. Thank you!', 'cod-guard-wc'); ?>
                    </div>
                <?php endif; ?>
                
                <table class="woocommerce-table woocommerce-table--customer-details">
                    <tbody>
                        <tr>
                            <th><?php _e('Advance Payment:', 'cod-guard-wc'); ?></th>
                            <td><?php echo wc_price($payment_summary['advance_amount']); ?></td>
                            <td class="status-completed"><?php _e('Paid', 'cod-guard-wc'); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('COD Balance:', 'cod-guard-wc'); ?></th>
                            <td><?php echo wc_price($payment_summary['cod_amount']); ?></td>
                            <td class="status-<?php echo esc_attr($payment_summary['cod_status']); ?>">
                                <?php echo esc_html(ucfirst($payment_summary['cod_status'])); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <?php
    }
    
    /**
     * Add My Account columns
     */
    public function add_my_account_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            // Add COD balance column after order total
            if ($key === 'order-total') {
                $new_columns['cod-balance'] = __('COD Balance', 'cod-guard-wc');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Display My Account COD balance
     */
    public function display_my_account_cod_balance($order) {
        if (!$this->is_cod_guard_order($order)) {
            echo '<span class="na">—</span>';
            return;
        }
        
        $cod_amount = $this->get_cod_amount($order);
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        
        if ($cod_amount > 0 && $cod_status !== 'completed') {
            echo '<span class="cod-balance-pending">' . wc_price($cod_amount) . '</span>';
        } else {
            echo '<span class="cod-balance-paid">' . __('Paid', 'cod-guard-wc') . '</span>';
        }
    }
    
    /**
     * Custom order status styles
     */
    public function custom_order_status_styles() {
        ?>
        <style>
        .widefat .column-cod_guard_status {
            width: 120px;
        }
        
        .cod-guard-status.completed {
            color: #2e7d32;
            font-weight: bold;
        }
        
        .cod-guard-status.pending {
            color: #f57c00;
            font-weight: bold;
        }
        
        .status-completed {
            color: #2e7d32;
        }
        
        .status-pending {
            color: #f57c00;
        }
        
        .cod-balance-pending {
            color: #f57c00;
            font-weight: bold;
        }
        
        .cod-balance-paid {
            color: #2e7d32;
        }
        
        /* Order status styling */
        mark.advance-paid {
            background: #ffeb3b;
            color: #333;
        }
        
        .order-status.status-advance-paid {
            background: #ffeb3b;
            color: #333;
        }
        </style>
        <?php
    }
    
    /**
     * Add search fields
     */
    public function add_search_fields($search_fields) {
        $search_fields[] = '_cod_guard_payment_mode';
        return $search_fields;
    }
    
    /**
     * Add bulk actions
     */
    public function add_bulk_actions($actions) {
        $actions['cod_guard_mark_cod_paid'] = __('Mark COD as Paid', 'cod-guard-wc');
        return $actions;
    }
    
    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'cod_guard_mark_cod_paid') {
            return $redirect_to;
        }
        
        $processed = 0;
        
        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            
            if ($order && $this->is_cod_guard_order($order)) {
                $this->mark_cod_paid($order);
                $processed++;
            }
        }
        
        $redirect_to = add_query_arg('cod_guard_bulk_action', $processed, $redirect_to);
        
        return $redirect_to;
    }
    
    /**
     * Helper Methods - FIXED
     */
    
    /**
     * Check if order uses COD Guard
     */
    private function is_cod_guard_order($order) {
        if (!$order) {
            return false;
        }
        return $order->get_meta('_cod_guard_enabled') === 'yes';
    }
    
    /**
     * Get advance amount for an order
     */
    private function get_advance_amount($order) {
        if (!$order) {
            return 0;
        }
        return floatval($order->get_meta('_cod_guard_advance_amount'));
    }
    
    /**
     * Get COD amount for an order
     */
    private function get_cod_amount($order) {
        if (!$order) {
            return 0;
        }
        return floatval($order->get_meta('_cod_guard_cod_amount'));
    }
    
    /**
     * Get order payment summary
     */
    private function get_order_payment_summary($order) {
        if (!$this->is_cod_guard_order($order)) {
            return false;
        }
        
        return array(
            'advance_amount' => $this->get_advance_amount($order),
            'cod_amount' => $this->get_cod_amount($order),
            'original_total' => floatval($order->get_meta('_cod_guard_original_total')),
            'payment_mode' => $order->get_meta('_cod_guard_payment_mode'),
            'advance_status' => $order->get_meta('_cod_guard_advance_status') ?: 'pending',
            'cod_status' => $order->get_meta('_cod_guard_cod_status') ?: 'pending',
            'advance_paid_date' => $order->get_meta('_cod_guard_advance_paid_date'),
            'cod_paid_date' => $order->get_meta('_cod_guard_cod_paid_date'),
        );
    }
}