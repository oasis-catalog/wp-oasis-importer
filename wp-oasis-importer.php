<?php
/*
Plugin Name: Oasiscatalog - Product Importer
Plugin URI: https://www.oasiscatalog.com
Description: Импорт товаров из каталога oasiscatalog.com в Woocommerce. Выгрузка заказов из Woocommerce в oasiscatalog. Виджет редактирования нанесения.
Version: 2.4.7
Text Domain: wp-oasis-importer
License: GPL2

WordPress tested:   6.2
Woocommerce tested: 6.2
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OASIS_PATH', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/src/lib/Config.php';
require_once __DIR__ . '/src/lib/Main.php';
require_once __DIR__ . '/src/lib/Cli.php';
require_once __DIR__ . '/src/lib/Api.php';
require_once __DIR__ . '/src/order.php';
require_once __DIR__ . '/src/branding.php';

use OasisImport\Api;
use OasisImport\Main;
use OasisImport\Config as OasisConfig;

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

	$cf = OasisConfig::instance();
	$cf->activate();

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

/**
 * Сброс части настроек при отключении плагина
 */
register_deactivation_hook( __FILE__, 'oasis_deactivate' );

function oasis_deactivate() {
	$cf = OasisConfig::instance();
	$cf->deactivate();
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
	$cf = OasisConfig::instance([
		'init' => true
	]);

	register_setting( 'oasis', 'oasis_options', 'oasis_sanitize_data' );

	add_settings_section(
		'oasis_section_developers',
		__( 'Setting up import of Oasis products', 'wp-oasis-importer' ),
		null,
		'oasis'
	);

	add_settings_field(
		'api_key',
		__( 'Key API', 'wp-oasis-importer' ),
		function() use($cf) {
			echo '<input type="text" name="oasis_options[api_key]" value="'.$cf->api_key.'" maxlength="255" style="width: 300px;"/>
				<p class="description">'.__( 'After specifying the key, it will be possible to configure the import of products into Woocommerce from the Oasis website', 'wp-oasis-importer' ).'</p>';
		},
		'oasis',
		'oasis_section_developers'
	);

	add_settings_field(
		'api_user_id',
		__( 'API User ID', 'wp-oasis-importer' ),
		function() use($cf) {
			echo '<input type="text" name="oasis_options[api_user_id]" value="'.$cf->api_user_id.'" maxlength="255" style="width: 120px;"/>
				<p class="description">'.__( 'After specifying the user id, it will be possible to upload orders to Oasis', 'wp-oasis-importer' ).'</p>';
		},
		'oasis',
		'oasis_section_developers'
	);

	if (empty($cf->api_key)) {
		add_settings_error( 'oasis_messages', 'oasis_messag', __( 'Specify the API key!', 'wp-oasis-importer' ) );
	} elseif (!$cf->loadCurrencies()) {
		add_settings_error( 'oasis_messages', 'oasis_messag', __( 'API key is invalid', 'wp-oasis-importer' ) );
	} else {
		$cf->initRelation();

		add_settings_field(
			'currency',
			__( 'Currency', 'wp-oasis-importer' ),
			function() use($cf) {
				echo '<select name="oasis_options[currency]" id="input-currency" class="form-select">';
				foreach ($cf->currencies as $c) {
					echo '<option value="'.$c['code'].'" '.($c['code'] === $cf->currency ? 'selected="selected"' : '').'>'.$c['name'].'</option>';
				}
				echo '</select>';
			},
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'categories',
			__( 'Categories', 'wp-oasis-importer' ),
			function() use($cf) {
				$categories = Api::getCategoriesOasis( false );
				$arr_cat    = [];

				foreach ( $categories as $item ) {
					if ( empty( $arr_cat[ (int) $item->parent_id ] ) ) {
						$arr_cat[ (int) $item->parent_id ] = [];
					}
					$arr_cat[ (int) $item->parent_id ][] = (array) $item;
				}

				echo '<div id="tree" class="oa-tree">
						<div class="oa-tree-ctrl">
							<button type="button" class="btn btn-sm btn-light oa-tree-ctrl-m">Свернуть все</button>
							<button type="button" class="btn btn-sm btn-light oa-tree-ctrl-p">Развернуть все</button>
						</div>' . Main::buildTreeCats($arr_cat, $cf->categories, $cf->categories_rel) . '</div>';
			},
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'category_rel',
			__( 'Default category', 'wp-oasis-importer' ),
			function() use($cf) {
				echo '<div id="cf_opt_category_rel">
					<input type="hidden" value="'. $cf->category_rel .'" name="oasis_options[category_rel]">
					<div class="oa-category-rel">'. $cf->category_rel_label .'</div></div>
					<p class="description">'.__( 'If no link is specified for a product category, the product will be placed in the default category', 'wp-oasis-importer' ).'</p>';
			},
			'oasis',
			'oasis_section_developers',
			[
				'label_for' => 'category_rel',
			]
		);

		add_settings_field(
			'is_not_up_cat',
			__( 'Do not update categories', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_not_up_cat', $cf->is_not_up_cat),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'is_import_anytime',
			__( 'Do not limit update', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_import_anytime', $cf->is_import_anytime, __( 'Full product update is limited, no more than once a day', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'limit',
			__( 'Limit products', 'wp-oasis-importer' ),
			fn() => oasis_sf_number('limit', $cf->limit, 100, __( 'The number of products received from the API and processed in one run.', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_developers'
		);


		add_settings_field(
			'is_no_vat',
			__( 'No VAT', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_no_vat', $cf->is_no_vat),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'is_not_on_order',
			__( 'Without goods "to order"', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_not_on_order', $cf->is_not_on_order),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'price_from',
			__( 'Price from', 'wp-oasis-importer' ),
			fn() => oasis_sf_number('price_from', $cf->price_from, 0.01),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'price_to',
			__( 'Price to', 'wp-oasis-importer' ),
			fn() => oasis_sf_number('price_to', $cf->price_to, 0.01),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'rating',
			__( 'Type', 'wp-oasis-importer' ),
			function() use($cf) {
				?>
				<select name="oasis_options[rating]" class="form-select col-sm-6">
					<option value=""><?php echo __( '---Select---', 'wp-oasis-importer' ); ?></option>
					<option value="1" <?php selected( $cf->rating, 1 ); ?>><?= __( 'Only new items', 'wp-oasis-importer' ); ?></option>
					<option value="2" <?php selected( $cf->rating, 2 ); ?>><?= __( 'Only hits', 'wp-oasis-importer' ); ?></option>
					<option value="3" <?php selected( $cf->rating, 3 ); ?>><?= __( 'Discount only', 'wp-oasis-importer' ); ?></option>
				</select>
				<?php
			},
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'is_wh_moscow',
			__( 'In stock in Moscow', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_wh_moscow', $cf->is_wh_moscow),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'is_wh_europe',
			__( 'In stock in Europe', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_wh_europe', $cf->is_wh_europe),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_field(
			'is_wh_remote',
			__( 'At a remote warehouse', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_wh_remote', $cf->is_wh_remote),
			'oasis',
			'oasis_section_developers'
		);

		add_settings_section(
			'oasis_section_price',
			__( 'Price settings', 'wp-oasis-importer' ),
			null,
			'oasis'
		);

		add_settings_field(
			'price_factor',
			__( 'Price factor', 'wp-oasis-importer' ),
			fn() => oasis_sf_number('price_factor', $cf->price_factor, 0.01, __( 'The price will be multiplied by this factor. For example, in order to increase the cost by 20%, you need to specify 1.2', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_price'
		);

		add_settings_field(
			'price_increase',
			__( 'Add to price', 'wp-oasis-importer' ),
			fn() => oasis_sf_number('price_increase', $cf->price_increase, 0.01, __( 'This value will be added to the price. For example, if you specify 100, then 100 will be added to the cost of all products.', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_price'
		);

		add_settings_field(
			'is_price_dealer',
			__( 'Use dealer prices', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_price_dealer', $cf->is_price_dealer),
			'oasis',
			'oasis_section_price'
		);

		add_settings_section(
			'oasis_section_additionally',
			__( 'Additional settings', 'wp-oasis-importer' ),
			null,
			'oasis'
		);

		add_settings_field(
			'is_comments',
			__( 'Enable reviews', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_comments', $cf->is_comments, __( 'Enable commenting on imported products', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_additionally'
		);
		add_settings_field(
			'is_brands',
			__( 'Enable brands', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_brands', $cf->is_brands, __( 'Enable brands on imported products', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_additionally'
		);

		add_settings_field(
			'is_disable_sales',
			__( 'Hide discounts', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_disable_sales', $cf->is_disable_sales, __( 'Hide "old" price in products', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_additionally'
		);

		add_settings_field(
			'is_branding',
			__( 'Widget branding', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_branding', $cf->is_branding, __( 'Enable branding widget on checkout page', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_additionally'
		);

		add_settings_field(
			'is_up_photo',
			__( 'Up photo', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_up_photo', $cf->is_up_photo, __( 'Enable update photos', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_additionally'
		);

		add_settings_field(
			'is_cdn_photo',
			__( 'Use CDN image server', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_cdn_photo', $cf->is_cdn_photo, __( 'Display product photos without uploading, saves space on hosting. May not work correctly with some themes and plugins', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_additionally'
		);
		add_settings_field(
			'is_fast_import',
			__( 'Quick import of products', 'wp-oasis-importer' ),
			fn() => oasis_sf_checbox('is_fast_import', $cf->is_fast_import, __( 'Import without photos. After a full upload of all products, the option is turned off', 'wp-oasis-importer' )),
			'oasis',
			'oasis_section_additionally'
		);
	}
}


function oasis_sf_checbox($opt, $is, $description = '') {
	echo '<input type="checkbox" class="code" value="1" name="oasis_options['.$opt.']" '.($is ? 'checked' : '').' />';
	if(!empty($description))
		echo '<p class="description">'.$description.'</p>';
}

function oasis_sf_number($opt, $v, $step, $description = '') {
	echo '<input type="number" maxlength="255" style="width: 120px;" name="oasis_options['.$opt.']" value="'.($v ? $v : '').'" step="'.$step.'" />';
	if(!empty($description))
		echo '<p class="description">'.$description.'</p>';
}

function oasis_sanitize_data( $options ) {
	if (empty($options['api_key'])) {
		add_settings_error(
			'oasis_messages',
			'oasis_messag',
			__('Specify the API key!', 'wp-oasis-importer'),
			'warning'
		);

		return get_option('oasis_options');
	}

	$cf = OasisConfig::instance([
		'init' => true
	]);

	foreach ( $options as $name => & $val ) {
		if ( $name === 'api_key' || $name === 'api_user_id' ) {
			$val = trim( $val );
		}
		elseif ($name == 'cat_relation'){
			$val = array_filter($val, fn($x) => !empty($x));
			$val = array_unique($val);
		}
	}

	$cf->progressClear();
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
		wp_enqueue_style( 'bootstrap533', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' );
		wp_enqueue_script( 'bootstrap533', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js' );
		wp_enqueue_script( 'oa-tree', plugins_url( 'assets/js/tree.js', __FILE__ ), [ 'jquery' ] );
		wp_enqueue_script( 'oasis-custom', plugins_url( 'assets/js/custom.js', __FILE__ ), [ 'oa-tree', 'jquery' ], false, true );
	}

	function oasis_add_bootstrap533_style( $html, $handle ) {
		if ( 'bootstrap533' === $handle ) {
			return str_replace( "media='all'", "media='all' integrity='sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH' crossorigin='anonymous'", $html );
		}

		return $html;
	}

	add_filter( 'style_loader_tag', 'oasis_add_bootstrap533_style', 10, 2 );

	function oasis_add_bootstrap533_script( $html, $handle ) {
		if ( 'bootstrap533' === $handle ) {
			return str_replace( "''>", "' integrity='sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz' crossorigin='anonymous'>", $html );
		}

		return $html;
	}

	add_filter( 'script_loader_tag', 'oasis_add_bootstrap533_script', 10, 2 );

	add_action( 'admin_menu', 'oasis_menu' );

	function oasis_page_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'oasis_messages', 'oasis_messag', __( 'Settings saved', 'wp-oasis-importer' ), 'updated' );
		}

		settings_errors( 'oasis_messages' );

		$cf = OasisConfig::instance([
			'init' => true
		]);
		$optBar = $cf->getOptBar();
		?>
		<div class="wrap">
			<div class="container-fluid">
				<?php
				if (!empty($cf->api_key)){
					$cronTask = 'php ' . OASIS_PATH . 'cron.php --key=' . $cf->getCronKey();
					if(function_exists('get_sites')){
						$sites = get_sites([ 'count' => true ]);
						if($sites > 1){
							$cronTask .= ' --site='.get_current_blog_id();
						}
					}

					$progressClass = $optBar['is_process'] ? 'progress-bar progress-bar-striped progress-bar-animated' : 'progress-bar';
					$dIcon = $optBar['is_process'] ? '<span class="oasis-process-icon"><span class="oasis-icon-run" data-bs-toggle="tooltip" data-bs-title="'.__( 'Loading...', 'wp-oasis-importer' ).'">' :
												'<span class="oasis-process-icon"><span class="oasis-icon-pause"><span></span>';
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
												aria-valuenow="<?= $optBar['p_total']; ?>"
												aria-valuemin="0" aria-valuemax="100" style="width: <?= $optBar['p_total']; ?>%">
												<?= $optBar['p_total']; ?>%
											</div>
										</div>
									</div>
								</div>
								<?php if ($cf->limit > 0) {
									$step_text = '';
									if($optBar['steps']){
										if ($optBar['is_process']) {
											$step_text = sprintf( __( '%s step in progress out of %s. Current step status', 'wp-oasis-importer' ), ($optBar['step'] + 1), $optBar['steps'] );
										} else {
											$step_text = sprintf( __( 'Next step %s of %s.', 'wp-oasis-importer' ), ($optBar['step'] + 1), $optBar['steps'] );
										}
									}
									?>
									<div class="row">
										<div class="col-md-4 col-sm-12">
											<h5><span class="oasis-process-text"><?php echo $step_text; ?></span></h5>
										</div>
										<div class="col-md-8 col-sm-12">
											<div class="progress">
												<div id="upAjaxStep" class="<?php echo $progressClass; ?>" role="progressbar"
													 aria-valuenow="<?= $optBar['p_step']; ?>"
													aria-valuemin="0" aria-valuemax="100"
													style="width: <?= $optBar['p_step']; ?>%"><?= $optBar['p_step']; ?>%
												</div>
											</div>
										</div>
									</div>
								<?php } ?>
								<p><?= sprintf( __( 'Last import completed: %s', 'wp-oasis-importer' ), $optBar['date'] ?? '' ); ?></p>
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
										<div class="input-group input-group-sm">
											<input type="text" class="form-control input-cron-task" value="<?php echo $cronTask; ?>" aria-label="<?php echo $cronTask; ?>" id="inputImport" readonly="readonly" onFocus="this.select()">
											<div class="btn btn-primary" id="copyImport" data-bs-toggle="tooltip" data-bs-title="<?php echo __( 'Copy', 'wp-oasis-importer' ); ?>">
												<div class="oasis-icon-copy"></div>
											</div>
										</div>
									</div>
								</div>
								<div class="row">
									<div class="col-md-4 col-sm-12">
										<p><?php echo __( 'Renewal of balances 1 time in 30 minutes', 'wp-oasis-importer' ); ?></p>
									</div>
									<div class="col-md-8 col-sm-12">
										<div class="input-group input-group-sm">
											<input type="text" class="form-control input-cron-task" value="<?php echo $cronTask; ?> --up" aria-label="<?php echo $cronTask; ?> --up" id="inputUp" readonly="readonly" onFocus="this.select()">
											<div class="btn btn-primary" id="copyUp" data-bs-toggle="tooltip" data-bs-title="<?php echo __( 'Copy', 'wp-oasis-importer' ); ?>">
												<div class="oasis-icon-copy"></div>
											</div>
										</div>
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
			<div id="oasis-relation" class="modal fade" tabindex="-1" tabindex="-1" aria-modal="true" role="dialog">
				<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title"><?= __( 'Categories', 'wp-oasis-importer' ) ?></h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body"></div>
						<div class="modal-footer">
							<button type="button" class="btn btn-danger mx-3 js-clear"><?= __( 'Clear', 'wp-oasis-importer' ) ?></button>
							<button type="button" class="btn btn-primary js-ok"><?= __( 'Select', 'wp-oasis-importer' ) ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	add_action( 'product_cat_edit_form_fields', 'oasis_cat_edit_term_fields', 10, 2 );

	function oasis_cat_edit_term_fields( $term, $taxonomy ) {
		$oasis_cat_id = get_term_meta( $term->term_id, 'oasis_cat_id', true );

		echo '<tr class="form-field">
	<th>
		<label for="oasis_cat_id">Oasis cat ID</label>
	</th>
	<td>
		<input name="oasis_cat_id" readonly id="oasis_cat_id" type="text" value="' . esc_attr( $oasis_cat_id ) . '" />
	</td>
	</tr>';
	}

	add_action( 'created_product_cat', 'oasis_cat_save_term_fields' );
	add_action( 'edited_product_cat', 'oasis_cat_save_term_fields' );

	function oasis_cat_save_term_fields( $term_id ) {
		if ( isset( $_POST['oasis_cat_id'] ) ) {
			update_term_meta( $term_id, 'oasis_cat_id', sanitize_text_field( $_POST['oasis_cat_id'] ) );
		} else {
			delete_term_meta( $term_id, 'oasis_cat_id' );
		}
	}
}

add_filter( 'plugin_action_links', function ( $links, $file ) {
	if ( $file != plugin_basename( __FILE__ ) ) {
		return $links;
	}

	$settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=oasis' ), __( 'Settings', 'wp-oasis-importer' ) );
	array_unshift( $links, $settings_link );

	return $links;
}, 10, 2 );

add_action( 'wp_ajax_oasis_get_progress_bar', 'oasis_get_progress_bar' );

function oasis_get_progress_bar() {
	$cf = OasisConfig::instance([
		'init' => true
	]);
	$optBar = $cf->getOptBar();

	$step_text = '';
	if($optBar['steps']){
		if ($optBar['is_process']) {
			$step_text = sprintf( __( '%s step in progress out of %s. Current step status', 'wp-oasis-importer' ), ($optBar['step'] + 1), $optBar['steps'] );
		} else {
			$step_text = sprintf( __( 'Next step %s of %s.', 'wp-oasis-importer' ), ($optBar['step'] + 1), $optBar['steps'] );
		}
	}

	echo json_encode([
		'is_process' => $optBar['is_process'],
		'progress_icon'   => $optBar['is_process'] ? '<span class="oasis-process-icon"><span class="oasis-icon-run" data-bs-toggle="tooltip" data-bs-title="'.__( 'Loading...', 'wp-oasis-importer' ).'">' :
											'<span class="oasis-icon-pause"></span>',
		'p_total' => $optBar['p_total'],
		'p_step' => $optBar['p_step'],
		'step_text' => $step_text
	]);
	wp_die();
}

function oasis_get_all_categories() {
	$categories = get_categories( [
		'taxonomy'   => 'product_cat',
		'hide_empty'   => 0
	]);

	$arr = [];
	foreach ($categories as $item) {
		if (empty($arr[$item->parent])) {
			$arr[$item->parent] = [];
		}
		$arr[$item->parent][] = [
			'id' => $item->term_id,
			'name' => $item->name,
		];
	}

	echo '<div class="oa-tree">
			<div class="oa-tree-ctrl">
				<button type="button" class="btn btn-sm btn-light oa-tree-ctrl-m">Свернуть все</button>
				<button type="button" class="btn btn-sm btn-light oa-tree-ctrl-p">Развернуть все</button>
			</div>' . Main::buildTreeRadioCats($arr) . '</div>';
	wp_die();
}
add_action( 'wp_ajax_oasis_get_all_categories', 'oasis_get_all_categories' );


add_action( 'init', 'oasis_init_filter' );

function oasis_init_filter() {
	$cf = OasisConfig::instance([
		'init' => true
	]);

	if($cf->is_cdn_photo){
		add_filter( 'image_downsize', 'oasis_filter_image_downsize', 10, 3);

		function oasis_filter_image_downsize($downsize, $id, $size = 'medium') {
			if($downsize){
				return $downsize;
			}

			$post = get_post( $id );
			if (!$post || 'attachment' !== $post->post_type) {
				return false;
			}

			$oasis_id = Main::getOasisProductIdByPostId($post->post_parent);
			if(!$oasis_id){
				return false;
			}

			$imagedata = wp_get_attachment_metadata($id);
			if (!is_array($imagedata) || empty($imagedata['sizes'])) {
				return false;
			}

			if (is_array($size)) {
				$size_data = $imagedata['sizes']['medium'] ?? [];
			}
			else {
				$size_data = $imagedata['sizes'][$size] ?? $imagedata['sizes']['medium'] ?? [];
			}
			
			if(empty($size_data['cdn'])){
				return false;
			}

			if($size_data){
				return [
					$size_data['cdn'],
					$size_data['width'],
					$size_data['height'],
					0
				];
			}
			else {
				return [
					$imagedata['cdn'],
					$imagedata['width'],
					$imagedata['height'],
					0
				];
			}
			return false;
		}
	}
}