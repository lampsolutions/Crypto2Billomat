<?php

define('BASE_DIR', dirname(__DIR__));
define('TPL_DIR', BASE_DIR.'/templates/');

require BASE_DIR.'/lib/lib.php';
require BASE_DIR.'/guzzle.phar';
$config = include BASE_DIR.'/config/config.php';

$url = @$_SERVER['REDIRECT_URL'];
switch($url) {
    case (preg_match('~/pay/(.*)/~m', $url, $route_match) ? $url : !$url):
        try {
            list($route, $uuid) = $route_match;
            $uuid = filter_userinput($uuid);
            echo pay_uuid_handler($uuid);
        } catch (Exception $e) {}
        break;
    case '/pay/':
        try {
            $invoice_number = filter_userinput(@$_REQUEST['invoice_number']);
            $invoice_date = filter_userinput(@$_REQUEST['invoice_date']);
            $customer_number = filter_userinput(@$_REQUEST['customer_number']);
            echo pay_handler($invoice_number, $invoice_date, $customer_number);
        } catch (Exception $e) {}
        break;
    default:
        index_handler();
        break;
}

function pay_uuid_handler($uuid) {
    global $config;
    return get_template_response('pay.html', ['UUID' => $uuid, 'CRYPTOGATE_BASE_URI' => $config['cryptogate.base_uri']]);
}

function pay_handler($invoice_number, $invoice_date, $customer_number) {
    $payment = api_get_payment($invoice_number, $invoice_date, $customer_number);
    $payment_status = (string)@$payment['status'];

    switch($payment_status) {
        case 'PAID':
            return get_template_response('index.html', ['SUCCESS_MSG' => 'Die Rechnung wurde bereits beglichen.']);
            break;
        case 'PAYMENT_URL':
            header('Location: /pay/'.$payment['uuid'].'/');
            return '';
            break;
        default:
            return get_template_response('index.html', ['ERROR_MSG' => 'Bitte prüfe deine Eingaben.']);
            break;
    }
}

function index_handler() {
    print get_template_response('index.html', []);
}
