<?php

namespace UnifiedEvents\Platforms;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Utilities\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ZeroBouncePlatform extends AbstractPlatform
{
    protected string $platformCode = 'zerobounce';
    protected string $displayName = 'ZeroBounce';
    protected Client $client;
    protected Logger $logger;
    
    // ZeroBounce configuration
    protected string $apiKey;
    protected bool $checkActivity = true;
    protected int $dailyLimit;
    protected int $dailyUsage = 0;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->apiKey = $config['api_key'] ?? '';
        $this->checkActivity = $config['check_activity'] ?? true;
        $this->dailyLimit = (int)($_ENV['ZEROBOUNCE_DAILY_LIMIT'] ?? 10000);
        
        $this->client = new Client([
            'base_uri' => 'https://api.zerobounce.net',
            'timeout' => $this->timeout,
            'verify' => true
        ]);
        
        $this->logger = new Logger();
    }
    
    /**
     * Send email for validation to ZeroBounce
     */
    public function send(Event $event): array
    {
        if (!$event->email) {
            return [
                'success' => false,
                'error' => 'No email address to validate'
            ];
        }
        
        try {
            // Check daily limit
            if ($this->isDailyLimitReached()) {
                $this->logger->warning('ZeroBounce daily limit reached', [
                    'limit' => $this->dailyLimit,
                    'usage' => $this->dailyUsage
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Daily validation limit reached'
                ];
            }
            
            // Step 1: Validate email
            $validationResult = $this->validateEmail($event);
            
            if (!$validationResult['success']) {
                return $validationResult;
            }
            
            // Step 2: Check activity (if enabled and email is valid)
            $activityResult = null;
            if ($this->checkActivity && 
                in_array($validationResult['validation_data']['status'], ['valid', 'unknown'])) {
                
                $activityResult = $this->checkEmailActivity($event);
                
                // Merge activity data into validation data
                if ($activityResult['success'] && isset($activityResult['activity_data'])) {
                    $validationResult['validation_data']['active_in_days'] = 
                        $activityResult['activity_data']['active_in_days'] ?? null;
                }
            }
            
            return [
                'success' => true,
                'validation_data' => $validationResult['validation_data'],
                'activity_data' => $activityResult['activity_data'] ?? null,
                'credits_remaining' => $validationResult['credits_remaining'] ?? null
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('ZeroBounce error', [
                'email' => $event->email,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate email address
     */
    protected function validateEmail(Event $event): array
    {
        try {
            $response = $this->client->get('/v2/validate', [
                'query' => [
                    'email' => $event->email,
                    'api_key' => $this->apiKey,
                    'ip_address' => $event->ip_address ?? ''
                ],
                'timeout' => $this->timeout
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            // Log the validation
            $this->logger->logEvent(
                'ZeroBounce Validation',
                $event->email,
                'Validation check successful',
                $data['status'] ?? 'unknown',
                'success'
            );
            
            // Increment daily usage
            $this->dailyUsage++;
            
            return [
                'success' => true,
                'validation_data' => $data,
                'credits_remaining' => $data['credits'] ?? null
            ];
            
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            
            $this->logger->logEvent(
                'ZeroBounce Validation',
                $event->email,
                $e->getMessage(),
                $statusCode,
                'failure'
            );
            
            // Handle specific error codes
            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'error' => 'Invalid API key'
                ];
            } elseif ($statusCode === 429) {
                return [
                    'success' => false,
                    'error' => 'Rate limit exceeded'
                ];
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $statusCode
            ];
        }
    }
    
    /**
     * Check email activity
     */
    protected function checkEmailActivity(Event $event): array
    {
        try {
            $response = $this->client->get('/v2/activity', [
                'query' => [
                    'email' => $event->email,
                    'api_key' => $this->apiKey
                ],
                'timeout' => $this->timeout
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            $this->logger->logEvent(
                'ZeroBounce Activity',
                $event->email,
                'Activity check successful',
                'success'
            );
            
            // Increment daily usage
            $this->dailyUsage++;
            
            return [
                'success' => true,
                'activity_data' => $data
            ];
            
        } catch (RequestException $e) {
            $this->logger->logEvent(
                'ZeroBounce Activity',
                $event->email,
                $e->getMessage(),
                $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0,
                'failure'
            );
            
            // Activity check failure is not critical
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get current API credits
     */
    public function getCredits(): ?int
    {
        try {
            $response = $this->client->get('/v2/getcredits', [
                'query' => ['api_key' => $this->apiKey]
            ]);
            
            $data = json_decode($response->getBody(), true);
            return (int)($data['credits'] ?? 0);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get ZeroBounce credits', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Check if daily limit is reached
     */
    protected function isDailyLimitReached(): bool
    {
        // Get today's usage from database
        $today = date('Y-m-d');
        $cacheKey = "zerobounce_usage_$today";
        
        // In production, this would check from a cache/database
        // For now, using instance variable
        return $this->dailyUsage >= $this->dailyLimit;
    }
    
    /**
     * Map fields (not needed for ZeroBounce)
     */
    public function mapFields(Event $event): array
    {
        return [
            'email' => $event->email,
            'ip_address' => $event->ip_address
        ];
    }
    
    /**
     * Handle ZeroBounce response
     */
    public function handleResponse($response): array
    {
        if (is_array($response)) {
            return $response;
        }
        
        return [
            'success' => false,
            'error' => 'Invalid response format'
        ];
    }
    
    /**
     * Validate configuration
     */
    public function validateConfig(): bool
    {
        if (empty($this->apiKey)) {
            throw new \Exception('ZeroBounce API key is required');
        }
        
        return true;
    }
    
    /**
     * Test connection
     */
    public function testConnection(): bool
    {
        try {
            $credits = $this->getCredits();
            return $credits !== null;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get usage statistics
     */
    public function getUsageStats(): array
    {
        return [
            'daily_limit' => $this->dailyLimit,
            'daily_usage' => $this->dailyUsage,
            'credits_remaining' => $this->getCredits()
        ];
    }
}