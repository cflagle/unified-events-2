<?php

/**
 * Statistics Endpoint
 * 
 * Provides system statistics and metrics
 */

use UnifiedEvents\Services\EventProcessor;
use UnifiedEvents\Services\QueueService;
use UnifiedEvents\Services\ValidationService;
use UnifiedEvents\Models\RevenueTracking;
use UnifiedEvents\Utilities\Database;
use Carbon\Carbon;

// Check permissions
if (!hasPermission('stats.read')) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

// Get time period from request
$period = $_GET['period'] ?? '24h';
$validPeriods = ['1h', '24h', '7d', '30d'];
if (!in_array($period, $validPeriods)) {
    $period = '24h';
}

// Initialize services
$eventProcessor = new EventProcessor();
$queueService = new QueueService();
$validationService = new ValidationService();
$db = new Database();

// Get overall stats
$stats = $eventProcessor->getStats($period);

// Add queue-specific stats
$stats['queue'] = $queueService->getStats();

// Add validation stats
$stats['validation'] = $validationService->getValidationStats();

// Get revenue stats
$stats['revenue'] = getRevenueStats($period);

// Get event type breakdown
$stats['events_by_type'] = getEventTypeBreakdown($period);

// Get top campaigns
$stats['top_campaigns'] = getTopCampaigns($period);

// Get platform performance
$stats['platform_performance'] = getPlatformPerformance($period);

// Add metadata
$stats['metadata'] = [
    'period' => $period,
    'generated_at' => date('c'),
    'timezone' => date_default_timezone_get()
];

echo json_encode($stats, JSON_PRETTY_PRINT);

/**
 * Get revenue statistics
 */
function getRevenueStats(string $period): array
{
    $db = new Database();
    
    $since = match($period) {
        '1h' => Carbon::now()->subHour(),
        '24h' => Carbon::now()->subDay(),
        '7d' => Carbon::now()->subWeek(),
        '30d' => Carbon::now()->subMonth(),
        default => Carbon::now()->subDay()
    };
    
    // Total revenue
    $sql = "SELECT 
            SUM(gross_revenue) as total_gross,
            SUM(net_revenue) as total_net,
            COUNT(*) as transaction_count,
            COUNT(DISTINCT event_id) as unique_events
            FROM revenue_tracking
            WHERE created_at >= ?";
    
    $totals = $db->queryRow($sql, [$since->toDateTimeString()]);
    
    // Revenue by platform
    $sql = "SELECT 
            p.platform_code,
            p.display_name,
            SUM(rt.gross_revenue) as gross_revenue,
            COUNT(rt.id) as transaction_count
            FROM revenue_tracking rt
            JOIN platforms p ON rt.platform_id = p.id
            WHERE rt.created_at >= ?
            GROUP BY p.id
            ORDER BY gross_revenue DESC";
    
    $byPlatform = $db->query($sql, [$since->toDateTimeString()]);
    
    // Revenue by status
    $sql = "SELECT 
            status,
            COUNT(*) as count,
            SUM(gross_revenue) as total
            FROM revenue_tracking
            WHERE created_at >= ?
            GROUP BY status";
    
    $byStatus = $db->query($sql, [$since->toDateTimeString()]);
    
    return [
        'total_gross' => (float)($totals['total_gross'] ?? 0),
        'total_net' => (float)($totals['total_net'] ?? 0),
        'transaction_count' => (int)($totals['transaction_count'] ?? 0),
        'unique_events' => (int)($totals['unique_events'] ?? 0),
        'by_platform' => $byPlatform,
        'by_status' => $byStatus,
        'average_per_event' => $totals['unique_events'] > 0 
            ? round($totals['total_gross'] / $totals['unique_events'], 2) 
            : 0
    ];
}

/**
 * Get event type breakdown
 */
function getEventTypeBreakdown(string $period): array
{
    $db = new Database();
    
    $since = match($period) {
        '1h' => Carbon::now()->subHour(),
        '24h' => Carbon::now()->subDay(),
        '7d' => Carbon::now()->subWeek(),
        '30d' => Carbon::now()->subMonth(),
        default => Carbon::now()->subDay()
    };
    
    $sql = "SELECT 
            event_type,
            status,
            COUNT(*) as count
            FROM events
            WHERE created_at >= ?
            GROUP BY event_type, status
            ORDER BY event_type, status";
    
    $results = $db->query($sql, [$since->toDateTimeString()]);
    
    // Reorganize data
    $breakdown = [];
    foreach ($results as $row) {
        if (!isset($breakdown[$row['event_type']])) {
            $breakdown[$row['event_type']] = [
                'total' => 0,
                'by_status' => []
            ];
        }
        
        $breakdown[$row['event_type']]['by_status'][$row['status']] = (int)$row['count'];
        $breakdown[$row['event_type']]['total'] += (int)$row['count'];
    }
    
    return $breakdown;
}

/**
 * Get top campaigns
 */
function getTopCampaigns(string $period): array
{
    $db = new Database();
    
    $since = match($period) {
        '1h' => Carbon::now()->subHour(),
        '24h' => Carbon::now()->subDay(),
        '7d' => Carbon::now()->subWeek(),
        '30d' => Carbon::now()->subMonth(),
        default => Carbon::now()->subDay()
    };
    
    $sql = "SELECT 
            campaign,
            COUNT(*) as event_count,
            COUNT(DISTINCT email) as unique_emails,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
            COUNT(CASE WHEN status = 'blocked' THEN 1 END) as blocked_count
            FROM events
            WHERE created_at >= ? 
            AND campaign IS NOT NULL 
            AND campaign != ''
            GROUP BY campaign
            ORDER BY event_count DESC
            LIMIT 10";
    
    return $db->query($sql, [$since->toDateTimeString()]);
}

/**
 * Get platform performance metrics
 */
function getPlatformPerformance(string $period): array
{
    $db = new Database();
    
    $since = match($period) {
        '1h' => Carbon::now()->subHour(),
        '24h' => Carbon::now()->subDay(),
        '7d' => Carbon::now()->subWeek(),
        '30d' => Carbon::now()->subMonth(),
        default => Carbon::now()->subDay()
    };
    
    $sql = "SELECT 
            p.platform_code,
            p.display_name,
            COUNT(pq.id) as total_jobs,
            COUNT(CASE WHEN pq.status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN pq.status = 'failed' THEN 1 END) as failed,
            COUNT(CASE WHEN pq.status = 'pending' THEN 1 END) as pending,
            COUNT(CASE WHEN pq.status = 'processing' THEN 1 END) as processing,
            AVG(CASE WHEN pq.status = 'completed' THEN pq.attempts END) as avg_attempts,
            AVG(pl.duration_ms) as avg_duration_ms
            FROM platforms p
            LEFT JOIN processing_queue pq ON p.id = pq.platform_id AND pq.created_at >= ?
            LEFT JOIN processing_log pl ON pq.id = pl.queue_id AND pl.status = 'success'
            WHERE p.is_active = 1
            GROUP BY p.id
            ORDER BY p.display_name";
    
    $results = $db->query($sql, [$since->toDateTimeString()]);
    
    // Calculate success rates
    foreach ($results as &$platform) {
        $total = (int)$platform['total_jobs'];
        $completed = (int)$platform['completed'];
        $failed = (int)$platform['failed'];
        
        $platform['success_rate'] = $total > 0 
            ? round(($completed / $total) * 100, 2) 
            : 0;
        
        $platform['avg_duration_ms'] = round((float)$platform['avg_duration_ms'], 2);
        $platform['avg_attempts'] = round((float)$platform['avg_attempts'], 2);
    }
    
    return $results;
}