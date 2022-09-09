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
		unset( $existProduct, $model, $allMetas, $item, $status );

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
		$price     = ! empty( $dealer ) ? $product->discount_price : $product->price;
		$old_price = ! empty( $product->old_price ) ? $product->old_price : null;

		if ( ! empty( $factor ) ) {
			$price = $price * (float) $factor;

			if ( empty( $dealer ) ) {
				$old_price = $old_price * (float) $factor;
			}
		}

		if ( ! empty( $increase ) ) {
			$price = $price + (float) $increase;

			if ( empty( $dealer ) ) {
				$old_price = $old_price + (float) $increase;
			}
		}

		$data['_price'] = $price;

		if ( ! empty( $old_price ) && $price < $old_price ) {
			$data['_regular_price'] = $old_price;
			$data['_sale_price']    = $price;
		} else {
			$data['_regular_price'] = $price;
			$data['_sale_price']    = '';
		}
		unset( $factor, $increase, $dealer, $product, $price, $old_price );

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
				foreach ( $attributes as $key => $value ) {
					update_post_meta( $productId, $key, $value );
				}
			}

			$wcProduct->set_name( $variation ? $oasisProduct->full_name : $oasisProduct->name );
			$wcProduct->set_status( getProductStatus( $oasisProduct->rating, $variation ? (int) $oasisProduct->total_stock : $totalStock, $variation )['post_status'] );
			$wcProduct->set_price( $dataPrice['_price'] );
			$wcProduct->set_regular_price( $dataPrice['_regular_price'] );
			$wcProduct->set_sale_price( $dataPrice['_sale_price'] );
			$wcProduct->set_stock_quantity( (int) $oasisProduct->total_stock );
			$wcProduct->set_backorders( getProductStatus( $oasisProduct->rating, $totalStock )['_backorders'] );
			$wcProduct->save();
			unset( $productId, $oasisProduct, $totalStock, $dataPrice, $categories, $variation, $attributes, $key, $value, $wcProduct );
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
		unset( $name, $post_type, $productId, $count, $dbPosts );

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
			'currency'     => $this->options['oasis_mi_currency'] ?? 'rub',
			'no_vat'       => $this->options['oasis_mi_no_vat'] ?? 0,
			'not_on_order' => $this->options['oasis_mi_not_on_order'] ?? null,
			'price_from'   => $this->options['oasis_mi_price_from'] ?? null,
			'price_to'     => $this->options['oasis_mi_price_to'] ?? null,
			'rating'       => $this->options['oasis_mi_rating'] ?? null,
			'moscow'       => $this->options['oasis_mi_warehouse_moscow'] ?? null,
			'europe'       => $this->options['oasis_mi_warehouse_europe'] ?? null,
			'remote'       => $this->options['oasis_mi_remote_warehouse'] ?? null,
		];

		if ( empty( $this->options['oasis_mi_categories'] ) ) {
			$categoryIds = array_keys( Oasis::getOasisMainCategories() );
		} else {
			$categoryIds = array_keys( $this->options['oasis_mi_categories'] );
		}

		$args += [
			'category' => implode( ',', $categoryIds ),
		];

		foreach ( $data as $key => $value ) {
			if ( $value ) {
				$args[ $key ] = $value;
			}
		}
		unset( $categoryIds, $category, $data, $key, $value );

		return Oasis::curlQuery( 'products', $args );
	}

	/**
	 * Get Stat Products
	 *
	 * @return array|mixed
	 */
	public function getStatProducts() {
		$args = [];

		$data = [
			'not_on_order' => $this->options['oasis_mi_not_on_order'] ?? null,
			'price_from'   => $this->options['oasis_mi_price_from'] ?? null,
			'price_to'     => $this->options['oasis_mi_price_to'] ?? null,
			'rating'       => ! empty( $this->options['oasis_mi_rating'] ) ? $this->options['oasis_mi_rating'] : '0,1,2,3,4,5',
			'moscow'       => $this->options['oasis_mi_warehouse_moscow'] ?? null,
			'europe'       => $this->options['oasis_mi_warehouse_europe'] ?? null,
			'remote'       => $this->options['oasis_mi_remote_warehouse'] ?? null,
		];

		if ( empty( $this->options['oasis_mi_categories'] ) ) {
			$data['category'] = implode( ',', array_keys( Oasis::getOasisMainCategories() ) );
		} else {
			$data['category'] = implode( ',', array_keys( $this->options['oasis_mi_categories'] ) );
		}

		foreach ( $data as $key => $value ) {
			if ( $value ) {
				$args[ $key ] = $value;
			}
		}
		unset( $data, $key, $value );

		return Oasis::curlQuery( 'stat', $args );
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

		if ( ! empty( $progressBar['step_total'] ) ) {
			$progressBar['step_item'] ++;
		}

		update_option( 'oasis_progress', $progressBar );

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
			$categories = Oasis::getCategoriesOasis();
		}

		foreach ( $categories as $category ) {
			if ( $category->level === 1 ) {
				$result[ $category->id ] = $category->name;
			}
		}
		unset( $categories, $category );

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
		unset( $options, $data, $apiKey );

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

		try {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query( $args ) );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			$content = curl_exec( $ch );

			if ( $content === false ) {
				throw new \Exception( 'Error: ' . curl_error( $ch ) );
			} else {
				$result = json_decode( $content );
			}

			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			sleep( 1 );

			if ( $http_code === 401 ) {
				throw new \Exception( 'Error Unauthorized. Invalid API key!' );
			} elseif ( $http_code != 200 ) {
				throw new \Exception( 'Error. Code: ' . $http_code );
			}

			unset( $content, $options, $args_pref, $args, $type, $ch, $http_code );
		} catch ( \Exception $e ) {
			echo $e->getMessage() . PHP_EOL;
			die();
		}

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
		unset( $result, $pattern );

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