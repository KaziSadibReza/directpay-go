<?php
/**
 * Template: Shipping Details Meta Box
 * 
 * @var WC_Order $order
 * @var string $carrier_name
 * @var string $delivery_type
 * @var string $pickup_name
 * @var string $pickup_address
 * @var string $pickup_city
 * @var string $pickup_zipcode
 * @var string $shipping_paid
 */

if (!defined('ABSPATH')) exit;
?>

<div class="directpay-meta-box">
    <div class="directpay-shipping-header">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="color: #64748b;">
            <path d="M13 16V6C13 5.46957 13.2107 4.96086 13.5858 4.58579C13.9609 4.21071 14.4696 4 15 4H20C20.5304 4 21.0391 4.21071 21.4142 4.58579C21.7893 4.96086 22 5.46957 22 6V16M13 16H3M13 16L11 20H15M22 16H15M22 16L20 20H16M3 16V6C3 5.46957 3.21071 4.96086 3.58579 4.58579C3.96086 4.21071 4.46957 4 5 4H8C8.53043 4 9.03914 4.21071 9.41421 4.58579C9.78929 4.96086 10 5.46957 10 6V16M3 16L1 20H5M10 16H7M10 16L8 20H12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <div>
            <strong><?php echo esc_html($carrier_name); ?></strong>
            <span class="delivery-badge"><?php echo esc_html($delivery_type); ?></span>
        </div>
    </div>
    
    <div class="directpay-field-group">
        <div class="directpay-field">
            <label><?php _e('Pickup Point', 'directpay-go'); ?></label>
            <div class="directpay-value"><?php echo esc_html($pickup_name); ?></div>
        </div>
        
        <div class="directpay-field">
            <label><?php _e('Address', 'directpay-go'); ?></label>
            <div class="directpay-value">
                <?php echo esc_html($pickup_address); ?><br>
                <?php echo esc_html($pickup_zipcode . ' ' . $pickup_city); ?>
            </div>
        </div>
        
        <?php if ($shipping_paid): ?>
        <div class="directpay-field">
            <label><?php _e('Shipping Status', 'directpay-go'); ?></label>
            <div class="directpay-value">
                <span class="status-badge <?php echo $shipping_paid === 'yes' ? 'paid' : 'free'; ?>">
                    <?php echo $shipping_paid === 'yes' ? __('Paid', 'directpay-go') : __('Free (Session)', 'directpay-go'); ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
