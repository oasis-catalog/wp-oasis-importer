<?php

use OasisImport\Controller\Oasis\Oasis;

/**
 * Добавление модели и его вариаций (если они есть)
 *
 * @param $model_id
 * @param $model
 * @param $categoriesOasis
 */
function upsert_model( $model_id, $model, $categoriesOasis ) {
	$dataQuery = Oasis::getPostsByMetaQuery( [ 'product' ], 'model_id', $model_id );

	$existProduct = null;
	if ( $dataQuery->posts ) {
		$existProduct = reset( $dataQuery->posts );
	}

	if ( $existProduct ) {
		$allMetas = get_post_meta( $existProduct->ID );
		foreach ( $model as $item ) {
			if ( $item->id == $allMetas['product_id'][0] ) {
				$firstProduct = $item;
				break;
			}
		}
		unset( $item );

		if ( ! $firstProduct ) {
			$firstProduct = reset( $model );
		}
	} else {
		$firstProduct = reset( $model );
	}

	$totalStock = 0;
	foreach ( $model as $item ) {
		$totalStock += $item->total_stock;
	}
	unset( $item );

	$categories = [];

	foreach ( $firstProduct->full_categories as $full_category ) {
		$categories[] = Oasis::getCategoryId( $categoriesOasis, $full_category );
	}

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

	$productParams = [
		'ID'             => $existProduct ? $existProduct->ID : 0,
		'post_author'    => get_current_user_id(),
		'post_date'      => current_time( 'mysql' ),
		'post_date_gmt'  => current_time( 'mysql', 1 ),
		'post_title'     => $firstProduct->name,
		'post_name'      => Oasis::transliteration( $firstProduct->name ),
		'post_status'    => getProductStatus( $firstProduct->rating, $totalStock )['post_status'],
		'comment_status' => 'closed',
		'ping_status'    => 'closed',
		'post_type'      => 'product',
		'post_content'   => $firstProduct->description,
		'post_excerpt'   => '',
		'meta_input'     => [
			                    'model_id'               => $model_id,
			                    'product_id'             => $firstProduct->id,
			                    '_regular_price'         => ! empty( $firstProduct->old_price ) ? str_replace( '.', ',', $firstProduct->old_price ) : str_replace( '.', ',', $firstProduct->price ),
			                    '_price'                 => str_replace( '.', ',', $firstProduct->price ),
			                    '_sale_price'            => ! empty( $firstProduct->old_price ) ? str_replace( '.', ',', $firstProduct->price ) : '',
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
		                    ] + $addonMeta,
	];

	$productId = wp_insert_post( $productParams );

	wp_set_object_terms( $productId, ( count( $model ) > 1 ? 'variable' : 'simple' ), 'product_type' );
	wp_set_object_terms( $productId, $categories, 'product_cat' );

	if ( ! $existProduct ) {
		upsert_photo( $firstProduct->images, $productId, $productId );
	}

	Oasis::upTransientWcProductsOnsale( $productId, $firstProduct );

	echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . ( $existProduct ? 'Обновлен' : 'Добавлен' ) . ' товар арт. ' . $firstProduct->article . PHP_EOL;

	if ( count( $model ) > 1 ) {
		foreach ( $model as $variation ) {
			$dataQuery      = Oasis::getPostsByMetaQuery( [ 'product_variation' ], 'variation_id', $variation->id );
			$existVariation = null;
			if ( $dataQuery->posts ) {
				$existVariation = reset( $dataQuery->posts );
			}

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

			$variationParams = [
				'ID'             => $existVariation ? $existVariation->ID : 0,
				'post_parent'    => $productId,
				'post_author'    => get_current_user_id(),
				'post_date'      => current_time( 'mysql' ),
				'post_date_gmt'  => current_time( 'mysql', 1 ),
				'post_title'     => $variation->full_name,
				'post_name'      => Oasis::transliteration( $variation->full_name ),
				'post_status'    => getProductStatus( $variation->rating, (int) $variation->total_stock, true )['post_status'],
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_type'      => 'product_variation',
				'post_excerpt'   => '',
				'meta_input'     => [
					                    'variation_id'             => $variation->id,
					                    'variation_parent_size_id' => $variation->parent_size_id,
					                    '_regular_price'           => ! empty( $firstProduct->old_price ) ? str_replace( '.', ',', $firstProduct->old_price ) : str_replace( '.', ',', $firstProduct->price ),
					                    '_price'                   => str_replace( '.', ',', $firstProduct->price ),
					                    '_sale_price'              => ! empty( $firstProduct->old_price ) ? str_replace( '.', ',', $firstProduct->price ) : '',
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
				                    ] + $attributeMeta,
			];

			$dataQuery = Oasis::getPostsByMetaQuery( [ 'product_variation' ], 'variation_parent_size_id', $variation->parent_size_id );

			if ( $dataQuery->posts ) {
				$parentId = reset( $dataQuery->posts )->ID;
			} else {
				$parentId = false;
			}

			$variationId = wp_insert_post( $variationParams );

			if ( ! $existVariation ) {
				upsert_photo( [ reset( $variation->images ) ], $variationId, $productId, $parentId );
			}

			Oasis::upTransientWcProductsOnsale( $variationId, $variation );

			echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . ( $existVariation ? 'Обновлен' : 'Добавлен' ) . ' вариант арт. ' . $variation->article . PHP_EOL;
		}
	}
}

/**
 * Up stock products
 *
 * @param $stock
 */
function upStock( $stock ) {
	$dataQuery = Oasis::getPostsByMetaQuery( [ 'product', 'product_variation' ], '_sku', $stock->article );

	if ( $dataQuery->post ) {
		update_post_meta( $dataQuery->post->ID, '_stock', $stock->stock );
	}
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
