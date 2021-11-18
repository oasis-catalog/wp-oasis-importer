<?php

namespace OasisImport\Controller\Oasis;

class Oasis {
	public $options = [];

	public function __construct() {
		$this->options = get_option( 'oasis_mi_options' );
	}

	public static function getCategoriesOasis( array $args = [] ) {
		return Oasis::curl_query( 'categories', $args );
	}

	public static function getCurrenciesOasis( array $args = [] ) {
		return Oasis::curl_query( 'currencies', $args );
	}

	public static function curl_query( $type, array $args = [] ) {
		$options = get_option( 'oasis_mi_options' );

		if ( empty( $options['oasis_mi_api_key'] ) ) {
			return false;
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

		return $http_code === 200 ? $result : false;
	}

	public static function d( $data ) {
		echo '<pre>';
		print_r( $data, false );
		echo '</pre>';
	}
}