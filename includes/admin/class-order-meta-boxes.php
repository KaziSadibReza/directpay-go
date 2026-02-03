<?php
/**
 * DirectPay Go - Order Meta Boxes
 * 
 * Handles admin order page meta boxes display
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Go_Order_Meta_Boxes {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_filter('woocommerce_hidden_order_itemmeta', [__CLASS__, 'hide_shipping_item_meta']);
    }
    
    /**
     * Add custom meta boxes to order page
     */
    public static function add_meta_boxes($post_type, $post_or_order) {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';
        
        add_meta_box(
            'directpay_order_reference',
            __('DirectPay Reference', 'directpay-go'),
            [__CLASS__, 'render_reference_meta_box'],
            $screen,
            'side',
            'high'
        );
        
        add_meta_box(
            'directpay_shipping_details',
            __('Shipping Details', 'directpay-go'),
            [__CLASS__, 'render_shipping_meta_box'],
            $screen,
            'normal',
            'default'
        );
        
        add_meta_box(
            'directpay_session_info',
            __('Session Information', 'directpay-go'),
            [__CLASS__, 'render_session_meta_box'],
            $screen,
            'normal',
            'default'
        );
    }
    
    /**
     * Render reference meta box
     */
    public static function render_reference_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;
        
        $reference = $order->get_meta('_custom_reference');
        $amount = $order->get_meta('_custom_amount');
        
        if (!$reference) return;
        
        include DIRECTPAY_GO_PLUGIN_DIR . 'includes/admin/templates/meta-box-reference.php';
    }
    
    /**
     * Render shipping meta box
     */
    public static function render_shipping_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;
        
        $pickup_name = $order->get_meta('_pickup_point_name');
        $pickup_address = $order->get_meta('_pickup_point_address');
        $pickup_city = $order->get_meta('_pickup_point_city');
        $pickup_zipcode = $order->get_meta('_pickup_point_zipcode');
        $pickup_carrier = $order->get_meta('_pickup_point_carrier');
        $shipping_paid = $order->get_meta('_directpay_shipping_paid');
        
        if (!$pickup_name) return;
        
        $carrier_name = $pickup_carrier === 'mondial_relay' ? 'Mondial Relay' : 'Chronopost';
        
        // Get delivery type from shipping item meta
        $delivery_type = 'Normal';
        foreach ($order->get_items('shipping') as $item) {
            $item_delivery_type = $item->get_meta('delivery_type');
            if ($item_delivery_type) {
                $delivery_type = ucfirst($item_delivery_type);
                break;
            }
        }
        
        include DIRECTPAY_GO_PLUGIN_DIR . 'includes/admin/templates/meta-box-shipping.php';
    }
    
    /**
     * Render session meta box
     */
    public static function render_session_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;
        
        $session_id = $order->get_meta('_directpay_session_id');
        $order_number = $order->get_meta('_directpay_session_order_number');
        $first_order_id = $order->get_meta('_directpay_first_order_id');
        
        if (!$session_id) return;
        
        // Get session data
        $session_data = get_transient('directpay_session_' . $session_id);
        $session_active = $session_data !== false;
        
        // Get all orders in this session
        $session_orders = wc_get_orders([
            'limit' => -1,
            'meta_key' => '_directpay_session_id',
            'meta_value' => $session_id,
            'orderby' => 'date',
            'order' => 'ASC'
        ]);
        
        $total_orders = count($session_orders);
        $completed_orders = 0;
        
        foreach ($session_orders as $sess_order) {
            if ($sess_order->get_status() === 'completed') {
                $completed_orders++;
            }
        }
        
        include DIRECTPAY_GO_PLUGIN_DIR . 'includes/admin/templates/meta-box-session.php';
    }
    
    /**
     * Hide shipping item meta fields from order items table
     */
    public static function hide_shipping_item_meta($hidden_meta) {
        $hidden_meta[] = 'provider';
        $hidden_meta[] = 'delivery_type';
        $hidden_meta[] = 'pickup_point_id';
        $hidden_meta[] = 'pickup_point_name';
        $hidden_meta[] = 'pickup_point_address';
        $hidden_meta[] = 'pickup_point_city';
        $hidden_meta[] = 'pickup_point_postal_code';
        return $hidden_meta;
    }
    
    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders')) {
            return;
        }
        
        wp_enqueue_style(
            'directpay-admin-order',
            DIRECTPAY_GO_PLUGIN_URL . 'assets/css/admin-order.css',
            [],
            DIRECTPAY_GO_VERSION
        );
    }
}
