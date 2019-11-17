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


function billomat_is_crypto_discount_valid($billomat_invoice, $default_attributes, $customer_attributes) {
    list($crypto_discount_days, $crypto_discount_percent) = billomat_get_crypto_discount_values($default_attributes, $customer_attributes);
    $crypto_discount_until = strtotime('+' . $crypto_discount_days . 'days', strtotime($billomat_invoice->date));

    if(!empty($crypto_discount_percent)) {
        if($crypto_discount_until > time()) {
            return true;
        }
    }

    return false;
}


function billomat_get_crypto_discount_values($default_attributes, $customer_attributes) {
    $crypto_discount_days = 0;
    $crypto_discount_percent = 0;

    foreach($default_attributes as $a) {
        if($a->name == 'CRYPTO_DISCOUNT_DAYS') $crypto_discount_days = intval($a->default_value);
        if($a->name == 'CRYPTO_DISCOUNT_PERCENTAGE') $crypto_discount_percent = intval($a->default_value);
    }

    foreach($customer_attributes as $a) {
        if($a->name == 'CRYPTO_DISCOUNT_DAYS' && !empty($a->value)) $crypto_discount_days = intval($a->value);
        if($a->name == 'CRYPTO_DISCOUNT_PERCENTAGE'  && !empty($a->value)) $crypto_discount_percent = intval($a->value);
    }

    return [$crypto_discount_days, $crypto_discount_percent];
}


function billomat_get_crypto_discount_amount($billomat_invoice, $default_attributes, $customer_attributes) {
    list($crypto_discount_days, $crypto_discount_percent) = billomat_get_crypto_discount_values($default_attributes, $customer_attributes);
    $crypto_discount_until = strtotime('+' . $crypto_discount_days . 'days', strtotime($billomat_invoice->date));

    if(!empty($crypto_discount_percent)) {
        if($crypto_discount_until > time()) {
            $amount = (float)$billomat_invoice->total_gross;
            $discount = (float) ($amount / 100) * $crypto_discount_percent;
            return (float) number_format( $discount, 2);
        }
    }

    return 0.00;
}


function billomat_get_total_amount($billomat_invoice, $default_attributes, $customer_attributes) {

    $discount_until = strtotime('+' . $billomat_invoice->discount_days . 'days', strtotime($billomat_invoice->date));

    $crypto_discount_amount = billomat_get_crypto_discount_amount($billomat_invoice, $default_attributes, $customer_attributes);

    if(!empty($crypto_discount_amount)) {
        if(billomat_is_crypto_discount_valid($billomat_invoice, $default_attributes, $customer_attributes)) {
            return (float) number_format( $billomat_invoice->total_gross - $crypto_discount_amount, 2);
        }
    }

    if($billomat_invoice->reminders){
        $total=$billomat_invoice->reminders->total_gross;
    }else{
        $total=$billomat_invoice->total_gross;
    }

    if(time() > $discount_until) {
        return $total;
    }


    return $total - $billomat_invoice->discount_amount;

}

function cryptopanel_invoice_create(
                    $cryptogate_base_uri,
                    $cryptogate_api_key,
                    $local_api_key,
                    $billomat_invoice,
                    $billomat_invoice_date,
                    $amount,
                    $customer,
                    $default_attributes,
                    $customer_attributes,
                    $config)
    {
    $client = new \GuzzleHttp\Client([
        'base_uri' => $cryptogate_base_uri,
        'timeout'  => 10.0,
    ]);

    $crypto_discount = billomat_is_crypto_discount_valid(
        $billomat_invoice,
        $default_attributes,
        $customer_attributes
    );

    $memo = $billomat_invoice->invoice_number;
    if($crypto_discount) {
        list($crypto_discount_days, $crypto_discount_percent) = billomat_get_crypto_discount_values($default_attributes, $customer_attributes);
        $memo .= sprintf(' (SKONTO: %d%%)', $crypto_discount_percent);
    }

    $token = cryptopanel_token_hash($billomat_invoice->invoice_number, $cryptogate_api_key, $amount);

    $note='';
    if($billomat_invoice->reminders){
        $note="ink. Mahngebühr: ".
            number_format(((float)$billomat_invoice->reminders->total_gross - (float)$billomat_invoice->total_gross),2,",",".")." EUR";
    }

    $reponse = $client->post(
        '/api/paymentform/create',
        [
            'query' => [
                'api_key' => $cryptogate_api_key
            ],
            'form_params' => [
                'amount' => $amount,
                'currency' => $billomat_invoice->currency_code,
                'memo' => $memo,
                'note' => $note,
                'seller_name' => $config["seller.Name"],
                'first_name' => $billomat_invoice->invoice_number,
                'last_name' => $customer->client_number,
                'email' => @$customer->email,
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

function billomat_email_invoice($billoamat_base_uri, $billomat_api_key, $billomat_template_id, $billomat_invoice, $invoice_date,$config) {
    $customer = billomat_customer_get_by_client_id($billoamat_base_uri, $billomat_api_key, $billomat_invoice->client_id);
    if(!$customer || empty($customer->email)) {
        return false;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer();
    $mail->isSMTP();
    $mail->SMTPDebug = 0;
    $mail->Host = $config["smtp.Host"];
    $mail->Port = $config["smtp.Port"];

    $mail->CharSet = 'utf-8';
    $mail->SetLanguage ("de");

    $mail->SMTPAuth = true;
    $mail->Username = $config["smtp.Username"];
    $mail->Password = $config["smtp.Password"];
    $mail->setFrom($config["smtp.From"], $config["smtp.FromNamw"]);
    $mail->addReplyTo($config["smtp.ReplyTo"], $config["smtp.ReplyToName"]);
    $mail->addAddress($customer->email, $customer->name);
    $mail->addBCC($config["smtp.BCC"], $config["smtp.BCCName"]);
    $mail->Subject = 'Zahlungseingang zur Rechnung '.$billomat_invoice->invoice_number.' am '.$invoice_date;
    $mail->Body = $config["smtp.Body"];

    $mail->send();

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

function billomat_default_customer_attributes($billoamat_base_uri, $billomat_api_key) {

    $client = new \GuzzleHttp\Client([
        'base_uri' => $billoamat_base_uri,
        'timeout'  => 5.0,
    ]);

    $reponse = $client->get(
        '/api/client-properties',
        [
            'query' => [
                'format' => 'json',
                'api_key' => $billomat_api_key
            ],
        ]
    );

    $attributes = @json_decode($reponse->getBody()->getContents());
    $km = 'client-properties';
    $ks = 'client-property';

    if($attributes->$km->$ks) {
        return $attributes->$km->$ks;
    }

    return false;
}

function billomat_customer_attributes($billoamat_base_uri, $billomat_api_key, $client_id) {

    $client = new \GuzzleHttp\Client([
        'base_uri' => $billoamat_base_uri,
        'timeout'  => 5.0,
    ]);

    $reponse = $client->get(
        '/api/client-property-values',
        [
            'query' => [
                'client_id' => $client_id,
                'format' => 'json',
                'api_key' => $billomat_api_key
            ],
        ]
    );

    $attributes = @json_decode($reponse->getBody()->getContents());
    $km = 'client-property-values';
    $ks = 'client-property-value';

    if($attributes->$km->$ks) {
        return $attributes->$km->$ks;
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

    $reminders=billomat_reminders_get($billoamat_base_uri, $billomat_api_key, $invoice_number);
    if(is_array($reminders)){
        $reminders=end($reminders);
    }

    if($reminders) {
        $invoices->invoices->invoice->reminders = $reminders;
    }


    if($invoices->invoices->invoice) {
        return $invoices->invoices->invoice;
    }

    return false;
}

function billomat_reminders_get($billoamat_base_uri, $billomat_api_key, $invoice_number) {

    $client = new \GuzzleHttp\Client([
        'base_uri' => $billoamat_base_uri,
        'timeout'  => 2.0,
    ]);

    $response = $client->get(
        '/api/reminders',
        [
            'query' => [
                'invoice_number' => $invoice_number,
                'format' => 'json',
                'api_key' => $billomat_api_key
            ],
        ]
    );

    $reminder = @json_decode($response->getBody()->getContents());
    if($reminder->reminders->reminder) {
        return $reminder->reminders->reminder;
    }

    return false;
}


function billomat_invoice_payment_set($billoamat_base_uri, $billomat_api_key, $invoice_id, $paid_amount, $paid_message) {

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
    $invoicePayment->$k->comment = $paid_message;
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