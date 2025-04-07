<?php

function addStore($validShop, $accessToken)
{
    //get shop details from api
    $endpoint = '/admin/api/'.$_ENV['APP_API_VERSION'].'/shop.json';
    $response = rest_api($accessToken, $validShop, $endpoint, array(), 'GET');
    $shopDetails = json_decode($response['data'], true);

    //prepare for db insert record
    $shopName = $shopDetails['shop']['name'];
    $shopEmail = $shopDetails['shop']['email'];
    $data = array(
        "shop_url" => $validShop,
        "access_token" => $accessToken,
        "installed_at" => $accessToken
    );

    $db = connectdb();
    $db->select("shops", array(
        "shop_url" => $validShop
    ));
    $shop = $db->result_array();

    // update if shop availble othewise update it
    if (isset($shop) && !empty($shop)) {
        $db->update('shops', $data, array(
            "shop_url" => $shop[0]['shop_url']
        ));
    } else {
        $db->insert('shops', $data);
        // $storeId = $db->id();
    }
    return true;
}

function validateHmac($params, $secret)
{
    $hmac = $params['hmac'];
    unset($params['hmac']);
    ksort($params);
    $computedHmac = hash_hmac('sha256', http_build_query($params), $secret);

    return hash_equals($hmac, $computedHmac);
}

function rest_api($token, $shop, $api_endpoint, $query = array(), $method = 'GET', $request_headers = array())
{
    $url = "https://" . $shop . $api_endpoint;
    if (!is_null($query) && in_array($method, array(
        'GET',
        'DELETE'
    ))) {
        $url = $url . "?" . http_build_query($query);
    }

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

    $request_headers[] = "";
    $headers[] = "Content-Type: application/json";
    if (!is_null($token)) {
        $request_headers[] = "X-Shopify-Access-Token: " . $token;
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $request_headers);

    if ($method != 'GET' && in_array($method, array(
        'POST',
        'PUT'
    ))) {
        if (is_array($query)) {
            $query = json_encode($query);
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
    }

    $response = curl_exec($curl);
    $error_number = curl_errno($curl);
    $error_message = curl_error($curl);
    curl_close($curl);

    if ($error_number) {
        return $error_message;
    } else {
        $response = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);
        $headers = array();
        $header_data = explode("\n", $response[0]);
        $headers['status'] = $header_data[0];
        array_shift($header_data);
        foreach ($header_data as $part) {
            $h = explode(":", $part, 2);
            $headers[trim($h[0])] = trim($h[1]);
        }

        return array(
            'headers' => $headers,
            'data' => $response[1]
        );
    }
}

function connectdb()
{
    $db = new Database($_ENV['DB_DATABASE'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $_ENV['DB_SERVER']);
    return $db;
}

?>