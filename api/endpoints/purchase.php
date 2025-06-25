<?php

/**
 * Purchase Event Endpoint
 * 
 * Processes purchase events
 * Maintains compatibility with existing purchase processing system
 */

use UnifiedEvents\Services\EventProcessor;
use UnifiedEvents\Utilities\Logger;

// Initialize services
$logger = new Logger();
$processor = new EventProcessor();

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

// Map all fields from your purchase processing file
$mappedData = [
    // Contact information
    'first_name' => $requestData['first_name'] ?? '',
    'email' => $requestData['email'] ?? '',
    'phone' => $requestData['phone_number'] ?? $requestData['phone'] ?? '',
    
    // Purchase-specific fields
    'offer' => $requestData['offer'] ?? '',
    'publisher' => $requestData['publisher'] ?? '',
    'amount' => $requestData['amt'] ?? '',
    
    // Traffic/campaign data
    'traffic_source' => $requestData['traffic_source'] ?? '',
    'purchase_creative' => $requestData['purchase_creative'] ?? '',
    'purchase_campaign' => $requestData['purchase_campaign'] ?? '',
    'purchase_content' => $requestData['purchase_content'] ?? '',
    'purchase_term' => $requestData['purchase_term'] ?? '',
    'traffic_source_account' => $requestData['traffic_source_account'] ?? '',
    'purchase_source' => $requestData['purchase_source'] ?? '',
    'purchase_lp' => $requestData['purchase_lp'] ?? '',
    'sid202' => $requestData['sid202'] ?? '',
    'source_site' => $requestData['source_site'] ?? '',
    
    // IP and tracking
    'ip_address' => $requestData['ipv4'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
    'ipv4' => $requestData['ipv4'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
    
    // Original acquisition data (from lead)
    'acq_source' => $requestData['acq_source'] ?? '',
    'acq_campaign' => $requestData['acq_campaign'] ?? '',
    'acq_term' => $requestData['acq_term'] ?? '',
    'acq_date' => $requestData['acq_date'] ?? '',
    'acq_form_title' => $requestData['acq_form_title'] ?? '',
    
    // Additional tracking
    'gclid' => $requestData['gclid'] ?? '',
    'ga_client_id' => $requestData['ga_client_id'] ?? '',
    'zb_last_active' => $requestData['zb_last_active'] ?? '',
    
    // MD5 email
    'email_md5' => $requestData['md5'] ?? 
                  (isset($requestData['email']) ? md5(strtolower(trim($requestData['email']))) : '')
];

// Process the event
$startTime = microtime(true);

try {
    // Log the request
    $logger->logEvent(
        'FileRequest',
        $mappedData['email'] ?: 'unknown',
        'Purchase processing initiated',
        'success'
    );
    
    // Process through our event processor
    $result = $processor->processEvent($mappedData, 'purchase');
    
    // Prepare response
    $response = [
        'success' => $result['success'],
        'event_id' => $result['event_id'] ?? null,
        'status' => $result['success'] ? 'queued' : 'failed',
        'job' => $result['event_id'] ?? null, // For compatibility
        'processing_time' => round(microtime(true) - $startTime, 3)
    ];
    
    if (!$result['success']) {
        $response['error'] = $result['error'] ?? 'Processing failed';
    }
    
    // Log metric (matching your log_metric function)
    $logLine = sprintf(
        "%s | Queued Purchase | %s\n",
        date('Y-m-d H:i:s'),
        json_encode([
            'email' => $mappedData['email'],
            'ipv4' => $mappedData['ipv4'],
            'offer' => $mappedData['offer'],
            'publisher' => $mappedData['publisher']
        ])
    );
    
    $logPath = Logger::getLogPath() . 'queue_log.txt';
    file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
    
    // Send response
    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($response);
    
} catch (Exception $e) {
    $logger->error('Purchase endpoint error', [
        'error' => $e->getMessage(),
        'email' => $mappedData['email'] ?? 'unknown',
        'offer' => $mappedData['offer'] ?? 'unknown'
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'error' => 'Internal server error',
        'processing_time' => round(microtime(true) - $startTime, 3)
    ]);
}