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

		// orders
		register_setting( 'oasis_mi_orders', 'oasis_mi_orders' );

		add_settings_section(
			'oasis_mi_section_orders',
			'',
			null,
			'oasis_mi_orders'
		);

		add_settings_field(
			'oasis_mi_orders',
			'Заказы',
			'oasis_mi_orders_cb',
			'oasis_mi_orders',
			'oasis_mi_section_orders',
			[
				'label_for' => 'oasis_mi_orders',
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

function oasis_mi_orders_cb( $args ) {
	echo '
<table class="wp-list-table widefat fixed striped table-view-list oasis-orders">
    <thead>
        <tr>
            <th class="manage-column">Заказ</th>
            <th class="manage-column">Дата</th>
            <th class="manage-column">Итого</th>
            <th class="manage-column export">Выгрузить</th>
        </tr>
    </thead>
    <tbody>' . PHP_EOL;

	oasis_mi_get_orders();

	echo '    </tbody>
</table>' . PHP_EOL;
}

function oasis_mi_get_orders() {
	$orders = wc_get_orders( [
		'limit'   => - 1,
		'orderby' => 'date',
		'order'   => 'DESC',
		'status'  => [ 'wc-processing', 'wc-pending', 'wc-on-hold', 'wc-completed' ],
	] );

	if ( $orders ) {
		foreach ( $orders as $order ) {
			$queueId = get_metadata( 'post', $order->get_order_number(), 'oasis_queue_id', true );

			if ( $queueId ) {
				$htmlExport = '';
				$dataOrder  = Oasis::getOrderByQueueId( $queueId );

				if ( isset( $dataOrder->state ) ) {
					if ( $dataOrder->state == 'created' ) {
						$htmlExport = '<div class="oasis-order__wrap"><div class="oasis-order oasis-order__success"><span class="dashicons dashicons-yes-alt"></span>' . $dataOrder->order->statusText . '. Заказ №' . $dataOrder->order->number . '</div></div>';
					} elseif ( $dataOrder->state == 'pending' ) {
						$htmlExport = '<div class="oasis-order__wrap"><div class="oasis-order oasis-order__warning"><span class="dashicons dashicons-warning"></span>Заказ обрабатывается в Oasiscatalog, ожидайте.</div></div>';
					} elseif ( $dataOrder->state == 'error' ) {
						$htmlExport = '<div class="oasis-order__wrap"><div class="oasis-order oasis-order__danger"><span class="dashicons dashicons-dismiss"></span>Ошибка, попробуйте еще раз.</div></div> <input type="submit" name="send_order" class="button send_order" value="Выгрузить" data-order-id="' . $order->get_order_number() . '">';
					}
				}
			} else {
				$htmlExport = '<input type="submit" name="send_order" class="button send_order" value="Выгрузить" data-order-id="' . $order->get_order_number() . '">';
			}

			echo '        <tr>
            <td><a href="' . $order->get_edit_order_url() . '">' . $order->get_order_number() . '</a></td>
            <td>' . date( 'Y-m-d H:i:s', strtotime( $order->get_date_created() ) ) . '</td>
            <td>' . $order->total . '</td>
            <td>' . $htmlExport . '</td>
        </tr>' . PHP_EOL;
		}
	} else {
		echo '
        <tr>
            <td colspan="5">Заказы не найдены</td>
        </tr>';
	}
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

	function oasis_mi_menu_orders() {
		$options = get_option( 'oasis_mi_options' );

		if ( ! empty( $options['oasis_mi_api_key'] ) && ! empty( $options['oasis_mi_api_user_id'] ) && OASIS_MI_API_VALIDATE ) {
			add_submenu_page(
				'tools.php',
				'Заказы Oasis',
				'Заказы Oasis',
				'manage_options',
				'oasiscatalog_mi_orders',
				'oasis_mi_orders_html'
			);
		}
	}

	add_action( 'admin_menu', 'oasis_mi_menu_orders' );

	function oasis_mi_orders_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_action( 'admin_print_footer_scripts', 'init_order_ajax', 99 );

		// show error/update messages
		settings_errors( 'oasis_mi_messages' );
		?>

        <style type="text/css">
            .widefat td, .widefat th {
                padding: 8px 10px;
            }

            table.oasis-orders th.export {
                width: 400px;
            }

            .oasis-order__wrap {
                padding: 0 10px 0 0;
                display: inline-block;
            }

            .oasis-order {
                border-radius: 3px;
                padding: 4px;
            }

            .oasis-order .dashicons {
                margin-right: 3px;
            }

            .oasis-order__success {
                border: solid 1px #d6e9c6;
                color: #3c763d;
                background-color: #dff0d8;
            }

            .oasis-order__warning {
                border: solid 1px #faebcc;
                color: #8a6d3b;
                background-color: #fcf8e3;
            }

            .oasis-order__danger {
                border: solid 1px #ebccd1;
                color: #a94442;
                background-color: #f2dede;
            }
        </style>
        <div class="wrap">
            <h1><?= esc_html( 'Экспорт заказов в oasiscatalog' ); ?></h1>
            <p>Экспортировать заказы возможно только со статусами: <b>«В ожидании оплаты», «Обработка», «На удержании», «Выполнен»</b></p>

            <form action="options.php" method="post" class="oasis-mi-orders-form">
				<?php
				settings_fields( 'oasis_mi_orders' );
				do_settings_sections( 'oasis_mi_orders' );
				?>
            </form>
        </div>
		<?php
	}

	function init_order_ajax() {
		?>
        <script>
            jQuery(function ($) {
                $('.send_order').click(function () {
                    var data = {
                        action: 'send_order',
                        order_id: this.getAttribute('data-order-id')
                    };
                    this.setAttribute("disabled", "disabled");

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: data,
                        success: function (data) {
                            setTimeout(function () {
                                location.reload();
                            }, 2 * 1000);
                        }
                    });
                    return false;
                });
            });
        </script>
		<?php
	}

	add_action( 'wp_ajax_send_order', 'send_order_ajax' );

	function send_order_ajax() {
		$order_id = strval( $_POST['order_id'] );
		$options  = get_option( 'oasis_mi_options' );

		if ( ! empty( $order_id ) ) {
			$apiKey = $options['oasis_mi_api_key'];
			$data   = [
				'userId' => $options['oasis_mi_api_user_id'],
			];
			if ( ! empty( $apiKey ) && ! empty( $data['userId'] ) ) {
				$order = wc_get_order( $order_id );

				foreach ( $order->get_items() as $item ) {
					if ( $item->get_variation_id() ) {
						$oasisProductId = get_metadata( 'post', $item->get_variation_id(), 'variation_id', true );
					} else {
						$oasisProductId = get_metadata( 'post', $item->get_product_id(), 'product_id', true );
					}
					$data['items'][] = [
						'productId' => $oasisProductId,
						'quantity'  => $item->get_quantity(),
					];

				}
				unset( $item );

				$request = Oasis::sendOrder( $apiKey, $data );

				if ( $request ) {
					update_metadata( 'post', $order_id, 'oasis_queue_id', $request->queueId );
				}
			}
		}
		wp_die();
	}

	function oasis_mi_page_html() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'oasis_mi_messages', 'oasis_mi_message', 'Настройки сохранены', 'updated' );
		}

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
