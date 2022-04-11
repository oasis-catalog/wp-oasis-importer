<?php

use OasisImport\Controller\Oasis\Oasis;

/**
 * Добавление модели и его вариаций (если они есть)
 *
 * @param $model_id
 * @param $model
 * @param $categoriesOasis
 * @param $factor
 * @param $increase
 * @param $dealer
 */
function upsert_model( $model_id, $model, $categoriesOasis, $factor, $increase, $dealer ) {
	global $wpdb;

	$dbResults = $wpdb->get_results( "
SELECT * FROM {$wpdb->prefix}oasis_products 
WHERE model_id_oasis = '" . $model_id . "' 
	AND type = 'product'
", ARRAY_A );

	$existProduct = null;

	if ( $dbResults ) {
		$existProduct = get_post( reset( $dbResults )['post_id'] );
		if ( ! $existProduct ) {
			$wpdb->delete( $wpdb->prefix . 'oasis_products', [ 'post_id' => reset( $dbResults )['post_id'], 'type' => 'product' ] );
		}
	}
	unset( $dbResults );

	$firstProduct = Oasis::getFirstProduct( $existProduct, $model );

	$totalStock = 0;
	foreach ( $model as $item ) {
		$totalStock += $item->total_stock;
	}
	unset( $item );

	$categories = [];

	foreach ( $firstProduct->full_categories as $full_category ) {
		$categories[] = Oasis::getCategoryId( $categoriesOasis, $full_category );
	}

	$dataPrice = Oasis::getDataPrice( $factor, $increase, $dealer, $firstProduct );

	$productAttributes = [];
	$addonMeta         = [];
	$existColor        = false;
	foreach ( $firstProduct->attributes as $key => $attribute ) {
		if ( count( $model ) > 1 && $attribute->id == '1000000001' ) {
			$existColor = true;

			$attrName = 'Цвет';
			$attr     = wc_sanitize_taxonomy_name( stripslashes( $attrName ) );

			$attrValues = [];
			foreach ( $model as $item ) {
				foreach ( $item->attributes as $attribute ) {
					if ( $attribute->id == '1000000001' ) {
						$attrValues[] = trim( $attribute->value );
						if ( $item->id == $firstProduct->id ) {
							$addonMeta['_default_attributes'] = [ strtolower( urlencode( $attr ) ) => trim( $attribute->value ) ];
						}
					}
				}
			}
			sort( $attrValues );

			$productAttributes[ $attr ] = [
				'name'         => $attrName,
				'value'        => implode( '|', array_unique( $attrValues ) ),
				'position'     => ++ $key,
				'is_visible'   => 1,
				'is_variation' => 1,
				'is_taxonomy'  => 0,
			];

			continue;
		}

		$attr = wc_sanitize_taxonomy_name( stripslashes( trim( $attribute->name ) ) );

		$productAttributes[ $attr ] = [
			'name'         => $attribute->name,
			'value'        => trim( $attribute->value ) . ( ! empty( $attribute->dim ) ? ' ' . $attribute->dim : '' ),
			'position'     => ( $key + 1 ),
			'is_visible'   => 1,
			'is_variation' => 0,
			'is_taxonomy'  => 0,
		];
	}

	if ( count( $model ) > 1 ) {
		if ( ! empty( $firstProduct->size ) ) {
			$attrName = 'Размер';
			$attr     = wc_sanitize_taxonomy_name( stripslashes( $attrName ) );

			$attrValues = [];
			foreach ( $model as $item ) {
				$attrValues[] = trim( $item->size );

				if ( $item->id == $firstProduct->id ) {
					$addonMeta['_default_attributes'][ strtolower( urlencode( $attr ) ) ] = trim( $item->size );
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

			usort( $attrValues, function ( $key1, $key2 ) use ( $etalonSizes ) {
				return ( array_search( $key1, $etalonSizes ) > array_search( $key2, $etalonSizes ) );
			} );

			$productAttributes[ $attr ] = [
				'name'         => $attrName,
				'value'        => implode( '|', array_unique( $attrValues ) ),
				'position'     => ++ $key,
				'is_visible'   => 1,
				'is_variation' => 1,
				'is_taxonomy'  => 0,
			];
		}
	}

	if ( ! $existProduct ) {
		$productParams = [
			'ID'             => 0,
			'post_author'    => get_current_user_id(),
			'post_date'      => current_time( 'mysql' ),
			'post_date_gmt'  => current_time( 'mysql', 1 ),
			'post_title'     => $firstProduct->name,
			'post_name'      => Oasis::getUniquePostName( $firstProduct->name, 'product' ),
			'post_status'    => getProductStatus( $firstProduct->rating, $totalStock )['post_status'],
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'post_type'      => 'product',
			'post_content'   => $firstProduct->description,
			'post_excerpt'   => '',
			'meta_input'     => [
				                    'model_id'               => $model_id,
				                    'product_id'             => $firstProduct->id,
				                    '_sale_price_dates_from' => '',
				                    '_sale_price_dates_to'   => '',
				                    '_sku'                   => count( $model ) > 1 ? $firstProduct->article_base : $firstProduct->article,
				                    '_weight'                => 0,
				                    '_length'                => 0,
				                    '_width'                 => 0,
				                    '_height'                => 0,
				                    '_tax_status'            => 'taxable',
				                    '_tax_class'             => '',
				                    '_stock_status'          => 'instock',
				                    '_visibility'            => 'visible',
				                    '_featured'              => 'no',
				                    '_downloadable'          => 'no',
				                    '_virtual'               => 'no',
				                    '_sold_individually'     => '',
				                    '_manage_stock'          => 'yes',
				                    '_backorders'            => getProductStatus( $firstProduct->rating, $totalStock )['_backorders'],
				                    '_stock'                 => (int) $firstProduct->total_stock,
				                    '_purchase_note'         => '',
				                    'total_sales'            => 0,
				                    '_product_attributes'    => $productAttributes,
				                    '_total_stock'           => $totalStock,
			                    ] + $addonMeta + $dataPrice,
		];

		$productId = wp_insert_post( $productParams );

		$wpdb->insert( $wpdb->prefix . 'oasis_products', [
			'post_id'          => $productId,
			'product_id_oasis' => $firstProduct->id,
			'model_id_oasis'   => $model_id,
			'type'             => 'product'
		] );

		wp_set_object_terms( $productId, ( count( $model ) > 1 ? 'variable' : 'simple' ), 'product_type' );
		wp_set_object_terms( $productId, $categories, 'product_cat' );
		upsert_photo( $firstProduct->images, $productId, $productId );
	} else {
		$productId = $existProduct->ID;
		Oasis::upWcProduct( $existProduct->ID, $firstProduct, $totalStock, $dataPrice, $categories, false, $addonMeta['_default_attributes'] );
	}

	echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . ( $existProduct ? 'Обновлен' : 'Добавлен' ) . ' товар арт. ' . $firstProduct->article . PHP_EOL;

	if ( count( $model ) > 1 ) {
		foreach ( $model as $variation ) {
			$dbResults = $wpdb->get_results( "
SELECT * FROM {$wpdb->prefix}oasis_products 
WHERE product_id_oasis = '" . $variation->id . "' 
	AND type = 'product_variation'
", ARRAY_A );

			$existVariation = null;

			if ( $dbResults ) {
				$existVariation = get_post( reset( $dbResults )['post_id'] );
				if ( ! $existVariation ) {
					$wpdb->delete( $wpdb->prefix . 'oasis_products', [ 'post_id' => reset( $dbResults )['post_id'], 'type' => 'product_variation' ] );
				}
			}
			unset( $dbResults );

			$attributeMeta = [];
			if ( ! empty( $variation->size ) ) {
				$attrName = 'Размер';
				$attr     = wc_sanitize_taxonomy_name( stripslashes( $attrName ) );

				$attributeMeta[ 'attribute_' . strtolower( urlencode( $attr ) ) ] = trim( $variation->size );
			}

			if ( $existColor ) {
				$attrName = 'Цвет';
				$attr     = wc_sanitize_taxonomy_name( stripslashes( $attrName ) );

				foreach ( $variation->attributes as $attribute ) {
					if ( $attribute->id == '1000000001' ) {
						$attributeMeta[ 'attribute_' . strtolower( urlencode( $attr ) ) ] = trim( $attribute->value );
					}
				}
			}

			$dataPrice = Oasis::getDataPrice( $factor, $increase, $dealer, $variation );

			if ( ! $existVariation ) {
				$variationParams = [
					'ID'             => 0,
					'post_parent'    => $productId,
					'post_author'    => get_current_user_id(),
					'post_date'      => current_time( 'mysql' ),
					'post_date_gmt'  => current_time( 'mysql', 1 ),
					'post_title'     => $variation->full_name,
					'post_name'      => Oasis::getUniquePostName( $variation->full_name, 'product_variation' ),
					'post_status'    => getProductStatus( $variation->rating, (int) $variation->total_stock, true )['post_status'],
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_type'      => 'product_variation',
					'post_excerpt'   => '',
					'meta_input'     => [
						                    'variation_id'             => $variation->id,
						                    'variation_parent_size_id' => $variation->parent_size_id,
						                    '_sale_price_dates_from'   => '',
						                    '_sale_price_dates_to'     => '',
						                    '_sku'                     => $variation->article,
						                    '_weight'                  => 0,
						                    '_length'                  => 0,
						                    '_width'                   => 0,
						                    '_height'                  => 0,
						                    '_tax_status'              => 'taxable',
						                    '_tax_class'               => '',
						                    '_stock_status'            => 'instock',
						                    '_visibility'              => 'visible',
						                    '_featured'                => 'no',
						                    '_downloadable'            => 'no',
						                    '_virtual'                 => 'no',
						                    '_sold_individually'       => '',
						                    '_manage_stock'            => 'yes',
						                    '_backorders'              => getProductStatus( $variation->rating, $totalStock )['_backorders'],
						                    '_stock'                   => (int) $variation->total_stock,
						                    '_purchase_note'           => '',
						                    'total_sales'              => 0,
					                    ] + $attributeMeta + $dataPrice,
				];

				$dbResults = $wpdb->get_results( "
SELECT * FROM {$wpdb->prefix}oasis_products 
WHERE variation_parent_size_id = '" . $variation->parent_size_id . "'
	AND type = 'product_variation'
", ARRAY_A );

				$parentId = false;

				if ( $dbResults ) {
					$dataPost = get_post( reset( $dbResults )['post_id'] );
					if ( ! $dataPost ) {
						$wpdb->update( $wpdb->prefix . 'oasis_products',
							[ 'variation_parent_size_id' => null ],
							[ 'post_id' => reset( $dbResults )['post_id'], 'type' => 'product_variation' ] );
					} else {
						$parentId = $dataPost->ID;
					}
				}
				unset( $dbResults );

				$variationId = wp_insert_post( $variationParams );

				$wpdb->insert( $wpdb->prefix . 'oasis_products', [
					'post_id'                  => $variationId,
					'product_id_oasis'         => $variation->id,
					'model_id_oasis'           => $model_id,
					'variation_parent_size_id' => $variation->parent_size_id,
					'type'                     => 'product_variation'
				] );

				upsert_photo( [ reset( $variation->images ) ], $variationId, $productId, $parentId );
			} else {
				Oasis::upWcProduct( $existVariation->ID, $variation, $totalStock, $dataPrice, false, true );
			}

			echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . ( $existVariation ? 'Обновлен' : 'Добавлен' ) . ' вариант арт. ' . $variation->article . PHP_EOL;
		}
	}
}

/**
 * Up stock products
 */
function upStock() {
	global $wpdb;

	$stock         = Oasis::getStockOasis();
	$time_start    = microtime( true );
	$dbResults     = $wpdb->get_results( "SELECT post_id, product_id_oasis, type  FROM {$wpdb->prefix}oasis_products", ARRAY_A );
	$oasisProducts = [];

	foreach ( $dbResults as $dbResult ) {
		if ( empty( $oasisProducts[ $dbResult['product_id_oasis'] ] ) || $dbResult['type'] == 'product_variation' ) {
			$oasisProducts[ $dbResult['product_id_oasis'] ] = $dbResult['post_id'];
		}
	}
	unset( $dbResult );

	foreach ( $stock as $item ) {
		if ( ! empty( $oasisProducts[ $item->id ] ) ) {
			update_post_meta( $oasisProducts[ $item->id ], '_stock', $item->stock );
		}
	}
	unset( $item );

	$time_end = microtime( true );
	update_option( 'oasis_upStock_time', ( $time_end - $time_start ) );
}

/**
 * Get status and backorders product or variation
 *
 * @param int $rating
 * @param int $totalStock
 * @param bool $variation
 *
 * @return string[]
 */
function getProductStatus( int $rating, int $totalStock, $variation = false ): array {
	$data = [
		'post_status' => 'publish',
		'_backorders' => 'no',
	];

	if ( $rating === 5 ) {
		$data['_backorders'] = 'yes';
	} elseif ( $totalStock === 0 ) {
		if ( $variation ) {
			$data['post_status'] = 'private';
		} else {
			$data['post_status'] = 'draft';
		}
	}

	return $data;
}

/**
 * Добавление или обновление фотографий для товара
 *
 * @param $images
 * @param $product_id
 * @param $main_product_id
 * @param bool $parentProductId
 */
function upsert_photo( $images, $product_id, $main_product_id, $parentProductId = false ) {
	$upload_dir = wp_upload_dir();

	require_once( ABSPATH . 'wp-admin/includes/image.php' );

	$attachIds = [];
	foreach ( $images as $image ) {
		if ( ! isset( $image->superbig ) ) {
			continue;
		}
		if ( ! $parentProductId ) {
			$filename = basename( $image->superbig );

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

				$wp_filetype = wp_check_filetype( $filename, null );

				$attachment = [
					'post_mime_type' => $wp_filetype['type'],
					'post_title'     => sanitize_file_name( $filename ),
					'post_content'   => '',
					'post_status'    => 'inherit',
					'post_parent'    => $main_product_id,
				];

				$attach_id = wp_insert_attachment( $attachment, $file );

				$attach_data = wp_generate_attachment_metadata( $attach_id, $file );
				wp_update_attachment_metadata( $attach_id, $attach_data );
				$attachIds[] = $attach_id;
			}
		} else {
			$attachIds[] = get_post_thumbnail_id( $parentProductId );
		}
	}

	if ( $attachIds ) {
		set_post_thumbnail( $product_id, reset( $attachIds ) );
		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $attachIds ) );
	}
}
