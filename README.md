# wp-invoicing-gateway-ipay-by
gateway для подключения платежного сервиса IPAY.BY к плагину wpinvoicing
<https://github.com/AyeCode/invoicing>

## Использование
скопировать в wp-content/plugins/invoicing/includes/gateways

## добавить флюз в плагин
wp-content\invoicing\includes\wpinv-gateway-functions.php
function wpinv_get_payment_gateways()
перед `'manual' => array(` вставить
```php
        'ipayby' => array(
            'admin_label'    => __( 'IPay.by', 'invoicing' ),
            'checkout_label' => __( 'IPay.by', 'invoicing' ),
            'ordering'       => 12,
        ),
```