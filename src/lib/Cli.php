<?php

namespace OasisImport;

use OasisImport\Config as OasisConfig;
use OasisImport\Main;
use Exception;


class Cli {
	public static OasisConfig $cf;

	public static function Run($cron_key, $cron_opt = [], $opt = [])
	{
		set_time_limit(0);
		ini_set('memory_limit', '2G');

		while (ob_get_level()) {
			ob_end_flush();
		}

		$cf = new OasisConfig($opt);

		if ($cron_opt['task'] == 'repair_image'){
			$cf->init();
			if (!$cf->checkCronKey($cron_key)) {
				$cf->log('Error! Invalid --key');
				die('Error! Invalid --key');
			}
			if ($cf->is_cdn_photo) {
				$cf->log('Error! On CDN photo');
				die('Error! On CDN photo');
			}
			self::RepairImage();
		}
		elseif ($cron_opt['task'] == 'add_image' || $cron_opt['task'] == 'up_image'){
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
		try {
			self::$cf->log('Начало обновления товаров');

			Main::prepareAttributeData();
			Main::prepareCategories();
			if(self::$cf->is_brands){
				Main::prepareBrands();
			}

			$args = [];
			if (!empty($opt['oid'])) {
				$args['ids'] = is_array($opt['oid']) ? implode(',', $opt['oid']) : $opt['oid'];
			}
			elseif (!empty($opt['sku'])) {
				$args['articles'] = is_array($opt['sku']) ? implode(',', $opt['sku']) : $opt['sku'];
			}
			else {
				$args['category'] = implode(',', self::$cf->categories ?: Main::getOasisMainCategories());

				if (self::$cf->limit > 0) {
					$args['limit']  = self::$cf->limit;
					$args['offset'] = self::$cf->limit * self::$cf->progress['step'];
				}
				self::$cf->progressOn();
			}

			$stats     = Api::getStatProducts();
			$groups    = [];
			$totalStep = 0;

			foreach (Api::getOasisProducts($args) as $product) {
				if (empty($product->is_deleted)) {
					if (empty($product->size) && empty($product->colors)) {
						$groups[$product->id][$product->id] = $product;
					} else {
						$groups[$product->group_id][$product->id] = $product;
					}
					$totalStep ++;
				} else {
					Main::checkDeleteProduct($product->id);
				}
			}

			self::$cf->progressStart($stats->products, $totalStep);

			$total = count($groups);
			$count = 0;

			foreach ($groups as $group_id => $products) {
				self::$cf->log('Начало обработки модели ' . $group_id);
				$totalStock = Main::getTotalStock($products);
				$dbGroupProducts = Main::checkGroupProducts($group_id);

				if (count($products) === 1) {
					$product   = reset($products);
					$dbProduct = reset($dbGroupProducts);
					if (count($dbGroupProducts) > 1) {
						Main::deleteProductForPostId(array_map(fn($item) => $item['post_id'], $dbGroupProducts));
						$dbProduct = null;
					}	

					if ($dbProduct) {
						Main::upWcProduct($dbProduct, $product, $products, $totalStock);
						self::$cf->log('Обновлен товар OAId='.$product->id.', WPId=' . $dbProduct['post_id']);
					} else {
						Main::addWcProduct($group_id, $product, $products, $totalStock);
						self::$cf->log('Добавлен товар id '.$product->id);
					}
					self::$cf->progressUp();
				}
				else {
					if (count($dbGroupProducts) == 1) {
						Main::deleteProductForPostId(array_map(fn($item) => $item['post_id'], $dbGroupProducts));
					}
					$product = reset($products);
					$dbProduct = Main::checkProduct($product->id, 'product');

					if ($dbProduct) {
						$wcProductId = $dbProduct['post_id'];
						Main::upWcProduct($dbProduct, $product, $products, $totalStock, true);
						self::$cf->log('Обновлен товар OAId='.$product->id.', WPId=' . $wcProductId);
					} else {
						Main::checkDeleteGroup($group_id);
						$wcProductId = Main::addWcProduct($group_id, $product, $products, $totalStock, true);
						if (!$wcProductId) {
							continue;
						}
						self::$cf->log('Добавлен товар id '.$product->id);
					}

					foreach ($products as $variation) {
						$dbVariation = Main::checkProduct($variation->id, 'product_variation');

						if ($dbVariation) {
							Main::upWcVariation($dbVariation, $variation);
							self::$cf->log(' - обновлен вариант OAId=' . $variation->id . ', WPId=' . $dbVariation['post_id']);
						} else {
							Main::addWcVariation($group_id, $wcProductId, $variation);
							self::$cf->log(' - добавлен вариант id ' . $variation->id);
						}
						self::$cf->progressUp();
					}
				}
				self::$cf->log('Done ' . ++$count . ' from ' . $total);
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
		try {
			self::$cf->log('Начало обновления остатков');

			$oasisProducts = [];

			foreach (Main::getOasisDbRows() as $row) {
				if (empty($oasisProducts[$row['product_id']]) || $row['type'] == 'product_variation') {
					$oasisProducts[$row['product_id']] = $row['post_id'];
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
		try {
			self::$cf->log('Начало обновления картинок товаров');

			$args = [
				'fields' => 'id,images',
			];
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

			$is_up    = !empty($opt['is_up']);
			$products = Api::getOasisProducts($args);
			$total    = count($products);
			$count    = 0;

			foreach ($products as $product) {
				foreach (Main::checkProducts($product->id) as $dbProduct) {
					if ($dbProduct['type'] == 'product_variation') {
						Main::wcVariationAddImage($dbProduct['post_id'], $product, $is_up);
					}
					else {
						Main::wcProductAddImage($dbProduct['post_id'], $product, $is_up);
					}
				}
				self::$cf->log('Выполнено ' . ++$count . ' из ' . $total . ' OAId=' . $product->id);
			}
			self::$cf->log('Окончание обновления картинок товаров');
		} catch (Exception $exception) {
			echo $exception->getMessage();
			die();
		}
	}

	public static function RepairImage()
	{
		self::$cf->log('Начало восстановления картинок');
		$products = Api::curlQuery('products', [
			'fields' => 'images',
			'showDeleted' => '1',
		]);
		$images = [];
		foreach ($products as $product) {
			foreach (($product->images ?? []) as $image) {
				if (!isset($image->superbig)) {
					continue;
				}
				$images[basename($image->superbig)] = $image->superbig;
			}
		}

		$post_ids = array_map(fn($row) => $row['post_id'], Main::getOasisDbRows());

		foreach (Main::getImagesForPostIds($post_ids) as $post_id => $attachments) {
			foreach ($attachments as $attachment_id) {
				$file_path = get_attached_file($attachment_id);
				if (empty($file_path)) {
					self::$cf->log('Путь к файлу не известен attachment_id: ' . $attachment_id);
					wp_delete_attachment($attachment_id, true);
					continue;
				}
				
				if (!file_exists($file_path)) {
					self::$cf->log('Файл не найден attachment_id: ' . $attachment_id);
					self::$cf->log(' - post_id: '. $post_id);
					self::$cf->log(' - path: '. $file_path);
					self::$cf->log(' - url: '. wp_get_attachment_url($attachment_id));

					$server_url = $images[basename($file_path)] ?? null;
					if ($server_url) {
						$file_dir = dirname($file_path);
						wp_mkdir_p($file_dir);
						$image_data = file_get_contents($server_url);
						if ($image_data === false){
							self::$cf->log('Error, get_contents False');
							continue;
						}
						if(file_put_contents($file_path, $image_data)) {;
							$metadata = wp_get_attachment_metadata($attachment_id);
							// Удаляем все файлы миниатюр
							if (isset($metadata['sizes'])) {
								foreach ($metadata['sizes'] as $size => $size_info) {
									$_thumb_path = $file_dir . '/' . $size_info['file'];
									if (file_exists($_thumb_path)) {
										self::$cf->log(' - удаляем миниатюру: ' . $_thumb_path);
										unlink($_thumb_path);
									}
								}
								$metadata['sizes'] = array();
								wp_update_attachment_metadata($attachment_id, $metadata);
							}
							self::$cf->log(' - ok');
						}
						else {
							self::$cf->log('Error, ошибка записи в файл');
						}
					}
					else {
						self::$cf->log('Error, нет картинки на сервере');
					}
				}
			}
		}
		self::$cf->log('Окончание восстановления картинок');
	}
}
