<?php

namespace OasisImport\Controller\Oasis;

use Exception;

class Api {

	/**
	 * Get products oasis
	 *
	 * @param array $args
	 * @param $categories
	 *
	 * @return array
	 */
	public static function getOasisProducts( array $args = [], $categories ): array {
		$options = get_option( 'oasis_mi_options' );

		$args += [
			'fieldset'    => 'full',
			'extend'      => 'is_visible',
			'showDeleted' => '1',
		];

		$data = [
			'currency'     => $options['oasis_mi_currency'] ?? 'rub',
			'no_vat'       => $options['oasis_mi_no_vat'] ?? 0,
			'not_on_order' => $options['oasis_mi_not_on_order'] ?? null,
			'price_from'   => $options['oasis_mi_price_from'] ?? null,
			'price_to'     => $options['oasis_mi_price_to'] ?? null,
			'rating'       => $options['oasis_mi_rating'] ?? null,
			'moscow'       => $options['oasis_mi_warehouse_moscow'] ?? null,
			'europe'       => $options['oasis_mi_warehouse_europe'] ?? null,
			'remote'       => $options['oasis_mi_remote_warehouse'] ?? null,
		];

		if ( empty( $options['oasis_mi_categories'] ) ) {
			$categoryIds = Main::getOasisMainCategories( $categories );
		} else {
			$categoryIds = $options['oasis_mi_categories'];
		}

		$args += [
			'category' => implode( ',', $categoryIds ),
		];

		foreach ( $data as $key => $value ) {
			if ( $value ) {
				$args[ $key ] = $value;
			}
		}
		unset( $categoryIds, $category, $data, $key, $value );

		return self::curlQuery( 'products', $args );
	}

	/**
	 * Get Stat Products
	 *
	 * @return array|mixed
	 */
	public static function getStatProducts( $categories ) {
		$options = get_option( 'oasis_mi_options' );
		$args    = [
			'showDeleted' => 1
		];

		$data = [
			'not_on_order' => $options['oasis_mi_not_on_order'] ?? null,
			'price_from'   => $options['oasis_mi_price_from'] ?? null,
			'price_to'     => $options['oasis_mi_price_to'] ?? null,
			'rating'       => ! empty( $options['oasis_mi_rating'] ) ? $options['oasis_mi_rating'] : '0,1,2,3,4,5',
			'moscow'       => $options['oasis_mi_warehouse_moscow'] ?? null,
			'europe'       => $options['oasis_mi_warehouse_europe'] ?? null,
			'remote'       => $options['oasis_mi_remote_warehouse'] ?? null,
		];

		if ( empty( $options['oasis_mi_categories'] ) ) {
			$data['category'] = implode( ',', Main::getOasisMainCategories( $categories ) );
		} else {
			$data['category'] = implode( ',', $options['oasis_mi_categories'] );
		}

		foreach ( $data as $key => $value ) {
			if ( $value ) {
				$args[ $key ] = $value;
			}
		}
		unset( $data, $key, $value );

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
			die();
		}

		return $result;
	}

	/**
	 * Get currencies oasis
	 *
	 * @return array
	 */
	public static function getCurrenciesOasis(): array {
		return self::curlQuery( 'currencies' );
	}

	/**
	 * Get stock oasis
	 *
	 * @return array
	 */
	public static function getStockOasis(): array {
		return self::curlQuery( 'stock', [ 'fields' => 'article,stock,id' ] );
	}

	/**
	 * Export order to Oasiscatalog
	 *
	 * @param $apiKey
	 * @param $data
	 *
	 * @return array|mixed
	 */
	public static function sendOrder( $apiKey, $data ) {
		$result = [];

		try {
			$options = [
				'http' => [
					'method' => 'POST',
					'header' => 'Content-Type: application/json' . PHP_EOL .
					            'Accept: application/json' . PHP_EOL,

					'content' => json_encode( $data ),
				],
			];

			return json_decode( file_get_contents( 'https://api.oasiscatalog.com/v4/reserves/?key=' . $apiKey, 0, stream_context_create( $options ) ) );

		} catch ( \Exception $exception ) {
		}
		unset( $options, $data, $apiKey );

		return $result;
	}

	/**
	 * Get order data by queue id
	 *
	 * @param $queueId
	 *
	 * @return array
	 */
	public static function getOrderByQueueId( $queueId ) {
		return self::curlQuery( 'reserves/by-queue/' . $queueId );
	}

	/**
	 * Get api data
	 *
	 * @param $type
	 * @param array $args
	 * @param bool $sleep
	 *
	 * @return array|mixed
	 */
	public static function curlQuery( $type, array $args = [], bool $sleep = true ) {
		$options = get_option( 'oasis_mi_options' );

		if ( empty( $options['oasis_mi_api_key'] ) ) {
			return [];
		}

		$args_pref = [
			'key'    => $options['oasis_mi_api_key'],
			'format' => 'json',
		];
		$args      = array_merge( $args_pref, $args );

		try {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query( $args ) );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			$content = curl_exec( $ch );

			if ( $content === false ) {
				throw new \Exception( 'Error: ' . curl_error( $ch ) );
			} else {
				$result = json_decode( $content );
			}

			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			if ( $sleep ) {
				sleep( 1 );
			}

			if ( $http_code === 401 ) {
				throw new \Exception( 'Error Unauthorized. Invalid API key!' );
			} elseif ( $http_code != 200 ) {
				throw new \Exception( 'Error. Code: ' . $http_code );
			}

			unset( $content, $options, $args_pref, $args, $type, $ch, $http_code );
		} catch ( \Exception $e ) {
			echo $e->getMessage() . PHP_EOL;
			die();
		}

		return $result;
	}
}