<?php

define('CALLBACK_BASE_URI', $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST']);
define('BASE_DIR', dirname(__DIR__));
require BASE_DIR.'/lib/lib.php';
require BASE_DIR.'/guzzle.phar';
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
            echo json_response(['status' => 'EXCEPTION']);
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
            echo json_response(['status' => 'EXCEPTION']);
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

    $payment_url = cryptopanel_invoice_create(
        $config['cryptogate.base_uri'],
        $config['cryptogate.api_key'],
        $config['api_key'],
        $invoice,
        $invoice_date
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

        billomat_invoice_payment_set(
            $config['billomat.base_uri'],
            $config['billomat.api_key'],
            $invoice->id,
            (string)$cryptopanel_invoice->amount
        );

        billomat_email_invoice(
            $config['billomat.base_uri'],
            $config['billomat.api_key'],
            $invoice
        );

        return json_response(['status' => 'OK']);
    }

    return json_response(['status' => 'NOK']);
}