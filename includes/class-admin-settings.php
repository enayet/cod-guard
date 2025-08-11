<?php
/**
 * COD Guard Admin Settings - COMPLETE FIX
 * 
 * Replace your existing class-admin-settings.php with this
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class COD_Guard_Admin_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Use admin_init instead of woocommerce_admin_init
        add_action('admin_init', array($this, 'init_settings_page'));
        
        // Also add the hooks directly as backup
        add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 50);
        add_action('woocommerce_settings_cod_guard', array($this, 'output'));
        add_action('woocommerce_settings_save_cod_guard', array($this, 'save'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }
    
    /**
     * Initialize settings page - now using admin_init
     */
    public function init_settings_page() {
        // Check if we're in admin and WooCommerce is available
        if (!is_admin() || !class_exists('WooCommerce')) {
            return;
        }
        
        // Double-check the hooks are added (redundant but safe)
        if (!has_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'))) {
            add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 50);
        }
        
        if (!has_action('woocommerce_settings_cod_guard', array($this, 'output'))) {
            add_action('woocommerce_settings_cod_guard', array($this, 'output'));
        }
        
        if (!has_action('woocommerce_settings_save_cod_guard', array($this, 'save'))) {
            add_action('woocommerce_settings_save_cod_guard', array($this, 'save'));
        }
    }
    
    /**
     * Add COD Guard settings tab
     */
    public function add_settings_tab($settings_tabs) {
        $settings_tabs['cod_guard'] = __('COD Guard', 'cod-guard-wc');
        return $settings_tabs;
    }
    
    /**
     * Get settings array
     */
    public function get_settings() {
        $settings = array(
            array(
                'name' => __('COD Guard Settings', 'cod-guard-wc'),
                'type' => 'title',
                'desc' => __('Configure advance payment options to reduce fake COD orders.', 'cod-guard-wc'),
                'id'   => 'cod_guard_settings'
            ),
            
            array(
                'name'    => __('Enable COD Guard', 'cod-guard-wc'),
                'type'    => 'checkbox',
                'desc'    => __('Enable advance payment for COD orders', 'cod-guard-wc'),
                'id'      => 'cod_guard_enabled',
                'default' => 'yes',
            ),
            
            array(
                'name'    => __('Payment Mode', 'cod-guard-wc'),
                'type'    => 'select',
                'desc'    => __('Choose how advance payment amount is calculated', 'cod-guard-wc'),
                'id'      => 'cod_guard_payment_mode',
                'options' => array(
                    'percentage' => __('Percentage of Order Total', 'cod-guard-wc'),
                    'shipping'   => __('Shipping Charges Only', 'cod-guard-wc'),
                    'fixed'      => __('Fixed Amount', 'cod-guard-wc'),
                ),
                'default' => 'percentage',
                'class'   => 'wc-enhanced-select cod-guard-payment-mode-select',
            ),
            
            array(
                'name'              => __('Advance Percentage', 'cod-guard-wc'),
                'type'              => 'number',
                'desc'              => __('Percentage of order total to collect as advance payment (10-90%)', 'cod-guard-wc'),
                'id'                => 'cod_guard_percentage_amount',
                'default'           => '25',
                'custom_attributes' => array(
                    'min'  => '10',
                    'max'  => '90',
                    'step' => '1',
                ),
                'class'             => 'cod-guard-percentage-field',
            ),
            
            array(
                'name'              => __('Fixed Amount', 'cod-guard-wc'),
                'type'              => 'number',
                'desc'              => __('Fixed amount to collect as advance payment', 'cod-guard-wc'),
                'id'                => 'cod_guard_fixed_amount',
                'default'           => '10',
                'custom_attributes' => array(
                    'min'  => '1',
                    'step' => '1',
                ),
                'class'             => 'cod-guard-fixed-field',
                'css'               => 'width: 150px;',
            ),
            
            array(
                'name'    => __('Payment Method Title', 'cod-guard-wc'),
                'type'    => 'text',
                'desc'    => __('Title displayed to customers during checkout', 'cod-guard-wc'),
                'id'      => 'cod_guard_title',
                'default' => __('COD Guard - Advance Payment + COD', 'cod-guard-wc'),
                'css'     => 'width: 400px;',
            ),
            
            array(
                'name'    => __('Payment Method Description', 'cod-guard-wc'),
                'type'    => 'textarea',
                'desc'    => __('Description displayed to customers during checkout', 'cod-guard-wc'),
                'id'      => 'cod_guard_description',
                'default' => __('Pay a small advance amount now and the remaining balance on delivery.', 'cod-guard-wc'),
                'css'     => 'width: 400px; height: 60px;',
            ),
            
            array(
                'name'    => __('Minimum Order Amount', 'cod-guard-wc'),
                'type'    => 'price',
                'desc'    => __('Minimum order amount required to use COD Guard (0 for no minimum)', 'cod-guard-wc'),
                'id'      => 'cod_guard_minimum_order_amount',
                'default' => '0',
                'custom_attributes' => array(
                    'min'  => '0',
                    'step' => '0.01',
                ),
            ),
            
            array(
                'name'    => __('Enable for Categories', 'cod-guard-wc'),
                'type'    => 'multiselect',
                'desc'    => __('Select product categories where COD Guard should be available. If multiple products from different categories are in cart, COD Guard will be available if ANY product matches. Leave empty for all categories.', 'cod-guard-wc'),
                'id'      => 'cod_guard_enable_for_categories',
                'options' => $this->get_product_categories_hierarchical(),
                'class'   => 'wc-enhanced-select',
            ),
            
            array(
                'name'    => __('COD Method Behavior', 'cod-guard-wc'),
                'type'    => 'select',
                'desc'    => __('How should COD Guard interact with the default COD payment method?', 'cod-guard-wc'),
                'id'      => 'cod_guard_cod_behavior',
                'options' => array(
                    'replace'    => __('Replace default COD completely', 'cod-guard-wc'),
                    'alongside'  => __('Show alongside default COD', 'cod-guard-wc'),
                ),
                'default' => 'replace',
                'class'   => 'wc-enhanced-select',
            ),
            
            array(
                'name'    => __('Advance Payment Methods', 'cod-guard-wc'),
                'type'    => 'multiselect',
                'desc'    => __('Which payment methods should be available for advance payment? (Leave empty to allow all except COD)', 'cod-guard-wc'),
                'id'      => 'cod_guard_advance_payment_methods',
                'options' => $this->get_available_payment_methods(),
                'class'   => 'wc-enhanced-select',
            ),
            
            array(
                'type' => 'sectionend',
                'id'   => 'cod_guard_settings'
            ),
            
            // Email Settings Section
            array(
                'name' => __('Email Settings', 'cod-guard-wc'),
                'type' => 'title',
                'desc' => __('Configure email notifications for COD Guard orders.', 'cod-guard-wc'),
                'id'   => 'cod_guard_email_settings'
            ),
            
            array(
                'name'    => __('Send Admin Notification', 'cod-guard-wc'),
                'type'    => 'checkbox',
                'desc'    => __('Send email to admin when advance payment is received', 'cod-guard-wc'),
                'id'      => 'cod_guard_admin_notification',
                'default' => 'yes',
            ),
            
            array(
                'name'    => __('Admin Email Subject', 'cod-guard-wc'),
                'type'    => 'text',
                'desc'    => __('Subject line for admin notification emails', 'cod-guard-wc'),
                'id'      => 'cod_guard_admin_email_subject',
                'default' => __('COD Guard: Advance Payment Received - Order #{order_number}', 'cod-guard-wc'),
                'css'     => 'width: 400px;',
            ),
            
            array(
                'type' => 'sectionend',
                'id'   => 'cod_guard_email_settings'
            ),
        );
        
        return apply_filters('cod_guard_settings', $settings);
    }
    
    /**
     * Output settings page
     */
    public function output() {
        $settings = $this->get_settings();
        WC_Admin_Settings::output_fields($settings);
        
        // Add custom JavaScript for dynamic field visibility
        $this->output_custom_js();
    }
    
    /**
     * Save settings
     */
    public function save() {
        $settings = $this->get_settings();
        
        // Custom validation
        $this->validate_settings();
        
        WC_Admin_Settings::save_fields($settings);
    }
    
    /**
     * Validate settings before saving
     */
    private function validate_settings() {
        // Validate percentage amount
        if (isset($_POST['cod_guard_percentage_amount'])) {
            $percentage = intval($_POST['cod_guard_percentage_amount']);
            if ($percentage < 10 || $percentage > 90) {
                WC_Admin_Settings::add_error(__('Advance percentage must be between 10% and 90%.', 'cod-guard-wc'));
            }
        }
        
        // Validate fixed amount
        if (isset($_POST['cod_guard_fixed_amount'])) {
            $fixed_amount = floatval($_POST['cod_guard_fixed_amount']);
            if ($fixed_amount < 0) {
                WC_Admin_Settings::add_error(__('Fixed amount cannot be negative.', 'cod-guard-wc'));
            }
        }
        
        // Validate minimum order amount
        if (isset($_POST['cod_guard_minimum_order_amount'])) {
            $minimum_order = floatval($_POST['cod_guard_minimum_order_amount']);
            if ($minimum_order < 0) {
                WC_Admin_Settings::add_error(__('Minimum order amount cannot be negative.', 'cod-guard-wc'));
            }
        }
    }
    
    /**
     * Get product categories with hierarchy for multiselect
     */
    private function get_product_categories_hierarchical() {
        $categories = array();
        
        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'hierarchical' => true,
        ));
        
        if (!is_wp_error($terms) && !empty($terms)) {
            // Build hierarchical array
            $hierarchy = $this->build_category_hierarchy($terms);
            $categories = $this->flatten_category_hierarchy($hierarchy);
        }
        
        return $categories;
    }
    
    /**
     * Build category hierarchy
     */
    private function build_category_hierarchy($terms, $parent = 0) {
        $hierarchy = array();
        
        foreach ($terms as $term) {
            if ($term->parent == $parent) {
                $children = $this->build_category_hierarchy($terms, $term->term_id);
                if ($children) {
                    $term->children = $children;
                }
                $hierarchy[] = $term;
            }
        }
        
        return $hierarchy;
    }
    
    /**
     * Flatten category hierarchy for dropdown
     */
    private function flatten_category_hierarchy($categories, $level = 0) {
        $flattened = array();
        
        foreach ($categories as $category) {
            $prefix = str_repeat('— ', $level);
            $flattened[$category->term_id] = $prefix . $category->name;
            
            if (isset($category->children)) {
                $flattened = $flattened + $this->flatten_category_hierarchy($category->children, $level + 1);
            }
        }
        
        return $flattened;
    }
    
    /**
     * Get available payment methods (excluding COD)
     */
    private function get_available_payment_methods() {
        $methods = array();
        
        if (class_exists('WooCommerce') && WC()->payment_gateways) {
            $gateways = WC()->payment_gateways->payment_gateways();
            
            foreach ($gateways as $id => $gateway) {
                // Exclude COD and COD Guard itself
                if ($id !== 'cod' && $id !== 'cod_guard' && $gateway->enabled === 'yes') {
                    $methods[$id] = $gateway->get_title();
                }
            }
        }
        
        return $methods;
    }
    
    /**
     * Output custom JavaScript for admin page - SIMPLIFIED VERSION
     */
    private function output_custom_js() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Show/hide fields based on payment mode
            function togglePaymentModeFields() {
                var paymentMode = $('#cod_guard_payment_mode').val();
                
                // Hide all mode-specific fields first
                $('.cod-guard-percentage-field').closest('tr').hide();
                $('.cod-guard-fixed-field').closest('tr').hide();
                
                // Show relevant field
                if (paymentMode === 'percentage') {
                    $('.cod-guard-percentage-field').closest('tr').show();
                } else if (paymentMode === 'fixed') {
                    $('.cod-guard-fixed-field').closest('tr').show();
                }
                // Shipping mode doesn't need extra fields
            }
            
            // Initial toggle on page load
            togglePaymentModeFields();
            
            // Toggle on change
            $('#cod_guard_payment_mode').on('change', function() {
                togglePaymentModeFields();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_scripts($hook) {
        // Only load on WooCommerce settings page
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        
        // Only load on COD Guard tab
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'cod_guard') {
            return;
        }
        
        wp_enqueue_script(
            'cod-guard-admin',
            COD_GUARD_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'wc-enhanced-select'),
            COD_GUARD_VERSION,
            true
        );
        
        wp_enqueue_style(
            'cod-guard-admin',
            COD_GUARD_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            COD_GUARD_VERSION
        );
        
        // Localize script
        wp_localize_script('cod-guard-admin', 'codGuardAdmin', array(
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'currency_position' => get_option('woocommerce_currency_pos'),
            'decimal_separator' => wc_get_price_decimal_separator(),
            'thousand_separator' => wc_get_price_thousand_separator(),
            'decimals' => wc_get_price_decimals(),
        ));
    }
}