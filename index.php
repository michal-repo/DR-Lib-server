<?php

namespace Router;

// Session cookie path setting is no longer relevant for JWT auth state
// $cookiePath = empty($_ENV['domain_path']) ? '/' : $_ENV['domain_path'];
// \ini_set('session.cookie_path', $cookiePath);

require_once 'vendor/autoload.php';

use DRLib\Auth\APIAuth;
use DRLib\Actions\Favorites;
use \Bramus\Router\Router as BRouter;
use Dotenv\Dotenv as Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();


$router = new BRouter();

// --- Enhanced Error Handler ---
function handleErr(\Throwable $th) {
    // Ensure JSON header is set for error responses
    header('Content-Type: application/json; charset=utf-8');
    $code = $th->getCode();
    $message = $th->getMessage();
    $httpStatusCode = 500;
    $jsonMessage = 'Internal Server Error';

    switch ($code) {
        case 400: $httpStatusCode = 400; $jsonMessage = $message ?: 'Bad Request'; break;
        case 401: $httpStatusCode = 401; $jsonMessage = $message ?: 'Unauthorized'; break; // Used for invalid/missing JWT
        case 404: $httpStatusCode = 404; $jsonMessage = $message ?: 'Not Found'; break;
        case 409: $httpStatusCode = 409; $jsonMessage = $message ?: 'Conflict'; break;
        case 503: $httpStatusCode = 503; $jsonMessage = $message ?: 'Service Unavailable'; break;
        case 429: $httpStatusCode = 429; $jsonMessage = $message ?: 'Too Many Requests'; break;
    }

    http_response_code($httpStatusCode);

    if (isset($_ENV['debug']) && $_ENV['debug'] === "true") {
        // Detailed error in debug mode
        echo json_encode([
            'status' => ['code' => $httpStatusCode, 'message' => $message],
            'error_details' => [
                'message' => $th->getMessage(),
                'code' => $th->getCode(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString()
            ]
        ], JSON_PRETTY_PRINT); // Added JSON_PRETTY_PRINT for debug readability
    } else {
        // Generic error for production
        if ($httpStatusCode >= 500) { // Log only server-side errors (5xx)
            $logEntry = sprintf(
                "### %s ###\nCode: %d\nMessage: %s\nFile: %s\nLine: %d\nTrace:\n%s\n",
                date('Y-m-d H:i:s'),
                $th->getCode(),
                $th->getMessage(),
                $th->getFile(),
                $th->getLine(),
                $th->getTraceAsString()
            );
            // Ensure logs directory exists and is writable
            @file_put_contents('logs/errors.log', $logEntry . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        echo json_encode(['status' => ['code' => $httpStatusCode, 'message' => $jsonMessage]]);
    }
    die();
}

// --- CORS OPTIONS Request Handler ---
function handleOptionsRequest(string $allowedMethods) {
    // Read CORS settings from environment or use defaults
    $allowedOrigin = $_ENV['CORS_ALLOWED_ORIGIN'] ?? '*';
    // Ensure Authorization is allowed for JWT
    $allowedHeaders = $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type, Authorization, X-Requested-With';
    $maxAge = $_ENV['CORS_MAX_AGE'] ?? '86400';

    header("Access-Control-Allow-Origin: {$allowedOrigin}");
    header("Access-Control-Allow-Methods: {$allowedMethods}, OPTIONS");
    header("Access-Control-Allow-Headers: {$allowedHeaders}");
    header("Access-Control-Max-Age: {$maxAge}");
    // If your frontend needs to send cookies (though less common with JWT), uncomment:
    // header("Access-Control-Allow-Credentials: true");

    http_response_code(204);
    die();
}

// --- Routes ---

$router->set404('/api(/.*)?', function () {
    handleErr(new \Exception("API endpoint not found", 404));
});

// --- Root ---
$router->options('/', function() { handleOptionsRequest('GET'); });
$router->get('/', function () {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => 'API Root']);
});

// --- Auth Check (Checks JWT Validity) ---
$router->options('/check', function() { handleOptionsRequest('GET'); });
$router->get('/check', function () {
    header('Content-Type: application/json; charset=utf-8');
    // Use the helper function which now checks JWT validity
    ensureAuthenticated();
    // If ensureAuthenticated passes, we are logged in
    echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Authenticated"]);
});

// --- Registration (Unaffected by JWT login state) ---
$router->options('/register', function() { handleOptionsRequest('GET, POST'); });
$router->get('/register', function () {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // Assuming isRegisterEnabled and register methods are uncommented/available in APIAuth
        $api = new APIAuth();
        // Need to check if the method exists if it was commented out
        if (!method_exists($api, 'isRegisterEnabled')) {
             handleErr(new \Exception("Registration check feature not available.", 501)); // 501 Not Implemented
        }
        if ($api->isRegisterEnabled()) {
            echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Available"]);
        } else {
            throw new \Exception("Registration is disabled.", 503);
        }
    } catch (\Throwable $th) {
        handleErr($th);
    }
});
$router->post('/register', function () {
    header('Content-Type: application/json; charset=utf-8');
    $j = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || is_null($j)) { handleErr(new \InvalidArgumentException("Invalid JSON provided.", 400)); }
    if (empty($j["email"]) || empty($j["password"]) || empty($j["username"])) { handleErr(new \InvalidArgumentException("Missing required fields: email, password, username.", 400)); }

    try {
        $api = new APIAuth();
        // Need to check if the method exists if it was commented out
        if (!method_exists($api, 'register')) {
             handleErr(new \Exception("Registration feature not available.", 501)); // 501 Not Implemented
        }
        $result = $api->register($j["email"], $j["password"], $j["username"]);
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $result]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
});

// --- Login (Returns JWT) ---
$router->options('/log-in', function() { handleOptionsRequest('POST'); });
$router->post('/log-in', function () {
    header('Content-Type: application/json; charset=utf-8');
    $j = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || is_null($j)) { handleErr(new \InvalidArgumentException("Invalid JSON provided.", 400)); }
    if (empty($j["email"]) || empty($j["password"])) { handleErr(new \InvalidArgumentException("Missing required fields: email, password.", 400)); }

    try {
        $api = new APIAuth();
        // log_in now returns the JWT string on success
        $jwtToken = $api->log_in($j["email"], $j["password"]);

        // Return the token in the response data
        echo json_encode([
            'status' => ['code' => 200, 'message' => 'ok'],
            'data' => ['token' => $jwtToken] // Send token back to client
        ]);
    } catch (\Throwable $th) {
        handleErr($th); // Handles 401, 429, 500 etc.
    }
});

// --- Logout (Invalidates JWT via Header) ---
function handleLogOut() {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $api = new APIAuth();
        // logOut now reads token from header internally
        $api->logOut();
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Logged out"]);
    } catch (\Throwable $th) {
        // Catch 400 if no token provided, or 500 for DB errors
        handleErr($th);
    }
}
$router->options('/log-out', function() { handleOptionsRequest('GET, POST'); });
$router->get('/log-out', function () { handleLogOut(); });
$router->post('/log-out', function () { handleLogOut(); });


// --- Helper Functions ---
function checkGetParam(string $param, $default, int $filter = FILTER_DEFAULT, $options = null) {
    $value = filter_input(INPUT_GET, $param, $filter, $options);
    return $value ?? $default;
}

/**
 * Checks if the user is authenticated via JWT. Throws 401 Exception if not.
 */
function ensureAuthenticated(): void {
    // No need to set header here, called before route handler potentially outputs
    try {
        $api = new APIAuth();
        if (!$api->isLoggedIn()) {
            // Throw exception for handleErr to catch
            throw new \Exception('Unauthorized', 401);
        }
        // If logged in, do nothing, let the route handler continue
    } catch (\Throwable $th) {
        // Catch exceptions from APIAuth constructor or isLoggedIn/throw
        handleErr($th); // handleErr sets headers and dies
    }
}


// --- Favorites Routes (Protected by JWT check inside Favorites class) ---
$router->mount('/favorites', function () use ($router) {

    // OPTIONS /favorites
    $router->options('/', function() { handleOptionsRequest('GET, POST, DELETE'); });

    // GET /favorites
    $router->get('/', function () {
        header('Content-Type: application/json; charset=utf-8');
        try {
            // Favorites methods now internally call APIAuth->getUserId() which validates JWT
            $favorites = new Favorites();
            $data = $favorites->list();
            echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $data]);
        } catch (\Throwable $th) { handleErr($th); } // Catches 401 from getCurrentUserID if JWT invalid
    });

    // POST /favorites
    $router->post('/', function () {
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_null($input) || empty($input['file']) || !is_string($input['file'])) { handleErr(new \InvalidArgumentException("Missing or invalid 'file' parameter in JSON body.", 400)); }
        try {
            $favorites = new Favorites();
            $newId = $favorites->add($input['file']);
            http_response_code(201);
            echo json_encode(['status' => ['code' => 201, 'message' => 'created'], "data" => ['id' => $newId]]);
        } catch (\Throwable $th) { handleErr($th); } // Catches 401, 409, 500
    });

    // DELETE /favorites
    $router->delete('/', function () {
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_null($input) || empty($input['file']) || !is_string($input['file'])) { handleErr(new \InvalidArgumentException("Missing or invalid 'file' parameter in JSON body.", 400)); }
        try {
            $favorites = new Favorites();
            $removed = $favorites->remove($input['file']);
            if ($removed) {
                echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Favorite removed."]);
            } else {
                handleErr(new \Exception("Favorite not found for this user.", 404));
            }
        } catch (\Throwable $th) { handleErr($th); } // Catches 401, 404, 500
    });

    // OPTIONS /favorites/check
    $router->options('/check', function() { handleOptionsRequest('GET'); });

    // GET /favorites/check
    $router->get('/check', function () {
        header('Content-Type: application/json; charset=utf-8');
        $file = checkGetParam('file', null);
        if (empty($file) || !is_string($file)) { handleErr(new \InvalidArgumentException("Missing or invalid 'file' query parameter.", 400)); }
        try {
            $favorites = new Favorites();
            $isFav = $favorites->isFavorite($file);
            echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => ['isFavorite' => $isFav]]);
        } catch (\Throwable $th) { handleErr($th); } // Catches 401, 500
    });
}); // End of /favorites mount


// --- Run the router ---
$router->run();
