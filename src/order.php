<?php

use OasisImport\Controller\Oasis\Api;
use OasisImport\Controller\Oasis\Main;

add_action( 'init', 'init_order' );

function init_order() {
	if ( current_user_can( 'manage_options' ) || current_user_can( 'shop_manager' ) ) {
		$IS_HPOS = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

		add_filter($IS_HPOS ? 'manage_woocommerce_page_wc-orders_columns' : 'manage_shop_order_posts_columns', function ( $defaults ) {
			$columns = [];
			foreach ( $defaults as $field => $value ) {
				if ( $field == 'order_total' ) {
					$columns['oa_export'] = __( 'Unload', 'wp-oasis-importer' );
				}
				$columns[ $field ] = $value;
			}

			return $columns;
		}, 100 );

		add_action($IS_HPOS ? 'manage_woocommerce_page_wc-orders_custom_column' : 'manage_shop_order_posts_custom_column', function ( $column_name, $post_ID ) {
			if ( $column_name === 'oa_export' ) {
				$order          = wc_get_order( $post_ID );
				$oasisProductId = null;

				foreach ( $order->get_items() as $item ) {
					$oasisProductId = Main::getOasisProductIdByOrderItem( $item );
				}

				if ( is_null( $oasisProductId ) ) {
					echo __( 'Invalid items in the order', 'wp-oasis-importer' );

					return;
				}
				unset( $item, $oasisProductId );

				$queueId    = get_metadata( 'post', $order->get_order_number(), 'oasis_queue_id', true );
				$htmlExport = '';

				if ( $queueId ) {
					$dataOrder = Api::getOrderByQueueId( $queueId );

					if ( isset( $dataOrder->state ) ) {
						if ( $dataOrder->state == 'created' ) {
							$htmlExport = '<div class="order-status status-processing" style="display: flex;justify-content: center;align-items: center;"><span class="dashicons dashicons-yes-alt"></span>' . $dataOrder->order->statusText . '. Заказ №' . $dataOrder->order->number . '</div></div>';
						} elseif ( $dataOrder->state == 'pending' ) {
							$htmlExport = '<div class="order-status status-on-hold" style="display: flex;justify-content: center;align-items: center;"><span class="dashicons dashicons-warning"></span>' . __( 'The order is being processed, please wait.', 'wp-oasis-importer' ) . '</div>';
						} elseif ( $dataOrder->state == 'error' ) {
							$htmlExport = '<div class="order-status status-failed" style="display: flex;justify-content: center;align-items: center;"><span class="dashicons dashicons-dismiss"></span>' . __( 'Error, please try again.', 'wp-oasis-importer' ) . '</div><div style="margin-top: 5px;"><input type="submit" name="send_order" class="button send_order" value="' . __( 'Unload', 'wp-oasis-importer' ) . '" data-order-id="' . $order->get_order_number() . '"></div>';
						}
					}
				} else {
					$htmlExport = '<input type="button" name="send_order" class="button oasis-send_order" value="' . __( 'Unload', 'wp-oasis-importer' ) . '" data-order-id="' . $order->get_order_number() . '">';
				}

				echo $htmlExport;
			}
		}, 10, 2 );

		add_action( 'admin_enqueue_scripts', 'oasis_order_script_init' );
		function oasis_order_script_init( $hook ) {
			$IS_HPOS = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

			$screen = get_current_screen();
			if (($IS_HPOS ? 'woocommerce_page_wc-orders' : 'edit-shop_order') != $screen->id ) {
				return;
			}

			$options = get_option( 'oasis_options' );

			if ( ! empty( $options['oasis_api_key'] ) && ! empty( $options['oasis_api_user_id'] ) ) {
				wp_enqueue_script( 'oasis-order', plugins_url( '/assets/js/order.js', dirname( __FILE__ ) ), [ 'jquery' ] );
			}
		}

		add_action( 'wp_ajax_send_order', 'send_order_ajax' );

		function send_order_ajax() {
			$order_id = strval( $_POST['order_id'] );
			$options  = get_option( 'oasis_options' );

			if ( ! empty( $order_id ) ) {
				$data = [
					'userId' => $options['oasis_api_user_id'],
				];

				if ( ! empty( $data['userId'] ) ) {
					$order        = wc_get_order( $order_id );
					$brandingData = json_decode( $order->get_meta( 'oasis_branding' ), true );

					if ( $brandingData ) {
						$data += $brandingData;
					} else {
						foreach ( $order->get_items() as $item ) {
							$data['items'][] = [
								'productId' => Main::getOasisProductIdByOrderItem( $item ),
								'quantity'  => $item->get_quantity(),
							];
						}
						unset( $item );
					}

					$request = Api::sendOrder( $data );

					if ( ! empty( $request->error ) ) {
						wp_send_json_error( $request->error );
					}

					if ( $request ) {
						update_metadata( 'post', $order_id, 'oasis_queue_id', $request->queueId );
					}
				}
			}
			wp_die();
		}
	}
}
