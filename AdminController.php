<?php

namespace Controllers;

// require __DIR__ . '/../functions/helpers.php';  // Include helper functions if needed
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    protected $view;
    protected $db;

    public function __construct($view,$db)
    {
        $this->view = $view;
        $this->db = $db;
    }

    public function customization(Request $request, Response $response, array $args): Response
    {
        $queryParams = $request->getQueryParams();
        $shop_url = $queryParams['shop'] ?? '';

        return $this->view->render($response, 'customization.twig', [
            'shopURL' => $shop_url
        ]);
    }

    public function offers(Request $request, Response $response, array $args): Response
    {
    	$params = $request->getQueryParams();
    	$shop_url = isset($params['shop']) ? $params['shop'] : null; // Get 'shop' parameter from the query string
         
        return $this->view->render($response, 'offers.twig', [
            'shopUrl' => $shop_url  // If needed, pass dynamic data (like shop URL)
        ]);
    }

    

    public function settings(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
    	$shop_url = isset($params['shop']) ? $params['shop'] : null; // Get 'shop' parameter from the query string
        $userData = getShopDetails($shop_url);
        return $this->view->render($response, 'settings.twig', [
            'shopUrl' => $shop_url,
            'userData' => $userData
        ]);
    }



    public function dashboard(Request $request, Response $response, array $args): Response
    {
	    $params = $request->getQueryParams();
    	$shop_url = isset($params['shop']) ? $params['shop'] : null; // Get 'shop' parameter from the query string

        $userData = getShopDetails($shop_url);
	    $app_url = "https://".$shop_url."/admin/apps/".$_ENV['APP_URL_ID'];
	    return $this->view->render($response, 'dashboard.twig', ['shopURL' => $shop_url,'userData' => $userData]); 
    }

    // API CALLS

    public function getSettingsData(Request $request, Response $response, array $args): Response
    {
        getSettings();
    }
    
    public function getOffers(Request $request, Response $response, array $args): Response
    {
        return getOffers($request, $response, $args);
    }

    public function getCustomization(Request $request, Response $response, array $args): Response
    {
        return getCustomization($request, $response, $args);
    }

    public function appStatusUpdate(Request $request, Response $response, array $args): Response
    {
		return appStatusUpdate($request);
    }

    public function submitSettings(Request $request, Response $response, array $args): Response
    {
		return submitSettings($request);
    }

    public function createOfferPage(Request $request, Response $response, array $args): Response
    {
    	$params = $request->getQueryParams();
    	$shop_url = isset($params['shop']) ? $params['shop'] : null; // Get 'shop' parameter from the query string
         
        return $this->view->render($response, 'createOffer.twig', [
            'shopUrl' => $shop_url  // If needed, pass dynamic data (like shop URL)
        ]);
    }

    public function submitOffer(Request $request, Response $response, array $args): Response
    {
		return submitOffer($request);
    }

    public function deleteOfferNow(Request $request, Response $response, array $args): Response
    {
        return deleteOfferNow($request);
    }

    public function orders(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $shop_url = isset($params['shop']) ? $params['shop'] : null; // Get 'shop' parameter from the query string
         
        return $this->view->render($response, 'orders.twig', [
            'shopUrl' => $shop_url  // If needed, pass dynamic data (like shop URL)
        ]);
    }

    public function getorders(Request $request, Response $response, array $args): Response
    {
        return  getorders($request,$response); 
    }

    public function appUninstalledWB(Request $request, Response $response, array $args): Response
    {
        return  appUninstalledWB($request,$response); 
    }

    public function orderCreatedWB(Request $request, Response $response, array $args): Response
    {
        return  orderCreatedWB($request,$response); 
    }

     public function selectPlan(Request $request, Response $response, array $args): Response
    {
        return  selectPlan($request,$response); 
    }
    public function billingConfirm(Request $request, Response $response, array $args): Response
    {
        return "x==";
    }
    public function addFollowers(Request $request, Response $response, array $args): Response
    {
        return monitorAndChargeUsage($request,$response);
    }
}
