<?php


/**
 * Plugin Name: CheckOut Plugin
 * Description: This is my test plugin for CheckOut
 * Version: 1.0.0
 * Text Domain: checkout-plugin
 * Domain Path: /languages
 * Author: S4
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


if ( ! class_exists( 'CheckOutPlugin' ) ) {
	class CheckOutPlugin {
		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
			$this->define_constants();
			$this->includes();
			$this->init_hooks();
		}


		public function load_textdomain(): void {
			load_plugin_textdomain( 'checkout-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}


		private function define_constants() {
			define( 'MY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
			define( 'MY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
			define( 'MY_PLUGIN_VERSION', '1.0.0' );
		}


		private function includes() {
			include_once MY_PLUGIN_PATH . 'includes/class-checkout-reserve-option.php';
			include_once MY_PLUGIN_PATH . 'includes/class-change-order-status.php';
			include_once MY_PLUGIN_PATH . 'includes/class-countdown-timer.php';
		}


		private function init_hooks() {
			add_action( 'init', array( $this, 'register_reserve_order_status' ) );
			add_filter( 'wc_order_statuses', array( $this, 'add_reserve_to_order_statuses' ) );
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'wp', array( $this, 'schedule_check_reserve_orders' ) );
			add_action( 'check_reserve_orders', array( $this, 'check_and_update_reserve_orders' ) );
			add_action( 'wp_login', array( $this, 'trigger_check_reserve_orders_on_login' ), 10, 2 );
		}


		public function trigger_check_reserve_orders_on_login( $user_login, $user ) {
			do_action( 'check_reserve_orders' );
		}


		public function check_and_update_reserve_orders() {
			$timeout_hours = get_option( 'reserve_order_timeout', 24 );
			$args          = array(
				'status'     => 'wc-reserve',
				'date_query' => array(
					array(
						'before' => date( 'Y-m-d H:i:s', strtotime( "-$timeout_hours hours" ) ),
					),
				),
				'return'     => 'ids',
			);

			$reserved_orders = wc_get_orders( $args );

			foreach ( $reserved_orders as $order_id ) {
				$order = wc_get_order( $order_id );
				$order->update_status( 'wc-processing', __( 'Order status changed to processing after timeout.', 'checkout-plugin' ) );
			}
		}


		public function schedule_check_reserve_orders() {
			if ( ! wp_next_scheduled( 'check_reserve_orders' ) ) {
				wp_schedule_event( time(), 'hourly', 'check_reserve_orders' );
			}
		}


		public function add_admin_menu() {
			add_menu_page(
				__( 'Reserve Settings', 'checkout-plugin' ),
				__( 'Reserve Settings', 'checkout-plugin' ),
				'manage_options',
				'reserve-settings',
				array( $this, 'reserve_settings_page' )
			);
		}


		public function add_reserve_to_order_statuses( $order_statuses ) {
			$new_order_statuses = array();

			foreach ( $order_statuses as $key => $status ) {
				$new_order_statuses[ $key ] = $status;
				if ( 'wc-processing' === $key ) {
					$new_order_statuses['wc-reserve'] = _x( 'Reserve', 'Order status', 'checkout-plugin' );
				}
			}

			return $new_order_statuses;
		}


		public function reserve_settings_page() {
			if ( ! empty( $_POST['reserve_timeout'] ) ) {
				update_option( 'reserve_order_timeout', (int) $_POST['reserve_timeout'] );
				echo '<div class="updated"><p>' . __( 'Settings saved.', 'checkout-plugin' ) . '</p></div>';
			}

			$current_timeout = get_option( 'reserve_order_timeout', 24 ); // Default to 24 hours

			echo '<form method="post">';
			echo '<h3>' . __( 'Reserve Order Timeout (in hours)', 'checkout-plugin' ) . '</h3>';
			echo '<input type="number" name="reserve_timeout" value="' . esc_attr( $current_timeout ) . '" />';
			echo '<input type="submit" value="' . __( 'Save Settings', 'checkout-plugin' ) . '" class="button-primary"/>';
			echo '</form>';
		}


		public function register_reserve_order_status() {
			register_post_status( 'wc-reserve', array(
				'label'                     => _x( 'Reserve', 'Order status', 'checkout-plugin' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( 'Reserve <span class="count">(%s)</span>', 'Reserve <span class="count">(%s)</span>', 'checkout-plugin' )
			) );
		}
	}


	new CheckOutPlugin();
}
