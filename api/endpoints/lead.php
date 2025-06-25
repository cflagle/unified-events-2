<?php

/**
 * Lead Event Endpoint
 * 
 * Processes lead form submissions
 * Maintains compatibility with existing lead processing system
 */

use UnifiedEvents\Services\EventProcessor;
use UnifiedEvents\Services\ValidationService;
use UnifiedEvents\Utilities\Logger;

// Initialize services
$logger = new Logger();
$processor = new EventProcessor();
$validator = new ValidationService();

// Get request data
$requestData = array_merge($_GET, $_POST, $_REQUEST);

// If JSON payload, merge it too
$jsonInput = file_get_contents('php://input');
if ($jsonInput) {
    $jsonData = json_decode($jsonInput, true);
    if (is_array($jsonData)) {
        $requestData = array_merge($requestData, $jsonData);
    }
}

// Get IP address (matching your getUserIP function)
function getUserIP() {
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
            return $_SERVER[$key];
        }
    }
    return '0.0.0.0';
}

// Map fields to match your existing system
$mappedData = [
    // Core fields
    'email' => $requestData['email'] ?? '',
    'name' => $requestData['name'] ?? '',
    'phone' => $requestData['phone'] ?? '',
    'phone_number' => $requestData['phone'] ?? '', // Support both field names
    
    // Attribution fields
    'source' => $requestData['source'] ?? $requestData['utm_source'] ?? '',
    'medium' => $requestData['medium'] ?? $requestData['utm_medium'] ?? '',
    'campaign' => $requestData['campaign'] ?? $requestData['utm_campaign'] ?? '',
    'content' => $requestData['content'] ?? $requestData['utm_content'] ?? '',
    'term' => $requestData['term'] ?? $requestData['utm_term'] ?? '',
    
    // Form-specific fields
    'form_title' => $requestData['formTitle'] ?? $requestData['form_title'] ?? '',
    'gclid' => $requestData['gclid'] ?? '',
    
    // Tracking fields
    'ga_client_id' => $requestData['gac'] ?? $_COOKIE['_ga'] ?? '0',
    'woo_tracker' => $requestData['woo-tracker'] ?? $_COOKIE['wooTracker'] ?? '0',
    'timezone' => $requestData['timezone'] ?? '0',
    
    // IP address
    'ip_address' => getUserIP(),
    'ipv4' => getUserIP(),
    
    // Honeypot fields (for bot detection)
    'zipcode' => $requestData['zipcode'] ?? '',
    'phonenumber' => $requestData['phonenumber'] ?? '',
    
    // Additional data
    'confirmation_page' => $requestData['coregLink'] ?? 'https://wallstwatchdogs.com'
];

// Format phone number (matching your formatNumber function)
if (!empty($mappedData['phone'])) {
    $formattedPhone = $validator->formatPhoneNumber($mappedData['phone']);
    if ($formattedPhone) {
        $mappedData['phone'] = $formattedPhone;
        $mappedData['phone_number'] = $formattedPhone;
    }
}

// Process name into first/last
if (!empty($mappedData['name'])) {
    $name = trim(preg_replace('/\s+/', ' ', $mappedData['name']));
    $nameParts = explode(' ', $name);
    $mappedData['first_name'] = array_shift($nameParts) ?: '';
    $mappedData['last_name'] = array_pop($nameParts) ?: '';
} else {
    $mappedData['first_name'] = $requestData['first_name'] ?? 'Reader';
    $mappedData['last_name'] = $requestData['last_name'] ?? '';
}

// Build redirect URL (before async processing)
$confirmationUrl = $mappedData['confirmation_page'];
$confirmationUrl = htmlspecialchars_decode($confirmationUrl);

// Add parameters to confirmation URL
$urlParams = [
    'fn' => $mappedData['first_name'],
    'ln' => $mappedData['last_name'],
    'c2' => $mappedData['email'],
    'c4' => $mappedData['woo_tracker'],
    'utm_source' => $mappedData['source'],
    'utm_term' => $mappedData['term'],
    'utm_campaign' => $mappedData['campaign'],
    'utm_content' => $mappedData['content'],
    'fid' => $mappedData['form_title'],
    'md5' => md5(strtolower(trim($mappedData['email']))),
    'email' => $mappedData['email']
];

// Add phone confirmation flag
if (strlen($mappedData['phone']) > 10) {
    $urlParams['c3'] = 'ph-confirmed';
    $urlParams['phone'] = substr($mappedData['phone'], 1); // Remove country code
}

// Add gclid if valid
if (!empty($mappedData['gclid']) && $mappedData['gclid'] !== 'undefined' && $mappedData['gclid'] !== '0') {
    $urlParams['gclid'] = $mappedData['gclid'];
}

// Build final URL
foreach ($urlParams as $key => $value) {
    if (!empty($value)) {
        $confirmationUrl = add_query_arg($key, $value, $confirmationUrl);
    }
}

// Helper function to add query args
function add_query_arg($key, $value, $url) {
    $separator = (parse_url($url, PHP_URL_QUERY) === null) ? '?' : '&';
    return $url . $separator . urlencode($key) . '=' . urlencode($value);
}

// Set cookies (before any output)
if (!empty($mappedData['name'])) {
    setcookie('grName', $mappedData['name'], time() + (3600 * 24 * 730), '/', '.wallstwatchdogs.com', false);
}
if (!empty($mappedData['email'])) {
    setcookie('gr_email', $mappedData['email'], time() + (3600 * 24 * 730), '/', '.wallstwatchdogs.com', false);
    setcookie('md_email', md5(strtolower(trim($mappedData['email']))), time() + (3600 * 24 * 730), '/', '.wallstwatchdogs.com', false);
}
if (!empty($mappedData['woo_tracker'])) {
    setcookie('wooTracker', $mappedData['woo_tracker'], time() + (3600 * 24 * 730), '/', '.wallstwatchdogs.com', false);
}
if (!empty($mappedData['ga_client_id']) && $mappedData['ga_client_id'] !== '0') {
    setcookie('_ga', $mappedData['ga_client_id'], time() + (3600 * 24 * 730), '/', '.wallstwatchdogs.com', false);
}

// Process the event
$startTime = microtime(true);

try {
    // Process through our event processor
    $result = $processor->processEvent($mappedData, 'lead');
    
    // Prepare response
    $response = [
        'success' => $result['success'],
        'event_id' => $result['event_id'] ?? null,
        'redirect_url' => $confirmationUrl,
        'processing_time' => round(microtime(true) - $startTime, 3)
    ];
    
    // If this is an AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        
        http_response_code($result['success'] ? 200 : 400);
        echo json_encode($response);
        exit;
    }
    
    // Otherwise, redirect (matching your current behavior)
    ignore_user_abort(true);
    set_time_limit(0);
    
    ob_start();
    header("Location: " . $confirmationUrl);
    header("Content-Encoding: none");
    header("Content-Length: " . ob_get_length());
    header("Connection: close");
    
    ob_end_flush();
    flush();
    
    // Continue processing in background
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    if (session_id()) {
        session_write_close();
    }
    
} catch (Exception $e) {
    $logger->error('Lead endpoint error', [
        'error' => $e->getMessage(),
        'email' => $mappedData['email'] ?? 'unknown'
    ]);
    
    // Still redirect on error
    header("Location: " . $confirmationUrl);
    exit;
}

// Log successful processing
$logger->info('Lead processed successfully', [
    'email' => $mappedData['email'] ?? 'unknown',
    'event_id' => $result['event_id'] ?? null,
    'processing_time' => round(microtime(true) - $startTime, 3)
]);