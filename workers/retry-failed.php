<?php

/**
 * Retry Failed Jobs Worker
 * 
 * This script retries failed jobs from the queue
 * Run this periodically via cron (e.g., every 15 minutes)
 * 
 * Usage:
 *   php retry-failed.php                    # Retry jobs from last hour
 *   php retry-failed.php --hours=4         # Retry jobs from last 4 hours
 *   php retry-failed.php --dry-run         # Show what would be retried
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
$options = getopt('', ['hours:', 'dry-run', 'platform:', 'limit:']);
$hoursAgo = (int)($options['hours'] ?? 1);
$dryRun = isset($options['dry-run']);
$platformFilter = $options['platform'] ?? null;
$limit = (int)($options['limit'] ?? 1000);

// Initialize services
$logger = new Logger();
$queueService = new QueueService();
$db = new Database();

// Log startup
$logger->info("Retry worker started", [
    'hours_ago' => $hoursAgo,
    'dry_run' => $dryRun,
    'platform_filter' => $platformFilter,
    'limit' => $limit
]);

try {
    // Find failed jobs to retry
    $since = Carbon::now()->subHours($hoursAgo);
    
    $sql = "SELECT pq.*, p.platform_code, p.display_name 
            FROM processing_queue pq
            JOIN platforms p ON pq.platform_id = p.id
            WHERE pq.status = 'failed' 
            AND pq.attempts < pq.max_retries
            AND pq.created_at >= ?";
    
    $params = [$since->toDateTimeString()];
    
    // Add platform filter if specified
    if ($platformFilter) {
        $sql .= " AND p.platform_code = ?";
        $params[] = $platformFilter;
    }
    
    $sql .= " ORDER BY pq.created_at ASC LIMIT ?";
    $params[] = $limit;
    
    $failedJobs = $db->query($sql, $params);
    
    $logger->info("Found failed jobs to retry", [
        'count' => count($failedJobs),
        'since' => $since->toDateTimeString()
    ]);
    
    if (empty($failedJobs)) {
        $logger->info("No failed jobs found to retry");
        exit(0);
    }
    
    // Group by platform for reporting
    $jobsByPlatform = [];
    foreach ($failedJobs as $job) {
        $platform = $job['platform_code'];
        if (!isset($jobsByPlatform[$platform])) {
            $jobsByPlatform[$platform] = [];
        }
        $jobsByPlatform[$platform][] = $job;
    }
    
    // Show summary
    echo "Failed Jobs Summary:\n";
    echo str_repeat('-', 50) . "\n";
    foreach ($jobsByPlatform as $platform => $jobs) {
        echo sprintf("  %s: %d jobs\n", $platform, count($jobs));
    }
    echo str_repeat('-', 50) . "\n";
    echo sprintf("Total: %d jobs\n\n", count($failedJobs));
    
    if ($dryRun) {
        echo "DRY RUN - No jobs will be retried\n";
        
        // Show details of what would be retried
        foreach ($failedJobs as $job) {
            echo sprintf(
                "Would retry: Job %d (Event %d -> %s) - Attempts: %d/%d\n",
                $job['id'],
                $job['event_id'],
                $job['platform_code'],
                $job['attempts'],
                $job['max_retries']
            );
        }
        
        exit(0);
    }
    
    // Retry the jobs
    $retried = 0;
    $failed = 0;
    
    foreach ($failedJobs as $jobData) {
        try {
            // Load the job model
            $job = ProcessingQueue::fromArray($jobData);
            
            // Calculate exponential backoff
            $delayMinutes = pow(2, $job->attempts) * 5; // 5, 10, 20, 40 minutes...
            $delayMinutes = min($delayMinutes, 120); // Cap at 2 hours
            
            // Set process_after time
            $job->process_after = Carbon::now()->addMinutes($delayMinutes);
            
            // Reset status to pending
            $job->status = 'pending';
            $job->attempts++; // Increment attempt count
            $job->locked_by = null;
            $job->locked_until = null;
            
            if ($job->save()) {
                $retried++;
                $logger->info("Job queued for retry", [
                    'job_id' => $job->id,
                    'event_id' => $job->event_id,
                    'platform' => $jobData['platform_code'],
                    'attempts' => $job->attempts,
                    'retry_after' => $job->process_after->toDateTimeString()
                ]);
            } else {
                $failed++;
                $logger->error("Failed to retry job", [
                    'job_id' => $job->id
                ]);
            }
            
        } catch (Exception $e) {
            $failed++;
            $logger->error("Exception retrying job", [
                'job_id' => $jobData['id'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Final summary
    $logger->info("Retry worker completed", [
        'total_jobs' => count($failedJobs),
        'retried' => $retried,
        'failed' => $failed
    ]);
    
    echo "\nRetry Summary:\n";
    echo str_repeat('=', 50) . "\n";
    echo sprintf("Successfully retried: %d\n", $retried);
    echo sprintf("Failed to retry: %d\n", $failed);
    echo str_repeat('=', 50) . "\n";
    
    // Also run general retry method from QueueService
    echo "\nRunning general retry check...\n";
    $additionalRetries = $queueService->retryFailedJobs($hoursAgo);
    echo sprintf("Additional jobs retried: %d\n", $additionalRetries);
    
} catch (Exception $e) {
    $logger->error("Fatal error in retry worker", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);