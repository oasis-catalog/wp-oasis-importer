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
			if (!$cf->checkCronKey($cron_key)) {
				$cf->log('Error! Invalid --key');
				die('Error! Invalid --key');
			}
			self::AddImage([
				'oid' => $cron_opt['oid'] ?? '',
				'sku' => $cron_opt['sku'] ?? '',
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
						self::Import($cron_opt);
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

	public static function Import($opt = []) {
		set_time_limit(0);
		ini_set('memory_limit', '4G');

		try {
			self::$cf->log('Начало обновления товаров');

			Main::prepareAttributeData();
			if(self::$cf->is_brands){
				Main::prepareBrands();
			}

			$args = [];
			if (!empty($opt['oid'])) {
				$args['ids'] = is_array($opt['oid']) ? implode(',', $opt['oid']) : $opt['oid'];
			}
			elseif (!empty($opt['sku'])) {
				$args['ids'] = [];

				$sku_arr = is_array($opt['sku']) ? $opt['sku'] : explode(',', $opt['sku']);
				foreach ($sku_arr as $sku) {
					$post_id = wc_get_product_id_by_sku($sku);
					if (empty($post_id))
						continue;

					$id = Main::getOasisProductIdByPostId($post_id);
					if (empty($id))
						continue;

					$args['ids'][] = $id;
				}
			}
			else {
				$limit   = self::$cf->limit;
				$step    = self::$cf->progress['step'];
				if ($limit > 0 ) {
					$args['limit']  = $limit;
					$args['offset'] = $step * $limit;
				}

				self::$cf->progressOn();
			}

			$cats_oasis =		Api::getCategoriesOasis();
			$products =			Api::getOasisProducts($cats_oasis, $args);
			$stats =			Api::getStatProducts($cats_oasis);

			$group_ids =		[];
			$countProducts =	0;
			foreach ($products as $product) {
				if ($product->size || $product->colors) {
					$group_ids[$product->group_id][$product->id] = $product;
				} else {
					$group_ids[$product->id][$product->id] = $product;
				}
				if ($product->is_deleted) {
					Main::checkDeleteProduct($product->id);
				} else {
					$countProducts ++;
				}
			}

			self::$cf->progressStart($stats->products, $countProducts);

			if (!empty($group_ids)) {
				unset($products, $product, $countProducts);
				$total = count(array_keys($group_ids));
				$count = 0;

				foreach ($group_ids as $group_id => $group) {
					$model = array_filter($group, fn($p) => !$p->is_deleted);
					if (count($model) == 0) {
						continue;
					}

					$is_simple	= count($model) == 1;
					$group_id	= $is_simple ? reset($model)->id : $group_id;

					self::$cf->log('Начало обработки модели ' . $group_id);
					$dbProduct  = Main::checkProductOasisTable(['model_id_oasis' => $group_id], 'product');
					$totalStock = Main::getTotalStock($model);

					if ($is_simple) {
						$product    = reset($model);
						$categories = ($dbProduct && self::$cf->is_not_up_cat) ? [] : Main::getProductCategories($product, $cats_oasis);

						if ($dbProduct) {
							Main::upWcProduct($dbProduct['post_id'], $model, $categories, $totalStock);
						} else {
							Main::addWcProduct($group_id, $product, $model, $categories, $totalStock);
						}
						self::$cf->progressUp();
					}
					else {
						$firstProduct = Main::getFirstProduct($model);
						$categories   = ($dbProduct && self::$cf->is_not_up_cat) ? [] : Main::getProductCategories($firstProduct, $cats_oasis);

						if ($dbProduct) {
							$wcProductId = $dbProduct['post_id'];
							if (!Main::upWcProduct($wcProductId, $model, $categories, $totalStock, true)) {
								continue;
							}
						} else {
							$wcProductId = Main::addWcProduct($group_id, $firstProduct, $model, $categories, $totalStock, true);
							if (!$wcProductId) {
								continue;
							}
						}

						foreach ($model as $variation) {
							$dbVariation = Main::checkProductOasisTable(['product_id_oasis' => $variation->id ], 'product_variation');

							if ($dbVariation) {
								Main::upWcVariation($dbVariation, $variation);
							} else {
								Main::addWcVariation($group_id, $wcProductId, $variation);
							}
							self::$cf->progressUp();
						}
					}
					self::$cf->log('Done ' . ++ $count . ' from ' . $total);
				}
			}
			self::$cf->progressEnd();
			self::$cf->log('Окончание обновления товаров');
		} catch (Exception $exception) {
			echo $exception->getMessage();
			die();
		}
	}

	public static function UpStock()
	{
		set_time_limit(0);
		ini_set('memory_limit', '2G');

		try {
			self::$cf->log('Начало обновления остатков');

			$oasisProducts = [];
			foreach (Main::getOasisDbRows() as $row) {
				if (empty($oasisProducts[$row['product_id_oasis']]) || $row['type'] == 'product_variation') {
					$oasisProducts[$row['product_id_oasis']] = $row['post_id'];
				}
			}

			$stock = [];
			foreach (Api::getStockOasis() as $item) {
				$stock[$item->id] = $item;
			}

			foreach ($oasisProducts as $product_id => $post_id) {
				$stock_item = $stock[$product_id] ?? null;
				if ($stock_item) {
					$val = intval($stock_item->stock) + intval($stock_item->{"stock-remote"});
					update_post_meta($post_id, '_stock', $val);
					update_post_meta($post_id, '_stock_status', $val > 0 ? 'instock' : 'outofstock');
				}
				else {
					self::$cf->log('Удаление OAId=' . $product_id);
					Main::checkDeleteProduct($product_id);
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
			if (!empty($opt['oid'])) {
				$args['ids'] = is_array($opt['oid']) ? implode(',', $opt['oid']) : $opt['oid'];
			}
			if (!empty($opt['sku'])) {
				$args['ids'] = [];

				$sku_arr = is_array($opt['sku']) ? $opt['sku'] : explode(',', $opt['sku']);
				foreach ($sku_arr as $sku) {
					$post_id = wc_get_product_id_by_sku($sku);
					if (empty($post_id))
						continue;

					$id = Main::getOasisProductIdByPostId($post_id);
					if (empty($id))
						continue;

					$args['ids'][] = $id;
				}
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
					$group_id = count($model) > 1 ? $group_id : reset($model)->id;

					self::$cf->log('Начало обработки модели ' . $group_id );

					$dbProduct = Main::checkProductOasisTable(['model_id_oasis' => $group_id], 'product');
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
