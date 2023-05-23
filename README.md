# Oasiscatalog - Product Importer for WooCommerce

PHP version: 7.3+

WooCommerce version: 5.9+

WordPress version: 5.8+

Свободное пространство на диске не менее 13Gb

## Usage

Скачать архив wp-oasis-importer.zip из [свежего релиза](https://github.com/oasis-catalog/wp-oasis-importer/releases), установить и активировать плагин на вашем сайте в разделе: http://site.com/wp-admin/plugin-install.php

Перейдите на страницу настроек модуля «Инструменты» -> «Импорт Oasis» и укажите действующий API ключ и User ID из [личного кабинета oasiscatalog](https://www.oasiscatalog.com/cabinet/integrations) и сохраните настройки модуля. 

Рекомендуем указывать лимит 5000. 

В панели управления хостингом добавить crontab задачи со страницы настроек модуля

**Возможные ошибки на хостинге Timeweb:**

```libgomp: Thread creation failed: Resource temporarily unavailable```

Для исправления необходимо привести команду запуска к такому виду:

```
env MAGICK_THREAD_LIMIT=1 /opt/php74/bin/php /YOUR_PATH/public_html/wp-content/plugins/wp-oasis-importer/cron_import.php --key=YOUR_KEY
```

