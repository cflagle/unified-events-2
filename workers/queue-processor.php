<?php

/**
 * Queue Worker Process
 * 
 * This script processes queued jobs in the background
 * Run this as a daemon or via cron job
 * 
 * Usage:
 *   php queue-processor.php                    # Process jobs continuously
 *   php queue-processor.php --once            # Process one batch and exit
 *   php queue-processor.php --workers=4       # Run with 4 worker processes
 */

require_once __DIR__ . '/../vendor/autoload.php';

use UnifiedEvents\Services\EventProcessor;
use UnifiedEvents\Services\QueueService;
use UnifiedEvents\Models\ProcessingQueue;
use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Utilities\Logger;
use Dotenv\Dotenv;

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
$options = getopt('', ['once', 'workers:', 'batch-size:', 'sleep:', 'max-runtime:']);
$runOnce = isset($options['once']);
$workerCount = (int)($options['workers'] ?? 1);
$batchSize = (int)($options['batch-size'] ?? $_ENV['QUEUE_BATCH_SIZE'] ?? 100);
$sleepTime = (int)($options['sleep'] ?? 5); // seconds between batches
$maxRuntime = (int)($options['max-runtime'] ?? 3600); // max runtime in seconds (default 1 hour)

// Initialize services
$logger = new Logger();
$queueService = new QueueService();
$eventProcessor = new EventProcessor();

// Generate unique worker ID
$workerId = gethostname() . '_' . getmypid() . '_' . uniqid();

// Signal handling for graceful shutdown
$shouldStop = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$shouldStop, $logger, $workerId) {
        $logger->info("Worker received SIGTERM signal", ['worker_id' => $workerId]);
        $shouldStop = true;
    });
    
    pcntl_signal(SIGINT, function() use (&$shouldStop, $logger, $workerId) {
        $logger->info("Worker received SIGINT signal", ['worker_id' => $workerId]);
        $shouldStop = true;
    });
}

// Log startup
$logger->info("Queue worker started", [
    'worker_id' => $workerId,
    'run_once' => $runOnce,
    'batch_size' => $batchSize,
    'sleep_time' => $sleepTime,
    'max_runtime' => $maxRuntime
]);

// Fork multiple workers if requested
if ($workerCount > 1 && function_exists('pcntl_fork')) {
    for ($i = 1; $i < $workerCount; $i++) {
        $pid = pcntl_fork();
        if ($pid == -1) {
            $logger->error("Failed to fork worker process");
            exit(1);
        } elseif ($pid == 0) {
            // Child process
            $workerId = gethostname() . '_' . getmypid() . '_' . uniqid();
            $logger->info("Child worker started", ['worker_id' => $workerId, 'worker_num' => $i]);
            break;
        }
        // Parent continues to fork
    }
}

// Main processing loop
$startTime = time();
$jobsProcessed = 0;
$errors = 0;

while (!$shouldStop) {
    try {
        // Check if we've exceeded max runtime
        if ((time() - $startTime) >= $maxRuntime) {
            $logger->info("Worker reached max runtime", [
                'worker_id' => $workerId,
                'runtime' => time() - $startTime,
                'jobs_processed' => $jobsProcessed
            ]);
            break;
        }
        
        // Get next batch of jobs
        $jobs = $queueService->getNextBatch($workerId);
        
        if (empty($jobs)) {
            // No jobs available
            if ($runOnce) {
                $logger->info("No jobs available, exiting (--once mode)", [
                    'worker_id' => $workerId
                ]);
                break;
            }
            
            // Sleep before checking again
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            sleep($sleepTime);
            continue;
        }
        
        // Process each job
        foreach ($jobs as $job) {
            if ($shouldStop) {
                // Release the job back to the queue
                $queueService->releaseLock($job);
                break;
            }
            
            try {
                $jobStart = microtime(true);
                
                $logger->info("Processing job", [
                    'worker_id' => $workerId,
                    'job_id' => $job->id,
                    'event_id' => $job->event_id,
                    'platform_id' => $job->platform_id,
                    'attempts' => $job->attempts
                ]);
                
                // Process the job
                $success = $eventProcessor->processQueuedJob($job);
                
                $duration = round((microtime(true) - $jobStart) * 1000, 2);
                
                if ($success) {
                    $jobsProcessed++;
                    $logger->info("Job completed successfully", [
                        'worker_id' => $workerId,
                        'job_id' => $job->id,
                        'duration_ms' => $duration
                    ]);
                } else {
                    $errors++;
                    $logger->warning("Job failed", [
                        'worker_id' => $workerId,
                        'job_id' => $job->id,
                        'duration_ms' => $duration,
                        'attempts' => $job->attempts
                    ]);
                }
                
            } catch (Exception $e) {
                $errors++;
                $logger->error("Exception processing job", [
                    'worker_id' => $workerId,
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Mark job as failed
                $job->fail($e->getMessage());
            }
            
            // Check for signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
        
        if ($runOnce) {
            $logger->info("Batch processed, exiting (--once mode)", [
                'worker_id' => $workerId,
                'jobs_processed' => count($jobs)
            ]);
            break;
        }
        
        // Small delay between batches to prevent CPU spinning
        usleep(100000); // 100ms
        
    } catch (Exception $e) {
        $logger->error("Fatal error in worker loop", [
            'worker_id' => $workerId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Sleep before retrying to prevent rapid failure loops
        sleep(30);
    }
}

// Cleanup and stats
$runtime = time() - $startTime;
$logger->info("Queue worker shutting down", [
    'worker_id' => $workerId,
    'runtime' => $runtime,
    'jobs_processed' => $jobsProcessed,
    'errors' => $errors,
    'jobs_per_minute' => $runtime > 0 ? round(($jobsProcessed / $runtime) * 60, 2) : 0
]);

// Wait for child processes if we forked
if ($workerCount > 1 && function_exists('pcntl_wait')) {
    while (pcntl_wait($status) > 0) {
        // Wait for all children to finish
    }
}

exit($errors > 0 ? 1 : 0);