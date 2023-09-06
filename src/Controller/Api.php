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
		$options = get_option( 'oasis_options' );

		$args += [
			'fieldset'    => 'full',
			'extend'      => 'is_visible',
			'showDeleted' => '1',
		];

		$data = [
			'currency'     => $options['oasis_currency'] ?? 'rub',
			'no_vat'       => $options['oasis_no_vat'] ?? 0,
			'not_on_order' => $options['oasis_not_on_order'] ?? null,
			'price_from'   => $options['oasis_price_from'] ?? null,
			'price_to'     => $options['oasis_price_to'] ?? null,
			'rating'       => $options['oasis_rating'] ?? null,
			'moscow'       => $options['oasis_warehouse_moscow'] ?? null,
			'europe'       => $options['oasis_warehouse_europe'] ?? null,
			'remote'       => $options['oasis_remote_warehouse'] ?? null,
		];

		if ( empty( $options['oasis_categories'] ) ) {
			$categoryIds = Main::getOasisMainCategories( $categories );
		} else {
			$categoryIds = $options['oasis_categories'];
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

		$products = self::curlQuery( 'products', $args );

		if ( ! empty( $products ) && ! empty( $args['limit'] ) ) {
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
	public static function getStatProducts( $categories ) {
		$options = get_option( 'oasis_options' );
		$args    = [
			'showDeleted' => 1
		];

		$data = [
			'not_on_order' => $options['oasis_not_on_order'] ?? null,
			'price_from'   => $options['oasis_price_from'] ?? null,
			'price_to'     => $options['oasis_price_to'] ?? null,
			'rating'       => ! empty( $options['oasis_rating'] ) ? $options['oasis_rating'] : '0,1,2,3,4,5',
			'moscow'       => $options['oasis_warehouse_moscow'] ?? null,
			'europe'       => $options['oasis_warehouse_europe'] ?? null,
			'remote'       => $options['oasis_remote_warehouse'] ?? null,
		];

		if ( empty( $options['oasis_categories'] ) ) {
			$data['category'] = implode( ',', Main::getOasisMainCategories( $categories ) );
		} else {
			$data['category'] = implode( ',', $options['oasis_categories'] );
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
	public static function getCurrenciesOasis( bool $sleep = true ): array {
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

	public static function getBranding( $params ) {
		return self::curlSend( 'branding/calc', $params );
	}

	/**
	 * Send data by POST method
	 *
	 * @param string $type
	 * @param array $data
	 *
	 * @return array|mixed
	 */
	public static function curlSend( string $type, array $data ) {
		$options = get_option( 'oasis_options' );

		if ( empty( $options['oasis_api_key'] ) ) {
			return [];
		}

		$args_pref = [
			'key'    => $options['oasis_api_key'],
			'format' => 'json',
		];

		try {
			$ch = curl_init( 'https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query( $args_pref ) );
			curl_setopt( $ch, CURLOPT_POST, 1 );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $data, '', '&' ) );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $ch, CURLOPT_HEADER, false );
			$content = curl_exec( $ch );

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
			echo $e->getMessage() . PHP_EOL;

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
	 *
	 * @return array|mixed
	 */
	public static function curlQuery( $type, array $args = [], bool $sleep = true ) {
		$options = get_option( 'oasis_options' );

		if ( empty( $options['oasis_api_key'] ) ) {
			return [];
		}

		$args_pref = [
			'key'    => $options['oasis_api_key'],
			'format' => 'json',
		];
		$args      = array_merge( $args_pref, $args );

		try {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query( $args ) );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			$content = curl_exec( $ch );

			if ( $content === false ) {
				throw new Exception( 'Error: ' . curl_error( $ch ) );
			} else {
				$result = json_decode( $content );
			}

			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );

			if ( $sleep ) {
				sleep( 1 );
			}

			if ( $http_code === 401 ) {
				throw new Exception( 'Error Unauthorized. Invalid API key!' );
			} elseif ( $http_code != 200 ) {
				throw new Exception( 'Error. Code: ' . $http_code );
			}

			unset( $content, $options, $args_pref, $args, $type, $ch, $http_code );
		} catch ( Exception $e ) {
			echo $e->getMessage() . PHP_EOL;

			return [];
		}

		return $result;
	}
}
