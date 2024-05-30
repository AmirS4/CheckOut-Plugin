<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function custom_free_shipping_method_init()
{
    if (!class_exists('WC_Custom_Free_Shipping_Method')) {
        class WC_Custom_Free_Shipping_Method extends WC_Shipping_Method
        {
            public function __construct()
            {
                $this->id = 'custom_free_shipping';
                $this->method_title = __('Custom Free Shipping', 'checkout-plugin');
                $this->method_description = __('Custom Free Shipping Method', 'checkout-plugin');
                $this->enabled = "no";
                $this->title = __('Free Shipping', 'checkout-plugin');
                $this->init();
            }

            function init()
            {
                $this->init_form_fields();
                $this->init_settings();

                $this->enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : 'no';
                $this->title = isset($this->settings['title']) ? $this->settings['title'] : __('Free Shipping', 'checkout-plugin');
            }

            public function calculate_shipping($package = array())
            {
                $rate = array(
                    'id' => $this->id,
                    'label' => $this->title,
                    'cost' => 0,
                    'calc_tax' => 'per_order'
                );

                $this->add_rate($rate);
            }
        }
    }
}

add_action('woocommerce_shipping_init', 'custom_free_shipping_method_init');

function add_custom_free_shipping_method($methods)
{
    $methods['custom_free_shipping'] = 'WC_Custom_Free_Shipping_Method';
    return $methods;
}

add_filter('woocommerce_shipping_methods', 'add_custom_free_shipping_method');

function adjust_shipping_methods_based_on_reserve_option($rates, $package)
{
    $customer_id = get_current_user_id();
    $add_to_reserve_order = get_user_meta($customer_id, 'add_to_reserve_order', true) === '1';
    $customer_has_reserve_order = customer_has_reserve_order($customer_id);

    if ($add_to_reserve_order && $customer_has_reserve_order) {
        $custom_free_shipping_found = false;
        foreach ($rates as $rate_id => $rate) {
            if ($rate->method_id !== 'custom_free_shipping') {
                unset($rates[$rate_id]);
            } else {
                $rates[$rate_id]->cost = 0;
                $custom_free_shipping_found = true;
            }
        }
        if (!$custom_free_shipping_found) {
            $rates['custom_free_shipping'] = new WC_Shipping_Rate('custom_free_shipping', __('Free Shipping', 'checkout-plugin'), 0, array(), 'custom_free_shipping');
        }
    } else {
        foreach ($rates as $rate_id => $rate) {
            if ($rate->method_id === 'custom_free_shipping') {
                unset($rates[$rate_id]);
            }
        }
    }
    return $rates;
}

add_filter('woocommerce_package_rates', 'adjust_shipping_methods_based_on_reserve_option', 10, 2);

function customer_has_reserve_order($customer_id)
{
    $reserved_orders = wc_get_orders(array(
        'status' => 'wc-reserve',
        'customer_id' => $customer_id,
        'limit' => 1,
    ));
    return !empty($reserved_orders);
}

class CheckOutReserveOption
{
    public function __construct()
    {
        add_action('woocommerce_review_order_before_submit', array($this, 'add_reserve_option'), 10);
        add_action('woocommerce_checkout_create_order', array($this, 'handle_reserve_order_logic'), 20, 2);
        add_action('woocommerce_payment_complete', array($this, 'after_payment_processing'), 10, 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_toggle_reserve_shipping', array($this, 'toggle_reserve_shipping'));
        add_action('wp_ajax_nopriv_toggle_reserve_shipping', array($this, 'toggle_reserve_shipping'));
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('checkout-reserve-option', plugin_dir_url(__FILE__) . 'assets/js/checkout-reserve-option.js', array('jquery'), '1.0', true);
        wp_localize_script('checkout-reserve-option', 'checkoutReserveOption', array(
            'ajax_url' => admin_url('admin-ajax.php'),
        ));
    }

    public function toggle_reserve_shipping()
    {
        $customer_id = get_current_user_id();
        $has_reserve_order = $this->customer_has_reserve_order($customer_id);

        if (isset($_POST['reserve']) && $has_reserve_order) {
            update_user_meta($customer_id, 'add_to_reserve_order', $_POST['reserve'] === '1' ? '1' : '0');
            wp_send_json_success();
        } else {
            delete_user_meta($customer_id, 'add_to_reserve_order');
            wp_send_json_error();
        }
    }

    public function after_payment_processing($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order->get_meta('add_to_reserve_order')) {
            $customer_id = $order->get_customer_id();
            $this->merge_with_reserve_order($customer_id, $order);
            $reserve_order_url = $this->get_reserve_order_url($customer_id);
            if (!is_admin()) {
                wp_redirect($reserve_order_url);
                exit;
            }
        } elseif ($order->get_meta('reserve_order')) {
            $order->update_meta_data('reserved_at', current_time('mysql'));
            $order->set_status('wc-reserve', __('Order reserved by customer.', 'checkout-plugin'));
            $order->save();
        }
    }

    private function get_reserve_order_url($customer_id)
    {
        $reserved_orders = wc_get_orders(array(
            'status' => 'wc-reserve',
            'customer_id' => $customer_id,
            'limit' => 1,
        ));
        if (!empty($reserved_orders)) {
            return $reserved_orders[0]->get_checkout_order_received_url();
        }
        return wc_get_page_permalink('myaccount');
    }

    private function merge_with_reserve_order($customer_id, $new_order)
    {
        $reserved_orders = wc_get_orders(array(
            'status' => 'wc-reserve',
            'customer_id' => $customer_id,
        ));
        if (!empty($reserved_orders)) {
            $reserve_order = $reserved_orders[0];

            foreach ($new_order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
                $quantity = $item->get_quantity();
                $product = wc_get_product($product_id);
                $total = $item->get_total();
                $subtotal = $item->get_subtotal();
                $new_item = new WC_Order_Item_Product();
                $new_item->set_product($product);
                $new_item->set_quantity($quantity);
                $new_item->set_subtotal($subtotal / $quantity);
                $new_item->set_total($total / $quantity);
                $new_item->set_subtotal_tax($item->get_subtotal_tax() / $quantity);
                $new_item->set_total_tax($item->get_total_tax() / $quantity);

                $meta_data = $item->get_meta_data();
                foreach ($meta_data as $meta) {
                    $new_item->add_meta_data($meta->key, $meta->value, true);
                }

                $reserve_order->add_item($new_item);
            }

            $reserve_order->add_order_note(sprintf(
                __('Items from order #%s added.', 'checkout-plugin'),
                $new_order->get_id()
            ));
            $reserve_order->calculate_totals();
            $reserve_order->save();

            $new_order->update_status('trash');
            $new_order->delete(true);
        }
    }

    public function handle_reserve_order_logic($order, $data)
    {
        $order->update_meta_data('add_to_reserve_order', isset($_POST['add_to_reserve_order']) && $_POST['add_to_reserve_order'] === '1');
        $order->update_meta_data('reserve_order', isset($_POST['reserve_order']) && $_POST['reserve_order'] === '1');
        $order->save_meta_data();
    }

    public function add_reserve_option()
    {
        $customer_id = get_current_user_id();
        $has_reserve_order = $this->customer_has_reserve_order($customer_id);

        if ($has_reserve_order) {
            woocommerce_form_field('add_to_reserve_order', array(
                'type' => 'checkbox',
                'class' => array('input-checkbox'),
                'label' => __('Add to my existing Reserve order', 'checkout-plugin'),
                'default' => '1',
                'checked' => 'checked'

            ));
        } else {
            woocommerce_form_field('reserve_order', array(
                'type' => 'checkbox',
                'class' => array('input-checkbox'),
                'label' => __('Reserve this order', 'checkout-plugin'),
            ));
        }

        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                $("#add_to_reserve_order").change(function() {
                    var reserve = $("#add_to_reserve_order").is(":checked") ? 1 : 0;
                    $.ajax({
                        url: checkoutReserveOption.ajax_url,
                        type: "POST",
                        data: {
                            action: "toggle_reserve_shipping",
                            reserve: reserve
                        },
                        success: function(response) {
                            if (response.success) {
                                $(document.body).trigger("update_checkout");
                            }
                        }
                    });
                });
            });
        </script>';
    }

    private function customer_has_reserve_order($customer_id)
    {
        $reserved_orders = wc_get_orders(array(
            'status' => 'wc-reserve',
            'customer_id' => $customer_id,
            'limit' => 1,
        ));
        return !empty($reserved_orders);
    }
}

new CheckOutReserveOption();