<?php

/**
 * Cleanup Worker
 * 
 * This script performs maintenance tasks:
 * - Removes old completed/failed queue jobs
 * - Cleans up old logs
 * - Optimizes database tables
 * - Releases stuck jobs
 * 
 * Run this daily via cron
 * 
 * Usage:
 *   php cleanup.php                         # Run all cleanup tasks
 *   php cleanup.php --task=queue           # Run specific task
 *   php cleanup.php --dry-run              # Show what would be cleaned
 */

require_once __DIR__ . '/../vendor/autoload.php';

use UnifiedEvents\Services\QueueService;
use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Utilities\Logger;
use Dotenv\Dotenv;
use Carbon\Carbon;

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

// Parse command line arguments
$options = getopt('', ['task:', 'dry-run', 'days:', 'verbose']);
$task = $options['task'] ?? 'all';
$dryRun = isset($options['dry-run']);
$daysToKeep = (int)($options['days'] ?? 30);
$verbose = isset($options['verbose']);

// Initialize services
$logger = new Logger();
$queueService = new QueueService();
$db = new Database();

// Log startup
$logger->info("Cleanup worker started", [
    'task' => $task,
    'dry_run' => $dryRun,
    'days_to_keep' => $daysToKeep
]);

// Track cleanup stats
$stats = [
    'queue_jobs_deleted' => 0,
    'logs_deleted' => 0,
    'stuck_jobs_released' => 0,
    'rate_limit_entries_deleted' => 0,
    'old_events_archived' => 0
];

try {
    // Task 1: Clean old queue jobs
    if ($task === 'all' || $task === 'queue') {
        echo "Cleaning old queue jobs...\n";
        
        $cutoff = Carbon::now()->subDays($daysToKeep);
        
        if ($dryRun) {
            $count = $db->count('processing_queue', [
                ['status', 'IN', ['completed', 'failed', 'skipped']],
                ['created_at', '<', $cutoff->toDateTimeString()]
            ]);
            echo "Would delete $count queue jobs older than {$cutoff->toDateString()}\n";
            $stats['queue_jobs_deleted'] = $count;
        } else {
            $stats['queue_jobs_deleted'] = $queueService->cleanup($daysToKeep);
            echo "Deleted {$stats['queue_jobs_deleted']} old queue jobs\n";
        }
    }
    
    // Task 2: Release stuck jobs
    if ($task === 'all' || $task === 'stuck') {
        echo "\nReleasing stuck jobs...\n";
        
        $stuckThreshold = Carbon::now()->subMinutes(30);
        $sql = "SELECT * FROM processing_queue 
                WHERE status = 'processing' 
                AND locked_until < ?";
        
        $stuckJobs = $db->query($sql, [$stuckThreshold->toDateTimeString()]);
        
        if ($dryRun) {
            echo "Would release " . count($stuckJobs) . " stuck jobs\n";
            if ($verbose) {
                foreach ($stuckJobs as $job) {
                    echo sprintf(
                        "  Job %d locked by %s until %s\n",
                        $job['id'],
                        $job['locked_by'],
                        $job['locked_until']
                    );
                }
            }
        } else {
            foreach ($stuckJobs as $jobData) {
                $job = ProcessingQueue::fromArray($jobData);
                if ($queueService->releaseLock($job)) {
                    $stats['stuck_jobs_released']++;
                    $logger->info("Released stuck job", [
                        'job_id' => $job->id,
                        'locked_by' => $job->locked_by
                    ]);
                }
            }
            echo "Released {$stats['stuck_jobs_released']} stuck jobs\n";
        }
    }
    
    // Task 3: Clean old logs
    if ($task === 'all' || $task === 'logs') {
        echo "\nCleaning old log entries...\n";
        
        $cutoff = Carbon::now()->subDays($daysToKeep * 2); // Keep logs longer
        
        // Clean processing logs
        if ($dryRun) {
            $count = $db->count('processing_log', [
                ['created_at', '<', $cutoff->toDateTimeString()]
            ]);
            echo "Would delete $count processing log entries\n";
            $stats['logs_deleted'] = $count;
        } else {
            $sql = "DELETE FROM processing_log WHERE created_at < ?";
            $db->execute($sql, [$cutoff->toDateTimeString()]);
            $deletedLogs = $db->query("SELECT ROW_COUNT() as count")[0]['count'];
            $stats['logs_deleted'] += $deletedLogs;
            echo "Deleted $deletedLogs processing log entries\n";
        }
        
        // Clean file logs
        $logDir = Logger::getLogPath();
        $logFiles = glob($logDir . '*.log.*'); // Rotated logs
        
        foreach ($logFiles as $file) {
            $fileAge = time() - filemtime($file);
            $daysOld = $fileAge / 86400;
            
            if ($daysOld > $daysToKeep * 2) {
                if ($dryRun) {
                    echo "Would delete log file: " . basename($file) . " (" . round($daysOld) . " days old)\n";
                } else {
                    unlink($file);
                    echo "Deleted log file: " . basename($file) . "\n";
                }
            }
        }
    }
    
    // Task 4: Clean rate limit entries
    if ($task === 'all' || $task === 'ratelimit') {
        echo "\nCleaning rate limit entries...\n";
        
        // Rate limit entries older than 1 day can be safely removed
        $cutoff = Carbon::now()->subDay();
        
        if ($dryRun) {
            $count = $db->count('rate_limit', [
                ['created_at', '<', $cutoff->toDateTimeString()]
            ]);
            echo "Would delete $count rate limit entries\n";
            $stats['rate_limit_entries_deleted'] = $count;
        } else {
            $sql = "DELETE FROM rate_limit WHERE created_at < ?";
            $db->execute($sql, [$cutoff->toDateTimeString()]);
            $deleted = $db->query("SELECT ROW_COUNT() as count")[0]['count'];
            $stats['rate_limit_entries_deleted'] = $deleted;
            echo "Deleted $deleted rate limit entries\n";
        }
    }
    
    // Task 5: Archive old events (optional)
    if ($task === 'archive') {
        echo "\nArchiving old events...\n";
        
        $archiveCutoff = Carbon::now()->subMonths(6);
        
        $sql = "SELECT COUNT(*) as count FROM events 
                WHERE created_at < ? 
                AND status = 'completed'";
        
        $oldEvents = $db->queryRow($sql, [$archiveCutoff->toDateTimeString()]);
        
        if ($dryRun) {
            echo "Would archive {$oldEvents['count']} events older than 6 months\n";
        } else {
            // In production, you'd move these to an archive table
            // For now, we'll just count them
            echo "Found {$oldEvents['count']} events ready for archiving\n";
            echo "Archiving not implemented - implement based on your needs\n";
        }
    }
    
    // Task 6: Optimize tables
    if ($task === 'all' || $task === 'optimize') {
        echo "\nOptimizing database tables...\n";
        
        $tables = [
            'events',
            'processing_queue',
            'processing_log',
            'rate_limit',
            'bot_registry',
            'email_validation_registry'
        ];
        
        foreach ($tables as $table) {
            if ($dryRun) {
                echo "Would optimize table: $table\n";
            } else {
                try {
                    $db->raw("OPTIMIZE TABLE $table");
                    echo "Optimized table: $table\n";
                } catch (Exception $e) {
                    echo "Failed to optimize $table: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    // Task 7: Update analytics summary tables
    if ($task === 'all' || $task === 'analytics') {
        echo "\nUpdating analytics summary tables...\n";
        
        if (!$dryRun) {
            // Update daily analytics for yesterday
            $yesterday = Carbon::yesterday();
            updateDailyAnalytics($db, $yesterday);
            echo "Updated analytics for " . $yesterday->toDateString() . "\n";
        }
    }
    
    // Final summary
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "Cleanup Summary:\n";
    echo str_repeat('=', 50) . "\n";
    foreach ($stats as $metric => $value) {
        if ($value > 0) {
            echo sprintf("  %s: %d\n", str_replace('_', ' ', ucfirst($metric)), $value);
        }
    }
    echo str_repeat('=', 50) . "\n";
    
    $logger->info("Cleanup worker completed", $stats);
    
} catch (Exception $e) {
    $logger->error("Fatal error in cleanup worker", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);

/**
 * Update daily analytics summary
 */
function updateDailyAnalytics(Database $db, Carbon $date): void
{
    $dateStr = $date->toDateString();
    
    // Delete existing data for this date
    $db->delete('analytics_daily', ['date' => $dateStr]);
    
    // Insert event summaries
    $sql = "INSERT INTO analytics_daily (date, event_type, platform_code, total_events, successful_events, failed_events, revenue)
            SELECT 
                ? as date,
                e.event_type,
                p.platform_code,
                COUNT(DISTINCT e.id) as total_events,
                COUNT(DISTINCT CASE WHEN pq.status = 'completed' THEN e.id END) as successful_events,
                COUNT(DISTINCT CASE WHEN pq.status = 'failed' THEN e.id END) as failed_events,
                COALESCE(SUM(rt.gross_revenue), 0) as revenue
            FROM events e
            LEFT JOIN processing_queue pq ON e.id = pq.event_id
            LEFT JOIN platforms p ON pq.platform_id = p.id
            LEFT JOIN revenue_tracking rt ON e.id = rt.event_id AND DATE(rt.created_at) = ?
            WHERE DATE(e.created_at) = ?
            GROUP BY e.event_type, p.platform_code";
    
    $db->execute($sql, [$dateStr, $dateStr, $dateStr]);
    
    // Update response time metrics
    $sql = "UPDATE analytics_daily ad
            SET avg_response_time_ms = (
                SELECT AVG(pl.duration_ms)
                FROM processing_log pl
                JOIN processing_queue pq ON pl.queue_id = pq.id
                JOIN events e ON pq.event_id = e.id
                JOIN platforms p ON pq.platform_id = p.id
                WHERE DATE(pl.created_at) = ad.date
                AND e.event_type = ad.event_type
                AND p.platform_code = ad.platform_code
                AND pl.status = 'success'
            )
            WHERE ad.date = ?";
    
    $db->execute($sql, [$dateStr]);
}