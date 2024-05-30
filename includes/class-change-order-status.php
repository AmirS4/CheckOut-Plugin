<?php

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Change_Order_Status {
	public function __construct() {
		add_filter('woocommerce_my_account_my_orders_actions', array($this, 'add_change_status_button'), 10, 2);
		add_action('init', array($this, 'handle_status_change'));
	}

	public function add_change_status_button($actions, $order) {
		// Check if the order status is 'reserve'
		if ($order->has_status('reserve')) {
			$actions['change_to_processing'] = array(
				'url'  => wp_nonce_url(add_query_arg('change-order-status', $order->get_id()), 'change-to-processing'),
				'name' => __('Change to Processing', 'checkout-plugin'),
			);
		}
		return $actions;
	}

	public function handle_status_change() {
		if (isset($_GET['change-order-status']) && isset($_GET['_wpnonce'])) {
			$order_id = absint($_GET['change-order-status']);
			if (wp_verify_nonce($_GET['_wpnonce'], 'change-to-processing') && $order_id) {
				$order = wc_get_order($order_id);
				if ($order && $order->has_status('reserve')) {
					$order->update_status('processing', __('Order status changed by customer.', 'checkout-plugin'));
					wp_redirect(wc_get_account_endpoint_url('orders'));
					exit;
				}
			}
		}
	}
}

new Change_Order_Status();
