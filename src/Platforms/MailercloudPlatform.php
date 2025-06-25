<?php

namespace UnifiedEvents\Platforms;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Utilities\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Carbon\Carbon;

class MailercloudPlatform extends AbstractPlatform
{
    protected string $platformCode = 'mailercloud';
    protected string $displayName = 'MailerCloud';
    protected Client $client;
    protected Logger $logger;
    
    // MailerCloud configuration
    protected string $apiKey;
    protected array $contactLists = ['UIWWUc']; // Default list from your code
    protected string $sourceSite = 'wswd';
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->apiKey = $config['api_key'] ?? '';
        $this->contactLists = $config['contact_lists'] ?? ['UIWWUc'];
        $this->sourceSite = $config['source_site'] ?? 'wswd';
        
        $this->client = new Client([
            'base_uri' => 'https://api.mailercloud.com',
            'timeout' => $this->timeout,
            'verify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ]
        ]);
        
        $this->logger = new Logger();
    }
    
    /**
     * Send contact to MailerCloud
     */
    public function send(Event $event): array
    {
        if (!$event->email) {
            return [
                'success' => false,
                'error' => 'Email is required for MailerCloud'
            ];
        }
        
        try {
            // Build the payload
            $payload = $this->buildPayload($event);
            
            // Make the API request
            $response = $this->client->post('v1/contacts', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey
                ],
                'json' => $payload,
                'timeout' => $this->timeout
            ]);
            
            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody(), true);
            
            // Log the event
            $this->logger->logEvent(
                'Mailercloud',
                $event->email,
                json_encode($responseBody),
                $statusCode,
                'success'
            );
            
            return [
                'success' => true,
                'status_code' => $statusCode,
                'platform_response' => $responseBody,
                'contact_id' => $responseBody['data']['id'] ?? null
            ];
            
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            
            // Try to decode error response
            $errorData = [];
            if ($body) {
                $errorData = json_decode($body, true) ?: ['raw' => $body];
            }
            
            $this->logger->logEvent(
                'Mailercloud',
                $event->email,
                $e->getMessage(),
                $statusCode,
                'failure'
            );
            
            // Handle specific error codes
            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'error' => 'Invalid API key',
                    'status_code' => $statusCode
                ];
            } elseif ($statusCode === 422) {
                return [
                    'success' => false,
                    'error' => 'Validation error: ' . json_encode($errorData),
                    'status_code' => $statusCode,
                    'validation_errors' => $errorData
                ];
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $statusCode,
                'platform_response' => $errorData
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('MailerCloud general error', [
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
     * Build the payload for MailerCloud
     * Matching the exact structure from your code
     */
    protected function buildPayload(Event $event): array
    {
        $payload = [
            'email' => $event->email,
            'first_name' => $event->first_name ?? '',
            'last_name' => $event->last_name ?? '',
            'contact_lists' => $this->contactLists,
            'custom_fields' => [
                'zb_last_active' => $event->zb_last_active ?? '',
                'acq_source' => $event->source ?? $event->acq_source ?? '',
                'acq_campaign' => $event->campaign ?? $event->acq_campaign ?? '',
                'acq_term' => $event->term ?? $event->acq_term ?? '',
                'acq_content' => $event->content ?? '',
                'form_title' => $event->acq_form_title ?? '',
                'md5_email' => $event->email_md5 ?? '',
                'ga_client_id' => $event->ga_client_id ?? '',
                'source_site' => $this->sourceSite,
                'gclid' => $event->gclid ?? '',
                'ipv4' => $event->ip_address ?? '',
                'acq_date' => $event->acq_date ? $event->acq_date->format('F j, Y, g:i a') : Carbon::now()->format('F j, Y, g:i a')
            ]
        ];
        
        // Add phone number if valid (11 digits)
        if ($event->phone && strlen($event->phone) === 11) {
            $payload['phone_number'] = '+' . $event->phone;
        }
        
        // Add purchase-specific fields if this is a purchase event
        if ($event->isPurchase()) {
            $payload['custom_fields']['last_purchase_date'] = Carbon::now()->format('Y-m-d');
            $payload['custom_fields']['last_purchase_offer'] = $event->offer ?? '';
            $payload['custom_fields']['last_purchase_amount'] = $event->amount ?? '';
            $payload['custom_fields']['publisher'] = $event->publisher ?? '';
        }
        
        return $payload;
    }
    
    /**
     * Update an existing contact
     */
    public function updateContact(string $contactId, array $data): array
    {
        try {
            $response = $this->client->put("v1/contacts/$contactId", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey
                ],
                'json' => $data,
                'timeout' => $this->timeout
            ]);
            
            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'platform_response' => json_decode($response->getBody(), true)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Add contact to a list
     */
    public function addToList(string $email, string $listId): array
    {
        try {
            $response = $this->client->post("v1/lists/$listId/contacts", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey
                ],
                'json' => ['email' => $email],
                'timeout' => $this->timeout
            ]);
            
            return [
                'success' => true,
                'status_code' => $response->getStatusCode()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Map event fields to MailerCloud format
     */
    public function mapFields(Event $event): array
    {
        return $this->buildPayload($event);
    }
    
    /**
     * Handle MailerCloud response
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
                    'success' => isset($decoded['data']),
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
        if (empty($this->apiKey)) {
            throw new \Exception('MailerCloud API key is required');
        }
        
        if (empty($this->contactLists)) {
            throw new \Exception('At least one contact list must be specified');
        }
        
        return true;
    }
    
    /**
     * Test connection
     */
    public function testConnection(): bool
    {
        try {
            // Try to get account info
            $response = $this->client->get('v1/account', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey
                ],
                'timeout' => 5
            ]);
            
            return $response->getStatusCode() === 200;
            
        } catch (\Exception $e) {
            $this->logger->error('MailerCloud connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get list statistics
     */
    public function getListStats(string $listId): array
    {
        try {
            $response = $this->client->get("v1/lists/$listId", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey
                ]
            ]);
            
            return json_decode($response->getBody(), true);
            
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get platform metrics
     */
    public function getMetrics(): array
    {
        return [
            'platform' => $this->platformCode,
            'contact_lists' => $this->contactLists,
            'source_site' => $this->sourceSite
        ];
    }
}