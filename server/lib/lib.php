<?php

function cryptopanel_invoice_verify($cryptogate_base_uri, $cryptogate_api_key, $cryptopanel_invoice, $uuid_verify, $status_verify, $token_verify) {
    $client = new \GuzzleHttp\Client([
        'base_uri' => $cryptogate_base_uri,
        'timeout'  => 10.0,
    ]);

    $reponse = $client->post(
        '/api/paymentform/verify',
        [
            'query' => [
                'api_key' => $cryptogate_api_key
            ],
            'form_params' => [
                'uuid' => $uuid_verify,
                'token' => $token_verify,
                'status' => $status_verify,
            ]
        ]
    );
    $res = json_decode($reponse->getBody()->getContents());

    if($token_verify == $res->token) {
        return true;
    }

    return false;
}


function cryptopanel_token_hash($invoice_number, $cryptogate_api_key, $amount) {
    return hash('sha256', $invoice_number.$cryptogate_api_key.number_format($amount));
}

function billomat_get_total_amount($billomat_invoice) {
    $discount_until = strtotime('+' . $billomat_invoice->discount_days . 'days', strtotime($billomat_invoice->date));

    if(time() > $discount_until) {
        return $billomat_invoice->total_gross_unreduced;
    }

    return $billomat_invoice->total_gross_unreduced - $billomat_invoice->discount_amount;
}

function cryptopanel_invoice_create($cryptogate_base_uri, $cryptogate_api_key, $local_api_key, $billomat_invoice, $billomat_invoice_date) {
    $client = new \GuzzleHttp\Client([
        'base_uri' => $cryptogate_base_uri,
        'timeout'  => 10.0,
    ]);

    $amount = billomat_get_total_amount($billomat_invoice);

    $token = cryptopanel_token_hash($billomat_invoice->invoice_number, $cryptogate_api_key, $amount);

    $reponse = $client->post(
        '/api/paymentform/create',
        [
            'query' => [
                'api_key' => $cryptogate_api_key
            ],
            'form_params' => [
                'amount' => $amount,
                'currency' => $billomat_invoice->currency_code,
                'memo' => $billomat_invoice->invoice_number,
                'seller_name' => 'Demo',
                'first_name' => $billomat_invoice->invoice_number,
                'last_name' => $billomat_invoice_date,
                'email' => '',
                'token' => $token,
                'return_url' => '',
                'callback_url' => CALLBACK_BASE_URI."/callback/?api_key=$local_api_key&invoice_number=$billomat_invoice->invoice_number&invoice_date=$billomat_invoice_date",
            ]
        ]
    );

    $resp = json_decode($reponse->getBody()->getContents());

    if($resp && $resp->payment_url) {
        return $resp->payment_url;
    }

    return false;
}

function billomat_email_invoice($billoamat_base_uri, $billomat_api_key, $billomat_invoice) {
    $customer = billomat_customer_get_by_client_id($billoamat_base_uri, $billomat_api_key, $billomat_invoice->client_id);

    $client = new \GuzzleHttp\Client([
        'base_uri' => $billoamat_base_uri,
        'timeout'  => 20.0,
    ]);

    $k = 'email';
    $invoiceEmail = new stdClass();
    $invoiceEmail->$k = new stdClass();
    $invoiceEmail->$k->recipients->to = $customer->email;

    if(!$customer || empty($customer->email)) {
        return false;
    }

    $client->post(
        '/api/invoices/'.$billomat_invoice->id.'/email',
        [
            'query' => [
                'format' => 'json',
                'api_key' => $billomat_api_key
            ],
            'body' => getXMLEncode($invoiceEmail),
            'headers' => ['Content-Type' => 'application/xml']
        ]
    );

    return true;
}

function billomat_customer_get_by_client_id($billoamat_base_uri, $billomat_api_key, $client_id) {

    $client = new \GuzzleHttp\Client([
        'base_uri' => $billoamat_base_uri,
        'timeout'  => 5.0,
    ]);

    $reponse = $client->get(
        '/api/clients/'.(int)$client_id,
        [
            'query' => [
                'format' => 'json',
                'api_key' => $billomat_api_key
            ],
        ]
    );

    $client = @json_decode($reponse->getBody()->getContents());
    if($client->client) {
        return $client->client;
    }

    return false;
}

function billomat_customer_get_by_client_number($billoamat_base_uri, $billomat_api_key, $client_number) {

    $client = new \GuzzleHttp\Client([
        'base_uri' => $billoamat_base_uri,
        'timeout'  => 5.0,
    ]);

    $reponse = $client->get(
        '/api/clients',
        [
            'query' => [
                'client_number' => $client_number,
                'format' => 'json',
                'api_key' => $billomat_api_key
            ],
        ]
    );

    $clients = @json_decode($reponse->getBody()->getContents());
    if($clients->clients->client) {
        return $clients->clients->client;
    }

    return false;
}

function billomat_invoice_get($billoamat_base_uri, $billomat_api_key, $invoice_number, $invoice_date) {

    $client = new \GuzzleHttp\Client([
        'base_uri' => $billoamat_base_uri,
        'timeout'  => 2.0,
    ]);

    $reponse = $client->get(
        '/api/invoices',
        [
            'query' => [
                'invoice_number' => $invoice_number,
                'from' => $invoice_date,
                'to' => $invoice_date,
                'format' => 'json',
                'api_key' => $billomat_api_key
            ],
        ]
    );

    $invoices = @json_decode($reponse->getBody()->getContents());
    if($invoices->invoices->invoice) {
        return $invoices->invoices->invoice;
    }

    return false;
}


function billomat_invoice_payment_set($billoamat_base_uri, $billomat_api_key, $invoice_id, $paid_amount) {

    $client = new \GuzzleHttp\Client([
        'base_uri' => $billoamat_base_uri,
        'timeout'  => 10.0,
    ]);

    $k = 'invoice-payment';
    $invoicePayment = new stdClass();
    $invoicePayment->$k = new stdClass();
    $invoicePayment->$k->amount = (float)$paid_amount;
    $invoicePayment->$k->invoice_id = $invoice_id;
    $invoicePayment->$k->type = 'MISC';
    $invoicePayment->$k->comment = 'Bezahlt mit Kryptozahlung';
    $invoicePayment->$k->mark_invoice_as_paid = 1;

    $response = $client->post(
        '/api/invoice-payments',
        [
            'query' => [
                'format' => 'json',
                'api_key' => $billomat_api_key
            ],
            'body' => getXMLEncode($invoicePayment),
            'headers' => ['Content-Type' => 'application/xml']
        ]
    );

    $respData = @json_decode($response->getBody()->getContents());

    return $respData;
}


function getXMLEncode($obj, $level = 1, $xml = NULL) {
    if(!$obj) {
        return FALSE;
    }

    $node = NULL;

    if($level==1) {
        $xml .= '<?xml version="1.0" encoding="ISO-8859-1"?>'."\n";
    }

    if(is_array($obj) || is_object($obj)) {

        foreach ($obj as $key => $value) {
            $key = strtolower($key);

            if($level>1) {
                $node = $xml;
            }
            $xml .= sprintf(str_repeat("\t", $level).'<%s>', $key);
            if (is_array($value) || is_object($value)) {
                $xml .= getXMLEncode($value, $level+1);
            } else {
                if (trim($value) != NULL) {
                    if (htmlspecialchars($value) != $value) {
                        $xml .= str_repeat("\t",$level)."<![CDATA[$value]]>\n";
                    } else {
                        $xml .= str_repeat("\t",$level)."$value\n";
                    }
                }
            }

            $xml .= sprintf(str_repeat("\t",$level).'</%s>', $key);
        }
        return $xml;
    } else {
        return (string)$obj;
    }
}

function json_response($response) {
    header('Content-Type: application/json');
    return json_encode($response);
}