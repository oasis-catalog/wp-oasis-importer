<?php

namespace OasisImport;

use OasisImport\Config as OasisConfig;
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
	 * @param bool $sleep
	 * @return array
	 */
	public static function getCategoriesOasis(bool $sleep = true): array
	{
		return self::curlQuery('categories', ['fields' => 'id,parent_id,root,level,slug,name,path'], $sleep);
	}

	/**
	 * Get currencies oasis
	 *
	 * @param bool $sleep
	 * @return array
	 */
	public static function getCurrenciesOasis(bool $sleep = true): array
	{
		return self::curlQuery( 'currencies', [], $sleep );
	}

	/**
	 * Get stock oasis
	 *
	 * @return array
	 */
	public static function getStockOasis(): array {
		return self::curlQuery( 'stock', [ 'fields' => 'article,stock,id,stock-remote' ] );
	}

	/**
	 * Get brands
	 *
	 * @param bool $sleep
	 * 
	 * @return array
	 */
	public static function getBrands(bool $sleep = true): array
	{
		return self::curlQuery('brands', [], $sleep, 'v3');
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
	public static function getBrandingCoef($id, $admin = false)
	{
		return self::curlQuery('branding/coef', [
			'id' => $id 
		], false, 'v4');
	}

	/**
	 * Send data by POST method
	 *
	 * @param string $type
	 * @param array $data
	 * @return array|mixed
	 */
	public static function curlSend(string $type, array $data, array $params = [])
	{
		if (empty(self::$cf->api_key)){
			return [];
		}

		$args_pref = [
			'key'    => self::$cf->api_key,
			'format' => 'json',
		];

		try {
			$ch = curl_init('https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query($args_pref));
			curl_setopt_array($ch, [
				CURLOPT_POST           => 1,
				CURLOPT_POSTFIELDS     => json_encode($data),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_HEADER         => false,
				CURLOPT_TIMEOUT        => $params['timeout'] ?? 0,
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'Accept: application/json',
				]
			]);
			$content = curl_exec($ch);

			if ($content === false) {
				throw new Exception('Error: ' . curl_error($ch));
			} else {
				$result = json_decode($content, false);
			}

			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($http_code === 401) {
				throw new Exception('Error Unauthorized. Invalid API key!');
			} elseif ($http_code != 200 && $http_code != 500) {
				throw new Exception('Error: ' . ($result->error ?? '') . PHP_EOL . 'Code: ' . $http_code);
			}
		} catch (Exception $e) {
			if (PHP_SAPI === 'cli') {
				echo $e->getMessage() . PHP_EOL;
			}
			return [];
		}

		return $result;
	}

	/**
	 * Get api data
	 *
	 * @param $type
	 * @param array $args
	 * @param bool $sleep
	 * @param string $version
	 * @return array|mixed
	 */
	public static function curlQuery($type, array $args = [], bool $sleep = true, string $version = 'v4')
	{
		if (empty(self::$cf->api_key)){
			return [];
		}
		$args = array_merge([
			'key'    => self::$cf->api_key,
			'format' => 'json',
		], $args);

		try {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://api.oasiscatalog.com/'.$version.'/'.$type.'?'. http_build_query($args));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$content = curl_exec($ch);

			if ($content === false) {
				throw new Exception('Error: ' . curl_error($ch));
			} else {
				$result = json_decode($content, false);
			}

			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($sleep) {
				sleep(1);
			}

			if ($http_code === 401) {
				throw new Exception('Error Unauthorized. Invalid API key!');
			} elseif ($http_code != 200) {
				throw new Exception('Error. Code: ' . $http_code);
			}
		} catch (Exception $e) {
			if (PHP_SAPI === 'cli') {
				echo $e->getMessage() . PHP_EOL;
			}
			return [];
		}

		return $result;
	}
}
