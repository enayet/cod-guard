<?php
/**
 * COD Guard Checkout Payment Breakdown Template
 * 
 * This template can be overridden by copying it to yourtheme/woocommerce/cod-guard/checkout-payment-breakdown.php
 * 
 * @package COD_Guard
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if breakdown data is available
if (empty($breakdown)) {
    return;
}

// Extract breakdown data
$subtotal = $breakdown['subtotal'] ?? 0;
$shipping = $breakdown['shipping'] ?? 0;
$tax = $breakdown['tax'] ?? 0;
$total = $breakdown['total'] ?? 0;
$advance_amount = $breakdown['advance_amount'] ?? 0;
$cod_amount = $breakdown['cod_amount'] ?? 0;
$payment_mode = $breakdown['payment_mode'] ?? '';
$mode_label = $breakdown['mode_label'] ?? '';

// Sanitize data
$subtotal = floatval($subtotal);
$shipping = floatval($shipping);
$tax = floatval($tax);
$total = floatval($total);
$advance_amount = floatval($advance_amount);
$cod_amount = floatval($cod_amount);
$payment_mode = sanitize_text_field($payment_mode);
$mode_label = sanitize_text_field($mode_label);

// Get display settings
$show_detailed_breakdown = apply_filters('cod_guard_show_detailed_breakdown', true);
$show_icons = apply_filters('cod_guard_show_payment_icons', true);
$table_style = apply_filters('cod_guard_table_style', 'default'); // default, minimal, detailed
?>

<div class="cod-guard-payment-breakdown" data-payment-mode="<?php echo esc_attr($payment_mode); ?>">
    
    <?php do_action('cod_guard_before_payment_breakdown', $breakdown); ?>
    
    <div class="cod-guard-breakdown-header">
        <h4 class="cod-guard-breakdown-title">
            <?php if ($show_icons): ?>
                <span class="cod-guard-icon">📊</span>
            <?php endif; ?>
            <?php echo esc_html(apply_filters('cod_guard_breakdown_title', __('Payment Breakdown', 'cod-guard-wc'))); ?>
            <span class="cod-guard-mode-badge cod-guard-mode-<?php echo esc_attr($payment_mode); ?>">
                <?php echo esc_html($mode_label); ?>
            </span>
        </h4>
        
        <?php if (apply_filters('cod_guard_show_breakdown_description', true)): ?>
            <p class="cod-guard-breakdown-description">
                <?php 
                switch ($payment_mode) {
                    case 'percentage':
                        printf(
                            __('Pay %s of your order total now, and the remaining %s on delivery.', 'cod-guard-wc'),
                            '<strong>' . esc_html($mode_label) . '</strong>',
                            '<strong>' . wc_price($cod_amount) . '</strong>'
                        );
                        break;
                    case 'shipping':
                        printf(
                            __('Pay shipping charges now (%s), and the product amount (%s) on delivery.', 'cod-guard-wc'),
                            '<strong>' . wc_price($advance_amount) . '</strong>',
                            '<strong>' . wc_price($cod_amount) . '</strong>'
                        );
                        break;
                    case 'fixed':
                        printf(
                            __('Pay a fixed advance of %s now, and %s on delivery.', 'cod-guard-wc'),
                            '<strong>' . wc_price($advance_amount) . '</strong>',
                            '<strong>' . wc_price($cod_amount) . '</strong>'
                        );
                        break;
                    default:
                        _e('Split your payment between now and delivery for added security.', 'cod-guard-wc');
                }
                ?>
            </p>
        <?php endif; ?>
    </div>
    
    <div class="cod-guard-breakdown-content">
        <table class="cod-guard-breakdown-table cod-guard-table-<?php echo esc_attr($table_style); ?>">
            <tbody>
                
                <?php if ($show_detailed_breakdown): ?>
                    <!-- Order Details Section -->
                    <tr class="cod-guard-section-header">
                        <td colspan="2">
                            <strong class="cod-guard-section-title">
                                <?php if ($show_icons): ?>
                                    <span class="cod-guard-icon">🛒</span>
                                <?php endif; ?>
                                <?php _e('Order Details', 'cod-guard-wc'); ?>
                            </strong>
                        </td>
                    </tr>
                    
                    <tr class="cod-guard-subtotal-row">
                        <td class="cod-guard-label">
                            <?php _e('Subtotal:', 'cod-guard-wc'); ?>
                        </td>
                        <td class="cod-guard-amount">
                            <?php echo wc_price($subtotal); ?>
                        </td>
                    </tr>
                    
                    <?php if ($shipping > 0): ?>
                        <tr class="cod-guard-shipping-row">
                            <td class="cod-guard-label">
                                <?php _e('Shipping:', 'cod-guard-wc'); ?>
                                <?php if ($payment_mode === 'shipping'): ?>
                                    <span class="cod-guard-highlight-badge"><?php _e('Advance', 'cod-guard-wc'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="cod-guard-amount">
                                <?php echo wc_price($shipping); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php if ($tax > 0): ?>
                        <tr class="cod-guard-tax-row">
                            <td class="cod-guard-label">
                                <?php _e('Tax:', 'cod-guard-wc'); ?>
                            </td>
                            <td class="cod-guard-amount">
                                <?php echo wc_price($tax); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    
                    <tr class="cod-guard-total-row cod-guard-divider">
                        <td class="cod-guard-label">
                            <strong><?php _e('Order Total:', 'cod-guard-wc'); ?></strong>
                        </td>
                        <td class="cod-guard-amount">
                            <strong><?php echo wc_price($total); ?></strong>
                        </td>
                    </tr>
                    
                <?php else: ?>
                    <!-- Simplified Total Row -->
                    <tr class="cod-guard-total-row">
                        <td class="cod-guard-label">
                            <strong><?php _e('Order Total:', 'cod-guard-wc'); ?></strong>
                        </td>
                        <td class="cod-guard-amount">
                            <strong><?php echo wc_price($total); ?></strong>
                        </td>
                    </tr>
                <?php endif; ?>
                
                <!-- Payment Split Section -->
                <tr class="cod-guard-section-header cod-guard-payment-split">
                    <td colspan="2">
                        <strong class="cod-guard-section-title">
                            <?php if ($show_icons): ?>
                                <span class="cod-guard-icon">💳</span>
                            <?php endif; ?>
                            <?php _e('Payment Split', 'cod-guard-wc'); ?>
                        </strong>
                    </td>
                </tr>
                
                <tr class="cod-guard-advance-row cod-guard-highlight-advance">
                    <td class="cod-guard-label">
                        <strong>
                            <?php if ($show_icons): ?>
                                <span class="cod-guard-payment-icon">💰</span>
                            <?php endif; ?>
                            <?php 
                            printf(
                                __('Pay Now (%s):', 'cod-guard-wc'), 
                                '<span class="cod-guard-mode-text">' . esc_html($mode_label) . '</span>'
                            ); 
                            ?>
                        </strong>
                    </td>
                    <td class="cod-guard-amount">
                        <strong class="cod-guard-advance-amount" data-amount="<?php echo esc_attr($advance_amount); ?>">
                            <?php echo wc_price($advance_amount); ?>
                        </strong>
                    </td>
                </tr>
                
                <tr class="cod-guard-cod-row cod-guard-highlight-cod">
                    <td class="cod-guard-label">
                        <strong>
                            <?php if ($show_icons): ?>
                                <span class="cod-guard-payment-icon">🚚</span>
                            <?php endif; ?>
                            <?php _e('Pay on Delivery:', 'cod-guard-wc'); ?>
                        </strong>
                    </td>
                    <td class="cod-guard-amount">
                        <strong class="cod-guard-cod-amount" data-amount="<?php echo esc_attr($cod_amount); ?>">
                            <?php echo wc_price($cod_amount); ?>
                        </strong>
                    </td>
                </tr>
                
            </tbody>
        </table>
        
        <?php if (apply_filters('cod_guard_show_payment_summary', true)): ?>
            <div class="cod-guard-payment-summary">
                <div class="cod-guard-summary-item cod-guard-summary-advance">
                    <span class="cod-guard-summary-label"><?php _e('Advance Payment', 'cod-guard-wc'); ?></span>
                    <span class="cod-guard-summary-amount"><?php echo wc_price($advance_amount); ?></span>
                </div>
                <div class="cod-guard-summary-divider">+</div>
                <div class="cod-guard-summary-item cod-guard-summary-cod">
                    <span class="cod-guard-summary-label"><?php _e('COD Payment', 'cod-guard-wc'); ?></span>
                    <span class="cod-guard-summary-amount"><?php echo wc_price($cod_amount); ?></span>
                </div>
                <div class="cod-guard-summary-divider">=</div>
                <div class="cod-guard-summary-item cod-guard-summary-total">
                    <span class="cod-guard-summary-label"><?php _e('Total', 'cod-guard-wc'); ?></span>
                    <span class="cod-guard-summary-amount"><?php echo wc_price($total); ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (apply_filters('cod_guard_show_security_notice', true)): ?>
            <div class="cod-guard-security-notice">
                <div class="cod-guard-notice-content">
                    <?php if ($show_icons): ?>
                        <span class="cod-guard-security-icon">🛡️</span>
                    <?php endif; ?>
                    <div class="cod-guard-notice-text">
                        <strong><?php _e('Why split payment?', 'cod-guard-wc'); ?></strong>
                        <p><?php _e('This reduces fake orders and ensures genuine purchases. Your advance payment secures your order.', 'cod-guard-wc'); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
    
    <!-- Hidden fields for form submission -->
    <div class="cod-guard-hidden-fields">
        <input type="hidden" name="cod_guard_advance_amount" value="<?php echo esc_attr($advance_amount); ?>" />
        <input type="hidden" name="cod_guard_cod_amount" value="<?php echo esc_attr($cod_amount); ?>" />
        <input type="hidden" name="cod_guard_payment_mode" value="<?php echo esc_attr($payment_mode); ?>" />
        <input type="hidden" name="cod_guard_original_total" value="<?php echo esc_attr($total); ?>" />
    </div>
    
    <?php do_action('cod_guard_after_payment_breakdown', $breakdown); ?>
    
</div>

<?php
/**
 * Template hooks for developers
 * 
 * These hooks allow theme developers and other plugins to modify the payment breakdown display:
 * 
 * - cod_guard_before_payment_breakdown: Before the entire breakdown
 * - cod_guard_after_payment_breakdown: After the entire breakdown
 * - cod_guard_breakdown_title: Filter the breakdown title text
 * - cod_guard_show_detailed_breakdown: Show/hide detailed order breakdown
 * - cod_guard_show_payment_icons: Show/hide icons in the breakdown
 * - cod_guard_show_payment_summary: Show/hide the visual payment summary
 * - cod_guard_show_security_notice: Show/hide the security explanation
 * - cod_guard_table_style: Change table styling (default, minimal, detailed)
 */
?>

<style>
/* Template-specific inline styles for better compatibility */
.cod-guard-breakdown-header {
    margin-bottom: 15px;
}

.cod-guard-breakdown-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 8px 0;
    font-size: 1.1em;
    color: #495057;
}

.cod-guard-mode-badge {
    background: #e9ecef;
    color: #495057;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.8em;
    font-weight: 600;
    margin-left: auto;
}

.cod-guard-mode-percentage {
    background: #d4edda;
    color: #155724;
}

.cod-guard-mode-shipping {
    background: #d1ecf1;
    color: #0c5460;
}

.cod-guard-mode-fixed {
    background: #fff3cd;
    color: #856404;
}

.cod-guard-breakdown-description {
    color: #6c757d;
    font-size: 0.9em;
    line-height: 1.4;
    margin: 0;
}

.cod-guard-section-header td {
    padding: 12px 0 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.cod-guard-section-title {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #495057;
    font-size: 0.95em;
}

.cod-guard-divider {
    border-top: 2px solid #dee2e6;
}

.cod-guard-highlight-badge {
    background: #ffc107;
    color: #212529;
    padding: 1px 6px;
    border-radius: 8px;
    font-size: 0.7em;
    font-weight: 600;
    margin-left: 8px;
}

.cod-guard-payment-split td {
    padding-top: 15px;
}

.cod-guard-highlight-advance {
    background: linear-gradient(90deg, #e8f5e8 0%, #ffffff 100%);
}

.cod-guard-highlight-cod {
    background: linear-gradient(90deg, #fff3cd 0%, #ffffff 100%);
}

.cod-guard-payment-summary {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin: 20px 0;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 6px;
    font-size: 0.9em;
}

.cod-guard-summary-item {
    text-align: center;
    flex: 1;
}

.cod-guard-summary-label {
    display: block;
    color: #6c757d;
    font-size: 0.8em;
    margin-bottom: 4px;
}

.cod-guard-summary-amount {
    display: block;
    font-weight: 600;
    color: #495057;
}

.cod-guard-summary-divider {
    color: #adb5bd;
    font-weight: bold;
    font-size: 1.2em;
}

.cod-guard-security-notice {
    background: #f0f6fc;
    border: 1px solid #c9d6df;
    border-radius: 6px;
    padding: 12px;
    margin-top: 15px;
}

.cod-guard-notice-content {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.cod-guard-security-icon {
    font-size: 1.2em;
    flex-shrink: 0;
}

.cod-guard-notice-text strong {
    color: #495057;
    display: block;
    margin-bottom: 4px;
}

.cod-guard-notice-text p {
    color: #6c757d;
    font-size: 0.85em;
    line-height: 1.3;
    margin: 0;
}

.cod-guard-hidden-fields {
    display: none;
}

/* Responsive adjustments */
@media (max-width: 600px) {
    .cod-guard-payment-summary {
        flex-direction: column;
        gap: 8px;
    }
    
    .cod-guard-summary-divider {
        transform: rotate(90deg);
    }
    
    .cod-guard-notice-content {
        flex-direction: column;
        gap: 8px;
    }
    
    .cod-guard-breakdown-title {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }
    
    .cod-guard-mode-badge {
        margin-left: 0;
    }
}
</style>