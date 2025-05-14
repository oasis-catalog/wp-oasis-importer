<?php

namespace OasisImport;

use OasisImport\Config as OasisConfig;
use OasisImport\Main;
use Exception;


class Cli {
	public static OasisConfig $cf;

	public static function RunCron($cron_key, $cron_opt = [], $opt = [])
	{
		$cf = new OasisConfig($opt);

		if($cron_opt['task'] == 'add_image' || $cron_opt['task'] == 'up_image'){
			$cf->init();
			self::AddImage([
				'oid' => $cron_opt['oid'] ?? '',
				'is_up' => $cron_opt['task'] == 'up_image'
			]);
		}
		else {
			$cf->lock(function() use ($cf, $cron_key, $cron_opt) {
				$cf->init();

				if (!$cf->checkCronKey($cron_key)) {
					$cf->log('Error! Invalid --key');
					die('Error! Invalid --key');
				}

				switch ($cron_opt['task']) {
					case 'import':
						$cf->initRelation();

						if(!$cf->checkPermissionImport()) {
							$cf->log('Import once day');
							die('Import once day');
						}
						self::Import();
						break;

					case 'up':
						self::UpStock();
						break;
				}
			}, function() use ($cf) {
				$cf->log('Already running');
				die('Already running');
			});
		}
	}

	public static function Import() {
		set_time_limit(0);
		ini_set('memory_limit', '4G');

		try {
			self::$cf->log( 'Начало обновления товаров' );

			Main::prepareAttributeData();
			if(self::$cf->is_brands){
				Main::prepareBrands();
			}

			$args    = [];
			$limit   = self::$cf->limit;
			$step    = self::$cf->progress['step'];
			if ($limit > 0 ) {
				$args['limit']  = $limit;
				$args['offset'] = $step * $limit;
			}

			$cats_oasis =		Api::getCategoriesOasis();
			$products =			Api::getOasisProducts( $cats_oasis, $args );
			$stats =			Api::getStatProducts( $cats_oasis );

			$group_ids =		[];
			$countProducts =	0;
			foreach ( $products as $product ) {
				if ( $product->is_deleted === false ) {
					if ( $product->size || $product->colors ) {
						$group_ids[ $product->group_id ][ $product->id ] = $product;
					} else {
						$group_ids[ $product->id ][ $product->id ] = $product;
					}

					$countProducts ++;
				} else {
					Main::checkDeleteProduct( $product->id );
				}
			}

			self::$cf->progressStart($stats->products, $countProducts);

			if ( ! empty( $group_ids ) ) {
				unset( $products, $product, $countProducts );
				$total = count( array_keys( $group_ids ) );
				$count = 0;

				foreach ( $group_ids as $group_id => $model ) {
					self::$cf->log( 'Начало обработки модели ' . $group_id );
					$dbProduct  = Main::checkProductOasisTable( [ 'model_id_oasis' => $group_id ], 'product' );
					$totalStock = Main::getTotalStock( $model );

					if ( count( $model ) === 1 ) {
						$product    = reset( $model );
						$categories = ($dbProduct && self::$cf->is_not_up_cat) ? [] : Main::getProductCategories($product, $cats_oasis);

						if ( $dbProduct ) {
							Main::upWcProduct( $dbProduct['post_id'], $model, $categories, $totalStock );
						} else {
							Main::addWcProduct( $product, $model, $categories, $totalStock, 'simple' );
						}

						self::$cf->progressUp();
					} elseif ( count( $model ) > 1 ) {
						$firstProduct = Main::getFirstProduct( $model );
						$categories   = ($dbProduct && self::$cf->is_not_up_cat) ? [] : Main::getProductCategories( $firstProduct, $cats_oasis);

						if ( $dbProduct ) {
							$wcProductId = $dbProduct['post_id'];
							if ( ! Main::upWcProduct( $wcProductId, $model, $categories, $totalStock ) ) {
								continue;
							}
						} else {
							$wcProductId = Main::addWcProduct( $firstProduct, $model, $categories, $totalStock, 'variable' );
							if ( ! $wcProductId ) {
								continue;
							}
						}

						foreach ( $model as $variation ) {
							$dbVariation = Main::checkProductOasisTable( [ 'product_id_oasis' => $variation->id ], 'product_variation' );

							if ( $dbVariation ) {
								Main::upWcVariation( $dbVariation, $variation );
							} else {
								Main::addWcVariation( $wcProductId, $variation );
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

	public static function AddImage($opt = []) {
		set_time_limit(0);
		ini_set('memory_limit', '4G');

		try {
			self::$cf->log('Начало обновления картинок товаров');

			$args = [];
			if(!empty($opt['oid'])){
				$args['ids'] =  is_array($opt['oid']) ? implode(',', $opt['oid']) : $opt['oid'];
			}

			$cats_oasis =	Api::getCategoriesOasis();
			$products =		Api::getOasisProducts($cats_oasis, $args);
			$group_ids =	[];
			foreach ( $products as $product ) {
				if ( $product->is_deleted === false ) {
					if ( $product->size || $product->colors ) {
						$group_ids[ $product->group_id ][ $product->id ] = $product;
					} else {
						$group_ids[ $product->id ][ $product->id ] = $product;
					}
				}
			}

			if (!empty($group_ids)) {
				$total = count(array_keys($group_ids));
				$count = 0;

				$is_up = !empty($opt['is_up']);

				foreach ($group_ids as $group_id => $model) {
					$count++;

					self::$cf->log('Начало обработки модели ' . $group_id );
					$dbProduct = Main::checkProductOasisTable([ 'model_id_oasis' => $group_id ], 'product');

					if(empty($dbProduct)){
						self::$cf->log('Выполнено ' . $count . ' из ' . $total . '. Модель не добавлена');
						continue;
					}

					if (count($model) === 1) {
						$product = reset($model);
						Main::wcProductAddImage($dbProduct['post_id'], $model, $is_up);
					}
					else if (count($model) > 1) {
						if (!Main::wcProductAddImage($dbProduct['post_id'], $model, $is_up)) {
							continue;
						}

						foreach ($model as $variation) {
							$dbVariation = Main::checkProductOasisTable(['product_id_oasis' => $variation->id], 'product_variation');

							if(empty($dbVariation)){
								self::$cf->log('Вариант не добавлен');
								continue;
							}
							else {
								Main::wcVariationAddImage($dbVariation, $variation, $is_up);
								self::$cf->log(' - обновлен вариант WPId=' . $dbVariation['post_id']);
							}
						}
					}
					self::$cf->log('Выполнено ' . $count . ' из ' . $total . '. WPId=' . $dbProduct['post_id']);
				}
			}
			self::$cf->log('Окончание обновления картинок товаров');
		} catch ( Exception $exception ) {
			echo $exception->getMessage();
			die();
		}
	}
}
