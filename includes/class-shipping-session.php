<?php
/**
 * Shipping Session Handler
 * 
 * Manages customer shopping sessions where shipping fee is charged only once
 * within a configurable time window. Multiple orders can be created in a session,
 * but only the first order pays for shipping.
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Shipping_Session {
    
    const COOKIE_NAME = 'directpay_shipping_session';
    const TRANSIENT_PREFIX = 'directpay_session_';
    const SESSION_META_KEY = '_directpay_session_id';
    const SESSION_ORDER_META_KEY = '_directpay_session_order_number';
    const SESSION_SHIPPING_PAID_META_KEY = '_directpay_shipping_paid';
    
    /**
     * Initialize session hooks
     */
    public static function init() {
        // Check session on checkout
        add_action('woocommerce_checkout_before_order_review', [__CLASS__, 'check_and_display_session_info']);
        
        // Modify shipping cost based on session
        add_filter('woocommerce_package_rates', [__CLASS__, 'modify_shipping_rates'], 100, 2);
        
        // Save session data to order meta
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_session_to_order'], 10, 2);
        
        // Create session after first order
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'create_session_after_order'], 10, 3);
        
        // REST API endpoint for session status
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        
        // Admin REST API endpoints
        add_action('rest_api_init', [__CLASS__, 'register_admin_rest_routes']);
    }
    
    /**
     * Get session duration in seconds from admin setting
     */
    public static function get_session_duration() {
        $hours = get_option('directpay_shipping_session_hours', 5);
        return absint($hours) * HOUR_IN_SECONDS;
    }
    
    /**
     * Get current active session data
     * 
     * @return array|null Session data or null if no active session
     */
    public static function get_active_session() {
        // Try to get session ID from cookie
        $session_id = isset($_COOKIE[self::COOKIE_NAME]) ? sanitize_text_field($_COOKIE[self::COOKIE_NAME]) : '';
        
        if (empty($session_id)) {
            return null;
        }
        
        // Get session data from transient
        $session_data = get_transient(self::TRANSIENT_PREFIX . $session_id);
        
        if (false === $session_data) {
            // Session expired or doesn't exist
            self::clear_session_cookie();
            return null;
        }
        
        // Validate session data
        if (!isset($session_data['start_time'], $session_data['customer_identifier'])) {
            return null;
        }
        
        // Check if session is still valid
        $elapsed_time = time() - $session_data['start_time'];
        $session_duration = self::get_session_duration();
        
        if ($elapsed_time > $session_duration) {
            // Session expired
            self::end_session($session_id);
            return null;
        }
        
        // Return active session with remaining time
        $session_data['session_id'] = $session_id;
        $session_data['remaining_seconds'] = $session_duration - $elapsed_time;
        $session_data['remaining_time_formatted'] = self::format_time_remaining($session_data['remaining_seconds']);
        
        return $session_data;
    }
    
    /**
     * Create a new shipping session
     * 
     * @param int $order_id First order ID in the session
     * @param string $customer_identifier Email or user ID
     * @return string Session ID
     */
    public static function create_session($order_id, $customer_identifier) {
        // Generate unique session ID
        $session_id = 'sess_' . wp_generate_password(32, false);
        
        $session_data = [
            'start_time' => time(),
            'first_order_id' => $order_id,
            'customer_identifier' => $customer_identifier,
            'order_count' => 1,
            'total_saved' => 0 // Track how much customer saved on shipping
        ];
        
        // Store in transient (expires based on admin setting)
        $duration = self::get_session_duration();
        set_transient(self::TRANSIENT_PREFIX . $session_id, $session_data, $duration);
        
        // Set cookie (expires with session duration)
        self::set_session_cookie($session_id, $duration);
        
        return $session_id;
    }
    
    /**
     * Add order to existing session
     * 
     * @param string $session_id Session ID
     * @param int $order_id Order ID to add
     * @param float $shipping_saved Amount saved on shipping
     */
    public static function add_order_to_session($session_id, $order_id, $shipping_saved = 0) {
        $session_data = get_transient(self::TRANSIENT_PREFIX . $session_id);
        
        if (false === $session_data) {
            return;
        }
        
        $session_data['order_count'] = isset($session_data['order_count']) ? $session_data['order_count'] + 1 : 2;
        $session_data['total_saved'] = isset($session_data['total_saved']) ? $session_data['total_saved'] + $shipping_saved : $shipping_saved;
        $session_data['last_order_id'] = $order_id;
        $session_data['last_order_time'] = time();
        
        // Update transient
        $remaining_time = $session_data['start_time'] + self::get_session_duration() - time();
        set_transient(self::TRANSIENT_PREFIX . $session_id, $session_data, $remaining_time);
    }
    
    /**
     * End a session
     * 
     * @param string $session_id Session ID to end
     */
    public static function end_session($session_id) {
        delete_transient(self::TRANSIENT_PREFIX . $session_id);
        self::clear_session_cookie();
    }
    
    /**
     * Set session cookie
     */
    private static function set_session_cookie($session_id, $duration) {
        $expire = time() + $duration;
        setcookie(
            self::COOKIE_NAME,
            $session_id,
            $expire,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true // httponly
        );
    }
    
    /**
     * Clear session cookie
     */
    private static function clear_session_cookie() {
        setcookie(
            self::COOKIE_NAME,
            '',
            time() - 3600,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
    }
    
    /**
     * Modify shipping rates based on active session
     * Free shipping for subsequent orders in a session
     */
    public static function modify_shipping_rates($rates, $package) {
        // Check if session system is enabled
        $session_enabled = get_option('directpay_shipping_session_enabled', 'yes');
        if ($session_enabled !== 'yes') {
            return $rates; // Session system disabled, return normal rates
        }
        
        $session = self::get_active_session();
        
        if (!$session) {
            return $rates; // No active session, normal shipping
        }
        
        // Customer is in an active session - free shipping for this order
        foreach ($rates as $rate_id => $rate) {
            if (strpos($rate_id, 'directpay_shipping') !== false) {
                // Store original cost for reference
                $original_cost = $rate->cost;
                
                // Set cost to 0
                $rate->cost = 0;
                
                // Update label to show it's free due to session
                $rate->label = $rate->label . ' (Free - Active Session)';
                
                // Store original cost in meta for tracking
                $rate->add_meta_data('original_cost', $original_cost);
                $rate->add_meta_data('session_discount', $original_cost);
            }
        }
        
        return $rates;
    }
    
    /**
     * Save session information to order meta
     */
    public static function save_session_to_order($order, $data) {
        $session = self::get_active_session();
        
        // Determine if this is first order (paying shipping) or subsequent order (free shipping)
        $is_first_order = !$session;
        
        if ($session) {
            // Existing session - this is a subsequent order
            $order->update_meta_data(self::SESSION_META_KEY, $session['session_id']);
            $order->update_meta_data(self::SESSION_ORDER_META_KEY, $session['order_count'] + 1);
            $order->update_meta_data(self::SESSION_SHIPPING_PAID_META_KEY, 'no');
            $order->update_meta_data('_directpay_first_order_id', $session['first_order_id']);
            
            // Add note about free shipping
            $order->add_order_note(
                sprintf(
                    __('Free shipping applied (Session #%d - Order %d of session)', 'directpay-go'),
                    $session['order_count'] + 1,
                    $session['order_count'] + 1
                )
            );
        } else {
            // No session yet - this will be the first order
            $order->update_meta_data(self::SESSION_SHIPPING_PAID_META_KEY, 'yes');
        }
    }
    
    /**
     * Create session after first order is processed
     */
    public static function create_session_after_order($order_id, $posted_data, $order) {
        $session = self::get_active_session();
        
        if ($session) {
            // Session already exists - add this order to it
            $shipping_items = $order->get_items('shipping');
            $shipping_saved = 0;
            
            foreach ($shipping_items as $item) {
                $original_cost = $item->get_meta('original_cost');
                if ($original_cost) {
                    $shipping_saved += floatval($original_cost);
                }
            }
            
            self::add_order_to_session($session['session_id'], $order_id, $shipping_saved);
            
            // Update order meta
            $order->update_meta_data(self::SESSION_META_KEY, $session['session_id']);
            $order->update_meta_data(self::SESSION_ORDER_META_KEY, $session['order_count'] + 1);
            $order->save();
            
        } else {
            // Create new session for this customer
            $customer_identifier = $order->get_billing_email();
            if (empty($customer_identifier) && $order->get_customer_id()) {
                $customer_identifier = 'user_' . $order->get_customer_id();
            }
            
            if (!empty($customer_identifier)) {
                $session_id = self::create_session($order_id, $customer_identifier);
                
                // Update order meta
                $order->update_meta_data(self::SESSION_META_KEY, $session_id);
                $order->update_meta_data(self::SESSION_ORDER_META_KEY, 1);
                $order->add_order_note(
                    sprintf(
                        __('New shipping session created. Free shipping on next orders for %s hours.', 'directpay-go'),
                        get_option('directpay_shipping_session_hours', 5)
                    )
                );
                $order->save();
            }
        }
    }
    
    /**
     * Display session info on checkout page
     */
    public static function check_and_display_session_info() {
        $session = self::get_active_session();
        
        if (!$session) {
            return;
        }
        
        // Display info box
        ?>
        <div class="woocommerce-info directpay-session-info" style="margin-bottom: 20px; padding: 15px; background: #e8f5e9; border-left: 4px solid #4caf50;">
            <strong>🎉 <?php _e('Free Shipping Active!', 'directpay-go'); ?></strong><br>
            <?php
            printf(
                __('You have <strong>%s</strong> remaining to enjoy free shipping on additional orders.', 'directpay-go'),
                esc_html($session['remaining_time_formatted'])
            );
            ?>
            <br>
            <small>
                <?php
                printf(
                    __('Session started with Order #%d', 'directpay-go'),
                    $session['first_order_id']
                );
                ?>
            </small>
        </div>
        <?php
    }
    
    /**
     * Format remaining time in human-readable format
     */
    private static function format_time_remaining($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($hours > 0) {
            return sprintf(__('%d hours %d minutes', 'directpay-go'), $hours, $minutes);
        } else {
            return sprintf(__('%d minutes', 'directpay-go'), $minutes);
        }
    }
    
    /**
     * Register REST API routes
     */
    public static function register_rest_routes() {
        register_rest_route('directpay/v1', '/shipping-session/status', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_session_status'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('directpay/v1', '/shipping-session/clear', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'clear_session'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * REST API: Get session status
     */
    public static function get_session_status() {
        $session = self::get_active_session();
        
        if (!$session) {
            return new WP_REST_Response([
                'active' => false,
                'message' => 'No active shipping session'
            ], 200);
        }
        
        return new WP_REST_Response([
            'active' => true,
            'session_id' => $session['session_id'],
            'first_order_id' => $session['first_order_id'],
            'order_count' => $session['order_count'],
            'remaining_seconds' => $session['remaining_seconds'],
            'remaining_formatted' => $session['remaining_time_formatted'],
            'total_saved' => isset($session['total_saved']) ? $session['total_saved'] : 0
        ], 200);
    }
    
    /**
     * REST API: Clear session manually
     */
    public static function clear_session() {
        $session = self::get_active_session();
        
        if ($session) {
            self::end_session($session['session_id']);
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Session cleared successfully'
            ], 200);
        }
        
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No active session to clear'
        ], 200);
    }
    
    /**
     * Get all orders in a session
     * 
     * @param string $session_id Session ID
     * @return array Array of order IDs
     */
    public static function get_session_orders($session_id) {
        $args = [
            'meta_key' => self::SESSION_META_KEY,
            'meta_value' => $session_id,
            'limit' => -1,
            'return' => 'ids'
        ];
        
        return wc_get_orders($args);
    }
    
    /**
     * Register admin REST API routes
     */
    public static function register_admin_rest_routes() {
        // Get all sessions
        register_rest_route('directpay/v1', '/admin/sessions', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_all_sessions'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Delete session
        register_rest_route('directpay/v1', '/admin/sessions/(?P<session_id>[a-zA-Z0-9_]+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_session_admin'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            },
            'args' => [
                'session_id' => [
                    'required' => true,
                    'type' => 'string'
                ]
            ]
        ]);
        
        // Update session settings
        register_rest_route('directpay/v1', '/admin/session-settings', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'update_session_settings'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            },
            'args' => [
                'enabled' => [
                    'required' => true,
                    'type' => 'boolean'
                ],
                'duration_hours' => [
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 48
                ]
            ]
        ]);
        
        // Get session settings
        register_rest_route('directpay/v1', '/admin/session-settings', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_session_settings'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
        
        // Get all DirectPay Go orders organized by sessions
        register_rest_route('directpay/v1', '/admin/directpay-orders', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_directpay_orders'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
    }
    
    /**
     * Get all active sessions
     */
    public static function get_all_sessions($request) {
        global $wpdb;
        
        $transient_prefix = '_transient_' . self::TRANSIENT_PREFIX;
        $sessions = [];
        
        // Query all session transients
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value 
            FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            ORDER BY option_id DESC",
            $wpdb->esc_like($transient_prefix) . '%'
        ));
        
        foreach ($results as $result) {
            $session_id = str_replace($transient_prefix, '', $result->option_name);
            $session_data = maybe_unserialize($result->option_value);
            
            if (!is_array($session_data)) {
                continue;
            }
            
            // Get customer info
            $customer_email = $session_data['customer_identifier'] ?? '';
            $customer_name = '';
            
            if (strpos($customer_email, 'user_') === 0) {
                $user_id = str_replace('user_', '', $customer_email);
                $user = get_user_by('id', $user_id);
                if ($user) {
                    $customer_name = $user->display_name;
                    $customer_email = $user->user_email;
                }
            } else {
                // Try to get name from first order
                if (!empty($session_data['first_order_id'])) {
                    $order = wc_get_order($session_data['first_order_id']);
                    if ($order) {
                        $customer_name = $order->get_formatted_billing_full_name();
                    }
                }
            }
            
            // Calculate remaining time
            $elapsed = time() - ($session_data['start_time'] ?? time());
            $duration = self::get_session_duration();
            $remaining = max(0, $duration - $elapsed);
            
            // Get all order IDs in session
            $order_ids = self::get_session_orders($session_id);
            
            $sessions[] = [
                'session_id' => $session_id,
                'customer_name' => $customer_name ?: 'Unknown',
                'customer_email' => $customer_email,
                'customer_id' => str_replace('user_', '', $session_data['customer_identifier'] ?? ''),
                'first_order_id' => $session_data['first_order_id'] ?? 0,
                'order_count' => $session_data['order_count'] ?? 0,
                'order_ids' => $order_ids,
                'total_saved' => $session_data['total_saved'] ?? 0,
                'start_time' => $session_data['start_time'] ?? 0,
                'remaining_seconds' => $remaining,
                'remaining_formatted' => self::format_time_remaining($remaining),
                'created_at' => date('Y-m-d H:i:s', $session_data['start_time'] ?? time())
            ];
        }
        
        return new WP_REST_Response([
            'success' => true,
            'sessions' => $sessions,
            'total' => count($sessions)
        ], 200);
    }
    
    /**
     * Delete session (admin)
     */
    public static function delete_session_admin($request) {
        $session_id = $request->get_param('session_id');
        
        // Delete transient
        $deleted = delete_transient(self::TRANSIENT_PREFIX . $session_id);
        
        if ($deleted) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Session deleted successfully'
            ], 200);
        }
        
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Session not found or already expired'
        ], 404);
    }
    
    /**
     * Update session settings
     */
    public static function update_session_settings($request) {
        $enabled = $request->get_param('enabled');
        $duration_hours = $request->get_param('duration_hours');
        
        update_option('directpay_shipping_session_enabled', $enabled ? 'yes' : 'no');
        update_option('directpay_shipping_session_hours', $duration_hours);
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Settings updated successfully'
        ], 200);
    }
    
    /**
     * Get session settings
     */
    public static function get_session_settings($request) {
        return new WP_REST_Response([
            'success' => true,
            'enabled' => get_option('directpay_shipping_session_enabled', 'yes') === 'yes',
            'duration_hours' => absint(get_option('directpay_shipping_session_hours', 5))
        ], 200);
    }
    
    /**
     * Get all DirectPay Go orders organized by customer sessions
     */
    public static function get_directpay_orders($request) {
        // Get all orders created by DirectPay Go
        $orders = wc_get_orders([
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_directpay_order',
            'meta_value' => 'yes',
        ]);
        
        $session_map = [];
        $no_session_orders = [];
        
        foreach ($orders as $order) {
            $session_id = $order->get_meta(self::SESSION_META_KEY);
            $customer_email = $order->get_billing_email();
            $customer_name = $order->get_formatted_billing_full_name();
            $paid_shipping = $order->get_meta(self::SESSION_SHIPPING_PAID_META_KEY) !== 'no';
            
            // Check if session is still active
            $session_active = false;
            if ($session_id) {
                $session_data = get_transient('directpay_session_' . $session_id);
                $session_active = $session_data !== false;
            }
            
            $order_data = [
                'order_id' => $order->get_id(),
                'date' => $order->get_date_created()->date('Y-m-d H:i:s'),
                'total' => html_entity_decode(strip_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8'),
                'status' => ucfirst($order->get_status()),
                'paid_shipping' => $paid_shipping,
            ];
            
            if ($session_id) {
                if (!isset($session_map[$session_id])) {
                    $session_map[$session_id] = [
                        'session_id' => $session_id,
                        'customer_name' => $customer_name ?: 'Guest',
                        'customer_email' => $customer_email,
                        'session_active' => $session_active,
                        'orders' => [],
                    ];
                }
                $session_map[$session_id]['orders'][] = $order_data;
            } else {
                $key = $customer_email ?: 'guest_' . $order->get_id();
                if (!isset($no_session_orders[$key])) {
                    $no_session_orders[$key] = [
                        'session_id' => null,
                        'customer_name' => $customer_name ?: 'Guest',
                        'customer_email' => $customer_email,
                        'session_active' => false,
                        'orders' => [],
                    ];
                }
                $no_session_orders[$key]['orders'][] = $order_data;
            }
        }
        
        $organized_data = array_merge(array_values($session_map), array_values($no_session_orders));
        
        // Sort orders within each session by date (oldest first)
        foreach ($organized_data as &$session) {
            usort($session['orders'], function($a, $b) {
                return strtotime($a['date']) - strtotime($b['date']);
            });
        }
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $organized_data,
            'total_orders' => count($orders),
        ], 200);
    }
}

// Initialize
DirectPay_Shipping_Session::init();
