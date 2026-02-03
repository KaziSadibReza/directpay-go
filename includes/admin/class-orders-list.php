<?php
/**
 * DirectPay Go - Orders List Table
 * 
 * Handles custom column in orders list
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Go_Orders_List {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        // Add custom column
        add_filter('manage_woocommerce_page_wc-orders_columns', [__CLASS__, 'add_column']);
        add_filter('manage_shop_order_posts_columns', [__CLASS__, 'add_column']);
        
        // Populate custom column
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'render_column'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_column_posts'], 10, 2);
        
        // Make column sortable
        add_filter('manage_edit-shop_order_sortable_columns', [__CLASS__, 'sortable_columns']);
        
        // Add row classes for visual grouping
        add_filter('post_class', [__CLASS__, 'add_row_class'], 10, 3);
        add_filter('woocommerce_admin_order_preview_get_order_details', [__CLASS__, 'add_row_class_hpos'], 10, 2);
        
        // Enqueue styles
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }
    
    /**
     * Add custom column to orders list
     */
    public static function add_column($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            // Add after order number column
            if ($key === 'order_number') {
                $new_columns['directpay'] = __('DirectPay', 'directpay-go');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Render column content (HPOS)
     */
    public static function render_column($column, $order) {
        if ($column !== 'directpay') {
            return;
        }
        
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return;
        }
        
        self::render_column_content($order);
    }
    
    /**
     * Render column content (Posts)
     */
    public static function render_column_posts($column, $post_id) {
        if ($column !== 'directpay') {
            return;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        
        self::render_column_content($order);
    }
    
    /**
     * Render column content
     */
    private static function render_column_content($order) {
        $is_directpay = $order->get_meta('_directpay_order') === 'yes';
        
        if (!$is_directpay) {
            echo '<span style="color: #cbd5e1;">—</span>';
            return;
        }
        
        $reference = $order->get_meta('_custom_reference');
        $session_id = $order->get_meta('_directpay_session_id');
        $order_number = $order->get_meta('_directpay_session_order_number');
        $customer_email = $order->get_billing_email();
        
        // Only show session indicator if there's actually a session
        $show_session = false;
        if ($session_id && $order_number) {
            // Check if there are other orders in this session
            $session_orders = wc_get_orders([
                'limit' => -1,
                'meta_key' => '_directpay_session_id',
                'meta_value' => $session_id,
                'return' => 'ids'
            ]);
            $show_session = count($session_orders) > 1;
        }
        
        ?>
        <div class="directpay-order-info">
            <span class="directpay-order-badge <?php echo $show_session ? 'has-session' : ''; ?>" title="<?php echo esc_attr(sprintf(__('DirectPay Order - %s', 'directpay-go'), $customer_email)); ?>">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                    <path d="M5 13l4 4L19 7"/>
                </svg>
                DP
            </span>
            
            <?php if ($show_session): ?>
            <span class="directpay-session-indicator <?php echo $order_number == 1 ? 'first-order' : ''; ?>" title="<?php echo $order_number == 1 ? __('First order - Session started', 'directpay-go') : sprintf(__('Session order #%d - Same customer', 'directpay-go'), $order_number); ?>">
                #<?php echo $order_number; ?>
            </span>
            <?php endif; ?>
            
            <?php if ($reference): ?>
            <span class="directpay-reference" title="<?php echo esc_attr(sprintf(__('Reference: %s', 'directpay-go'), $reference)); ?>"><?php echo esc_html($reference); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Make column sortable
     */
    public static function sortable_columns($columns) {
        $columns['directpay'] = 'directpay_order';
        return $columns;
    }
    
    /**
     * Add row class for visual grouping (Posts)
     */
    public static function add_row_class($classes, $class, $post_id) {
        if (get_post_type($post_id) !== 'shop_order') {
            return $classes;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return $classes;
        }
        
        $session_id = $order->get_meta('_directpay_session_id');
        $order_number = $order->get_meta('_directpay_session_order_number');
        
        // Only add visual indicators if part of a multi-order session
        if ($session_id) {
            $session_orders = wc_get_orders([
                'limit' => -1,
                'meta_key' => '_directpay_session_id',
                'meta_value' => $session_id,
                'return' => 'ids'
            ]);
            
            if (count($session_orders) > 1) {
                $classes[] = 'directpay-session-order';
                
                if ($order_number == 1) {
                    $classes[] = 'directpay-first-order';
                }
            }
        }
        
        return $classes;
    }
    
    /**
     * Add row class for HPOS
     */
    public static function add_row_class_hpos($data, $order) {
        $session_id = $order->get_meta('_directpay_session_id');
        $order_number = $order->get_meta('_directpay_session_order_number');
        
        if ($session_id) {
            $data['row_class'] = 'directpay-session-order';
            
            if ($order_number == 1) {
                $data['row_class'] .= ' directpay-first-order';
            }
        }
        
        return $data;
    }
    
    /**
     * Enqueue assets
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'woocommerce_page_wc-orders' && $hook !== 'edit.php') {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders' && $screen->id !== 'edit-shop_order')) {
            return;
        }
        
        wp_enqueue_style(
            'directpay-orders-list',
            DIRECTPAY_GO_PLUGIN_URL . 'assets/css/admin-orders-list.css',
            [],
            DIRECTPAY_GO_VERSION
        );
    }
}
