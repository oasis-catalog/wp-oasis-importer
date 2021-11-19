<?php
/*
Plugin Name: Oasiscatalog - Product Importer
Plugin URI: https://forum.oasiscatalog.com
Description: Импорт товаров из каталога oasiscatalog.com в Woocommerce
Version: 2.0.1
Author: Oasiscatalog Team
Author URI: https://forum.oasiscatalog.com
License: GPL2

WordPress tested:   5.8
Woocommerce tested: 5.9
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OASIS_MI_PATH', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/src/Controller/Oasis.php';

use OasisImport\Controller\Oasis\Oasis;

/**
 * Проверка на наличие включенного Woocommerce при активации плагина
 */
register_activation_hook( __FILE__, 'oasis_mi_activate' );

function oasis_mi_activate() {
	if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) and current_user_can( 'activate_plugins' ) ) {
		wp_die( 'Плагин Oasiscatalog - Product Importer не может работать без Woocommerce <br><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Вернуться на страницу плагинов</a>' );
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	include( ABSPATH . "wp-includes/pluggable.php" );
}

$oasis = new Oasis();

define( 'OASIS_MI_API_VALIDATE', (bool) Oasis::getCurrenciesOasis() );

/**
 * custom option and settings
 */
function oasis_mi_settings_init() {
	if ( ! is_php_version_compatible( '7.3' ) ) {
		wp_die( 'Вы используете старую версию PHP ' . phpversion() . '. Попросите администратора сервера её обновить до 7.3 или выше! <br><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Вернуться на страницу плагинов</a>' );
	}

	register_setting( 'oasis_mi', 'oasis_mi_options' );

	add_settings_section(
		'oasis_mi_section_developers',
		'Настройка импорта моделей Oasis',
		null,
		'oasis_mi'
	);

	add_settings_field(
		'oasis_mi_api_key',
		'Ключ API',
		'oasis_mi_api_key_cb',
		'oasis_mi',
		'oasis_mi_section_developers',
		[
			'label_for' => 'oasis_mi_api_key',
		]
	);

	add_settings_field(
		'oasis_mi_api_user_id',
		'API User ID',
		'oasis_mi_api_user_id_cb',
		'oasis_mi',
		'oasis_mi_section_developers',
		[
			'label_for' => 'oasis_mi_api_user_id',
		]
	);

	$options = get_option( 'oasis_mi_options' );

	if ( empty( $options['oasis_mi_api_key'] ) ) {
		?>
        <div class="notice notice-error">
            <p><strong>Укажите API ключ!</strong></p>
        </div>
		<?php
	} elseif ( OASIS_MI_API_VALIDATE ) {
		add_settings_field(
			'oasis_mi_currency',
			'Валюта',
			'oasis_mi_currency_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_currency',
			]
		);

		add_settings_field(
			'oasis_mi_categories',
			'Категории',
			'oasis_mi_categories_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_categories',
			]
		);

		add_settings_field(
			'oasis_mi_no_vat',
			'Без НДС',
			'oasis_mi_checbox_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_no_vat',
			]
		);

		add_settings_field(
			'oasis_mi_not_on_order',
			'Под заказ',
			'oasis_mi_checbox_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_not_on_order',
			]
		);

		add_settings_field(
			'oasis_mi_price_from',
			'Цена от',
			'oasis_mi_price_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_price_from',
			]
		);

		add_settings_field(
			'oasis_mi_price_to',
			'Цена до',
			'oasis_mi_price_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_price_to',
			]
		);

		add_settings_field(
			'oasis_mi_rating',
			'Тип',
			'oasis_mi_rating_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_rating',
			]
		);

		add_settings_field(
			'oasis_mi_warehouse_moscow',
			'На складе в Москве',
			'oasis_mi_checbox_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_warehouse_moscow',
			]
		);

		add_settings_field(
			'oasis_mi_warehouse_europe',
			'На складе в Европе',
			'oasis_mi_checbox_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_warehouse_europe',
			]
		);

		add_settings_field(
			'oasis_mi_remote_warehouse',
			'На удаленном складе',
			'oasis_mi_checbox_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_remote_warehouse',
			]
		);

		add_settings_field(
			'oasis_mi_category_map',
			'Сопоставления категорий',
			'oasis_mi_category_map_cb',
			'oasis_mi',
			'oasis_mi_section_developers',
			[
				'label_for' => 'oasis_mi_category_map',
			]
		);
	}
}

function oasis_mi_api_key_cb( $args ) {
	$options = get_option( 'oasis_mi_options' );
	?>

    <input type="text" name="oasis_mi_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
           value="<?php echo $options[ $args['label_for'] ] ?? ''; ?>"
           maxlength="255" style="width: 300px;"/>

    <p class="description">После указания ключа можно будет настроить импорт товаров в Woocommerce с сайта Oasis</p>
	<?php
}

function oasis_mi_api_user_id_cb( $args ) {
	$options = get_option( 'oasis_mi_options' );
	?>

    <input type="text" name="oasis_mi_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
           value="<?php echo $options[ $args['label_for'] ] ?? ''; ?>"
           maxlength="255" style="width: 120px;"/>

    <p class="description">После указания user id можно будет выгружать заказы в Oasis</p>
	<?php
}

function oasis_mi_categories_cb( $args ) {
	$options    = get_option( 'oasis_mi_options' );
	$categories = Oasis::getOasisMainCategories();

	foreach ( $categories as $key => $value ) {
        ?>

        <input name="oasis_mi_options[<?php echo esc_attr( $args['label_for'] ); ?>][<?php echo $key; ?>]"
               type="checkbox"<?php echo checked( 1, $options[ $args['label_for'] ][ $key ], false ); ?> value="1"
               class="code" id="<?php echo esc_attr( $args['label_for'] . '-' . $key ); ?>"/>
        <label for="<?php echo esc_attr( $args['label_for'] . '-' . $key ); ?>" class="option"><?php echo $value; ?></label><br/>
        <?php
	}
}

function oasis_mi_currency_cb( $args ) {
	$options         = get_option( 'oasis_mi_options' );
	$defaultCurrency = $options['oasis_mi_currency'] ?? 'rub';
	?>

    <select name="oasis_mi_options[<?php echo esc_attr( $args['label_for'] ); ?>]" id="input-currency" class="form-control col-sm-6">
		<?php
		$currencies = Oasis::getCurrenciesOasis();

		foreach ( $currencies as $currency ) {
			$selected = '';
			if ( $currency->code === $defaultCurrency ) {
				$selected = ' selected="selected"';
			}
			echo '<option value="' . $currency->code . '"' . $selected . '>' . $currency->full_name . '</option>' . PHP_EOL;
		}
		unset( $currency );
		?>
    </select>
	<?php
}

function oasis_mi_checbox_cb( $args ) {
	$options = get_option( 'oasis_mi_options' );
	$checked = $options[ $args['label_for'] ];
	?>
    <input name="oasis_mi_options[<?php echo esc_attr( $args['label_for'] ); ?>]" type="checkbox"<?php echo checked( 1, $checked, false ); ?> value="1"
           class="code"/>
	<?php
}

function oasis_mi_price_cb( $args ) {
	$options = get_option( 'oasis_mi_options' );
	?>

    <input type="number" name="oasis_mi_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
           value="<?php echo $options[ $args['label_for'] ] ?? ''; ?>"
           maxlength="255" style="width: 120px;"/>
	<?php
}

function oasis_mi_rating_cb( $args ) {
	$options = get_option( 'oasis_mi_options' );
	?>

    <select name="oasis_mi_options[<?php echo esc_attr( $args['label_for'] ); ?>]" id="input-rating" class="form-control col-sm-6">
        <option value="">---Выбрать---</option>
        <option value="1" <?php selected( $options[ $args['label_for'] ], 1 ); ?>>Только новинки</option>
        <option value="2" <?php selected( $options[ $args['label_for'] ], 2 ); ?>>Только хиты</option>
        <option value="3" <?php selected( $options[ $args['label_for'] ], 3 ); ?>>Только со скидкой</option>
    </select>
	<?php
}

function oasis_mi_category_map_cb( $args ) {
	$options         = get_option( 'oasis_mi_options' );
	$oasisCategories = get_oasis_categories( $options['oasis_mi_api_key'] );

	echo '<table class="wp-list-table widefat fixed striped tags ui-sortable">';
	oasis_mi_recursive_category( 0, 1, $args, $options, $oasisCategories );
	echo '</table>';
}

function oasis_mi_recursive_category( $parent_id, $level, $args, $options, $oasisCategories ) {
	$wp_categories = get_categories( [
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'parent'     => $parent_id,
		'orderby'    => 'name',
	] );
	foreach ( $wp_categories as $wp_category ) {
		echo '<tr><td style="padding-left: ' . ( $level * 10 ) . 'px;">' . ( $level > 1 ? '- ' : '' ) . $wp_category->name . ' (#' . $wp_category->term_id . ')</td><td><select name="oasis_mi_options[' . esc_attr( $args['label_for'] ) . '][' . $wp_category->term_id . ']">' .
		     get_oasis_categories_tree(
			     $oasisCategories,
			     0, 0,
			     ( $options[ $args['label_for'] ][ $wp_category->term_id ] ?? '' )
		     ) .
		     '</select></td></tr>';
		oasis_mi_recursive_category( $wp_category->term_id, $level + 1, $args, $options, $oasisCategories );
	}
}

function get_oasis_categories( $key ) {
	$result = [];

	$availableRoots = [
		2891 => 'Продукция',
	];

	$data = json_decode( file_get_contents( 'https://api.oasiscatalog.com/v4/categories?format=json&fields=id,parent_id,root,name&key=' . $key ),
		true );

	if ( $data ) {
		foreach ( $data as $row ) {
			if ( isset( $availableRoots[ $row['root'] ] ) && ! empty( $row['parent_id'] ) ) {
				$parent = (int) $row['parent_id'];
				if ( $parent == 2891 ) {
					$parent = 0;
				}
				$result[ $parent ][] = $row;
			}
		}
	}

	return $result;
}

function get_oasis_categories_tree( $tree, $parent_id, $level, $seleted ) {
	$result = '';
	if ( $level == 0 ) {
		$result .= '<option value="">Выберите рубрику Oasis</option>';
	}
	if ( isset( $tree[ $parent_id ] ) ) {
		foreach ( $tree[ $parent_id ] as $cat ) {
			$result .= '<option value="' . $cat['id'] . '" ' . ( $seleted == $cat['id'] ? 'selected' : '' ) . '>' . str_repeat( ' - ',
					$level ) . $cat['name'] . '</option>';
			$result .= get_oasis_categories_tree( $tree, $cat['id'], $level + 1, $seleted );
		}
	}

	return $result;
}

/**
 * register our wporg_settings_init to the admin_init action hook
 */
add_action( 'admin_init', 'oasis_mi_settings_init' );

/**
 * Добавление пункта меню в раздел Инструменты для настройки импорта
 */
if ( is_admin() ) {

	function oasis_mi_menu() {
		add_submenu_page(
			'tools.php',
			'Импорт Oasis',
			'Импорт Oasis',
			'manage_options',
			'oasiscatalog_mi',
			'oasis_mi_page_html'
		);
	}

	add_action( 'admin_menu', 'oasis_mi_menu' );

	function oasis_mi_page_html() {
		// check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'oasis_mi_messages', 'oasis_mi_message', 'Настройки сохранены', 'updated' );
		}

		// show error/update messages
		settings_errors( 'oasis_mi_messages' );

		$options = get_option( 'oasis_mi_options' );
		?>
        <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet"/>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>

        <div class="wrap">
            <h1><?= esc_html( 'Настройка импорта моделей Oasis' ); ?></h1>
			<?php if ( ! empty( $options['oasis_mi_api_key'] ) && OASIS_MI_API_VALIDATE ) { ?>
                <p>Для включения автоматического обновления каталога необходимо в панели управления хостингом добавить crontab задачи:<br/>
                    <strong>Не разглашайте эти данные!</strong></p>
                <p><code style="border: dashed 1px #333; border-radius: 4px; padding: 10px 20px;">php <?= OASIS_MI_PATH; ?>cron_import.php
                        --key=<?php echo md5( $options['oasis_mi_api_key'] ); ?></code> - загрузка/обновление товаров 1 раз в сутки</p><br/>
                <p><code style="border: dashed 1px #333; border-radius: 4px; padding: 10px 20px;">php <?= OASIS_MI_PATH; ?>cron_import.php
                        --key=<?php echo md5( $options['oasis_mi_api_key'] ); ?> --up</code> - обновление остатков 1 раз в 30 минут
                </p><br/>
				<?php
			} elseif ( ! empty( $options['oasis_mi_api_key'] ) && ! OASIS_MI_API_VALIDATE ) {
				?>
                <div class="notice notice-error">
                    <p><strong>Не корректный API ключ, для корректной работы укажите существующий API ключ</strong></p>
                </div>
				<?php
			}
			?>

            <form action="options.php" method="post" class="oasis-mi-form">
				<?php
				settings_fields( 'oasis_mi' );
				do_settings_sections( 'oasis_mi' );
				submit_button( 'Сохранить настроки' );
				?>
            </form>
        </div>
        <script>
            jQuery(document).ready(function () {
                jQuery('.oasis-mi-form select').select2();
            });
        </script>
		<?php
	}

	/**
	 * @param $actions
	 * @param $post
	 *
	 * @return mixed
	 */
	function oasis_mi_update_link( $actions, $post ) {
		if ( $post->post_type != 'product' ) {
			return $actions;
		}

		$post_status = ( $_REQUEST['post_status'] ?? false );
		if ( ! empty( $post_status ) ) {
			if ( $post_status == 'trash' ) {
				return $actions;
			}
		}

		$model_id = get_post_meta( $post->ID, 'model_id' );
		if ( empty( $model_id ) ) {
			return $actions;
		}

		$actions['oasis_update'] = '<span class="delete"><a href="' . wp_nonce_url( admin_url( 'edit.php?post_type=product&ids=' . $post->ID . '&action=oasis_update' ),
				'oasis_update_' . $post->ID ) . '" title="Обновить товар из Oasiscatalog" rel="permalink">Обновить модель Oasiscatalog</a></span>';

		return $actions;
	}

	add_filter( 'post_row_actions', 'oasis_mi_update_link', 10, 2 );
	add_filter( 'page_row_actions', 'oasis_mi_update_link', 10, 2 );

	/**
	 *
	 */
	function oasis_mi_update_action() {
		include_once( OASIS_MI_PATH . 'functions.php' );

		if ( empty( $_REQUEST['ids'] ) ) {
			wp_die( 'Не выбраны модели для обновления!' );
		}

		// Get the original page
		$id = isset( $_REQUEST['ids'] ) ? absint( $_REQUEST['ids'] ) : '';

		$options            = get_option( 'oasis_mi_options' );
		$api_key            = $options['oasis_mi_api_key'];
		$selectedCategories = array_filter( $options['oasis_mi_category_map'] );
		$oasisCategories    = get_oasis_categories( $api_key );

		$sku   = [];
		$sku[] = reset( get_post_meta( $id, '_sku' ) );

		$variations = get_posts( [ 'post_type' => 'product_variation', 'post_parent' => $id ] );
		if ( $variations ) {
			foreach ( $variations as $variation ) {
				$sku[] = reset( get_post_meta( $variation->ID, '_sku' ) );
			}
		}

		$params = [
			'format'   => 'json',
			'fieldset' => 'full',
			'articles' => implode( ",", $sku ),
			'no_vat'   => 0,
			'extend'   => 'is_visible',
			'key'      => $api_key,
		];

		$products = json_decode(
			file_get_contents( 'https://api.oasiscatalog.com/v4/products?' . http_build_query( $params ) ),
			true
		);

		$models = [];
		foreach ( $products as $product ) {
			$models[ $product['group_id'] ][ $product['id'] ] = $product;
		}

		ob_start();
		foreach ( $models as $model_id => $model ) {
			$selectedCategory = [];

			$firstProduct = reset( $model );
			foreach ( $selectedCategories as $k => $v ) {
				if ( in_array( $v, $firstProduct['categories_array'] ) || in_array( $v, $firstProduct['full_categories'] ) ) {
					$selectedCategory[] = $k;
				}
			}

			if ( empty( $selectedCategory ) ) {
				foreach ( $selectedCategories as $k => $v ) {
					$selectedCategory = array_merge( $selectedCategory,
						recursiveCheckCategories( $k, $v, $oasisCategories, $firstProduct['categories_array'] ) );
				}
			}
			upsert_model( $model_id, $model, $selectedCategory, true, true );
		}

		$result = ob_get_contents();

		add_option( 'oasis_mi_update_message', nl2br( $result ) );
		ob_end_clean();

		$post_type = 'product';
		$url       = add_query_arg( [
			'post_type' => $post_type,
		], 'edit.php' );
		wp_redirect( $url );
		exit();
	}

	add_action( 'admin_action_oasis_update', 'oasis_mi_update_action' );

	/**
	 *
	 */
	function oasis_mi_update_message() {
		$message = get_option( 'oasis_mi_update_message' );
		if ( $message ) {
			delete_option( 'oasis_mi_update_message' );
			?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?= $message; ?></strong></p>
            </div>
			<?php
		}
	}

	add_action( 'admin_notices', 'oasis_mi_update_message' );

	/**
	 * @param $actions
	 * @param $post
	 *
	 * @return mixed
	 */
	function oasis_mi_update_category_link( $actions, $term ) {
		if ( $term->taxonomy != 'product_cat' ) {
			return $actions;
		}

		$options = get_option( 'oasis_mi_options' );

		$selectedCategories = ! empty( $options['oasis_mi_category_map'] ) ? array_filter( $options['oasis_mi_category_map'] ) : [];

		if ( empty( $selectedCategories ) || ! isset( $selectedCategories[ $term->term_id ] ) ) {
			return $actions;
		}


		$actions['oasis_update_category'] = '<span class="delete"><a href="' . wp_nonce_url( admin_url( 'admin.php?tag_ID=' . $term->term_id . '&page=oasis-update-category' ),
				'oasis_update_category' . $term->term_id ) . '" title="Обновить товары из Oasiscatalog для этой категории" rel="permalink">Обновить категорию Oasiscatalog</a></span>';

		return $actions;
	}

	add_filter( 'product_cat_row_actions', 'oasis_mi_update_category_link', 10, 2 );

	/**
	 *
	 */
	function oasis_mi_oasis_update_category() {
		include_once( OASIS_MI_PATH . 'functions.php' );

		if ( empty( $_REQUEST['tag_ID'] ) ) {
			wp_die( 'Не выбрана категория для обновления!' );
		}

		$options            = get_option( 'oasis_mi_options' );
		$api_key            = $options['oasis_mi_api_key'];
		$selectedCategories = array_filter( $options['oasis_mi_category_map'] );

		$models = $oasisCategories = [];

		if ( $api_key && $selectedCategories ) {

			$oasisCategories = get_oasis_categories( $api_key );

			$oasisCategory = $selectedCategories[ $_REQUEST['tag_ID'] ];
			$params        = [
				'format'   => 'json',
				'fieldset' => 'full',
				'category' => $oasisCategory,
				'no_vat'   => 0,
				'extend'   => 'is_visible',
				'key'      => $api_key,
			];

			$products = json_decode(
				file_get_contents( 'https://api.oasiscatalog.com/v4/products?' . http_build_query( $params ) ),
				true
			);


			foreach ( $products as $product ) {
				$models[ $product['group_id'] ][ $product['id'] ] = $product;
			}
		}


		$tmpfname = tempnam( sys_get_temp_dir(), "cat" );
		file_put_contents( $tmpfname, json_encode( [ 'categories' => $oasisCategories, 'models' => $models ] ) );

		$term = get_term( $_REQUEST['tag_ID'], 'product_cat' );
		?>
        <div class="wrap">
            <h1><?= esc_html( 'Импорт товаров Oasis для категории "' . $term->name . '"' ); ?></h1>

            <div class="oasis-content">
                Начат процесс обновления категории...<br/>
            </div>
        </div>
        <script>
            var uniqname = '<?=$tmpfname;?>';
            jQuery(document).ready(function () {
                setTimeout(function () {
                    var step = 0;
                    var total = <?= count( $models );?>;

                    function getNextStep() {
                        jQuery.ajax({
                            type: "POST",
                            url: ajaxurl,
                            data: {'action': 'oasis_update_category_ajax', 'file': uniqname, 'step': step},
                            async: true
                        }).done(function (msg) {
                            if (step <= total) {
                                jQuery('.oasis-content').append(msg);
                                step = step + 1;
                                getNextStep();
                            }
                        });
                    }

                    getNextStep();

                }, 3000);
            });
        </script>
		<?php
	}

	add_action( 'admin_menu', 'action_oasis_update_category' );
	function action_oasis_update_category( $context ) {
		add_submenu_page(
			'tools.php',
			'oasis-update-category',
			'oasis-update-category',
			'manage_categories',
			'oasis-update-category',
			'oasis_mi_oasis_update_category'
		);
	}

	/**
	 *
	 */
	function oasis_mi_oasis_update_category_ajax() {
		include_once( OASIS_MI_PATH . 'functions.php' );

		if ( empty( $_REQUEST['file'] ) ) {
			wp_die( 'Не выбрана категория для обновления!' );
		}

		if ( substr_count( $_REQUEST['file'], sys_get_temp_dir() ) == 0 ) {
			wp_die( 'Не выбран файл для обновления!' );
		}

		$data = json_decode( file_get_contents( $_REQUEST['file'] ), true );

		$oasisCategories = $data['categories'];
		$models          = $data['models'];

		$options            = get_option( 'oasis_mi_options' );
		$selectedCategories = array_filter( $options['oasis_mi_category_map'] );

		$keys = array_keys( $models );

		if ( isset( $keys[ $_REQUEST['step'] ] ) ) {
			$model_id = $keys[ $_REQUEST['step'] ];
			$model    = $models[ $model_id ];

			ob_start();
			echo '[' . date( 'c' ) . '] Начало обработки модели ' . $model_id . PHP_EOL;
			$selectedCategory = [];

			$firstProduct = reset( $model );
			foreach ( $selectedCategories as $k => $v ) {
				if ( in_array( $v, $firstProduct['categories_array'] ) || in_array( $v, $firstProduct['full_categories'] ) ) {
					$selectedCategory[] = $k;
				}
			}
			if ( empty( $selectedCategory ) ) {
				foreach ( $selectedCategories as $k => $v ) {
					$selectedCategory = array_merge( $selectedCategory,
						recursiveCheckCategories( $k, $v, $oasisCategories, $firstProduct['categories_array'] ) );
				}
			}

			upsert_model( $model_id, $model, $selectedCategory, true );
		} else {
			echo 'Обновление завершено.' . PHP_EOL;
			unlink( $_REQUEST['file'] );
		}

		$result = ob_get_contents();
		ob_end_clean();

		echo nl2br( $result );
		exit();
	}

	add_action( 'wp_ajax_oasis_update_category_ajax', 'oasis_mi_oasis_update_category_ajax' );
}
