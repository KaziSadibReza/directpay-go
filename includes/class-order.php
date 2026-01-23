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
            
            // Validate amount
            if ($amount <= 0) {
                return new WP_Error(
                    'invalid_amount',
                    __('Amount must be greater than zero', 'directpay-go')
                );
            }
            
            // Create order
            $order = wc_create_order([
                'status' => 'pending',
                'customer_id' => 0, // Guest checkout
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
            
            // Set payment method first
            $order->set_payment_method($payment_method);
            
            // Add custom amount as a fee
            $this->add_custom_amount_to_order($order, $amount, $reference);
            
            // Set shipping method if provided
            if ($shipping_method) {
                $this->set_shipping_method($order, $shipping_method);
            }
            
            // Save custom meta data
            $order->update_meta_data('_custom_reference', $reference);
            $order->update_meta_data('_custom_amount', $amount);
            $order->update_meta_data('_directpay_order', 'yes');
            $order->update_meta_data('_order_locale', $locale);
            
            // Save Stripe payment intent ID if provided
            if ($payment_intent_id) {
                $order->update_meta_data('_stripe_intent_id', $payment_intent_id);
                $order->update_meta_data('_transaction_id', $payment_intent_id);
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
     * Set shipping method for order
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
}
