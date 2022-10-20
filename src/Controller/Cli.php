<?php

namespace OasisImport\Controller\Oasis;

use Exception;

class Cli extends Main {

	public static function import() {
		set_time_limit( 0 );
		ini_set( 'memory_limit', '4G' );

		try {
			parent::cliMsg( 'Начало обновления товаров' );

			$args    = [];
			$options = get_option( 'oasis_mi_options' );
			$limit   = isset( $options['oasis_mi_limit'] ) ? (int) $options['oasis_mi_limit'] : null;
			$step    = (int) get_option( 'oasis_step' );

			if ( $limit > 0 ) {
				$args['limit']  = $limit;
				$args['offset'] = $step * $limit;
			}

			$oasisCategories = Api::getCategoriesOasis();
			$products        = Api::getOasisProducts( $args, $oasisCategories );
			$stats           = Api::getStatProducts( $oasisCategories );

			$progressBar              = get_option( 'oasis_progress' );
			$progressBar['total']     = $stats->products;
			$progressBar['step_item'] = 0;

			if ( $limit > 0 && ! empty( $products ) ) {
				$progressBar['step_total'] = count( $products );
			} else {
				$progressBar['step_total'] = 0;
				$progressBar['item']       = 0;
			}

			$nextStep = $products ? ++ $step : 0;

			$group_ids = [];
			foreach ( $products as $product ) {
				$group_ids[ $product->group_id ][ $product->id ] = $product;
			}
			unset( $products, $product );

			$total      = count( array_keys( $group_ids ) );
			$count      = 0;
			$time_start = microtime( true );

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

					$progressBar = parent::upProgressBar( $progressBar );
				} elseif ( count( $model ) > 1 ) {
					$firstProduct = parent::getFirstProduct( $model, $dbProduct );
					$categories   = parent::getProductCategories( $firstProduct->full_categories, $oasisCategories );

					if ( $dbProduct ) {
						$wcProductId = $dbProduct['post_id'];
						if ( ! parent::upWcProduct( $wcProductId, $model, $categories, $totalStock, $options ) ) {
							continue;
						}
					} else {
						$wcProductId = parent::addWcProduct( $firstProduct, $model, $categories, $totalStock, $options, 'variable' );
					}

					foreach ( $model as $variation ) {
						$dbVariation = parent::checkProductOasisTable( [ 'product_id_oasis' => $variation->id ], 'product_variation' );

						if ( $dbVariation ) {
							parent::upWcVariation( $dbVariation, $variation, $options );
						} else {
							parent::addWcVariation( $wcProductId, $variation, $options );
						}

						$progressBar = parent::upProgressBar( $progressBar );
					}
				}
				update_option( 'oasis_progress', $progressBar );
				parent::cliMsg( 'Done ' . ++ $count . ' from ' . $total );
			}

			if ( ! empty( $limit ) ) {
				update_option( 'oasis_step', $nextStep );
				$progressBar['step_item'] = $progressBar['step_total'];
			} else {
				$progressBar['item'] = $stats->products;
			}

			parent::cliMsg( 'Окончание обновления товаров' );
			$progressBar['date'] = current_time( 'mysql' );
			update_option( 'oasis_progress', $progressBar );

			$time_end = microtime( true );
			update_option( 'oasis_import_time', ( $time_end - $time_start ) );
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
			$time_start    = microtime( true );
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
					update_post_meta( $oasisProducts[ $item->id ], '_stock', $item->stock );
				}
			}
			unset( $item );

			$time_end = microtime( true );
			update_option( 'oasis_upStock_time', ( $time_end - $time_start ) );
		} catch ( Exception $exception ) {
			die();
		}
	}
}