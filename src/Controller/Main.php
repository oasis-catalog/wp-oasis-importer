<?php

namespace OasisImport\Controller\Oasis;

use Exception;

class Main {

	/**
	 * Check product in table oasis_products
	 *
	 * @param array $where
	 * @param string $type
	 *
	 * @return array|\WP_Post|null
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
	 * @param $dbProduct
	 *
	 * @return false|mixed
	 */
	public static function getFirstProduct( $model, $dbProduct ) {
		foreach ( $model as $item ) {
			if ( $dbProduct ) {
				if ( $dbProduct['product_id_oasis'] == $item->id ) {
					return $item;
				}
			} elseif ( $item->group_id == $item->id ) {
				return $item;
			}
		}

		return reset( $model );
	}

	/**
	 * Add WooCommerce product
	 *
	 * @param $oasisProduct
	 * @param $model
	 * @param $categories
	 * @param $totalStock
	 * @param $options
	 * @param string $type
	 *
	 * @return int|void
	 */
	public static function addWcProduct( $oasisProduct, $model, $categories, $totalStock, $options, string $type ) {
		try {
			$dataPrice = self::getDataPrice( $oasisProduct, $options );

			$wcProduct = self::getWcProductObjectType( $type );
			$wcProduct->set_name( $oasisProduct->name );
			$wcProduct->set_description( self::preparePostContent( $oasisProduct ) );
			$wcProduct->set_category_ids( $categories );
			$wcProduct->set_slug( self::getUniquePostName( $oasisProduct->name, 'product' ) );
			$wcProduct->set_manage_stock( true );
			$wcProduct->set_status( self::getProductStatus( $oasisProduct, $totalStock ) );
			$wcProduct->set_price( $dataPrice['_price'] );
			$wcProduct->set_regular_price( $dataPrice['_regular_price'] );
			$wcProduct->set_sale_price( $dataPrice['_sale_price'] );
			$wcProduct->set_stock_quantity( $totalStock );
			$wcProduct->set_backorders( $oasisProduct->rating === 5 ? 'yes' : 'no' );
			$wcProduct->set_attributes( self::prepareProductAttributes( $oasisProduct, $model ) );
			$wcProduct->set_reviews_allowed( ! empty( $options['oasis_mi_comments'] ) );

			$defaultAttr = self::getProductDefaultAttributes( $oasisProduct->id, $model );
			if ( $defaultAttr ) {
				$wcProduct->set_default_attributes( self::getProductDefaultAttributes( $oasisProduct->id, $model ) );
			}

			if ( $type == 'simple' ) {
				$wcProduct->set_sku( $oasisProduct->article );
			}

			$wcProduct->save();

			$wcProductId = $wcProduct->get_id();
			$images      = self::processingPhoto( $oasisProduct->images, $wcProductId );
			$wcProduct->set_image_id( array_shift( $images ) );
			$wcProduct->set_gallery_image_ids( $images );
			$wcProduct->save();

			self::addProductOasisTable( $wcProductId, $oasisProduct->id, $oasisProduct->group_id, 'product' );
			self::cliMsg( 'Добавлен товар id ' . $oasisProduct->id );
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
	 * @param $options
	 *
	 * @return bool|void
	 */
	public static function upWcProduct( $productId, $model, $categories, $totalStock, $options ) {
		$oasisProduct = reset( $model );
		$dataPrice    = self::getDataPrice( $oasisProduct, $options );

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
				$wcProduct->set_price( $dataPrice['_price'] );
				$wcProduct->set_regular_price( $dataPrice['_regular_price'] );
				$wcProduct->set_sale_price( $dataPrice['_sale_price'] );
				$wcProduct->set_stock_quantity( (int) $oasisProduct->total_stock );
				$wcProduct->set_backorders( $oasisProduct->rating === 5 ? 'yes' : 'no' );
				$wcProduct->set_category_ids( $categories );
				$wcProduct->set_attributes( self::prepareProductAttributes( $oasisProduct, $model ) );
				$wcProduct->set_reviews_allowed( ! empty( $options['oasis_mi_comments'] ) );

				$defaultAttr = self::getProductDefaultAttributes( $oasisProduct->id, $model );
				if ( $defaultAttr ) {
					$wcProduct->set_default_attributes( self::getProductDefaultAttributes( $oasisProduct->id, $model ) );
				}

				$wcProduct->save();
				self::cliMsg( 'Обновлен товар id ' . $oasisProduct->id );

				return true;
			} else {
				self::deleteWcProduct( $wcProduct );
				self::cliMsg( 'Некорректный тип, товар удален id ' . $oasisProduct->id );

				return false;
			}
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;
			die();
		}
	}

	/**
	 * Add variation
	 *
	 * @param $productId
	 * @param $oasisProduct
	 * @param $options
	 *
	 * @return int|void|null
	 */
	public static function addWcVariation( $productId, $oasisProduct, $options ) {
		try {
			$dataPrice = self::getDataPrice( $oasisProduct, $options );

			$wcVariation = new \WC_Product_Variation();
			$wcVariation->set_name( $oasisProduct->full_name );
			$wcVariation->set_manage_stock( true );
			$wcVariation->set_sku( $oasisProduct->article );
			$wcVariation->set_parent_id( $productId );
			$wcVariation->set_slug( self::getUniquePostName( $oasisProduct->name, 'product_variation' ) );
			$wcVariation->set_status( self::getProductStatus( $oasisProduct, $oasisProduct->total_stock, true ) );
			$wcVariation->set_price( $dataPrice['_price'] );
			$wcVariation->set_regular_price( $dataPrice['_regular_price'] );
			$wcVariation->set_sale_price( $dataPrice['_sale_price'] );
			$wcVariation->set_stock_quantity( intval( $oasisProduct->total_stock ) );
			$wcVariation->set_backorders( $oasisProduct->rating === 5 ? 'yes' : 'no' );

			$attributes = self::getVariationAttributes( $oasisProduct );
			if ( $attributes ) {
				$wcVariation->set_attributes( $attributes );
			}

			$wcVariation->save();

            if ( $oasisProduct->images ) {
                $wcVariationId = $wcVariation->get_id();
                $images        = self::processingPhoto( [ reset( $oasisProduct->images ) ], $wcVariationId, self::getVariationParentSizeId( $oasisProduct ) );
                $wcVariation->set_image_id( array_shift( $images ) );
                $wcVariation->save();
            }

			self::addProductOasisTable( $wcVariationId, $oasisProduct->id, $oasisProduct->group_id, 'product_variation', $oasisProduct->parent_size_id );
			self::cliMsg( 'Добавлен вариант id ' . $oasisProduct->id );
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
	 * @param $options
	 */
	public static function upWcVariation( $dbVariation, $oasisProduct, $options ) {
		try {
			$dataPrice   = self::getDataPrice( $oasisProduct, $options );
			$wcVariation = wc_get_product( $dbVariation['post_id'] );

			if ( $wcVariation === false ) {
				throw new Exception( 'Error open variation. No variation with this ID' );
			}

			$wcVariation->set_name( $oasisProduct->full_name );
			$wcVariation->set_manage_stock( true );
			$wcVariation->set_status( self::getProductStatus( $oasisProduct, $oasisProduct->total_stock, true ) );
			$wcVariation->set_price( $dataPrice['_price'] );
			$wcVariation->set_regular_price( $dataPrice['_regular_price'] );
			$wcVariation->set_sale_price( $dataPrice['_sale_price'] );
			$wcVariation->set_stock_quantity( (int) $oasisProduct->total_stock );
			$wcVariation->set_backorders( $oasisProduct->rating === 5 ? 'yes' : 'no' );
			$wcVariation->save();

			self::cliMsg( 'Обновлен вариант id ' . $oasisProduct->id );
		} catch ( Exception $exception ) {
			echo $exception->getMessage() . PHP_EOL;
			die();
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
			self::cliMsg( 'Есть артикул! Oasis Id: ' . $product->id );
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
	 * @param $options
	 *
	 * @return array
	 */
	public static function getDataPrice( $product, $options ): array {
		$price     = ! empty( $options['oasis_mi_dealer'] ) ? $product->discount_price : $product->price;
		$old_price = ! empty( $product->old_price ) ? $product->old_price : null;

		if ( ! empty( $options['oasis_mi_price_factor'] ) ) {
			$price = $price * (float) $options['oasis_mi_price_factor'];

			if ( empty( $options['oasis_mi_dealer'] ) ) {
				$old_price = $old_price * (float) $options['oasis_mi_price_factor'];
			}
		}

		if ( ! empty( $options['oasis_mi_increase'] ) ) {
			$price = $price + (float) $options['oasis_mi_increase'];

			if ( empty( $options['oasis_mi_dealer'] ) ) {
				$old_price = $old_price + (float) $options['oasis_mi_increase'];
			}
		}

		$data['_price'] = $price;

		if ( empty( $options['oasis_mi_disable_sales'] ) && ! empty( $old_price ) && $price < $old_price ) {
			$data['_regular_price'] = $old_price;
			$data['_sale_price']    = $price;
		} else {
			$data['_regular_price'] = $price;
			$data['_sale_price']    = '';
		}
		unset( $product, $options, $price, $old_price );

		return $data;
	}

	/**
	 * Get array IDs WooCommerce categories
	 *
	 * @param $categories
	 * @param $oasisCategories
	 *
	 * @return array
	 */
	public static function getProductCategories( $categories, $oasisCategories ): array {
		$result = [];

		foreach ( $categories as $category ) {
			$result[] = self::getCategoryId( $oasisCategories, $category );
		}
		unset( $categories, $category );

		return $result;
	}

	/**
	 * Get product object
	 *
	 * @param string $type
	 *
	 * @return void|\WC_Product|\WC_Product_Simple|\WC_Product_Variable
	 */
	public static function getWcProductObjectType( string $type = 'simple' ) {
		try {
			if ( $type === 'variable' ) {
				$product = new \WC_Product_Variable();
			} else {
				$product = new \WC_Product_Simple();
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
			$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( 'Размер', $dataSize ), $dataSize, true, count( $models ) > 1 );
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

				if ( ! empty( $filterColors ) ) {
					$filterColors   = self::checkColorsToFilter( array_unique( $filterColors ) );
					$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( 'Фильтр цвет', $filterColors, 'color' ), $filterColors, false, false );
					unset( $filterColors );
				}

				$dataColor = self::getProductAttributeColor( $attribute, $models );

				if ( ! empty( $dataColor ) ) {
					$wcAttributes[] = self::getWcObjectProductAttribute( self::createAttribute( 'Цвет', $dataColor ), $dataColor, true, count( $models ) > 1 );
					unset( $dataColor );
				}
			} elseif ( ! empty( $attribute->id ) && $attribute->id == '1000000008' ) {
				$branding['attr']['attribute_id']       = 0;
				$branding['attr']['attribute_taxonomy'] = $attribute->name;
				$branding['value'][]                    = trim( $attribute->value );
			} elseif ( $attribute->name != 'Размер' ) {
				$attr = [
					'attribute_id'       => 0,
					'attribute_taxonomy' => $attribute->name
				];

				$wcAttributes[] = self::getWcObjectProductAttribute( $attr, [ trim( $attribute->value ) . ( ! empty( $attribute->dim ) ? ' ' . $attribute->dim : '' ) ], true, false );
				unset( $attr );
			}
		}

		if ( ! empty( $branding ) ) {
			$wcAttributes[] = self::getWcObjectProductAttribute( $branding['attr'], $branding['value'], true, false );
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
		if ( count( $model ) > 1 ) {
			foreach ( $model as $product ) {
				if ( ! empty( $product->size ) ) {
					if ( $product->id == $productId ) {
						$result[ sanitize_title( 'pa_' . self::transliteration( wc_sanitize_taxonomy_name( stripslashes( 'Размер' ) ) ) ) ] = sanitize_title( trim( $product->size ) );
					}
				}

				foreach ( $product->attributes as $attribute ) {
					if ( isset( $attribute->id ) && $attribute->id == '1000000001' ) {
						if ( $product->id == $productId ) {
							$result[ sanitize_title( 'pa_' . self::transliteration( wc_sanitize_taxonomy_name( stripslashes( 'Цвет' ) ) ) ) ] = sanitize_title( trim( $attribute->value ) );
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
				if ( ! empty( trim( $product->size ) ) ) {
					$result[] = trim( $product->size );
				}
			}

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

			usort( $result, function ( $key1, $key2 ) use ( $etalonSizes ) {
				return ( array_search( $key1, $etalonSizes ) > array_search( $key2, $etalonSizes ) );
			} );

			$result = array_values( array_unique( $result ) );
		}

		return $result ?? [];
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
	 * Get object wc product attribute
	 *
	 * @param $attr
	 * @param $value
	 * @param bool $visible
	 * @param $variation
	 *
	 * @return \WC_Product_Attribute
	 */
	public static function getWcObjectProductAttribute( $attr, $value, bool $visible, $variation ): \WC_Product_Attribute {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( $attr['attribute_id'] );
		$attribute->set_name( $attr['attribute_taxonomy'] );
		$attribute->set_visible( $visible );
		$attribute->set_options( $value );
		$attribute->set_variation( $variation );

		return $attribute;
	}

	/**
	 * Get variation attributes
	 *
	 * @param $variation
	 *
	 * @return array
	 */
	public static function getVariationAttributes( $variation ): array {
		if ( ! empty( $variation->size ) ) {
			$result[ sanitize_title( 'pa_' . self::transliteration( wc_sanitize_taxonomy_name( stripslashes( 'Размер' ) ) ) ) ] = sanitize_title( trim( $variation->size ) );
		}

		foreach ( $variation->attributes as $attribute ) {
			if ( isset( $attribute->id ) && $attribute->id == '1000000001' ) {
				$result[ sanitize_title( 'pa_' . self::transliteration( wc_sanitize_taxonomy_name( stripslashes( 'Цвет' ) ) ) ) ] = sanitize_title( trim( $attribute->value ) );
			}
		}

		return $result ?? [];
	}

	/**
	 * Create attribute
	 *
	 * @param string $raw_name Name of attribute to create.
	 * @param array(string) $terms Terms to create for the attribute.
	 * @param string $slug
	 *
	 * @return array
	 */
	public static function createAttribute( string $raw_name, array $terms, string $slug = '' ): array {
		global $wc_product_attributes;

		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );

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
				$result = wp_insert_term( $term, $attribute->slug );
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

		$result = wp_insert_category( $data );
		update_term_meta( $result, 'oasis_cat_id', $category->id );
		unset( $category, $parentTermsId, $data );

		return $result;
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
		} elseif ( intval( $stock ) === 0 ) {
			$result = $variation ? 'private' : 'draft';
		} else {
			$result = 'publish';
		}
		unset( $product, $stock, $variation );

		return $result;
	}

	/**
	 * Update progress bar
	 *
	 * @param $progressBar
	 *
	 * @return mixed
	 */
	public static function upProgressBar( $progressBar ) {
		$progressBar['item'] ++;

		if ( isset( $progressBar['step_total'] ) ) {
			$progressBar['step_item'] ++;
		}

		return $progressBar;
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
	 * Build tree categories
	 *
	 * @param $data
	 * @param array $checkedArr
	 * @param string $treeCats
	 * @param int $parent_id
	 * @param bool $sw
	 *
	 * @return string
	 */
	public static function buildTreeCats( $data, array $checkedArr = [], string $treeCats = '', int $parent_id = 0, bool $sw = false ): string {
		if ( ! empty( $data[ $parent_id ] ) ) {
			$treeCats .= $sw ? '<ul>' . PHP_EOL : '';

			for ( $i = 0; $i < count( $data[ $parent_id ] ); $i ++ ) {
				if ( empty( $checkedArr ) ) {
					$checked = $data[ $parent_id ][ $i ]['level'] == 1 ? ' checked' : '';
				} else {
					$checked = array_search( $data[ $parent_id ][ $i ]['id'], $checkedArr ) !== false ? ' checked' : '';
				}

				$treeCats .= '<li><label><input id="categories" type="checkbox" name="oasis_mi_options[oasis_mi_categories][]" value="' . $data[ $parent_id ][ $i ]['id'] . '"' . $checked . '/> ' . $data[ $parent_id ][ $i ]['name'] . '</label>' . PHP_EOL;
				$treeCats = self::buildTreeCats( $data, $checkedArr, $treeCats, $data[ $parent_id ][ $i ]['id'], true ) . '</li>' . PHP_EOL;
			}
			$treeCats .= $sw ? '</ul>' . PHP_EOL : '';
		}

		return $treeCats;
	}

	/**
	 * Update category and currency settings on plugin activation
	 */
	public static function activatePluginUpOptions() {
		self::upOptionsCurrency( [
			[
				'code' => 'kzt',
				'name' => 'Тенге'
			],
			[
				'code' => 'kgs',
				'name' => 'Киргизский Сом'
			],
			[
				'code' => 'rub',
				'name' => 'Российский рубль'
			],
			[
				'code' => 'usd',
				'name' => 'Доллар США'
			],
			[
				'code' => 'byn',
				'name' => 'Белорусский рубль'
			],
			[
				'code' => 'eur',
				'name' => 'Евро'
			],
			[
				'code' => 'uah',
				'name' => 'Гривна'
			]
		] );
	}

	/**
	 * Update currency options
	 *
	 * @param array $data
	 */
	public static function upOptionsCurrency( array $data = [] ) {
		if ( empty( $data ) ) {
			$currencies = Api::getCurrenciesOasis();

			foreach ( $currencies as $currency ) {
				$data[] = [
					'code' => $currency->code,
					'name' => $currency->full_name
				];
			}
		}

		update_option( 'oasis_currencies', $data );
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
	 * Add/update product photos
	 *
	 * @param $images
	 * @param $product_id
	 * @param null $parentSizeId
	 *
	 * @return array
	 */
	public static function processingPhoto( $images, $product_id, $parentSizeId = null ): array {
		$upload_dir = wp_upload_dir();

		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$attachIds = [];
		if ( $images ) {
			foreach ( $images as $image ) {
				if ( ! isset( $image->superbig ) ) {
					continue;
				}

				if ( ! $parentSizeId ) {
					$filename   = basename( $image->superbig );
					$existImage = get_page_by_title( sanitize_file_name( $filename ), OBJECT, 'attachment' );

					if ( $existImage ) {
						$attachIds[] = $existImage->ID;
						continue;
					}

					$image_data = file_get_contents( $image->superbig );

					if ( $image_data ) {
						if ( wp_mkdir_p( $upload_dir['path'] ) ) {
							$file = $upload_dir['path'] . '/' . $filename;
						} else {
							$file = $upload_dir['basedir'] . '/' . $filename;
						}

						file_put_contents( $file, $image_data );
						$wp_filetype = wp_check_filetype( $filename );

						$attachment = [
							'post_mime_type' => $wp_filetype['type'],
							'post_title'     => sanitize_file_name( $filename ),
							'post_content'   => '',
							'post_status'    => 'inherit',
							'post_parent'    => $product_id,
						];

						$attach_id   = wp_insert_attachment( $attachment, $file );
						$attach_data = wp_generate_attachment_metadata( $attach_id, $file );
						wp_update_attachment_metadata( $attach_id, $attach_data );
						$attachIds[] = $attach_id;
					}
				} else {
					$attachIds[] = get_post_thumbnail_id( $parentSizeId );
				}
			}
		}

		return $attachIds;
	}

	/**
	 * Print message in console
	 *
	 * @param $str
	 */
	public static function cliMsg( $str ) {
		echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . $str . PHP_EOL;
	}

	/**
	 * Debug func
	 *
	 * @param $data
	 */
	public static function d( $data ) {
		echo '<pre>';
		print_r( $data, false );
		echo '</pre>';
	}
}