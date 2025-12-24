<?php
if (!defined('ABSPATH')) {
	exit;
}

use OasiscatalogImporter\Api;
use OasiscatalogImporter\Main;
use OasiscatalogImporter\Config as OasisConfig;

add_action('init', 'oasis_import_branding_init');

function oasis_import_branding_init() {
	$options = get_option('oasis_import_options');

	if (empty($options['is_branding'])) {
		return;
	}
	$IS_HPOS = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

	add_action('wp_enqueue_scripts', 'oasis_import_branding_scripts');
	function oasis_import_branding_scripts() {
		wp_enqueue_style('oasis-import-branding-widget', plugins_url('/assets/css/branding-widget.css', dirname( __FILE__ )), [], OasisConfig::VERSION);
		wp_enqueue_script('oasis-import-branding-widget', plugins_url('/assets/js/branding-widget.js', dirname( __FILE__ )), [], OasisConfig::VERSION, ['in_footer' => true]);
		wp_enqueue_script('oasis-import-widget', plugins_url('/assets/js/widget.js', dirname( __FILE__ )), ['jquery'], OasisConfig::VERSION, ['in_footer' => true]);
	}

	// карточка товара
	add_filter('woocommerce_available_variation', 'oasis_import_branding_prepare_variation', 10, 1);
	function oasis_import_branding_prepare_variation ($data) {
		if ($data['variation_id']) {
			$data['product_id_oasis'] = Main::getOasisProductIdByPostId($data['variation_id']) ?? '';
		}
		return $data;
	}
	
	// добавление в карточку товара
	add_action('woocommerce_before_add_to_cart_button', 'oasis_import_branding_cart');
	function oasis_import_branding_cart() {
		global $product;

		$product_id_oasis = Main::getOasisProductIdByPostId($product->get_id());
		if ($product_id_oasis) {
			echo '<div class="js--oasis-client-branding-widget"'
					. ' data-oasis-locale="' . esc_attr(str_replace('_', '-', get_locale())) . '"'
					. ' data-oasis-currency="' . esc_attr(get_woocommerce_currency()) . '"'
					. ' data-oasis-product-id="' . ($product->is_type('simple') ? esc_attr($product_id_oasis) : '') . '"'
					. '></div>';
		}
	}

	// добавление в корзину
	add_filter('woocommerce_add_cart_item_data', 'oasis_import_branding_add_cart_item_data', 10, 3);
	function oasis_import_branding_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
		$oasisBranding = [];
		foreach ($_REQUEST['branding'] ?? [] as $requestItem) {
			$item = [];
			foreach (['productId', 'placeId', 'typeId', 'width', 'height', 'logoId'] as $k) {
				if ($v = sanitize_text_field($requestItem[$k])) {
					$item[$k] = $v;
				}
			}
			if (!empty($item['productId'])) {
				$oasisBranding[] = $item;
			}
		}
		if ($oasisBranding) {
			try {
				$data = Api::getBrandingCoef($oasisBranding[0]['productId']);
				if ($data) {
					$labels = [];

					foreach ($oasisBranding as $branding) {
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
						'data' => $oasisBranding,
						'label' => implode(', ', $labels),
					];
				}
			} catch (\Throwable $e) {
			}
		}

		return $cart_item_data;
	}

	// корзина
	add_filter('woocommerce_get_item_data', 'oasis_import_branding_get_item_data', 10, 2);
	function oasis_import_branding_get_item_data($item_data, $cart_item) {
		if (isset($cart_item['oasis_branding'])) {
			$item_data = array_merge($item_data, [[
					'key'     => esc_attr__('Branding', 'oasiscatalog-importer'),
					'display' => $cart_item['oasis_branding']['label'],
				], [
					'key'     => 'Стоимость нанесения',
					'display' => wc_price($cart_item['oasis_branding']['price']),
				]
			]);
		}
		return $item_data;
	}

	add_filter('woocommerce_get_cart_contents', 'oasis_import_branding_get_cart_contents');
	function oasis_import_branding_get_cart_contents($cart_contents) {
		$is_up = false;
		foreach ($cart_contents as $cart_item) {
			if (isset($cart_item['oasis_branding'])
				&& (empty($cart_item['oasis_branding']['date_up']) || $cart_item['oasis_branding']['date_up'] != gmdate('Y-m-d')))
			{
				$is_up = true;
				break;
			}
		}
		if ($is_up) {
			$cart_contents = &wc()->cart->cart_contents;
			$data = oasis_import_branding_prepare_branding_car_items($cart_contents);
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
							$cart_item['oasis_branding']['date_up'] = gmdate('Y-m-d');
							$i++;
						}
					}
				}
			}
		}
		return $cart_contents;
	}

	add_action('woocommerce_cart_calculate_fees', 'oasis_import_branding_cart_calculate_fees', 10, 1);
	function oasis_import_branding_cart_calculate_fees($cart) {
		$branding_cost = 0;
		foreach ($cart->cart_contents as $cart_item) {
			if (isset($cart_item['oasis_branding'])) {
				$branding_cost += $cart_item['oasis_branding']['price'] ?? 0;
			}
		}
		
		if (!empty($branding_cost)) {
			$cart->add_fee(esc_attr__('Branding', 'oasiscatalog-importer'), $branding_cost);
		}
	}
	add_action('woocommerce_after_cart_item_quantity_update', 'oasis_import_branding_after_cart_item_quantity_update', 10, 3);
	function oasis_import_branding_after_cart_item_quantity_update($cart_item_key, $quantity, $old_quantity) {
		foreach (wc()->cart->cart_contents as $key => &$cart_item) {
			if ($key === $cart_item_key && isset($cart_item['oasis_branding'])) {
				$cart_item['oasis_branding']['date_up'] = null;
			}
		}
	}

	// заказ
	add_filter('woocommerce_checkout_create_order_line_item', 'oasis_import_branding_update_order_meta', 10, 3);
	function oasis_import_branding_update_order_meta ($item, $cart_item_key, $values) {
		if (isset($values['oasis_branding'])) {
			$item->add_meta_data(esc_attr__('Branding', 'oasiscatalog-importer'), $values['oasis_branding']['label']);
			$item->add_meta_data(esc_attr__('Cost of branding', 'oasiscatalog-importer'), $values['oasis_branding']['price']);
		}
	}

	if ($IS_HPOS) {
		add_action('woocommerce_store_api_checkout_order_processed', 'oasis_import_branding_save_order_meta', 10, 1);
		function oasis_import_branding_save_order_meta ($order) {
			$data = oasis_import_branding_prepare_branding_car_items(wc()->cart->cart_contents);
			if ($data) {
				$order->add_meta_data('oasis_branding', wp_json_encode($data));
				$order->save_meta_data();
			}
		}
	}
	else {
		add_action('woocommerce_checkout_order_processed', 'oasis_import_branding_save_order_meta', 10, 3);
		function oasis_import_branding_save_order_meta($order_id, $posted_data, $order) {
			$data = oasis_import_branding_prepare_branding_car_items(wc()->cart->cart_contents);
			if ($data) {
				$order->add_meta_data('oasis_branding', wp_json_encode($data));
				$order->save_meta_data();
			}
		}
	}

	function oasis_import_branding_prepare_branding_car_items($cart_items = []) {
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
function oasis_import_branding_get_product_id($cart_item) {
	$options = get_option('oasis_import_options');
	if (empty($options['is_branding'])) {
		return;
	}

	$product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
	return Main::getOasisProductIdByPostId($product_id);
}