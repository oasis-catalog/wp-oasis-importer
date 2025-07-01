<?php

namespace OasisImport;

use OasisImport\Config as OasisConfig;
use Exception;

class Api {
	public static OasisConfig $cf;

	/**
	 * Get products oasis
	 *
	 * @param $categories
	 * @param array $args
	 *
	 * @return array
	 */
	public static function getOasisProducts( $categories, array $args = [] ): array {
		$args += [
			'fieldset'		=> 'full',
			'extend'		=> 'is_visible',
			'showDeleted'	=> '1',

			'currency'		=> self::$cf->currency,
			'no_vat'		=> self::$cf->is_no_vat,
			'not_on_order'	=> self::$cf->is_not_on_order,
			'price_from'	=> self::$cf->price_from,
			'price_to'		=> self::$cf->price_to,
			'rating'		=> self::$cf->rating,
			'moscow'		=> self::$cf->is_wh_moscow,
			'europe'		=> self::$cf->is_wh_europe,
			'remote'		=> self::$cf->is_wh_remote,
			'category'		=> implode(',', empty(self::$cf->categories) ? Main::getOasisMainCategories( $categories ) : self::$cf->categories)
		];

		foreach ($args as $key => $value) {
			if (empty($value)) {
				unset($args[$key]);
			}
		}

		$products = self::curlQuery( 'products', $args );

		if (!empty($products) && (!empty($args['limit']) || !empty($args['ids']))) {
			unset( $args['limit'], $args['offset'], $args['ids'] );

			$group_id = [ $products[ array_key_first( $products ) ]->group_id ];

			if ( count( $products ) > 1 ) {
				$group_id[] = $products[ array_key_last( $products ) ]->group_id;
			}

			$args['group_id']   = implode( ',', array_unique( $group_id ) );
			$additionalProducts = self::curlQuery( 'products', $args );

			foreach ( $additionalProducts as $additionalProduct ) {
				$neededProduct = Main::searchObject( $products, $additionalProduct->id );

				if ( $neededProduct === false ) {
					$products[] = $additionalProduct;
				}
			}
		}

		return $products;
	}

	/**
	 * Get Stat Products
	 *
	 * @return array|mixed
	 */
	public static function getStatProducts($categories) {
		$args = [
			'showDeleted'	=> 1,
			'not_on_order'	=> self::$cf->is_not_on_order,
			'price_from'	=> self::$cf->price_from,
			'price_to'		=> self::$cf->price_to,
			'rating'		=> self::$cf->rating,
			'moscow'		=> self::$cf->is_wh_moscow,
			'europe'		=> self::$cf->is_wh_europe,
			'remote'		=> self::$cf->is_wh_remote,
			'category'		=> implode(',', empty(self::$cf->categories) ? Main::getOasisMainCategories( $categories ) : self::$cf->categories)
		];
		foreach ($args as $key => $value) {
			if (empty($value)) {
				unset($args[$key]);
			}
		}

		try {
			$result = self::curlQuery( 'stat', $args );
			if ( empty( $result ) ) {
				throw new Exception( 'API error. No stat data.' );
			}
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;
			die();
		}

		return $result;
	}

	/**
	 * Get categories oasis
	 *
	 * @param bool $sleep
	 *
	 * @return array
	 */
	public static function getCategoriesOasis( bool $sleep = true ): array {
		try {
			$result = self::curlQuery( 'categories', [ 'fields' => 'id,parent_id,root,level,slug,name,path' ], $sleep );

			if ( empty( $result ) ) {
				throw new Exception( 'API error. No category data.' );
			}
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;

			return [];
		}

		return $result;
	}

	/**
	 * Get currencies oasis
	 *
	 * @param bool $sleep
	 *
	 * @return array
	 */
	public static function getCurrenciesOasis(bool $sleep = true): array {
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
	 *
	 * @return array|mixed
	 */
	public static function sendOrder( $data ) {
		return self::curlSend( 'reserves/', $data );
	}

	/**
	 * Get order data by queue id
	 *
	 * @param $queueId
	 *
	 * @return array|mixed
	 */
	public static function getOrderByQueueId( $queueId ) {
		return self::curlQuery( 'reserves/by-queue/' . $queueId );
	}

	/**
	 * @param array $data
	 * @param array $params
	 * @return array|mixed
	 */
	public static function brandingCalc($data, $params) {
		return self::curlSend( 'branding/calc', $data, $params);
	}

	/**
	 * @param $id
	 * @param $admin
	 * @return array|mixed
	 */
	public static function getBrandingCoef($id, $admin = false) {
		return self::curlQuery('branding/coef', [
			'id' => $id 
		], false, 'v4');
	}

	/**
	 * Send data by POST method
	 *
	 * @param string $type
	 * @param array $data
	 *
	 * @return array|mixed
	 */
	public static function curlSend( string $type, array $data, array $params = []) {
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
				CURLOPT_POST 			=> 1,
				CURLOPT_POSTFIELDS 		=> http_build_query($data, '', '&'),
				CURLOPT_RETURNTRANSFER	=> true,
				CURLOPT_SSL_VERIFYPEER	=> false,
				CURLOPT_HEADER			=> false,
				CURLOPT_TIMEOUT			=> $params['timeout'] ?? 0
			]);
			$content = curl_exec($ch);

			if ( $content === false ) {
				throw new Exception( 'Error: ' . curl_error( $ch ) );
			} else {
				$result = json_decode( $content );
			}

			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			if ( $http_code === 401 ) {
				throw new Exception( 'Error Unauthorized. Invalid API key!' );
			} elseif ( $http_code != 200 && $http_code != 500 ) {
				throw new Exception( 'Error: ' . ( $result->error ?? '' ) . PHP_EOL . 'Code: ' . $http_code );
			}
		} catch ( Exception $e ) {
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
	 *
	 * @return array|mixed
	 */
	public static function curlQuery($type, array $args = [], bool $sleep = true, string $version = 'v4') {
		if (empty(self::$cf->api_key)){
			return [];
		}

		$args_pref = [
			'key'    => self::$cf->api_key,
			'format' => 'json',
		];
		$args = array_merge($args_pref, $args);

		try {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://api.oasiscatalog.com/'.$version.'/'.$type.'?'. http_build_query($args));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$content = curl_exec($ch);

			if ( $content === false ) {
				throw new Exception('Error: ' . curl_error($ch));
			} else {
				$result = json_decode($content);
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
