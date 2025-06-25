<?php

namespace UnifiedEvents\Platforms;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Models\ProcessingLog;
use UnifiedEvents\Utilities\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Carbon\Carbon;

class ActiveCampaignPlatform extends AbstractPlatform
{
    protected string $platformCode = 'activecampaign';
    protected string $displayName = 'ActiveCampaign';
    protected Client $client;
    protected Logger $logger;
    
    // ActiveCampaign configuration
    protected string $apiUrl;
    protected string $apiKey;
    protected int $listId = 2; // Default list ID from your code
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->apiUrl = $config['api_url'] ?? 'https://rif868.api-us1.com';
        $this->apiKey = $config['api_key'] ?? '';
        $this->listId = $config['list_id'] ?? 2;
        
        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'timeout' => 30,
            'verify' => true
        ]);
        
        $this->logger = new Logger();
    }
    
    /**
     * Send event data to ActiveCampaign
     */
    public function send(Event $event): array
    {
        try {
            // First sync - all data
            $payload = $this->buildPayload($event);
            $response = $this->makeApiCall('contact_sync', $payload);
            
            // CRITICAL: Check if contact was updated (not new)
            if ($this->isContactUpdate($response)) {
                $this->logger->info('ActiveCampaign contact updated, sending LAST_SUB_DATE update', [
                    'email' => $event->email,
                    'response' => $response
                ]);
                
                // Second sync with LAST_SUB_DATE
                $updatePayload = $this->buildUpdatePayload($event);
                $updateResponse = $this->makeApiCall('contact_sync', $updatePayload);
                
                return [
                    'success' => true,
                    'platform_response' => [
                        'initial_sync' => $response,
                        'update_sync' => $updateResponse
                    ],
                    'contact_id' => $this->extractContactId($updateResponse),
                    'was_update' => true
                ];
            }
            
            return [
                'success' => true,
                'platform_response' => $response,
                'contact_id' => $this->extractContactId($response),
                'was_update' => false
            ];
            
        } catch (RequestException $e) {
            $this->logger->error('ActiveCampaign API request failed', [
                'email' => $event->email,
                'error' => $e->getMessage(),
                'response_body' => $e->hasResponse() ? (string)$e->getResponse()->getBody() : null
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'platform_response' => $e->hasResponse() ? (string)$e->getResponse()->getBody() : null
            ];
        } catch (\Exception $e) {
            $this->logger->error('ActiveCampaign general error', [
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
     * Build the complete payload for ActiveCampaign
     * This includes ALL fields from your original implementation
     */
    protected function buildPayload(Event $event): array
    {
        $payload = [
            // Core contact fields
            'email' => $event->email,
            'first_name' => $event->first_name ?? 'Reader',
            'last_name' => $event->last_name ?? '',
            'phone' => $event->phone ?? '',
            'ip4' => $event->ip_address ?? '',
            
            // Custom fields - preserving exact field names from your code
            'field[%ACQSOURCE%,0]' => $event->source ?? $event->acq_source ?? '',
            'field[%ACQDATE%,0]' => $event->acq_date ? $event->acq_date->format('F j, Y, g:i a') : Carbon::now()->format('F j, Y, g:i a'),
            'field[%ACQCAMPAIGN%,0]' => $event->campaign ?? $event->acq_campaign ?? '',
            'field[%ACQCONTENT%,0]' => $event->content ?? '',
            'field[%ACQTERM%,0]' => $event->term ?? $event->acq_term ?? '',
            'field[%FORMTITLE%,0]' => $event->acq_form_title ?? '',
            'field[%GCLID%,0]' => $event->gclid ?? '',
            'field[%GA_CLIENT_ID%,0]' => $event->ga_client_id ?? '',
            'field[%TIMEZONE%,0]' => $event->event_data['timezone'] ?? '0',
            'field[%WOOTRACKER%,0]' => $event->event_data['woo_tracker'] ?? '',
            'field[%ZBLASTACTIVE%,0]' => $event->zb_last_active ?? '',
            'field[%MD5%,0]' => $event->email_md5 ?? '',
            
            // List subscription settings
            'p[' . $this->listId . ']' => $this->listId,
            'instantresponders[' . $this->listId . ']' => 0,
            'status[' . $this->listId . ']' => 1,
            
            // Important: overwrite existing data
            'overwrite' => 1
        ];
        
        // Add purchase-specific fields if this is a purchase event
        if ($event->isPurchase()) {
            $payload['field[%LAST_PURCHASE_DATE%,0]'] = Carbon::now()->format('m/d/Y');
            $payload['field[%LAST_PURCHASE_OFFER%,0]'] = $event->offer ?? '';
            $payload['field[%LAST_PURCHASE_AMOUNT%,0]'] = $event->amount ?? '';
            $payload['field[%PURCHASE_SOURCE%,0]'] = $event->purchase_source ?? '';
            $payload['field[%PUBLISHER%,0]'] = $event->publisher ?? '';
        }
        
        return $payload;
    }
    
    /**
     * Build the update payload for LAST_SUB_DATE
     * This is sent only when a contact is updated (not new)
     */
    protected function buildUpdatePayload(Event $event): array
    {
        return [
            'email' => $event->email,
            'field[%LAST_SUB_DATE%,0]' => Carbon::now()->format('m/d/Y'),
            'field[%MD5%,0]' => $event->email_md5 ?? '',
            'phone' => $event->phone ?? '',
            'ip4' => $event->ip_address ?? '',
            'p[' . $this->listId . ']' => $this->listId,
            'instantresponders[' . $this->listId . ']' => 0,
            'overwrite' => 1,
            'status[' . $this->listId . ']' => 1
        ];
    }
    
    /**
     * Make API call to ActiveCampaign
     */
    protected function makeApiCall(string $action, array $params): string
    {
        $uri = "/admin/api.php";
        
        $queryParams = [
            'api_key' => $this->apiKey,
            'api_action' => $action,
            'api_output' => 'serialize'
        ];
        
        $response = $this->client->post($uri, [
            'query' => $queryParams,
            'form_params' => $params,
            'timeout' => $this->timeout
        ]);
        
        return (string)$response->getBody();
    }
    
    /**
     * Check if the response indicates a contact update (not new)
     */
    protected function isContactUpdate(string $response): bool
    {
        // Check for "Contact updated" in the serialized response
        return stripos($response, 'Contact updated') !== false;
    }
    
    /**
     * Extract contact ID from response
     */
    protected function extractContactId(string $response): ?int
    {
        // Try to unserialize the response
        $data = @unserialize($response);
        
        if ($data && isset($data['subscriber_id'])) {
            return (int)$data['subscriber_id'];
        }
        
        // Try regex as fallback
        if (preg_match('/subscriber_id["\']?\s*[:=]\s*["\']?(\d+)/', $response, $matches)) {
            return (int)$matches[1];
        }
        
        return null;
    }
    
    /**
     * Validate that we have required configuration
     */
    public function validateConfig(): bool
    {
        if (empty($this->apiKey)) {
            throw new \Exception('ActiveCampaign API key is required');
        }
        
        if (empty($this->apiUrl)) {
            throw new \Exception('ActiveCampaign API URL is required');
        }
        
        return true;
    }
    
    /**
     * Map event fields to platform-specific fields
     */
    public function mapFields(Event $event): array
    {
        // This is handled in buildPayload for ActiveCampaign
        return $this->buildPayload($event);
    }
    
    /**
     * Handle platform-specific response
     */
    public function handleResponse($response): array
    {
        if (is_string($response)) {
            $data = @unserialize($response);
            
            if ($data === false) {
                // If unserialize fails, check for success indicators in raw response
                $success = stripos($response, 'Contact added') !== false || 
                          stripos($response, 'Contact updated') !== false;
                
                return [
                    'success' => $success,
                    'raw_response' => $response
                ];
            }
            
            return [
                'success' => isset($data['result_code']) && $data['result_code'] == 1,
                'data' => $data
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Invalid response format'
        ];
    }
    
    /**
     * Get platform-specific metrics
     */
    public function getMetrics(): array
    {
        return [
            'platform' => $this->platformCode,
            'list_id' => $this->listId,
            'api_url' => $this->apiUrl
        ];
    }
    
    /**
     * Test the connection to ActiveCampaign
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->makeApiCall('account_view', []);
            return stripos($response, 'result_code') !== false;
        } catch (\Exception $e) {
            $this->logger->error('ActiveCampaign connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}