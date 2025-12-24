<?php
/*
Plugin Name: Oasiscatalog Importer
Plugin URI: https://www.oasiscatalog.com
Description: Import products from the oasiscatalog.com catalog to WooCommerce. Upload orders from WooCommerce to oasiscatalog. Application editing widget.
Version: 3.0.0
Text Domain: oasiscatalog-importer
License: GPL2

WordPress tested:   6.9
Woocommerce tested: 6.9
*/

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/src/lib/Config.php';
require_once __DIR__ . '/src/lib/Main.php';
require_once __DIR__ . '/src/lib/Cli.php';
require_once __DIR__ . '/src/lib/Api.php';
require_once __DIR__ . '/src/order.php';
require_once __DIR__ . '/src/branding.php';

use OasiscatalogImporter\Api;
use OasiscatalogImporter\CLI;
use OasiscatalogImporter\Main;
use OasiscatalogImporter\Config as OasisConfig;


register_activation_hook( __FILE__, 'oasis_import_activate' );
function oasis_import_activate() {
	if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) && current_user_can( 'activate_plugins' ) ) {
		wp_die( 'Плагин Oasiscatalog - Product Importer не может работать без Woocommerce <br><a href="' . esc_attr(admin_url('plugins.php')) . '">&laquo; Вернуться на страницу плагинов</a>' );
	}

	$cf = OasisConfig::instance();
	$cf->activate();
}

register_deactivation_hook( __FILE__, 'oasis_import_deactivate' );
function oasis_import_deactivate() {
	$cf = OasisConfig::instance();
	$cf->deactivate();

	wp_unschedule_hook('oasis_import_schedule_run');
	wp_unschedule_hook('oasis_import_schedule_run_stock');
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	include( ABSPATH . "wp-includes/pluggable.php" );
}

/**
 * custom option and settings
 */
function oasis_import_settings_init() {

	register_setting( 'oasis-import', 'oasis_import_options', 'oasis_import_sanitize_data' );

	add_settings_section(
		'oasis-import-run',
		esc_attr__( 'Launch settings', 'oasiscatalog-importer' ),
		function () {
			$timeRun = wp_next_scheduled('oasis_import_schedule_run');
			$timeRunStock = wp_next_scheduled('oasis_import_schedule_run_stock');
			if (empty($timeRun) || empty($timeRunStock)) {
				?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e('The product import process runs in the background.', 'oasiscatalog-importer'); ?></p>
					<p><?php esc_html_e('You can adjust the time using WordPress tools.', 'oasiscatalog-importer'); ?></p><br>
					<p><?php esc_html_e('Or add the command to your hosting task scheduler.', 'oasiscatalog-importer'); ?></p>
					<p><?php esc_html_e('This requires WP-CLI. Example for crontab, please ensure the path is correct', 'oasiscatalog-importer'); ?> <strong>/usr/local/bin/wp</strong>:</p>
					<div class="row">
						<div class="col-md-4 col-sm-12">
							<p><?php esc_html_e( 'Download / update products 1 time per day', 'oasiscatalog-importer' ); ?></p>
						</div>
						<div class="col-md-8 col-sm-12">
							<div class="input-group input-group-sm">
								<input type="text" class="form-control input-cron-task" value="0 0 * * * /usr/local/bin/wp oasis_import run --path=<?php echo esc_attr(ABSPATH); ?>" id="inputImport" readonly="readonly" onFocus="this.select()">
								<div class="btn btn-primary" id="copyImport" data-bs-toggle="tooltip" data-bs-title="<?php esc_html_e( 'Copy', 'oasiscatalog-importer' ); ?>">
									<div class="oasis-icon-copy"></div>
								</div>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-4 col-sm-12">
							<p><?php esc_html_e( 'Renewal of balances 1 time in 2 hours', 'oasiscatalog-importer' ); ?></p>
						</div>
						<div class="col-md-8 col-sm-12">
							<div class="input-group input-group-sm">
								<input type="text" class="form-control input-cron-task" value="0 0/2 * * * /usr/local/bin/wp oasis_import up --path=<?php echo esc_attr(ABSPATH); ?>" id="inputUp" readonly="readonly" onFocus="this.select()">
								<div class="btn btn-primary" id="copyUp" data-bs-toggle="tooltip" data-bs-title="<?php esc_html_e( 'Copy', 'oasiscatalog-importer' ); ?>">
									<div class="oasis-icon-copy"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php
			}

			if ($timeRun) {
				echo '<div class="notice notice-info inline">'
					. '<p><strong>' . esc_attr__('Next run of product imports:', 'oasiscatalog-importer') . '</strong> ' . wp_date('Y-m-d H:i', $timeRun). '</p>'
					. '</div>';
			}
			if ($timeRunStock) {
				echo '<div class="notice notice-info inline">'
					. '<p><strong>' . esc_attr__('Next run of stock update:', 'oasiscatalog-importer') . '</strong> ' . wp_date('Y-m-d H:i', $timeRunStock). '</p>'
					. '</div>';
			}
		},
		'oasis-import'
	);

	$options = get_option('oasis_import_options', []);

	add_settings_field(
		'run_type',
		esc_attr__( 'Schedule of imports of products', 'oasiscatalog-importer' ),
		function() use($options) {
			?>
			<select name="oasis_import_options[run_type]" class="form-select col-sm-6">
				<option value=""><?php esc_html_e( '---Off---', 'oasiscatalog-importer' ); ?></option>
				<option value="1" <?php selected( $options['run_type'], 1 ); ?>><?php esc_html_e( 'Every day', 'oasiscatalog-importer' ); ?></option>
				<option value="2" <?php selected( $options['run_type'], 2 ); ?>><?php esc_html_e( 'Once every two days', 'oasiscatalog-importer' ); ?></option>
				<option value="3" <?php selected( $options['run_type'], 3 ); ?>><?php esc_html_e( 'Once every three days', 'oasiscatalog-importer' ); ?></option>
				<option value="4" <?php selected( $options['run_type'], 4 ); ?>><?php esc_html_e( 'Once a week', 'oasiscatalog-importer' ); ?></option>
			</select>
			<?php
		},
		'oasis-import',
		'oasis-import-run',
	);
	add_settings_field(
		'run_time',
		esc_attr__( 'Schedule of imports of products', 'oasiscatalog-importer' ),
		function() use($options) {
			?>
			<input type="time" value="<?php echo esc_attr($options['run_time']) ?>" name="oasis_import_options[run_time]">
			<?php
		},
		'oasis-import',
		'oasis-import-run',
	);

	add_settings_field(
		'run_stock_type',
		esc_attr__( 'Schedule for updating stock', 'oasiscatalog-importer' ),
		function() use($options) {
			?>
			<select name="oasis_import_options[run_stock_type]" class="form-select col-sm-6">
				<option value=""><?php esc_html_e( '---Off---', 'oasiscatalog-importer' ); ?></option>
				<option value="1" <?php selected( $options['run_stock_type'], 1 ); ?>><?php esc_html_e( 'Every hour', 'oasiscatalog-importer' ); ?></option>
				<option value="2" <?php selected( $options['run_stock_type'], 2 ); ?>><?php esc_html_e( 'Every 2 hours', 'oasiscatalog-importer' ); ?></option>
				<option value="3" <?php selected( $options['run_stock_type'], 3 ); ?>><?php esc_html_e( 'Every 3 hours', 'oasiscatalog-importer' ); ?></option>
				<option value="4" <?php selected( $options['run_stock_type'], 4 ); ?>><?php esc_html_e( 'Every 6 hours', 'oasiscatalog-importer' ); ?></option>
				<option value="5" <?php selected( $options['run_stock_type'], 5 ); ?>><?php esc_html_e( 'Every 12 hours', 'oasiscatalog-importer' ); ?></option>
			</select>
			<?php
		},
		'oasis-import',
		'oasis-import-run'
	);


	$cf = OasisConfig::instance([
		'init' => true
	]);

	add_settings_section(
		'oasis-import-setting',
		esc_attr__( 'Setting up import of Oasis products', 'oasiscatalog-importer' ),
		null,
		'oasis-import'
	);

	add_settings_field(
		'api_key',
		esc_attr__( 'Key API', 'oasiscatalog-importer' ),
		function() use($cf) {
			echo '<input type="text" name="oasis_import_options[api_key]" value="' . esc_attr($cf->api_key) . '" maxlength="255" style="width: 300px;"/>
				<p class="description">' . esc_html__( 'After specifying the key, it will be possible to configure the import of products into Woocommerce from the Oasis website', 'oasiscatalog-importer' ) . '</p>';
		},
		'oasis-import',
		'oasis-import-setting'
	);

	add_settings_field(
		'api_user_id',
		esc_attr__( 'API User ID', 'oasiscatalog-importer' ),
		function() use($cf) {
			echo '<input type="text" name="oasis_import_options[api_user_id]" value="' . esc_attr($cf->api_user_id) . '" maxlength="255" style="width: 120px;"/>
				<p class="description">' . esc_html__( 'After specifying the user id, it will be possible to upload orders to Oasis', 'oasiscatalog-importer' ) . '</p>';
		},
		'oasis-import',
		'oasis-import-setting'
	);

	if (empty($cf->api_key)) {
		add_settings_error( 'oasis_import_messages', 'oasis-import-msg', esc_attr__( 'Specify the API key!', 'oasiscatalog-importer' ) );
	} elseif (!$cf->loadCurrencies()) {
		add_settings_error( 'oasis_import_messages', 'oasis-import-msg', esc_attr__( 'API key is invalid', 'oasiscatalog-importer' ) );
	} else {
		$cf->initRelation();

		add_settings_field(
			'currency',
			esc_attr__( 'Currency', 'oasiscatalog-importer' ),
			function() use($cf) {
				echo '<select name="oasis_import_options[currency]" id="input-currency" class="form-select">';
				foreach ($cf->currencies as $c) {
					echo '<option value="' . esc_attr($c['code']) . '" ' . ($c['code'] === $cf->currency ? 'selected="selected"' : '') . '>' . esc_attr($c['name']) . '</option>';
				}
				echo '</select>';
			},
			'oasis-import',
			'oasis-import-setting'
		);

		add_settings_field(
			'categories',
			esc_attr__( 'Categories', 'oasiscatalog-importer' ),
			function() use($cf) {
				$categories = Api::getCategoriesOasis();
				$arr_cat    = [];

				foreach ( $categories as $item ) {
					if ( empty( $arr_cat[ (int) $item->parent_id ] ) ) {
						$arr_cat[ (int) $item->parent_id ] = [];
					}
					$arr_cat[ (int) $item->parent_id ][] = (array) $item;
				}

				echo '<div id="tree" class="oa-tree">
						<div class="oa-tree-ctrl">
							<button type="button" class="btn btn-sm btn-light oa-tree-ctrl-m">' . esc_html__( 'Collapse all', 'oasiscatalog-importer' ) . '</button>
							<button type="button" class="btn btn-sm btn-light oa-tree-ctrl-p">' . esc_html__( 'Expand all', 'oasiscatalog-importer' ) . '</button>
						</div>' .
						wp_kses(Main::buildTreeCats($arr_cat, $cf->categories, $cf->categories_rel), [
							'div' => [
								'class'   => true,
							],
							'span' => [
								'class'   => true,
							],
							'label' => [],
							'input' => [
								'class'   => true,
								'type'    => true,
								'value'   => true,
								'name'    => true,
								'checked' => true,
							],
						])
					. '</div>';
					
			},
			'oasis-import',
			'oasis-import-setting'
		);

		add_settings_field(
			'category_rel',
			esc_attr__( 'Default category', 'oasiscatalog-importer' ),
			function() use($cf) {
				echo '<div id="cf_opt_category_rel">
					<input type="hidden" value="' . esc_attr($cf->category_rel) . '" name="oasis_import_options[category_rel]">
					<div class="oa-category-rel">' . esc_html($cf->category_rel_label) . '</div></div>
					<p class="description">' . esc_html__( 'If no link is specified for a product category, the product will be placed in the default category', 'oasiscatalog-importer' ) . '</p>';
			},
			'oasis-import',
			'oasis-import-setting',
			[
				'label_for' => 'category_rel',
			]
		);

		add_settings_field(
			'is_not_up_cat',
			esc_attr__( 'Do not update categories', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_not_up_cat', $cf->is_not_up_cat),
			'oasis-import',
			'oasis-import-setting'
		);

		add_settings_field(
			'is_not_defect',
			esc_attr__( 'No defective goods', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_not_defect', $cf->is_not_defect),
			'oasis-import',
			'oasis-import-setting'
		);

		add_settings_field(
			'is_import_anytime',
			esc_attr__( 'Do not limit update', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_import_anytime', $cf->is_import_anytime, esc_attr__( 'Full product update is limited, no more than once a day', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-setting'
		);

		add_settings_field(
			'limit',
			esc_attr__( 'Limit products', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_number('limit', $cf->limit, 100, esc_attr__( 'The number of products received from the API and processed in one run.', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-setting'
		);
		add_settings_field(
			'is_no_vat',
			esc_attr__( 'No VAT', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_no_vat', $cf->is_no_vat),
			'oasis-import',
			'oasis-import-setting'
		);
		add_settings_field(
			'is_not_on_order',
			esc_attr__( 'Without goods "to order"', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_not_on_order', $cf->is_not_on_order),
			'oasis-import',
			'oasis-import-setting'
		);
		add_settings_field(
			'price_from',
			esc_attr__( 'Price from', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_number('price_from', $cf->price_from, 0.01),
			'oasis-import',
			'oasis-import-setting'
		);
		add_settings_field(
			'price_to',
			esc_attr__( 'Price to', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_number('price_to', $cf->price_to, 0.01),
			'oasis-import',
			'oasis-import-setting'
		);
		add_settings_field(
			'rating',
			esc_attr__( 'Type', 'oasiscatalog-importer' ),
			function() use($cf) {
				?>
				<select name="oasis_import_options[rating]" class="form-select col-sm-6">
					<option value=""><?php esc_html_e( '---Select---', 'oasiscatalog-importer' ); ?></option>
					<option value="1" <?php selected( $cf->rating, 1 ); ?>><?php esc_html_e( 'Only new items', 'oasiscatalog-importer' ); ?></option>
					<option value="2" <?php selected( $cf->rating, 2 ); ?>><?php esc_html_e( 'Only hits', 'oasiscatalog-importer' ); ?></option>
					<option value="3" <?php selected( $cf->rating, 3 ); ?>><?php esc_html_e( 'Discount only', 'oasiscatalog-importer' ); ?></option>
				</select>
				<?php
			},
			'oasis-import',
			'oasis-import-setting'
		);
		add_settings_field(
			'is_wh_moscow',
			esc_attr__( 'In stock in Moscow', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_wh_moscow', $cf->is_wh_moscow),
			'oasis-import',
			'oasis-import-setting'
		);
		add_settings_field(
			'is_wh_europe',
			esc_attr__( 'In stock in Europe', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_wh_europe', $cf->is_wh_europe),
			'oasis-import',
			'oasis-import-setting'
		);
		add_settings_field(
			'is_wh_remote',
			esc_attr__( 'At a remote warehouse', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_wh_remote', $cf->is_wh_remote),
			'oasis-import',
			'oasis-import-setting'
		);

		add_settings_section(
			'oasis-import-price',
			esc_attr__( 'Price settings', 'oasiscatalog-importer' ),
			null,
			'oasis-import'
		);
		add_settings_field(
			'price_factor',
			esc_attr__( 'Price factor', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_number('price_factor', $cf->price_factor, 0.01, esc_attr__( 'The price will be multiplied by this factor. For example, in order to increase the cost by 20%, you need to specify 1.2', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-price'
		);
		add_settings_field(
			'price_increase',
			esc_attr__( 'Add to price', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_number('price_increase', $cf->price_increase, 0.01, esc_attr__( 'This value will be added to the price. For example, if you specify 100, then 100 will be added to the cost of all products.', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-price'
		);
		add_settings_field(
			'is_price_dealer',
			esc_attr__( 'Use dealer prices', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_price_dealer', $cf->is_price_dealer),
			'oasis-import',
			'oasis-import-price'
		);

		add_settings_section(
			'oasis-import-extra',
			esc_attr__( 'Additional settings', 'oasiscatalog-importer' ),
			null,
			'oasis-import'
		);
		add_settings_field(
			'is_comments',
			esc_attr__( 'Enable reviews', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_comments', $cf->is_comments, esc_attr__( 'Enable commenting on imported products', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-extra'
		);
		add_settings_field(
			'is_brands',
			esc_attr__( 'Enable brands', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_brands', $cf->is_brands, esc_attr__( 'Enable brands on imported products', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-extra'
		);
		add_settings_field(
			'is_disable_sales',
			esc_attr__( 'Hide discounts', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_disable_sales', $cf->is_disable_sales, esc_attr__( 'Hide "old" price in products', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-extra'
		);
		add_settings_field(
			'is_branding',
			esc_attr__( 'Widget branding', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_branding', $cf->is_branding, esc_attr__( 'Enable branding widget on checkout page', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-extra'
		);
		add_settings_field(
			'is_up_photo',
			esc_attr__( 'Up photo', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_up_photo', $cf->is_up_photo, esc_attr__( 'Enable update photos', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-extra'
		);
		add_settings_field(
			'is_cdn_photo',
			esc_attr__( 'Use CDN image server', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_cdn_photo', $cf->is_cdn_photo, esc_attr__( 'Display product photos without uploading, saves space on hosting. May not work correctly with some themes and plugins', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-extra'
		);
		add_settings_field(
			'is_fast_import',
			esc_attr__( 'Quick import of products', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_fast_import', $cf->is_fast_import, esc_attr__( 'Import without photos. After a full upload of all products, the option is turned off', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-extra'
		);
		add_settings_field(
			'is_without_quotes',
			esc_attr__( 'Remove quotes', 'oasiscatalog-importer' ),
			fn() => oasis_import_sf_checbox('is_without_quotes', $cf->is_without_quotes, esc_attr__( 'Remove quotes in the title', 'oasiscatalog-importer' )),
			'oasis-import',
			'oasis-import-extra'
		);
	}
}


function oasis_import_sf_checbox($opt, $is, $description = '') {
	echo '<input type="checkbox" class="code" value="1" name="oasis_import_options[' . esc_attr($opt) . ']" ' . ($is ? 'checked' : '') . ' />';
	if(!empty($description))
		echo '<p class="description">' . esc_html($description) . '</p>';
}

function oasis_import_sf_number($opt, $v, $step, $description = '') {
	echo '<input type="number" maxlength="255" style="width: 120px;" name="oasis_import_options[' . esc_attr($opt) . ']" value="' . esc_attr($v ? $v : '') . '" step="' . esc_attr($step) . '" />';
	if(!empty($description))
		echo '<p class="description">' . esc_html($description) . '</p>';
}

function oasis_import_sanitize_data($options) {
	if (empty($options['api_key'])) {
		add_settings_error(
			'oasis_import_messages',
			'oasis-import-msg',
			esc_attr__('Specify the API key!', 'oasiscatalog-importer'),
			'warning'
		);

		return get_option('oasis_import_options');
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

		elseif ($name == 'run_time'){
			if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $val) !== 1) {
				$val = '01:00';
			}
		}
		elseif ($name == 'categories'){
			$categories = Api::getCategoriesOasis();
			$arr_cat	= [];

			foreach ($categories as $item) {
				$l = $item->level;
				if (empty($arr_cat[$l])) {
					$arr_cat[$l] = [];
				}
				if (empty($arr_cat[$l][$item->id])) {
					$arr_cat[$l][$item->id] = [];
				}
				if ($item->parent_id) {
					if (empty($arr_cat[$l][$item->parent_id])) {
						$arr_cat[$l][$item->parent_id] = [];
					}
					$arr_cat[$l][$item->parent_id][] = $item->id;
				}
			}
			ksort($arr_cat);
			while (true) {
				foreach (array_reverse($arr_cat) as $arr) {
					foreach ($arr as $id => $childs) {
						if (count($childs) > 0 && count(array_diff($childs, $val)) == 0){
							$val = array_diff($val, $childs);
							$val[] = $id;
							continue 3;
						}
					}
				}
				break;
			}

			$val = array_values(array_unique($val));
		}
	}

	if (!oasis_import_check_options($options, get_option('oasis_import_options'), ['run_type', 'run_time', 'run_stock_type'])) {
		wp_unschedule_hook('oasis_import_schedule_run');
		wp_unschedule_hook('oasis_import_schedule_run_stock');

		if (!empty($options['run_type'])) {
			$d = new \DateTime(date('Y-m-d') . ' ' . $options['run_time'], wp_timezone());
			switch ($options['run_type']) {
				case '2': $recurrence = 'oasis_import_every_2days'; break;
				case '3': $recurrence = 'oasis_import_every_3days'; break;
				case '4': $recurrence = 'oasis_import_every_7days'; break;
				case '1': 
				default:
					$recurrence = 'daily';
			}
			wp_schedule_event($d->getTimestamp(), $recurrence, 'oasis_import_schedule_run');
		}

		if (!empty($options['run_stock_type'])) {
			$minute = rand(0,59);
			$d = new \DateTime(date('Y-m-d') . ' 00:' . ($minute < 10 ? "0{$minute}" : $minute), wp_timezone());
			switch ($options['run_stock_type']) {
				case '2': $recurrence = 'oasis_import_every_2hours'; break;
				case '3': $recurrence = 'oasis_import_every_3hours'; break;
				case '4': $recurrence = 'oasis_import_every_6hours'; break;
				case '5': $recurrence = 'twicedaily'; break;
				case '1': 
				default:
					$recurrence = 'hourly';
			}
			wp_schedule_event($d->getTimestamp(), $recurrence, 'oasis_import_schedule_run_stock');
		}
	}

	$cf->progressClear();
	return $options;
}

function oasis_import_check_options ($arr1, $arr2, $keys) {
	foreach ($keys as $key) {
		if (($arr1[$key] ?? null) !== ($arr2[$key] ?? null)) {
			return false;
		}
	}
	return true;
}


if ( is_admin() ) {
	add_action( 'admin_menu', 'oasis_import_menu' );
	function oasis_import_menu() {
		$page = add_submenu_page(
			'woocommerce',
			esc_attr__( 'Oasis Import', 'oasiscatalog-importer' ),
			esc_attr__( 'Oasis Import', 'oasiscatalog-importer' ),
			'manage_options',
			'oasis-import',
			'oasis_import_page_html'
		);

		add_action( 'load-' . $page, 'oasis_import_admin_styles' );
		add_action( 'load-' . $page, 'oasis_import_admin_validations' );
		oasis_import_settings_init();
	}

	function oasis_import_admin_styles() {
		wp_enqueue_style('oasis-import-style', plugins_url('assets/css/style.css', __FILE__  ), [], OasisConfig::VERSION);
		wp_enqueue_style('oasis-import-bootstrap533', plugins_url('assets/css/bootstrap.min.css' , __FILE__ ), [], OasisConfig::VERSION);
		wp_enqueue_script('oasis-import-bootstrap533', plugins_url('assets/js/bootstrap.min.js', __FILE__ ), [], OasisConfig::VERSION, ['in_footer' => true]);
		wp_enqueue_script('oasis-import-tree', plugins_url('assets/js/tree.js', __FILE__ ), [ 'jquery' ], OasisConfig::VERSION, ['in_footer' => true]);
		wp_enqueue_script('oasis-import-custom', plugins_url('assets/js/custom.js', __FILE__ ), ['jquery', 'oasis-import-tree'], OasisConfig::VERSION, ['in_footer' => true]);
	}

	function oasis_import_admin_validations() {
		if ( ! is_php_version_compatible( '7.4' ) ) {
			wp_die( 'Вы используете старую версию PHP ' . esc_attr(phpversion()) . '. Попросите администратора сервера её обновить до 7.4 или выше! <br><a href="' . esc_attr(admin_url('plugins.php')) . '">&laquo; Вернуться на страницу плагинов</a>' );
		}
	}

	function oasis_import_page_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'oasis_import_messages', 'oasis-import-msg', esc_attr__( 'Settings saved', 'oasiscatalog-importer' ), 'updated' );
		}

		settings_errors( 'oasis_import_messages' );

		$cf = OasisConfig::instance([
			'init' => true
		]);
		$optBar = $cf->getOptBar();
		?>
		<div class="wrap">
			<div class="container-fluid">
				<?php if (!empty($cf->api_key)) {
					$progressClass = $optBar['is_process'] ? 'progress-bar progress-bar-striped progress-bar-animated' : 'progress-bar';
					?>
					<div class="row mb-2">
						<div class="oa-notice oa-notice-info">
							<div class="row">
								<div class="col-md-4 col-sm-12">
									<h5>
									<?php
										esc_html_e( 'General processing status', 'oasiscatalog-importer' );
										echo $optBar['is_process'] ? ' <span class="oasis-process-icon"><span class="oasis-icon-run" data-bs-toggle="tooltip" data-bs-title="' . esc_attr__( 'Loading...', 'oasiscatalog-importer' ) . '">'
																: ' <span class="oasis-process-icon"><span class="oasis-icon-pause"><span></span>';
									?>
									</h5>
								</div>
								<div class="col-md-8 col-sm-12">
									<div class="progress">
										<div id="upAjaxTotal" class="<?php echo esc_attr($progressClass); ?>" role="progressbar"
											aria-valuenow="<?php echo esc_attr($optBar['p_total']); ?>"
											aria-valuemin="0" aria-valuemax="100" style="width: <?php echo esc_attr($optBar['p_total']); ?>%">
											<?php echo esc_attr($optBar['p_total']); ?>%
										</div>
									</div>
								</div>
							</div>
							<?php if ($cf->limit > 0) { ?>
								<div class="row">
									<div class="col-md-4 col-sm-12">
										<h5>
											<span class="oasis-process-text">
												<?php
													if (!empty($optBar['steps'])) {
														if ($optBar['is_process']) {
															/* translators: status bar is process. 1: current step, 2: all steps */
															echo esc_html(sprintf(__('%1$s step in progress out of %2$s. Current step status', 'oasiscatalog-importer'), ($optBar['step'] + 1), $optBar['steps']));
														} else {
															/* translators: status bar, next step. 1: next step, 2: all steps */
															echo esc_html(sprintf(__('Next step %1$s of %2$s.', 'oasiscatalog-importer'), ($optBar['step'] + 1), $optBar['steps']));
														}
													}
												?>
											</span>
										</h5>
									</div>
									<div class="col-md-8 col-sm-12">
										<div class="progress">
											<div id="upAjaxStep" class="<?php echo esc_attr($progressClass); ?>" role="progressbar"
												aria-valuenow="<?php echo esc_attr($optBar['p_step']); ?>"
												aria-valuemin="0" aria-valuemax="100"
												style="width: <?php echo esc_attr($optBar['p_step']); ?>%"><?php echo esc_html($optBar['p_step']); ?>%
											</div>
										</div>
									</div>
								</div>
							<?php } ?>
							<p><?php 
								/* translators: date last import. 1: date */
								echo esc_html(sprintf(__('Last import completed: %1$s', 'oasiscatalog-importer' ), $optBar['date'] ?? ''));
							?></p>
						</div>
					</div>
				<?php } ?>
				<div class="row">
					<form action="options.php" method="post" class="oasis-form">
						<?php
						settings_fields( 'oasis-import' );
						do_settings_sections( 'oasis-import' );
						submit_button( esc_attr__( 'Save settings', 'oasiscatalog-importer' ) );
						?>
					</form>
				</div>
			</div>
			<div id="oasis-relation" class="modal fade" tabindex="-1" tabindex="-1" aria-modal="true" role="dialog">
				<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title"><?php esc_html_e( 'Categories', 'oasiscatalog-importer' ); ?></h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
						</div>
						<div class="modal-body"></div>
						<div class="modal-footer">
							<button type="button" class="btn btn-danger mx-3 js-clear"><?php esc_html_e( 'Clear', 'oasiscatalog-importer' ); ?></button>
							<button type="button" class="btn btn-primary js-ok"><?php esc_html_e( 'Select', 'oasiscatalog-importer' ); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	add_action( 'product_cat_edit_form_fields', 'oasis_import_cat_edit_term_fields', 10, 2 );

	function oasis_import_cat_edit_term_fields( $term, $taxonomy ) {
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

	add_action( 'created_product_cat', 'oasis_import_cat_save_term_fields' );
	add_action( 'edited_product_cat', 'oasis_import_cat_save_term_fields' );

	function oasis_import_cat_save_term_fields( $term_id ) {
		if ( isset( $_POST['oasis_cat_id'] ) ) {
			update_term_meta( $term_id, 'oasis_cat_id', sanitize_text_field( $_POST['oasis_cat_id'] ) );
		} else {
			delete_term_meta( $term_id, 'oasis_cat_id' );
		}
	}
}

add_filter('plugin_action_links', function ($links, $file) {
	if ($file != plugin_basename( __FILE__)) {
		return $links;
	}

	$settings_link = sprintf('<a href="%s">%s</a>', esc_attr(admin_url('admin.php?page=oasis-import' )), esc_attr__('Settings', 'oasiscatalog-importer'));
	array_unshift($links, $settings_link);

	return $links;
}, 10, 2 );

add_filter('cron_schedules', function ($schedules) {
	$schedules['oasis_import_every_2days'] = [
		'interval' => 172800, // 60×60×24×2
		'display'  => 'Once every two days'
	];
	$schedules['oasis_import_every_3days'] = [
		'interval' => 259200, // 60×60×24×3
		'display'  => 'Once every three days'
	];
	$schedules['oasis_import_every_7days'] = [
		'interval' => 604800, // 60×60×24×7
		'display'  => 'Once a week'
	];

	$schedules['oasis_import_every_2hours'] = [
		'interval' => 7200, // 60×60×2
		'display'  => 'Every 2 hour'
	];
	$schedules['oasis_import_every_3hours'] = [
		'interval' => 10800, // 60×60×3
		'display'  => 'Every 3 hours'
	];
	$schedules['oasis_import_every_6hours'] = [
		'interval' => 21600, // 60×60×6
		'display'  => 'Every 6 hours'
	];
	return $schedules;
});


add_action( 'wp_ajax_oasis_get_progress_bar', 'oasis_import_get_progress_bar' );
function oasis_import_get_progress_bar() {
	$cf = OasisConfig::instance([
		'init' => true
	]);
	$optBar = $cf->getOptBar();

	$step_text = '';
	if($optBar['steps']){
		if ($optBar['is_process']) {
			/* translators: status bar is process. 1: current step, 2: all steps */
			$step_text = sprintf( esc_html__( '%1$s step in progress out of %2$s. Current step status', 'oasiscatalog-importer' ), ($optBar['step'] + 1), $optBar['steps'] );
		} else {
			/* translators: status bar, next step. 1: next step, 2: all steps */
			$step_text = sprintf( esc_html__( 'Next step %1$s of %2$s.', 'oasiscatalog-importer' ), ($optBar['step'] + 1), $optBar['steps'] );
		}
	}

	echo wp_json_encode([
		'is_process' => $optBar['is_process'],
		'progress_icon'   => $optBar['is_process'] ? '<span class="oasis-process-icon"><span class="oasis-icon-run" data-bs-toggle="tooltip" data-bs-title="' . esc_html__( 'Loading...', 'oasiscatalog-importer' ) . '">' :
											'<span class="oasis-icon-pause"></span>',
		'p_total' => $optBar['p_total'],
		'p_step' => $optBar['p_step'],
		'step_text' => $step_text
	]);
	wp_die();
}

add_action( 'wp_ajax_oasis_get_all_categories', 'oasis_import_get_all_categories' );
function oasis_import_get_all_categories() {
	$categories = get_categories( [
		'taxonomy'   => 'product_cat',
		'hide_empty' => 0
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
				<button type="button" class="btn btn-sm btn-light oa-tree-ctrl-m">' . esc_html__( 'Collapse all', 'oasiscatalog-importer' ) . '</button>
				<button type="button" class="btn btn-sm btn-light oa-tree-ctrl-p">' . esc_html__( 'Expand all', 'oasiscatalog-importer' ) . '</button>
			</div>' .
			wp_kses(Main::buildTreeRadioCats($arr), [
				'div' => [
					'class'   => true,
				],
				'span' => [
					'class'   => true,
				],
				'label' => [],
				'input' => [
					'class'   => true,
					'type'    => true,
					'value'   => true,
					'name'    => true,
					'checked' => true,
				],
			])
		. '</div>';

	wp_die();
}


add_action('init', 'oasis_import_init_filter' );
function oasis_import_init_filter() {
	$cf = OasisConfig::instance([
		'init' => true
	]);

	if($cf->is_cdn_photo){
		add_filter('image_downsize', 'oasis_import_image_downsize', 10, 3);
		function oasis_import_image_downsize($downsize, $id, $size = 'medium') {
			if ($downsize) {
				return $downsize;
			}

			$post = get_post( $id );
			if (!$post || 'attachment' !== $post->post_type) {
				return false;
			}

			$oasis_id = Main::getOasisProductIdByPostId($post->post_parent);
			if (!$oasis_id) {
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
			
			if (empty($size_data['cdn'])) {
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

		add_filter( 'wp_get_attachment_url', 'oasis_import_get_attachment_url', 10, 2);
		function oasis_import_get_attachment_url($url, $id) {
			$post = get_post( $id );
			if (!$post || 'attachment' !== $post->post_type) {
				return $url;
			}

			$oasis_id = Main::getOasisProductIdByPostId($post->post_parent);
			if (!$oasis_id) {
				return $url;
			}

			$imagedata = wp_get_attachment_metadata($id);
			if (!is_array($imagedata) || empty($imagedata['sizes'])) {
				return $url;
			}
			$size_data = $imagedata['sizes']['medium'] ?? [];
			
			if (empty($size_data['cdn'])) {
				return $url;
			}
			return $size_data['cdn'];
		}
	}
}

add_action('oasis_import_schedule_run', function() {
	Cli::Run('run');
});
add_action('oasis_import_schedule_run_stock', function() {
	Cli::Run('up');
});

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_command('oasis_import', function ($args, $assoc_args) {
		$com = $args[0] ?? '';

		if (!in_array($com, ['run', 'up', 'up_image', 'add_image', 'repair_image'])) {
			WP_CLI::error("Unknown command: {$com}");
			return;
		}

		if (isset($assoc_args['site'])) {
			switch_to_blog($assoc_args['site']);
		}

		Cli::Run($com, [
			'info'     => isset($assoc_args['info']),
			'info_log' => isset($assoc_args['info_log']),
			'mode'     => 'WP_CLI',
			'oid'      => $assoc_args['oid'] ?? '',
			'sku'      => $assoc_args['sku'] ?? '',
		]);

		WP_CLI::success('end');
	});
}