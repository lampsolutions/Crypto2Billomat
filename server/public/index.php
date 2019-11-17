<?php

define('CALLBACK_BASE_URI', $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST']);
define('BASE_DIR', dirname(__DIR__));
require BASE_DIR.'/lib/lib.php';
require BASE_DIR.'/guzzle.phar';
require BASE_DIR.'/lib/phpmailer/Exception.php';
require BASE_DIR.'/lib/phpmailer/OAuth.php';
require BASE_DIR.'/lib/phpmailer/POP3.php';
require BASE_DIR.'/lib/phpmailer/SMTP.php';
require BASE_DIR.'/lib/phpmailer/PHPMailer.php';
$config = include BASE_DIR.'/config/config.php';

if($_REQUEST['api_key'] !== $config['api_key']) die(json_response(['status' => 'AUTH_FAILED']));

switch(@$_SERVER['REDIRECT_URL']) {
    case '/pay/':
        try {
            $invoice_number = trim(@$_REQUEST['invoice_number']);
            $customer_number = trim(@$_REQUEST['customer_number']);
            $invoice_date = trim(@$_REQUEST['invoice_date']);
            echo payment_handler($customer_number, $invoice_number, $invoice_date);
        } catch (Exception $e) {
            echo json_response(['error' => $e->getMessage(), 'status' => 'EXCEPTION']);
        }
        break;
    case '/callback/':
        try {
            $body = file_get_contents('php://input');
            $cryptopanel_invoice = json_decode($body);
            $invoice_number = trim(@$_REQUEST['invoice_number']);
            $invoice_date = trim(@$_REQUEST['invoice_date']);
            $uuid_verify = trim(@$_REQUEST['uuid']);
            $status_verify = trim(@$_REQUEST['status']);
            $token_verify = trim(@$_REQUEST['token']);
            echo callback_handler($invoice_number, $invoice_date, $uuid_verify, $status_verify, $token_verify, $cryptopanel_invoice);
        } catch (Exception $e) {
            echo json_response(['error' => $e, 'status' => 'EXCEPTION']);
        }
        break;
    default:
        die();
        break;
}

function payment_handler($customer_number, $invoice_number, $invoice_date) {
    global $config;

    if(empty($customer_number)||empty($invoice_number)||empty($invoice_date)) return json_response(['status' => 'ERR']);

    $invoice = billomat_invoice_get(
        $config['billomat.base_uri'],
        $config['billomat.api_key'],
        $invoice_number,
        $invoice_date
    );

    $customer = billomat_customer_get_by_client_number(
        $config['billomat.base_uri'],
        $config['billomat.api_key'],
        $customer_number
    );

    if(empty($invoice) || empty($customer)) return json_response(['status' => 'ERR']);
    if($invoice->client_id != $customer->id) return json_response(['status' => 'ERR']);
    if($invoice->status == 'PAID') return json_response(['status' => 'PAID']);



    $customer_attributes = billomat_customer_attributes(
        $config['billomat.base_uri'],
        $config['billomat.api_key'],
        $customer->id);

    $default_attributes = billomat_default_customer_attributes(
        $config['billomat.base_uri'],
        $config['billomat.api_key']
    );

    $total_amount = billomat_get_total_amount(
        $invoice,
        $default_attributes,
        $customer_attributes
    );


    $payment_url = cryptopanel_invoice_create(
        $config['cryptogate.base_uri'],
        $config['cryptogate.api_key'],
        $config['api_key'],
        $invoice,
        $invoice_date,
        $total_amount,
        $customer,
        $default_attributes,
        $customer_attributes,
        $config
    );

    if(!empty($payment_url)) {
        return json_response(['status' => 'PAYMENT_URL', 'url' => $payment_url]);
    }

    return json_response(['status' => 'ERR']);
}


function callback_handler($invoice_number, $invoice_date, $uuid_verify, $status_verify, $token_verify, $cryptopanel_invoice) {
    global $config;

    if(empty($cryptopanel_invoice)||empty($cryptopanel_invoice->uuid)) return json_response(['status' => 'NOK']);

    $token = cryptopanel_token_hash($invoice_number, $config['cryptogate.api_key'], (string)$cryptopanel_invoice->amount);

    if($token_verify!=$token) return json_response(['status' => 'NOK']);

    if(cryptopanel_invoice_verify($config['cryptogate.base_uri'], $config['cryptogate.api_key'], $cryptopanel_invoice, $uuid_verify, $status_verify, $token_verify)) {
        $invoice = billomat_invoice_get(
            $config['billomat.base_uri'],
            $config['billomat.api_key'],
            $invoice_number,
            $invoice_date
        );

        if($invoice->status == 'PAID') return json_response(['status' => 'ALREADY_PAID']);

        $customer = billomat_customer_get_by_client_id(
            $config['billomat.base_uri'],
            $config['billomat.api_key'],
            $invoice->client_id
        );

        $customer_attributes = billomat_customer_attributes(
            $config['billomat.base_uri'],
            $config['billomat.api_key'],
            $customer->id);

        $default_attributes = billomat_default_customer_attributes(
            $config['billomat.base_uri'],
            $config['billomat.api_key']
        );


        $crypto_discount = billomat_is_crypto_discount_valid(
            $invoice,
            $default_attributes,
            $customer_attributes
        );


        $paid_message = sprintf('Bezahlt mit Kryptowaehrung %s.', (string)$cryptopanel_invoice->invoice_payment->currency);
        if($crypto_discount) {
            list($crypto_discount_days, $crypto_discount_percent) = billomat_get_crypto_discount_values($default_attributes, $customer_attributes);
            $paid_message .= sprintf(' (SKONTO: %d%%)', $crypto_discount_percent);
        }


        billomat_invoice_payment_set(
            $config['billomat.base_uri'],
            $config['billomat.api_key'],
            $invoice->id,
            (string)$cryptopanel_invoice->amount,
            $paid_message
        );

        billomat_email_invoice(
            $config['billomat.base_uri'],
            $config['billomat.api_key'],
            $config['billomat.payment_email_template_id'],
            $invoice,
            $invoice_date,
            $config
        );

        return json_response(['status' => 'OK']);
    }

    return json_response(['status' => 'NOK']);
}