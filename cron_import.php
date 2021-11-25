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

		parent::__construct();

		define( 'API_KEY', $this->options['oasis_mi_api_key'] );

		if ( CRON_KEY !== md5( API_KEY ) ) {
			die( 'Error' );
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

		echo '[' . date( 'c' ) . '] Начало обновления товаров' . PHP_EOL;

		include_once( __DIR__ . '/functions.php' );

		$products   = $this->getOasisProducts();
		$categories = Oasis::getCategoriesOasis();

		$group_ids = [];
		foreach ( $products as $product ) {
			$group_ids[ $product->group_id ][ $product->id ] = $product;
		}

		$total = count( array_keys( $group_ids ) );
		$count = 0;

		foreach ( $group_ids as $group_id => $model ) {
			echo '[' . date( 'c' ) . '] Начало обработки модели ' . $group_id . PHP_EOL;
			upsert_model( $group_id, $model, $categories);
			$count ++;
			echo '[' . date( 'c' ) . '] Done  ' . $count . ' from ' . $total . PHP_EOL;
		}

		echo '[' . date( 'c' ) . '] Окончание обновления товаров' . PHP_EOL;
	}

	public function cronUpStock() {
		try {
			include_once( __DIR__ . '/functions.php' );

			set_time_limit( 0 );
			ini_set( 'memory_limit', '2G' );
			$stock = Oasis::getStockOasis();

			foreach ( $stock as $item ) {
				upStock( $item );
			}
			unset( $item );
		} catch ( \Exception $exception ) {
			die();
		}
	}
}

try {
	new OasisCron();
} catch ( Exception $e ) {
}
