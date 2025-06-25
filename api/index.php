<?php

/**
 * Unified Events API Router
 * 
 * This is the main entry point for all API requests
 * Routes requests to appropriate endpoints based on the URL path
 */

// Load environment variables
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Utilities\Logger;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize database
Database::initialize([
    'host' => $_ENV['DB_HOST'],
    'port' => $_ENV['DB_PORT'],
    'database' => $_ENV['DB_DATABASE'],
    'username' => $_ENV['DB_USERNAME'],
    'password' => $_ENV['DB_PASSWORD']
]);

// Set timezone
date_default_timezone_set('America/New_York');

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Global exception handler
set_exception_handler(function($exception) {
    $logger = new Logger();
    $logger->error('Uncaught exception in API', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'request_id' => uniqid('err_')
    ]);
});

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Remove query string
$requestUri = strtok($requestUri, '?');

// Handle subdirectory installation
// Remove everything up to and including /api
if (preg_match('#/api(/.*)?$#', $requestUri, $matches)) {
    $path = $matches[1] ?? '/';
} else {
    $path = '/';
}

// Clean path
$path = '/' . trim($path, '/');

// CORS headers (matching your current setup)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Route mapping
$routes = [
    'POST' => [
        '/events/lead' => __DIR__ . '/endpoints/lead.php',
        '/events/purchase' => __DIR__ . '/endpoints/purchase.php',
        '/events/email-open' => __DIR__ . '/endpoints/email-open.php',
        '/events/email-click' => __DIR__ . '/endpoints/email-click.php',
    ],
    'GET' => [
        '/health' => __DIR__ . '/endpoints/health.php',
        '/stats' => __DIR__ . '/endpoints/stats.php',
    ]
];

// Find matching route
$endpoint = null;
if (isset($routes[$method][$path])) {
    $endpoint = $routes[$method][$path];
} else {
    // Try pattern matching for dynamic routes
    foreach ($routes[$method] ?? [] as $pattern => $file) {
        if (preg_match('#^' . str_replace('/', '\/', $pattern) . '$#', $path, $matches)) {
            $endpoint = $file;
            $_REQUEST['route_params'] = $matches;
            break;
        }
    }
}

// Load middleware
$middlewarePath = __DIR__ . '/middleware/';

// Apply rate limiting
require_once $middlewarePath . 'rate-limit.php';
if (!checkRateLimit()) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Rate limit exceeded',
        'retry_after' => 60
    ]);
    exit;
}

// Apply authentication if needed
require_once $middlewarePath . 'auth.php';
$publicRoutes = ['/events/lead', '/events/purchase', '/health']; // Public endpoints

// Debug: Log the path being checked
error_log("Checking path: " . $path . " against public routes");

if (!in_array($path, $publicRoutes) && !authenticate()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'path' => $path, // Include path in response for debugging
        'public_routes' => $publicRoutes
    ]);
    exit;
}

// Route to endpoint
if ($endpoint && file_exists($endpoint)) {
    // Set default response type
    header('Content-Type: application/json');
    
    // Include the endpoint file
    require_once $endpoint;
} else {
    // 404 Not Found
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Endpoint not found',
        'path' => $path,
        'method' => $method
    ]);
}