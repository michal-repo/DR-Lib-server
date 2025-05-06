<?php

namespace Router;

// Session cookie path setting is no longer relevant for JWT auth state
// $cookiePath = empty($_ENV['domain_path']) ? '/' : $_ENV['domain_path'];
// \ini_set('session.cookie_path', $cookiePath);

require_once 'vendor/autoload.php';

use DRLib\Auth\APIAuth;
use DRLib\Actions\Favorites;
use DRLib\Actions\Files;
use DRLib\Actions\Catalogs;
use \Bramus\Router\Router as BRouter;
use Dotenv\Dotenv as Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();


$router = new BRouter();

// --- Enhanced Error Handler ---
// (Keep the existing handleErr function as is)
function handleErr(\Throwable $th) {
    // ... (your existing handleErr function code) ...
    header('Content-Type: application/json; charset=utf-8');
    $code = $th->getCode();
    $message = $th->getMessage();
    $httpStatusCode = 500;
    $jsonMessage = 'Internal Server Error';

    switch ($code) {
        case 400:
            $httpStatusCode = 400;
            $jsonMessage = $message ?: 'Bad Request';
            break;
        case 401:
            $httpStatusCode = 401;
            $jsonMessage = $message ?: 'Unauthorized';
            break; // Used for invalid/missing JWT
        case 404:
            $httpStatusCode = 404;
            $jsonMessage = $message ?: 'Not Found';
            break;
        case 409:
            $httpStatusCode = 409;
            $jsonMessage = $message ?: 'Conflict';
            break;
        case 503:
            $httpStatusCode = 503;
            $jsonMessage = $message ?: 'Service Unavailable';
            break;
        case 429:
            $httpStatusCode = 429;
            $jsonMessage = $message ?: 'Too Many Requests';
            break;
        // Add case for 500 if you want a specific message for generic 500s thrown by your code
        case 500:
            $httpStatusCode = 500;
            $jsonMessage = $message ?: 'Internal Server Error';
            break;
        default:
            // If the code is not one of the specific ones, treat it as a generic 500
            if ($code < 100 || $code >= 600) { // Basic check for valid HTTP status code range
                $httpStatusCode = 500;
                $jsonMessage = 'Internal Server Error';
            } else {
                $httpStatusCode = $code;
                $jsonMessage = $message ?: 'Server Error'; // Use the message if it's a valid HTTP code
            }
            break;
    }


    http_response_code($httpStatusCode);

    if (isset($_ENV['debug']) && $_ENV['debug'] === "true") {
        // Detailed error in debug mode
        echo json_encode([
            'status' => ['code' => $httpStatusCode, 'message' => $message], // Use the original message for debug
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
            @mkdir('logs', 0775, true);
            @file_put_contents('logs/errors.log', $logEntry . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        echo json_encode(['status' => ['code' => $httpStatusCode, 'message' => $jsonMessage]]); // Use the generic message for production
    }
    die();
}


// --- CORS OPTIONS Request Handler ---
// (Keep the existing handleOptionsRequest function as is)
function handleOptionsRequest(string $allowedMethods) {
    // ... (your existing handleOptionsRequest function code) ...
    $allowedOrigin = $_ENV['CORS_ALLOWED_ORIGIN'] ?? '*';
    $allowedHeaders = $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type, Authorization, X-Requested-With';
    $maxAge = $_ENV['CORS_MAX_AGE'] ?? '86400';

    header("Access-Control-Allow-Origin: {$allowedOrigin}");
    header("Access-Control-Allow-Methods: {$allowedMethods}, OPTIONS");
    header("Access-Control-Allow-Headers: {$allowedHeaders}");
    header("Access-Control-Max-Age: {$maxAge}");

    http_response_code(204);
    die();
}

// --- Routes ---

$router->set404('/api(/.*)?', function () {
    handleErr(new \Exception("API endpoint not found", 404));
});

// --- Root ---
$router->options('/', function () {
    handleOptionsRequest('GET');
});
$router->get('/', function () {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => 'API Root']);
});

// --- Auth Check (Checks JWT Validity) ---
$router->options('/check', function () {
    handleOptionsRequest('GET');
});
$router->get('/check', function () {
    header('Content-Type: application/json; charset=utf-8');
    // Use the helper function which now checks JWT validity
    ensureAuthenticated();
    // If ensureAuthenticated passes, we are logged in
    echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Authenticated"]);
});

// --- Registration (Unaffected by JWT login state) ---
$router->options('/register', function () {
    handleOptionsRequest('GET, POST');
});
$router->get('/register', function () {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $api = new APIAuth();
        if (!method_exists($api, 'isRegisterEnabled')) {
            handleErr(new \Exception("Registration check feature not available.", 501));
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
    if (json_last_error() !== JSON_ERROR_NONE || is_null($j)) {
        handleErr(new \InvalidArgumentException("Invalid JSON provided.", 400));
    }
    if (empty($j["email"]) || empty($j["password"]) || empty($j["username"])) {
        handleErr(new \InvalidArgumentException("Missing required fields: email, password, username.", 400));
    }

    try {
        $api = new APIAuth();
        if (!method_exists($api, 'register')) {
            handleErr(new \Exception("Registration feature not available.", 501));
        }
        $result = $api->register($j["email"], $j["password"], $j["username"]);
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $result]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
});

// --- Login (Returns JWT) ---
$router->options('/log-in', function () {
    handleOptionsRequest('POST');
});
$router->post('/log-in', function () {
    header('Content-Type: application/json; charset=utf-8');
    $j = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || is_null($j)) {
        handleErr(new \InvalidArgumentException("Invalid JSON provided.", 400));
    }
    if (empty($j["email"]) || empty($j["password"])) {
        handleErr(new \InvalidArgumentException("Missing required fields: email, password.", 400));
    }

    try {
        $api = new APIAuth();
        $jwtToken = $api->log_in($j["email"], $j["password"]);

        echo json_encode([
            'status' => ['code' => 200, 'message' => 'ok'],
            'data' => ['token' => $jwtToken]
        ]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
});

// --- Logout (Invalidates JWT via Header) ---
function handleLogOut() {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $api = new APIAuth();
        $api->logOut();
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Logged out"]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
}
$router->options('/log-out', function () {
    handleOptionsRequest('GET, POST');
});
$router->get('/log-out', function () {
    handleLogOut();
});
$router->post('/log-out', function () {
    handleLogOut();
});


// --- Helper Functions ---
// (Keep the existing checkGetParam function as is)
function checkGetParam(string $param, $default, int $filter = FILTER_DEFAULT, $options = null) {
    // Ensure default filter is used if none specified that makes sense for general use
    if ($filter !== FILTER_VALIDATE_INT && $filter !== FILTER_VALIDATE_FLOAT && $filter !== FILTER_VALIDATE_BOOLEAN && $filter !== FILTER_VALIDATE_EMAIL && $filter !== FILTER_VALIDATE_URL) {
        $filter = FILTER_DEFAULT; // Sanitize string by default
    }

    // For FILTER_DEFAULT, add STRIP_LOW and STRIP_HIGH flags to prevent potential XSS or control character issues
    if ($filter === FILTER_DEFAULT && $options === null) {
        $options = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH;
    } elseif ($filter === FILTER_DEFAULT && is_array($options) && !isset($options['flags'])) {
        $options['flags'] = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH;
    } elseif ($filter === FILTER_DEFAULT && is_int($options)) { // If options is just flags
        $options |= FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH;
    }


    $value = filter_input(INPUT_GET, $param, $filter, $options);

    // Check if filter_input failed or returned null
    // For boolean, false is a valid value, so we check specifically for null/false failure
    if ($filter === FILTER_VALIDATE_BOOLEAN) {
        if ($value === null) { // Parameter not present or invalid boolean string
            // Check if parameter exists but is invalid, return default. If not present, also return default.
            if (filter_has_var(INPUT_GET, $param)) {
                // Parameter exists but wasn't a valid boolean representation
                return $default;
            }
            return $default; // Parameter not present
        }
        // filter_input returns true for "1", "true", "on", "yes". Returns false for "0", "false", "off", "no", "". Null otherwise.
        return (bool) $value;
    } elseif ($value === null || $value === false) {
        // For other filters, null or false indicates failure or parameter not set
        // Check if the parameter was actually present but failed validation
        if (filter_has_var(INPUT_GET, $param) && $value === false) {
            // Parameter was present but invalid according to the filter
            return $default; // Return default if validation failed
        }
        // Parameter was not present or filter returned null for other reasons
        return $default;
    }

    // Return the filtered value
    return $value;
}


/**
 * Checks if the user is authenticated via JWT. Throws 401 Exception if not.
 */
function ensureAuthenticated(): void {
    try {
        $api = new APIAuth();
        if (!$api->isLoggedIn()) {
            throw new \Exception('Unauthorized', 401);
        }
    } catch (\Throwable $th) {
        // Re-throw with appropriate code if needed, or let handleErr manage it
        if ($th->getCode() !== 401) { // If the auth check itself failed unexpectedly
            handleErr(new \Exception('Authentication check failed.', 500, $th));
        } else {
            handleErr($th); // Pass the original 401 exception
        }
    }
}


// --- Favorites Routes (Protected by JWT check inside Favorites class) ---
$router->options('/favorites-catalogs', function () {
    handleOptionsRequest('GET');
});
$router->get('/favorites-catalogs', function () {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // Get pagination and search parameters from the query string
        $page = checkGetParam('page', 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $size = checkGetParam('size', 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $thumbLimit = checkGetParam('thumbnails', 3, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $searchQuery = checkGetParam('search', null);
        $favorites = new Favorites();
        $data = $favorites->listFavoriteCatalogs($page, $size, $thumbLimit, $searchQuery);
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $data]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
});

$router->options('/favorites-by-catalog', function () {
    handleOptionsRequest('GET');
});
$router->get('/favorites-by-catalog', function () {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // Get pagination parameters from the query string using the helper
        $page = checkGetParam('page', 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $size = checkGetParam('size', 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        // The $catalogDirectory is required by the backend method; Favorites class will throw 400 if empty.
        $catalogDirectory = checkGetParam('directory', null); 
        // Get the optional search query for files within the catalog
        $searchQuery = checkGetParam('search', null); 

        $favorites = new Favorites();
        $data = $favorites->listFavoritesByCatalog($catalogDirectory, $page, $size, $searchQuery);
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $data]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
});

$router->mount('/favorites', function () use ($router) {
    // ... (your existing /favorites routes) ...
    // OPTIONS /favorites
    $router->options('/', function () {
        handleOptionsRequest('GET, POST, DELETE');
    });

    // GET /favorites
    $router->get('/', function () {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $favorites = new Favorites();
            $data = $favorites->list();
            echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $data]);
        } catch (\Throwable $th) {
            handleErr($th);
        }
    });

    // POST /favorites
    $router->post('/', function () {
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_null($input) || empty($input['file']) || !is_string($input['file']) || empty($input['thumbnail']) || !is_string($input['thumbnail'])) {
            handleErr(new \InvalidArgumentException("Missing or invalid 'file' or 'thumbnail' parameter in JSON body.", 400));
        }
        try {
            $favorites = new Favorites();
            $newId = $favorites->add($input['file'], $input['thumbnail']);
            http_response_code(201);
            echo json_encode(['status' => ['code' => 201, 'message' => 'created'], "data" => ['id' => $newId]]);
        } catch (\Throwable $th) {
            handleErr($th);
        }
    });

    // DELETE /favorites
    $router->delete('/', function () {
        header('Content-Type: application/json; charset=utf-8');
        $input = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_null($input) || empty($input['file']) || !is_string($input['file'])) {
            handleErr(new \InvalidArgumentException("Missing or invalid 'file' parameter in JSON body.", 400));
        }
        try {
            $favorites = new Favorites();
            $removed = $favorites->remove($input['file']);
            if ($removed) {
                echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Favorite removed."]);
            } else {
                handleErr(new \Exception("Favorite not found for this user.", 404));
            }
        } catch (\Throwable $th) {
            handleErr($th);
        }
    });

    // OPTIONS /favorites/check
    $router->options('/check', function () {
        handleOptionsRequest('GET');
    });

    // GET /favorites/check
    $router->get('/check', function () {
        header('Content-Type: application/json; charset=utf-8');
        $file = checkGetParam('file', null);
        if (empty($file) || !is_string($file)) {
            handleErr(new \InvalidArgumentException("Missing or invalid 'file' query parameter.", 400));
        }
        try {
            $favorites = new Favorites();
            $isFav = $favorites->isFavorite($file);
            echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => ['isFavorite' => $isFav]]);
        } catch (\Throwable $th) {
            handleErr($th);
        }
    });
}); // End of /favorites mount


// --- Route for Listing Reference Files with Pagination ---
$router->options('/reference-files', function () {
    handleOptionsRequest('GET');
});
$router->get('/reference-files', function () {
    header('Content-Type: application/json; charset=utf-8');

    try {
        // Get pagination parameters from the query string using the helper
        // Defaulting to 1 and 20 respectively
        $page = checkGetParam('page', 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $size = checkGetParam('size', 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        // Get the directory filter parameter (named 'catalog' in the URL)
        $catalog = checkGetParam('catalog', null); // Default filter is fine for strings, default is null

        // Instantiate the Files class
        $filesHandler = new Files();

        // Call the listing method, passing the catalog filter
        $fileData = $filesHandler->listReferenceFilesPaginated($page, $size, $catalog); // Pass $catalog here

        // Output the data as JSON
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $fileData]);
    } catch (\Throwable $th) {
        // Catch any exceptions
        handleErr($th); // Use the existing error handler
    }
});

// --- NEW Route for Listing Catalogs with Pagination ---
$router->options('/catalogs', function () {
    handleOptionsRequest('GET');
});
$router->get('/catalogs', function () {
    header('Content-Type: application/json; charset=utf-8');

    try {
        // Get pagination parameters from the query string using the helper
        $page = checkGetParam('page', 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $size = checkGetParam('size', 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $thumbLimit = checkGetParam('thumbnails', 3, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        // --- Get the optional search query parameter ---
        $searchQuery = checkGetParam('search', null); // Default filter is fine, default is null

        // Instantiate the Catalog class
        $catalogHandler = new Catalogs();

        // Call the listing method, passing the search query
        $catalogData = $catalogHandler->listCatalogsPaginated($page, $size, $thumbLimit, $searchQuery); // Pass $searchQuery here

        // Output the data as JSON
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $catalogData]);
    } catch (\Throwable $th) {
        // Catch any exceptions
        handleErr($th); // Use the existing error handler
    }
});


// --- Run the router ---
$router->run();
