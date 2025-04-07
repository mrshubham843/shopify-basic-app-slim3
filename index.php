<?php
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/helpers/common_helper.php';  // Include helper functions if needed
require __DIR__ . '/helpers/Database.php';

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Views\Twig;
use Slim\Views\TwigExtension;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$config = ['apiKey' =>  $_ENV["SHOPIFY_API_KEY"], 'secret' => $_ENV["SHOPIFY_API_SECRET"], 'host' =>  $_ENV["NGROK_URL"], 'settings' => [
  'determineRouteBeforeAppMiddleware' => true, 'displayErrorDetails' => true, 'addContentLengthHeader' => false
]];
$app = new \Slim\App($config);
$container = $app->getContainer();
// Set up Twig view
$container['view'] = function ($container) {
    $view = new Twig('pages', ['cache' => false]);
    $view->addExtension(new TwigExtension($container['router'], $container['request']->getUri()));
    return $view;
};

$app->get('/auth', function ($request, $response) {
    $shop = $request->getQueryParams()['shop'];
    $apiKey = $_ENV['SHOPIFY_API_KEY'];
    $scopes = $_ENV['SHOPIFY_SCOPES'];
    $redirectUri = $_ENV['BASE_URL']."/auth/callback";

    $installUrl = "https://$shop/admin/oauth/authorize?client_id=$apiKey&scope=$scopes&redirect_uri=$redirectUri";

    return $response->withRedirect($installUrl);
});

$app->get('/auth/callback', function ($request, $response) {
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
                $_SESSION["shop_data"][$shop_url]["verified_user"] = $_GET["shop"];
                $_SESSION["shop_data"][$shop_url]["token_expiry"] = time() + 360;

                return $response->withHeader('Location',"https://admin.shopify.com/store/".$shopName."/apps/".$_ENV["APP_URL_ID"]);
            }

            // return $response->write("App installed successfully!");
        } else {
            return $response->withStatus(400)->write("Failed to get access token.");
        }
    }
});

//initial index page load
$app->get('/', function ($request, $response) {
     return $this->view->render($response, 'index.php', [
            'env' => $_ENV,
            'shopUrl' => $_GET['shop']
        ]);
})->add(function ($request, $response, $next) {
   
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
                echo "<script>window.top.location.href = '" . $_ENV['NGROK_URL'] . $_ENV['PROJECT_DIR'] . "/auth?shop=" . $_GET['shop'] . "'</script>";
                exit();
            }else{
                $_SESSION["shop_data"][$_GET["shop"]]["verified_user"] = $_GET["shop"];
                $_SESSION["shop_data"][$_GET["shop"]]["token_expiry"] = time() + 360;
            }
        } else{
            echo "<script>window.top.location.href = '" . $_ENV['NGROK_URL'] . $_ENV['PROJECT_DIR'] . "/auth?shop=" . $_GET['shop'] . "'</script>";
            exit();
        }
    }
    return $next($request, $response);
});;

$app->get('/dashboard', function (Request $request, Response $response) {
    	$params = $request->getQueryParams();
        $shop_url = $params['shop'];

        return $this->view->render($response, 'dashboard.php', ['shopURL' => $shop_url]);
    });

$app->get('/loadnewpage', function (Request $request, Response $response) {
    	return "<h2>new page loaded</h2>";
    });
$app->get('/getdata', function (Request $request, Response $response) {
    	return "<h2>getdata loaded</h2>";
    });



$app->run();

?>