<?php

namespace OasisImport\Controller\Oasis;

use Exception;

class Cli extends Main {

	public static function import() {
		set_time_limit( 0 );
		ini_set( 'memory_limit', '4G' );

		try {
			parent::cliMsg( 'Начало обновления товаров' );

			delete_option( 'oasis_progress_tmp' );
			$args    = [];
			$options = get_option( 'oasis_options' );
			$limit   = isset( $options['oasis_limit'] ) ? (int) $options['oasis_limit'] : null;
			$step    = (int) get_option( 'oasis_step' );
			parent::prepareAttributeData();

			if ( $limit > 0 ) {
				$args['limit']  = $limit;
				$args['offset'] = $step * $limit;
			}

			$oasisCategories = Api::getCategoriesOasis();
			$products        = Api::getOasisProducts( $oasisCategories, $args );
			$stats           = Api::getStatProducts( $oasisCategories );

			$pBar              = get_option( 'oasis_progress' );
			$pBar['total']     = $stats->products;
			$pBar['step_item'] = 0;
			$tmpBar            = $pBar;
			$nextStep          = ( $limit > 0 && $products ) ? ++ $step : 0;

			$group_ids     = [];
			$countProducts = 0;
			foreach ( $products as $product ) {
				if ( $product->is_deleted === false ) {
					$group_ids[ $product->group_id ][ $product->id ] = $product;
					$countProducts ++;
				} else {
					parent::checkDeleteProduct( $product->id );
				}
			}

			if ( ! empty( $group_ids ) ) {
				if ( $limit > 0 ) {
					$tmpBar['step_total'] = $countProducts;

					if ( $step === 1 ) {
						$tmpBar['item'] = 0;
					}
				} else {
					$tmpBar['step_total'] = 0;
					$tmpBar['item']       = 0;
				}

				unset( $products, $product, $countProducts );
				$total = count( array_keys( $group_ids ) );
				$count = 0;

				foreach ( $group_ids as $group_id => $model ) {
					parent::cliMsg( 'Начало обработки модели ' . $group_id );
					$dbProduct  = parent::checkProductOasisTable( [ 'model_id_oasis' => $group_id ], 'product' );
					$totalStock = parent::getTotalStock( $model );

					if ( count( $model ) === 1 ) {
						$product    = reset( $model );
						$categories = parent::getProductCategories( $product->full_categories, $oasisCategories );

						if ( $dbProduct ) {
							parent::upWcProduct( $dbProduct['post_id'], $model, $categories, $totalStock, $options );
						} else {
							parent::addWcProduct( $product, $model, $categories, $totalStock, $options, 'simple' );
						}

						$tmpBar = parent::upProgressBar( $tmpBar );
					} elseif ( count( $model ) > 1 ) {
						$firstProduct = parent::getFirstProduct( $model );
						$categories   = parent::getProductCategories( $firstProduct->full_categories, $oasisCategories );

						if ( $dbProduct ) {
							$wcProductId = $dbProduct['post_id'];
							if ( ! parent::upWcProduct( $wcProductId, $model, $categories, $totalStock, $options ) ) {
								continue;
							}
						} else {
							$wcProductId = parent::addWcProduct( $firstProduct, $model, $categories, $totalStock, $options, 'variable' );
							if ( ! $wcProductId ) {
								continue;
							}
						}

						foreach ( $model as $variation ) {
							$dbVariation = parent::checkProductOasisTable( [ 'product_id_oasis' => $variation->id ], 'product_variation' );

							if ( $dbVariation ) {
								parent::upWcVariation( $dbVariation, $variation, $options );
							} else {
								parent::addWcVariation( $wcProductId, $variation, $options );
							}

							$tmpBar = parent::upProgressBar( $tmpBar );
						}
					}
					update_option( 'oasis_progress_tmp', $tmpBar );
					parent::cliMsg( 'Done ' . ++ $count . ' from ' . $total );
				}

				if ( empty( $limit ) ) {
					$tmpBar['item'] = 0;
				}

				$pBar = $tmpBar;
			} else {
				$pBar['item'] = 0;
			}
			unset( $tmpBar );

			$pBar['step_total'] = 0;

			parent::cliMsg( 'Окончание обновления товаров' );
			$pBar['date'] = current_time( 'mysql' );
			update_option( 'oasis_step', $nextStep );
			update_option( 'oasis_progress', $pBar );
			delete_option( 'oasis_progress_tmp' );
			parent::upOptionsCurrency();
		} catch ( Exception $exception ) {
			echo $exception->getMessage();
			die();
		}
	}

	/**
	 * Up stock products
	 */
	public static function upStock() {
		global $wpdb;

		set_time_limit( 0 );
		ini_set( 'memory_limit', '2G' );

		try {
			$stock         = Api::getStockOasis();
			$dbResults     = $wpdb->get_results( "SELECT `post_id`, `product_id_oasis`, `type`  FROM {$wpdb->prefix}oasis_products", ARRAY_A );
			$oasisProducts = [];

			foreach ( $dbResults as $dbResult ) {
				if ( empty( $oasisProducts[ $dbResult['product_id_oasis'] ] ) || $dbResult['type'] == 'product_variation' ) {
					$oasisProducts[ $dbResult['product_id_oasis'] ] = $dbResult['post_id'];
				}
			}
			unset( $dbResult );

			foreach ( $stock as $item ) {
				if ( ! empty( $oasisProducts[ $item->id ] ) ) {
					update_post_meta( $oasisProducts[ $item->id ], '_stock', intval( $item->stock ) + intval( $item->{"stock-remote"} ) );
				}
			}
			unset( $item );
		} catch ( Exception $exception ) {
			die();
		}
	}
}
