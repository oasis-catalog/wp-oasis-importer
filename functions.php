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

	$totalStock   = 0;
	$filterColors = [];
	foreach ( $model as $item ) {
		$totalStock += $item->total_stock;

		if ( ! empty( $item->colors ) ) {
			foreach ( $item->colors as $color ) {
				$filterColors[] = $color->parent_id;
			}
			unset( $color );
		}
	}
	unset( $item );

	$categories = [];

	foreach ( $firstProduct->full_categories as $full_category ) {
		$categories[] = Oasis::getCategoryId( $categoriesOasis, $full_category );
	}
	unset( $full_category );

	$dataPrice = Oasis::getDataPrice( $factor, $increase, $dealer, $firstProduct );

	$attributes    = [];
	$branding      = [];
	$existColor    = false;
	$attrNameColor = 'Цвет';
	foreach ( $firstProduct->attributes as $attribute ) {
		if ( count( $model ) > 1 && isset( $attribute->id ) && $attribute->id == '1000000001' ) {
			$existColor = true;
			$attrValues = [];
			foreach ( $model as $item ) {
				foreach ( $item->attributes as $itemAttribute ) {
					if ( isset( $itemAttribute->id ) && $itemAttribute->id == '1000000001' ) {
						$attrValues[] = trim( $itemAttribute->value );
						if ( $item->id == $firstProduct->id ) {
							$attributes['default'][ $attrNameColor ] = $itemAttribute->value;
						}
					}
				}
				unset( $itemAttribute );
			}
			unset( $item );
			sort( $attrValues );

			$attributes['attributes'][] = [
				'id'    => $attribute->id,
				'name'  => $attrNameColor,
				'value' => array_unique( $attrValues ),
			];

			continue;
		}

		if ( ! empty( $attribute->id ) && $attribute->id == '1000000008' ) {
			$branding[ $attribute->name ][] = trim( $attribute->value );
		} else {
			if ( isset( $attribute->id ) && $attribute->id == '1000000001' ) {
				$attrName = $attrNameColor;
			} else {
				$attrName = $attribute->name;
			}

			$attributes['attributes'][] = [
				'id'    => ! empty( $attribute->id ) ? $attribute->id : '',
				'name'  => $attrName,
				'value' => [ trim( $attribute->value ) . ( ! empty( $attribute->dim ) ? ' ' . $attribute->dim : '' ) ],
			];
		}
	}

	foreach ( $branding as $bKey => $bValue ) {
		$attributes['attributes'][] = [
			'id'    => '1000000008',
			'name'  => $bKey,
			'value' => $bValue,
		];
	}
	unset( $branding, $bKey, $bValue );

	if ( count( $model ) > 1 ) {
		if ( ! empty( $firstProduct->size ) ) {
			$attrNameSize = 'Размер';
			$attrValues   = [];
			foreach ( $model as $item ) {
				if ( ! empty( trim( $item->size ) ) ) {
					$attrValues[] = trim( $item->size );
				}

				if ( $item->id == $firstProduct->id ) {
					$attributes['default'][ $attrNameSize ] = $item->size;
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

			$existAttributeKey = Oasis::searchForKeyValue( $attributes['attributes'], 'name', $attrNameSize );

			if ( is_null( $existAttributeKey ) ) {
				$attributes['attributes'][] = [
					'id'    => '1110000001',
					'name'  => $attrNameSize,
					'value' => array_unique( $attrValues ),
				];
			} else {
				$attributes['attributes'][ $existAttributeKey ]['value'] = array_unique( array_merge( $attributes['attributes'][ $existAttributeKey ]['value'], $attrValues ) );
				$attributes['attributes'][ $existAttributeKey ]['id']    = '1110000001';
			}
		}
	}

	if ( ! empty( $filterColors ) ) {
		$attributes['attributes'][] = [
			'id'    => '2220000002',
			'name'  => 'Фильтр цвет',
			'value' => Oasis::checkColorsToFilter( array_unique( $filterColors ) ),
		];
	}
	unset( $filterColors );

	$firstPostStatus = Oasis::getProductStatus( $firstProduct, $totalStock )['post_status'];

	if ( ! $existProduct ) {
		if ( $firstPostStatus !== 'trash' ) {
			$productParams = [
				'ID'             => 0,
				'post_author'    => get_current_user_id(),
				'post_date'      => current_time( 'mysql' ),
				'post_date_gmt'  => current_time( 'mysql', 1 ),
				'post_title'     => $firstProduct->name,
				'post_name'      => Oasis::getUniquePostName( $firstProduct->name, 'product' ),
				'post_status'    => $firstPostStatus,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'product',
				'post_content'   => Oasis::preparePostContent( $firstProduct ),
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
					                    '_backorders'            => Oasis::getProductStatus( $firstProduct, $totalStock )['_backorders'],
					                    '_stock'                 => (int) $firstProduct->total_stock,
					                    '_purchase_note'         => '',
					                    'total_sales'            => 0,
					                    '_total_stock'           => $totalStock,
				                    ] + $dataPrice,
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
		}
	} else {
		$productId = $existProduct->ID;
		Oasis::upWcProduct( $existProduct->ID, $firstProduct, $totalStock, $dataPrice, $categories );
	}

	if ( $firstPostStatus !== 'trash' ) {
		Oasis::wcProductAttributes( $productId, $attributes, count( $model ) > 1 );
	}

	echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . ( $existProduct ? 'Обновлен' : 'Добавлен' ) . ' товар id ' . $firstProduct->id . PHP_EOL;

	$progressBar = get_option( 'oasis_progress' );

	if ( count( $model ) > 1 && $firstPostStatus !== 'trash' ) {
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
				$attributeMeta[ sanitize_title( 'pa_' . Oasis::transliteration( wc_sanitize_taxonomy_name( stripslashes( 'Размер' ) ) ) ) ] = sanitize_title( trim( $variation->size ) );
			}

			if ( $existColor ) {
				foreach ( $variation->attributes as $attribute ) {
					if ( isset( $attribute->id ) && $attribute->id == '1000000001' ) {
						$attributeMeta[ sanitize_title( 'pa_' . Oasis::transliteration( wc_sanitize_taxonomy_name( stripslashes( 'Цвет' ) ) ) ) ] = sanitize_title( trim( $attribute->value ) );
					}
				}
				unset( $attribute );
			}

			$dataPriceVariation = Oasis::getDataPrice( $factor, $increase, $dealer, $variation );

			if ( ! $existVariation ) {
				$variationParams = [
					'ID'             => 0,
					'post_parent'    => $productId,
					'post_author'    => get_current_user_id(),
					'post_date'      => current_time( 'mysql' ),
					'post_date_gmt'  => current_time( 'mysql', 1 ),
					'post_title'     => $variation->full_name,
					'post_name'      => Oasis::getUniquePostName( $variation->full_name, 'product_variation' ),
					'post_status'    => Oasis::getProductStatus( $variation, (int) $variation->total_stock, true )['post_status'],
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
						                    '_backorders'              => Oasis::getProductStatus( $variation, $totalStock )['_backorders'],
						                    '_stock'                   => (int) $variation->total_stock,
						                    '_purchase_note'           => '',
						                    'total_sales'              => 0,
					                    ] + $dataPriceVariation,
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

				$variationId = wp_insert_post( $variationParams );
				unset( $dbResults, $dataPost, $variationParams );

				$wpdb->insert( $wpdb->prefix . 'oasis_products', [
					'post_id'                  => $variationId,
					'product_id_oasis'         => $variation->id,
					'model_id_oasis'           => $model_id,
					'variation_parent_size_id' => $variation->parent_size_id,
					'type'                     => 'product_variation'
				] );

				upsert_photo( [ reset( $variation->images ) ], $variationId, $productId, $parentId );
			} else {
				$variationId = $existVariation->ID;
				Oasis::upWcProduct( $existVariation->ID, $variation, $totalStock, $dataPriceVariation, false, true );
			}
			Oasis::wcVariationAttributes( $variationId, $attributeMeta );

			echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . ( $existVariation ? 'Обновлен' : 'Добавлен' ) . ' вариант id ' . $variation->id . PHP_EOL;
			$progressBar = Oasis::upProgressBar( $progressBar );
			unset( $variation, $existVariation, $dataPriceVariation, $variationId, $parentId );
		}
	} else {
		$progressBar = Oasis::upProgressBar( $progressBar );
	}
	unset( $model_id, $model, $categoriesOasis, $categories, $factor, $increase, $dealer, $totalStock, $productId, $firstProduct );

	return $progressBar;
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
