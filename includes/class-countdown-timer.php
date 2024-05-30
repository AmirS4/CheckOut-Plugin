<?php

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Countdown_Timer {
	public function __construct() {
		add_filter('woocommerce_account_orders_columns', array($this, 'add_countdown_timer_column'));
		add_action('woocommerce_my_account_my_orders_column_countdown_timer', array($this, 'display_countdown_timer'), 10, 1);
		add_action('wp_footer', array($this, 'countdown_timer_script'));
	}

	public function add_countdown_timer_column($columns) {
		$columns['countdown_timer'] = __('Countdown Timer', 'your-text-domain');
		return $columns;
	}

	public function display_countdown_timer($order) {
		if ($order->get_status() === 'reserve') {
			$created_timestamp = strtotime($order->get_date_created()->date('Y-m-d H:i:s'));
			$timeout_hours = get_option('reserve_order_timeout', 24);
			$timeout_timestamp = $created_timestamp + $timeout_hours * 3600;
			$server_time = current_time('timestamp');
			echo '<span class="countdown-timer" data-timeout="' . $timeout_timestamp . '" data-server-time="' . $server_time . '"></span>';
		}
	}

	public function countdown_timer_script() {
		?>
        <script>
            jQuery(document).ready(function($) {
                $('.countdown-timer').each(function() {
                    var $timer = $(this);
                    var timeoutTimestamp = parseInt($timer.data('timeout'));
                    var serverTime = parseInt($timer.data('server-time'));
                    var userTime = Math.floor(Date.now() / 1000);
                    var timeOffset = userTime - serverTime;

                    setInterval(function() {
                        var remainingSeconds = Math.max(0, timeoutTimestamp - (Math.floor(Date.now() / 1000) - timeOffset));
                        var hours = Math.floor(remainingSeconds / 3600);
                        var minutes = Math.floor((remainingSeconds % 3600) / 60);
                        var seconds = remainingSeconds % 60;
                        var remainingTime = hours.toString().padStart(2, '0') + ':' + minutes.toString().padStart(2, '0') + ':' + seconds.toString().padStart(2, '0');
                        $timer.text(remainingTime);
                    }, 1000);
                });
            });
        </script>
		<?php
	}
}

new Countdown_Timer();


