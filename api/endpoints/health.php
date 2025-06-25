<?php

/**
 * Health Check Endpoint
 * 
 * Provides system health status and diagnostics
 */

use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Services\QueueService;
use UnifiedEvents\Services\RouterService;
use UnifiedEvents\Platforms\PlatformFactory;
use Predis\Client as Redis;

$startTime = microtime(true);
$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => $_ENV['APP_VERSION'] ?? '1.0.0',
    'checks' => []
];

// Check database connection
try {
    $db = new Database();
    $result = $db->query("SELECT 1 as test");
    
    $health['checks']['database'] = [
        'status' => 'healthy',
        'response_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ];
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['database'] = [
        'status' => 'unhealthy',
        'error' => $e->getMessage()
    ];
}

// Check Redis connection
try {
    $redis = new Redis([
        'scheme' => 'tcp',
        'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['REDIS_PORT'] ?? 6379,
        'password' => $_ENV['REDIS_PASSWORD'] ?? null,
    ]);
    
    $redisStart = microtime(true);
    $redis->ping();
    
    $health['checks']['redis'] = [
        'status' => 'healthy',
        'response_time' => round((microtime(true) - $redisStart) * 1000, 2) . 'ms'
    ];
} catch (Exception $e) {
    // Redis is optional, so don't mark overall health as unhealthy
    $health['checks']['redis'] = [
        'status' => 'unavailable',
        'error' => 'Redis not configured or unavailable'
    ];
}

// Check queue health
try {
    $queueService = new QueueService();
    $queueHealth = $queueService->checkHealth();
    
    $health['checks']['queue'] = [
        'status' => $queueHealth['healthy'] ? 'healthy' : 'unhealthy',
        'issues' => $queueHealth['issues'],
        'stats' => [
            'pending' => $queueHealth['stats']['by_status']['pending'] ?? 0,
            'processing' => $queueHealth['stats']['by_status']['processing'] ?? 0,
            'failed' => $queueHealth['stats']['by_status']['failed'] ?? 0
        ]
    ];
    
    if (!$queueHealth['healthy']) {
        $health['status'] = 'degraded';
    }
} catch (Exception $e) {
    $health['checks']['queue'] = [
        'status' => 'error',
        'error' => $e->getMessage()
    ];
}

// Check platform connections
$platformChecks = [];
$router = new RouterService();

// Test critical platforms
$criticalPlatforms = ['zerobounce', 'activecampaign', 'woopra'];
foreach ($criticalPlatforms as $platformCode) {
    try {
        $platformConfig = $router->getPlatformByCode($platformCode);
        if ($platformConfig && $platformConfig['is_active']) {
            $platform = PlatformFactory::create($platformCode, $platformConfig);
            
            $platformStart = microtime(true);
            $connected = $platform->testConnection();
            
            $platformChecks[$platformCode] = [
                'status' => $connected ? 'healthy' : 'unhealthy',
                'response_time' => round((microtime(true) - $platformStart) * 1000, 2) . 'ms'
            ];
            
            if (!$connected) {
                $health['status'] = 'degraded';
            }
        }
    } catch (Exception $e) {
        $platformChecks[$platformCode] = [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

$health['checks']['platforms'] = $platformChecks;

// Check disk space
$freeSpace = disk_free_space(__DIR__);
$totalSpace = disk_total_space(__DIR__);
$usedPercentage = round((($totalSpace - $freeSpace) / $totalSpace) * 100, 2);

$health['checks']['disk'] = [
    'status' => $usedPercentage < 90 ? 'healthy' : 'warning',
    'used_percentage' => $usedPercentage . '%',
    'free_space' => round($freeSpace / 1073741824, 2) . 'GB'
];

if ($usedPercentage >= 90) {
    $health['status'] = 'degraded';
}

// Check recent error rate
try {
    $db = new Database();
    $recentErrors = $db->count('processing_log', [
        ['status', '=', 'failure'],
        ['created_at', '>=', date('Y-m-d H:i:s', strtotime('-5 minutes'))]
    ]);
    
    $recentTotal = $db->count('processing_log', [
        ['created_at', '>=', date('Y-m-d H:i:s', strtotime('-5 minutes'))]
    ]);
    
    $errorRate = $recentTotal > 0 ? round(($recentErrors / $recentTotal) * 100, 2) : 0;
    
    $health['checks']['error_rate'] = [
        'status' => $errorRate < 5 ? 'healthy' : ($errorRate < 10 ? 'warning' : 'unhealthy'),
        'rate' => $errorRate . '%',
        'errors_last_5min' => $recentErrors
    ];
    
    if ($errorRate >= 10) {
        $health['status'] = 'degraded';
    }
} catch (Exception $e) {
    $health['checks']['error_rate'] = [
        'status' => 'unknown',
        'error' => $e->getMessage()
    ];
}

// Overall metrics
$health['metrics'] = [
    'uptime' => getUptime(),
    'memory_usage' => round(memory_get_usage(true) / 1048576, 2) . 'MB',
    'memory_peak' => round(memory_get_peak_usage(true) / 1048576, 2) . 'MB',
    'response_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
];

// Set appropriate HTTP status code
$statusCode = match($health['status']) {
    'healthy' => 200,
    'degraded' => 200, // Still return 200 for degraded but functioning
    'unhealthy' => 503,
    default => 500
};

http_response_code($statusCode);
echo json_encode($health, JSON_PRETTY_PRINT);

/**
 * Get system uptime
 */
function getUptime(): string
{
    // shell_exec is often disabled on shared hosting
    if (!function_exists('shell_exec')) {
        return 'N/A (shell_exec disabled)';
    }
    
    if (PHP_OS_FAMILY === 'Windows') {
        return 'N/A';
    }
    
    try {
        $uptime = @shell_exec('uptime -p');
        return trim($uptime ?: 'Unknown');
    } catch (Exception $e) {
        return 'Unknown';
    }
}