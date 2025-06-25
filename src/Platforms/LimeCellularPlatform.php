<?php

namespace UnifiedEvents\Platforms;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Utilities\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class LimeCellularPlatform extends AbstractPlatform
{
    protected string $platformCode = 'limecellular';
    protected string $displayName = 'Lime Cellular';
    protected Client $client;
    protected Logger $logger;
    
    // Lime configuration from your code
    protected string $user = 'charles@rif.marketing';
    protected string $apiId = 'S4UWmQBaZ7yeF4z1';
    protected string $listId = '135859';
    protected string $keyword = 'Stock (18882312751)';
    protected string $gender = 'F';
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        // Override with config if provided
        $this->user = $config['user'] ?? $this->user;
        $this->apiId = $config['api_id'] ?? $this->apiId;
        $this->listId = $config['list_id'] ?? $this->listId;
        $this->keyword = $config['keyword'] ?? $this->keyword;
        $this->gender = $config['gender'] ?? $this->gender;
        
        $this->client = new Client([
            'base_uri' => 'https://mcpn.us',
            'timeout' => $this->timeout,
            'verify' => true
        ]);
        
        $this->logger = new Logger();
    }
    
    /**
     * Send SMS opt-in to Lime Cellular
     */
    public function send(Event $event): array
    {
        // Validate phone number
        if (!$event->phone || strlen($event->phone) < 11) {
            return [
                'success' => false,
                'error' => 'Invalid or missing phone number'
            ];
        }
        
        try {
            // Build the payload
            $payload = $this->buildPayload($event);
            
            // Make the API call
            $response = $this->client->post('/limeApi', [
                'query' => [
                    'ev' => 'optin',
                    'format' => 'json',
                    'user' => $this->apiId,
                    'apiId' => 'v7E7A8560AAzv51q' // From your code
                ],
                'json' => $payload,
                'timeout' => $this->timeout
            ]);
            
            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $responseData = json_decode($body, true);
            
            // Log the event
            $this->logger->logEvent(
                'LimeCellular',
                $event->email ?? '',
                "Mobile: {$event->phone}, Response: $body",
                'success'
            );
            
            return [
                'success' => $statusCode === 200,
                'status_code' => $statusCode,
                'platform_response' => $responseData,
                'subscriber_id' => $responseData['subscriber_id'] ?? null
            ];
            
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            
            $this->logger->logEvent(
                'LimeCellular',
                $event->email ?? '',
                $e->getMessage(),
                $statusCode,
                'failure'
            );
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $statusCode,
                'platform_response' => $body
            ];
        } catch (\Exception $e) {
            $this->logger->error('LimeCellular general error', [
                'email' => $event->email,
                'phone' => $event->phone,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Build the payload for Lime Cellular
     */
    protected function buildPayload(Event $event): array
    {
        $name = trim(($event->first_name ?? '') . ' ' . ($event->last_name ?? ''));
        if (empty($name)) {
            $name = $event->first_name ?? 'Reader';
        }
        
        return [
            'user' => $this->user,
            'apiId' => $this->apiId,
            'listId' => $this->listId,
            'keyword' => $this->keyword,
            'mobile' => $event->phone,
            'firstName' => $name,
            'email' => $event->email ?? '',
            'gender' => $this->gender,
            'format' => 'json',
            'isJson' => 'true',
            'tag' => $event->acq_form_title ?? ''
        ];
    }
    
    /**
     * Map event fields to Lime format
     */
    public function mapFields(Event $event): array
    {
        return $this->buildPayload($event);
    }
    
    /**
     * Handle Lime response
     */
    public function handleResponse($response): array
    {
        if (is_array($response)) {
            return $response;
        }
        
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'success' => isset($decoded['status']) && $decoded['status'] === 'success',
                    'data' => $decoded
                ];
            }
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
        $required = ['user', 'apiId', 'listId'];
        
        foreach ($required as $field) {
            if (empty($this->$field)) {
                throw new \Exception("LimeCellular $field is required");
            }
        }
        
        return true;
    }
    
    /**
     * Test connection
     */
    public function testConnection(): bool
    {
        try {
            // There's no specific test endpoint, so we'll just verify we can reach the API
            $response = $this->client->get('/limeApi', [
                'query' => [
                    'ev' => 'ping',
                    'apiId' => $this->apiId
                ],
                'timeout' => 5
            ]);
            
            return $response->getStatusCode() < 500;
            
        } catch (\Exception $e) {
            $this->logger->error('LimeCellular connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get platform metrics
     */
    public function getMetrics(): array
    {
        return [
            'platform' => $this->platformCode,
            'list_id' => $this->listId,
            'keyword' => $this->keyword
        ];
    }
}