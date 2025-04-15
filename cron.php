<?php

/** Set up WordPress environment */
require_once( __DIR__ . '/../../../wp-load.php' );
require_once( __DIR__ . '/src/lib/Api.php' );
require_once( __DIR__ . '/src/lib/Main.php' );
require_once( __DIR__ . '/src/lib/Config.php' );
require_once( __DIR__ . '/src/lib/Cli.php' );

use OasisImport\Cli;


try {
	$params = [
		'short' => 'k:u',
		'long'  => ['key:', 'site:', 'up', 'debug', 'debug_log'],
	];

	$errors = '';
	$cliOptions = getopt($params['short'], $params['long']);

	if (isset($cliOptions['key']) || isset($cliOptions['k'])) {
		$cron_key = $cliOptions['key'] ?? $cliOptions['k'];
	} else {
		$errors = 'key required';
	}

	$cron_up = false;
	if (isset($cliOptions['up']) || isset($cliOptions['u'])) {
		$cron_up = true;
	}

	if ($errors) {
		die('
usage:  php ' . __DIR__ . '/cron.php [-k|--key=secret] [-u|--up]
Options:
-k  --key      substitute your secret key from the Oasis module
-u  --up       specify this key to use the update
Example import products:
php ' . __DIR__ . '/cron.php --key=secret
Example update stock (quantity) products:
php ' . __DIR__ . '/cron.php --key=secret --up
Errors: ' . $errors . PHP_EOL);
	}

	if(isset($cliOptions['site'])){
		switch_to_blog($cliOptions['site']);
	}

	$version_php = intval(PHP_MAJOR_VERSION . PHP_MINOR_VERSION);
	if ($version_php < 74) {
		die('Error! Minimum PHP version 7.4, your PHP version ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
	}

	Cli::RunCron($cron_key, $cron_up, [
		'debug' => isset($cliOptions['debug']),
		'debug_log' => isset($cliOptions['debug_log'])
	]);
} catch (Exception $e) {
	echo $e->getMessage() . PHP_EOL;
	exit();
}