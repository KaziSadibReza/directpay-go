<?php
/**
 * Order creation and management for DirectPay Go
 * 
 * Handles the creation of custom orders with manual amounts
 */

if (!defined('ABSPATH')) {
    exit;
}

class DirectPay_Go_Order {
    
    /**
     * Create a custom order
     * 
     * @param array $data Order data from API
     * @return WC_Order|WP_Error
     */
    public function create_custom_order($data) {
        try {
            // Extract data
            $reference = sanitize_text_field($data['reference']);
            $amount = floatval($data['amount']);
            $customer = $data['customer'];
            $shipping_method = $data['shipping_method'] ?? null;
            $payment_method = sanitize_text_field($data['payment_method']);
            $locale = $data['locale'] ?? get_locale();
            $payment_intent_id = $data['payment_intent_id'] ?? null;
            $pickup_point = $data['pickup_point'] ?? null;
            $shipping_cost = isset($data['shipping_cost']) ? floatval($data['shipping_cost']) : 0;
            $has_active_session = $data['has_active_session'] ?? false;
            $session_id = $data['session_id'] ?? null;
            $save_payment_method = $data['save_payment_method'] ?? false;
            
            // Validate amount
            if ($amount <= 0) {
                return new WP_Error(
                    'invalid_amount',
                    __('Amount must be greater than zero', 'directpay-go')
                );
            }
            
            // Get customer ID (use current user if logged in, otherwise guest)
            $customer_id = get_current_user_id();
            
            // Create order
            $order = wc_create_order([
                'status' => 'pending',
                'customer_id' => $customer_id, // Use actual user ID if logged in
                'created_via' => 'directpay_go',
            ]);
            
            if (is_wp_error($order)) {
                return $order;
            }
            
            // Set customer email
            $order->set_billing_email(sanitize_email($customer['email']));
            
            // Set billing address
            $order->set_address([
                'first_name' => sanitize_text_field($customer['first_name']),
                'last_name' => sanitize_text_field($customer['last_name']),
                'email' => sanitize_email($customer['email']),
                'address_1' => sanitize_text_field($customer['address_1'] ?? ''),
                'city' => sanitize_text_field($customer['city'] ?? ''),
                'postcode' => sanitize_text_field($customer['postcode'] ?? ''),
                'country' => sanitize_text_field($customer['country'] ?? 'FR'),
                'phone' => sanitize_text_field($customer['phone'] ?? ''),
            ], 'billing');
            
            // Set shipping address (same as billing)
            $order->set_address([
                'first_name' => sanitize_text_field($customer['first_name']),
                'last_name' => sanitize_text_field($customer['last_name']),
                'address_1' => sanitize_text_field($customer['address_1'] ?? ''),
                'city' => sanitize_text_field($customer['city'] ?? ''),
                'postcode' => sanitize_text_field($customer['postcode'] ?? ''),
                'country' => sanitize_text_field($customer['country'] ?? 'FR'),
            ], 'shipping');
            
            // Set payment method and title
            $order->set_payment_method($payment_method);
            
            // Get payment gateway title
            $payment_gateways = WC()->payment_gateways->payment_gateways();
            if (isset($payment_gateways[$payment_method])) {
                $order->set_payment_method_title($payment_gateways[$payment_method]->get_title());
            } else {
                // Fallback title if gateway not found
                $payment_titles = [
                    'stripe' => 'Credit Card (Stripe)',
                    'cod' => 'Cash on Delivery',
                    'bacs' => 'Bank Transfer',
                    'cheque' => 'Check Payment',
                ];
                $title = $payment_titles[$payment_method] ?? ucfirst($payment_method);
                $order->set_payment_method_title($title);
            }
            
            // Add custom amount as a fee
            $this->add_custom_amount_to_order($order, $amount, $reference);
            
            // Add shipping if provided
            if ($shipping_method && $shipping_cost > 0) {
                $this->add_shipping_to_order($order, $shipping_method, $shipping_cost, $pickup_point);
            }
            
            // Save custom meta data
            $order->update_meta_data('_custom_reference', $reference);
            $order->update_meta_data('_custom_amount', $amount);
            $order->update_meta_data('_directpay_order', 'yes');
            $order->update_meta_data('_order_locale', $locale);
            
            // Save pickup point data if provided
            if ($pickup_point && is_array($pickup_point)) {
                $order->update_meta_data('_pickup_point_id', sanitize_text_field($pickup_point['id']));
                $order->update_meta_data('_pickup_point_name', sanitize_text_field($pickup_point['name']));
                $order->update_meta_data('_pickup_point_address', sanitize_text_field($pickup_point['address']));
                $order->update_meta_data('_pickup_point_city', sanitize_text_field($pickup_point['city']));
                $order->update_meta_data('_pickup_point_zipcode', sanitize_text_field($pickup_point['zipCode']));
                $order->update_meta_data('_pickup_point_carrier', sanitize_text_field($pickup_point['carrier']));
                
                // Add order note about pickup point
                $order->add_order_note(
                    sprintf(
                        __('Pickup Point: %s - %s, %s %s (%s)', 'directpay-go'),
                        $pickup_point['name'],
                        $pickup_point['address'],
                        $pickup_point['zipCode'],
                        $pickup_point['city'],
                        $pickup_point['carrier'] === 'mondial_relay' ? 'Mondial Relay' : 'Chronopost'
                    )
                );
            }
            
            // Save Stripe payment intent ID if provided
            if ($payment_intent_id) {
                $order->update_meta_data('_stripe_intent_id', $payment_intent_id);
                $order->update_meta_data('_transaction_id', $payment_intent_id);
            }
            
            // Save payment method flag for tokenization (if user is logged in)
            if ($customer_id > 0 && $save_payment_method) {
                $order->update_meta_data('_wc_' . $payment_method . '_new_payment_method', 'true');
                $order->add_order_note(__('Customer chose to save payment method', 'directpay-go'));
            }
            
            // Handle shipping session
            if ($has_active_session && $session_id) {
                // Add to existing session
                $session_data = get_transient('directpay_session_' . $session_id);
                if ($session_data) {
                    $order->update_meta_data('_directpay_session_id', $session_id);
                    $order->update_meta_data('_directpay_session_order_number', ($session_data['order_count'] ?? 0) + 1);
                    $order->update_meta_data('_directpay_shipping_paid', 'no');
                    $order->update_meta_data('_directpay_first_order_id', $session_data['first_order_id'] ?? '');
                    
                    // Update session data
                    $session_data['order_count'] = ($session_data['order_count'] ?? 0) + 1;
                    $session_data['total_saved'] = ($session_data['total_saved'] ?? 0) + $shipping_cost;
                    $session_data['last_order_id'] = $order->get_id();
                    $session_data['last_order_time'] = time();
                    
                    // Update transient
                    $remaining_time = $session_data['start_time'] + (get_option('directpay_shipping_session_hours', 5) * HOUR_IN_SECONDS) - time();
                    set_transient('directpay_session_' . $session_id, $session_data, $remaining_time);
                    
                    $order->add_order_note(
                        sprintf(
                            __('Free shipping applied (Session Order #%d)', 'directpay-go'),
                            $session_data['order_count']
                        )
                    );
                }
            } else if ($shipping_method) {
                // First order - create new session
                $order->update_meta_data('_directpay_shipping_paid', 'yes');
            }
            
            // Calculate totals AFTER adding items
            $order->calculate_totals();
            
            // Set status based on payment method and payment intent
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
            
            if ($payment_method === 'stripe' && $payment_intent_id) {
                // Stripe payment confirmed
                $order->set_status('processing', __('Payment confirmed via Stripe', 'directpay-go'));
                $order->payment_complete($payment_intent_id);
            } elseif (isset($available_gateways[$payment_method]) && $available_gateways[$payment_method]->id === 'cod') {
                // COD
                $order->set_status('processing', __('Payment via COD', 'directpay-go'));
            } else {
                // Other payment methods
                $order->set_status('pending', __('Awaiting payment confirmation', 'directpay-go'));
            }
            
            $order->save();
            
            // Add order note
            $order->add_order_note(
                sprintf(
                    __('DirectPay Go order created. Reference: %s', 'directpay-go'),
                    $reference
                )
            );
            
            // Handle shipping session after order is saved
            if ($has_active_session && $session_id) {
                // Already handled above in session section
            } else if ($shipping_method && get_option('directpay_shipping_session_enabled', 'yes') === 'yes') {
                // First order - create new session
                $customer_identifier = $order->get_billing_email();
                if (empty($customer_identifier) && $order->get_customer_id()) {
                    $customer_identifier = 'user_' . $order->get_customer_id();
                }
                
                if (!empty($customer_identifier)) {
                    // Generate unique session ID
                    $new_session_id = 'sess_' . wp_generate_password(32, false);
                    
                    $session_data = [
                        'start_time' => time(),
                        'first_order_id' => $order->get_id(),
                        'customer_identifier' => $customer_identifier,
                        'order_count' => 1,
                        'total_saved' => 0
                    ];
                    
                    // Store in transient
                    $duration = absint(get_option('directpay_shipping_session_hours', 5)) * HOUR_IN_SECONDS;
                    set_transient('directpay_session_' . $new_session_id, $session_data, $duration);
                    
                    // Set cookie
                    $expire = time() + $duration;
                    setcookie(
                        'directpay_shipping_session',
                        $new_session_id,
                        $expire,
                        COOKIEPATH ? COOKIEPATH : '/',
                        COOKIE_DOMAIN,
                        is_ssl(),
                        true // httponly
                    );
                    
                    // Update order meta
                    $order->update_meta_data('_directpay_session_id', $new_session_id);
                    $order->update_meta_data('_directpay_session_order_number', 1);
                    $order->add_order_note(
                        sprintf(
                            __('New shipping session created. Free shipping on next orders for %s hours.', 'directpay-go'),
                            get_option('directpay_shipping_session_hours', 5)
                        )
                    );
                    $order->save();
                }
            }
            
            // Trigger order created action
            do_action('directpay_go_order_created', $order, $data);
            
            return $order;
            
        } catch (Exception $e) {
            return new WP_Error(
                'order_creation_exception',
                $e->getMessage()
            );
        }
    }
    
    /**
     * Add custom amount to order
     * 
     * Options:
     * 1. As a fee (simple, fast)
     * 2. As a virtual product (better for reporting)
     */
    private function add_custom_amount_to_order($order, $amount, $reference) {
        // Add as a fee with proper WooCommerce structure
        $fee = new WC_Order_Item_Fee();
        $fee->set_name(sprintf(__('Payment - Ref: %s', 'directpay-go'), $reference));
        $fee->set_amount($amount);
        $fee->set_total($amount);
        $fee->set_tax_status('none');
        $fee->set_tax_class('');
        
        $order->add_item($fee);
        
        // Option 2: Add as a virtual product (commented out)
        // Uncomment this if you prefer to use a hidden virtual product
        /*
        $virtual_product_id = $this->get_or_create_virtual_product();
        if ($virtual_product_id) {
            $order->add_product(
                wc_get_product($virtual_product_id),
                1,
                [
                    'subtotal' => $amount,
                    'total' => $amount,
                ]
            );
        }
        */
    }
    
    /**
     * Add shipping to order with cost
     * 
     * @param WC_Order $order Order object
     * @param string $shipping_method_id Shipping method ID
     * @param float $shipping_cost Shipping cost
     * @param array $pickup_point Pickup point data
     */
    private function add_shipping_to_order($order, $shipping_method_id, $shipping_cost, $pickup_point = null) {
        try {
            // Parse shipping method ID (format: directpay_shipping_chronopost_express)
            $parts = explode('_', $shipping_method_id);
            $provider = $parts[2] ?? 'directpay';
            $delivery_type = $parts[3] ?? 'normal';
            
            // Format provider name
            $provider_name = ucfirst(str_replace('_', ' ', $provider));
            $delivery_label = ucfirst($delivery_type);
            
            // Create shipping line item
            $item = new WC_Order_Item_Shipping();
            $item->set_method_title("$provider_name - $delivery_label Delivery");
            $item->set_method_id('directpay_shipping');
            $item->set_total($shipping_cost);
            
            // Add shipping meta data
            if ($pickup_point) {
                $item->add_meta_data('provider', $provider);
                $item->add_meta_data('delivery_type', $delivery_type);
                $item->add_meta_data('pickup_point_id', $pickup_point['id'] ?? '');
                $item->add_meta_data('pickup_point_name', $pickup_point['name'] ?? '');
                $item->add_meta_data('pickup_point_address', $pickup_point['address'] ?? '');
                $item->add_meta_data('pickup_point_city', $pickup_point['city'] ?? '');
                $item->add_meta_data('pickup_point_postal_code', $pickup_point['postalCode'] ?? '');
            }
            
            $order->add_item($item);
            $order->set_shipping_total($shipping_cost);
            
            // Add order note
            if ($pickup_point) {
                $order->add_order_note(
                    sprintf(
                        __('Shipping: %s - %s (€%s) - Pickup: %s, %s %s', 'directpay-go'),
                        $provider_name,
                        $delivery_label,
                        number_format($shipping_cost, 2),
                        $pickup_point['name'] ?? '',
                        $pickup_point['postalCode'] ?? '',
                        $pickup_point['city'] ?? ''
                    )
                );
            }
            
        } catch (Exception $e) {
            error_log('DirectPay Go: Failed to add shipping to order - ' . $e->getMessage());
        }
    }
    
    /**
     * Set shipping method for order (DEPRECATED - use add_shipping_to_order instead)
     */
    private function set_shipping_method($order, $shipping_method_id) {
        try {
            // Initialize WooCommerce shipping if needed
            if (!WC()->shipping) {
                WC()->shipping();
            }
            
            // Calculate shipping for this order
            $packages = WC()->shipping()->get_packages();
            $shipping_cost = 0;
            $method_title = 'Shipping';
            
            // Find the selected shipping method in packages
            foreach ($packages as $package) {
                if (!empty($package['rates'])) {
                    foreach ($package['rates'] as $rate) {
                        if ($rate->id === $shipping_method_id) {
                            $shipping_cost = $rate->cost;
                            $method_title = $rate->label;
                            
                            // Create shipping line item
                            $item = new WC_Order_Item_Shipping();
                            $item->set_method_title($method_title);
                            $item->set_method_id($rate->method_id);
                            $item->set_instance_id($rate->instance_id);
                            $item->set_total($shipping_cost);
                            
                            $order->add_item($item);
                            return;
                        }
                    }
                }
            }
            
            // Fallback: Parse method ID and add basic shipping
            $parts = explode(':', $shipping_method_id);
            $method_id = $parts[0];
            $instance_id = $parts[1] ?? 0;
            
            $zones = WC_Shipping_Zones::get_zones();
            foreach ($zones as $zone_data) {
                foreach ($zone_data['shipping_methods'] as $method) {
                    if ($method->id === $method_id && $method->instance_id == $instance_id) {
                        $cost = floatval($method->get_option('cost', 0));
                        
                        $item = new WC_Order_Item_Shipping();
                        $item->set_method_title($method->get_title());
                        $item->set_method_id($method_id);
                        $item->set_instance_id($instance_id);
                        $item->set_total($cost);
                        
                        $order->add_item($item);
                        return;
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('DirectPay Go: Failed to set shipping method - ' . $e->getMessage());
        }
    }
    
    /**
     * Get or create a hidden virtual product for custom amounts
     * (Used if you prefer Option 2 in add_custom_amount_to_order)
     */
    private function get_or_create_virtual_product() {
        $product_id = get_option('directpay_go_virtual_product_id');
        
        // Check if product exists
        if ($product_id && get_post($product_id)) {
            return $product_id;
        }
        
        // Create virtual product
        $product = new WC_Product_Simple();
        $product->set_name(__('DirectPay Custom Amount', 'directpay-go'));
        $product->set_slug('directpay-custom-amount');
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->set_status('private');
        $product->set_price(0);
        $product->set_regular_price(0);
        
        $product_id = $product->save();
        
        if ($product_id) {
            update_option('directpay_go_virtual_product_id', $product_id);
        }
        
        return $product_id;
    }
    
    /**
     * Get order by custom reference
     */
    public function get_order_by_reference($reference) {
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => '_custom_reference',
            'meta_value' => sanitize_text_field($reference),
        ]);
        
        return !empty($orders) ? $orders[0] : null;
    }
    
    /**
     * Initialize admin hooks
     */
    public static function init_admin_hooks() {
        // Load admin classes
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/admin/class-order-meta-boxes.php';
        require_once DIRECTPAY_GO_PLUGIN_DIR . 'includes/admin/class-order-preview.php';
        
        // Initialize admin components
        DirectPay_Go_Order_Meta_Boxes::init();
        DirectPay_Go_Order_Preview::init();
    }
}
