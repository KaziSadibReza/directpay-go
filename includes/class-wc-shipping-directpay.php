<?php
/**
 * DirectPay Shipping Method
 * Unified shipping method for Chronopost and Mondial Relay
 *
 * @package DirectPayGo
 */

if (!defined('ABSPATH')) {
    exit;
}

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    class WC_Shipping_DirectPay extends WC_Shipping_Method {
        
        /**
         * Constructor
         */
        public function __construct($instance_id = 0) {
            $this->id                 = 'directpay_shipping';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = __('DirectPay Shipping', 'directpay-go');
            $this->method_description = __('Pickup point delivery with Chronopost and Mondial Relay (Express and Normal options)', 'directpay-go');
            $this->supports           = array(
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );

            $this->init();
        }

        /**
         * Initialize settings
         */
        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->enabled = $this->get_option('enabled');

            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Initialize form fields
         */
        public function init_form_fields() {
            $this->instance_form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'directpay-go'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable DirectPay shipping', 'directpay-go'),
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => __('Title', 'directpay-go'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'directpay-go'),
                    'default'     => __('Pickup Point Delivery', 'directpay-go'),
                    'desc_tip'    => true,
                ),
            );
        }

        /**
         * Calculate shipping
         */
        public function calculate_shipping($package = array()) {
            // Get customer country
            $country = $package['destination']['country'];
            
            // Get pricing for both providers
            $chronopost_pricing = get_option('directpay_chronopost_pricing', array());
            $mondial_relay_pricing = get_option('directpay_mondial_relay_pricing', array());
            
            $rates_added = false;
            
            // Add Chronopost rates if available for this country
            if (!empty($chronopost_pricing) && isset($chronopost_pricing[$country])) {
                $pricing = $chronopost_pricing[$country];
                
                // Add Express delivery rate only if price is set
                if (!empty($pricing['express']) && $pricing['express'] > 0) {
                    $this->add_rate(array(
                        'id'       => $this->id . '_chronopost_express',
                        'label'    => $this->title . ' - Chronopost Express',
                        'cost'     => $pricing['express'],
                        'meta_data' => array(
                            'delivery_type' => 'express',
                            'shipping_method' => 'chronopost',
                            'provider' => 'Chronopost'
                        )
                    ));
                    $rates_added = true;
                }

                // Add Normal delivery rate only if price is set
                if (!empty($pricing['normal']) && $pricing['normal'] > 0) {
                    $this->add_rate(array(
                        'id'       => $this->id . '_chronopost_normal',
                        'label'    => $this->title . ' - Chronopost Normal',
                        'cost'     => $pricing['normal'],
                        'meta_data' => array(
                            'delivery_type' => 'normal',
                            'shipping_method' => 'chronopost',
                            'provider' => 'Chronopost'
                        )
                    ));
                    $rates_added = true;
                }
            }
            
            // Add Mondial Relay rates if available for this country
            if (!empty($mondial_relay_pricing) && isset($mondial_relay_pricing[$country])) {
                $pricing = $mondial_relay_pricing[$country];
                
                // Add Express delivery rate only if price is set
                if (!empty($pricing['express']) && $pricing['express'] > 0) {
                    $this->add_rate(array(
                        'id'       => $this->id . '_mondial_relay_express',
                        'label'    => $this->title . ' - Mondial Relay Express',
                        'cost'     => $pricing['express'],
                        'meta_data' => array(
                            'delivery_type' => 'express',
                            'shipping_method' => 'mondial_relay',
                            'provider' => 'Mondial Relay'
                        )
                    ));
                    $rates_added = true;
                }

                // Add Normal delivery rate only if price is set
                if (!empty($pricing['normal']) && $pricing['normal'] > 0) {
                    $this->add_rate(array(
                        'id'       => $this->id . '_mondial_relay_normal',
                        'label'    => $this->title . ' - Mondial Relay Normal',
                        'cost'     => $pricing['normal'],
                        'meta_data' => array(
                            'delivery_type' => 'normal',
                            'shipping_method' => 'mondial_relay',
                            'provider' => 'Mondial Relay'
                        )
                    ));
                    $rates_added = true;
                }
            }
        }
    }
}
