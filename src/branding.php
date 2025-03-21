<?php

use OasisImport\Api;
use OasisImport\Main;

add_action( 'init', 'init_branding' );

function init_branding() {
	$options = get_option( 'oasis_options' );

	if ( empty( $options['is_branding'] ) ) {
		return;
	}

	add_action( 'wp_enqueue_scripts', 'init_scripts_branding' );
	function init_scripts_branding() {
		wp_enqueue_style( 'branding-widget', '//unpkg.com/@oasis-catalog/branding-widget@^1.0.4/client/style.css' );
		wp_enqueue_script( 'branding-widget', '//unpkg.com/@oasis-catalog/branding-widget@^1.0.4/client/index.iife.js', [], '', true );
	}

	/**
	 * Add button up total price in page checkout
	 *
	 * @return void
	 */
	function add_btn_up_order() {
		echo '
<button id="total-price" class="oasis-branding-widget-toggler__btn" type="button">Обновить итог</button>
<div style="width: 100%;">Пересчитать итоговую стоимость с учетом нанесения</div>';
		wp_enqueue_script( 'oasis-branding', plugins_url( '/assets/js/widget.js', dirname( __FILE__ ) ), [], '', true );
	}

	add_action( 'wp_enqueue_scripts', 'up_ajax_data_oasis_branding', 99 );

	/**
	 * @return void
	 */
	function up_ajax_data_oasis_branding() {
		wp_localize_script( 'jquery', 'uptotalprice',
			[
				'url' => admin_url( 'admin-ajax.php' )
			]
		);
	}

	if ( wp_doing_ajax() ) {
		add_action( 'wp_ajax_oasis_action', 'oasis_branding_callback' );
		add_action( 'wp_ajax_nopriv_oasis_action', 'oasis_branding_callback' );
	}

	/**
	 * @return void
	 */
	function oasis_branding_callback() {
		$params = prepare_oasis_branding_params( $_POST['data'] ?? [] );

		$total       = 0;
		$cart_totals = WC()->cart->get_totals();

		if ( $params ) {
			try {
				$brandingData = Api::getBranding( $params );
			} catch ( Exception $e ) {
				echo $e->getMessage() . PHP_EOL;
			}

			if ( empty( $brandingData->error ) ) {
				if ( ! empty( $brandingData->branding ) ) {
					foreach ( $brandingData->branding as $branding ) {
						$total += floatval( $branding->{0}->price->client->total );
					}
				}
			} else {
				echo '<div class="error-oasis-branding" style="color: #ec0000">Ошибка! Измените параметры нанесений и повторите.</div>' . PHP_EOL;
			}
		}

		echo wc_price( floatval( $cart_totals['subtotal'] ) + $total );

		wp_die();
	}

	add_action( 'woocommerce_checkout_order_processed', 'save_order_meta_oasis_branding', 10, 3 );

	/**
	 * @param $order_id
	 * @param $posted_data
	 * @param $order
	 *
	 * @return void
	 */
	function save_order_meta_oasis_branding( $order_id, $posted_data, $order ) {
		if ( ! empty( $_POST['branding'] && is_array( $_POST['branding'] ) ) ) {
			$data = [];
			$i    = 0;

			$products = [];
			foreach ( $order->get_items() as $item ) {
				$products[ Main::getOasisProductIdByOrderItem( $item ) ] = $item->get_quantity();
			}
			unset( $item );

			foreach ( $_POST['branding'] as $key => $value ) {
				if ( $key !== 'items' && ! empty( $value ) ) {
					$data[ $i ]             = $value;
					$data[ $i ]['quantity'] = $products[ $value['productId'] ];
					$i ++;
				}
			}
			unset( $key, $value, $products );

			Main::d( $data, true );
			$params = prepare_oasis_branding_params( $data );
			Main::d( $params, true );

			if ( $params ) {
				$order->add_meta_data( 'oasis_branding', json_encode( $params ) );
				$order->save_meta_data();
			}
		}
	}

	/**
	 * Preparing oasis branding parameters
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	function prepare_oasis_branding_params( array $data ): array {
		$result = [];
		$i      = 0;

		foreach ( $data as $item ) {
			$brandingItem = [
				'placeId' => strval( $item['placeId'] ),
				'typeId'  => strval( $item['typeId'] ),
			];

			if ( ! empty( $item['width'] ) ) {
				$brandingItem['width'] = intval( $item['width'] );
			}

			if ( ! empty( $item['height'] ) ) {
				$brandingItem['height'] = intval( $item['height'] );
			}

			$brandingCheck = Main::checkDiffArray( $brandingItem, $result['branding'] ?? [] );
			$keyBranding   = is_null( $brandingCheck ) ? $i : $brandingCheck;

			if ( is_null( $brandingCheck ) ) {
				$result['branding'][ $i ] = $brandingItem;
			}

			if ( empty( $result['items'] ) ) {
				$result['items'][] = [
					'productId' => strval( $item['productId'] ),
					'quantity'  => intval( $item['quantity'] ),
					'branding'  => [ $keyBranding ]
				];
			} else {
				$keyItem = array_search( $item['productId'], array_column( $result['items'], 'productId' ) );

				if ( $keyItem === false ) {
					$result['items'][] = [
						'productId' => strval( $item['productId'] ),
						'quantity'  => intval( $item['quantity'] ),
						'branding'  => [ $keyBranding ]
					];
				} else {
					$result['items'][ $keyItem ]['branding'][] = $keyBranding;
					$result['items'][ $keyItem ]['branding']   = array_unique( $result['items'][ $keyItem ]['branding'] );
				}
			}
			$i ++;
		}

		return $result;
	}
}

/**
 * Get oasis product id by cart item
 *
 * @param $cart_item
 *
 * @return mixed|string
 */
function get_product_id_oasis_by_cart_item( $cart_item ) {
	$options = get_option( 'oasis_options' );

	if ( empty( $options['is_branding'] ) ) {
		return;
	}

	global $wpdb;

	$product_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];

	if ( $product_id ) {
		$dbResult = $wpdb->get_row( "
SELECT * FROM {$wpdb->prefix}oasis_products 
WHERE post_id = '" . $product_id . "'", ARRAY_A );
	}

	return $dbResult['product_id_oasis'] ?? '';
}
