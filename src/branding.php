<?php

use OasisImport\Api;
use OasisImport\Main;

add_action('init', 'init_branding');

function init_branding() {
	$options = get_option('oasis_options');

	if (empty($options['is_branding'])) {
		return;
	}
	$IS_HPOS = get_option('woocommerce_custom_orders_table_enabled') === 'yes';


	add_action('wp_enqueue_scripts', 'init_scripts_branding');
	function init_scripts_branding() {
		wp_enqueue_style( 'branding-widget', '//unpkg.com/@oasis-catalog/branding-widget@1.3.0/client/style.css' );
		wp_enqueue_script( 'branding-widget', '//unpkg.com/@oasis-catalog/branding-widget@1.3.0/client/index.iife.js', [], '', true );
		wp_enqueue_script( 'oasis-branding', plugins_url( '/assets/js/widget.js', dirname( __FILE__ ) ), ['jquery'], '', true );
	}


	add_action( 'wp_enqueue_scripts', 'up_ajax_data_oasis_branding', 99 );
	function up_ajax_data_oasis_branding() {
		wp_localize_script( 'jquery', 'uptotalprice',
			[
				'url' => admin_url( 'admin-ajax.php' )
			]
		);
	}


	// карточка товара
	add_filter( 'woocommerce_available_variation', 'oasis_prepare_variation', 10, 1);
	function oasis_prepare_variation ($data) {
		if ($data['variation_id']) {
			$data['product_id_oasis'] =  Main::getOasisProductIdByPostId($data['variation_id']) ?? '';
		}
		return $data;
	}
	
	add_action('woocommerce_before_add_to_cart_button', 'oasis_branding');
	function oasis_branding() {
		global $product;

		$product_id_oasis = Main::getOasisProductIdByPostId($product->get_id());
		if ($product_id_oasis) {
			$locale		= str_replace('_', '-', get_locale());
			$currency	= get_woocommerce_currency();
			$row		= '<div class="js--oasis-client-branding-widget" data-oasis-locale="'.$locale.'" data-oasis-currency="'.$currency.'"';
			if ($product->is_type('simple')){
				$row .= ' data-oasis-product-id="'.$product_id_oasis.'"';
			}
			$row .= '></div>';
			echo $row;
		}
	}


	// добавление в корзину
	add_filter('woocommerce_add_cart_item_data', 'oasis_add_cart_item_data', 10, 3);
	function oasis_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
		if ($oasis_branding = $_REQUEST['branding']) {
			try {
				if ($productId = reset($oasis_branding)['productId']) {
					$data = Api::getBrandingCoef($productId);
					if ($data) {
						$labels = [];

						foreach ($oasis_branding as $branding) {
							foreach ($data->methods as $method) {
								foreach ($method->types as $type) {
									if ($branding['typeId'] == $type->id) {
										$labels[] = $type->name;
										break 2;
									}
								}
							}
						}

						$cart_item_data['oasis_branding'] = [
							'data' => $oasis_branding,
							'label' => implode(', ', $labels),
						];
					}
				}
			} catch (\Throwable $e) {
			}
		}

		return $cart_item_data;
	}


	// корзина
	add_filter('woocommerce_get_item_data', 'oasis_get_item_data', 10, 2);
	function oasis_get_item_data($item_data, $cart_item) {
		if (isset($cart_item['oasis_branding'])) {
			$item_data[] = array(
				'key'   => __('Branding', 'wp-oasis-importer'),
				'display' => $cart_item['oasis_branding']['label'],
			);
		}
		return $item_data;
	}

	add_action('woocommerce_cart_calculate_fees', 'oasis_cart_calculate_fees', 10, 1);
	function oasis_cart_calculate_fees($cart) {
		static $branding_cost;
		if (!isset($branding_cost)) {
			try {
				$data = prepare_oasis_branding_car_items($cart->cart_contents);

				if ($data) {
					$result = Api::brandingCalc($data, ['timeout' => 10]);

					if (empty($result->error) && !empty($result->branding)) {
						$branding_cost = 0;

						foreach ($result->branding as $branding) {
							$branding_cost += floatval($branding->{0}->price->client->total);
						}
					}
					else {
						wc_add_notice(__('We are temporarily unable to calculate the Branding, please try again later.', 'wp-oasis-importer'), 'error');
					}
				}
			}
			catch (\Throwable $e) {
				wc_add_notice(__('We are temporarily unable to calculate the Branding, please try again later.', 'wp-oasis-importer'), 'error');
			}
		}
		
		if (isset($branding_cost)) {
			$cart->add_fee(__('Branding', 'wp-oasis-importer'), $branding_cost);
		}
	}


	// заказ
	add_filter('woocommerce_checkout_create_order_line_item', 'oasis_update_order_meta', 10, 3);
	function oasis_update_order_meta ($item, $cart_item_key, $values) {
		if (isset($values['oasis_branding'])) {
			$item->add_meta_data(__('Branding', 'wp-oasis-importer'), $values['oasis_branding']['label']);
		}
	}

	if ($IS_HPOS) {
		add_action('woocommerce_store_api_checkout_order_processed', 'save_order_meta_oasis_branding', 10, 1);
		function save_order_meta_oasis_branding ($order) {
			$data = prepare_oasis_branding_car_items(wc()->cart->cart_contents);

			if ($data) {
				$order->add_meta_data('oasis_branding', json_encode($data));
				$order->save_meta_data();
			}
		}
	}
	else {
		add_action('woocommerce_checkout_order_processed', 'save_order_meta_oasis_branding', 10, 3);
		function save_order_meta_oasis_branding($order_id, $posted_data, $order) {
			$data = prepare_oasis_branding_car_items(wc()->cart->cart_contents);

			if ($data) {
				$order->add_meta_data('oasis_branding', json_encode($data));
				$order->save_meta_data();
			}
		}
	}


	function prepare_oasis_branding_car_items($cart_items = []) {
		$items = [];
		$brandings = [];

		foreach ($cart_items as $cart_item) {
			if (isset($cart_item['oasis_branding'])) {
				$item = [
					'quantity' => $cart_item['quantity']
				];

				foreach ($cart_item['oasis_branding']['data'] as $branding) {
					if (empty($item['productId'])) {
						$item['productId'] = $branding['productId'];
						$item['branding'] = [];
					}

					$item['branding'][] = count($brandings);
					$brandings[] = array_intersect_key($branding, array_flip(['placeId', 'typeId', 'width', 'height']));
				}
				$items[] = $item;
			}
		}

		return $items ? ['items' => $items, 'branding' => $brandings] : null;
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
