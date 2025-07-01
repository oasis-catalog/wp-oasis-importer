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
		'long'  => ['key:', 'site:', 'oid:', 'sku:', 'up', 'up_image', 'add_image', 'debug', 'debug_log'],
	];

	$errors = '';
	$cliOptions = getopt($params['short'], $params['long']);

	if (isset($cliOptions['key']) || isset($cliOptions['k'])) {
		$cron_key = $cliOptions['key'] ?? $cliOptions['k'];
	} else {
		$errors = 'key required';
	}

	if ($errors) {
		die('
usage:  php ' . __DIR__ . '/cron.php [-k|--key=secret] [-u|--up]
Options:
-k  --key      substitute your secret key from the Oasis module
-u  --up       specify this key to use the update
--add_image    add image if empty
--up_image     update only image
--debug        show log
--debug_log    wrire log to file
Example import products:
php ' . __DIR__ . '/cron.php --key=secret
Example update stock (quantity) products:
php ' . __DIR__ . '/cron.php --key=secret --up
Errors: ' . $errors . PHP_EOL);
	}

	$version_php = intval(PHP_MAJOR_VERSION . PHP_MINOR_VERSION);
	if ($version_php < 74) {
		die('Error! Minimum PHP version 7.4, your PHP version ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
	}

	if(isset($cliOptions['site'])){
		switch_to_blog($cliOptions['site']);
	}

	$cron_opt = [
		'oid' => $cliOptions['oid'] ?? '',
		'sku' => $cliOptions['sku'] ?? '',
	];

	if(isset($cliOptions['up']) || isset($cliOptions['u'])){
		$cron_opt['task'] = 'up';
	}
	else if(isset($cliOptions['up_image'])){
		$cron_opt['task'] = 'up_image';
	}
	else if(isset($cliOptions['add_image'])){
		$cron_opt['task'] = 'add_image';
	}
	else {
		$cron_opt['task'] = 'import';
	}

	Cli::RunCron($cron_key, $cron_opt, [
		'debug' => isset($cliOptions['debug']),
		'debug_log' => isset($cliOptions['debug_log'])
	]);
} catch (Exception $e) {
	echo $e->getMessage() . PHP_EOL;
	exit();
}