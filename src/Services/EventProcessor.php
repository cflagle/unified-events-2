<?php

namespace UnifiedEvents\Services;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Models\ProcessingQueue;
use UnifiedEvents\Models\ProcessingLog;
use UnifiedEvents\Models\EventRelationship;
use UnifiedEvents\Models\RevenueTracking;
use UnifiedEvents\Utilities\Logger;
use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Platforms\PlatformFactory;
use Carbon\Carbon;

class EventProcessor
{
    private Logger $logger;
    private Database $db;
    private ValidationService $validator;
    private RouterService $router;
    private QueueService $queue;
    
    public function __construct()
    {
        $this->logger = new Logger();
        $this->db = new Database();
        $this->validator = new ValidationService();
        $this->router = new RouterService();
        $this->queue = new QueueService();
    }
    
    /**
     * Process incoming event request
     * This is the main entry point for all events
     */
    public function processEvent(array $requestData, string $eventType): array
    {
        $startTime = microtime(true);
        
        try {
            // Step 1: Create event from request
            $event = Event::createFromRequest($requestData, $eventType);
            
            $this->logger->logEvent(
                'FileRequest',
                $event->email ?? 'unknown',
                ucfirst($eventType) . ' processing initiated',
                'success'
            );
            
            // Step 2: Run pre-processing validations
            $validation = $this->validator->validateEvent($event, $requestData);
            
            if (!$validation['is_valid']) {
                // Block the event
                $reason = $validation['is_bot'] ? 
                    'bot_detected: ' . $validation['bot_reason'] : 
                    'validation_failed: ' . implode(', ', $validation['validation_errors']);
                
                $event->block($reason);
                
                return [
                    'success' => false,
                    'event_id' => $event->event_id,
                    'error' => 'Event blocked',
                    'reason' => $reason,
                    'processing_time' => round(microtime(true) - $startTime, 3)
                ];
            }
            
            // Step 3: Save the event
            if (!$event->save()) {
                throw new \Exception('Failed to save event');
            }
            
            // Step 4: Link to parent events (e.g., link purchase to original lead)
            if ($event->isPurchase() && $event->email) {
                $this->linkToParentLead($event);
            }
            
            // Step 5: Determine which platforms to send to
            $platforms = $this->router->getRoutesForEvent($event);
            
            // Step 6: Queue for processing
            $queuedCount = 0;
            foreach ($platforms as $platform) {
                if ($this->queue->enqueue($event->id, $platform['id'])) {
                    $queuedCount++;
                }
            }
            
            // Step 7: If ZeroBounce validation is needed, queue it first
            if ($validation['needs_zerobounce'] && $event->email) {
                $zbPlatform = $this->router->getZeroBouncePlatform();
                if ($zbPlatform) {
                    $this->queue->enqueuePriority($event->id, $zbPlatform['id']);
                }
            }
            
            // Log successful queueing
            $this->logger->info('Event queued for processing', [
                'event_id' => $event->event_id,
                'event_type' => $eventType,
                'platforms_queued' => $queuedCount,
                'email' => $event->email
            ]);
            
            return [
                'success' => true,
                'event_id' => $event->event_id,
                'queued_platforms' => $queuedCount,
                'processing_time' => round(microtime(true) - $startTime, 3)
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Event processing failed', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => 'Processing failed: ' . $e->getMessage(),
                'processing_time' => round(microtime(true) - $startTime, 3)
            ];
        }
    }
    
    /**
     * Process a queued job
     * This is called by the queue worker
     */
    public function processQueuedJob(ProcessingQueue $job): bool
    {
        $startTime = microtime(true);
        
        try {
            // Get the event
            $event = Event::find($job->event_id);
            if (!$event) {
                throw new \Exception('Event not found');
            }
            
            // Get the platform
            $platform = $this->router->getPlatformById($job->platform_id);
            if (!$platform) {
                throw new \Exception('Platform not found');
            }
            
            // Create platform instance - ensure api_config is included
            $platformConfig = $platform;
            
            // If api_config exists and is an array, merge it into the main config
            if (isset($platform['api_config']) && is_array($platform['api_config'])) {
                $platformConfig = array_merge($platform, $platform['api_config']);
            }
            
            $platformInstance = PlatformFactory::create($platform['platform_code'], $platformConfig);
            
            // Special handling for ZeroBounce
            if ($platform['platform_code'] === 'zerobounce') {
                return $this->processZeroBounceValidation($event, $platformInstance, $job);
            }
            
            // Check if we should skip this platform
            if ($this->shouldSkipPlatform($event, $platform)) {
                $job->skip('Platform conditions not met');
                return true;
            }
            
            // Send to platform
            $result = $platformInstance->send($event);
            
            // Log the attempt
            ProcessingLog::create([
                'event_id' => $event->id,
                'platform_id' => $platform['id'],
                'queue_id' => $job->id,
                'action' => 'api_call',
                'status' => $result['success'] ? 'success' : 'failure',
                'request_payload' => json_encode($platformInstance->mapFields($event)),
                'response_code' => $result['response_code'] ?? null,
                'response_body' => json_encode($result['platform_response'] ?? null),
                'error_message' => $result['error'] ?? null,
                'duration_ms' => round((microtime(true) - $startTime) * 1000)
            ]);
            
            if ($result['success']) {
                // Mark job as completed
                $job->complete($result['response_code'] ?? 200, json_encode($result));
                
                // Handle revenue if applicable
                if (isset($result['revenue']) && $result['revenue'] > 0) {
                    $this->recordRevenue($event, $platform, $result['revenue']);
                }
                
                // Update event with platform-specific data
                $this->updateEventFromPlatformResponse($event, $platform['platform_code'], $result);
                
                return true;
            } else {
                // Handle failure
                if ($job->attempts >= $job->max_retries) {
                    $job->fail($result['error'] ?? 'Unknown error');
                    return false;
                } else {
                    // Retry with exponential backoff
                    $job->retry();
                    return false;
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Queue job processing failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage()
            ]);
            
            $job->fail($e->getMessage());
            return false;
        }
    }
    
    /**
     * Process ZeroBounce validation
     */
    private function processZeroBounceValidation(Event $event, $platform, ProcessingQueue $job): bool
    {
        try {
            // Send to ZeroBounce
            $result = $platform->send($event);
            
            if ($result['success']) {
                // Process the validation response
                $isValid = $this->validator->processZeroBounceResponse(
                    $event->email,
                    $result['validation_data']
                );
                
                // Update event
                $event->email_validation_status = $isValid ? 'valid' : 'invalid';
                $event->zb_last_active = $result['validation_data']['active_in_days'] ?? null;
                $event->save();
                
                // If email is invalid, cancel other platform sends
                if (!$isValid) {
                    $this->queue->cancelPendingJobs($event->id, 'email_invalid');
                    
                    $this->logger->info('Email invalid, cancelled platform sends', [
                        'event_id' => $event->event_id,
                        'email' => $event->email
                    ]);
                }
                
                $job->complete(200, json_encode($result));
                return true;
            } else {
                $job->fail($result['error'] ?? 'ZeroBounce validation failed');
                return false;
            }
            
        } catch (\Exception $e) {
            $job->fail($e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if we should skip sending to a platform
     */
    private function shouldSkipPlatform(Event $event, array $platform): bool
    {
        // Skip if email is invalid and platform requires valid email
        if ($event->email_validation_status === 'invalid' && 
            ($platform['requires_valid_email'] ?? true)) {
            return true;
        }
        
        // Skip SMS platforms if no phone
        if ($platform['platform_type'] === 'sms' && empty($event->phone)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Link purchase event to original lead
     */
    private function linkToParentLead(Event $purchaseEvent): void
    {
        try {
            // Find the most recent lead event with same email
            $leadEvents = Event::findByEmail($purchaseEvent->email);
            
            foreach ($leadEvents as $leadEvent) {
                if ($leadEvent->isLead() && $leadEvent->id !== $purchaseEvent->id) {
                    // Copy acquisition data if not already set
                    if (!$purchaseEvent->acq_source && $leadEvent->acq_source) {
                        $purchaseEvent->acq_source = $leadEvent->acq_source;
                        $purchaseEvent->acq_campaign = $leadEvent->acq_campaign;
                        $purchaseEvent->acq_term = $leadEvent->acq_term;
                        $purchaseEvent->acq_date = $leadEvent->acq_date;
                        $purchaseEvent->acq_form_title = $leadEvent->acq_form_title;
                        $purchaseEvent->save();
                    }
                    
                    // Create relationship
                    $purchaseEvent->linkToParent($leadEvent->id, 'lead_to_purchase');
                    
                    $this->logger->info('Linked purchase to lead', [
                        'purchase_id' => $purchaseEvent->id,
                        'lead_id' => $leadEvent->id,
                        'email' => $purchaseEvent->email
                    ]);
                    
                    break; // Link to most recent lead only
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to link purchase to lead', [
                'error' => $e->getMessage(),
                'purchase_id' => $purchaseEvent->id
            ]);
        }
    }
    
    /**
     * Record revenue from a platform
     */
    private function recordRevenue(Event $event, array $platform, float $amount): void
    {
        try {
            RevenueTracking::create([
                'event_id' => $event->id,
                'platform_id' => $platform['id'],
                'gross_revenue' => $amount,
                'net_revenue' => $amount, // Adjust if platform takes fees
                'currency' => 'USD',
                'status' => 'confirmed',
                'notes' => 'Auto-recorded from ' . $platform['display_name']
            ]);
            
            $this->logger->logRevenue(
                $platform['platform_code'],
                $event->email ?? 'unknown',
                $amount,
                'confirmed'
            );
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to record revenue', [
                'error' => $e->getMessage(),
                'event_id' => $event->id,
                'platform' => $platform['platform_code']
            ]);
        }
    }
    
    /**
     * Update event data based on platform response
     */
    private function updateEventFromPlatformResponse(Event $event, string $platformCode, array $response): void
    {
        try {
            // Platform-specific updates
            switch ($platformCode) {
                case 'zerobounce':
                    if (isset($response['validation_data']['active_in_days'])) {
                        $event->zb_last_active = $response['validation_data']['active_in_days'];
                    }
                    break;
                    
                case 'activecampaign':
                    // Store AC contact ID in event_data
                    if (isset($response['contact_id'])) {
                        $eventData = $event->event_data ?? [];
                        $eventData['ac_contact_id'] = $response['contact_id'];
                        $event->event_data = $eventData;
                    }
                    break;
            }
            
            $event->save();
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to update event from platform response', [
                'error' => $e->getMessage(),
                'event_id' => $event->id
            ]);
        }
    }
    
    /**
     * Get processing statistics
     */
    public function getStats(string $period = '24h'): array
    {
        $since = match($period) {
            '1h' => Carbon::now()->subHour(),
            '24h' => Carbon::now()->subDay(),
            '7d' => Carbon::now()->subWeek(),
            '30d' => Carbon::now()->subMonth(),
            default => Carbon::now()->subDay()
        };
        
        return [
            'events_processed' => $this->db->count('events', [
                ['created_at', '>=', $since->toDateTimeString()]
            ]),
            'events_blocked' => $this->db->count('events', [
                ['status', '=', 'blocked'],
                ['created_at', '>=', $since->toDateTimeString()]
            ]),
            'queue_pending' => $this->db->count('processing_queue', [
                'status' => 'pending'
            ]),
            'queue_failed' => $this->db->count('processing_queue', [
                'status' => 'failed',
                ['created_at', '>=', $since->toDateTimeString()]
            ]),
            'validation_stats' => $this->validator->getValidationStats(),
            'platform_stats' => $this->getPlatformStats($since)
        ];
    }
    
    /**
     * Get platform-specific statistics
     */
    private function getPlatformStats(Carbon $since): array
    {
        $sql = "SELECT 
                p.platform_code,
                COUNT(CASE WHEN pl.status = 'success' THEN 1 END) as success_count,
                COUNT(CASE WHEN pl.status = 'failure' THEN 1 END) as failure_count,
                AVG(pl.duration_ms) as avg_duration_ms
                FROM processing_log pl
                JOIN platforms p ON pl.platform_id = p.id
                WHERE pl.created_at >= ?
                GROUP BY p.platform_code";
        
        return $this->db->query($sql, [$since->toDateTimeString()]);
    }
}