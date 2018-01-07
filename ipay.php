<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/* без формы ввода данных о кредитной карте */
add_action( 'wpinv_ipay_cc_form', '__return_false' );

/* подготовка и отправка платежа в ipay.by */
function wpinv_process_ipay_payment( $purchase_data ) {
    if( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'wpi-gateway' ) ) {
        wp_die( __( 'Nonce verification has failed', 'invoicing' ), __( 'Error', 'invoicing' ), array( 'response' => 403 ) );
    }

    /*
    * данные о заказе приходят в виде
    *
    $purchase_data = array(
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
    
    // подготавливаем данные для оплаты
    $payment_data = array(
        'amount'         => $purchase_data['price']*100, // обязательно в копейках или центах
        'orderNumber'   => $purchase_data['invoice_key'],
        'currency'      => wpinv_get_currency(), // BYN - 933
		'returnUrl'		=> wpinv_get_ipn_url( 'ipay' ),
		'failUrl'		=> wpinv_get_ipn_url( 'ipay' ),
		'description'	=> '',
//		'language'		=> '',
		'clientId'		=> $purchase_data['user_email'], //
		'gateway'       => 'ipay',
        'status'        => 'wpi-pending'
    );

    // Record the pending payment
    $invoice = wpinv_get_invoice( $purchase_data['invoice_id'] );
    
    if ( !empty( $invoice ) ) {        
        wpinv_set_payment_transaction_id( $invoice->ID, $invoice->generate_key() );
        wpinv_update_payment_status( $invoice, 'publish' );
        
        // Empty the shopping cart
        wpinv_empty_cart();
        
        wpinv_send_to_success_page( array( 'invoice_key' => $invoice->get_key() ) );
    } else {
        wpinv_record_gateway_error( __( 'Payment Error', 'invoicing' ), sprintf( __( 'Payment creation failed while processing a manual (free or test) purchase. Payment data: %s', 'invoicing' ), json_encode( $payment_data ) ), $invoice );
        // If errors are present, send the user back to the purchase page so they can be corrected
        wpinv_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['wpi-gateway'] );
    }
}
add_action( 'wpinv_gateway_ipay', 'wpinv_process_ipay_payment' );

// admin ipay settings
function wpinv_gateway_settings_ipay( $setting ) {
    $setting['ipay_desc']['std'] = __( 'Оплата с использованием сервиса iPay.by', 'invoicing' );
    
    $setting['ipay_sandbox'] = array(
            'type' => 'checkbox',
            'id'   => 'ipay_sandbox',
            'name' => __( 'Тестовый режим', 'invoicing' ),
            'desc' => __( 'Включает тестовый режим работы', 'invoicing' ),
            'std'  => 1,
        );
        
    $setting['ipay_username'] = array(
            'type' => 'text',
            'id'   => 'ipay_username',
            'name' => __( 'Логин магазина', 'invoicing' ),
            'desc' => __( 'Логин магазина, полученный при подключении', 'invoicing' ),
            'std' => 'username',
        );
    
    $setting['ipay_password'] = array(
            'type' => 'password',
            'id'   => 'ipay_password',
            'name' => __( 'Пароль магазина', 'invoicing' ),
            'desc' => __( 'Пароль магазина, полученный при подключении', 'invoicing' ),
            'std' => '12345',
        );
/*    
    $setting['ipay_ipn_url'] = array(
            'type' => 'ipn_url',
            'id'   => 'ipay_ipn_url',
            'name' => __( 'ipay Success Callback Url', 'invoicing' ),
            'std' => wpinv_get_ipn_url( 'ipay' ),
            'desc' => wp_sprintf( __( 'Login to your Worldpay Merchant Interface then enable Payment Response & Shopper Response. Next, go to the Payment Response URL field and type "%s" or "%s" for a dynamic payment response.', 'invoicing' ), '<font style="color:#000;font-style:normal">' . wpinv_get_ipn_url( 'worldpay' ) . '</font>', '<font style="color:#000;font-style:normal">&lt;wpdisplay item=MC_callback&gt;</font>' ),
            'size' => 'large',
            'custom' => 'worldpay',
            'readonly' => true
        );
*/        
    return $setting;
}
add_filter( 'wpinv_gateway_settings_ipay', 'wpinv_gateway_settings_ipay', 10, 1 );
