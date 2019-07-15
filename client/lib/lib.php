<?php

function get_template_response($tpl_file, $data=[]) {
    $tpl = file_get_contents(TPL_DIR.$tpl_file);

    $data['SUCCESS_MSG'] = isset($data['SUCCESS_MSG']) ? $data['SUCCESS_MSG'] : '';
    $data['ERROR_MSG'] = isset($data['ERROR_MSG']) ? $data['ERROR_MSG'] : '';

    if(!empty($data['ERROR_MSG'])) {
        $data['ERROR_MSG'] = '<div class="alert alert-danger" role="alert">'.$data['ERROR_MSG'].'</div>';
    }

    if(!empty($data['SUCCESS_MSG'])) {
        $data['SUCCESS_MSG'] = '<div class="alert alert-success" role="alert">'.$data['SUCCESS_MSG'].'</div>';
    }

    foreach($data as $k => $v) {
        $tpl = str_replace('{{'.$k.'}}', $v, $tpl);
    }

    return $tpl;
}


function api_get_payment($invoice_number, $invoice_date, $customer_number) {
    global $config;

    $postdata = http_build_query(
        array(
            'api_key' => $config['api.api_key'],
            'invoice_number' => $invoice_number,
            'customer_number' => $customer_number,
            'invoice_date' => $invoice_date
        )
    );

    $opts = array('http' =>
        array(
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $postdata
        )
    );

    $context  = stream_context_create($opts);

    $result = @json_decode(@file_get_contents($config['api.base_uri'].'/pay/?'.$postdata, false, $context), true);

    if(isset($result['url']) && $result['status'] == 'PAYMENT_URL') {
        preg_match('~/payments/(.*)~m', $result['url'], $uuid_match);
        list($route, $uuid) = $uuid_match;
        $result['uuid']=$uuid;
    }

    return $result;
}

function filter_userinput($input) {
    return preg_replace('/[^a-z\d-]/i', '', $input);
}