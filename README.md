# Oasiscatalog - Product Importer for WooCommerce

PHP version: 7.4+

WooCommerce version: 5.9+

WordPress version: 5.8+

Свободное пространство на диске не менее 18Gb

## Usage

Скачать архив wp-oasis-importer.zip из [свежего релиза](https://github.com/oasis-catalog/wp-oasis-importer/releases), установить и активировать плагин на вашем сайте в разделе: http://site.com/wp-admin/plugin-install.php

Перейдите на страницу настроек модуля «WooCommerce» -> «Импорт Oasis» и укажите действующий API ключ и User ID из [личного кабинета oasiscatalog](https://www.oasiscatalog.com/cabinet/integrations) и сохраните настройки модуля. 

Рекомендуем указывать лимит 5000. 

В панели управления хостингом добавить crontab задачи со страницы настроек модуля

**Возможные ошибки на хостинге Timeweb:**

```libgomp: Thread creation failed: Resource temporarily unavailable```

Для исправления необходимо привести команду запуска к такому виду:

```
env MAGICK_THREAD_LIMIT=1 /opt/php74/bin/php /YOUR_PATH/public_html/wp-content/plugins/wp-oasis-importer/cli.php --key=YOUR_KEY
```

### Заказы

После установки плагина и указания USER ID на станице WooCommerce->Заказы у заказов появится кнопка «Выгрузить» при условии что в заказе только товары Оазиса. 

**Возможность выгрузить имеется только у пользователей с правами «Супер-Админ», «Админ» и «Менеджера магазина»** 

## Виджет нанесения

Для работы виджета необходимо на странице оформления заказа подготовить данные по товарам: 
```angular2html
<div class="js--oasis-branding-widget" data-product-id="00000000006" data-product-quantity="2"></div>
<input type="hidden" name="branding[items][00000000006]" value="2">

<div class="js--oasis-branding-widget" data-product-id="00000000018" data-product-quantity="5"></div>
<input type="hidden" name="branding[items][00000000018]" value="5">
...
```

Получить ID товара ```data-product-id="00000000006"``` можно в цикле с помощью метода ```get_product_id_oasis_by_cart_item( $cart_item );``` который принимает в качестве параметра ```$cart_item```
