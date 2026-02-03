<?php
/**
 * Template: Session Information Meta Box
 * 
 * @var WC_Order $order
 * @var string $session_id
 * @var int $order_number
 * @var int $first_order_id
 * @var array $session_data
 * @var bool $session_active
 * @var array $session_orders
 * @var int $total_orders
 * @var int $completed_orders
 */

if (!defined('ABSPATH')) exit;
?>

<div class="directpay-meta-box">
    <div class="directpay-session-header">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="color: #64748b;">
            <path d="M12 8V12L15 15M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <div>
            <strong><?php _e('Free Shipping Session', 'directpay-go'); ?></strong>
            <span class="session-status <?php echo $session_active ? 'active' : 'expired'; ?>">
                <?php echo $session_active ? __('Active', 'directpay-go') : __('Expired', 'directpay-go'); ?>
            </span>
        </div>
    </div>
    
    <div class="directpay-field-group">
        <div class="directpay-field">
            <label><?php _e('Session Order', 'directpay-go'); ?></label>
            <div class="directpay-value">
                <?php printf(__('Order #%d of %d', 'directpay-go'), $order_number, $total_orders); ?>
            </div>
        </div>
        
        <div class="directpay-field">
            <label><?php _e('Orders Status', 'directpay-go'); ?></label>
            <div class="directpay-value">
                <span class="orders-count"><?php echo $completed_orders; ?> / <?php echo $total_orders; ?></span>
                <span class="orders-label"><?php _e('Completed', 'directpay-go'); ?></span>
            </div>
        </div>
        
        <?php if ($session_data && isset($session_data['start_time'])): ?>
        <div class="directpay-field">
            <label><?php _e('Session Started', 'directpay-go'); ?></label>
            <div class="directpay-value">
                <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $session_data['start_time']); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($first_order_id && $first_order_id != $order->get_id()): ?>
        <div class="directpay-field">
            <label><?php _e('First Order', 'directpay-go'); ?></label>
            <div class="directpay-value">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&action=edit&id=' . $first_order_id)); ?>">
                    #<?php echo $first_order_id; ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="directpay-session-orders">
            <label><?php _e('All Session Orders', 'directpay-go'); ?></label>
            <div class="session-orders-list">
                <?php foreach ($session_orders as $sess_order): ?>
                <div class="session-order-item">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&action=edit&id=' . $sess_order->get_id())); ?>">
                        #<?php echo $sess_order->get_id(); ?>
                    </a>
                    <span class="order-status status-<?php echo $sess_order->get_status(); ?>">
                        <?php echo wc_get_order_status_name($sess_order->get_status()); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
