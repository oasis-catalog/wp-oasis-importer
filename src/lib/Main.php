<?php

namespace OasisImport;

use OasisImport\Config as OasisConfig;
use Exception;
use WC_Cache_Helper;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Post;
use WP_Query;

class Main {
	public static OasisConfig $cf;
	public static $attrVariation = [];
	public static $brands = [];

	/**
	 * Prepare attributes for variations
	 *
	 * @return void
	 */
	public static function prepareAttributeData() {
		$attr_names       = [
			'color' => 'Цвет',
			'size'  => 'Размер'
		];
		$attribute_labels = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_label', 'attribute_name' );

		foreach ( $attr_names as $key => $name ) {
			$attribute_name = array_search( $name, $attribute_labels, true );

			if ( $attribute_name === false ) {
				$attribute_taxonomy = self::createAttribute( $name, [] );
				$attribute_name     = str_replace( 'pa_', '', $attribute_taxonomy['attribute_taxonomy'] );
			}

			self::$attrVariation[ $key ] = [
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
	 * Check product in table oasis_products
	 *
	 * @param array $where
	 * @param string $type
	 *
	 * @return array|WP_Post|null
	 */
	public static function checkProductOasisTable( array $where, string $type ) {
		global $wpdb;

		$sql    = '';
		$values = [ $type ];

		foreach ( $where as $key => $value ) {
			if ( $key == 'post_id' ) {
				$sql .= " AND op." . $key . " = '%d'";
			} else {
				$sql .= " AND op." . $key . " LIKE '%s'";
			}

			$values[] = $value;
		}

		$dbResults = $wpdb->get_results(
			$wpdb->prepare( "
				SELECT op.post_id, op.product_id_oasis, p.ID, p.post_modified 
				FROM {$wpdb->prefix}oasis_products op
				LEFT JOIN {$wpdb->prefix}posts p
				ON op.post_id = p.ID
				WHERE op.type LIKE '%s'" . $sql,
				$values
			),
			ARRAY_A
		);
		$dbResult  = reset( $dbResults );

		if ( $dbResult ) {
			if ( is_null( $dbResult['ID'] ) ) {
				$wpdb->delete( $wpdb->prefix . 'oasis_products', [ 'post_id' => $dbResult['post_id'], 'type' => $type ] );
			} else {
				$result = $dbResult;
			}
		}

		return $result ?? null;
	}

	/**
	 * Insert row into table oasis_products
	 *
	 * @param $post_id
	 * @param string $product_id_oasis
	 * @param string $model_id
	 * @param string $type
	 * @param $parentSizeId
	 */
	public static function addProductOasisTable( $post_id, string $product_id_oasis, string $model_id, string $type, $parentSizeId = null ) {
		global $wpdb;

		$data = [
			'post_id'          => $post_id,
			'product_id_oasis' => $product_id_oasis,
			'model_id_oasis'   => $model_id,
			'type'             => $type
		];

		if ( ! empty( $parentSizeId ) ) {
			$data['variation_parent_size_id'] = $parentSizeId;
		}

		$wpdb->insert( $wpdb->prefix . 'oasis_products', $data );
	}

	/**
	 * Get first product
	 *
	 * @param $model
	 *
	 * @return false|mixed
	 */
	public static function getFirstProduct( $model ) {
		foreach ( $model as $product ) {
			if ( $product->rating === 5 || self::getProductStatus( $product, $product->total_stock ) == 'publish' ) {
				return $product;
			}
		}

		return reset( $model );
	}

	/**
	 * Add WooCommerce product
	 * @param $group_id
	 * @param $oasisProduct
	 * @param $model
	 * @param $categories
	 * @param $totalStock
	 * @param string $type
	 *
	 * @return int|void
	 */
	public static function addWcProduct($group_id, $oasisProduct, $model, $categories, $totalStock, string $type ) {
		try {
			$dataPrice = self::getDataPrice($oasisProduct);

			$wcProduct = self::getWcProductObjectType( $type );
			$wcProduct->set_name( $oasisProduct->name );
			$wcProduct->set_description( self::preparePostContent( $oasisProduct ) );
			$wcProduct->set_category_ids( $categories );
			$wcProduct->set_slug( self::getUniquePostName( $oasisProduct->name, 'product' ) );
			$wcProduct->set_manage_stock( true );
			$wcProduct->set_status( self::getProductStatus( $oasisProduct, $totalStock ) );
			$wcProduct->set_price( $dataPrice['price'] );
			$wcProduct->set_regular_price( $dataPrice['regular_price'] );
			$wcProduct->set_sale_price( $dataPrice['sale_price'] );
			$wcProduct->set_stock_quantity( $totalStock );
			$wcProduct->set_backorders( $oasisProduct->rating === 5 ? 'yes' : 'no' );
			$wcProduct->set_attributes( self::prepareProductAttributes( $oasisProduct, $model ) );
			$wcProduct->set_reviews_allowed(self::$cf->is_comments);

			$defaultAttr = self::getProductDefaultAttributes( $oasisProduct->id, $model );
			if ( $defaultAttr ) {
				$wcProduct->set_default_attributes( $defaultAttr );
			}

			if ($type == 'simple') {
				$wcProduct->set_sku( $oasisProduct->article );
			}

			$wcProductId = $wcProduct->save();
			if(!self::$cf->is_fast_import){
				$images = self::processingPhoto($oasisProduct->images, $wcProductId);
				$wcProduct->set_image_id(array_shift($images));
				$wcProduct->set_gallery_image_ids($images);	
				$wcProduct->save();
			}

			self::updateWcProductBrand($wcProductId, $oasisProduct);
			self::addProductOasisTable($wcProductId, $oasisProduct->id, $group_id, 'product');
			self::$cf->log('Добавлен товар id '.$oasisProduct->id);
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;

			if ( $exception->getErrorCode() == 'product_invalid_sku' ) {
				self::deleteWcProductBySky( $oasisProduct );
			} else {
				die();
			}
		}

		return $wcProductId ?? false;
	}

	/**
	 * Up product
	 *
	 * @param $productId
	 * @param $model
	 * @param $categories
	 * @param $totalStock
	 *
	 * @return bool|void
	 */
	public static function upWcProduct($productId, $model, $categories, $totalStock) {
		$oasisProduct = self::getFirstProduct( $model );
		$dataPrice    = self::getDataPrice($oasisProduct);

		try {
			$wcProduct = wc_get_product( $productId );

			if ( self::checkWcProductType( $wcProduct, $model ) ) {
				if ( $wcProduct === false ) {
					throw new Exception( 'Error open product. No product with this ID' );
				}

				$wcProduct->set_name( $oasisProduct->name );
				$wcProduct->set_description( self::preparePostContent( $oasisProduct ) );
				$wcProduct->set_manage_stock( true );
				$wcProduct->set_status( self::getProductStatus( $oasisProduct, $totalStock ) );
				$wcProduct->set_price( $dataPrice['price'] );
				$wcProduct->set_regular_price( $dataPrice['regular_price'] );
				$wcProduct->set_sale_price( $dataPrice['sale_price'] );
				$wcProduct->set_stock_quantity( (int) $totalStock );
				$wcProduct->set_backorders( $oasisProduct->rating === 5 ? 'yes' : 'no' );
				$wcProduct->set_attributes( self::prepareProductAttributes( $oasisProduct, $model ) );
				$wcProduct->set_reviews_allowed(self::$cf->is_comments);
				$wcProduct->set_date_modified( time() );

				if($categories){
					$wcProduct->set_category_ids( $categories );
				}

				$defaultAttr = self::getProductDefaultAttributes( $oasisProduct->id, $model );
				if ( $defaultAttr ) {
					$wcProduct->set_default_attributes( $defaultAttr );
				}

				if (self::$cf->is_up_photo || self::checkImages( $oasisProduct->images, $wcProduct ) === false) {
					self::deleteImgInProduct( $wcProduct );
					$images = self::processingPhoto( $oasisProduct->images, $productId );
					$wcProduct->set_image_id( array_shift( $images ) );
					$wcProduct->set_gallery_image_ids( $images );
				}

				$wcProduct->save();
				self::$cf->log('Обновлен товар OAId='.$oasisProduct->id.' add WPId=' . $productId);

				return true;
			} else {
				self::deleteWcProduct( $wcProduct );
				self::$cf->log('Некорректный тип, товар удален id ' . $oasisProduct->id);

				return false;
			}
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;
			die();
		}
	}

	/**
	 * Add product image
	 *
	 * @param $productId
	 * @param $model
	 * @param $is_up
	 *
	 * @return bool|void
	 */
	public static function wcProductAddImage($productId, $model, $is_up = false) {
		$oasisProduct = self::getFirstProduct( $model );

		try {
			$wcProduct = wc_get_product( $productId );

			if (empty($wcProduct)) {
				throw new Exception( 'Error open product. No product with this ID' );
			}
			if(!$is_up && !empty($wcProduct->get_image_id())){
				return true;
			}

			self::deleteImgInProduct( $wcProduct );
			$images = self::processingPhoto( $oasisProduct->images, $productId );
			$wcProduct->set_image_id( array_shift( $images ) );
			$wcProduct->set_gallery_image_ids( $images );
			$wcProduct->set_date_modified(time());
			$wcProduct->save();
			return true;
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;
			die();
		}
	}

	/**
	 * Add variation
	 * @param $group_id
	 * @param $productId
	 * @param $oasisProduct
	 * @return int|void|null
	 */
	public static function addWcVariation($group_id, $productId, $oasisProduct) {
		try {
			$dataPrice = self::getDataPrice($oasisProduct);

			$wcVariation = new WC_Product_Variation();
			$wcVariation->set_name( $oasisProduct->full_name );
			$wcVariation->set_manage_stock( true );
			$wcVariation->set_sku( $oasisProduct->article );
			$wcVariation->set_parent_id( $productId );
			$wcVariation->set_slug( self::getUniquePostName( $oasisProduct->name, 'product_variation' ) );
			$wcVariation->set_status( self::getProductStatus( $oasisProduct, $oasisProduct->total_stock, true ) );
			$wcVariation->set_price( $dataPrice['price'] );
			$wcVariation->set_regular_price( $dataPrice['regular_price'] );
			$wcVariation->set_sale_price( $dataPrice['sale_price'] );
			$wcVariation->set_stock_quantity( intval( $oasisProduct->total_stock ) );
			$wcVariation->set_backorders( $oasisProduct->rating === 5 ? 'yes' : 'no' );

			$attributes = self::getVariationAttributes( $oasisProduct );
			if ( $attributes ) {
				$wcVariation->set_attributes( $attributes );
			}

			$wcVariationId = $wcVariation->save();

			if ($oasisProduct->images && !self::$cf->is_fast_import) {
				$images = self::processingPhoto( [ reset( $oasisProduct->images ) ], $wcVariationId );
				$wcVariation->set_image_id( array_shift( $images ) );
				$wcVariation->save();
			}

			self::addProductOasisTable( $wcVariationId, $oasisProduct->id, $group_id, 'product_variation', $oasisProduct->parent_size_id );
			self::$cf->log('Добавлен вариант id ' . $oasisProduct->id);
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;

			if ( $exception->getErrorCode() == 'product_invalid_sku' ) {
				self::deleteWcProductBySky( $oasisProduct );
			} else {
				die();
			}
		}

		return $wcVariationId ?? null;
	}

	/**
	 * Up variation
	 *
	 * @param $dbVariation
	 * @param $oasisProduct
	 */
	public static function upWcVariation($dbVariation, $oasisProduct) {
		try {
			$dataPrice   = self::getDataPrice($oasisProduct);
			$wcVariation = wc_get_product( $dbVariation['post_id'] );

			if ( $wcVariation === false ) {
				throw new Exception( 'Error open variation. No variation with this ID' );
			}

			$wcVariation->set_name( $oasisProduct->full_name );
			$wcVariation->set_manage_stock( true );
			$wcVariation->set_status( self::getProductStatus( $oasisProduct, $oasisProduct->total_stock, true ) );
			$wcVariation->set_price( $dataPrice['price'] );
			$wcVariation->set_regular_price( $dataPrice['regular_price'] );
			$wcVariation->set_sale_price( $dataPrice['sale_price'] );
			$wcVariation->set_stock_quantity( (int) $oasisProduct->total_stock );
			$wcVariation->set_backorders( $oasisProduct->rating === 5 ? 'yes' : 'no' );
			$wcVariation->set_date_modified( time() );

			$attributes = self::getVariationAttributes( $oasisProduct );
			if ( $attributes ) {
				$wcVariation->set_attributes( $attributes );
			}

			if (self::$cf->is_up_photo || self::checkImages( $oasisProduct->images, $wcVariation ) === false) {
				self::deleteImgInProduct( $wcVariation );
				$images = self::processingPhoto( [ reset( $oasisProduct->images ) ], $dbVariation['post_id'] );
				$wcVariation->set_image_id( array_shift( $images ) );
			}

			$wcVariation->save();

			self::$cf->log('Обновлен вариант OAId='.$oasisProduct->id.' add WPId=' . $dbVariation['post_id']);
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;
			die();
		}
	}

	/**
	 * Add variation image
	 *
	 * @param $dbVariation
	 * @param $oasisProduct
	 * @param $is_up
	 */
	public static function wcVariationAddImage($dbVariation, $oasisProduct, $is_up = false) {
		try {
			$wcVariation = wc_get_product($dbVariation['post_id']);

			if ($wcVariation === false) {
				throw new Exception('Error open variation. No variation with this ID');
			}
			if(!$is_up && !empty($wcVariation->get_image_id())){
				return true;
			}

			self::deleteImgInProduct($wcVariation);
			$images = self::processingPhoto([reset($oasisProduct->images)], $dbVariation['post_id']);
			$wcVariation->set_image_id(array_shift($images));
			$wcVariation->set_date_modified(time());
			$wcVariation->save();
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;
			die();
		}
	}

	/**
	 * Add Brand in WooCommerce product
	 *
	 * @param $wcProductId
	 * @param $oasisProduct
	 *
	 * @return void
	 */
	public static function updateWcProductBrand($wcProductId, $oasisProduct): void
	{
		if(self::$cf->is_brands && !empty($oasisProduct->brand_id)){
			$brand = self::$brands[$oasisProduct->brand_id] ?? null;
			if($brand){
				if(!isset($brand['term_id'])){
					$term_id = self::getTermIdByOasisBrand($brand);
					self::$brands[$oasisProduct->brand_id]['term_id'] = $brand['term_id'] = $term_id;
				}
				if(!empty($brand['term_id'])){
					wp_set_object_terms($wcProductId, $brand['term_id'], 'product_brand');
				}
			}
		}
	}

	/**
	 * Check and delete product by Oasis product id
	 *
	 * @param $productId
	 */
	public static function checkDeleteProduct( $productId ) {
		global $wpdb;

		$dbResults = $wpdb->get_results(
			$wpdb->prepare( "
				SELECT op.post_id, p.ID 
				FROM {$wpdb->prefix}oasis_products op
				LEFT JOIN {$wpdb->prefix}posts p
				ON op.post_id = p.ID
				WHERE op.product_id_oasis LIKE '%s'",
				$productId
			),
			ARRAY_A
		);

		if ( $dbResults ) {
			foreach ( $dbResults as $dbResult ) {
				if ( ! is_null( $dbResult['ID'] ) ) {
					$wcProduct = wc_get_product( intval( $dbResult['post_id'] ) );

					if ( $wcProduct ) {
						self::deleteWcProduct( $wcProduct );
					}
				}

				$wpdb->delete( $wpdb->prefix . 'oasis_products', [ 'post_id' => intval( $dbResult['post_id'] ) ] );
			}
		}
	}

	/**
	 * Delete woocommerce product by sky
	 *
	 * @param $product
	 */
	private static function deleteWcProductBySky( $product ) {
		$wcProductID = wc_get_product_id_by_sku( $product->article );

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
	private static function deleteWcProduct( $wcProduct ) {
		global $wpdb;

		if ( $wcProduct->is_type( 'variable' ) ) {
			foreach ( $wcProduct->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				$child->delete( true );
				$wpdb->delete( $wpdb->prefix . 'oasis_products', [ 'post_id' => intval( $child_id ) ] );
			}
		}

		$wpdb->delete( $wpdb->prefix . 'oasis_products', [ 'post_id' => intval( $wcProduct->get_id() ) ] );
		$wcProduct->delete( true );
	}

	/**
	 * Check woocommerce product type
	 *
	 * @param $wcProduct
	 * @param $model
	 *
	 * @return bool
	 */
	public static function checkWcProductType( $wcProduct, $model ): bool {
		$type = count( $model ) > 1 ? 'variable' : 'simple';

		return $wcProduct->get_type() === $type;
	}

	/**
	 * Calculation price
	 *
	 * @param $product
	 *
	 * @return array
	 */
	public static function getDataPrice($product): array {
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
	 * @param $oasisCategories
	 *
	 * @return array
	 */
	public static function getProductCategories($product, $oasisCategories): array {
		$result = [];
		
		foreach ($product->categories as $oasis_cat_id) {
			$rel_id = self::$cf->getRelCategoryId($oasis_cat_id);

			if(isset($rel_id)){
				$parents = self::getTermParents($rel_id);
				$result = array_merge($result, array_map(fn($x) => $x->term_id, $parents));
			}
			else{
				$full_categories = self::getOasisParentsCategoriesId($oasis_cat_id, $oasisCategories);

				foreach ($full_categories as $categoryId) {
					$result[] = self::getCategoryId( $oasisCategories, $categoryId );
				}
			}
		}
		return $result;
	}

	/**
	 * Get product object
	 *
	 * @param string $type
	 *
	 * @return void|WC_Product|WC_Product_Simple|WC_Product_Variable
	 */
	public static function getWcProductObjectType( string $type = 'simple' ) {
		try {
			if ( $type === 'variable' ) {
				$product = new WC_Product_Variable();
			} else {
				$product = new WC_Product_Simple();
			}

			if ( ! is_a( $product, 'WC_Product' ) ) {
				throw new Exception( 'Error get product object type' );
			}
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;
			die();
		}

		return $product;
	}

	/**
	 * Get total stock products
	 *
	 * @param array $products
	 *
	 * @return int
	 */
	public static function getTotalStock( array $products ): int {
		$result = 0;

		foreach ( $products as $product ) {
			$result += intval( $product->total_stock );
		}
		unset( $products, $product );

		return $result;
	}

	/**
	 * Get product attributes
	 *
	 * @param $product
	 * @param array $models
	 *
	 * @return array
	 */
	public static function prepareProductAttributes( $product, array $models = [] ): array {
		$wcAttributes = [];
		$dataSize     = self::getProductAttributeSize( $models );

		if ( ! empty( $dataSize ) ) {
			$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( self::$attrVariation['size']['name'], $dataSize, self::$attrVariation['size']['slug'] ), $dataSize, true, count( $models ) > 1 );
			unset( $dataSize );
		}

		foreach ( $product->attributes as $attribute ) {
			if ( isset( $attribute->id ) && $attribute->id == '1000000001' ) {
				foreach ( $models as $model ) {
					if ( ! empty( $model->colors ) ) {
						foreach ( $model->colors as $color ) {
							$filterColors[] = $color->parent_id;
						}
						unset( $color );
					}
				}
				unset( $model );

				if ( ! empty( $filterColors ) ) {
					$filterColors   = self::checkColorsToFilter( $filterColors );
					$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( 'Фильтр цвет', $filterColors, 'color' ), $filterColors, false, false );
					unset( $filterColors );
				}

				$dataColor = self::getProductAttributeColor( $attribute, $models );

				if ( ! empty( $dataColor ) ) {
					sort( $dataColor );
					$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( self::$attrVariation['color']['name'], $dataColor, self::$attrVariation['color']['slug'] ), $dataColor, true, count( $models ) > 1 );
					unset( $dataColor );
				}
			} elseif ( isset( $attribute->id ) && $attribute->id == '1000000008' ) {
				$branding['attr']['attribute_id']       = 0;
				$branding['attr']['attribute_taxonomy'] = $attribute->name;
				$branding['value'][]                    = trim( $attribute->value );
			} elseif ( isset( $attribute->id ) && $attribute->id == '65' ) {
				$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( 'Пол', [ $attribute->value ] ), [ $attribute->value ], true, false );
			} elseif ( isset( $attribute->id ) && $attribute->id == '1000000002' ) {
				$materials      = self::getStandardAttributeMaterial( $attribute->value );
				$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( 'Материал (фильтр)', $materials, 'material' ), $materials, false, false );
				$wcAttributes[] = self::getWcObjectProductAttribute(
					[
						'attribute_id'       => 0,
						'attribute_taxonomy' => $attribute->name
					],
					[ trim( $attribute->value ) ],
					true,
					false
				);
				unset( $materials );
			} elseif ( $attribute->name != 'Размер' ) {
				$wcAttributes[] = self::getWcObjectProductAttribute(
					[
						'attribute_id'       => 0,
						'attribute_taxonomy' => $attribute->name
					],
					[ trim( $attribute->value ) . ( ! empty( $attribute->dim ) ? ' ' . $attribute->dim : '' ) ],
					true,
					false
				);
			}
		}

		if ( ! empty( $branding ) ) {
			$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( $branding['attr']['attribute_taxonomy'], $branding['value'] ), $branding['value'], true, false );
			unset( $branding );
		}

		return $wcAttributes;
	}

	/**
	 * Get product default attributes
	 *
	 * @param $productId
	 * @param $model
	 *
	 * @return array
	 */
	public static function getProductDefaultAttributes( $productId, $model ): array {
		if (count($model) > 1 ) {
			foreach ($model as $product) {
				if (!empty($product->size)) {
					if ($product->id == $productId) {
						$taxonomy = sanitize_title('pa_' . self::$attrVariation['size']['slug']);
						$term = self::getTermByName($product->size, $taxonomy);
						$result[$taxonomy] = $term->slug;
					}
				}

				foreach ($product->attributes as $attribute) {
					if (isset($attribute->id) && $attribute->id == '1000000001') {
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

		return $result ?? [];
	}

	/**
	 * Get product attribute color
	 *
	 * @param $attribute
	 * @param $models
	 *
	 * @return array
	 */
	public static function getProductAttributeColor( $attribute, $models ): array {
		$attrValues = [];

		if ( count( $models ) > 1 ) {
			foreach ( $models as $model ) {
				foreach ( $model->attributes as $modelAttribute ) {
					if ( isset( $modelAttribute->id ) && $modelAttribute->id == '1000000001' ) {
						$attrValues[] = trim( $modelAttribute->value );
						break;
					}
				}
			}

			sort( $attrValues );

			$result = array_unique( $attrValues );
		} elseif ( count( $models ) == 1 ) {
			$result = [ trim( $attribute->value ) ];
		}

		return $result ?? [];
	}

	/**
	 * Get product attribute size
	 *
	 * @param $models
	 *
	 * @return array
	 */
	public static function getProductAttributeSize( $models ): array {
		if ( count( $models ) > 1 ) {
			$result = [];

			foreach ( $models as $product ) {
				$size = trim($product->size ?? '');

				if ( ! empty( $size ) ) {
					$result[] = $size;
				}
			}

			$result = self::sortSizeByStandard( $result );
		}

		return $result ?? [];
	}

	/**
	 * Sort sizes according to standard. Returns unique values
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public static function sortSizeByStandard( array $data ): array {
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

		usort($data, function ( $key1, $key2 ) use ( $etalonSizes ) {
			return array_search( $key1, $etalonSizes ) <=> array_search( $key2, $etalonSizes );
		});

		return array_values( array_unique( $data ) );
	}

	/**
	 * Get variation parent size id
	 *
	 * @param $variation
	 *
	 * @return int|null
	 */
	public static function getVariationParentSizeId( $variation ): ?int {
		global $wpdb;

		$dbResults = $wpdb->get_results( "
SELECT * FROM {$wpdb->prefix}oasis_products 
WHERE variation_parent_size_id = '" . $variation->parent_size_id . "'
	AND type = 'product_variation'
", ARRAY_A );

		if ( $dbResults ) {
			$post = get_post( reset( $dbResults )['post_id'] );

			if ( ! $post ) {
				$wpdb->update( $wpdb->prefix . 'oasis_products',
					[ 'variation_parent_size_id' => null ],
					[ 'post_id' => reset( $dbResults )['post_id'], 'type' => 'product_variation' ] );
			} else {
				$result = $post->ID;
			}
		}

		return $result ?? null;
	}

	/**
	 * Get product id oasis by order item
	 *
	 * @param $item
	 *
	 * @return string|null
	 */
	public static function getOasisProductIdByOrderItem( $item ): ?string {
		return Main::getOasisProductIdByPostId( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
	}

	/**
	 * Get oasis product id by post_id
	 *
	 * @param $postId
	 *
	 * @return string|null
	 */
	public static function getOasisProductIdByPostId( $postId ): ?string {
		global $wpdb;

		$dbResults = $wpdb->get_row( "
SELECT `product_id_oasis` FROM {$wpdb->prefix}oasis_products
WHERE `post_id` = " . intval( $postId ), ARRAY_A );

		return ! empty( $dbResults['product_id_oasis'] ) ? strval( $dbResults['product_id_oasis'] ) : null;
	}

	/**
	 * Delete oasis product for post_id
	 * @param $post_id
	 * @return void
	 */
	public static function deleteOasisProductByPostId($post_id) {
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'oasis_products', [ 'post_id' => $post_id ] );
	}

	/**
	 * Get object wc product attribute
	 * @param $attr
	 * @param $values
	 * @param bool $visible
	 * @param bool $variation
	 * @return WC_Product_Attribute
	 */
	public static function getWcObjectProductAttribute($attr, array $values, bool $visible, bool $variation): WC_Product_Attribute {
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
		$attribute->set_id($attr['attribute_id']);
		$attribute->set_name($attr['attribute_taxonomy']);
		$attribute->set_visible($visible);
		$attribute->set_options($options);
		$attribute->set_variation($variation);

		return $attribute;
	}

	/**
	 * Get variation attributes
	 * @param $variation
	 * @return array
	 */
	public static function getVariationAttributes($variation): array {
		if (!empty( $variation->size)) {
			$taxonomy = sanitize_title( 'pa_' . self::$attrVariation['size']['slug'] );
			$term = self::getTermByName($variation->size, $taxonomy);
			$result[$taxonomy] = $term->slug;
		}

		foreach ($variation->attributes as $attribute) {
			if (isset( $attribute->id ) && $attribute->id == '1000000001') {
				$taxonomy = sanitize_title('pa_' . self::$attrVariation['color']['slug']);
				$term = self::getTermByName($attribute->value, $taxonomy);
				$result[$taxonomy] = $term->slug;
			}
		}
		return $result ?? [];
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
	 *
	 * @return array
	 */
	public static function createAttribute( string $raw_name, array $terms, string $slug = '' ): array {
		global $wc_product_attributes;

		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

		$attribute_labels = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_label', 'attribute_name' );
		$attribute_name   = array_search( empty( $slug ) ? $raw_name : $slug, $attribute_labels, true );
		if ( ! $attribute_name ) {
			$attribute_name = wc_sanitize_taxonomy_name( empty( $slug ) ? $raw_name : $slug );
		}

		$attribute_name = substr( self::transliteration( $attribute_name ), 0, 27 );
		$attribute_id   = wc_attribute_taxonomy_id_by_name( $attribute_name );

		if ( ! $attribute_id ) {
			$taxonomy_name = wc_attribute_taxonomy_name( $attribute_name );
			unregister_taxonomy( $taxonomy_name );

			$attribute_id = wc_create_attribute( [
				'name'         => $raw_name,
				'slug'         => $attribute_name,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => 0,
			] );

			register_taxonomy(
				$taxonomy_name,
				apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy_name, [ 'product' ] ),
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

			foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
				$wc_product_attributes[ wc_attribute_taxonomy_name( $taxonomy->attribute_name ) ] = $taxonomy;
			}
		}

		$attribute = wc_get_attribute( $attribute_id );
		$return    = [
			'attribute_name'     => $attribute->name,
			'attribute_taxonomy' => $attribute->slug,
			'attribute_id'       => $attribute_id,
			'term_ids'           => [],
		];

		foreach ( $terms as $term ) {
			$result = term_exists( $term, $attribute->slug );
			if ( ! $result ) {
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
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public static function checkColorsToFilter( $data ): array {
		$data   = array_unique( $data );
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

		foreach ( $data as $item ) {
			if ( ! empty( $colors[ $item ] ) ) {
				$result[] = $colors[ $item ];
			}
		}

		return $result;
	}

	/**
	 * Get unique post_name by post_title
	 *
	 * @param $name
	 * @param $post_type
	 * @param null $productId
	 * @param int $count
	 *
	 * @return string
	 */
	public static function getUniquePostName( $name, $post_type, $productId = null, int $count = 0 ): string {
		$post_name = self::transliteration( $name );

		if ( ! empty( $count ) ) {
			$post_name = $post_name . '-' . $count;
		}

		$dbPosts = get_posts( [
			'name'        => $post_name,
			'post_type'   => $post_type,
			'post_status' => [ 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash', 'any' ],
			'exclude'     => ! empty( $productId ) ? intval( $productId ) : '',
		] );

		if ( $dbPosts ) {
			$post_name = self::getUniquePostName( $name, $post_type, $productId, ++ $count );
		}
		unset( $name, $post_type, $productId, $count, $dbPosts );

		return $post_name;
	}

	/**
	 * Prepare post content
	 *
	 * @param $product
	 *
	 * @return string
	 */
	public static function preparePostContent( $product ): string {
		$result = ! empty( $product->description ) ? $product->description : '';

		if ( ! empty( $product->defect ) ) {
			$result .= PHP_EOL . '<p>' . trim( $product->defect ) . '</p>';
		}

		return $result;
	}

	/**
	 * Get id category woocommerce
	 *
	 * @param $categories
	 * @param $categoryId
	 *
	 * @return int
	 */
	public static function getCategoryId( $categories, $categoryId ): int {
		$terms = self::getTermsByCatId( $categoryId );

		if ( ! $terms ) {
			$category      = self::searchObject( $categories, $categoryId );
			$parentTermsId = 0;

			if ( ! is_null( $category->parent_id ) ) {
				$parentTerms = self::getTermsByCatId( $category->parent_id );

				if ( $parentTerms ) {
					$parentTermsId = $parentTerms->term_id;
				} else {
					$parentTermsId = self::getCategoryId( $categories, $category->parent_id );
				}
			}

			$result = self::addCategory( $category, $parentTermsId );
		} else {
			$result = (int) $terms->term_id;
		}
		unset( $categories, $categoryId, $terms, $category, $parentTermsId, $parentTerms );

		return $result;
	}

	/**
	 * Add category
	 *
	 * @param $category
	 * @param $parentTermsId
	 *
	 * @return int
	 */
	public static function addCategory( $category, $parentTermsId ): int {
		require_once ABSPATH . '/wp-admin/includes/taxonomy.php';

		$data = [
			'cat_name'             => $category->name,
			'category_description' => '',
			'category_nicename'    => $category->slug,
			'category_parent'      => $parentTermsId,
			'taxonomy'             => 'product_cat',
		];

		$cat_id = wp_insert_category( $data );
		update_term_meta( $cat_id, 'oasis_cat_id', $category->id );
		unset( $category, $parentTermsId, $data );

		return $cat_id;
	}

	/**
	 * Get terms by oasis id category
	 *
	 * @param $categoryId
	 *
	 * @return object|array
	 */
	public static function getTermsByCatId( $categoryId ) {
		$args = [
			'taxonomy'   => [ 'product_cat' ],
			'hide_empty' => false,
			'meta_query' => [
				[
					'key'   => 'oasis_cat_id',
					'value' => $categoryId,
				]
			],
		];

		$terms = get_terms( $args );
		unset( $categoryId, $args );

		return $terms ? reset( $terms ) : [];
	}

	/**
	 * Get terms by oasis id category
	 *
	 * @param $brand
	 *
	 * @return int|false
	 */
	public static function getTermIdByOasisBrand($brand) {
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
	 *
	 * @return array
	 */
	public static function getTermParents($term_id): array {
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
	 *
	 * @return false|mixed|null
	 */
	public static function searchObject( $data, $id ) {
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
	 *
	 * @param $product
	 * @param $stock
	 * @param bool $variation
	 *
	 * @return string
	 */
	public static function getProductStatus( $product, $stock, bool $variation = false ): string {
		if ( $product->is_deleted === true ) {
			$result = 'trash';
		} elseif ( intval( $stock ) === 0 || $product->is_stopped === true ) {
			$result = $variation ? 'private' : 'draft';
		} else {
			$result = 'publish';
		}
		unset( $product, $stock, $variation );

		return $result;
	}

	/**
	 * Get categories level 1
	 *
	 * @param null $categories
	 *
	 * @return array
	 */
	public static function getOasisMainCategories( $categories = null ): array {
		$result = [];

		if ( ! $categories ) {
			$categories = Api::getCategoriesOasis();
		}

		foreach ( $categories as $category ) {
			if ( $category->level === 1 ) {
				$result[] = $category->id;
			}
		}
		unset( $categories, $category );

		return $result;
	}

	/**
	 * Get oasis parents id categories
	 *
	 * @param null $cat_id
	 * @param null $oasisCategories
	 *
	 * @return array
	 */
	public static function getOasisParentsCategoriesId($cat_id, $oasisCategories): array {
		$result = [];
		$parent_id = $cat_id;

		while($parent_id){
			foreach ($oasisCategories as $category) {
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
	 *
	 * @return string
	 */
	public static function buildTreeCats($data, array $checkedArr = [], array $relCategories = [], int $parent_id = 0, bool $parent_checked = false): string {
		$treeItem = '';
		if ( ! empty( $data[ $parent_id ] ) ) {
			foreach($data[ $parent_id ] as $item){
				$checked = $parent_checked || in_array($item['id'], $checkedArr);

				$rel_cat = $relCategories[$item['id']] ?? null;
				$rel_label = '';
				$rel_value = '';
				if($rel_cat){
					$rel_value = $item['id'].'_'.$rel_cat['id'];
					$rel_label = $rel_cat['rel_label'];
				}

				$treeItemChilds = self::buildTreeCats($data, $checkedArr, $relCategories, $item['id'], $checked);

				if(empty($treeItemChilds)){
					$treeItem .= '<div class="oa-tree-leaf">
						<div class="oa-tree-label ' . ($rel_value ? 'relation-active' : '') . '">
							<input type="hidden" class="oa-tree-inp-rel" name="oasis_options[cat_relation][]" value="' . $rel_value . '" />
							<label>
								<input type="checkbox" class="oa-tree-cb-cat" name="oasis_options[categories][]" value="' . $item['id'] . '"' . ($checked ? ' checked' : '' ) . '/>
								<div class="oa-tree-btn-relation"></div>' . $item['name'] . '
							</label>
							<div class="oa-tree-dashed"></div>
							<div class="oa-tree-relation">' . $rel_label . '</div>
						</div>
					</div>';
				}
				else{
					$treeItem .= '<div class="oa-tree-node oa-tree-collapsed">
						<div class="oa-tree-label ' . ($rel_value ? 'relation-active' : '') . '">
							<input type="hidden" class="oa-tree-inp-rel"  name="oasis_options[cat_relation][]" value="' . $rel_value . '" />
							<span class="oa-tree-handle-p">+</span>
							<span class="oa-tree-handle-m">-</span>
							<label>
								<input type="checkbox" class="oa-tree-cb-cat" name="oasis_options[categories][]" value="' . $item['id'] . '"' . ($checked ? ' checked' : '' ) . '/>
								<div class="oa-tree-btn-relation"></div>' . $item['name'] . '
							</label>
							<div class="oa-tree-dashed"></div>
							<div class="oa-tree-relation">' . $rel_label . '</div>
						</div>
						<div class="oa-tree-childs">' . $treeItemChilds . '</div>
					</div>';
				}
			}
		}

		return $treeItem ?? '';
	}

	/**
	 * Build tree categories
	 *
	 * @param $data
	 * @param int $checked_id
	 * @param int $parent_id
	 *
	 * @return string
	 */
	public static function buildTreeRadioCats( $data, ?array $checked_id = null, int $parent_id = 0 ): string {
		$treeItem = '';
		if ( ! empty( $data[ $parent_id ] ) ) {
			foreach($data[ $parent_id ] as $item){
				$checked = $checked_id === $item['id'];

				$treeItemChilds = self::buildTreeRadioCats( $data, $checked_id, $item['id'] );

				if(empty($treeItemChilds)){
					$treeItem .= '<div class="oa-tree-leaf">
						<div class="oa-tree-label">
							<label><input type="radio" name="oasis_radio_tree" value="' . $item['id'] . '"' . $checked . '/>' . $item['name'] . '</label>
						</div>
					</div>';
				}
				else{
					$treeItem .= '<div class="oa-tree-node oa-tree-collapsed">
						<div class="oa-tree-label">
							<span class="oa-tree-handle-p">+</span>
							<span class="oa-tree-handle-m">-</span>
							<label><input type="radio" name="oasis_radio_tree" value="' . $item['id'] . '"' . $checked . '/>' . $item['name'] . '</label>
						</div>
						<div class="oa-tree-childs">' . $treeItemChilds . '</div>
					</div>';
				}
			}
		}

		return $treeItem ?? '';
	}

	/**
	 * String transliteration for url
	 *
	 * @param $string
	 *
	 * @return string
	 */
	public static function transliteration( $string ): string {
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
	 *
	 * @param $str
	 *
	 * @return array
	 */
	static public function getStandardAttributeMaterial( $str ): array {
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
			'пластик'            => [
				'пластик',
				'ПВХ'
			],
			'металл'             => [
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
			'драгметалл'         => [
				'серебро',
				'посеребрение',
				'золото',
				'позолота',
				'позолочение'
			],
			'дерево'             => [
				'дерево',
				'МДФ'
			],
			'искусственная кожа' => [
				'искусственная кожа',
				'кожзам'
			],
			'натуральная кожа'   => [
				'натуральная кожа',
				'телячья кожа'
			],
			'бумага'             => [
				'бумага',
				'картон'
			],
			'камень'             => [
				'камень',
				'мрамор',
				'гранит'
			],
			'soft-touch'         => [
				'soft-touch',
				'софт-тач'
			],
		];

		foreach ( $attributes as $key => $attribute ) {
			if ( is_array( $attribute ) ) {
				foreach ( $attribute as $subAttribute ) {
					if ( strpos( $str, $subAttribute ) !== false ) {
						$result[] = $key;
						break;
					}
				}
			} else {
				if (
					strpos( $str, $attribute ) !== false
					&& strpos( $str, 'стекловолокно' ) === false
					&& strpos( $str, 'стеклопластик' ) === false
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
	 * @param $product_id
	 *
	 * @return array
	 */
	public static function processingPhoto($images, $product_id): array {
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
					wp_update_post([
						'ID'			=> $existImageId,
						'post_parent'	=> $product_id,
					]);
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
						'post_parent'    => $product_id,
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
							'post_parent'    => $product_id,
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
	 *
	 * Usage:
	 *
	 * Check is good - true
	 *
	 * Check is bad - false
	 *
	 * @param $images
	 * @param $wcProduct
	 *
	 * @return bool
	 */
	public static function checkImages( $images, $wcProduct ): bool {
		$db_images = get_post_meta( $wcProduct->get_id(), '_thumbnail_id' );

		if ( empty( intval( reset( $db_images ) ) ) ) {
			return false;
		}

		if ( $wcProduct->get_type() == 'variation' ) {
			$images = [ reset( $images ) ];
		} else {
			$db_images = array_merge( $db_images, $wcProduct->get_gallery_image_ids() );
		}

		if ( count( $images ) !== count( $db_images ) ) {
			return false;
		}

		$posts = get_posts( [
			'numberposts' => - 1,
			'post_type'   => 'attachment',
			'include'     => implode( ',', $db_images )
		] );

		if ( empty( $posts ) ) {
			return false;
		}

		$attachments = [];

		foreach ( $posts as $post ) {
			$attachments[] = (array) $post;
		}
		unset( $posts, $post );

		foreach ( $images as $image ) {
			if ( empty( $image->superbig ) ) {
				return false;
			}

			$keyNeeded = array_search( basename( $image->superbig ), array_column( $attachments, 'post_title' ) );

			if ( $keyNeeded === false || $image->updated_at > strtotime( $attachments[ $keyNeeded ]['post_date_gmt'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete images in product
	 *
	 * @param $wcProduct
	 *
	 * @return void
	 */
	private static function deleteImgInProduct( $wcProduct ): void {
		$images = get_post_meta( $wcProduct->get_id(), '_thumbnail_id' );

		if ( empty( intval( reset( $images ) ) ) ) {
			$images = [];
		}

		$images = array_merge( $images, $wcProduct->get_gallery_image_ids() );

		foreach ( $images as $imgID ) {
			wp_delete_attachment( $imgID, true );
		}
	}

	/**
	 * Get page by title
	 *
	 * @param $title
	 *
	 * @return int|null
	 */
	public static function getAttachmentIdByTitle( $title ): ?int {
		$query = new WP_Query( [
			'post_type'              => 'attachment',
			'title'                  => sanitize_file_name( $title ),
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

		if ( ! empty( $query->posts ) ) {
			return $query->posts[0];
		} else {
			return null;
		}
	}

	/**
	 * Finding an array within an array.
	 *
	 * @param array $needle The array to be found
	 * @param array $haystack Array to be searched
	 *
	 * @return int|string|null Returns the key of the found array. If not found will return NULL
	 */
	public static function checkDiffArray( array $needle, array $haystack ) {
		ksort( $needle );
		foreach ( $haystack as $key => $value ) {
			ksort( $value );

			if ( $needle === $value ) {
				return $key;
			}
		}

		return null;
	}
}