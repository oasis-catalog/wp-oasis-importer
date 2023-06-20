<?php

/** Set up WordPress environment */
require_once( __DIR__ . '/../../../wp-load.php' );
require_once( __DIR__ . '/src/Controller/Cli.php' );

use OasisImport\Controller\Oasis\Cli;
use OasisImport\Controller\Oasis\Main;

class StartCli {

	private bool $cronUp = false;

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
usage:  php ' . __DIR__ . '/cli.php [-k|--key=secret] [-u|--up]

Options:
        -k  --key      substitute your secret key from the Oasis module
        -u  --up       specify this key to use the update
Example import products:
        php ' . __DIR__ . '/cli.php --key=secret
Example update stock (quantity) products:
        php ' . __DIR__ . '/cli.php --key=secret --up

Errors: ' . $errors . PHP_EOL;
			die( $help );
		}

		$options = get_option( 'oasis_options' );
		define( 'API_KEY', $options['oasis_api_key'] ?? '' );

		if ( CRON_KEY !== md5( API_KEY ) ) {
			die( 'Error! Invalid --key' );
		}

		$version_php = intval( PHP_MAJOR_VERSION . PHP_MINOR_VERSION );

		if ( $version_php < 74 ) {
			die( 'Error! Minimum PHP version 7.4, your PHP version ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
		}

		$this->doExecute();
	}

	public function doExecute() {
		try {
			$lock = fopen( Main::getFileNameLock(), 'w' );
			if ( ! ( $lock && flock( $lock, LOCK_EX | LOCK_NB ) ) ) {
				throw new Exception( 'Already running' );
			}

			if ( $this->cronUp ) {
				Cli::upStock();
			} else {
				Cli::import();
			}

		} catch ( Exception $e ) {
			echo $e->getMessage() . PHP_EOL;
			exit();
		}
	}
}

try {
	new StartCli();
} catch ( Exception $e ) {
}
