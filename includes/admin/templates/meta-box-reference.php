<?php
/**
 * Template: DirectPay Reference Meta Box
 * 
 * @var WC_Order $order
 * @var string $reference
 * @var float $amount
 */

if (!defined('ABSPATH')) exit;
?>

<div class="directpay-meta-box">
    <div class="directpay-field">
        <label><?php _e('Reference Number', 'directpay-go'); ?></label>
        <div class="directpay-value reference-value"><?php echo esc_html($reference); ?></div>
    </div>
    <?php if ($amount): ?>
    <div class="directpay-field">
        <label><?php _e('Custom Amount', 'directpay-go'); ?></label>
        <div class="directpay-value"><?php echo wc_price($amount); ?></div>
    </div>
    <?php endif; ?>
</div>
