<?php

namespace UnifiedEvents\Services;

use UnifiedEvents\Models\ProcessingQueue;
use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Utilities\Logger;
use Carbon\Carbon;
use Predis\Client as Redis;

class QueueService
{
    private Database $db;
    private Logger $logger;
    private ?Redis $redis;
    private int $batchSize;
    private int $lockTimeout = 300; // 5 minutes
    
    public function __construct()
    {
        $this->db = new Database();
        $this->logger = new Logger();
        $this->batchSize = (int)($_ENV['QUEUE_BATCH_SIZE'] ?? 100);
        
        // Initialize Redis if available
        try {
            $this->redis = new Redis([
                'scheme' => 'tcp',
                'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                'port' => $_ENV['REDIS_PORT'] ?? 6379,
                'password' => $_ENV['REDIS_PASSWORD'] ?? null,
            ]);
            $this->redis->ping();
        } catch (\Exception $e) {
            $this->redis = null;
            $this->logger->warning('Redis not available, using database queue only');
        }
    }
    
    /**
     * Add a job to the queue
     */
    public function enqueue(int $eventId, int $platformId, int $delaySeconds = 0): bool
    {
        try {
            $processAfter = Carbon::now()->addSeconds($delaySeconds);
            
            $job = new ProcessingQueue();
            $job->event_id = $eventId;
            $job->platform_id = $platformId;
            $job->status = 'pending';
            $job->process_after = $processAfter;
            $job->created_at = Carbon::now();
            
            if ($job->save()) {
                // Also add to Redis for faster processing if available
                if ($this->redis) {
                    $this->redis->zadd('queue:pending', [
                        $job->id => $processAfter->timestamp
                    ]);
                }
                
                $this->logger->debug('Job enqueued', [
                    'job_id' => $job->id,
                    'event_id' => $eventId,
                    'platform_id' => $platformId,
                    'process_after' => $processAfter->toDateTimeString()
                ]);
                
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to enqueue job', [
                'event_id' => $eventId,
                'platform_id' => $platformId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Add a priority job to the queue (processes immediately)
     */
    public function enqueuePriority(int $eventId, int $platformId): bool
    {
        return $this->enqueue($eventId, $platformId, 0);
    }
    
    /**
     * Get next batch of jobs to process
     */
    public function getNextBatch(string $workerId): array
    {
        $now = Carbon::now();
        $lockUntil = $now->copy()->addSeconds($this->lockTimeout);
        
        // Use Redis if available for better performance
        if ($this->redis) {
            return $this->getNextBatchFromRedis($workerId, $now, $lockUntil);
        }
        
        // Otherwise use database
        return $this->getNextBatchFromDatabase($workerId, $now, $lockUntil);
    }
    
    /**
     * Get jobs from database
     */
    private function getNextBatchFromDatabase(string $workerId, Carbon $now, Carbon $lockUntil): array
    {
        $jobs = [];
        
        $this->db->transaction(function($db) use ($workerId, $now, $lockUntil, &$jobs) {
            // Find pending jobs that are ready to process
            $sql = "SELECT * FROM processing_queue 
                    WHERE status = 'pending' 
                    AND process_after <= ? 
                    AND (locked_until IS NULL OR locked_until < ?)
                    ORDER BY process_after ASC, id ASC
                    LIMIT ?
                    FOR UPDATE";
            
            $rows = $db->query($sql, [
                $now->toDateTimeString(),
                $now->toDateTimeString(),
                $this->batchSize
            ]);
            
            if (empty($rows)) {
                return;
            }
            
            // Lock the jobs
            $jobIds = array_column($rows, 'id');
            $placeholders = array_fill(0, count($jobIds), '?');
            
            $updateSql = "UPDATE processing_queue 
                         SET status = 'processing', 
                             locked_by = ?, 
                             locked_until = ?
                         WHERE id IN (" . implode(',', $placeholders) . ")";
            
            $params = array_merge([$workerId, $lockUntil->toDateTimeString()], $jobIds);
            $db->execute($updateSql, $params);
            
            // Convert to ProcessingQueue objects
            foreach ($rows as $row) {
                $jobs[] = ProcessingQueue::fromArray($row);
            }
        });
        
        return $jobs;
    }
    
    /**
     * Get jobs from Redis
     */
    private function getNextBatchFromRedis(string $workerId, Carbon $now, Carbon $lockUntil): array
    {
        $jobs = [];
        
        // Get job IDs from Redis sorted set
        $jobIds = $this->redis->zrangebyscore(
            'queue:pending',
            0,
            $now->timestamp,
            ['limit' => [0, $this->batchSize]]
        );
        
        if (empty($jobIds)) {
            return [];
        }
        
        // Remove from Redis and get from database
        $this->redis->zrem('queue:pending', ...$jobIds);
        
        // Lock in database
        $this->db->transaction(function($db) use ($jobIds, $workerId, $lockUntil, &$jobs) {
            foreach ($jobIds as $jobId) {
                $job = ProcessingQueue::find($jobId);
                if ($job && $job->status === 'pending') {
                    $job->status = 'processing';
                    $job->locked_by = $workerId;
                    $job->locked_until = $lockUntil;
                    $job->save();
                    $jobs[] = $job;
                }
            }
        });
        
        return $jobs;
    }
    
    /**
     * Release a locked job back to the queue
     */
    public function releaseLock(ProcessingQueue $job): bool
    {
        try {
            $job->status = 'pending';
            $job->locked_by = null;
            $job->locked_until = null;
            $job->save();
            
            // Re-add to Redis if available
            if ($this->redis) {
                $this->redis->zadd('queue:pending', [
                    $job->id => $job->process_after->timestamp
                ]);
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to release job lock', [
                'job_id' => $job->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Cancel pending jobs for an event
     */
    public function cancelPendingJobs(int $eventId, string $reason): int
    {
        $sql = "UPDATE processing_queue 
                SET status = 'skipped', 
                    skip_reason = ?
                WHERE event_id = ? 
                AND status = 'pending'";
        
        $this->db->execute($sql, [$reason, $eventId]);
        $affected = $this->db->query("SELECT ROW_COUNT() as count")[0]['count'];
        
        // Remove from Redis if present
        if ($this->redis && $affected > 0) {
            $jobs = $this->db->findAll('processing_queue', [
                'event_id' => $eventId,
                'status' => 'skipped'
            ]);
            
            foreach ($jobs as $job) {
                $this->redis->zrem('queue:pending', $job['id']);
            }
        }
        
        $this->logger->info('Cancelled pending jobs', [
            'event_id' => $eventId,
            'reason' => $reason,
            'count' => $affected
        ]);
        
        return $affected;
    }
    
    /**
     * Retry failed jobs
     */
    public function retryFailedJobs(int $hoursAgo = 1): int
    {
        $since = Carbon::now()->subHours($hoursAgo);
        
        $sql = "SELECT * FROM processing_queue 
                WHERE status = 'failed' 
                AND attempts < max_retries
                AND created_at >= ?
                ORDER BY id ASC";
        
        $jobs = $this->db->query($sql, [$since->toDateTimeString()]);
        $count = 0;
        
        foreach ($jobs as $jobData) {
            $job = ProcessingQueue::fromArray($jobData);
            if ($job->retry()) {
                $count++;
                
                // Add to Redis queue
                if ($this->redis) {
                    $this->redis->zadd('queue:pending', [
                        $job->id => $job->process_after->timestamp
                    ]);
                }
            }
        }
        
        $this->logger->info('Retried failed jobs', [
            'count' => $count,
            'since' => $since->toDateTimeString()
        ]);
        
        return $count;
    }
    
    /**
     * Clean up old completed/failed jobs
     */
    public function cleanup(int $daysToKeep = 30): int
    {
        $cutoff = Carbon::now()->subDays($daysToKeep);
        
        $sql = "DELETE FROM processing_queue 
                WHERE status IN ('completed', 'failed', 'skipped') 
                AND created_at < ?";
        
        $this->db->execute($sql, [$cutoff->toDateTimeString()]);
        $deleted = $this->db->query("SELECT ROW_COUNT() as count")[0]['count'];
        
        $this->logger->info('Cleaned up old queue jobs', [
            'deleted' => $deleted,
            'older_than' => $cutoff->toDateTimeString()
        ]);
        
        return $deleted;
    }
    
    /**
     * Get queue statistics
     */
    public function getStats(): array
    {
        $stats = [];
        
        // Status counts
        $sql = "SELECT status, COUNT(*) as count 
                FROM processing_queue 
                GROUP BY status";
        
        $results = $this->db->query($sql);
        foreach ($results as $row) {
            $stats['by_status'][$row['status']] = (int)$row['count'];
        }
        
        // Jobs by platform
        $sql = "SELECT p.platform_code, pq.status, COUNT(*) as count 
                FROM processing_queue pq
                JOIN platforms p ON pq.platform_id = p.id
                GROUP BY p.platform_code, pq.status";
        
        $results = $this->db->query($sql);
        foreach ($results as $row) {
            if (!isset($stats['by_platform'][$row['platform_code']])) {
                $stats['by_platform'][$row['platform_code']] = [];
            }
            $stats['by_platform'][$row['platform_code']][$row['status']] = (int)$row['count'];
        }
        
        // Processing rate (last hour)
        $sql = "SELECT COUNT(*) as count 
                FROM processing_queue 
                WHERE status = 'completed' 
                AND processed_at >= ?";
        
        $stats['completed_last_hour'] = $this->db->query($sql, [
            Carbon::now()->subHour()->toDateTimeString()
        ])[0]['count'];
        
        // Average processing time
        $sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_seconds
                FROM processing_queue 
                WHERE status = 'completed' 
                AND processed_at >= ?";
        
        $avgResult = $this->db->query($sql, [
            Carbon::now()->subDay()->toDateTimeString()
        ])[0];
        
        $stats['avg_processing_seconds'] = round($avgResult['avg_seconds'] ?? 0, 2);
        
        // Redis status
        $stats['redis_available'] = $this->redis !== null;
        if ($this->redis) {
            $stats['redis_pending_count'] = $this->redis->zcard('queue:pending');
        }
        
        return $stats;
    }
    
    /**
     * Check queue health
     */
    public function checkHealth(): array
    {
        $issues = [];
        
        // Check for stuck jobs
        $stuckThreshold = Carbon::now()->subMinutes(30);
        $stuckCount = $this->db->count('processing_queue', [
            ['status', '=', 'processing'],
            ['locked_until', '<', $stuckThreshold->toDateTimeString()]
        ]);
        
        if ($stuckCount > 0) {
            $issues[] = "Found $stuckCount stuck jobs (processing for >30 minutes)";
        }
        
        // Check failure rate
        $recentFailures = $this->db->count('processing_queue', [
            ['status', '=', 'failed'],
            ['created_at', '>=', Carbon::now()->subHour()->toDateTimeString()]
        ]);
        
        if ($recentFailures > 100) {
            $issues[] = "High failure rate: $recentFailures failures in last hour";
        }
        
        // Check queue size
        $pendingCount = $this->db->count('processing_queue', ['status' => 'pending']);
        if ($pendingCount > 10000) {
            $issues[] = "Large queue backlog: $pendingCount pending jobs";
        }
        
        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'stats' => $this->getStats()
        ];
    }
}