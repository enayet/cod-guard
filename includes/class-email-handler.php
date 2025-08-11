<?php
/**
 * COD Guard Email Handler - FIXED VERSION
 * 
 * Replace includes/class-email-handler.php with this version
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Email_Handler {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Email hooks
        add_action('cod_guard_advance_payment_completed', array($this, 'send_advance_payment_notification'));
        add_action('cod_guard_order_fully_completed', array($this, 'send_order_completion_notification'));
        
        // WooCommerce email hooks
        add_filter('woocommerce_email_classes', array($this, 'add_email_classes'));
        add_action('woocommerce_order_status_advance-paid', array($this, 'trigger_advance_paid_email'), 10, 2);
        
        // Email content modifications
        add_action('woocommerce_email_order_details', array($this, 'add_cod_guard_details_to_email'), 15, 4);
        add_action('woocommerce_email_after_order_table', array($this, 'add_cod_balance_notice'), 15, 4);
        
        // Email template modifications
        add_filter('woocommerce_locate_template', array($this, 'locate_cod_guard_template'), 10, 3);
        
        // Custom email actions
        add_action('init', array($this, 'register_email_actions'));
    }
    
    /**
     * Send advance payment notification to admin
     */
    public function send_advance_payment_notification($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !$this->is_cod_guard_order($order)) {
            return;
        }
        
        // Check if admin notifications are enabled
        $admin_notification = get_option('cod_guard_admin_notification', 'yes');
        
        if ($admin_notification !== 'yes') {
            return;
        }
        
        // Get payment summary
        $payment_summary = $this->get_order_payment_summary($order);
        
        if (!$payment_summary) {
            return;
        }
        
        // Email details
        $to = get_option('admin_email');
        $subject = get_option('cod_guard_admin_email_subject', __('COD Guard: Advance Payment Received - Order #{order_number}', 'cod-guard-wc'));
        $subject = str_replace('{order_number}', $order->get_order_number(), $subject);
        
        // Email content
        $message = $this->get_admin_notification_content($order, $payment_summary);
        
        // Email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        // Send email
        wp_mail($to, $subject, $message, $headers);
        
        // Log email
        $order->add_order_note(__('COD Guard: Admin notification email sent for advance payment.', 'cod-guard-wc'));
    }
    
    /**
     * Send order completion notification
     */
    public function send_order_completion_notification($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !$this->is_cod_guard_order($order)) {
            return;
        }
        
        // Send completion email to customer
        $customer_email = $order->get_billing_email();
        
        if ($customer_email) {
            $subject = sprintf(__('Order #%s - COD Payment Completed', 'cod-guard-wc'), $order->get_order_number());
            $message = $this->get_completion_notification_content($order);
            
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
            );
            
            wp_mail($customer_email, $subject, $message, $headers);
        }
        
        $order->add_order_note(__('COD Guard: Order completion notification sent to customer.', 'cod-guard-wc'));
    }
    
    /**
     * Get admin notification email content
     */
    private function get_admin_notification_content($order, $payment_summary) {
        ob_start();
        ?>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php _e('COD Guard - Advance Payment Received', 'cod-guard-wc'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
                .order-details { background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
                .payment-breakdown { margin: 20px 0; }
                .payment-breakdown table { width: 100%; border-collapse: collapse; }
                .payment-breakdown th, .payment-breakdown td { padding: 10px; border: 1px solid #ddd; text-align: left; }
                .payment-breakdown th { background: #f8f9fa; }
                .status-completed { color: #28a745; font-weight: bold; }
                .status-pending { color: #ffc107; font-weight: bold; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2><?php _e('COD Guard - Advance Payment Received', 'cod-guard-wc'); ?></h2>
                    <p><?php printf(__('Order #%s has received advance payment through COD Guard.', 'cod-guard-wc'), $order->get_order_number()); ?></p>
                </div>
                
                <div class="order-details">
                    <h3><?php _e('Order Details', 'cod-guard-wc'); ?></h3>
                    <p><strong><?php _e('Order Number:', 'cod-guard-wc'); ?></strong> #<?php echo $order->get_order_number(); ?></p>
                    <p><strong><?php _e('Customer:', 'cod-guard-wc'); ?></strong> <?php echo $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(); ?></p>
                    <p><strong><?php _e('Email:', 'cod-guard-wc'); ?></strong> <?php echo $order->get_billing_email(); ?></p>
                    <p><strong><?php _e('Phone:', 'cod-guard-wc'); ?></strong> <?php echo $order->get_billing_phone(); ?></p>
                    <p><strong><?php _e('Order Date:', 'cod-guard-wc'); ?></strong> <?php echo wc_format_datetime($order->get_date_created()); ?></p>
                </div>
                
                <div class="payment-breakdown">
                    <h3><?php _e('Payment Breakdown', 'cod-guard-wc'); ?></h3>
                    <table>
                        <thead>
                            <tr>
                                <th><?php _e('Payment Type', 'cod-guard-wc'); ?></th>
                                <th><?php _e('Amount', 'cod-guard-wc'); ?></th>
                                <th><?php _e('Status', 'cod-guard-wc'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php _e('Advance Payment', 'cod-guard-wc'); ?></td>
                                <td><?php echo wc_price($payment_summary['advance_amount']); ?></td>
                                <td class="status-completed"><?php _e('Completed', 'cod-guard-wc'); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('COD Balance', 'cod-guard-wc'); ?></td>
                                <td><?php echo wc_price($payment_summary['cod_amount']); ?></td>
                                <td class="status-pending"><?php _e('Pending Delivery', 'cod-guard-wc'); ?></td>
                            </tr>
                            <tr style="background: #f8f9fa;">
                                <td><strong><?php _e('Total Order Value', 'cod-guard-wc'); ?></strong></td>
                                <td><strong><?php echo wc_price($payment_summary['original_total']); ?></strong></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="order-items">
                    <h3><?php _e('Order Items', 'cod-guard-wc'); ?></h3>
                    <?php
                    echo '<table style="width: 100%; border-collapse: collapse;">';
                    echo '<thead><tr><th style="padding: 10px; border: 1px solid #ddd; text-align: left;">' . __('Product', 'cod-guard-wc') . '</th>';
                    echo '<th style="padding: 10px; border: 1px solid #ddd; text-align: left;">' . __('Quantity', 'cod-guard-wc') . '</th>';
                    echo '<th style="padding: 10px; border: 1px solid #ddd; text-align: left;">' . __('Price', 'cod-guard-wc') . '</th></tr></thead>';
                    echo '<tbody>';
                    
                    foreach ($order->get_items() as $item) {
                        echo '<tr>';
                        echo '<td style="padding: 10px; border: 1px solid #ddd;">' . $item->get_name() . '</td>';
                        echo '<td style="padding: 10px; border: 1px solid #ddd;">' . $item->get_quantity() . '</td>';
                        echo '<td style="padding: 10px; border: 1px solid #ddd;">' . wc_price($item->get_total()) . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody></table>';
                    ?>
                </div>
                
                <div class="next-steps">
                    <h3><?php _e('Next Steps', 'cod-guard-wc'); ?></h3>
                    <ul>
                        <li><?php _e('Process and prepare the order for shipping', 'cod-guard-wc'); ?></li>
                        <li><?php printf(__('Collect COD amount of %s upon delivery', 'cod-guard-wc'), wc_price($payment_summary['cod_amount'])); ?></li>
                        <li><?php _e('Mark COD as paid in the admin panel after delivery', 'cod-guard-wc'); ?></li>
                    </ul>
                </div>
                
                <div class="footer">
                    <p><?php _e('This is an automated notification from COD Guard plugin.', 'cod-guard-wc'); ?></p>
                    <p><?php printf(__('Manage this order: %s', 'cod-guard-wc'), '<a href="' . admin_url('post.php?post=' . $order->get_id() . '&action=edit') . '">' . __('View Order', 'cod-guard-wc') . '</a>'); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get completion notification email content
     */
    private function get_completion_notification_content($order) {
        $payment_summary = $this->get_order_payment_summary($order);
        
        ob_start();
        ?>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php _e('Order Completed - Thank You!', 'cod-guard-wc'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #28a745; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
                .content { background: #f8f9fa; padding: 20px; border-radius: 5px; }
                .footer { margin-top: 20px; text-align: center; color: #666; font-size: 0.9em; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2><?php _e('Payment Completed!', 'cod-guard-wc'); ?></h2>
                    <p><?php printf(__('Thank you for completing the payment for Order #%s', 'cod-guard-wc'), $order->get_order_number()); ?></p>
                </div>
                
                <div class="content">
                    <p><?php printf(__('Dear %s,', 'cod-guard-wc'), $order->get_billing_first_name()); ?></p>
                    
                    <p><?php _e('We have received your COD payment and your order is now fully completed. Thank you for your business!', 'cod-guard-wc'); ?></p>
                    
                    <h3><?php _e('Payment Summary', 'cod-guard-wc'); ?></h3>
                    <ul>
                        <li><?php printf(__('Advance Payment: %s (Completed)', 'cod-guard-wc'), wc_price($payment_summary['advance_amount'])); ?></li>
                        <li><?php printf(__('COD Payment: %s (Completed)', 'cod-guard-wc'), wc_price($payment_summary['cod_amount'])); ?></li>
                        <li><strong><?php printf(__('Total Paid: %s', 'cod-guard-wc'), wc_price($payment_summary['original_total'])); ?></strong></li>
                    </ul>
                    
                    <p><?php _e('If you have any questions about your order, please don\'t hesitate to contact us.', 'cod-guard-wc'); ?></p>
                </div>
                
                <div class="footer">
                    <p><?php printf(__('Order #%s | %s', 'cod-guard-wc'), $order->get_order_number(), get_bloginfo('name')); ?></p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Add COD Guard details to order emails
     */
    public function add_cod_guard_details_to_email($order, $sent_to_admin, $plain_text, $email) {
        if (!$this->is_cod_guard_order($order)) {
            return;
        }
        
        $payment_summary = $this->get_order_payment_summary($order);
        
        if (!$payment_summary) {
            return;
        }
        
        if ($plain_text) {
            $this->add_plain_text_cod_details($payment_summary);
        } else {
            $this->add_html_cod_details($payment_summary);
        }
    }
    
    /**
     * Add COD balance notice to emails - FIXED
     */
    public function add_cod_balance_notice($order, $sent_to_admin, $plain_text, $email) {
        if (!$this->is_cod_guard_order($order)) {
            return;
        }
        
        $cod_amount = $this->get_cod_amount($order);
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        
        if ($cod_amount <= 0 || $cod_status === 'completed') {
            return;
        }
        
        if ($plain_text) {
            echo "\n" . __('IMPORTANT: COD Balance Due', 'cod-guard-wc') . "\n";
            echo sprintf(__('Please pay %s to the delivery person when your order arrives.', 'cod-guard-wc'), wc_price($cod_amount));
            echo "\n";
        } else {
            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;">';
            echo '<h3 style="margin-top: 0; color: #856404;">' . __('Important: COD Balance Due', 'cod-guard-wc') . '</h3>';
            echo '<p style="margin-bottom: 0;">' . sprintf(
                __('Please pay %s to the delivery person when your order arrives.', 'cod-guard-wc'),
                '<strong>' . wc_price($cod_amount) . '</strong>'
            ) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Add HTML COD details
     */
    private function add_html_cod_details($payment_summary) {
        ?>
        <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
            <h3 style="margin-top: 0; color: #495057;"><?php _e('COD Guard Payment Details', 'cod-guard-wc'); ?></h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #fff;"><?php _e('Payment Mode:', 'cod-guard-wc'); ?></td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #fff;"><?php echo esc_html(ucfirst($payment_summary['payment_mode'])); ?></td>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #fff;"><?php _e('Advance Payment:', 'cod-guard-wc'); ?></td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #fff; color: #28a745; font-weight: bold;"><?php echo wc_price($payment_summary['advance_amount']); ?> (<?php _e('Paid', 'cod-guard-wc'); ?>)</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #fff;"><?php _e('COD Balance:', 'cod-guard-wc'); ?></td>
                    <td style="padding: 8px; border: 1px solid #ddd; background: #fff; color: #ffc107; font-weight: bold;"><?php echo wc_price($payment_summary['cod_amount']); ?> (<?php echo esc_html(ucfirst($payment_summary['cod_status'])); ?>)</td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Add plain text COD details
     */
    private function add_plain_text_cod_details($payment_summary) {
        echo "\n" . __('COD Guard Payment Details', 'cod-guard-wc') . "\n";
        echo str_repeat('-', 30) . "\n";
        echo sprintf(__('Payment Mode: %s', 'cod-guard-wc'), ucfirst($payment_summary['payment_mode'])) . "\n";
        echo sprintf(__('Advance Payment: %s (Paid)', 'cod-guard-wc'), wc_price($payment_summary['advance_amount'])) . "\n";
        echo sprintf(__('COD Balance: %s (%s)', 'cod-guard-wc'), wc_price($payment_summary['cod_amount']), ucfirst($payment_summary['cod_status'])) . "\n";
        echo "\n";
    }
    
    /**
     * Add custom email classes
     */
    public function add_email_classes($email_classes) {
        // For future expansion - custom email classes can be added here
        return $email_classes;
    }
    
    /**
     * Trigger advance paid email
     */
    public function trigger_advance_paid_email($order_id, $order) {
        // This can be expanded to send custom emails for advance-paid status
        do_action('cod_guard_advance_paid_status', $order_id, $order);
    }
    
    /**
     * Locate COD Guard email templates
     */
    public function locate_cod_guard_template($template, $template_name, $template_path) {
        // Check if this is a COD Guard template
        if (strpos($template_name, 'cod-guard') !== false) {
            $plugin_template = COD_GUARD_PLUGIN_PATH . 'templates/' . $template_name;
            
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }
        
        return $template;
    }
    
    /**
     * Register email actions
     */
    public function register_email_actions() {
        // Register actions that can trigger emails
        add_action('cod_guard_send_reminder_email', array($this, 'send_cod_reminder_email'));
    }
    
    /**
     * Send COD reminder email
     */
    public function send_cod_reminder_email($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || !$this->is_cod_guard_order($order)) {
            return;
        }
        
        $cod_amount = $this->get_cod_amount($order);
        $cod_status = $order->get_meta('_cod_guard_cod_status');
        
        if ($cod_amount <= 0 || $cod_status === 'completed') {
            return;
        }
        
        $customer_email = $order->get_billing_email();
        
        if (!$customer_email) {
            return;
        }
        
        $subject = sprintf(__('Reminder: COD Payment Due for Order #%s', 'cod-guard-wc'), $order->get_order_number());
        $message = sprintf(
            __('This is a friendly reminder that you have a COD balance of %s due for Order #%s. Please ensure you have the exact amount ready when your order is delivered.', 'cod-guard-wc'),
            wc_price($cod_amount),
            $order->get_order_number()
        );
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        wp_mail($customer_email, $subject, $message, $headers);
        
        $order->add_order_note(__('COD Guard: Reminder email sent to customer for pending COD payment.', 'cod-guard-wc'));
    }
    
    /**
     * Helper Methods - using main plugin methods
     */
    
    /**
     * Check if order uses COD Guard
     */
    private function is_cod_guard_order($order) {
        return COD_Guard_WooCommerce::is_cod_guard_order($order);
    }
    
    /**
     * Get advance amount for an order
     */
    private function get_advance_amount($order) {
        return COD_Guard_WooCommerce::get_advance_amount($order);
    }
    
    /**
     * Get COD amount for an order
     */
    private function get_cod_amount($order) {
        return COD_Guard_WooCommerce::get_cod_amount($order);
    }
    
    /**
     * Get order payment summary
     */
    private function get_order_payment_summary($order) {
        return COD_Guard_WooCommerce::get_order_payment_summary($order);
    }
}