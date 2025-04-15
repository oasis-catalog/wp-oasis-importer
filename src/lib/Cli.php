<?php

namespace OasisImport;

use OasisImport\Config as OasisConfig;
use Exception;


class Cli extends Main {
	public static OasisConfig $cf;

	public static function RunCron($cron_key, $cron_up, $opt = [])
	{
		$cf = new OasisConfig($opt);
		$cf->lock(function() use ($cf, $cron_key, $cron_up) {
			$cf->init();
			$cf->initRelation();

			if (!$cf->checkCronKey($cron_key)) {
				$cf->log('Error! Invalid --key');
				die('Error! Invalid --key');
			}

			if (!$cron_up && !$cf->checkPermissionImport()) {
				$cf->log('Import once day');
				die('Import once day');
			}

			if ($cron_up) {
				self::UpStock();
			} else {
				self::Import();
			}
		}, function() use ($cf) {
			$cf->log('Already running');
			die('Already running');
		});
	}

	public static function Import() {
		set_time_limit( 0 );
		ini_set( 'memory_limit', '4G' );

		try {
			self::$cf->log( 'Начало обновления товаров' );

			$args    = [];
			$limit   = self::$cf->limit;
			$step    = self::$cf->progress['step'];
			parent::prepareAttributeData();

			if ($limit > 0 ) {
				$args['limit']  = $limit;
				$args['offset'] = $step * $limit;
			}

			$cats_oasis		= Api::getCategoriesOasis();
			$products		= Api::getOasisProducts( $cats_oasis, $args );
			$stats			= Api::getStatProducts( $cats_oasis );

			$group_ids     = [];
			$countProducts = 0;
			foreach ( $products as $product ) {
				if ( $product->is_deleted === false ) {
					if ( $product->size || $product->colors ) {
						$group_ids[ $product->group_id ][ $product->id ] = $product;
					} else {
						$group_ids[ $product->id ][ $product->id ] = $product;
					}

					$countProducts ++;
				} else {
					parent::checkDeleteProduct( $product->id );
				}
			}

			self::$cf->progressStart($stats->products, $countProducts);

			if ( ! empty( $group_ids ) ) {
				unset( $products, $product, $countProducts );
				$total = count( array_keys( $group_ids ) );
				$count = 0;

				foreach ( $group_ids as $group_id => $model ) {
					self::$cf->log( 'Начало обработки модели ' . $group_id );
					$dbProduct  = parent::checkProductOasisTable( [ 'model_id_oasis' => $group_id ], 'product' );
					$totalStock = parent::getTotalStock( $model );

					if ( count( $model ) === 1 ) {
						$product    = reset( $model );
						$categories = ($dbProduct && self::$cf->is_not_up_cat) ? [] : parent::getProductCategories($product, $cats_oasis);

						if ( $dbProduct ) {
							parent::upWcProduct( $dbProduct['post_id'], $model, $categories, $totalStock );
						} else {
							parent::addWcProduct( $product, $model, $categories, $totalStock, 'simple' );
						}

						self::$cf->progressUp();
					} elseif ( count( $model ) > 1 ) {
						$firstProduct = parent::getFirstProduct( $model );
						$categories   = ($dbProduct && self::$cf->is_not_up_cat) ? [] : parent::getProductCategories( $firstProduct, $cats_oasis);

						if ( $dbProduct ) {
							$wcProductId = $dbProduct['post_id'];
							if ( ! parent::upWcProduct( $wcProductId, $model, $categories, $totalStock ) ) {
								continue;
							}
						} else {
							$wcProductId = parent::addWcProduct( $firstProduct, $model, $categories, $totalStock, 'variable' );
							if ( ! $wcProductId ) {
								continue;
							}
						}

						foreach ( $model as $variation ) {
							$dbVariation = parent::checkProductOasisTable( [ 'product_id_oasis' => $variation->id ], 'product_variation' );

							if ( $dbVariation ) {
								parent::upWcVariation( $dbVariation, $variation );
							} else {
								parent::addWcVariation( $wcProductId, $variation );
							}
							self::$cf->progressUp();
						}
					}
					self::$cf->log( 'Done ' . ++ $count . ' from ' . $total );
				}
			}
			self::$cf->progressEnd();
			self::$cf->log( 'Окончание обновления товаров' );
		} catch ( Exception $exception ) {
			echo $exception->getMessage();
			die();
		}
	}

	public static function UpStock() {
		global $wpdb;

		set_time_limit(0);
		ini_set('memory_limit', '2G');

		try {
			self::$cf->log('Начало обновления остатков');
			$stock         = Api::getStockOasis();
			$dbResults     = $wpdb->get_results("SELECT `post_id`, `product_id_oasis`, `type` FROM {$wpdb->prefix}oasis_products", ARRAY_A);
			$oasisProducts = [];

			foreach ($dbResults as $dbResult) {
				if (empty($oasisProducts[$dbResult['product_id_oasis']]) || $dbResult['type'] == 'product_variation') {
					$oasisProducts[$dbResult['product_id_oasis']] = $dbResult['post_id'];
				}
			}

			foreach ($stock as $item) {
				if (!empty($oasisProducts[$item->id])) {
					$val = intval($item->stock) + intval($item->{"stock-remote"});
					update_post_meta($oasisProducts[$item->id], '_stock', $val);
					update_post_meta($oasisProducts[$item->id], '_stock_status', $val > 0 ? 'instock' : 'outofstock');
				}
			}
			self::$cf->log('Окончание обновления остатков');
		} catch (Exception $exception) {
			die();
		}
	}
}
