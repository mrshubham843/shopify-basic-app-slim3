<?php
ini_set("session.cookie_secure", "1"); // Enforce HTTPS-only cookies
ini_set("session.cookie_httponly", "1"); // Prevent JavaScript access to cookies
ini_set("session.use_only_cookies", "1"); // Disallow URL-based sessions
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/vendor/autoload.php';

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Views\Twig;
use Slim\Views\TwigExtension;
use Dotenv\Dotenv;
use Controllers\AdminController;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

require __DIR__ . '/functions/helpers.php';  // Include helper functions if needed

$config = ['apiKey' =>  $_ENV["SHOPIFY_API_KEY"], 'secret' => $_ENV["SHOPIFY_API_SECRET"], 'host' =>  $_ENV["NGROK_URL"], 'settings' => [
  'determineRouteBeforeAppMiddleware' => true, 'displayErrorDetails' => true, 'addContentLengthHeader' => false
]];
$app = new \Slim\App($config);
$container = $app->getContainer();
// Set up Twig view
$container['view'] = function ($container) {
    $view = new Twig('templates', ['cache' => false]);
    $view->addExtension(new TwigExtension($container['router'], $container['request']->getUri()));
    return $view;
};

// Register Database connection
$container['db'] = function ($c) {
    return new Database(
        $_ENV['DB_DATABASE'],
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        $_ENV['DB_SERVER']
    );
};

$container[AdminController::class] = function ($c) {
    return new AdminController($c->get('view'),$c->get('db'));
};


$app->get('/install', function (Request $request, Response $response) {
    installApp($request,$response);
});

$app->get('/auth/callback', function (Request $request, Response $response) {
    $returnUrl = shopifyAuthCallback($request,$response);

    return $response->withHeader('Location', $returnUrl)->withStatus(302);
});

$app->get('/', function ($request, $response) {
     return $this->view->render($response, 'index.twig', ['env' => $_ENV,'shopUrl' => $_GET['shop']]);
})->add(function ($request, $response, $next) {
    return verifyWithMiddleWare($request, $response, $next); // Add Auth middleware here
});

$app->group('/admin', function () use ($app) {
    $app->get('/customization', AdminController::class . ':customization');
    $app->get('/getOffers', AdminController::class . ':getOffers');
    $app->get('/settings', AdminController::class . ':settings');
    $app->get('/offers', AdminController::class . ':offers');
    $app->get('/orders', AdminController::class . ':orders');
    $app->get('/dashboard', AdminController::class . ':dashboard');
    $app->post('/getCustomization', AdminController::class . ':getCustomization');
    $app->get('/createOfferPage', AdminController::class . ':createOfferPage');

    // $app->get('/selectPlan', AdminController::class . ':selectPlan');
    $app->get('/selectPlan', AdminController::class . ':selectPlan');
    $app->get('/billing/confirm', AdminController::class . ':dashboard');
    $app->post('/addFollowers', AdminController::class . ':addFollowers');



    $app->post('/submitSettings', AdminController::class . ':submitSettings');
    $app->post('/appStatusUpdate', AdminController::class . ':appStatusUpdate');
    $app->post('/submitOffer', AdminController::class . ':submitOffer');
    $app->post('/deleteOfferNow', AdminController::class . ':deleteOfferNow');
    $app->post('/getOffers', AdminController::class . ':getOffers');
    $app->post('/getorders', AdminController::class . ':getorders');
    $app->post('/getSettingsData', AdminController::class . ':getSettingsData');

    // $app->post('/getOffers', AdminController::class . ':getOffers')->add('verifyShopifyToken');
    // ->add('verifyShopifyToken') fix this issue (error    "Token expired or invalid") later............
});

$app->post('/webhooks/app-uninstalled', AdminController::class . ':appUninstalledWB');
$app->post('/webhooks/order-created', AdminController::class . ':orderCreatedWB');



// Run Slim App
$app->run();
