<?php

namespace OasisImport\Controller\Oasis;

class Oasis {
	public $options = [];

	public function __construct() {
		$this->options = get_option( 'oasis_mi_options' );
	}

	/**
	 * Get prosucta oasis
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function getOasisProducts( array $args = [] ): array {
		$args['fieldset'] = 'full';

		$data = [
			'currency'         => $this->options['oasis_mi_currency'] ?? 'rub',
			'no_vat'           => $this->options['oasis_mi_no_vat'] ?? 0,
			'not_on_order'     => $this->options['oasis_mi_not_on_order'],
			'price_from'       => $this->options['oasis_mi_price_from'],
			'price_to'         => $this->options['oasis_mi_price_to'],
			'rating'           => $this->options['oasis_mi_rating'],
			'warehouse_moscow' => $this->options['oasis_mi_warehouse_moscow'],
			'warehouse_europe' => $this->options['oasis_mi_warehouse_europe'],
			'remote_warehouse' => $this->options['oasis_mi_remote_warehouse'],
		];

		$categories  = $this->options['oasis_mi_categories'];
		$categoryIds = [];

		if ( ! is_null( $categories ) ) {
			$categoryIds = array_keys( $categories );
		}

		if ( ! count( $categoryIds ) ) {
			$categoryIds = array_keys( Oasis::getOasisMainCategories() );
		}

		$data['category'] = implode( ',', $categoryIds );

		unset( $categoryIds, $category );

		foreach ( $data as $key => $value ) {
			if ( $value ) {
				$args[ $key ] = $value;
			}
		}

		return Oasis::curlQuery( 'products', $args );
	}

	/**
	 * Get categories level 1
	 *
	 * @return array
	 */
	public static function getOasisMainCategories(): array {
		$result     = [];
		$categories = Oasis::getCategoriesOasis();

		foreach ( $categories as $category ) {
			if ( $category->level === 1 ) {
				$result[ $category->id ] = $category->name;
			}
		}

		return $result;
	}

	/**
	 * Get categories oasis
	 *
	 * @return array
	 */
	public static function getCategoriesOasis(): array {
		return Oasis::curlQuery( 'categories', [ 'fields' => 'id,parent_id,root,level,slug,name,path' ] );
	}

	/**
	 * Get currencies oasis
	 *
	 * @return array
	 */
	public static function getCurrenciesOasis(): array {
		return Oasis::curlQuery( 'currencies' );
	}

	/**
	 * Get api data
	 *
	 * @param $type
	 * @param array $args
	 *
	 * @return array
	 */
	public static function curlQuery( $type, array $args = [] ): array {
		$options = get_option( 'oasis_mi_options' );

		if ( empty( $options['oasis_mi_api_key'] ) ) {
			return [];
		}

		$args_pref = [
			'key'    => $options['oasis_mi_api_key'],
			'format' => 'json',
		];
		$args      = array_merge( $args_pref, $args );

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query( $args ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$result    = json_decode( curl_exec( $ch ) );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		return $http_code === 200 ? $result : [];
	}

	/**
	 * Debug func
	 *
	 * @param $data
	 */
	public static function d( $data ) {
		echo '<pre>';
		print_r( $data, false );
		echo '</pre>';
	}
}