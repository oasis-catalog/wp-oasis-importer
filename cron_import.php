<?php

/** Set up WordPress environment */
require_once( __DIR__ . '/../../../wp-load.php' );
require_once __DIR__ . '/src/Controller/Oasis.php';

use OasisImport\Controller\Oasis\Oasis;

class OasisCron extends Oasis {

	private $cronUp = false;

	public function __construct() {
		$params = [
			'short' => 'k:u',
			'long'  => [ 'key:', 'up' ],
		];

		// Default values
		$errors  = '';
		$options = getopt( $params['short'], $params['long'] );

		if ( isset( $options['key'] ) || isset( $options['k'] ) ) {
			define( 'CRON_KEY', $options['key'] ?? $options['k'] );
		} else {
			$errors = 'key required';
		}

		if ( isset( $options['up'] ) || isset( $options['u'] ) ) {
			$this->cronUp = true;
		}

		if ( $errors ) {
			$help = "
usage:  php " . __DIR__ . "/oasis_cli.php [-k|--key=secret] [-u|--up]

Options:
        -k  --key      substitute your secret key from the Oasis module
        -u  --up       specify this key to use the update
Example import products:
        php " . __DIR__ . "/oasis_cli.php --key=secret
Example update stock (quantity) products:
        php " . __DIR__ . "/oasis_cli.php --key=secret --up

Errors: " . $errors . PHP_EOL;
			die( $help );
		}

		$options = get_option( 'oasis_mi_options' );
		define( 'API_KEY', $options['oasis_mi_api_key'] );

		if ( CRON_KEY !== md5( API_KEY ) ) {
			die( 'Error' );
		}

		$this->doExecute();
	}

	public function doExecute() {
		set_time_limit( 0 );
		ini_set( 'memory_limit', '2G' );

		echo '[' . date( 'c' ) . '] Начало обновления товаров' . PHP_EOL;

		$selectedUserCategory = null;
		if ( isset( $argv[1] ) ) {
			$selectedUserCategory = intval( $argv[1] );
		}

		include_once( __DIR__ . '/functions.php' );

		$options            = get_option( 'oasis_mi_options' );
		$api_key            = $options['oasis_mi_api_key'];
		$selectedCategories = ! empty( $options['oasis_mi_category_map'] ) ? array_filter( $options['oasis_mi_category_map'] ) : [];

		$loopArray = array_values( $selectedCategories );
		if ( $selectedUserCategory ) {
			$loopArray = [ $selectedUserCategory ];
		}

		if ( $api_key && $selectedCategories ) {
			$oasisCategories = get_oasis_categories( $api_key );

			foreach ( $loopArray as $oasisCategory ) {
				$params = [
					'format'   => 'json',
					'fieldset' => 'full',
					'category' => $oasisCategory,
					'no_vat'   => 0,
					'extend'   => 'is_visible',
					'key'      => $api_key,
				];

				$products = json_decode(
					file_get_contents( 'https://api.oasiscatalog.com/v4/products?' . http_build_query( $params ) ),
					true
				);

				$models = [];
				foreach ( $products as $product ) {
					$models[ $product['group_id'] ][ $product['id'] ] = $product;
				}

				$total = count( array_keys( $models ) );
				$count = 0;
				foreach ( $models as $model_id => $model ) {
					echo '[' . date( 'c' ) . '] Начало обработки модели ' . $model_id . PHP_EOL;
					$selectedCategory = [];

					$firstProduct = reset( $model );
					foreach ( $selectedCategories as $k => $v ) {
						if ( in_array( $v, $firstProduct['categories_array'] ) || in_array( $v, $firstProduct['full_categories'] ) ) {
							$selectedCategory[] = $k;
						}
					}
					if ( empty( $selectedCategory ) ) {
						foreach ( $selectedCategories as $k => $v ) {
							$selectedCategory = array_merge( $selectedCategory,
								recursiveCheckCategories( $k, $v, $oasisCategories, $firstProduct['categories_array'] ) );
						}
					}

					upsert_model( $model_id, $model, $selectedCategory, true );
					$count ++;
					echo '[' . date( 'c' ) . '] Done  ' . $count . ' from ' . $total . PHP_EOL;
				}
			}
		}

		echo '[' . date( 'c' ) . '] Окончание обновления товаров' . PHP_EOL;
	}
}

try {
	new OasisCron();
} catch ( Exception $e ) {
}
