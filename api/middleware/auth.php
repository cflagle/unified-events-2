<?php

/**
 * Authentication Middleware
 * 
 * Handles API authentication for protected endpoints
 */

use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Utilities\Logger;

/**
 * Authenticate the current request
 */
function authenticate(): bool
{
    // Get authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    
    if (empty($authHeader)) {
        return false;
    }
    
    // Extract token
    $token = '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    } else {
        $token = $authHeader;
    }
    
    if (empty($token)) {
        return false;
    }
    
    // Validate token
    return validateApiKey($token);
}

/**
 * Validate API key
 */
function validateApiKey(string $apiKey): bool
{
    // Check static keys first (for system integrations)
    $staticKeys = [
        $_ENV['MASTER_API_KEY'] ?? null,
        $_ENV['DASHBOARD_API_KEY'] ?? null,
    ];
    
    if (in_array($apiKey, $staticKeys, true)) {
        logApiAccess($apiKey, 'static_key', true);
        return true;
    }
    
    // Check database for dynamic API keys
    $db = new Database();
    
    $key = $db->findOne('api_keys', [
        'api_key' => hash('sha256', $apiKey),
        'is_active' => 1
    ]);
    
    if (!$key) {
        logApiAccess($apiKey, 'unknown', false);
        return false;
    }
    
    // Check expiration
    if ($key['expires_at'] && strtotime($key['expires_at']) < time()) {
        logApiAccess($apiKey, $key['name'], false, 'expired');
        return false;
    }
    
    // Check rate limits for this key
    if (!checkApiKeyRateLimit($key)) {
        logApiAccess($apiKey, $key['name'], false, 'rate_limited');
        return false;
    }
    
    // Update last used
    $db->update('api_keys', [
        'last_used_at' => date('Y-m-d H:i:s'),
        'request_count' => ($key['request_count'] ?? 0) + 1
    ], ['id' => $key['id']]);
    
    // Store key info for later use
    $_REQUEST['api_key_id'] = $key['id'];
    $_REQUEST['api_key_name'] = $key['name'];
    $_REQUEST['api_key_permissions'] = json_decode($key['permissions'] ?? '[]', true);
    
    logApiAccess($apiKey, $key['name'], true);
    return true;
}

/**
 * Check rate limit for API key
 */
function checkApiKeyRateLimit(array $key): bool
{
    if (!isset($key['rate_limit']) || $key['rate_limit'] <= 0) {
        return true; // No rate limit
    }
    
    $db = new Database();
    $hourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
    
    $recentRequests = $db->count('api_access_log', [
        ['api_key_id', '=', $key['id']],
        ['created_at', '>=', $hourAgo],
        ['success', '=', 1]
    ]);
    
    return $recentRequests < $key['rate_limit'];
}

/**
 * Log API access attempt
 */
function logApiAccess(string $apiKey, string $keyName, bool $success, string $reason = ''): void
{
    $logger = new Logger();
    $db = new Database();
    
    // Log to file
    $logger->info('API access attempt', [
        'key_name' => $keyName,
        'success' => $success,
        'reason' => $reason,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ]);
    
    // Log to database
    try {
        $db->insert('api_access_log', [
            'api_key_id' => $_REQUEST['api_key_id'] ?? null,
            'api_key_hash' => substr(hash('sha256', $apiKey), 0, 8),
            'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'success' => $success ? 1 : 0,
            'reason' => $reason,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        // Don't fail the request if logging fails
    }
}

/**
 * Check if current user has permission
 */
function hasPermission(string $permission): bool
{
    $permissions = $_REQUEST['api_key_permissions'] ?? [];
    
    // Check for wildcard permission
    if (in_array('*', $permissions)) {
        return true;
    }
    
    // Check specific permission
    return in_array($permission, $permissions);
}

/**
 * Get current API key info
 */
function getCurrentApiKey(): ?array
{
    if (!isset($_REQUEST['api_key_id'])) {
        return null;
    }
    
    return [
        'id' => $_REQUEST['api_key_id'],
        'name' => $_REQUEST['api_key_name'] ?? 'Unknown',
        'permissions' => $_REQUEST['api_key_permissions'] ?? []
    ];
}