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
     * Load plugin classes
     */
    private function load_classes() {
        // Load classes in the correct order - UPDATED for checkbox approach
        require_once COD_GUARD_PLUGIN_PATH . 'includes/class-checkbox-handler.php';
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
        
        // Initialize checkbox handler (replaces payment gateway)
        new COD_Guard_Checkbox_Handler();
        
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
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
        flush_rewrite_rules();
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
}

/**
 * Initialize the plugin
 */
function cod_guard_woocommerce() {
    return COD_Guard_WooCommerce::get_instance();
}

// Start the plugin
cod_guard_woocommerce();