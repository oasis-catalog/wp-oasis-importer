<?php

/** Set up WordPress environment */
require_once( __DIR__ . '/../../../wp-load.php' );
require_once( __DIR__ . '/src/Controller/Oasis.php' );

use OasisImport\Controller\Oasis\Oasis;

class OasisCron extends Oasis {

	private $cronUp = false;

	public function __construct() {
		$params = [
			'short' => 'k:u',
			'long'  => [ 'key:', 'up' ],
		];

		$errors     = '';
		$cliOptions = getopt( $params['short'], $params['long'] );

		if ( isset( $cliOptions['key'] ) || isset( $cliOptions['k'] ) ) {
			define( 'CRON_KEY', $cliOptions['key'] ?? $cliOptions['k'] );
		} else {
			$errors = 'key required';
		}

		if ( isset( $cliOptions['up'] ) || isset( $cliOptions['u'] ) ) {
			$this->cronUp = true;
		}

		if ( $errors ) {
			$help = '
usage:  php ' . __DIR__ . '/cron_import.php [-k|--key=secret] [-u|--up]

Options:
        -k  --key      substitute your secret key from the Oasis module
        -u  --up       specify this key to use the update
Example import products:
        php ' . __DIR__ . '/cron_import.php --key=secret
Example update stock (quantity) products:
        php ' . __DIR__ . '/cron_import.php --key=secret --up

Errors: ' . $errors . PHP_EOL;
			die( $help );
		}

		parent::__construct();

		define( 'API_KEY', $this->options['oasis_mi_api_key'] ?? '' );

		if ( CRON_KEY !== md5( API_KEY ) ) {
			die( 'Error! Invalid --key' );
		}

		$version_php = intval( PHP_MAJOR_VERSION . PHP_MINOR_VERSION );

		if ( $version_php < 73 ) {
			die( 'Error! Minimum PHP version 7.3, your PHP version ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
		}

		$this->doExecute();
	}

	public function doExecute() {
		if ( $this->cronUp ) {
			$this->cronUpStock();
		} else {
			$this->cronUpProduct();
		}
	}

	public function cronUpProduct() {
		set_time_limit( 0 );
		ini_set( 'memory_limit', '2G' );

		echo '[' . date( 'Y-m-d H:i:s' ) . '] Начало обновления товаров' . PHP_EOL;

		include_once( __DIR__ . '/functions.php' );

		$args    = [];
		$options = get_option( 'oasis_mi_options' );
		$limit   = isset( $options['oasis_mi_limit'] ) ? (int) $options['oasis_mi_limit'] : null;
		$step    = (int) get_option( 'oasis_step' );

		if ( $limit > 0 ) {
			$args['limit']  = $limit;
			$args['offset'] = $step * $limit;
		}

		$products   = $this->getOasisProducts( $args );
		$categories = Oasis::getCategoriesOasis();

		if ( $products ) {
			$nextStep = ++ $step;
		} else {
			$nextStep = 0;
		}

		$group_ids = [];
		foreach ( $products as $product ) {
			$group_ids[ $product->group_id ][ $product->id ] = $product;
		}
		unset( $products );

		$total      = count( array_keys( $group_ids ) );
		$count      = 0;
		$time_start = microtime( true );

		update_option( 'oasis_total_model', $total );
		foreach ( $group_ids as $group_id => $model ) {
			echo '[' . date( 'Y-m-d H:i:s' ) . '] Начало обработки модели ' . $group_id . PHP_EOL;
			upsert_model( $group_id, $model, $categories, $options['oasis_mi_price_factor'] ?? '', $options['oasis_mi_increase'] ?? '', $options['oasis_mi_dealer'] ?? '' );
			$count ++;
			echo '[' . date( 'Y-m-d H:i:s' ) . '] Done ' . $count . ' from ' . $total . PHP_EOL;
			update_option( 'oasis_item_model', $count );
		}
		unset( $group_ids );

		if ( ! empty( $limit ) ) {
			update_option( 'oasis_step', $nextStep );
		}

		echo '[' . date( 'Y-m-d H:i:s' ) . '] Окончание обновления товаров' . PHP_EOL;

		$time_end = microtime( true );
		update_option( 'oasis_import_time', ( $time_end - $time_start ) );
		up_currencies_categories( false, $categories );
	}

	public function cronUpStock() {
		try {
			include_once( __DIR__ . '/functions.php' );

			set_time_limit( 0 );
			ini_set( 'memory_limit', '2G' );

			upStock();
		} catch ( \Exception $exception ) {
			die();
		}
	}
}

try {
	new OasisCron();
} catch ( Exception $e ) {
}
