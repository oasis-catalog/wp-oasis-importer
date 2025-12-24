<?php

namespace OasiscatalogImporter;

use OasiscatalogImporter\Config as OasisConfig;
use Exception;

class Api {
	public static OasisConfig $cf;

	/**
	 * Get products oasis
	 *
	 * @param array $args
	 * @return array
	 */
	public static function getOasisProducts(array $args = []): array
	{
		$data = [
			'fieldset'		=> 'full',
			'not_on_order'	=> self::$cf->is_not_on_order,
			'excludeDefect' => self::$cf->is_not_defect,
			'currency'		=> self::$cf->currency,
			'no_vat'		=> self::$cf->is_no_vat,
			'price_from'	=> self::$cf->price_from,
			'price_to'		=> self::$cf->price_to,
			'rating'		=> self::$cf->rating,
			'moscow'		=> self::$cf->is_wh_moscow,
			'europe'		=> self::$cf->is_wh_europe,
			'remote'		=> self::$cf->is_wh_remote,
		];
		foreach ($data as $key => $value) {
			if ($value) {
				$args[$key] = $value;
			}
		}
		$products = self::curlQuery('products', $args);

		if (!empty($products) && Main::arrayKeysExists($args, ['limit', 'ids', 'articles'])) {
			unset($args['limit'], $args['offset'], $args['ids'], $args['articles']);

			$group_ids = [$products[array_key_first($products)]->group_id];

			if (count($products) > 1) {
				$group_ids[] = $products[array_key_last($products)]->group_id;
			}

			$args['group_id'] = implode( ',', array_unique($group_ids));
			$addProducts = self::curlQuery('products', $args);

			foreach ($addProducts as $addProduct) {
				if (!array_find($products, fn($item) => $item->id == $addProduct->id)) {
					$products[] = $addProduct;
				}
			}
		}
		return $products;
	}

	/**
	 * Get Stat Products
	 * @param array $args
	 * @return stdClass
	 */
	public static function getStatProducts(array $args = [])
	{
		$data = [
			'excludeDefect' => self::$cf->is_not_defect,
			'not_on_order'  => self::$cf->is_not_on_order,
			'price_from'    => self::$cf->price_from,
			'price_to'      => self::$cf->price_to,
			'rating'        => self::$cf->rating,
			'moscow'        => self::$cf->is_wh_moscow,
			'europe'        => self::$cf->is_wh_europe,
			'remote'        => self::$cf->is_wh_remote,
			'category'      => implode(',', self::$cf->categories ?: Main::getOasisMainCategories()),
		];
		foreach ($data as $key => $value) {
			if ($value) {
				$args[$key] = $value;
			}
		}
		return self::curlQuery('stat', $args);
	}

	/**
	 * Get categories oasis
	 * @return array
	 */
	public static function getCategoriesOasis(): array
	{
		return self::curlQuery('categories', ['fields' => 'id,parent_id,root,level,slug,name,path']);
	}

	/**
	 * Get currencies oasis
	 *
	 * @return array
	 */
	public static function getCurrenciesOasis(): array
	{
		return self::curlQuery('currencies', []);
	}

	/**
	 * Get stock oasis
	 *
	 * @return array
	 */
	public static function getStockOasis(): array
	{
		return self::curlQuery('stock', ['fields' => 'article,stock,id,stock-remote']);
	}

	/**
	 * Get brands
	 *
	 * @return array
	 */
	public static function getBrands(): array
	{
		return self::curlQuery('brands', [], 'v3');
	}

	/**
	 * Export order to Oasiscatalog
	 *
	 * @param $data
	 * @return array|mixed
	 */
	public static function sendOrder($data)
	{
		return self::curlSend('reserves/', $data);
	}

	/**
	 * Get order data by queue id
	 *
	 * @param $queueId
	 * @return array|mixed
	 */
	public static function getOrderByQueueId($queueId)
	{
		return self::curlQuery('reserves/by-queue/' . $queueId);
	}

	/**
	 * @param array $data
	 * @param array $params
	 * @return array|mixed
	 */
	public static function brandingCalc($data, $params)
	{
		return self::curlSend('branding/calc', $data, $params);
	}

	/**
	 * @param $id
	 * @param $admin
	 * @return array|mixed
	 */
	public static function getBrandingCoef($id)
	{
		return self::curlQuery('branding/coef', [
			'id' => $id 
		], 'v4');
	}

	/**
	 * Send data by POST method
	 *
	 * @param string $type
	 * @param array $data
	 * @return array|mixed
	 */
	public static function curlSend(string $type, array $data, array $params = [], $version = 'v4')
	{
		if (empty(self::$cf->api_key)){
			return [];
		}
		$args_pref = [
			'key'    => self::$cf->api_key,
			'format' => 'json',
		];

		try {
			$response = wp_remote_request('https://api.oasiscatalog.com/' . $version . '/' . $type . '?' . http_build_query($args_pref), [
				'method' => 'POST',
				'timeout' => $params['timeout'] ?? 0,
				'body' => wp_json_encode($data),
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
			]);

			if (!is_wp_error($response)) {
				$code = $response['response']['code'];

				if ($code === 401) {
					throw new Exception('Error Unauthorized. Invalid API key!');
				}
				elseif ($code != 200) {
					throw new Exception('Error. Code: ' . $code);
				}
				else {
					return json_decode($response['body'], false);
				}
			} else {
				throw new Exception('Error: ' . $response->get_error_message());
			}
		} catch (Exception $e) {
			self::$cf->fatal($e->getMessage());
		}
	}

	/**
	 * Get api data
	 *
	 * @param $type
	 * @param array $args
	 * @param string $version
	 * @return array|mixed
	 */
	public static function curlQuery($type, array $args = [], string $version = 'v4')
	{
		if (empty(self::$cf->api_key)){
			return [];
		}
		$args = array_merge([
			'key'    => self::$cf->api_key,
			'format' => 'json',
		], $args);

		try {
			$response = wp_remote_request('https://api.oasiscatalog.com/' . $version . '/' . $type, [
				'method' => 'GET',
				'timeout' => 30,
				'body' => $args,
			]);

			if (!is_wp_error($response)) {
				$code = $response['response']['code'];

				if ($code === 401) {
					throw new Exception('Error Unauthorized. Invalid API key!');
				}
				elseif ($code != 200) {
					throw new Exception('Error. Code: ' . $code);
				}
				else {
					return json_decode($response['body'], false);
				}
			} else {
				throw new Exception('Error: ' . $response->get_error_message());
			}
		} catch (Exception $e) {
			self::$cf->fatal($e->getMessage());
		}
	}
}