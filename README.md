# Oasiscatalog - Product Importer for WooCommerce

PHP version: 7.4+

WooCommerce version: 5.9+

WordPress version: 5.8+

Свободное пространство на диске не менее 25Gb

## Установка

На вашем сайте в разделе установки плагинов http://site.com/wp-admin/plugin-install.php

Загрузка:
 - через поиск по названию Oasiscatalog Importer
 - скачать и установить вручную https://ru.wordpress.org/plugins/oasiscatalog-importer/
 - скачать архив oasiscatalog-importer.zip из [свежего релиза](https://github.com/oasis-catalog/wp-oasis-importer/releases) и установить вручную


Перейдите на страницу настроек модуля «WooCommerce» -> «Импорт Oasis» и укажите действующий API ключ и User ID из [личного кабинета oasiscatalog](https://www.oasiscatalog.com/cabinet/integrations) и сохраните настройки модуля. 

Рекомендуем указывать лимит 5000. 

### Настройка запуска

Процесс импорта товаров запускается в фоновом режиме.

Доступно 2 режима работы:

 - с помощью инструментов Wordpress, настройте расписание
 - с помощью планировщика задач (требуется WP-CLI), в панели управления хостингом

### Заказы

После установки плагина и указания USER ID на станице WooCommerce->Заказы у заказов появится кнопка «Выгрузить» при условии что в заказе только товары Оазиса. 

**Возможность выгрузить имеется только у пользователей с правами «Супер-Админ», «Админ» и «Менеджера магазина»**

## Часто задаваемыми вопросы и подсказки по настройкам

[FAQ.md](./FAQ.MD)

## Куда можно сообщить об ошибках?

Сообщайте об ошибках или пожеланиях тут https://github.com/oasis-catalog/wp-oasis-importer/issues

## Плагин содержит/включает
1. **Bootstrap**, Version: 5.3.3, License: MIT, [https://getbootstrap.com/](https://getbootstrap.com/)
2. **Oasis-catalog/branding-widget**, Version: 1.3.0, License: ISC, [https://www.npmjs.com/package/@oasis-catalog/branding-widget/](https://www.npmjs.com/package/@oasis-catalog/branding-widget/)