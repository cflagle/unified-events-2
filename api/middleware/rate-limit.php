<?php

/**
 * Rate Limiting Middleware
 * 
 * Prevents abuse by limiting requests per IP/email
 */

use UnifiedEvents\Utilities\Database;
use Predis\Client as Redis;

/**
 * Check if the current request exceeds rate limits
 */
function checkRateLimit(): bool
{
    // Skip rate limiting in development
    if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
        return true;
    }
    
    // Get identifier (IP address or email)
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $email = $_REQUEST['email'] ?? null;
    
    // Rate limit configuration
    $limits = [
        'ip' => [
            'requests' => 100,  // 100 requests
            'window' => 3600    // per hour
        ],
        'email' => [
            'requests' => 10,   // 10 requests
            'window' => 3600    // per hour
        ],
        'burst' => [
            'requests' => 10,   // 10 requests
            'window' => 60      // per minute
        ]
    ];
    
    try {
        // Try Redis first for better performance
        if (class_exists('Predis\Client')) {
            $redis = new Redis([
                'scheme' => 'tcp',
                'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                'port' => $_ENV['REDIS_PORT'] ?? 6379,
                'password' => $_ENV['REDIS_PASSWORD'] ?? null,
            ]);
            
            return checkRateLimitRedis($redis, $ip, $email, $limits);
        }
    } catch (Exception $e) {
        // Fall back to database
    }
    
    // Use database-based rate limiting
    return checkRateLimitDatabase($ip, $email, $limits);
}

/**
 * Check rate limit using Redis
 */
function checkRateLimitRedis($redis, string $ip, ?string $email, array $limits): bool
{
    $now = time();
    
    // Check IP rate limit
    $ipKey = "rate_limit:ip:$ip";
    $ipCount = $redis->incr($ipKey);
    if ($ipCount == 1) {
        $redis->expire($ipKey, $limits['ip']['window']);
    }
    if ($ipCount > $limits['ip']['requests']) {
        return false;
    }
    
    // Check burst limit
    $burstKey = "rate_limit:burst:$ip";
    $burstCount = $redis->incr($burstKey);
    if ($burstCount == 1) {
        $redis->expire($burstKey, $limits['burst']['window']);
    }
    if ($burstCount > $limits['burst']['requests']) {
        return false;
    }
    
    // Check email rate limit if provided
    if ($email) {
        $emailKey = "rate_limit:email:" . md5(strtolower($email));
        $emailCount = $redis->incr($emailKey);
        if ($emailCount == 1) {
            $redis->expire($emailKey, $limits['email']['window']);
        }
        if ($emailCount > $limits['email']['requests']) {
            return false;
        }
    }
    
    return true;
}

/**
 * Check rate limit using database
 */
function checkRateLimitDatabase(string $ip, ?string $email, array $limits): bool
{
    $db = new Database();
    $now = time();
    
    // Clean old entries
    $db->execute(
        "DELETE FROM rate_limit WHERE created_at < ?",
        [date('Y-m-d H:i:s', $now - 3600)]
    );
    
    // Check IP limit
    $ipCount = $db->count('rate_limit', [
        ['identifier', '=', $ip],
        ['created_at', '>=', date('Y-m-d H:i:s', $now - $limits['ip']['window'])]
    ]);
    
    if ($ipCount >= $limits['ip']['requests']) {
        return false;
    }
    
    // Check burst limit
    $burstCount = $db->count('rate_limit', [
        ['identifier', '=', $ip],
        ['created_at', '>=', date('Y-m-d H:i:s', $now - $limits['burst']['window'])]
    ]);
    
    if ($burstCount >= $limits['burst']['requests']) {
        return false;
    }
    
    // Check email limit
    if ($email) {
        $emailHash = md5(strtolower($email));
        $emailCount = $db->count('rate_limit', [
            ['identifier', '=', $emailHash],
            ['created_at', '>=', date('Y-m-d H:i:s', $now - $limits['email']['window'])]
        ]);
        
        if ($emailCount >= $limits['email']['requests']) {
            return false;
        }
        
        // Record email request
        $db->insert('rate_limit', [
            'identifier' => $emailHash,
            'type' => 'email',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    // Record IP request
    $db->insert('rate_limit', [
        'identifier' => $ip,
        'type' => 'ip',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    return true;
}

/**
 * Get rate limit headers for response
 */
function getRateLimitHeaders(): array
{
    return [
        'X-RateLimit-Limit' => '100',
        'X-RateLimit-Remaining' => '99', // Would calculate actual remaining
        'X-RateLimit-Reset' => time() + 3600
    ];
}