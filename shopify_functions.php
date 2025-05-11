<?php   

global $shopUrl;
$shopUrl = $_GET['shop'] ?? null;

function connectdb()
{
    static $db = null;

    if ($db === null) {
        $db = new Database(
            $_ENV["DB_DATABASE"],
            $_ENV["DB_USERNAME"],
            $_ENV["DB_PASSWORD"],
            $_ENV["DB_SERVER"]
        );
    }
    return $db;
}

function errorLog($data='')
{
    if(is_array($data)){
        $data = json_encode($data);
    }
    $fileLocation = __DIR__ . "/../errorLogs.txt";  // Move up one directory
    $file = fopen($fileLocation, "a");

    if ($file) {
        fwrite($file, "\n".$data);
        fclose($file);
        return true;
    } else {
        http_response_code(500); // Forbidden
    }
}

function installApp($request,$response)
{
    $params = $request->getQueryParams();

    if (!isset($params['shop'])) {
        return $response->withStatus(400)->write("Missing shop parameter.");
    }

    $shop = $params['shop'];
    $installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
        'client_id'    => $_ENV["SHOPIFY_API_KEY"],
        'scope'        => $_ENV["APP_SCOPES"],
        'redirect_uri' => $_ENV["BASE_URL"]."/auth/callback"
    ]);

    errorLog($installUrl);

    header("Location: " . $installUrl);
    exit();

    // return $response->withHeader('Location', $installUrl)->withStatus(302);
}

function shopifyAuthCallback($request,$response)
{
    errorLog('shopifyAuthCallback called==');

    $params = $request->getQueryParams();
    if (validateHmac($params, $_ENV["SHOPIFY_API_SECRET"])) {
        if (!isset($params['code'], $params['shop'])) {
            return $response->withStatus(400)->write("Invalid request.");
        }

        $shop = $params['shop'];
        $code = $params['code'];
        $tokenUrl = "https://{$shop}/admin/oauth/access_token";

        $data = [
            'client_id'     => $_ENV["SHOPIFY_API_KEY"],
            'client_secret' => $_ENV["SHOPIFY_API_SECRET"],
            'code'          => $code
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $result = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($result, true);

        if (isset($json['access_token'])) {
            $shopName = str_replace(".myshopify.com", "", $shop);
            $result = addStore($params["shop"], $json['access_token']);
            if ($result) {
                $_SESSION["shop_data"][$shop]["verified_user"] = $shop;
                $_SESSION["shop_data"][$shop]["token_expiry"] = time() + 360;
                
                $redirectUrl = "https://admin.shopify.com/store/".$shopName."/apps/".$_ENV["APP_URL_ID"];
                return $redirectUrl;
            }
        } else {
            return $response->withStatus(400)->write("Failed to get access token.");
        }
    }
}

function verifyShopifyToken($request, $response, $next) {
    $authHeader = $request->getHeaderLine('Authorization');

    if (!$authHeader) {
        return $response->withJson(['error' => 'Authorization header missing'], 401);
    }

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        return $response->withJson(['error' => 'Invalid session token format'], 401);
    }

    $token = $matches[1];
    $secretKey = $_ENV['SHOPIFY_API_SECRET'];

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return $response->withJson(['error' => 'Invalid token structure'], 401);
    }

    list($header, $payload, $signature) = $parts;

    $expectedSignature = hash_hmac('sha256', "$header.$payload", $secretKey, true);
    $expectedSignature = rtrim(strtr(base64_encode($expectedSignature), '+/', '-_'), '=');

    if (!hash_equals($expectedSignature, $signature)) {
        return $response->withJson(['error' => 'Invalid token signature'], 401);
    }

    $payloadData = json_decode(base64_decode($payload), true);
    if (!$payloadData || empty($payloadData['exp']) || $payloadData['exp'] < time() + 360) {
        return $response->withJson(['error' => 'Token expired or invalid'], 401);
    }

    if (!isset($payloadData['iss']) || !str_contains($payloadData['iss'], 'myshopify.com')) {
        return $response->withJson(['error' => 'Invalid token issuer'], 401);
    }

    // Token is valid — optionally attach token data to request for later use
    $request = $request->withAttribute('shopify_token', $payloadData);

    return $next($request, $response);
}

function verifyWithMiddleWare($request, $response, $next)
{
    // Middleware
    $params = $request->getQueryParams();
    $shop_url = $params['shop'];

    if (isset($_SESSION['shop_data'][$shop_url]['verified_user']) && $_SESSION['shop_data'][$shop_url]['token_expiry'] > time()) {
        //USER VALIDATED 
        return $next($request, $response);
    }
    else {
        $db = connectdb();
        $db->select("shops", array(
            "shop_url" => $params['shop']
        ));
        $shop = $db->result_array();
        if (isset($shop) && !empty($shop)) {
            $endpoint = '/admin/api/'.$_ENV['APP_API_VERSION'].'/shop.json';
            $responseData = rest_api($shop[0]['access_token'], $params['shop'], $endpoint, array(), 'GET');
            $responseData = json_decode($responseData['data'], true);

            if (array_key_exists('errors', $responseData)) {
                echo "<script>window.top.location.href = '" . $_ENV['NGROK_URL'] . $_ENV['PROJECT_DIR'] . "/install?shop=" . $shop_url . "'</script>";
                exit();
            }else{
                $_SESSION["shop_data"][$shop_url]["verified_user"] = $shop_url;
                $_SESSION["shop_data"][$shop_url]["token_expiry"] = time() + 360;
            }
        } else{
            echo "<script>window.top.location.href = '" . $_ENV['NGROK_URL'] . $_ENV['PROJECT_DIR'] . "/install?shop=" . $shop_url . "'</script>";
            exit();
        }
    }
    return $next($request, $response);
}


function validateHmac($params, $secret)
{
    $hmac = $params['hmac'];
    unset($params['hmac']);
    ksort($params);
    $computedHmac = hash_hmac('sha256', http_build_query($params), $secret);

    return hash_equals($hmac, $computedHmac);
}

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
        "shop_name" => $shopName,
        "shop_email" => $shopEmail,
        "access_token" => $accessToken,
        "install_date" => date('Y-m-d H:i:s'),
        "userActive" => 1
    );

    $db = connectdb();
    $db->select("shops", array(
        "shop_url" => $validShop
    ));
    $shop = $db->result_array();

    // update if shop availble othewise update it
    if (isset($shop) && !empty($shop)) {
        $db->update('shops', $data, array(
            "shop_url" => $validShop
        ));
    } else {
        $db->insert('shops', $data);
        // $storeId = $db->id();
    }


    // add default settings
    $db->select("settings", array('shop_url' => $validShop));
    $settings = $db->result_array();
    $settingsData = array(
        'shop_url' => $validShop,
        'app_status' => 0,
        'offer_message_text' => 'Buy this product & get free 100 followers',
        'offer_button_text' => 'Claim Offer',
        'no_of_followers' => 100,
        'max_usage'=>1
    );

    if (isset($settings) && !empty($settings)) {
        $db->update('settings', $settingsData, array('shop_url' => $validShop));
    } else {
        $db->insert('settings', $settingsData);
    }

    // add default customization 
    $db->select("customization", array('shop_url' => $validShop));
    $customization = $db->result_array();
    $customizationData = array(
                            'shop_url' => $validShop,
                            'offerHeading' => 'Free Gift',
                            'offerText' => 'Buy now & get #followerQty Instagram followers!"',
                            'fomoText' => null,
                            'buttonText' => 'Claim Offer Now!',
                            'borderColor' => '#fff',
                            'fomoTextColor' => null,
                            'timerTextColor' => '#d63031',
                            'offerTextColor' => '#666',
                            'offerHeadingColor' => '#000',
                            'borderSize' => '0',
                            'borderRadius' => '15',
                            'margin' => '0',
                            'padding' => '20',
                            'offerHeadingSize' => '20',
                            'offerTextSize' => '14',
                            'timerTextSize' => '16',
                            'showTimer' => '0',
                            'fomoTextSize' => '14',
                            'timerText' => ' Hurry! Offer ends in ',
                            'created' => date('Y-m-d H:i:s') // current timestamp
                        );


    if (isset($customization) && !empty($customization)) {
        $db->update('customization', $customizationData, array('shop_url' => $validShop));
    } else {
        $db->insert('customization', $customizationData);
    }

    // Register uninstall webhook
    $responseUninstall = registerShopifyWebhook($validShop,$accessToken,'app/uninstalled','/webhooks/app-uninstalled');
    errorLog('Uninstall Webhook: ' . json_encode($responseUninstall));


    // Register order create webhook
    $responseOrderCreate = registerShopifyWebhook($validShop, $accessToken, 'orders/create', '/webhooks/order-created');
    errorLog('Order Create Webhook: ' . json_encode($responseOrderCreate));

    return true;
}

function getShopDetails($shop) {
    $where = '';
    if (is_numeric($shop)) {
        $where = array('id' => $shop);
    }else{
        $where = array('shop_url' => $shop);
    }
    $db = connectdb();
    $db->select("shops", $where);
    return $db->row_array();
}

function registerShopifyWebhook($shop, $accessToken, $topic, $callbackPath)
{
    $endpoint = '/admin/api/' . $_ENV['APP_API_VERSION'] . '/webhooks.json';
    $webhookPayload = [
        'webhook' => [
            'topic'   => $topic,
            'address' => rtrim($_ENV['BASE_URL'], '/') . $callbackPath,
            'format'  => 'json'
        ]
    ];

    $headers = [
        'Content-Type: application/json'
    ];

    $responseData = rest_api(
        $accessToken,
        $shop,
        $endpoint,
        json_encode($webhookPayload),
        'POST',
        $headers
    );

    return $responseData;
}

function appUninstalledWB()
{
    errorLog("appUninstall>>>dataRecieved");

    $hmac_header = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'];
    $shop_url = $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'];
    $jsonarray = file_get_contents('php://input');
    $calculated_hmac = base64_encode(hash_hmac('sha256', $jsonarray, $_ENV['SHOPIFY_API_SECRET'], true));
    $verified =  hash_equals($hmac_header, $calculated_hmac);

    if ($verified) {
        errorLog("appUninstall>>>verified");
        
        $db = connectdb();
        $db->select("shops", array('shop_url' => $shop_url));
        $results = $db->result_array();

        if (isset($results) && !empty($results)) {
            $db->update('shops', array('userActive' => 0), array('shop_url' => $shop_url));
        }else{
             errorLog("appUninstall>>>DB shop not found");
        }
    }else{
             errorLog("appUninstall>>>Not verified");
    }

    return $response->withStatus(200)->write('Webhook received');
}

function graphql2($query = array(),$variables = array())
{
    global $shopUrl;
  
    $db = connectdb();
    $db->select("shops", array(
        "shop_url" => $shopUrl
    ));
    $shop = $db->result_array();
    // echo "<pre>";print_r($shop);die;

    $access_token = $shop[0]['access_token'];

    $url = 'https://' . $shopUrl . '/admin/api/'.$_ENV['APP_API_VERSION'].'/graphql.json';

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

    $headers[] = "";
    $headers[] = "Content-Type: application/json";
    if ($access_token) $headers[] = "X-Shopify-Access-Token: " . $access_token;
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($query,JSON_UNESCAPED_UNICODE));
    curl_setopt($curl, CURLOPT_POST, true);

    $response = curl_exec($curl);
    $error = curl_errno($curl);
    $error_msg = curl_error($curl);


    curl_close($curl);

    if ($error) {
        return $error_msg;
    } else {
        $response = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);

        $headers = array();
        $headers_content = explode("\n", $response[0]);
        $headers['status'] = $headers_content[0];

        array_shift($headers_content);

        foreach ($headers_content as $content) {
            $data = explode(':', $content);
            $headers[trim($data[0])] = trim($data[1]);
        }

        return array('body' => $response[1]);
    }
}


function rest_api($token, $shop, $api_endpoint, $query = array(), $method = 'GET', $request_headers = array())
{
    $url = "https://" . $shop . $api_endpoint;
    if (!is_null($query) && in_array($method, ['GET', 'DELETE'])) {
        $url = $url . "?" . http_build_query($query);
    }

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);  // Secure SSL verification
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

    $request_headers[] = "Content-Type: application/json";
    if (!is_null($token)) {
        $request_headers[] = "X-Shopify-Access-Token: " . $token;
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $request_headers);

    if ($method != 'GET' && in_array($method, ['POST', 'PUT'])) {
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
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $headers = [];
        foreach (explode("\n", $header) as $h) {
            if (strpos($h, ":") !== false) {
                list($key, $value) = explode(":", $h, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        return [
            'headers' => $headers,
            'data' => $body
        ];
    }
}

 
// function selectPlan($request,$response)
// {
//     $parsedBody = $request->getParsedBody();
//     $selectedPlan = $parsedBody['selectPlan'];

//     $shopUrl = $request->getQueryParams();
//     $shop_url = isset($shopUrl['shop']) ? $shopUrl['shop'] : null; 

//     $db = connectdb();
//     $db->select("shops", array(
//         "shop_url" => $shop_url
//     ));
//     $shop = $db->result_array();

//     // update if shop availble othewise update it
//     if (isset($shop) && !empty($shop)) {

//         // GraphQL mutation for plan selection
//         $mutation = createBillingMutation($selectedPlan);
//         if (!$mutation) {
//             return $response->withStatus(400)->write("Invalid plan selected");
//         }
//         // echo "<pre>";print_r($mutation);die;

//         // Call GraphQL API to create subscription
//         $res = graphql2($shop[0]['shop_url'], $shop[0]['access_token'], $mutation);

//         $url = $res['data']['appSubscriptionCreate']['confirmationUrl'] ?? null;

//         if ($url) {
//             return $response->withHeader('Location', $url)->withStatus(302);
//         } else {
//             return $response->write("Billing creation failed: " . json_encode($res));
//         }
//     }else{
//         return $response->withStatus(500)->write("invalid shop");
//     }
// }

// // Helper function to create GraphQL mutation based on plan
// function createBillingMutation($selectedPlan)
// {
//     $returnUrl = rtrim($_ENV['BASE_URL'], '/') . '/billing/confirm';

//     if ($selectedPlan === 'basic') {
//         return <<<GQL
//         mutation {
//             appSubscriptionCreate(
//                 name: "Basic Plan",
//                 returnUrl: "$returnUrl",
//                 test: true,
//                 lineItems: [
//                     {
//                         plan: {
//                             usagePricingDetails: {
//                                 cappedAmount: { amount: 50.0, currencyCode: USD },
//                                 terms: "Free install, pay-as-you-use (up to \$50/month)"
//                             }
//                         }
//                     }
//                 ]
//             ) {
//                 userErrors { field message }
//                 confirmationUrl
//                 appSubscription { id }
//             }
//         }
//         GQL;
//     } elseif ($selectedPlan === 'pro') {
//         return <<<GQL
//         mutation {
//             appSubscriptionCreate(
//                 name: "Pro Plan",
//                 returnUrl: "$returnUrl",
//                 test: true,
//                 lineItems: [
//                     {
//                         plan: {
//                             appRecurringPricingDetails: {
//                                 interval: EVERY_30_DAYS,
//                                 price: { amount: 5.00, currencyCode: USD },
//                                 usagePricingDetails: {
//                                     cappedAmount: { amount: 100.0, currencyCode: USD },
//                                     terms: "\$5/month base, plus usage (up to \$100/month)"
//                                 }
//                             }
//                         }
//                     }
//                 ]
//             ) {
//                 userErrors { field message }
//                 confirmationUrl
//                 appSubscription { id }
//             }
//         }
//         GQL;
//     }
//     return null;
// }




function selectPlan($request, $response)
{
    $shopUrl = $request->getQueryParams();
    $shop_url = isset($shopUrl['shop']) ? $shopUrl['shop'] : null;
    $selectedPlan = isset($shopUrl['selectPlan']) ? $shopUrl['selectPlan'] : 'basic';

    $db = connectdb();
    $db->select("shops", ["shop_url" => $shop_url]);
    $shop = $db->result_array();
        errorLog(json_encode("selectPlan"));

    if (!empty($shop)) {
        $confirmationUrl = createRecurringCharge($shop[0]['shop_url'], $shop[0]['access_token'], $selectedPlan);
        if ($confirmationUrl) {
            return $response->write($confirmationUrl)->withHeader('Content-Type', 'text/html')->withStatus(200);
        } else {
            return $response->withJson(['message' => 'Failed to create recurring charge', 'status' => 500])->withHeader('Content-Type', 'application/json');
        }
    } else {
        return $response->withJson(['message' => 'Invalid shop', 'status' => 500])->withHeader('Content-Type', 'application/json');
    }
}

function createRecurringCharge($shop, $token, $planType = 'basic') {
    $shopName = str_replace(".myshopify.com", "", $shop);
    $returnUrl = "https://admin.shopify.com/store/".$shopName."/apps/".$_ENV["APP_URL_ID"]."/";
    $currencyCode = "USD";

    $name = $_ENV['APP_PLAN_NAME'];
    $cappedAmount = $_ENV['APP_PLAN_CAPPED_AMOUNT'];
    $terms = $_ENV['APP_PLAN_TERM'];
    $recurringPrice = $_ENV['APP_PLAN_RECURRING_PRICE'];

    // GraphQL mutation
    $query = <<<QUERY
    mutation {
        appSubscriptionCreate(
            name: "$name",
            returnUrl: "$returnUrl",
            test: true,
            lineItems: [
                {
                    plan: {
                        appUsagePricingDetails: {
                            terms: "$terms",
                            cappedAmount: {
                                amount: $cappedAmount,
                                currencyCode: $currencyCode
                            }
                        }
                    }
                },
                {
                    plan: {
                        appRecurringPricingDetails: {
                            price: {
                                amount: $recurringPrice,
                                currencyCode: $currencyCode
                            }
                        }
                    }
                }
            ]
        ) {
            userErrors {
                field
                message
            }
            confirmationUrl
            appSubscription {
                id
                lineItems {
                    id
                    plan {
                        pricingDetails {
                            __typename
                        }
                    }
                }
            }
        }
    }
    QUERY;

    // Send the GraphQL request
    $response = rest_api($token, $shop, "/admin/api/".$_ENV['APP_API_VERSION']."/graphql.json", json_encode(["query" => $query]), "POST");
    $data = json_decode($response['data'], true);

    errorLog("GraphQL Response: " . json_encode($data));

    // Check for errors in response
    if (isset($data['data']['appSubscriptionCreate']['userErrors']) && !empty($data['data']['appSubscriptionCreate']['userErrors'])) {
        foreach ($data['data']['appSubscriptionCreate']['userErrors'] as $error) {
            errorLog("Error creating subscription: " . $error['message']);
        }
        return null;
    }

    // Extract the confirmation URL and subscription ID
    $confirmationUrl = $data['data']['appSubscriptionCreate']['confirmationUrl'] ?? null;
    $subscriptionId = $data['data']['appSubscriptionCreate']['appSubscription']['lineItems'][0]['id'] ?? null;

    if ($subscriptionId) {
        // Save the charge ID to the database
        $db = connectdb();
        $db->update("shops", ["recurring_charge_id" => $subscriptionId], ["shop_url" => $shop]);

        errorLog("Subscription ID $subscriptionId saved for shop: $shop");
        return $confirmationUrl;
    }

    // Log the failure and return null
    errorLog("Failed to create recurring charge for shop: $shop. Response: " . json_encode($data));
    return null;
}

function monitorAndChargeUsage($request,$response) {
    $parsedBody     = $request->getParsedBody();
    $addFollowers   = $parsedBody['addFollowers'];

    $shopUrl = $request->getQueryParams();
    $shop_url = isset($shopUrl['shop']) ? $shopUrl['shop'] : null; 

    $shop = getShopDetails($shop_url);
    if (!empty($shop)) {
        $lineItemId = $shop['recurring_charge_id'];
        $addFollowers = (int)$addFollowers;
        $charge_amount = $addFollowers * $_ENV['CHARGE_PER_FOLLOWER'];
        
        $lineItemId = getSubscriptionLineItemId($shop['shop_url'], $shop['access_token']);
        $charge = createUsageBasedCharge($shop['shop_url'], $shop['access_token'], $lineItemId, "Follower increase charge", $charge_amount);
        if ($charge) {
            recordUsage($shop_url, $addFollowers, $charge_amount, $charge['id']);
            echo json_encode(true);
            exit();
        }
    }
   
    echo json_encode(false);
    exit();
}

function createUsageBasedCharge($shop, $token, $lineItemId, $description, $amount) {
    $query = <<<QUERY
    mutation {
        appUsageRecordCreate(
            subscriptionLineItemId: "$lineItemId",
            description: "$description",
            price: { amount: $amount, currencyCode: USD }
        ) {
            appUsageRecord {
                id
                description
                price {
                    amount
                    currencyCode
                }
            }
            userErrors {
                field
                message
            }
        }
    }
    QUERY;

    $response = rest_api($token, $shop, "/admin/api/2025-01/graphql.json", json_encode(["query" => $query]), "POST");
    $data = json_decode($response['data'], true);
    
    // Check for errors in the response
    if (isset($data['data']['appUsageRecordCreate']['userErrors']) && !empty($data['data']['appUsageRecordCreate']['userErrors'])) {
        foreach ($data['data']['appUsageRecordCreate']['userErrors'] as $error) {
            errorLog($error);
        }
        return null;
    }

    return $data['data']['appUsageRecordCreate']['appUsageRecord'] ?? null;
}

function getSubscriptionLineItemId($shop, $token) {
    // Updated GraphQL query to get the subscription line item ID
    $query = <<<QUERY
    {
        appInstallation {
            activeSubscriptions {
                id
                name
                lineItems {
                    id
                }
            }
        }
    }
    QUERY;

    // Make the API request to Shopify
    $response = rest_api($token, $shop, "/admin/api/2025-01/graphql.json", json_encode(['query' => $query]), "POST");
    $data = json_decode($response['data'], true);

    // Check if the response contains the subscription line item ID
    if (!empty($data['data']['appInstallation']['activeSubscriptions'][0]['lineItems'][0]['id'])) {
        $subscriptionLineItemId = $data['data']['appInstallation']['activeSubscriptions'][0]['lineItems'][0]['id'];
        return $subscriptionLineItemId;
    } else {
        errorLog("Error fetching subscription line item ID: " . json_encode($data));
        return null;
    }
}

function recordUsage($shop_url, $followers, $charge_amount, $usage_charge_id) {
    $shop = getShopDetails($shop_url);
    if($shop){
        $db = connectdb();
        $db->insert("usage_charges", [
            "shop_url" => $shop_url,
            "usage_charge_id" => $usage_charge_id,
            "followers_added" => $followers,
            "amount" => $charge_amount,
            "description" => "Follower increase charge"
        ]);

        // Calculate new total followers and usage cap
        $total_followers = $shop['total_followers'] + $followers;
        $newCap = $shop['total_usage_cap'] + $charge_amount;

        // Update the shops table using your helper update function
        $db->update("shops", [
            "total_followers" => $total_followers,
            "total_usage_cap" => $newCap
        ], [
            "shop_url" => $shop_url
        ]);
        
    }
}

function getShopId() {
    $query = [
        'query' => '
        {
            shop {
                id
            }
        }'
    ];

    $response = graphql2($query);
    $response = json_decode($response['body'],true);

    if (!empty($response['data']['shop']['id'])) {
        return $response['data']['shop']['id'];
    } else {
        echo "❌ Failed to retrieve Shop ID.";
        return null;
    }
}

// Check if Metafield Definition Exists
function checkMetafieldDefinition($namespace, $key) {
    $query = [
        "query" => '
        {
            metafieldDefinitions(first: 10, query: "namespace:' . $namespace . ' key:' . $key . '") {
                edges {
                    node {
                        id
                    }
                }
            }
        }'
    ];

    $response = graphql2($query);
    $result = json_decode($response['body'], true);

    return !empty($result['data']['metafieldDefinitions']['edges']);
}

function saveMetafield($type, $namespace, $key, $jsonValue, $targetId = null) {
    $ownerId = '';

    if ($type === 'shop') {
        $ownerId = getShopId();
    } elseif ($type === 'product' && $targetId) {
        $ownerId = "gid://shopify/Product/$targetId";
    } elseif ($type === 'collection' && $targetId) {
        $ownerId = "gid://shopify/Collection/$targetId";
    } else {
        echo "❌ Invalid type or missing ID for product/collection.";
        return;
    }

    if (!$ownerId) {
        echo "❌ Owner ID not found.";
        return;
    }

    // Encode JSON as a string for 'multi_line_text_field'
    $encodedValue = json_encode($jsonValue, JSON_PRETTY_PRINT);

    $graphqlQuery = [
        'query' => '
        mutation MetafieldsSet($metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: $metafields) {
                metafields {
                    id
                    namespace
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }',
        'variables' => [
            'metafields' => [
                [
                    'namespace' => $namespace,
                    'key' => $key,
                    'type' => 'multi_line_text_field',
                    'value' => $encodedValue,  // Correct format
                    'ownerId' => $ownerId
                ]
            ]
        ]
    ];

    $response = graphql2($graphqlQuery);
    // $response = json_decode($response['body'], true);
}

function deleteMetafieldById($metafieldId) {
    $mutation = [
        'query' => '
            mutation DeleteMetafield($id: ID!) {
                metafieldDelete(input: {id: $id}) {
                    deletedId
                    userErrors {
                        field
                        message
                    }
                }
            }',
        'variables' => [
            'id' => $metafieldId
        ]
    ];

    return graphql2($mutation);
}

function getMetafieldIdByKey($ownerId, $namespace, $key) {
    $query = [
        'query' => '
            query GetMetafieldId($ownerId: ID!, $namespace: String!, $key: String!) {
                owner: node(id: $ownerId) {
                    ... on Shop {
                        metafield(namespace: $namespace, key: $key) {
                            id
                        }
                    }
                }
            }',
        'variables' => [
            'ownerId' => $ownerId,
            'namespace' => $namespace,
            'key' => $key
        ]
    ];

    $response = graphql2($query);
    return $response['data']['owner']['metafield']['id'] ?? null;
}

function getAllMetafieldsByNamespace($ownerId, $namespace) {
    $query = [
        'query' => '
            query GetMetafields($ownerId: ID!, $namespace: String!) {
                owner: node(id: $ownerId) {
                    ... on Shop {
                        metafields(first: 100, namespace: $namespace) {
                            edges {
                                node {
                                    id
                                    namespace
                                    key
                                }
                            }
                        }
                    }
                }
            }',
        'variables' => [
            'ownerId' => $ownerId, // Example: getShopId()
            'namespace' => $namespace
        ]
    ];

    $response = graphql2($query);
    return $response['data']['owner']['metafields']['edges'] ?? [];
}

function getShopMetafield()
{
    $getShopMetafield = [
        'query' => '{
                      shop {
                        metafield(namespace: "socialx", key: "offerData") {
                          key
                          namespace
                          value
                          type
                        }
                      }
                    }'
    ];
 
    $getShopMetafield = graphql2($getShopMetafield);
    $getShopMetafield = json_decode($getShopMetafield['body'],true);
    $getShopMetafield = $getShopMetafield['data']['shop']['metafield']['value'];
    return $getShopMetafield;
}

// Validate input data function
function sanitizeInput($input, $default = '') {
    return isset($input) && !empty(trim($input)) ? htmlspecialchars(trim($input)) : $default;
}



?>