<?php
/*
Plugin Name: Oasiscatalog - Product Importer
Plugin URI: https://forum.oasiscatalog.com
Description: Импорт товаров из каталога oasiscatalog.com в Woocommerce. Выгрузка заказов из Woocommerce в oasiscatalog. Виджет редактирования нанесения.
Version: 2.4.3
Text Domain: wp-oasis-importer
Author: Viktor Grishin
Author URI: https://sitever.ru
License: GPL2

WordPress tested:   6.2
Woocommerce tested: 6.2
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OASIS_PATH', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/src/Controller/Api.php';
require_once __DIR__ . '/src/Controller/Main.php';
require_once __DIR__ . '/src/order.php';
require_once __DIR__ . '/src/branding.php';

use OasisImport\Controller\Oasis\Api;
use OasisImport\Controller\Oasis\Main;

/**
 * Init translations
 */
add_action( 'plugins_loaded', 'true_load_plugin_textdomain' );

function true_load_plugin_textdomain() {
	load_plugin_textdomain( 'wp-oasis-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/**
 * Проверка на наличие включенного Woocommerce, создание таблицы и первоначальные настройки при активации плагина
 */
register_activation_hook( __FILE__, 'oasis_activate' );

function oasis_activate() {
	if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) && current_user_can( 'activate_plugins' ) ) {
		wp_die( 'Плагин Oasiscatalog - Product Importer не может работать без Woocommerce <br><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Вернуться на страницу плагинов</a>' );
	}
	create_table();
	update_option( 'oasis_step', 0 );
	Main::activatePluginUpOptions();
}

/**
 * Сброс части настроек при отключении плагина
 */
register_deactivation_hook( __FILE__, 'oasis_deactivate' );

function oasis_deactivate() {
	delete_option( 'oasis_progress' );
	delete_option( 'oasis_progress_tmp' );
	delete_option( 'oasis_step' );
	delete_option( 'oasis_currencies' );
	delete_option( 'oasis_options' );
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	include( ABSPATH . "wp-includes/pluggable.php" );
}

function oasis_admin_validations() {
	if ( ! is_php_version_compatible( '7.3' ) ) {
		wp_die( 'Вы используете старую версию PHP ' . phpversion() . '. Попросите администратора сервера её обновить до 7.3 или выше! <br><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Вернуться на страницу плагинов</a>' );
	}
}

/**
 * custom option and settings
 */
function oasis_settings_init() {

	register_setting( 'oasis', 'oasis_options', 'sanitize_data' );

	add_settings_section(
		'oasis_section_developers',
		__( 'Setting up import of Oasis products', 'wp-oasis-importer' ),
		null,
		'oasis'
	);

	add_settings_field(
		'oasis_api_key',
		__( 'Key API', 'wp-oasis-importer' ),
		'oasis_api_key_cb',
		'oasis',
		'oasis_section_developers',
		[
			'label_for' => 'oasis_api_key',
		]
	);

	add_settings_field(
		'oasis_api_user_id',
		__( 'API User ID', 'wp-oasis-importer' ),
		'oasis_api_user_id_cb',
		'oasis',
		'oasis_section_developers',
		[
			'label_for' => 'oasis_api_user_id',
		]
	);

	$options = get_option( 'oasis_options' );

	if ( empty( $options['oasis_api_key'] ) ) {
		add_settings_error( 'oasis_messages', 'oasis_messag', __( 'Specify the API key!', 'wp-oasis-importer' ) );
	} elseif ( empty( Api::getCurrenciesOasis( false ) ) ) {
		add_settings_error( 'oasis_messages', 'oasis_messag', __( 'API key is invalid', 'wp-oasis-importer' ) );
	} else {
		add_settings_field(
			'oasis_currency',
			__( 'Currency', 'wp-oasis-importer' ),
			'oasis_currency_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_currency',
			]
		);

		add_settings_field(
			'oasis_categories',
			__( 'Categories', 'wp-oasis-importer' ),
			'oasis_categories_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_categories',
			]
		);

		add_settings_field(
			'oasis_no_vat',
			__( 'No VAT', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_no_vat',
			]
		);

		add_settings_field(
			'oasis_not_on_order',
			__( 'Without goods "to order"', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_not_on_order',
			]
		);

		add_settings_field(
			'oasis_price_from',
			__( 'Price from', 'wp-oasis-importer' ),
			'oasis_number_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_price_from',
				'step'      => '0.01',
			]
		);

		add_settings_field(
			'oasis_price_to',
			__( 'Price to', 'wp-oasis-importer' ),
			'oasis_number_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_price_to',
				'step'      => '0.01',
			]
		);

		add_settings_field(
			'oasis_rating',
			__( 'Type', 'wp-oasis-importer' ),
			'oasis_rating_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_rating',
			]
		);

		add_settings_field(
			'oasis_warehouse_moscow',
			__( 'In stock in Moscow', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_warehouse_moscow',
			]
		);

		add_settings_field(
			'oasis_warehouse_europe',
			__( 'In stock in Europe', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_warehouse_europe',
			]
		);

		add_settings_field(
			'oasis_remote_warehouse',
			__( 'At a remote warehouse', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'oasis_remote_warehouse',
			]
		);

		add_settings_field(
			'oasis_limit',
			__( 'Limit products', 'wp-oasis-importer' ),
			'oasis_number_cb',
			'oasis',
			'oasis_section_developers',
			[
				'label_for'   => 'oasis_limit',
				'description' => __( 'The number of products received from the API and processed in one run.', 'wp-oasis-importer' ),
				'step'        => '100',
			]
		);

		add_settings_section(
			'oasis_section_price',
			__( 'Price settings', 'wp-oasis-importer' ),
			null,
			'oasis'
		);

		add_settings_field(
			'oasis_price_factor',
			__( 'Price factor', 'wp-oasis-importer' ),
			'oasis_number_cb',
			'oasis',
			'oasis_section_price',
			[
				'label_for'   => 'oasis_price_factor',
				'description' => __( 'The price will be multiplied by this factor. For example, in order to increase the cost by 20%, you need to specify 1.2', 'wp-oasis-importer' ),
				'step'        => '0.01',
			]
		);

		add_settings_field(
			'oasis_increase',
			__( 'Add to price', 'wp-oasis-importer' ),
			'oasis_number_cb',
			'oasis',
			'oasis_section_price',
			[
				'label_for'   => 'oasis_increase',
				'description' => __( 'This value will be added to the price. For example, if you specify 100, then 100 will be added to the cost of all products.', 'wp-oasis-importer' ),
				'step'        => '0.01',
			]
		);

		add_settings_field(
			'oasis_dealer',
			__( 'Use dealer prices', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_price',
			[
				'label_for' => 'oasis_dealer',
			]
		);

		add_settings_section(
			'oasis_section_additionally',
			__( 'Additional settings', 'wp-oasis-importer' ),
			null,
			'oasis'
		);

		add_settings_field(
			'oasis_comments',
			__( 'Enable reviews', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_additionally',
			[
				'label_for'   => 'oasis_comments',
				'description' => __( 'Enable commenting on imported products', 'wp-oasis-importer' ),
			]
		);

		add_settings_field(
			'oasis_disable_sales',
			__( 'Hide discounts', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_additionally',
			[
				'label_for'   => 'oasis_disable_sales',
				'description' => __( 'Hide "old" price in products', 'wp-oasis-importer' ),
			]
		);

		add_settings_field(
			'oasis_branding',
			__( 'Widget branding', 'wp-oasis-importer' ),
			'oasis_checbox_cb',
			'oasis',
			'oasis_section_additionally',
			[
				'label_for'   => 'oasis_branding',
				'description' => __( 'Enable branding widget on checkout page', 'wp-oasis-importer' ),
			]
		);
	}
}

function oasis_api_key_cb( $args ) {
	$options = get_option( 'oasis_options' );
	?>

	<input type="text" name="oasis_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
	       value="<?php echo $options[ $args['label_for'] ] ?? ''; ?>"
	       maxlength="255" style="width: 300px;"/>

	<p class="description"><?php echo __( 'After specifying the key, it will be possible to configure the import of products into Woocommerce from the Oasis website', 'wp-oasis-importer' ); ?></p>
	<?php
}

function oasis_api_user_id_cb( $args ) {
	$options = get_option( 'oasis_options' );
	?>

	<input type="text" name="oasis_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
	       value="<?php echo $options[ $args['label_for'] ] ?? ''; ?>"
	       maxlength="255" style="width: 120px;"/>

	<p class="description"><?php echo __( 'After specifying the user id, it will be possible to upload orders to Oasis', 'wp-oasis-importer' ); ?></p>
	<?php
}

function oasis_categories_cb() {
	$options    = get_option( 'oasis_options' );
	$categories = Api::getCategoriesOasis( false );
	$arr_cat    = [];

	foreach ( $categories as $item ) {
		if ( empty( $arr_cat[ (int) $item->parent_id ] ) ) {
			$arr_cat[ (int) $item->parent_id ] = [];
		}
		$arr_cat[ (int) $item->parent_id ][] = (array) $item;
	}

	echo '<ul id="tree">' . PHP_EOL . Main::buildTreeCats( $arr_cat, $options['oasis_categories'] ?? [] ) . PHP_EOL . '</ul>' . PHP_EOL;
}

function oasis_currency_cb( $args ) {
	$options         = get_option( 'oasis_options' );
	$defaultCurrency = $options['oasis_currency'] ?? 'rub';
	?>

	<select name="oasis_options[<?php echo esc_attr( $args['label_for'] ); ?>]" id="input-currency" class="form-select">
		<?php
		$currencies = get_option( 'oasis_currencies' );

		if ( empty( $currencies ) ) {
			$currencies      = [];
			$currenciesOasis = Api::getCurrenciesOasis();

			foreach ( $currenciesOasis as $currency ) {
				$currencies[] = [
					'code' => $currency->code,
					'name' => $currency->full_name
				];
			}
		}

		foreach ( $currencies as $currency ) {
			$selected = '';
			if ( $currency['code'] === $defaultCurrency ) {
				$selected = ' selected="selected"';
			}
			echo '<option value="' . $currency['code'] . '"' . $selected . '>' . $currency['name'] . '</option>' . PHP_EOL;
		}
		unset( $currency );
		?>
	</select>
	<?php
}

function oasis_checbox_cb( $args ) {
	$options = get_option( 'oasis_options' );
	$checked = $options[ $args['label_for'] ] ?? false;
	?>
	<input name="oasis_options[<?php echo esc_attr( $args['label_for'] ); ?>]" type="checkbox"<?php echo checked( 1, $checked, false ); ?> value="1"
	       class="code"/>
	<?php
	echo ! empty( $args['description'] ) ? '<p class="description">' . $args['description'] . '</p>' : '';
}

function oasis_number_cb( $args ) {
	$options = get_option( 'oasis_options' );
	?>

	<input type="number" name="oasis_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
		<?php echo $args['step'] ? 'step="' . $args['step'] . '"' : ''; ?>
		   value="<?php echo $options[ $args['label_for'] ] ?? ''; ?>"
		   maxlength="255" style="width: 120px;"/>
	<?php
	echo ! empty( $args['description'] ) ? '<p class="description">' . $args['description'] . '</p>' : '';
}

function oasis_rating_cb( $args ) {
	$options = get_option( 'oasis_options' );
	?>

	<select name="oasis_options[<?php echo esc_attr( $args['label_for'] ); ?>]" id="input-rating" class="form-select col-sm-6">
		<option value=""><?php echo __( '---Select---', 'wp-oasis-importer' ); ?></option>
		<option value="1" <?php selected( $options[ $args['label_for'] ], 1 ); ?>><?php echo __( 'Only new items', 'wp-oasis-importer' ); ?></option>
		<option value="2" <?php selected( $options[ $args['label_for'] ], 2 ); ?>><?php echo __( 'Only hits', 'wp-oasis-importer' ); ?></option>
		<option value="3" <?php selected( $options[ $args['label_for'] ], 3 ); ?>><?php echo __( 'Discount only', 'wp-oasis-importer' ); ?></option>
	</select>
	<?php
}


function sanitize_data( $options ) {
	foreach ( $options as $name => & $val ) {
		if ( $name === 'oasis_api_key' || $name === 'oasis_api_user_id' ) {
			$val = trim( $val );
		} elseif ( $name === 'oasis_limit' && empty( $val ) ) {
			update_option( 'oasis_step', 0 );
		}
	}

	return $options;
}

/**
 * Добавление пункта меню в раздел Инструменты для настройки импорта
 */
if ( is_admin() ) {
	function oasis_menu() {
		$page = add_submenu_page(
			'woocommerce',
			__( 'Oasis Import', 'wp-oasis-importer' ),
			__( 'Oasis Import', 'wp-oasis-importer' ),
			'manage_options',
			'oasis',
			'oasis_page_html'
		);

		add_action( 'load-' . $page, 'oasis_admin_styles' );
		add_action( 'load-' . $page, 'oasis_admin_validations' );
		oasis_settings_init();
	}

	function oasis_admin_styles() {
		wp_enqueue_style( 'oasis-stylesheet', plugins_url( 'assets/css/stylesheet.css', __FILE__ ) );
		wp_enqueue_style( 'font-awesome', plugins_url( 'assets/css/font-awesome.min.css', __FILE__ ) );
		wp_enqueue_style( 'bootstrap530-alpha3', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css' );
		wp_enqueue_script( 'bootstrap530-alpha3', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/js/bootstrap.bundle.min.js' );
		wp_enqueue_script( 'jquery-tree', plugins_url( 'assets/js/jquery.tree.js', __FILE__ ), [ 'jquery' ] );
		wp_enqueue_script( 'custom', plugins_url( 'assets/js/custom.js', __FILE__ ), [ 'jquery' ], false, true );
	}

	function add_bootstrap530_alpha3_style( $html, $handle ) {
		if ( 'bootstrap530-alpha3' === $handle ) {
			return str_replace( "media='all'", "media='all' integrity='sha384-KK94CHFLLe+nY2dmCWGMq91rCGa5gtU4mk92HdvYe+M/SXH301p5ILy+dN9+nJOZ' crossorigin='anonymous'", $html );
		}

		return $html;
	}

	add_filter( 'style_loader_tag', 'add_bootstrap530_alpha3_style', 10, 2 );

	function add_bootstrap530_alpha3_script( $html, $handle ) {
		if ( 'bootstrap530-alpha3' === $handle ) {
			return str_replace( "'>", "' integrity='sha384-ENjdO4Dr2bkBIFxQpeoTz1HIcje39Wm4jDKdf19U8gI4ddQ3GYNS7NTKfAdVQSZe' crossorigin='anonymous'>", $html );
		}

		return $html;
	}

	add_filter( 'script_loader_tag', 'add_bootstrap530_alpha3_script', 10, 2 );

	add_action( 'admin_menu', 'oasis_menu' );

	function oasis_page_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'oasis_messages', 'oasis_messag', __( 'Settings saved', 'wp-oasis-importer' ), 'updated' );
		}

		settings_errors( 'oasis_messages' );

		$lockProcess = Main::checkLockProcess();
		$options     = get_option( 'oasis_options' );
		$pBar        = get_option( $lockProcess ? 'oasis_progress_tmp' : 'oasis_progress' );
		$limit       = isset( $options['oasis_limit'] ) ? intval( $options['oasis_limit'] ) : null;

		if ( empty( $pBar ) ) {
			$pBar              = get_option( 'oasis_progress' );
			$pBar['step_item'] = 0;
		}

		?>

		<div class="wrap">
			<div class="container-fluid">
				<?php
				if ( ! empty( $options['oasis_api_key'] ) ) {
					$cronTask = 'php ' . OASIS_PATH . 'cli.php --key=' . md5( $options['oasis_api_key'] );

					if ( ! empty( $pBar['item'] ) && ! empty( $pBar['total'] ) ) {
						$percentTotal = round( ( $pBar['item'] / $pBar['total'] ) * 100, 2, PHP_ROUND_HALF_DOWN );
						$percentTotal = min( $percentTotal, 100 );
					} else {
						$percentTotal = 0;
					}

					if ( ! empty( $pBar['step_item'] ) && ! empty( $pBar['step_total'] ) ) {
						$percentStep = round( ( $pBar['step_item'] / $pBar['step_total'] ) * 100, 2, PHP_ROUND_HALF_DOWN );
					} else {
						$percentStep = 0;
					}

					$progressClass = $lockProcess ? 'progress-bar progress-bar-striped progress-bar-animated' : 'progress-bar';

					if ( $lockProcess ) {
						$dIcon = '<span class="oasis-process-icon"><i class="fa fa-cog fa-spin fa-fw" style="color: #0c7a0a;"></i><span class="sr-only">' . __( 'Loading...', 'wp-oasis-importer' ) . '</span></span>';
					} else {
						$dIcon = '<span class="oasis-process-icon"><i class="fa fa-pause" aria-hidden="true" style="color: #e97906;"></i></span>';
					}
					?>

					<div class="row">
						<div class="col-md-12">
							<div class="oa-notice oa-notice-info">
								<div class="row">
									<div class="col-md-4 col-sm-12">
										<h5><?php echo __( 'General processing status', 'wp-oasis-importer' ) . ' ' . $dIcon; ?></h5>
									</div>
									<div class="col-md-8 col-sm-12">
										<div class="progress">
											<div id="upAjaxTotal" class="<?php echo $progressClass; ?>" role="progressbar"
											     aria-valuenow="<?php echo $percentTotal; ?>"
											     aria-valuemin="0" aria-valuemax="100" style="width: <?php echo $percentTotal; ?>%">
												<?php echo $percentTotal; ?>%
											</div>
										</div>
									</div>
								</div>
								<?php if ( $limit > 0 ) {
									$stepTotal  = ! empty( $pBar['total'] ) ? ceil( intval( $pBar['total'] ) / intval( $limit ) ) : 0;
									$oasis_step = intval( get_option( 'oasis_step' ) );
									$step       = $oasis_step < $stepTotal ? ++ $oasis_step : $oasis_step;

									if ( $lockProcess ) {
										$step_text = sprintf( __( '%s step in progress out of %s. Current step status', 'wp-oasis-importer' ), strval( $step ), strval( $stepTotal ) );
									} else {
										$step_text = sprintf( __( 'Next step %s of %s.', 'wp-oasis-importer' ), strval( $step ), strval( $stepTotal ) );
									}
									?>
									<div class="row">
										<div class="col-md-4 col-sm-12">
											<h5><span class="oasis-process-text"><?php echo $step_text; ?></span></h5>
										</div>
										<div class="col-md-8 col-sm-12">
											<div class="progress">
												<div id="upAjaxStep" class="<?php echo $progressClass; ?>" role="progressbar"
												     aria-valuenow="<?php echo $percentStep; ?>"
												     aria-valuemin="0" aria-valuemax="100"
												     style="width: <?php echo $percentStep; ?>%"><?php echo $percentStep; ?>%
												</div>
											</div>
										</div>
									</div>
								<?php } ?>
								<p><?php echo sprintf( __( 'Last import completed: %s', 'wp-oasis-importer' ), $pBar['date'] ?? '' ); ?></p>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-md-12">
							<div class="oa-notice">
								<div class="row">
									<div class="col-md-12">
										<p><?php echo __( 'To enable automatic updating of the directory, you need to add crontab tasks in the hosting control panel: <br/>
<strong>Do not disclose this information!</strong>', 'wp-oasis-importer' ); ?></p>
									</div>
								</div>
								<div class="row">
									<div class="col-md-4 col-sm-12">
										<p><?php echo __( 'Download / update products 1 time per day', 'wp-oasis-importer' ); ?></p>
									</div>
									<div class="col-md-8 col-sm-12">
										<input type="text" class="form-control input-cron-task" value="<?php echo $cronTask; ?>"
										       aria-label="<?php echo $cronTask; ?>" id="inputImport" readonly="readonly" onFocus="this.select()">
										<span id="copyImport" class="ispan" data-bs-toggle="tooltip" data-bs-placement="right"
										      data-bs-title="<?php echo __( 'Copy', 'wp-oasis-importer' ); ?>">
                                            <i class="fa fa-clone" aria-hidden="true"></i>
                                        </span>
									</div>
								</div>
								<div class="row">
									<div class="col-md-4 col-sm-12">
										<p><?php echo __( 'Renewal of balances 1 time in 30 minutes', 'wp-oasis-importer' ); ?></p>
									</div>
									<div class="col-md-8 col-sm-12">
										<input type="text" class="form-control input-cron-task" value="<?php echo $cronTask; ?> --up"
										       aria-label="<?php echo $cronTask; ?> --up" id="inputUp" readonly="readonly" onFocus="this.select()">
										<span id="copyUp" class="ispan" data-bs-toggle="tooltip" data-bs-placement="right"
										      data-bs-title="<?php echo __( 'Copy', 'wp-oasis-importer' ); ?>">
                                            <i class="fa fa-clone" aria-hidden="true"></i>
                                        </span>
									</div>
								</div>
							</div>
						</div>
					</div>
					<?php
				}
				?>

				<div class="row">
					<div class="col-md-12">
						<form action="options.php" method="post" class="oasis-form">
							<?php
							settings_fields( 'oasis' );
							do_settings_sections( 'oasis' );
							submit_button( __( 'Save settings', 'wp-oasis-importer' ) );
							?>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	add_action( 'product_cat_add_form_fields', 'true_add_cat_fields' );

	function true_add_cat_fields( $taxonomy ) {
		echo '<div class="form-field">
		<label for="oasis-cat-id">Oasis ID</label>
		<input type="text" name="oasis_cat_id" id="oasis_cat_id" />
	</div>';
	}

	add_action( 'product_cat_edit_form_fields', 'true_edit_term_fields', 10, 2 );

	function true_edit_term_fields( $term, $taxonomy ) {
		$oasis_cat_id = get_term_meta( $term->term_id, 'oasis_cat_id', true );

		echo '<tr class="form-field">
	<th>
		<label for="oasis_cat_id">Oasis cat ID</label>
	</th>
	<td>
		<input name="oasis_cat_id" id="oasis_cat_id" type="text" value="' . esc_attr( $oasis_cat_id ) . '" />
	</td>
	</tr>';
	}

	add_action( 'created_product_cat', 'true_save_term_fields' );
	add_action( 'edited_product_cat', 'true_save_term_fields' );

	function true_save_term_fields( $term_id ) {
		if ( isset( $_POST['oasis_cat_id'] ) ) {
			update_term_meta( $term_id, 'oasis_cat_id', sanitize_text_field( $_POST['oasis_cat_id'] ) );
		} else {
			delete_term_meta( $term_id, 'oasis_cat_id' );
		}
	}
}

function create_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$wpdb->prefix}oasis_products (
	post_id bigint(20) unsigned NOT NULL,
	product_id_oasis char(12) NOT NULL,
	model_id_oasis char(12) NOT NULL,
	variation_parent_size_id char(12) DEFAULT NULL,
	type char(30) NOT NULL,
	PRIMARY KEY (post_id)
	)
	{$charset_collate};";

	dbDelta( $sql );
}

add_filter( 'plugin_action_links', function ( $links, $file ) {
	if ( $file != plugin_basename( __FILE__ ) ) {
		return $links;
	}

	$settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=oasis' ), __( 'Settings', 'wp-oasis-importer' ) );
	array_unshift( $links, $settings_link );

	return $links;
}, 10, 2 );

add_action( 'wp_ajax_up_progress_bar', 'get_percent_progress_bar' );

function get_percent_progress_bar() {
	$lockProcess = Main::checkLockProcess();

	$options    = get_option( 'oasis_options' );
	$pBar       = get_option( $lockProcess ? 'oasis_progress_tmp' : 'oasis_progress' );
	$limit      = isset( $options['oasis_limit'] ) ? intval( $options['oasis_limit'] ) : null;
	$stepTotal  = ! empty( $pBar['total'] ) ? ceil( intval( $pBar['total'] ) / intval( $limit ) ) : 0;
	$oasis_step = intval( get_option( 'oasis_step' ) );
	$step       = $oasis_step < $stepTotal ? $oasis_step + 1 : $oasis_step;

	$result = [
		'status_progress' => false,
		'progress_icon'   => '<i class="fa fa-pause" aria-hidden="true" style="color: #e97906;"></i>',
	];

	if ( $oasis_step === 0 && ! $lockProcess ) {
		$result['total_item'] = 0;
	}

	if ( $limit ) {
		$result['progress_step_text'] = sprintf( __( 'Next step %s of %s.', 'wp-oasis-importer' ), strval( $step ), strval( $stepTotal ) );
		if ( ! $lockProcess ) {
			$result['step_item'] = 0;
		}
	}

	if ( $lockProcess ) {
		$pBar = get_option( 'oasis_progress_tmp' );

		if ( $pBar ) {
			$step_item  = round( ( $pBar['step_item'] / $pBar['step_total'] ) * 100, 2, PHP_ROUND_HALF_DOWN );
			$total_item = round( ( $pBar['item'] / $pBar['total'] ) * 100, 2, PHP_ROUND_HALF_DOWN );

			$result['total_item']      = min( $total_item, 100 );
			$result['status_progress'] = true;
			$result['progress_icon']   = '<i class="fa fa-cog fa-spin fa-fw" style="color: #0c7a0a;"></i><span class="sr-only">' . __( 'Loading...', 'wp-oasis-importer' ) . '</span>';

			if ( $limit ) {
				$result['step_item']          = $step_item > 99.5 ? 100 : $step_item;
				$result['progress_step_text'] = sprintf( __( '%s step in progress out of %s. Current step status', 'wp-oasis-importer' ), strval( $step ), strval( $stepTotal ) );
			}
		}
	}

	echo json_encode( $result );
	wp_die();
}

add_action( 'wp_ajax_check_status_progress', 'check_status_progress' );

function check_status_progress() {
	echo json_encode( [ 'status_progress' => (bool) Main::checkLockProcess(), ] );
	wp_die();
}
