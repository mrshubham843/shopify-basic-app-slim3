$(document).ready(function() {

//------------------------- #page common code - start
    initializeAppBridge();
    
    function initializeAppBridge() {
        window.appVar = {};

        var urlParams = new URLSearchParams(window.location.search);
        var host = urlParams.get("host");
        var AppBridge = window['app-bridge'];
        var actions = AppBridge.actions;
        var Redirect = actions.Redirect;

        var app = AppBridge.createApp({
            apiKey: SHOPIFY_API_KEY,
            host: host
        });

        window.appVar.appBridge = AppBridge;
        window.appVar.actions = actions;
        window.appVar.redirect = Redirect;
        window.appVar.app = app;
    }

    //app-bridge token generate - start
    fetchSessionToken().then(function (token) {
        setupAjaxWithToken(token);
    }).catch(function (error) {
        console.error('❌ Error during session token setup:', error);
    }); 

    // Set AJAX Token
    function setupAjaxWithToken(token) {
        $.ajaxSetup({
            beforeSend: function (xhr) {
                if (token) {
                    xhr.setRequestHeader('Authorization', `Bearer ${token}`);
                }
            }
        });
    }

    function fetchSessionToken() {
        return new Promise(async (resolve, reject) => {
            initializeAppBridge();
            console.log(window['app-bridge-utils']);
            const { getSessionToken } = window['app-bridge-utils'];
            try {
                const token = await getSessionToken(window.appVar.app);
                if (!token) {
                    return reject('Empty token');
                }
                resolve(token);
            } catch (error) {
                reject(error);
            }
        });
    }
    //app-bridge token generate - end

    function showToast(message) {
        var Toast = window.appVar.actions.Toast;
        if (!Toast || !window.appVar.app) {
            initializeAppBridge(); // Ensure AppBridge is active
        }
        var toast = Toast.create(window.appVar.app, {
            message: message,
            duration: 3000
        });

        toast.dispatch(Toast.Action.SHOW);
    }
}); 
