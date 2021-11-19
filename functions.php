<?php

/**
 * Добавление модели и его вариаций (если они есть)
 *
 * @param $model_id
 * @param $model
 * @param $categories
 * @param bool $verbose
 */
function upsert_model( $model_id, $model, $categories, $verbose = false, $force = false ) {
	$args = [
		'post_type'  => [ 'product' ],
		'meta_query' => [
			[
				'key'   => 'model_id',
				'value' => $model_id,
			],
		],
	];

	$query        = new WP_Query( $args );
	$existProduct = null;
	if ( $query->posts ) {
		$existProduct = reset( $query->posts );
	}

	$existProductStock      = 0;
	$existProductTotalStock = 0;
	if ( $existProduct ) {
		$allMetas = get_post_meta( $existProduct->ID );
		foreach ( $model as $item ) {
			if ( $item->id == $allMetas['product_id'][0] ) {
				$firstProduct = $item;
				break;
			}
		}
		$existProductStock = (int) $allMetas['_stock'][0];
		if ( isset( $allMetas['_total_stock'] ) ) {
			$existProductTotalStock = (int) $allMetas['_total_stock'][0];
		}

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

	if ( empty( $existProduct ) && ( $firstProduct->is_visible == false || $totalStock == 0 ) ) {
		if ( $verbose ) {
			echo '[' . date( 'c' ) . '] Арт. ' . $item->article . ' отключен из вывода.' . PHP_EOL;
		}

		return;
	}

	switch ( true ) {
		case empty( $existProduct ):
		case ! empty( $existProduct ) && strtotime( $existProduct->post_modified ) < strtotime( $firstProduct->updated_at ):
		case $existProductStock != $firstProduct->total_stock:
		case $existProductTotalStock != $totalStock:
			$force = true;
			break;
	}

	if ( $force ) {
		$productAttributes = [];
		$existColor        = false;
		foreach ( $firstProduct->attributes as $key => $attribute ) {
			if ( count( $model ) > 1 && $attribute->id == '1000000001' ) {
				$existColor = true;
				continue;
			}

			$attr = wc_sanitize_taxonomy_name( stripslashes( $attribute->name ) );

			$productAttributes[ $attr ] = [
				'name'         => $attribute->name,
				'value'        => $attribute->value . ( ! empty( $attribute->dim ) ? ' ' . $attribute->dim : '' ),
				'position'     => ( $key + 1 ),
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 0,
			];
		}

		$addonMeta = [];
		if ( count( $model ) > 1 ) {
			if ( ! empty( $firstProduct->size ) ) {
				$attrName = 'Размер';
				$attr     = wc_sanitize_taxonomy_name( stripslashes( $attrName ) );

				$attrValues = [];
				foreach ( $model as $item ) {
					$attrValues[] = $item->size;
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
					'value'        => implode( "|", array_unique( $attrValues ) ),
					'position'     => ++ $key,
					'is_visible'   => 1,
					'is_variation' => 1,
					'is_taxonomy'  => 0,
				];
			}

			if ( $existColor ) {
				$attrName = 'Цвет';
				$attr     = wc_sanitize_taxonomy_name( stripslashes( $attrName ) );

				$attrValues = [];
				foreach ( $model as $item ) {
					foreach ( $item->attributes as $attribute ) {
						if ( $attribute->id == '1000000001' ) {
							$attrValues[] = $attribute->value;
							if ( $item->id == $firstProduct->id ) {
								$addonMeta['_default_attributes'] = [ strtolower( urlencode( $attr ) ) => $attribute->value ];
							}
						}
					}
				}
				sort( $attrValues );

				$productAttributes[ $attr ] = [
					'name'         => $attrName,
					'value'        => implode( "|", array_unique( $attrValues ) ),
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
			'post_name'      => oasis_mi_translite( $firstProduct->name ),
			'post_status'    => $firstProduct->is_visible && $totalStock > 0 ? 'publish' : 'draft',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'post_type'      => 'product',
			'post_content'   => $firstProduct->description,
			'post_excerpt'   => '',
			'meta_input'     => [
				                    'model_id'               => $model_id,
				                    'product_id'             => $firstProduct->id,
				                    '_regular_price'         => $firstProduct->price,
				                    '_price'                 => $firstProduct->price,
				                    '_sale_price'            => '',
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
				                    '_backorders'            => 'no',
				                    '_stock'                 => $firstProduct->total_stock,
				                    '_purchase_note'         => '',
				                    'total_sales'            => 0,
				                    '_product_attributes'    => $productAttributes,
				                    '_total_stock'           => $totalStock,
			                    ] + $addonMeta,
		];

		$productId = wp_insert_post( $productParams );

		wp_set_object_terms( $productId, ( count( $model ) > 1 ? 'variable' : 'simple' ), 'product_type' );
		wp_set_object_terms( $productId, $categories, 'product_cat' );

		upsert_photo( $firstProduct->images, $productId, $productId );
		if ( $verbose ) {
			echo '[' . date( 'c' ) . '] ' . ( $existProduct ? 'Обновлен' : 'Добавлен' ) . ' товар арт. ' . $firstProduct->article . PHP_EOL;
		}

		if ( count( $model ) > 1 ) {
			foreach ( $model as $item ) {
				$args = [
					'post_type'  => [ 'product_variation' ],
					'meta_query' => [
						[
							'key'   => 'variation_id',
							'value' => $item->id,
						],
					],
				];

				$query          = new WP_Query( $args );
				$existVariation = null;
				if ( $query->posts ) {
					$existVariation = reset( $query->posts );
				}

				$attributeMeta = [];
				if ( ! empty( $item->size ) ) {
					$attrName = 'Размер';
					$attr     = wc_sanitize_taxonomy_name( stripslashes( $attrName ) );

					$attributeMeta[ 'attribute_' . strtolower( urlencode( $attr ) ) ] = $item->size;
				}

				if ( $existColor ) {
					$attrName = 'Цвет';
					$attr     = wc_sanitize_taxonomy_name( stripslashes( $attrName ) );

					foreach ( $item->attributes as $attribute ) {
						if ( $attribute->id == '1000000001' ) {
							$attributeMeta[ 'attribute_' . strtolower( urlencode( $attr ) ) ] = $attribute->value;
						}
					}
				}

				$variationParams = [
					'ID'             => $existVariation ? $existVariation->ID : 0,
					'post_parent'    => $productId,
					'post_author'    => get_current_user_id(),
					'post_date'      => current_time( 'mysql' ),
					'post_date_gmt'  => current_time( 'mysql', 1 ),
					'post_title'     => $item->full_name,
					'post_name'      => oasis_mi_translite( $item->full_name ),
					'post_status'    => 'publish',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_type'      => 'product_variation',
					'post_excerpt'   => '',
					'meta_input'     => [
						                    'variation_id'           => $item->id,
						                    '_regular_price'         => $item->price,
						                    '_price'                 => $item->price,
						                    '_sale_price'            => '',
						                    '_sale_price_dates_from' => '',
						                    '_sale_price_dates_to'   => '',
						                    '_sku'                   => $item->article,
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
						                    '_backorders'            => 'no',
						                    '_stock'                 => $item->total_stock,
						                    '_purchase_note'         => '',
						                    'total_sales'            => 0,
					                    ] + $attributeMeta,
				];

				$variationId = wp_insert_post( $variationParams );

				// upsert_photo([reset($item['images'])], $variationId, $productId);
				upsert_photo( $item->images, $variationId, $productId );

				if ( $verbose ) {
					echo '[' . date( 'c' ) . '] ' . ( $existVariation ? 'Обновлен' : 'Добавлен' ) . ' вариант арт. ' . $item->article . PHP_EOL;
				}
			}
		}
	}
}

/**
 * Добавление или обновление фотографий для товара
 *
 * @param $images
 * @param $product_id
 * @param $main_product_id
 */
function upsert_photo( $images, $product_id, $main_product_id ) {
	$upload_dir = wp_upload_dir();

	require_once( ABSPATH . 'wp-admin/includes/image.php' );

	$attachIds = [];
	foreach ( $images as $image ) {
		if ( ! isset( $image->superbig ) ) {
			continue;
		}

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
	}

	if ( $attachIds ) {
		set_post_thumbnail( $product_id, reset( $attachIds ) );
		update_post_meta( $product_id, '_product_image_gallery', implode( ',', $attachIds ) );
	}
}

/**
 * Транслитерация для адреса товара
 *
 * @param $name
 *
 * @return mixed
 */
function oasis_mi_translite( $name ) {
	$name = str_replace( [
		'а',
		'б',
		'в',
		'г',
		'д',
		'е',
		'ё',
		'ж',
		'з',
		'и',
		'й',
		'к',
		'л',
		'љ',
		'м',
		'н',
		'њ',
		'о',
		'п',
		'р',
		'с',
		'т',
		'у',
		'ф',
		'х',
		'ц',
		'ч',
		'џ',
		'ш',
		'щ',
		'ъ',
		'ы',
		'ь',
		'э',
		'ю',
		'я',
		'А',
		'Б',
		'В',
		'Г',
		'Д',
		'Е',
		'Ё',
		'Ж',
		'З',
		'И',
		'Й',
		'К',
		'Л',
		'Љ',
		'М',
		'Н',
		'Њ',
		'О',
		'П',
		'Р',
		'С',
		'Т',
		'У',
		'Ф',
		'Х',
		'Ц',
		'Ч',
		'Џ',
		'Ш',
		'Щ',
		'Ъ',
		'Ы',
		'Ь',
		'Э',
		'Ю',
		'Я',
	],
		[
			'a',
			'b',
			'v',
			'g',
			'd',
			'e',
			'e',
			'z',
			'z',
			'i',
			'j',
			'k',
			'l',
			'lj',
			'm',
			'n',
			'nj',
			'o',
			'p',
			'r',
			's',
			't',
			'u',
			'f',
			'h',
			'c',
			'c',
			'dz',
			's',
			's',
			'i',
			'j',
			'j',
			'e',
			'ju',
			'ja',
			'A',
			'B',
			'V',
			'G',
			'D',
			'E',
			'E',
			'Z',
			'Z',
			'I',
			'J',
			'K',
			'L',
			'Lj',
			'M',
			'N',
			'Nj',
			'O',
			'P',
			'R',
			'S',
			'T',
			'U',
			'F',
			'H',
			'C',
			'C',
			'Dz',
			'S',
			'S',
			'I',
			'J',
			'J',
			'E',
			'Ju',
			'Ja',
		], $name );

	return $name;
}

function recursiveCheckCategories( $wpCategoryId, $parentId, $oasisCategories, $productCategories ) {
	$result = [];
	if ( isset( $oasisCategories[ $parentId ] ) ) {
		foreach ( $oasisCategories[ $parentId ] as $sub_v ) {
			if ( in_array( $sub_v['id'], $productCategories ) ) {
				$result[] = $wpCategoryId;
			}
			$result = array_merge( $result,
				recursiveCheckCategories( $wpCategoryId, $sub_v['id'], $oasisCategories, $productCategories ) );
		}
	}

	return $result;
}
