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
		wp_enqueue_style('branding-widget', '//unpkg.com/@oasis-catalog/branding-widget@1.3.0/client/style.css');
		wp_enqueue_script('branding-widget', '//unpkg.com/@oasis-catalog/branding-widget@1.3.0/client/index.iife.js', [], '', true);
		wp_enqueue_script('oasis-branding', plugins_url('/assets/js/widget.js', dirname( __FILE__ ) ), ['jquery'], '', true);
	}

	add_action('wp_enqueue_scripts', 'up_ajax_data_oasis_branding', 99);
	function up_ajax_data_oasis_branding() {
		wp_localize_script('jquery', 'uptotalprice', ['url' => admin_url('admin-ajax.php')]);
	}

	// карточка товара
	add_filter('woocommerce_available_variation', 'oasis_prepare_variation', 10, 1);
	function oasis_prepare_variation ($data) {
		if ($data['variation_id']) {
			$data['product_id_oasis'] = Main::getOasisProductIdByPostId($data['variation_id']) ?? '';
		}
		return $data;
	}
	
	// добавление в карточку товара
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
			$item_data = array_merge($item_data, [[
					'key'     => __('Branding', 'wp-oasis-importer'),
					'display' => $cart_item['oasis_branding']['label'],
				], [
					'key'     => 'Стоимость нанесения',
					'display' => wc_price($cart_item['oasis_branding']['price']),
				]
			]);
		}
		return $item_data;
	}

	add_filter('woocommerce_get_cart_contents', 'oasis_woocommerce_get_cart_contents');
	function oasis_woocommerce_get_cart_contents($cart_contents) {
		$is_up = false;
		foreach ($cart_contents as $cart_item) {
			if (isset($cart_item['oasis_branding'])
				&& (empty($cart_item['oasis_branding']['date_up']) || $cart_item['oasis_branding']['date_up'] != date('Y-m-d')))
			{
				$is_up = true;
				break;
			}
		}
		if ($is_up) {
			$cart_contents = &wc()->cart->cart_contents;
			$data = prepare_oasis_branding_car_items($cart_contents);
			if ($data) {
				$result = Api::brandingCalc($data, ['timeout' => 10]);
				if (empty($result->error) && !empty($result->branding)) {
					$i = 0;
					foreach ($cart_contents as &$cart_item) {
						if (isset($cart_item['oasis_branding'])) {
							$price = 0;
							foreach ($data['items'][$i]['branding'] as $n) {
								$price += $result->branding[$n]->main->price->client->total;
							}
							$cart_item['oasis_branding']['price'] = $price;
							$cart_item['oasis_branding']['date_up'] = date('Y-m-d');
							$i++;
						}
					}
				}
			}
		}
		return $cart_contents;
	}

	add_action('woocommerce_cart_calculate_fees', 'oasis_cart_calculate_fees', 10, 1);
	function oasis_cart_calculate_fees($cart) {
		$branding_cost = 0;
		foreach ($cart->cart_contents as $cart_item) {
			if (isset($cart_item['oasis_branding'])) {
				$branding_cost += $cart_item['oasis_branding']['price'] ?? 0;
			}
		}
		
		if (!empty($branding_cost)) {
			$cart->add_fee(__('Branding', 'wp-oasis-importer'), $branding_cost);
		}
	}
	add_action('woocommerce_after_cart_item_quantity_update', 'oasis_woocommerce_after_cart_item_quantity_update', 10, 3);
	function oasis_woocommerce_after_cart_item_quantity_update($cart_item_key, $quantity, $old_quantity) {
		foreach (wc()->cart->cart_contents as $key => &$cart_item) {
			if ($key === $cart_item_key && isset($cart_item['oasis_branding'])) {
				$cart_item['oasis_branding']['date_up'] = null;
			}
		}
		return $cart_contents;
	}

	// заказ
	add_filter('woocommerce_checkout_create_order_line_item', 'oasis_update_order_meta', 10, 3);
	function oasis_update_order_meta ($item, $cart_item_key, $values) {
		if (isset($values['oasis_branding'])) {
			$item->add_meta_data(__('Branding', 'wp-oasis-importer'), $values['oasis_branding']['label']);
			$item->add_meta_data(__('Cost of branding', 'wp-oasis-importer'), $values['oasis_branding']['price']);
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
 * @return mixed|string
 */
function get_product_id_oasis_by_cart_item($cart_item) {
	$options = get_option('oasis_options');
	if (empty($options['is_branding'])) {
		return;
	}

	$product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
	return Main::getOasisProductIdByPostId($product_id);
}