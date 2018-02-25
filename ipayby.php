<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/* без формы ввода данных о кредитной карте */
add_action( 'wpinv_ipayby_cc_form', '__return_false' );

/* без подписок */
add_filter( 'wpinv_ipayby_support_subscription', '__return_false' );


// admin ipay settings
function wpinv_gateway_settings_ipayby( $setting ) {
    $setting['ipayby_desc']['std'] = __( 'Оплата с использованием сервиса iPay.by', 'invoicing' );

    $setting['ipayby_baseurl'] = array(
            'type' => 'text',
            'id'   => 'ipayby_baseurl',
            'name' => __( 'Адрес сайта банка', 'invoicing' ),
            'desc' => __( 'Адрес точки входа API в формате http://address.com:port', 'invoicing' ),
            'std'  => 'https://test.bank.by:9443',
        );

    $setting['ipayby_merchusername'] = array(
            'type' => 'text',
            'id'   => 'ipayby_merch_username',
            'name' => __( 'Логин магазина', 'invoicing' ),
            'desc' => __( 'Логин магазина, полученный при подключении', 'invoicing' ),
            'std' => 'merchant_username',
        );

    $setting['ipayby_merchpassword'] = array(
        'type' => 'password',
        'id'   => 'ipayby_merch_password',
        'name' => __( 'Пароль магазина', 'invoicing' ),
        'desc' => __( 'Пароль магазина, полученный при подключении', 'invoicing' ),
        'std' => 'merchant_password',
    );
    $setting['ipayby_certfilename'] = array(
        'type' => 'text',
        'id'   => 'ipayby_certfilename',
        'name' => __( 'Файл сертификата', 'invoicing' ),
        'desc' => __( 'Расположение файла с сертификатом *.crt', 'invoicing' ),
        'size' => 'large',
        'std' => '/home/etc/merchant.cert',
    );
    $setting['ipayby_pkfilename'] = array(
        'type' => 'text',
        'id'   => 'ipayby_pkfilename',
        'name' => __( 'Файл личного ключа', 'invoicing' ),
        'desc' => __( 'Расположение файла с личным ключем *.pk', 'invoicing' ),
        'size' => 'large',
        'std' => '/home/etc/my_private_key.pk',
    );
    $setting['ipayby_pkpassword'] = array(
        'type' => 'password',
        'id'   => 'ipayby_pk_password',
        'name' => __( 'Пароль личного ключа', 'invoicing' ),
        'desc' => __( 'Пароль, которым зашифрован личный ключ', 'invoicing' ),
        'std' => 'private_key_pass',
    );

    $setting['ipay_ipn_url'] = array(
        'type' => 'ipn_url',
        'id'   => 'ipayby_ipn_url',
        'name' => __( 'Url для подтверждения платежа', 'invoicing' ),
        'std' => wpinv_get_ipn_url( 'ipayby' ),
        'desc' => 'Адрес для возврата после платежа',
        'size' => 'large',
        'readonly' => true
    );

    return $setting;
}
add_filter( 'wpinv_gateway_settings_ipayby', 'wpinv_gateway_settings_ipayby', 10, 1 );

/**
 * подготовка и отправка платежа в ipay.by
 *
 * @param array $purchase_data данные о платеже
 * @return void
 */
function wpinv_process_ipayby_payment( $purchase_data ) {
    if( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'wpi-gateway' ) ) {
        wp_die( __( 'Nonce verification has failed', 'invoicing' ), __( 'Error', 'invoicing' ), array( 'response' => 403 ) );
    }
    error_log('подготовка и отправка платежа в ipay.by - '. $purchase_data['invoice_id'] );
    /*
    * данные о заказе приходят в виде
    *
    $purchase_data = array(
        'invoice_id'= > invoice_id
        'items' => array of item IDs,
        'price' => total price of cart contents,
        'invoice_key' =>  // Random key
        'user_email' => $user_email,
        'date' => date('Y-m-d H:i:s'),
        'user_id' => $user_id,
        'post_data' => $_POST,
        'user_info' => array of user's information and used discount code
        'cart_details' => array of cart details,
        'gateway' => payment gateway,
    );
    */

    $invoice = wpinv_get_invoice( $purchase_data['invoice_id'] );
    // подготавливаем данные для оплаты
    if ( !empty( $invoice ) ) {
        $payment_data = array(
            'amount'        => $purchase_data['price']*100, // обязательно в копейках или центах
            'orderNumber'   => $purchase_data['invoice_id'],
            'invoiceKey'    => $purchase_data['invoice_key'],
            'transactionId' => $invoice->get_transaction_id(),
            'currency'      => wpinv_get_currency(), // BYN - 933
            'currencyCode'  => 933, // BYN - 933
            'returnUrl'		=> wpinv_get_ipn_url( 'ipayby' ) . '&invoiceKey=' . $purchase_data['invoice_key'],
            'description'	=> 'оплата счета №'.$invoice->number .' на сайте '. site_url(),
            'clientId'		=> $purchase_data['user_email'],
        );

        // если есть код транзакции, сразу переходим к оплате
        // неправильное поведение(возможно): при пустом поле транзакции возвращает invoice_id
        if (
            $payment_data['transactionId']
            && !$invoice->has_status(array('wpi-processed'))
            && $payment_data['transactionId'] !== $payment_data['orderNumber']
        ) {
            $baseUrl = 'https://mpi-test.bgpb.by'; // TODO: вынести в настройки,
            $formUrl = $baseUrl . '/payment/merchants/Testing/payment_ru.html?mdOrder='.$payment_data['transactionId'];
            wp_redirect($formUrl);
            exit;
        }

        if (!$invoice->has_status(array('wpi-pending', 'wpi-failed'))) {
            wpinv_record_gateway_error('Ошибка платежа ipay.by', sprintf( 'Ошибка статуса счета, нельзя оплатить счет со статусом : %s', '('. $invoice->get_status(false) . ') ' .  $invoice->get_status(true)), $invoice->ID );
            wpinv_insert_payment_note($invoice->ID, sprintf( 'Ошибка статуса счета, нельзя оплатить счет со статусом : %s', $invoice->get_status(true)));
            wpinv_send_to_failed_page();
        }
        // устанавливаем статус заказа "в обработке"
        wpinv_update_payment_status( $invoice->ID, 'wpi-processing' );
        // получаем ссылку на форму оплаты
        $bankResponseData = wpinv_ipayby_get_payment_data($payment_data);
        if($bankResponseData) {
            if(0 != $bankResponseData['errorCode']) {
                wpinv_record_gateway_error('Ошибка платежа ipay.by', sprintf( 'Ошибка системы ipay.by: %s', $bankResponseData['errorMessage']), $invoice->ID );
                wpinv_insert_payment_note($invoice->ID, sprintf( 'Ошибка системы ipay.by: %s-%s', $bankResponseData['errorCode'], $bankResponseData['errorMessage']));
                wpinv_update_payment_status( $invoice->ID, 'wpi-pending' );
                wpinv_send_to_failed_page();
            }
            // очищаем корзину
            wpinv_empty_cart();
            // сохраняем код транзакции
            wpinv_set_payment_transaction_id( $invoice->ID, $bankResponseData['orderId'] );
            // переходим к оплате
            wp_redirect($bankResponseData['formUrl']);
            exit;
        } else {
            // не удалось получить адрес формы оплаты
            wpinv_record_gateway_error('Ошибка платежа ipay.by', sprintf( 'Данные платежа %s', json_encode( $payment_data ) ), $invoice->ID );
            wpinv_insert_payment_note($invoice->ID, 'Не удалось получить адрес формы оплаты. Подробнсти см. в логе ошибок');
            wpinv_update_payment_status( $invoice->ID, 'wpi-pending' );
            wpinv_send_to_failed_page();
        }
    } else {
        // счет не сушествует
        wpinv_record_gateway_error( __( 'Payment Error', 'invoicing' ), sprintf( __( 'Payment creation failed. Payment data: %s', 'invoicing' ), json_encode( $payment_data ) ), $invoice );
        // If errors are present, send the user back to the purchase page so they can be corrected
        wpinv_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['wpi-gateway'] );
    }
}
add_action( 'wpinv_gateway_ipayby', 'wpinv_process_ipayby_payment' );

/**
 * Получение от банка данных для начала оплаты
 *
 * @param array $payment_data
 * @return array|false false in failed
 */
function wpinv_ipayby_get_payment_data($payment_data)
{
    $amount = $payment_data['amount'];
    $orderNo = $payment_data['invoiceKey'];
    $description = urlencode($payment_data['description']);
    $curr =  $payment_data['currencyCode'];
    $returnUrl = urlencode($payment_data['returnUrl']);
    $bankUsername = wpinv_get_option( 'ipayby_merch_username', false );
    $bankSecret =   wpinv_get_option( 'ipayby_merch_password', false );
    $baseUrl = wpinv_get_option( 'ipayby_baseurl', false );      // 'https://mpi.test.by:9443';
    $url = "{$baseUrl}/payment/rest/register.do?amount={$amount}&currency={$curr}&language=ru&orderNumber={$orderNo}&returnUrl={$returnUrl}&userName={$bankUsername}&password={$bankSecret}&description={$description}";
error_log('wpinv_ipayby_get_payment_data bank request -- '.$url);
    $response = wpinv_ipayby_http_get_ssl($url); // wp_remote_get($url);
error_log('wpinv_ipayby_get_payment_data bank response -- ' . $response);
    if (!$response) {
        wpinv_record_gateway_error('Ошибка платежа ipay.by', 'Ошибка связи с сервером', $orderNo );
        //error_log('wpinv_ipayby_get_payment_data bank response error -- ' . curl_error($curl));
        return false;
    }
    $responseData = json_decode($response, true);
    if (!$responseData) {
        wpinv_record_gateway_error('Ошибка платежа ipay.by', 'Ошибка обработки данных с сервера', $orderNo );
        return false;
    }
    return $responseData;
}

/**
 * обработчик ответа из банка
 *
 * @return void
 */
function wpinv_ipayby_process_ipn()
{
    $request = wpinv_get_post_data();
error_log('wpinv_ipayby_process_ipn --'.json_encode($request));
    $invoice_id = wpinv_get_invoice_id_by_key( $request['invoiceKey'] );
    // проверяем наличие счета
    $invoice = wpinv_get_invoice( $invoice_id);
    if(empty($invoice)){
error_log('ipn: invoice not found ');
        wpinv_record_gateway_error( 'Ошибка обработки платежа ipay.by', sprintf( 'Счет не найден. Данные запроса: %s', json_encode( $request ) ));
        wpinv_send_to_failed_page();
    }
    // сверяем номер банковской транзакции
    $orderId = $invoice->transaction_id;
    if($orderId !== $request['orderId']){
error_log('ipn: transations not equal '. $orderId . '<>'. $request['orderId']);
        wpinv_record_gateway_error( 'Ошибка обработки платежа ipay.by', sprintf( 'не совпадают коды транзакций %s<>%s', $orderId, $request['orderId']));
        wpinv_insert_payment_note($invoice->ID, 'Ошибка обработки платежа ipay.by, не совпадают коды транзакций. Подробности см. в логе ошибок');
        wpinv_send_to_failed_page();
    }

    // проверяем полученные данные, делая запрос в банк
    $responseData = wpinv_ipayby_check_order($request);
    if (!$responseData) {
        error_log('ipn: check order status error ');
        wpinv_record_gateway_error( 'Ошибка проверки платежа ipay.by', '');
        wpinv_insert_payment_note($invoice->ID, 'Ошибка проверки платежа ipay.by. Подробности см. в логе ошибок');
        wpinv_send_to_failed_page();
    }

    wpinv_insert_payment_note($invoice->ID, 'Данные проверки оплаты: '. json_encode($responseData));
    if (0 != $responseData['ErrorCode']) {
        wpinv_update_payment_status( $invoice->ID, 'wpi-failed' );
        wpinv_insert_payment_note($invoice->ID, sprintf( 'Ошибка проверки платежа ipay.by %s-%s', $responseData['ErrorCode'], $responseData['ErrorMessage']));
        wpinv_send_to_failed_page();
    }
    // наконец-то успех
    wpinv_update_payment_status( $invoice->ID, 'publish' );
    wpinv_send_to_success_page(array( 'invoice_key' => $invoice->get_key() ) );
}
add_action( 'wpinv_verify_ipayby_ipn', 'wpinv_ipayby_process_ipn' );

/**
 * Проверяет данные платежа, делая запрос к серверу банка
 *
 * @param array $payment_data данные из банка
 * @return array|false массив данных с ответом из банка или false в случае ошибки
 */
function wpinv_ipayby_check_order($payment_data)
{
error_log(__METHOD__);
    $bankUsername = wpinv_get_option( 'ipayby_merch_username', false );
    $bankSecret =   wpinv_get_option( 'ipayby_merch_password', false );

    $orderId = $payment_data['orderId']; // transaction_id

    $baseUrl = wpinv_get_option( 'ipayby_baseurl', false );
    $url = "{$baseUrl}/payment/rest/getOrderStatus.do?orderId={$orderId}&password={$bankSecret}&userName={$bankUsername}";
error_log('call to url  -- ' . $url);
    $response = wpinv_ipayby_http_get_ssl($url);
    error_log('response -- ' . $response);
    if (!$response) {
        wpinv_record_gateway_error('Ошибка проверки платежа ipay.by', 'Ошибка связи с сервером', $orderNo );
error_log('response error -- ' . curl_error($curl));
        return false;
    }
    $responseData = json_decode($response, true);
    if (!$responseData) {
        wpinv_record_gateway_error('Ошибка проверки платежа ipay.by', 'Ошибка обработки данных с сервера', $orderNo );
        return false;
    }
    return $responseData;
}

/**
 * get запрос к серверу банка с использованием ssl
 *
 * @param string $url
 * @return string|false ответ из банка
 */
function wpinv_ipayby_http_get_ssl($url)
{
    // из настроек
    $sslCert = wpinv_get_option( 'ipayby_certfilename', false );
    $sslKey =  wpinv_get_option( 'ipayby_pkfilename', false );
    $sslPass = wpinv_get_option( 'ipayby_pk_password', false );

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    if ($sslCert) {
        curl_setopt($curl, CURLOPT_SSLCERT, $sslCert);
    }
    if ($sslKey) {
        curl_setopt($curl, CURLOPT_SSLKEY, $sslKey);
        curl_setopt($curl, CURLOPT_SSLKEYPASSWD, $sslPass);
    }
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($curl);
    if (false === $result) {
        error_log(__METHOD__);
        error_log('curl error:' . curl_error($curl));
        error_log('curl request: ' . $url);
    }
    return $result;
}

