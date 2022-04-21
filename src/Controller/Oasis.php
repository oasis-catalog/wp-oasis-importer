<?php

namespace OasisImport\Controller\Oasis;

use WP_Query;

class Oasis {
	public $options = [];

	public function __construct() {
		$this->options = get_option( 'oasis_mi_options' );
	}

	/**
	 * Get first product
	 *
	 * @param $existProduct
	 * @param $model
	 *
	 * @return false|mixed
	 */
	public static function getFirstProduct( $existProduct, $model ) {
		if ( count( $model ) > 1 ) {
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

			$status = getProductStatus( $firstProduct->rating, (int) $firstProduct->total_stock )['post_status'];

			if ( $status != 'publish' ) {
				array_shift( $model );
				$firstProduct = self::getFirstProduct( $existProduct, $model );
			}
		} else {
			$firstProduct = reset( $model );
		}

		return $firstProduct;
	}

	/**
	 * Calculation price
	 *
	 * @param $factor
	 * @param $increase
	 * @param $dealer
	 * @param $product
	 *
	 * @return array
	 */
	public static function getDataPrice( $factor, $increase, $dealer, $product ): array {
		if ( ! empty( $factor ) || ! empty( $increase ) || ! empty( $dealer ) ) {
			$price = ! empty( $dealer ) ? $product->discount_price : $product->price;

			if ( ! empty( $factor ) ) {
				$price = $price * (float) $factor;
			}

			if ( ! empty( $increase ) ) {
				$price = $price + (float) $increase;
			}

			$data['_price']         = str_replace( '.', ',', $price );
			$data['_regular_price'] = str_replace( '.', ',', $price );
			$data['_sale_price']    = '';
		} else {
			$data['_price'] = str_replace( '.', ',', $product->price );

			if ( ! empty( $product->old_price ) && $product->price < $product->old_price ) {
				$data['_regular_price'] = str_replace( '.', ',', $product->old_price );
				$data['_sale_price']    = str_replace( '.', ',', $product->price );
			} else {
				$data['_regular_price'] = str_replace( '.', ',', $product->price );
				$data['_sale_price']    = '';
			}
		}

		return $data;
	}

	/**
	 * Up product
	 *
	 * @param $productId
	 * @param $oasisProduct
	 * @param $totalStock
	 * @param $dataPrice
	 * @param false $categories
	 * @param bool $variation
	 * @param array $attributes
	 */
	public static function upWcProduct( $productId, $oasisProduct, $totalStock, $dataPrice, $categories = false, $variation = false, $attributes = [] ) {
		if ( $productId ) {
			$wcProduct = wc_get_product( $productId );

			if ( $variation === false ) {
				$wcProduct->set_description( $oasisProduct->description );
				wp_set_object_terms( $productId, $categories, 'product_cat' );
				update_post_meta( $productId, '_total_stock', $totalStock );
			}

			if ( ! empty( $attributes ) ) {
				update_post_meta( $productId, '_default_attributes', $attributes );
			}

			$wcProduct->set_name( $variation ? $oasisProduct->full_name : $oasisProduct->name );
			$wcProduct->set_status( getProductStatus( $oasisProduct->rating, $variation ? (int) $oasisProduct->total_stock : $totalStock, $variation )['post_status'] );
			$wcProduct->set_price( $dataPrice['_price'] );
			$wcProduct->set_regular_price( $dataPrice['_regular_price'] );
			$wcProduct->set_sale_price( $dataPrice['_sale_price'] );
			$wcProduct->set_stock_quantity( (int) $oasisProduct->total_stock );
			$wcProduct->set_backorders( getProductStatus( $oasisProduct->rating, $totalStock )['_backorders'] );
			$wcProduct->save();
		}
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
	public static function getUniquePostName( $name, $post_type, $productId = null, $count = 0 ): string {
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

		return $post_name;
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

		if ( ! $neededObject ) {
			return false;
		}

		return array_shift( $neededObject );
	}

	/**
	 * Get products oasis
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function getOasisProducts( array $args = [] ): array {
		$args += [
			'fieldset' => 'full',
			'extend'   => 'is_visible',
		];

		$data = [
			'currency'         => $this->options['oasis_mi_currency'] ?? 'rub',
			'no_vat'           => $this->options['oasis_mi_no_vat'] ?? 0,
			'not_on_order'     => $this->options['oasis_mi_not_on_order'],
			'price_from'       => $this->options['oasis_mi_price_from'],
			'price_to'         => $this->options['oasis_mi_price_to'],
			'rating'           => $this->options['oasis_mi_rating'],
			'warehouse_moscow' => $this->options['oasis_mi_warehouse_moscow'],
			'warehouse_europe' => $this->options['oasis_mi_warehouse_europe'],
			'remote_warehouse' => $this->options['oasis_mi_remote_warehouse'],
		];

		$categories  = $this->options['oasis_mi_categories'];
		$categoryIds = [];

		if ( ! is_null( $categories ) ) {
			$categoryIds = array_keys( $categories );
		}

		if ( ! count( $categoryIds ) ) {
			$categoryIds = array_keys( Oasis::getOasisMainCategories() );
		}

		$args += [
			'category' => implode( ',', $categoryIds ),
		];

		unset( $categoryIds, $category );

		foreach ( $data as $key => $value ) {
			if ( $value ) {
				$args[ $key ] = $value;
			}
		}

		return Oasis::curlQuery( 'products', $args );
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
			$categories = Oasis::getCategoriesOasis();
		}

		foreach ( $categories as $category ) {
			if ( $category->level === 1 ) {
				$result[ $category->id ] = $category->name;
			}
		}

		return $result;
	}

	/**
	 * Get categories oasis
	 *
	 * @return array
	 */
	public static function getCategoriesOasis(): array {
		return Oasis::curlQuery( 'categories', [ 'fields' => 'id,parent_id,root,level,slug,name,path' ] );
	}

	/**
	 * Get currencies oasis
	 *
	 * @return array
	 */
	public static function getCurrenciesOasis(): array {
		return Oasis::curlQuery( 'currencies' );
	}

	/**
	 * Get stock oasis
	 *
	 * @return array
	 */
	public static function getStockOasis(): array {
		return Oasis::curlQuery( 'stock', [ 'fields' => 'article,stock,id' ] );
	}

	/**
	 * Export order to Oasiscatalog
	 *
	 * @param $apiKey
	 * @param $data
	 *
	 * @return array|mixed
	 */
	public static function sendOrder( $apiKey, $data ) {
		$result = [];

		try {
			$options = [
				'http' => [
					'method' => 'POST',
					'header' => 'Content-Type: application/json' . PHP_EOL .
					            'Accept: application/json' . PHP_EOL,

					'content' => json_encode( $data ),
				],
			];

			return json_decode( file_get_contents( 'https://api.oasiscatalog.com/v4/reserves/?key=' . $apiKey, 0, stream_context_create( $options ) ) );

		} catch ( \Exception $exception ) {
		}

		return $result;
	}

	/**
	 * Get order data by queue id
	 *
	 * @param $queueId
	 *
	 * @return array
	 */
	public static function getOrderByQueueId( $queueId ) {
		return Oasis::curlQuery( 'reserves/by-queue/' . $queueId );
	}

	/**
	 * Get api data
	 *
	 * @param $type
	 * @param array $args
	 *
	 * @return array|mixed
	 */
	public static function curlQuery( $type, array $args = [] ) {
		$options = get_option( 'oasis_mi_options' );

		if ( empty( $options['oasis_mi_api_key'] ) ) {
			return [];
		}

		$args_pref = [
			'key'    => $options['oasis_mi_api_key'],
			'format' => 'json',
		];
		$args      = array_merge( $args_pref, $args );

		$content = file_get_contents( 'https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query( $args ) );
		$result  = json_decode( $content );

		return $result;
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

		return strtolower( strtr( $string, $arr_trans ) );
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