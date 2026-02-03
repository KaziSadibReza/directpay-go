<?php
/**
 * DirectPay Go - Order Preview
 * 
 * Handles order preview modal in orders list
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Go_Order_Preview {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        add_filter('woocommerce_admin_order_preview_get_order_details', [__CLASS__, 'add_order_data'], 10, 2);
        add_action('woocommerce_admin_order_preview_end', [__CLASS__, 'render_template']);
    }
    
    /**
     * Add DirectPay data to order preview
     */
    public static function add_order_data($data, $order) {
        $pickup_name = $order->get_meta('_pickup_point_name');
        $pickup_address = $order->get_meta('_pickup_point_address');
        $pickup_city = $order->get_meta('_pickup_point_city');
        $pickup_zipcode = $order->get_meta('_pickup_point_zipcode');
        $pickup_carrier = $order->get_meta('_pickup_point_carrier');
        $reference = $order->get_meta('_custom_reference');
        
        // Add data to array
        $data['directpay_reference'] = $reference ?: '';
        $data['directpay_pickup_name'] = $pickup_name ?: '';
        $data['directpay_pickup_address'] = $pickup_address ?: '';
        $data['directpay_pickup_city'] = $pickup_city ?: '';
        $data['directpay_pickup_zipcode'] = $pickup_zipcode ?: '';
        $data['directpay_carrier'] = $pickup_carrier === 'mondial_relay' ? 'Mondial Relay' : 'Chronopost';
        
        // Get delivery type from shipping item meta
        $delivery_type = 'Normal';
        foreach ($order->get_items('shipping') as $item) {
            $item_delivery_type = $item->get_meta('delivery_type');
            if ($item_delivery_type) {
                $delivery_type = ucfirst($item_delivery_type);
                break;
            }
        }
        $data['directpay_delivery_type'] = $delivery_type;
        
        return $data;
    }
    
    /**
     * Render template in order preview
     */
    public static function render_template() {
        include DIRECTPAY_GO_PLUGIN_DIR . 'includes/admin/templates/order-preview.php';
    }
}
