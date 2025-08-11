<?php
/**
 * Plugin Name: COD Guard - Partial Payment for WooCommerce (Checkbox Version)
 * Plugin URI: https://your-website.com
 * Description: Reduce fake COD orders with flexible advance payments. Simple checkbox approach.
 * Version: 1.1.0
 * Author: Your Name
 * Author URI: https://your-website.com
 * Text Domain: cod-guard-wc
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('COD_GUARD_VERSION', '1.1.0');
define('COD_GUARD_PLUGIN_FILE', __FILE__);
define('COD_GUARD_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('COD_GUARD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COD_GUARD_TEXT_DOMAIN', 'cod-guard-wc');

/**
 * Main COD Guard Plugin Class
 */
final class COD_Guard_WooCommerce {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Load textdomain first
        add_action('plugins_loaded', array($this, 'load_textdomain'), 1);
        
        // Then initialize plugin
        add_action('plugins_loaded', array($this, 'init_plugin'), 10);
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Load plugin textdomain early
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'cod-guard-wc',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }
    
    /**
     * Initialize plugin after WooCommerce is loaded
     */
    public function init_plugin() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Load plugin classes
        $this->load_classes();
        
        // Initialize components
        $this->init_components();
        
        // Plugin loaded hook
        do_action('cod_guard_loaded');
    }
    
    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }
    
    /**
     * Load plugin classes - UPDATED to include checkout success handler
     */
    private function load_classes() {
        // Core classes for checkbox approach
        require_once COD_GUARD_PLUGIN_PATH . 'includes/class-checkbox-handler.php';
        require_once COD_GUARD_PLUGIN_PATH . 'includes/class-payment-handler.php';
        require_once COD_GUARD_PLUGIN_PATH . 'includes/class-checkout-success.php';
        require_once COD_GUARD_PLUGIN_PATH . 'includes/class-order-management.php';
        require_once COD_GUARD_PLUGIN_PATH . 'includes/class-email-handler.php';
        
        // Load admin settings only in admin
        if (is_admin()) {
            require_once COD_GUARD_PLUGIN_PATH . 'includes/class-admin-settings.php';
        }
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize admin settings
        if (is_admin()) {
            new COD_Guard_Admin_Settings();
        }
        
        // Initialize checkbox handler (the main functionality)
        new COD_Guard_Checkbox_Handler();
        
        new COD_Guard_Payment_Handler();
        
        // Initialize checkout success handler
        new COD_Guard_Checkout_Success();
        
        // Initialize order management and emails
        new COD_Guard_Order_Management();
        new COD_Guard_Email_Handler();
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Check WooCommerce dependency
        if (!$this->is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('COD Guard requires WooCommerce to be installed and activated.', 'cod-guard-wc'),
                __('Plugin Activation Error', 'cod-guard-wc'),
                array('back_link' => true)
            );
        }
        
        // Set default options
        $this->set_default_options();
        
        // Create custom order status
        $this->register_custom_order_status();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Clear any existing logs
        error_log('COD Guard: Plugin activated successfully');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
        flush_rewrite_rules();
        
        error_log('COD Guard: Plugin deactivated');
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $defaults = array(
            'enabled' => 'yes',
            'payment_mode' => 'percentage', // percentage, shipping, fixed
            'percentage_amount' => 25,
            'fixed_amount' => 10,
            'title' => __('COD Guard - Advance Payment + COD', 'cod-guard-wc'),
            'description' => __('Pay a small advance amount now and the remaining balance on delivery.', 'cod-guard-wc'),
            'enable_for_categories' => array(),
            'minimum_order_amount' => 0,
            'cod_behavior' => 'replace', // replace or alongside
            'advance_payment_methods' => array(), // Empty means all except COD
            'admin_notification' => 'yes',
            'admin_email_subject' => __('COD Guard: Advance Payment Received - Order #{order_number}', 'cod-guard-wc'),
        );
        
        foreach ($defaults as $key => $value) {
            $option_key = 'cod_guard_' . $key;
            if (false === get_option($option_key)) {
                update_option($option_key, $value);
            }
        }
    }
    
    /**
     * Register custom order status
     */
    private function register_custom_order_status() {
        add_action('init', function() {
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
                )
            ));
        });
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php 
                printf(
                    __('%1$sCOD Guard%2$s requires WooCommerce to be installed and activated.', 'cod-guard-wc'),
                    '<strong>',
                    '</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Get plugin settings
     */
    public static function get_settings() {
        return array(
            'enabled' => get_option('cod_guard_enabled', 'yes'),
            'payment_mode' => get_option('cod_guard_payment_mode', 'percentage'),
            'percentage_amount' => get_option('cod_guard_percentage_amount', 25),
            'fixed_amount' => get_option('cod_guard_fixed_amount', 10),
            'title' => get_option('cod_guard_title', __('COD Guard - Advance Payment + COD', 'cod-guard-wc')),
            'description' => get_option('cod_guard_description', __('Pay a small advance amount now and the remaining balance on delivery.', 'cod-guard-wc')),
            'enable_for_categories' => get_option('cod_guard_enable_for_categories', array()),
            'minimum_order_amount' => get_option('cod_guard_minimum_order_amount', 0),
            'cod_behavior' => get_option('cod_guard_cod_behavior', 'replace'),
            'advance_payment_methods' => get_option('cod_guard_advance_payment_methods', array()),
        );
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
     * Get original order total before COD Guard adjustment
     */
    public static function get_original_total($order) {
        if (!$order) {
            return 0;
        }
        return floatval($order->get_meta('_cod_guard_original_total'));
    }
    
    /**
     * Calculate advance amount for given total and settings
     */
    public static function calculate_advance_amount($total) {
        $settings = self::get_settings();
        $payment_mode = $settings['payment_mode'];
        $advance_amount = 0;
        
        switch ($payment_mode) {
            case 'percentage':
                $percentage = intval($settings['percentage_amount']);
                $advance_amount = ($total * $percentage) / 100;
                break;
                
            case 'shipping':
                // For shipping mode, we'd need shipping cost
                // This is handled in the checkout handler
                $advance_amount = 0;
                break;
                
            case 'fixed':
                $advance_amount = floatval($settings['fixed_amount']);
                if ($advance_amount > $total) {
                    $advance_amount = $total;
                }
                break;
        }
        
        return max(0, $advance_amount);
    }
    
    /**
     * Get payment summary for an order
     */
    public static function get_order_payment_summary($order) {
        if (!self::is_cod_guard_order($order)) {
            return false;
        }
        
        return array(
            'advance_amount' => self::get_advance_amount($order),
            'cod_amount' => self::get_cod_amount($order),
            'original_total' => self::get_original_total($order),
            'payment_mode' => self::get_payment_mode($order),
            'advance_status' => $order->get_meta('_cod_guard_advance_status'),
            'cod_status' => $order->get_meta('_cod_guard_cod_status'),
            'advance_paid_date' => $order->get_meta('_cod_guard_advance_paid_date'),
            'cod_paid_date' => $order->get_meta('_cod_guard_cod_paid_date'),
        );
    }
    
    /**
     * Check if COD Guard is available for current cart
     */
    public static function is_available_for_cart() {
        $settings = self::get_settings();
        
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
     * Get plugin version
     */
    public static function get_version() {
        return COD_GUARD_VERSION;
    }
    
    /**
     * Get plugin path
     */
    public static function get_plugin_path() {
        return COD_GUARD_PLUGIN_PATH;
    }
    
    /**
     * Get plugin URL
     */
    public static function get_plugin_url() {
        return COD_GUARD_PLUGIN_URL;
    }
    
    /**
     * Log debug messages
     */
    public static function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[COD Guard ' . strtoupper($level) . '] ' . $message);
        }
    }
}

/**
 * Initialize the plugin
 */
function cod_guard_woocommerce() {
    return COD_Guard_WooCommerce::get_instance();
}

// Start the plugin
cod_guard_woocommerce();



if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('init', function() {
        // Debug checkout process
        add_action('woocommerce_checkout_process', function() {
            if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1') {
                error_log('COD Guard Debug: Checkout process started');
                error_log('COD Guard Debug: POST data - ' . print_r($_POST, true));
            }
        }, 1);
        
        // Debug order creation
        add_action('woocommerce_checkout_order_processed', function($order_id, $posted_data, $order) {
            if (isset($_POST['cod_guard_enabled']) && $_POST['cod_guard_enabled'] === '1') {
                error_log('COD Guard Debug: Order ' . $order_id . ' processed');
                error_log('COD Guard Debug: Order status - ' . $order->get_status());
                error_log('COD Guard Debug: Order total - ' . $order->get_total());
                error_log('COD Guard Debug: Cart empty? - ' . (WC()->cart->is_empty() ? 'YES' : 'NO'));
            }
        }, 1, 3);
        
        // Debug payment completion
        add_action('woocommerce_payment_complete', function($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_meta('_cod_guard_enabled') === 'yes') {
                error_log('COD Guard Debug: Payment completed for order ' . $order_id);
                error_log('COD Guard Debug: Order status after payment - ' . $order->get_status());
            }
        });
        
        // Debug notices
        add_action('woocommerce_before_checkout_form', function() {
            $notices = wc_get_notices();
            if (!empty($notices)) {
                error_log('COD Guard Debug: Notices on checkout - ' . print_r($notices, true));
            }
        });
        
        // Debug session data
        add_action('wp_footer', function() {
            if (is_checkout() && WC()->session) {
                $session_data = array(
                    'cod_guard_processing' => WC()->session->get('cod_guard_processing'),
                    'cod_guard_order_success' => WC()->session->get('cod_guard_order_success'),
                    'cod_guard_original_total' => WC()->session->get('cod_guard_original_total'),
                );
                error_log('COD Guard Debug: Session data - ' . print_r($session_data, true));
            }
        });
        
        // Debug redirects
        add_action('wp_redirect', function($location, $status) {
            if (strpos($location, 'order-received') !== false) {
                error_log('COD Guard Debug: Redirect to - ' . $location);
            }
        }, 10, 2);
    });
}

// Also add this function to help with manual debugging
function cod_guard_debug_order($order_id) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('COD Guard Debug: Order ' . $order_id . ' not found');
        return;
    }
    
    $debug_data = array(
        'order_id' => $order_id,
        'status' => $order->get_status(),
        'total' => $order->get_total(),
        'payment_method' => $order->get_payment_method(),
        'cod_guard_enabled' => $order->get_meta('_cod_guard_enabled'),
        'advance_amount' => $order->get_meta('_cod_guard_advance_amount'),
        'cod_amount' => $order->get_meta('_cod_guard_cod_amount'),
        'original_total' => $order->get_meta('_cod_guard_original_total'),
        'advance_status' => $order->get_meta('_cod_guard_advance_status'),
        'cod_status' => $order->get_meta('_cod_guard_cod_status'),
        'checkout_success' => $order->get_meta('_cod_guard_checkout_success'),
    );
    
    error_log('COD Guard Debug Order ' . $order_id . ': ' . print_r($debug_data, true));
}