<?php
if (!defined('ABSPATH')) {
	exit;
}

use OasiscatalogImporter\Api;
use OasiscatalogImporter\Main;
use OasiscatalogImporter\Config as OasisConfig;

add_action( 'init', 'oasis_import_order_init' );

function oasis_import_order_init() {
	if ( current_user_can( 'manage_options' ) || current_user_can( 'shop_manager' ) ) {
		$IS_HPOS = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

		add_filter($IS_HPOS ? 'manage_woocommerce_page_wc-orders_columns' : 'manage_shop_order_posts_columns', function ( $defaults ) {
			$columns = [];
			foreach ( $defaults as $field => $value ) {
				if ( $field == 'order_total' ) {
					$columns['oa_export'] = esc_attr__( 'Unload', 'oasiscatalog-importer' );
				}
				$columns[ $field ] = $value;
			}

			return $columns;
		}, 100 );

		add_action($IS_HPOS ? 'manage_woocommerce_page_wc-orders_custom_column' : 'manage_shop_order_posts_custom_column', function ( $column_name, $post_ID ) {
			if ($column_name === 'oa_export') {
				$order          = wc_get_order( $post_ID );
				$oasisProductId = null;

				foreach ( $order->get_items() as $item ) {
					$oasisProductId = Main::getOasisProductIdByOrderItem( $item );
				}

				if (is_null($oasisProductId)) {
					esc_attr_e( 'Invalid items in the order', 'oasiscatalog-importer' );
					return;
				}
				unset( $item, $oasisProductId );

				$queueId = get_metadata( 'post', $order->get_order_number(), 'oasis_queue_id', true );

				if ($queueId) {
					$dataOrder = Api::getOrderByQueueId($queueId);

					if (isset($dataOrder->state)) {
						if ( $dataOrder->state == 'created' ) {
							echo '<div class="order-status status-processing" style="display: flex;justify-content: center;align-items: center;"><span class="dashicons dashicons-yes-alt"></span>' . esc_html($dataOrder->order->statusText) . '. Заказ №' . esc_html($dataOrder->order->number) . '</div></div>';
						} elseif ( $dataOrder->state == 'pending' ) {
							echo '<div class="order-status status-on-hold" style="display: flex;justify-content: center;align-items: center;"><span class="dashicons dashicons-warning"></span>' . esc_html__( 'The order is being processed, please wait.', 'oasiscatalog-importer' ) . '</div>';
						} elseif ( $dataOrder->state == 'error' ) {
							echo '<div class="order-status status-failed" style="display: flex;justify-content: center;align-items: center;"><span class="dashicons dashicons-dismiss"></span>' . esc_html__( 'Error, please try again.', 'oasiscatalog-importer' ) . '</div><div style="margin-top: 5px;"><input type="submit" name="send_order" class="button send_order" value="' . esc_html__( 'Unload', 'oasiscatalog-importer' ) . '" data-order-id="' . esc_attr($order->get_order_number()) . '"></div>';
						}
					}
				} else {
					echo '<input type="button" name="send_order" class="button js-oasis-send_order" value="' . esc_attr__( 'Unload', 'oasiscatalog-importer' ) . '" data-order-id="' . esc_attr($order->get_order_number()) . '">';
				}
			}
		}, 10, 2);

		add_action('admin_enqueue_scripts', 'oasis_import_order_script_init');
		function oasis_import_order_script_init( $hook ) {
			$IS_HPOS = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

			$screen = get_current_screen();
			if (($IS_HPOS ? 'woocommerce_page_wc-orders' : 'edit-shop_order') != $screen->id ) {
				return;
			}

			$options = get_option('oasis_import_options');

			if (!empty($options['api_key']) && !empty($options['api_user_id'])) {
				wp_enqueue_script('oasis-import-order', plugins_url('/assets/js/order.js', dirname(__FILE__)), ['jquery'], OasisConfig::VERSION, ['in_footer' => true]);
			}
		}

		add_action('wp_ajax_oasis_send_order', 'oasis_import_order_send_order');
		function oasis_import_order_send_order() {
			$order_id = sanitize_text_field($_POST['order_id']);
			$options  = get_option('oasis_import_options');

			if (!empty($order_id)) {
				$data = [
					'userId' => $options['api_user_id'],
				];

				if (!empty($data['userId'])) {
					$order        = wc_get_order($order_id);
					$brandingData = json_decode($order->get_meta('oasis_branding'), true);

					if ($brandingData) {
						$data += $brandingData;
					} else {
						foreach ($order->get_items() as $item) {
							$data['items'][] = [
								'productId' => Main::getOasisProductIdByOrderItem($item),
								'quantity'  => $item->get_quantity(),
							];
						}
					}
					try {
						$request = Api::sendOrder($data);

						if (!empty($request->error)) {
							wp_send_json_error($request->error);
						}

						if ($request) {
							update_metadata('post', $order_id, 'oasis_queue_id', $request->queueId);
						}
					}
					catch (\Throwable $e) {}
				}
			}
			wp_die();
		}
	}
}
