<?php

namespace OasiscatalogImporter;

use OasiscatalogImporter\Config as OasisConfig;
use Exception;
use WC_Cache_Helper;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Post;
use WP_Query;

class Main
{
	public const ATTR_COLOR_ID    = 1000000001; // Цвет товара
	public const ATTR_MATERIAL_ID = 1000000002; // Материал товара
	public const ATTR_BRANDING_ID = 1000000008; // Метод нанесения
	public const ATTR_BARCODE_ID  = 1000000011; // Штрихкод
	public const ATTR_GENDER_ID   = 65;        	// Пол
	public const ATTR_FLASH_ID    = 219;        // Объем памяти
	public const ATTR_MARKING_ID  = 254;        // Обязательная маркировка
	public const ATTR_REMOTE_ID   = 310;        // Минимальная сумма для удалённого склада
	public const ATTR_SIZE_NAME   = 'Размер';


	public static OasisConfig $cf;
	public static $attrVariation = [];
	public static $brands = [];

	private static array $oasisCategories;

	/**
	 * Prepare attributes for variations
	 */
	public static function prepareAttributeData()
	{
		$attr_names = [
			'color'		=> 'Цвет',
			'size'		=> 'Размер'
		];
		$attribute_labels = wp_list_pluck(wc_get_attribute_taxonomies(), 'attribute_label', 'attribute_name');

		foreach ($attr_names as $key => $name) {
			$attribute_name = array_search($name, $attribute_labels, true);

			if ($attribute_name === false) {
				$attribute_taxonomy = self::createAttribute($name, []);
				$attribute_name     = str_replace('pa_', '', $attribute_taxonomy['attribute_taxonomy']);
			}

			self::$attrVariation[$key] = [
				'name' => $name,
				'slug' => $attribute_name,
			];
		}
	}

	/**
	 * Load and prepare brands
	 *
	 * @return void
	 */
	public static function prepareBrands(): void
	{
		$brands	= Api::getBrands() ?? [];
		self::$brands = [];
		foreach ($brands as $brand){
			self::$brands[$brand->id] = [
				'name' => $brand->name,
				'slug' => $brand->slug,
				'logotype' => $brand->logotype,
				'term_id' => null
			];
		}
	}

	/**
	 * Load categories oasis
	 */
	public static function prepareCategories()
	{
		self::$oasisCategories = Api::getCategoriesOasis();
	}

	/**
	 * @param $productId
	 * @param int $type
	 * @return array
	 */
	public static function checkProduct($productId, string $type = '')
	{
		$products = self::checkProducts($productId, $type);
		return reset($products);
	}

	/**
	 * Check products
	 * @param $productId
	 * @param $productId
	 * @param string $type
	 * @return array
	 */
	public static function checkProducts($productId, string $type = '')
	{
		global $wpdb;
		$sql = "SELECT DISTINCT p.ID as post_id, p.post_type as type, pm_product.meta_value as product_id, pm_group.meta_value as group_id, pm_updated.meta_value as updated_at
				FROM {$wpdb->prefix}posts p
				INNER JOIN {$wpdb->prefix}postmeta pm_product ON p.ID = pm_product.post_id AND pm_product.meta_key = '_oasis_product'
				INNER JOIN {$wpdb->prefix}postmeta pm_group ON p.ID = pm_group.post_id AND pm_group.meta_key = '_oasis_group'
				INNER JOIN {$wpdb->prefix}postmeta pm_updated ON p.ID = pm_updated.post_id AND pm_updated.meta_key = '_oasis_updated'
				WHERE pm_product.meta_value = %s";
		$values = [$productId];

		if (!empty($type)) {
			$sql .= ' AND p.post_type = %s';
			$values[] = $type;
		}

		return $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
	}

	/**
	 * Check products for oasis group_id
	 * @param $group_id
	 * @return array
	 */
	public static function checkGroupProducts($group_id)
	{
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare(
			"SELECT DISTINCT p.ID as post_id, p.post_type as type, pm_product.meta_value as product_id, pm_group.meta_value as group_id, pm_updated.meta_value as updated_at
			FROM {$wpdb->prefix}posts p
			INNER JOIN {$wpdb->prefix}postmeta pm_product ON p.ID = pm_product.post_id AND pm_product.meta_key = '_oasis_product'
			INNER JOIN {$wpdb->prefix}postmeta pm_group ON p.ID = pm_group.post_id AND pm_group.meta_key = '_oasis_group'
			INNER JOIN {$wpdb->prefix}postmeta pm_updated ON p.ID = pm_updated.post_id AND pm_updated.meta_key = '_oasis_updated'
			WHERE pm_group.meta_value = %s", [$group_id]
		), ARRAY_A);
	}

	/**
	 * Add WooCommerce product
	 * @param $group_id
	 * @param $product
	 * @param $products
	 * @param $totalStock
	 * @param bool $is_variable
	 * @return int
	 */
	public static function addWcProduct($group_id, $product, $products, $totalStock, bool $is_variable = false)
	{
		$wcProduct = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();

		$product_name = self::$cf->is_without_quotes ? self::removeQuotes($product->name) : $product->name;
		$wcProduct->set_props([
			'name'            => $product_name,
			'description'     => self::preparePostContent($product),
			'category_ids'    => self::getProductCategories($product),
			'slug'            => self::getUniquePostName($product_name, 'product'),
			'manage_stock'    => true,
			'status'          => self::getProductStatus($product, $totalStock),
			'stock_quantity'  => $totalStock,
			'backorders'      => $product->rating === 5 ? 'yes' : 'no',
			'attributes'      => self::prepareProductAttributes($product, $products, $is_variable),
			'reviews_allowed' => self::$cf->is_comments,
		]);

		$defaultAttr = self::getProductDefaultAttributes($product->id, $products);
		if ($defaultAttr) {
			$wcProduct->set_default_attributes($defaultAttr);
		}

		if (!$is_variable) {
			$dataPrice = self::getDataPrice($product);
			$wcProduct->set_props([
				'sku'           => $product->article,
				'price'         => $dataPrice['price'],
				'regular_price' => $dataPrice['regular_price'],
				'sale_price'    => $dataPrice['sale_price'],
			]);
		}

		$wcProduct->add_meta_data('_oasis_product', $product->id);
		$wcProduct->add_meta_data('_oasis_group', $group_id);
		$wcProduct->add_meta_data('_oasis_updated', $product->updated_at);

		$wcProductId = $wcProduct->save();
		if(!self::$cf->is_fast_import){
			$images = self::processingPhoto($product->images, $wcProductId);
			$wcProduct->set_image_id(array_shift($images));
			$wcProduct->set_gallery_image_ids($images);	
			$wcProduct->save();
		}

		self::updateWcProductBrand($wcProductId, $product);
		return $wcProductId;
	}

	/**
	 * Up product
	 *
	 * @param $wcProductId
	 * @param $product
	 * @param $products
	 * @param $totalStock
	 * @param bool $is_variable
	 */
	public static function upWcProduct($dbProduct, $product, $products, $totalStock, bool $is_variable = false)
	{
		$wcProduct = wc_get_product($dbProduct['post_id']);
		$need_save = false;

		if (self::getNeedUp($dbProduct, $product)) {
			$wcProduct->set_props([
				'name'            => self::$cf->is_without_quotes ? self::removeQuotes($product->name) : $product->name,
				'manage_stock'    => true,
				'backorders'      => $product->rating === 5 ? 'yes' : 'no',
				'description'     => self::preparePostContent($product),
				'attributes'      => self::prepareProductAttributes($product, $products, $is_variable),
				'reviews_allowed' => self::$cf->is_comments,
			]);

			$defaultAttr = self::getProductDefaultAttributes($product->id, $products);
			if ($defaultAttr) {
				$wcProduct->set_default_attributes($defaultAttr);
			}
			$need_save = true;
		}

		$old_data = $wcProduct->get_data();

		if (!self::$cf->is_not_up_cat) {
			$categories = self::getProductCategories($product);
			if (!self::compareIsEqualArray($categories, $old_data['category_ids'])) {
				$wcProduct->set_category_ids($categories);
				$need_save = true;
			}
		}

		$up_data = [
			['status', self::getProductStatus($product, $totalStock)],
			['stock_quantity', (int)$totalStock],
		];
		if (!$is_variable) {
			$dataPrice = self::getDataPrice($product);
			$up_data += [
				['price', $dataPrice['price']],
				['regular_price', $dataPrice['regular_price']],
				['sale_price', $dataPrice['sale_price']],
			];
		}

		foreach ($up_data as $row) {
			$key = $row[0];
			$val = $row[1];

			if ($old_data[$key] !== $val) {
				$wcProduct->{'set_'.$key}($val);
				$need_save = true;
			}
		}

		if (self::$cf->is_up_photo || !self::checkImages($product->images, $wcProduct)) {
			self::deleteWcProductImages($wcProduct);
			$images = self::processingPhoto($product->images, $dbProduct['post_id']);
			$wcProduct->set_image_id(array_shift($images));
			$wcProduct->set_gallery_image_ids($images);
			$need_save = true;
		}

		if ($need_save) {
			$wcProduct->set_date_modified(time());
			$wcProduct->update_meta_data('_oasis_updated', $product->updated_at);
			$wcProduct->save();
		}
	}

	/**
	 * Add product image
	 *
	 * @param $post_id
	 * @param $oasisProduct
	 * @param $is_up
	 */
	public static function wcProductAddImage($post_id, $oasisProduct, $is_up = false)
	{
		$wcProduct = wc_get_product($post_id);

		if (empty($wcProduct)) {
			throw new Exception('Error open product. No product with this ID');
		}
		if(!$is_up && !empty($wcProduct->get_image_id())){
			return true;
		}

		self::deleteWcProductImages($wcProduct);
		$images = self::processingPhoto($oasisProduct->images, $post_id);
		$wcProduct->set_image_id(array_shift($images));
		$wcProduct->set_gallery_image_ids($images);
		$wcProduct->set_date_modified(time());
		$wcProduct->save();
	}

	/**
	 * Add variation
	 * @param $group_id
	 * @param $productId
	 * @param $variation
	 * @return int|void|null
	 */
	public static function addWcVariation($group_id, $productId, $variation)
	{
		try {
			$wcVariation = new WC_Product_Variation();
			$dataPrice = self::getDataPrice($variation);

			$wcVariation->set_props([
				'name'           => $variation->full_name,
				'manage_stock'   => true,
				'sku'            => $variation->article,
				'parent_id'      => $productId,
				'slug'           => self::getUniquePostName($variation->name, 'product_variation'),
				'status'         => self::getProductStatus($variation, $variation->total_stock, true),
				'stock_quantity' => intval($variation->total_stock),
				'backorders'     => $variation->rating === 5 ? 'yes' : 'no',
				'price'          => $dataPrice['price'],
				'regular_price'  => $dataPrice['regular_price'],
				'sale_price'     => $dataPrice['sale_price'],
			]);

			if ($attributes = self::getVariationAttributes($variation)) {
				$wcVariation->set_attributes($attributes);
			}
			if ($meta_data = self::getVariationMetaData($variation)) {
				foreach ($meta_data as $key => $value) {
					$wcVariation->add_meta_data($key, $value);
				}
			}
			$wcVariation->add_meta_data('_oasis_product', $variation->id);
			$wcVariation->add_meta_data('_oasis_group', $group_id);
			$wcVariation->add_meta_data('_oasis_updated', $variation->updated_at);

			$wcVariationId = $wcVariation->save();

			if ($variation->images && !self::$cf->is_fast_import) {
				$images = self::processingPhoto([reset($variation->images)], $wcVariationId );
				$wcVariation->set_image_id(array_shift($images));
				$wcVariation->save();
			}
		} catch (Exception $e) {
			if ($e->getErrorCode() == 'product_invalid_sku') {
				self::$cf->error($e->getMessage() . PHP_EOL);
				self::deleteWcProductBySky($variation);
			} else {
				self::$cf->fatal($e->getMessage());
			}
		}

		return $wcVariationId ?? null;
	}

	/**
	 * Up variation
	 *
	 * @param $dbVariation
	 * @param $variation
	 */
	public static function upWcVariation($dbVariation, $variation)
	{
		$wcVariation = wc_get_product($dbVariation['post_id']);
		$dataPrice   = self::getDataPrice($variation);
		$need_save   = false;

		if (self::getNeedUp($dbVariation, $variation)) {
			$wcVariation->set_name($variation->full_name);
			$wcVariation->set_manage_stock(true);
			$wcVariation->set_backorders( $variation->rating === 5 ? 'yes' : 'no' );

			if ($attributes = self::getVariationAttributes($variation)) {
				$wcVariation->set_attributes($attributes);
			}

			if ($meta_data = self::getVariationMetaData($variation)) {
				foreach ($meta_data as $key => $value) {
					$wcVariation->update_meta_data($key, $value);
				}
			}
			$need_save = true;
		}

		$old_data = $wcVariation->get_data();
		foreach ([
				['status', self::getProductStatus( $variation, $variation->total_stock, true )],
				['price', $dataPrice['price']],
				['regular_price', $dataPrice['regular_price']],
				['sale_price', $dataPrice['sale_price']],
				['stock_quantity', (int) $variation->total_stock],
			] as $row)
		{
			$key = $row[0];
			$val = $row[1];

			if ($old_data[$key] !== $val) {
				$wcVariation->{'set_'.$key}($val);
				$need_save = true;
			}
		}

		if (self::$cf->is_up_photo || !self::checkImages($variation->images, $wcVariation)) {
			self::deleteWcProductImages($wcVariation);
			$images = self::processingPhoto([reset($variation->images)], $dbVariation['post_id']);
			$wcVariation->set_image_id(array_shift($images));
			$need_save = true;
		}

		if ($need_save) {
			$wcVariation->set_date_modified(time());
			$wcVariation->update_meta_data('_oasis_updated', $variation->updated_at);
			$wcVariation->save();
		}
	}

	/**
	 * Add variation image
	 *
	 * @param $post_id
	 * @param $oasisProduct
	 * @param $is_up
	 */
	public static function wcVariationAddImage($post_id, $oasisProduct, $is_up = false)
	{
		$wcVariation = wc_get_product($post_id);

		if ($wcVariation === false) {
			throw new Exception('Error open variation. No variation with this ID');
		}
		if(!$is_up && !empty($wcVariation->get_data()['image_id'])){
			return true;
		}

		self::deleteWcProductImages($wcVariation);
		$images = self::processingPhoto([reset($oasisProduct->images)], $post_id);
		$wcVariation->set_image_id(array_shift($images));
		$wcVariation->set_date_modified(time());
		$wcVariation->save();
	}

	/**
	 * Add Brand in WooCommerce product
	 *
	 * @param $wcProductId
	 * @param $product
	 */
	public static function updateWcProductBrand($wcProductId, $product)
	{
		if (self::$cf->is_brands && !empty($product->brand_id)) {
			$brand = self::$brands[$product->brand_id] ?? null;
			if ($brand) {
				if (!isset($brand['term_id'])) {
					$term_id = self::getTermIdByOasisBrand($brand);
					self::$brands[$product->brand_id]['term_id'] = $brand['term_id'] = $term_id;
				}
				if (!empty($brand['term_id'])) {
					wp_set_object_terms($wcProductId, $brand['term_id'], 'product_brand');
				}
			}
		}
	}

	/**
	 * Check need update product
	 * @param $dbProduct
	 * @param $product
	 * @return bool
	 */
	public static function getNeedUp($dbProduct, $product)
	{
		return ($product->updated_at ?? '1') > ($dbProduct['updated_at'] ?? '');
	}

	/**
	 * Delete product for posts_id
	 *
	 * @param $posts_id
	 */
	public static function deleteProductForPostId($posts_id)
	{
		if (!is_array($posts_id)) {
			$posts_id = [$posts_id];
		}
		foreach ($posts_id as $post_id) {
			$wcProduct = wc_get_product(intval($post_id));
			if ($wcProduct) {
				self::deleteWcProduct($wcProduct);
			}
		}
	}

	/**
	 * Check and delete product by Oasis product id
	 *
	 * @param $productId
	 */
	public static function checkDeleteProduct($productId)
	{
		foreach (self::checkProducts($productId) as $dbProduct) {
			$wcProduct = wc_get_product(intval($dbProduct['post_id']));
			if ($wcProduct) {
				self::deleteWcProduct($wcProduct);
			}
		}
	}

	/**
	 * Check and delete product by Oasis group id
	 *
	 * @param $group_id
	 */
	public static function checkDeleteGroup($group_id)
	{
		foreach (self::checkGroupProducts($group_id) as $dbProduct) {
			$wcProduct = wc_get_product(intval($dbProduct['post_id']));
			if ($wcProduct) {
				self::deleteWcProduct($wcProduct);
			}
		}
	}

	/**
	 * Compare arrays
	 *
	 * @param $arr1
	 * @param $arr2
	 * @return bool
	 */
	private static function compareIsEqualArray($arr1 = [], $arr2 = []): bool
	{
		if (count($arr1) != count($arr2)) {
			return false;
		}
		foreach ($arr1 as $item1) {
			if (!in_array($item1, $arr2)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Delete woocommerce product by sky
	 *
	 * @param $product
	 */
	private static function deleteWcProductBySky($product)
	{
		$wcProductID = wc_get_product_id_by_sku($product->article);

		if ( $wcProductID ) {
			$wcProduct = wc_get_product( $wcProductID );
			self::deleteWcProduct( $wcProduct );
			self::$cf->log('Есть артикул! Oasis Id: ' . $product->id);
		}
	}

	/**
	 * Delete woocommerce product
	 * 
	 * @param $wcProduct
	 */
	private static function deleteWcProduct($wcProduct)
	{
		if ($wcProduct->is_type('variable')) {
			foreach ($wcProduct->get_children() as $child_id) {
				$child = wc_get_product($child_id);
				self::deleteWcProductImages($child);
				$child->delete(true);
				self::$cf->log(' - удален wc_product_variation_id: ' . $child_id);
			}
		}

		self::deleteWcProductImages($wcProduct);
		$id = $wcProduct->get_id();
		$wcProduct->delete(true);
		self::$cf->log(' - удален wc_product_id: ' . $id);
	}

	/**
	 * Delete images for woocommerce product
	 * 
	 * @param $wcProduct
	 */
	private static function deleteWcProductImages($wcProduct)
	{
		$images = array_merge(
			[$wcProduct->get_image_id()], 
			$wcProduct->get_gallery_image_ids());

		foreach($images as $image_id) {
			if(!empty($image_id)) {
				$other = self::checkAttachmentOtherPost($image_id, $wcProduct->get_id());
				if (empty($other)) {
					wp_delete_attachment($image_id, true);
					self::$cf->log(' - удален attachment: ' . $image_id);
				}
				else {
					self::$cf->log(' - не удален attachment: ' . $image_id . ', в других: ' . implode(', ', $other));
				}
			}
		}
	}

	/**
	 * Check use attachment in other post
	 * @param $attachment_id
	 * @param $product_id
	 */
	private static function checkAttachmentOtherPost($attachment_id, $product_id = 0)
	{
		global $wpdb;
		$as_featured = $wpdb->get_results($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} 
			 WHERE meta_key = '_thumbnail_id' 
			 AND meta_value = %d
			 AND post_id != %d", 
			$attachment_id, 
			$product_id
		), ARRAY_A);
		$result = array_map(fn($row) => $row['post_id'], $as_featured);

		$in_galleries = $wpdb->get_results($wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} 
			 WHERE meta_key = '_product_image_gallery' 
			 AND meta_value LIKE %s
			 AND post_id != %d",
			'%' . $wpdb->esc_like($attachment_id) . '%',
			$product_id
		), ARRAY_A);
		foreach ($in_galleries as $gallery) {
			$ids = explode(',', $gallery['meta_value']);
			if (in_array($attachment_id, $ids)) {
				$result[] = $gallery['post_id'];
			}
		}
		return $result;
	}

	/**
	 * Calculation price
	 *
	 * @param $product
	 * @return array
	 */
	public static function getDataPrice($product): array
	{
		$price     = self::$cf->is_price_dealer ? $product->discount_price : $product->price;
		$old_price = !empty( $product->old_price ) ? $product->old_price : null;

		if (!empty(self::$cf->price_factor)) {
			$price = $price * self::$cf->price_factor;

			if (!self::$cf->is_price_dealer) {
				$old_price = $old_price * self::$cf->price_factor;
			}
		}

		if (!empty(self::$cf->price_increase)) {
			$price = $price + self::$cf->price_increase;

			if (!self::$cf->is_price_dealer) {
				$old_price = $old_price + self::$cf->price_increase;
			}
		}

		$data = [
			'price' => $price
		];

		if (!self::$cf->is_disable_sales && !empty($old_price) && $price < $old_price) {
			$data['regular_price'] = $old_price;
			$data['sale_price']    = $price;
		} else {
			$data['regular_price'] = $price;
			$data['sale_price']    = '';
		}

		return $data;
	}

	/**
	 * Get array IDs WooCommerce categories
	 *
	 * @param $product
	 * @return array
	 */
	public static function getProductCategories($product): array
	{
		$result = [];
		
		foreach ($product->categories as $oasis_cat_id) {
			$rel_id = self::$cf->getRelCategoryId($oasis_cat_id);

			if(isset($rel_id)){
				$parents = self::getTermParents($rel_id);
				$result = array_merge($result, array_map(fn($x) => $x->term_id, $parents));
			}
			else{
				$full_categories = self::getOasisParentsCategoriesId($oasis_cat_id);

				foreach ($full_categories as $categoryId) {
					$result[] = self::getCategoryId($categoryId);
				}
			}
		}
		return $result;
	}

	/**
	 * Get total stock products
	 *
	 * @param array $products
	 * @return int
	 */
	public static function getTotalStock(array $products): int
	{
		$result = 0;
		foreach ($products as $product) {
			$result += intval($product->total_stock);
		}
		return $result;
	}

	/**
	 * Get product attributes
	 * @param $product
	 * @param array $models
	 * @param bool $is_variable
	 * @return array
	 */
	public static function prepareProductAttributes($product, array $models = [], bool $is_variable = false): array
	{
		$wcAttributes	= [];
		$brandings		= [];
		$dataSize		= self::getProductAttributeSize($models);

		if (!empty($dataSize)) {
			$wcAttributes[] = self::getWcObjectProductAttribute(
				self::createAttribute(self::$attrVariation['size']['name'], $dataSize, self::$attrVariation['size']['slug']),
				$dataSize,
				true, $is_variable);
		}

		foreach ($product->attributes as $attr) {
			switch ($attr->id ?? false) {
				case self::ATTR_COLOR_ID: // цвет
					$colorIds = [];
					$pantones = [];
					foreach ($models as $model) {
						if (!empty($model->colors)) {
							foreach ($model->colors as $color) {
								$colorIds[] = $color->parent_id;
								if (!empty($color->pantone)) {
									$pantones[] = $color->pantone;
								}
							}
						}
					}
					if (!empty($colorIds)) {
						$filterColors   = self::checkColorsToFilter($colorIds);
						$wcAttributes[] = self::getWcObjectProductAttribute(
							self::createAttribute('Фильтр цвет', $filterColors, 'color'),
							$filterColors,
							false, false);
					}
					if (!empty($pantones)) {
						$wcAttributes[] = self::getWcObjectProductAttribute(
							self::createAttribute('Фильтр пантон', $pantones, 'filter-panton'),
							$pantones,
							false, false);
					}
					$dataColor = self::getProductAttributeColor($attr, $models);
					if (!empty($dataColor)) {
						sort($dataColor);
						$wcAttributes[] = self::getWcObjectProductAttribute(
							self::createAttribute(self::$attrVariation['color']['name'], $dataColor, self::$attrVariation['color']['slug']),
							$dataColor,
							true, $is_variable);
					}

					if (!$is_variable) {
						$pantones = [];
						foreach (($product->colors ?? []) as $color) {
							if (!empty($color->pantone)) {
								$pantones[] = $color->pantone;
							}
						}
						if (!empty($pantones)) {
							$wcAttributes[] = self::getWcObjectProductAttribute(
								self::createAttribute('Пантон', $pantones, 'panton'),
								$pantones,
								true, false);
						}
					}
					break;
				
				case self::ATTR_BRANDING_ID: // Метод нанесения
					$brandings[] = trim($attr->value);
					break;

				case self::ATTR_GENDER_ID: // Гендер (пол)
					$wcAttributes[] = self::getWcObjectProductAttribute(
						self::createAttribute('Пол', [$attr->value]),
						[$attr->value],
						true, false);
					break;

				case self::ATTR_MATERIAL_ID: // Материал товара
					$materials      = self::getStandardAttributeMaterial($attr->value);
					$wcAttributes[] = self::getWcObjectProductAttribute(
						self::createAttribute('Материал (фильтр)', $materials, 'material'),
						$materials,
						false, false);
					$wcAttributes[] = self::getWcObjectProductAttribute(
						[
							'attribute_id'       => 0,
							'attribute_taxonomy' => $attr->name
						],
						[trim( $attr->value)],
						true, false);
					break;

				default:
					if ($attr->name != self::ATTR_SIZE_NAME) {
						$wcAttributes[] = self::getWcObjectProductAttribute(
							[
								'attribute_id'       => 0,
								'attribute_taxonomy' => $attr->name
							],
							[trim($attr->value) . (!empty($attr->dim) ? ' ' . $attr->dim : '' )],
							true, false);
					}
					break;
			}
		}

		if (!empty($brandings)) {
			$wcAttributes[] = self::getWcObjectProductAttribute(
				self::createAttribute('Метод нанесения', $brandings),
				$brandings,
				true, false);
		}

		return $wcAttributes;
	}

	/**
	 * Get product default attributes
	 * @param $productId
	 * @param $model
	 * @return array
	 */
	public static function getProductDefaultAttributes($productId, $model): array
	{
		$result = [];
		if (count($model) > 1) {
			foreach ($model as $product) {
				if (!empty($product->size)) {
					if ($product->id == $productId) {
						$taxonomy = sanitize_title('pa_' . self::$attrVariation['size']['slug']);
						$term = self::getTermByName($product->size, $taxonomy);
						$result[$taxonomy] = $term->slug;
					}
				}
				foreach ($product->attributes as $attribute) {
					if (isset($attribute->id) && $attribute->id == self::ATTR_COLOR_ID) {
						if ($product->id == $productId) {
							$taxonomy = sanitize_title('pa_' . self::$attrVariation['color']['slug']);
							$term = self::getTermByName($attribute->value, $taxonomy);
							$result[$taxonomy] = $term->slug;
						}
						break;
					}
				}
			}
		}
		return $result;
	}

	/**
	 * Get product attribute color
	 * @param $attribute
	 * @param $models
	 * @return array
	 */
	public static function getProductAttributeColor($attribute, $models): array
	{
		$result = [];
		if (count($models) > 1) {
			foreach ($models as $model) {
				foreach ($model->attributes as $attr) {
					if (isset($attr->id) && $attr->id == self::ATTR_COLOR_ID) {
						$result[] = trim($attr->value);
						break;
					}
				}
			}
			sort($result);
			$result = array_unique($result);
		} else {
			$result = [trim($attribute->value)];
		}
		return $result;
	}

	/**
	 * Get product attribute size
	 * @param $models
	 * @return array
	 */
	public static function getProductAttributeSize($models): array
	{
		$result = [];
		if (count($models) > 1) {
			foreach ($models as $product) {
				$size = trim($product->size ?? '');
				if (!empty($size)) {
					$result[] = $size;
				}
			}
			$result = self::sortSizeByStandard($result);
		}

		return $result;
	}

	/**
	 * Sort sizes according to standard. Returns unique values
	 * @param array $data
	 * @return array
	 */
	public static function sortSizeByStandard(array $data): array
	{
		$etalonSizes = [
			'3XS',
			'2XS',
			'XS',
			'XS-S',
			'S',
			'M',
			'M-L',
			'L',
			'XL',
			'XL-2XL',
			'2XL',
			'3XL',
			'4XL',
			'5XL',
		];

		usort($data, function ($key1, $key2) use ($etalonSizes) {
			return array_search($key1, $etalonSizes) <=> array_search($key2, $etalonSizes);
		});

		return array_values(array_unique($data));
	}

	/**
	 * Get product id oasis by order item
	 *
	 * @param $item
	 * @return string|null
	 */
	public static function getOasisProductIdByOrderItem( $item ): ?string
	{
		return Main::getOasisProductIdByPostId($item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id());
	}

	/**
	 * Get oasis product id by post_id
	 * 
	 * @param int $post_id
	 * @return string|null
	 */
	public static function getOasisProductIdByPostId(int $post_id): ?string
	{
		global $wpdb;
		$dbResults = $wpdb->get_row($wpdb->prepare(
			"SELECT DISTINCT pm_product.meta_value as product_id
			FROM {$wpdb->prefix}posts p
			INNER JOIN {$wpdb->prefix}postmeta pm_product ON p.ID = pm_product.post_id AND pm_product.meta_key = '_oasis_product'
			WHERE p.ID = %d", [$post_id]
		), ARRAY_A);
		return $dbResults['product_id'] ?? null;
	}

	/**
	 * Get products
	 *
	 * @return array
	 */
	public static function getOasisDbRows(): array
	{
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare(
			"SELECT DISTINCT p.ID as post_id, p.post_type as type, pm_product.meta_value as product_id, pm_group.meta_value as group_id, pm_updated.meta_value as updated_at
			FROM {$wpdb->prefix}posts p
			INNER JOIN {$wpdb->prefix}postmeta pm_product ON p.ID = pm_product.post_id AND pm_product.meta_key = '_oasis_product'
			INNER JOIN {$wpdb->prefix}postmeta pm_group ON p.ID = pm_group.post_id AND pm_group.meta_key = '_oasis_group'
			INNER JOIN {$wpdb->prefix}postmeta pm_updated ON p.ID = pm_updated.post_id AND pm_updated.meta_key = '_oasis_updated'"
		), ARRAY_A);
	}

	/**
	 * Get image attachment_id for post_id
	 *
	 * @param array $post_ids
	 * @return array
	 */
	public static function getImagesForPostIds(array $post_ids = []): array
	{
		global $wpdb;
		$result = [];
		$placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

		$attachments = $wpdb->get_results($wpdb->prepare(
			"SELECT meta_value, post_id FROM {$wpdb->postmeta} 
			 WHERE meta_key = '_thumbnail_id' 
			 AND post_id in ($placeholders)", 
			$post_ids
		), ARRAY_A);

		foreach ($attachments as $attachment) {
			if (!isset($result[$attachment['post_id']])) {
				$result[$attachment['post_id']] = [];
			}
			$result[$attachment['post_id']][] = $attachment['meta_value'];
		}

		$in_galleries = $wpdb->get_results($wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} 
			 WHERE meta_key = '_product_image_gallery' 
			 AND post_id in ($placeholders)", 
			$post_ids
		), ARRAY_A);

		foreach ($in_galleries as $gallery) {
			if (!isset($result[$gallery['post_id']])) {
				$result[$gallery['post_id']] = [];
			}
			$result[$gallery['post_id']] = array_merge($result[$gallery['post_id']], explode(',', $gallery['meta_value']));
		}
		return $result;
	}

	/**
	 * Get object wc product attribute
	 *
	 * @param $attr
	 * @param $values
	 * @param bool $visible
	 * @param bool $is_variable
	 * @return WC_Product_Attribute
	 */
	public static function getWcObjectProductAttribute($attr, array $values, bool $visible, bool $is_variable): WC_Product_Attribute
	{
		if ($attr['attribute_id']) {
			$options = [];
			foreach ($values as $value) {
				$term = self::getTermByName($value, $attr['attribute_taxonomy']);
				$options[] = intval($term->term_id);
			}
		}
		else {
			$options = $values;
		}

		$attribute = new WC_Product_Attribute();
		$attribute->set_id($attr['attribute_id'] ?? 0);
		$attribute->set_name($attr['attribute_taxonomy']);
		$attribute->set_visible($visible);
		$attribute->set_options($options);
		$attribute->set_variation($is_variable);

		return $attribute;
	}

	/**
	 * Get variation attributes
	 *
	 * @param $variation
	 * @return array
	 */
	public static function getVariationAttributes($variation): array
	{
		$result = [];
		if (!empty($variation->size)) {
			$taxonomy = sanitize_title('pa_' . self::$attrVariation['size']['slug']);
			$term = self::getTermByName($variation->size, $taxonomy);
			$result[$taxonomy] = $term->slug;
		}

		foreach ($variation->attributes as $attribute) {
			if (isset($attribute->id) && $attribute->id == self::ATTR_COLOR_ID) {
				$taxonomy = sanitize_title('pa_' . self::$attrVariation['color']['slug']);
				$term = self::getTermByName($attribute->value, $taxonomy);
				$result[$taxonomy] = $term->slug;
			}
		}
		return $result;
	}

	/**
	 * Get variation metadata
	 *
	 * @param $variation
	 * @return array
	 */
	public static function getVariationMetaData($variation): array
	{
		$result = [];
		$pantones = [];
		foreach ($variation->colors as $color) {
			if (!empty($color->pantone)) {
				$pantones[] = $color->pantone;
			}
		}
		if ($pantones) {
			$result['_pantone'] = $pantones;
		}

		return $result;
	}

	
	/**
	 * Get term
	 * @param $name
	 * @param $taxonomy
	 * @return term
	 */
	public static function getTermByName($name, $taxonomy) {
		$result = term_exists($name, $taxonomy);
		if (!$result) {
			$result = wp_insert_term($name, $taxonomy, [
				'slug' => self::transliteration($name)
			]);
		}
		return get_term($result['term_id'], $taxonomy);
	}

	/**
	 * Create attribute
	 *
	 * @param string $raw_name Name of attribute to create.
	 * @param array $terms Terms to create for the attribute.
	 * @param string $slug
	 * @return array
	 */
	public static function createAttribute(string $raw_name, array $terms, string $slug = ''): array
	{
		global $wc_product_attributes;

		delete_transient('wc_attribute_taxonomies');
		WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');

		$attribute_labels = wp_list_pluck(wc_get_attribute_taxonomies(), 'attribute_label', 'attribute_name');
		$attribute_name   = array_search(empty($slug) ? $raw_name : $slug, $attribute_labels, true);
		if (!$attribute_name) {
			$attribute_name = wc_sanitize_taxonomy_name(empty($slug) ? $raw_name : $slug);
		}

		$attribute_name = substr(self::transliteration($attribute_name), 0, 27);
		$attribute_id   = wc_attribute_taxonomy_id_by_name($attribute_name);

		if ( ! $attribute_id ) {
			$taxonomy_name = wc_attribute_taxonomy_name($attribute_name);
			unregister_taxonomy($taxonomy_name);

			$attribute_id = wc_create_attribute([
				'name'         => $raw_name,
				'slug'         => $attribute_name,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => 0,
			]);

			register_taxonomy(
				$taxonomy_name,
				apply_filters('woocommerce_taxonomy_objects_' . $taxonomy_name, [ 'product' ]),
				apply_filters(
					'woocommerce_taxonomy_args_' . $taxonomy_name,
					[
						'labels'       => [
							'name' => $raw_name,
						],
						'hierarchical' => false,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
					]
				)
			);

			$wc_product_attributes = [];

			foreach (wc_get_attribute_taxonomies() as $taxonomy) {
				$wc_product_attributes[ wc_attribute_taxonomy_name($taxonomy->attribute_name)] = $taxonomy;
			}
		}

		$attribute = wc_get_attribute($attribute_id);
		$return    = [
			'attribute_name'     => $attribute->name,
			'attribute_taxonomy' => $attribute->slug,
			'attribute_id'       => $attribute_id,
			'term_ids'           => [],
		];

		foreach ($terms as $term) {
			$result = term_exists($term, $attribute->slug);
			if (!$result) {
				$result = wp_insert_term($term, $attribute->slug, [
					'slug' => self::transliteration($term)
				]);
			}
			$return['term_ids'][] = $result['term_id'];
		}

		return $return;
	}

	/**
	 * Check colors to filter
	 * @param $colorIds
	 * @return array
	 */
	public static function checkColorsToFilter($colorIds): array
	{
		$result = [];
		$colors = [
			1480 => 'Голубой',
			1483 => 'Бежевый',
			1471 => 'Черный',
			1472 => 'Синий',
			1482 => 'Коричневый',
			1478 => 'Бордовый',
			1488 => 'Темно-синий',
			1484 => 'Золотистый',
			1474 => 'Зеленый',
			1475 => 'Зеленое яблоко',
			1481 => 'Серый',
			1486 => 'Разноцветный',
			1476 => 'Оранжевый',
			1487 => 'Розовый',
			1479 => 'Фиолетовый',
			1473 => 'Красный',
			1485 => 'Серебристый',
			1470 => 'Белый',
			1477 => 'Желтый'
		];

		foreach (array_unique($colorIds) as $id) {
			if (!empty($colors[$id])) {
				$result[] = $colors[$id];
			}
		}
		return $result;
	}

	/**
	 * Remove quotes
	 *
	 * @param $text
	 * @return string
	 */
	private static function removeQuotes($text): string
	{
		return str_replace(['\'', '"', '«', '»'], ['', '', '', ''], $text);
	}

	/**
	 * Get unique post_name by post_title
	 *
	 * @param $name
	 * @param $post_type
	 * @param null $productId
	 * @param int $count
	 * @return string
	 */
	public static function getUniquePostName($name, $post_type, $productId = null, int $count = 0): string
	{
		$post_name = self::transliteration($name);

		if (!empty($count)) {
			$post_name = $post_name . '-' . $count;
		}

		$dbPosts = get_posts([
			'name'        => $post_name,
			'post_type'   => $post_type,
			'post_status' => ['publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash', 'any'],
			'exclude'     => !empty($productId) ? intval($productId) : '',
		]);

		if ($dbPosts) {
			$post_name = self::getUniquePostName($name, $post_type, $productId, ++$count);
		}
		return $post_name;
	}

	/**
	 * Prepare post content
	 *
	 * @param $product
	 * @return string
	 */
	public static function preparePostContent($product): string
	{
		$result = !empty($product->description) ? $product->description : '';
		if (!empty($product->defect)) {
			$result .= PHP_EOL . '<p>' . trim( $product->defect ) . '</p>';
		}
		return $result;
	}

	/**
	 * Get id category woocommerce
	 *
	 * @param $categoryId
	 * @return int
	 */
	public static function getCategoryId($categoryId): int
	{
		$terms = self::getTermsByCatId($categoryId);

		if (! $terms) {
			$category = self::searchObject(self::$oasisCategories, $categoryId);
			$parentTermsId = 0;

			if (!is_null($category->parent_id)) {
				$parentTerms = self::getTermsByCatId($category->parent_id);

				if ($parentTerms) {
					$parentTermsId = $parentTerms->term_id;
				} else {
					$parentTermsId = self::getCategoryId($category->parent_id);
				}
			}

			$result = self::addCategory($category, $parentTermsId);
		} else {
			$result = (int)$terms->term_id;
		}

		return $result;
	}

	/**
	 * Add category
	 *
	 * @param $category
	 * @param $parentTermsId
	 * @return int
	 */
	public static function addCategory($category, $parentTermsId): int
	{
		require_once ABSPATH . '/wp-admin/includes/taxonomy.php';

		$data = [
			'cat_name'             => $category->name,
			'category_description' => '',
			'category_nicename'    => $category->slug,
			'category_parent'      => $parentTermsId,
			'taxonomy'             => 'product_cat',
		];

		$cat_id = wp_insert_category($data);
		update_term_meta($cat_id, 'oasis_cat_id', $category->id);

		return $cat_id;
	}

	/**
	 * Get terms by oasis id category
	 *
	 * @param $categoryId
	 * @return object|array
	 */
	public static function getTermsByCatId($categoryId)
	{
		$args = [
			'taxonomy'   => ['product_cat'],
			'hide_empty' => false,
			'meta_query' => [
				[
					'key'   => 'oasis_cat_id',
					'value' => $categoryId,
				]
			],
		];

		$terms = get_terms($args);
		return $terms ? reset($terms) : [];
	}

	/**
	 * Get terms by oasis id category
	 *
	 * @param $brand
	 * @return int|false
	 */
	public static function getTermIdByOasisBrand($brand)
	{
		$args = [
			'taxonomy' =>	'product_brand',
			'number'  =>	1,
			'fields' =>		'ids',			
			'hide_empty' => false,
			'name' =>		$brand['name'],
			'slug' =>		$brand['slug']
		];

		$terms = get_terms($args);

		$term_id = $terms ? reset($terms) : false;

		if(empty($term_id)){
			$new_term = wp_insert_term($brand['name'], 'product_brand', [
				'slug' => $brand['slug']
			]);

			if($new_term){
				$term_id = $new_term['term_id'];

				if(!empty($brand['logotype'])){
					$filename = basename($brand['logotype']);
					$attach_id = self::getAttachmentIdByTitle($filename);

					if (!$attach_id) {
						$image_data = file_get_contents($brand['logotype']);
						if ($image_data) {
							require_once( ABSPATH . 'wp-admin/includes/image.php' );

							$wp_filetype = wp_check_filetype($filename);
							$upload_dir = wp_upload_dir();

							if (wp_mkdir_p( $upload_dir['path'])) {
								$file = $upload_dir['path'] . '/' . $filename;
							} else {
								$file = $upload_dir['basedir'] . '/' . $filename;
							}

							file_put_contents($file, $image_data);

							$attachment = [
								'post_mime_type' => $wp_filetype['type'],
								'post_title'     => sanitize_file_name($filename),
								'post_content'   => '',
								'post_status'    => 'inherit',
							];

							$attach_id   = wp_insert_attachment($attachment, $file);
							$attach_data = wp_generate_attachment_metadata($attach_id, $file);
							wp_update_attachment_metadata($attach_id, $attach_data);
						}
					}

					if(!empty($attach_id)){
						update_term_meta($term_id, 'thumbnail_id', $attach_id);
					}
				}

				self::$cf->log('Добавлен бренд '.$brand['name']);
			}
		}

		return $term_id;
	}

	/**
	 * Get array parents for term_id
	 *
	 * @param $term_id
	 * @return array
	 */
	public static function getTermParents($term_id): array
	{
		$term = get_term($term_id, 'product_cat');
		if (!$term) {
			return [];
		}
		$list = [];

		$parents = get_ancestors($term_id, 'product_cat', 'taxonomy');
		array_unshift( $parents, $term_id );

		foreach (array_reverse( $parents ) as $term_id) {
			$parent = get_term($term_id, 'product_cat');
			$list []= $parent;
		}

		return $list;
	}

	/**
	 * Search object by id
	 *
	 * @param $data
	 * @param $id
	 * @return false|mixed|null
	 */
	public static function searchObject($data, $id)
	{
		$neededObject = array_filter( $data, function ( $e ) use ( $id ) {
			return $e->id == $id;
		} );
		unset( $data, $id );

		if ( ! $neededObject ) {
			return false;
		}

		return array_shift( $neededObject );
	}

	/**
	 * Get status product
	 * @param $product
	 * @param $stock
	 * @param bool $variation
	 * @return string
	 */
	public static function getProductStatus($product, $stock, bool $variation = false): string {
		if ($product->is_deleted === true) {
			$result = 'trash';
		} elseif (intval($stock) === 0 || !empty($product->is_stopped)) {
			$result = $variation ? 'private' : 'draft';
		} else {
			$result = 'publish';
		}
		return $result;
	}

	/**
	 * Get categories level 1
	 * @return array
	 */
	public static function getOasisMainCategories(): array
	{
		$result = [];
		$categories = Api::getCategoriesOasis();

		foreach ($categories as $category) {
			if ($category->level === 1) {
				$result[] = $category->id;
			}
		}
		return $result;
	}

	/**
	 * Get oasis parents id categories
	 *
	 * @param null $cat_id
	 * @return array
	 */
	public static function getOasisParentsCategoriesId($cat_id): array
	{
		$result = [];
		$parent_id = $cat_id;

		while($parent_id){
			foreach (self::$oasisCategories as $category) {
				if ($parent_id == $category->id) {
					array_unshift($result, $category->id);
					$parent_id = $category->parent_id;
					continue 2;
				}
			}
			break;
		}
		return $result;
	}

	/**
	 * Build tree categories
	 *
	 * @param $data
	 * @param array $checkedArr
	 * @param array $relCategories
	 * @param int $parent_id
	 * @param bool $parent_checked
	 * @return string
	 */
	public static function buildTreeCats($data, array $checkedArr = [], array $relCategories = [], int $parent_id = 0, bool $parent_checked = false): string
	{
		$treeItem = '';
		if (!empty($data[$parent_id])) {
			foreach ($data[$parent_id] as $item) {
				$checked = $parent_checked || in_array($item['id'], $checkedArr);

				$rel_cat = $relCategories[$item['id']] ?? null;
				$rel_label = '';
				$rel_value = '';
				if($rel_cat){
					$rel_value = $item['id'].'_'.$rel_cat['id'];
					$rel_label = $rel_cat['rel_label'];
				}

				$treeItemChilds = self::buildTreeCats($data, $checkedArr, $relCategories, $item['id'], $checked);

				if (empty($treeItemChilds)) {
					$treeItem .= '<div class="oa-tree-leaf">
						<div class="oa-tree-label ' . ($rel_value ? 'relation-active' : '') . '">
							<input type="hidden" class="oa-tree-inp-rel" name="oasis_import_options[cat_relation][]" value="' . esc_attr($rel_value) . '" />
							<label>
								<input type="checkbox" class="oa-tree-cb-cat" name="oasis_import_options[categories][]" value="' . esc_attr($item['id']) . '"' . ($checked ? ' checked="checked"' : '' ) . '/>
								<div class="oa-tree-btn-relation"></div>' . esc_html($item['name']) . '
							</label>
							<div class="oa-tree-dashed"></div>
							<div class="oa-tree-relation">' . esc_html($rel_label) . '</div>
						</div>
					</div>';
				}
				else {
					$treeItem .= '<div class="oa-tree-node oa-tree-collapsed">
						<div class="oa-tree-label ' . ($rel_value ? 'relation-active' : '') . '">
							<input type="hidden" class="oa-tree-inp-rel"  name="oasis_import_options[cat_relation][]" value="' . esc_attr($rel_value) . '" />
							<span class="oa-tree-handle-p">+</span>
							<span class="oa-tree-handle-m">-</span>
							<label>
								<input type="checkbox" class="oa-tree-cb-cat" name="oasis_import_options[categories][]" value="' . esc_attr($item['id']) . '"' . ($checked ? ' checked="checked"' : '' ) . '/>
								<div class="oa-tree-btn-relation"></div>' . esc_html($item['name']) . '
							</label>
							<div class="oa-tree-dashed"></div>
							<div class="oa-tree-relation">' . esc_html($rel_label) . '</div>
						</div>
						<div class="oa-tree-childs">' . $treeItemChilds . '</div>
					</div>';
				}
			}
		}
		return $treeItem;
	}

	/**
	 * Build tree categories
	 *
	 * @param $data
	 * @param int $checked_id
	 * @param int $parent_id
	 * @return string
	 */
	public static function buildTreeRadioCats($data, ?array $checked_id = null, int $parent_id = 0): string
	{
		$treeItem = '';
		if (!empty($data[$parent_id])) {
			foreach ($data[$parent_id] as $item) {
				$checked = $checked_id === $item['id'] ? ' checked="checked"' : '';

				$treeItemChilds = self::buildTreeRadioCats($data, $checked_id, $item['id']);

				if (empty($treeItemChilds)) {
					$treeItem .= '<div class="oa-tree-leaf">
						<div class="oa-tree-label">
							<label><input type="radio" name="oasis_import_radio_tree" value="' . esc_attr($item['id']) . '"' . $checked . '/>' . esc_attr($item['name']) . '</label>
						</div>
					</div>';
				}
				else {
					$treeItem .= '<div class="oa-tree-node oa-tree-collapsed">
						<div class="oa-tree-label">
							<span class="oa-tree-handle-p">+</span>
							<span class="oa-tree-handle-m">-</span>
							<label><input type="radio" name="oasis_import_radio_tree" value="' . esc_attr($item['id']) . '"' . $checked . '/>' . esc_attr($item['name']) . '</label>
						</div>
						<div class="oa-tree-childs">' . $treeItemChilds . '</div>
					</div>';
				}
			}
		}
		return $treeItem;
	}

	/**
	 * String transliteration for url
	 *
	 * @param $string
	 * @return string
	 */
	public static function transliteration( $string ): string
	{
		$arr_trans = [
			'А'  => 'A',
			'Б'  => 'B',
			'В'  => 'V',
			'Г'  => 'G',
			'Д'  => 'D',
			'Е'  => 'E',
			'Ё'  => 'E',
			'Ж'  => 'J',
			'З'  => 'Z',
			'И'  => 'I',
			'Й'  => 'Y',
			'К'  => 'K',
			'Л'  => 'L',
			'М'  => 'M',
			'Н'  => 'N',
			'О'  => 'O',
			'П'  => 'P',
			'Р'  => 'R',
			'С'  => 'S',
			'Т'  => 'T',
			'У'  => 'U',
			'Ф'  => 'F',
			'Х'  => 'H',
			'Ц'  => 'TS',
			'Ч'  => 'CH',
			'Ш'  => 'SH',
			'Щ'  => 'SCH',
			'Ъ'  => '',
			'Ы'  => 'YI',
			'Ь'  => '',
			'Э'  => 'E',
			'Ю'  => 'YU',
			'Я'  => 'YA',
			'а'  => 'a',
			'б'  => 'b',
			'в'  => 'v',
			'г'  => 'g',
			'д'  => 'd',
			'е'  => 'e',
			'ё'  => 'e',
			'ж'  => 'j',
			'з'  => 'z',
			'и'  => 'i',
			'й'  => 'y',
			'к'  => 'k',
			'л'  => 'l',
			'м'  => 'm',
			'н'  => 'n',
			'о'  => 'o',
			'п'  => 'p',
			'р'  => 'r',
			'с'  => 's',
			'т'  => 't',
			'у'  => 'u',
			'ф'  => 'f',
			'х'  => 'h',
			'ц'  => 'ts',
			'ч'  => 'ch',
			'ш'  => 'sh',
			'щ'  => 'sch',
			'ъ'  => 'y',
			'ы'  => 'yi',
			'ь'  => '',
			'э'  => 'e',
			'ю'  => 'yu',
			'я'  => 'ya',
			'.'  => '-',
			' '  => '-',
			'?'  => '-',
			'/'  => '-',
			'\\' => '-',
			'*'  => '-',
			':'  => '-',
			'>'  => '-',
			'|'  => '-',
			'\'' => '',
			'('  => '',
			')'  => '',
			'!'  => '',
			'@'  => '',
			'%'  => '',
			'`'  => '',
		];
		$string    = str_replace( [ '-', '+', '.', '?', '/', '\\', '*', ':', '*', '|' ], ' ', $string );
		$string    = htmlspecialchars_decode( $string );
		$string    = strip_tags( $string );
		$pattern   = '/[\w\s\d]+/u';
		preg_match_all( $pattern, $string, $result );
		$string = implode( '', $result[0] );
		$string = preg_replace( '/[\s]+/us', ' ', $string );
		unset( $result, $pattern );

		return strtolower( strtr( $string, $arr_trans ) );
	}

	/**
	 * Get standard attribute material
	 * @param $str
	 * @return array
	 */
	static public function getStandardAttributeMaterial($str): array
	{
		$result     = [];
		$attributes = [
			'акрил',
			'нейлон',
			'синтепон',
			'полиэстер',
			'микрофлис',
			'сатин',
			'хлопок',
			'шелк',
			'шерсть',
			'эластан',
			'поликарбонат',
			'стекло',
			'бамбук',
			'силикон',
			'полипропилен',
			'полиуретан',
			'фарфор',
			'трикотаж',
			'каучук',
			'керамика',
			'хрусталь',
			'лак',
			'пластик' => [
				'пластик',
				'ПВХ'
			],
			'металл' => [
				'металл',
				'алюминий',
				'бронза',
				'бронзовым',
				'хром',
				'тритан',
				'олово',
				'медь',
				'цинк'
			],
			'драгметалл' => [
				'серебро',
				'посеребрение',
				'золото',
				'позолота',
				'позолочение'
			],
			'дерево' => [
				'дерево',
				'МДФ'
			],
			'искусственная кожа' => [
				'искусственная кожа',
				'кожзам'
			],
			'натуральная кожа' => [
				'натуральная кожа',
				'телячья кожа'
			],
			'бумага' => [
				'бумага',
				'картон'
			],
			'камень' => [
				'камень',
				'мрамор',
				'гранит'
			],
			'soft-touch' => [
				'soft-touch',
				'софт-тач'
			],
		];
		foreach ($attributes as $key => $attribute) {
			if (is_array($attribute)) {
				foreach ($attribute as $subAttribute) {
					if (strpos($str, $subAttribute) !== false ) {
						$result[] = $key;
						break;
					}
				}
			} else {
				if (strpos($str, $attribute) !== false
					&& strpos($str, 'стекловолокно') === false
					&& strpos($str, 'стеклопластик') === false
				) {
					$result[] = $attribute;
				}
			}
		}
		return $result;
	}

	/**
	 * Add/update product photos
	 *
	 * @param $images
	 * @param $wcProductId
	 * @return array
	 */
	public static function processingPhoto($images, $wcProductId): array
	{
		$attachIds = [];
		if ($images) {
			$image_subsizes = wp_get_registered_image_subsizes();
			$upload_dir = wp_upload_dir();
			require_once( ABSPATH . 'wp-admin/includes/image.php' );

			foreach ($images as $image) {
				if (!isset($image->superbig)) {
					continue;
				}

				$filename     = basename($image->superbig);
				$existImageId = self::getAttachmentIdByTitle($filename);

				if ($existImageId) {
					$attachIds[] = $existImageId;
					continue;
				}

				$wp_filetype = wp_check_filetype($filename);

				if(self::$cf->is_cdn_photo){
					$attach_sizes = [];

					foreach($image_subsizes as $size => $size_data){
						if($size_data['width'] <= OasisConfig::IMG_SIZE_THUMBNAIL[0]){
							$attach_sizes[$size] = [
								'file' => '',
								'cdn' => $image->thumbnail,
								'width' => OasisConfig::IMG_SIZE_THUMBNAIL[0],
								'height' => OasisConfig::IMG_SIZE_THUMBNAIL[1],
								'mime-type' => $wp_filetype['type']
							];
							continue;
						}
						else if($size_data['width'] <= OasisConfig::IMG_SIZE_SMALL[0]) {
							$attach_sizes[$size] = [
								'file' => '',
								'cdn' => $image->small,
								'width' => OasisConfig::IMG_SIZE_SMALL[0],
								'height' => OasisConfig::IMG_SIZE_SMALL[1],
								'mime-type' => $wp_filetype['type']
							];
							continue;
						}
						else if($size_data['width'] <= OasisConfig::IMG_SIZE_BIG[0]) {
							$attach_sizes[$size] = [
								'file' => '',
								'cdn' => $image->big,
								'width' => OasisConfig::IMG_SIZE_BIG[0],
								'height' => OasisConfig::IMG_SIZE_BIG[1],
								'mime-type' => $wp_filetype['type']
							];
							continue;
						}
						else {
							$attach_sizes[$size] = [
								'file' => '',
								'cdn' => $image->superbig,
								'width' => OasisConfig::IMG_SIZE_SUPERBIG[0],
								'height' => OasisConfig::IMG_SIZE_SUPERBIG[1],
								'mime-type' => $wp_filetype['type']
							];
						}
					}

					$attachment = [
						'post_mime_type' => $wp_filetype['type'],
						'post_title'     => sanitize_file_name( $filename ),
						'post_content'   => '',
						'post_status'    => 'inherit',
						'post_parent'    => $wcProductId,
					];
					$attach_id   = wp_insert_attachment($attachment);
					$attach_data = array(
						'width'    => OasisConfig::IMG_SIZE_SUPERBIG[0],
						'height'   => OasisConfig::IMG_SIZE_SUPERBIG[1],
						'file'     => '1',
						'sizes'    => $attach_sizes,
					);

					wp_update_attachment_metadata($attach_id, $attach_data);
					$attachIds[] = $attach_id;
				}
				else {
					$image_data = file_get_contents($image->superbig);

					if ($image_data) {
						if (wp_mkdir_p( $upload_dir['path'])) {
							$file = $upload_dir['path'] . '/' . $filename;
						} else {
							$file = $upload_dir['basedir'] . '/' . $filename;
						}

						file_put_contents($file, $image_data);

						$attachment = [
							'post_mime_type' => $wp_filetype['type'],
							'post_title'     => sanitize_file_name($filename),
							'post_content'   => '',
							'post_status'    => 'inherit',
							'post_parent'    => $wcProductId,
						];

						$attach_id   = wp_insert_attachment($attachment, $file);
						$attach_data = wp_generate_attachment_metadata($attach_id, $file);
						wp_update_attachment_metadata($attach_id, $attach_data);
						$attachIds[] = $attach_id;
					}
				}
			}
		}

		return $attachIds;
	}

	/**
	 * Checking product images for relevance
	 * Usage:
	 * Check is good - true
	 * Check is bad - false
	 *
	 * @param $images
	 * @param $wcProduct
	 * @return bool
	 */
	public static function checkImages($images, $wcProduct): bool
	{
		$db_images = get_post_meta($wcProduct->get_id(), '_thumbnail_id');

		if (empty(intval(reset($db_images)))) {
			return false;
		}

		if ($wcProduct->get_type() == 'variation') {
			$images = [reset($images)];
		} else {
			$db_images = array_merge($db_images, $wcProduct->get_gallery_image_ids());
		}

		if (count($images) !== count($db_images)) {
			return false;
		}

		$posts = get_posts([
			'numberposts' => - 1,
			'post_type'   => 'attachment',
			'include'     => implode(',', $db_images)
		]);
		if (empty($posts)) {
			return false;
		}

		$attachments = [];
		foreach ($posts as $post) {
			$attachments[] = (array) $post;
		}
		foreach ($images as $image) {
			if (empty($image->superbig)) {
				return false;
			}
			$keyNeeded = array_search(basename($image->superbig), array_column($attachments, 'post_title'));
			if ($keyNeeded === false || $image->updated_at > strtotime($attachments[$keyNeeded ]['post_date_gmt'])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Get page by title
	 *
	 * @param $title
	 * @return int|null
	 */
	public static function getAttachmentIdByTitle($title): ?int
	{
		$query = new WP_Query( [
			'post_type'              => 'attachment',
			'title'                  => sanitize_file_name($title),
			'post_status'            => 'all',
			'posts_per_page'         => 1,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'orderby'                => 'post_date ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'fields'                 => 'ids'
		] );

		if (!empty($query->posts)) {
			return $query->posts[0];
		} else {
			return null;
		}
	}

	/**
	 * @param $array
	 * @param $keys
	 * @return bool
	 */
	public static function arrayKeysExists($array, $keys): bool
	{
		foreach ($keys as $key) {
			if (array_key_exists($key, $array)) {
				return true;
			}
		}
		return false;
	}	
}