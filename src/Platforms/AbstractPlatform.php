<?php

namespace UnifiedEvents\Platforms;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Models\ProcessingQueue;
use UnifiedEvents\Models\ProcessingLog;
use GuzzleHttp\Client;

abstract class AbstractPlatform
{
    protected string $platformCode;
    protected string $displayName;
    protected array $config;
    protected int $maxRetries = 3;
    protected int $retryDelay = 1000; // milliseconds
    protected int $timeout = 30; // seconds
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryDelay = $config['retry_delay'] ?? 1000;
        $this->timeout = $config['timeout'] ?? 30;
    }
    
    /**
     * Send event data to the platform
     */
    abstract public function send(Event $event): array;
    
    /**
     * Map event fields to platform-specific fields
     */
    abstract public function mapFields(Event $event): array;
    
    /**
     * Handle platform-specific response
     */
    abstract public function handleResponse($response): array;
    
    /**
     * Validate platform configuration
     */
    abstract public function validateConfig(): bool;
    
    /**
     * Test platform connection
     */
    abstract public function testConnection(): bool;
    
    /**
     * Get platform code
     */
    public function getPlatformCode(): string
    {
        return $this->platformCode;
    }
    
    /**
     * Get display name
     */
    public function getDisplayName(): string
    {
        return $this->displayName;
    }
    
    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Perform request with retry logic
     */
    protected function requestWithRetry(callable $request): mixed
    {
        $lastException = null;
        
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            try {
                return $request();
            } catch (\Exception $e) {
                $lastException = $e;
                
                if ($attempt < $this->maxRetries - 1) {
                    usleep($this->retryDelay * 1000 * pow(2, $attempt));
                }
            }
        }
        
        throw $lastException;
    }
}