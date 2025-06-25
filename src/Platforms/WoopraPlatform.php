<?php

namespace UnifiedEvents\Platforms;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Models\ProcessingLog;
use UnifiedEvents\Utilities\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Carbon\Carbon;

class WoopraPlatform extends AbstractPlatform
{
    protected string $platformCode = 'woopra';
    protected string $displayName = 'Woopra';
    protected Client $client;
    protected Logger $logger;
    
    // Woopra configuration
    protected string $domain = 'wallstreetwatchdogs.com';
    protected string $project;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->domain = $config['domain'] ?? 'wallstreetwatchdogs.com';
        $this->project = $config['project'] ?? 'wallstreetwatchdogs.com';
        
        $this->client = new Client([
            'base_uri' => 'http://www.woopra.com',
            'timeout' => $this->timeout,
            'verify' => true
        ]);
        
        $this->logger = new Logger();
    }
    
    /**
     * Send event data to Woopra
     * Handles multiple event types: Identify, ProcessEmail, ProcessSMS, MBCobrand
     */
    public function send(Event $event): array
    {
        $results = [];
        
        try {
            // Step 1: Always identify the visitor first
            $identifyResult = $this->identify($event);
            $results['identify'] = $identifyResult;
            
            // Step 2: Track the main event (process_form)
            $processResult = $this->trackProcessEmail($event);
            $results['process_email'] = $processResult;
            
            // Step 3: If phone number exists, track SMS event
            if ($event->phone && strlen($event->phone) >= 11) {
                $smsResult = $this->trackProcessSMS($event);
                $results['process_sms'] = $smsResult;
            }
            
            // Step 4: If this came from Marketbeat cobrand, track that
            if ($this->isMarketbeatCobrand($event)) {
                $cobrandResult = $this->trackMarketbeatCobrand($event);
                $results['mb_cobrand'] = $cobrandResult;
            }
            
            // Determine overall success
            $overallSuccess = $identifyResult['success'] && $processResult['success'];
            
            return [
                'success' => $overallSuccess,
                'platform_response' => $results,
                'events_sent' => count(array_filter($results, fn($r) => $r['success'] ?? false))
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Woopra platform error', [
                'email' => $event->email,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'platform_response' => $results
            ];
        }
    }
    
    /**
     * Identify visitor in Woopra
     */
    protected function identify(Event $event): array
    {
        $params = [
            'project' => $this->project,
            'cookie' => $event->event_data['woo_tracker'] ?? $this->generateWoopraId($event),
            'cv_email' => $event->email,
            'cv_phonenumber' => $event->phone ?? '',
            'cv_md5' => $event->email_md5 ?? ''
        ];
        
        return $this->makeWoopraRequest('track/identify', $params, 'WoopraIdentify');
    }
    
    /**
     * Track process_form event (email submission)
     */
    protected function trackProcessEmail(Event $event): array
    {
        // Generate random values like in original code
        $randA = rand(1, 100);
        $randB = rand(1, 100);
        $randC = rand(1, 100);
        
        $params = [
            'domain' => $this->domain,
            'event' => 'process_form',
            'ce_form_title' => $event->acq_form_title ?? '',
            'cv_first_name' => $event->first_name ?? '',
            'cv_last_name' => $event->last_name ?? '',
            'cv_is_cobranded' => 'false', // Will be updated if Marketbeat
            'cv_ga_client_id' => $event->ga_client_id ?? '',
            'cv_email' => $event->email,
            'cv_name' => trim(($event->first_name ?? '') . ' ' . ($event->last_name ?? '')),
            'ce_phonenumber' => $event->phone ?? '',
            'cv_phonenumber' => $event->phone ?? '',
            'ce_acq_source' => $event->source ?? $event->acq_source ?? '',
            'ce_acq_campaign' => $event->campaign ?? $event->acq_campaign ?? '',
            'ce_acq_content' => $event->content ?? '',
            'ce_acq_term' => $event->term ?? $event->acq_term ?? '',
            'cv_acq_source' => $event->source ?? $event->acq_source ?? '',
            'cv_acq_campaign' => $event->campaign ?? $event->acq_campaign ?? '',
            'cv_acq_content' => $event->content ?? '',
            'cv_acq_term' => $event->term ?? $event->acq_term ?? '',
            'cv_form_title' => $event->acq_form_title ?? '',
            'cv_gclid' => $event->gclid ?? '',
            'cv_ipv4' => $event->ip_address ?? '',
            'ip' => $event->ip_address ?? '',
            'cv_acq_date' => $event->acq_date ? $event->acq_date->format('F j, Y, g:i a') : Carbon::now()->format('F j, Y, g:i a'),
            'cookie' => $event->event_data['woo_tracker'] ?? $this->generateWoopraId($event),
            'cv_md5' => $event->email_md5 ?? '',
            'cs_gclid' => $event->gclid ?? '',
            'cv_rand_a' => $randA,
            'cv_rand_b' => $randB,
            'cv_rand_c' => $randC,
            'cv_zb_last_active' => $event->zb_last_active ?? '',
            'ce_zb_last_active' => $event->zb_last_active ?? ''
        ];
        
        return $this->makeWoopraRequest('track/ce', $params, 'WoopraProcessEmail');
    }
    
    /**
     * Track process_sms event
     */
    protected function trackProcessSMS(Event $event): array
    {
        $params = [
            'domain' => $this->domain,
            'event' => 'process_sms',
            'cv_first_name' => $event->first_name ?? '',
            'cv_last_name' => $event->last_name ?? '',
            'ce_form_title' => $event->acq_form_title ?? '',
            'ce_phonenumber' => $event->phone,
            'cv_phonenumber' => $event->phone,
            'cv_ga_client_id' => $event->ga_client_id ?? '',
            'cv_email' => $event->email,
            'cv_name' => trim(($event->first_name ?? '') . ' ' . ($event->last_name ?? '')),
            'cv_md5' => $event->email_md5 ?? '',
            'ce_acq_source' => $event->source ?? $event->acq_source ?? '',
            'ce_acq_campaign' => $event->campaign ?? $event->acq_campaign ?? '',
            'ce_checkbox' => 'no-checkbox',
            'ce_acq_content' => $event->content ?? '',
            'ce_acq_term' => $event->term ?? $event->acq_term ?? '',
            'cv_acq_source' => $event->source ?? $event->acq_source ?? '',
            'cv_acq_campaign' => $event->campaign ?? $event->acq_campaign ?? '',
            'cv_acq_content' => $event->content ?? '',
            'cv_acq_term' => $event->term ?? $event->acq_term ?? '',
            'cv_form_title' => $event->acq_form_title ?? '',
            'cv_ipv4' => $event->ip_address ?? '',
            'ip' => $event->ip_address ?? '',
            'cookie' => $event->event_data['woo_tracker'] ?? $this->generateWoopraId($event)
        ];
        
        return $this->makeWoopraRequest('track/ce', $params, 'WoopraProcessSMS');
    }
    
    /**
     * Track Marketbeat cobrand event
     */
    protected function trackMarketbeatCobrand(Event $event): array
    {
        $revenue = $event->event_data['marketbeat_revenue'] ?? 0;
        
        $params = [
            'domain' => $this->domain,
            'event' => 'sent_to_cobranded_list',
            'cv_email' => $event->email,
            'cv_cobranded_with_marketbeat' => 'true',
            'ce_cobranded_with_marketbeat' => 'true',
            'ce_cobranded_with' => 'marketbeat',
            'cv_cobranded_with' => 'marketbeat',
            'ce_revenue' => $revenue,
            'cookie' => $event->event_data['woo_tracker'] ?? $this->generateWoopraId($event)
        ];
        
        return $this->makeWoopraRequest('track/ce', $params, 'WoopraMBCobrand');
    }
    
    /**
     * Make request to Woopra API
     */
    protected function makeWoopraRequest(string $endpoint, array $params, string $eventType): array
    {
        try {
            $response = $this->client->get($endpoint, [
                'query' => $params,
                'timeout' => $this->timeout
            ]);
            
            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            
            // Woopra returns 200 or 202 for success
            $success = in_array($statusCode, [200, 202]);
            
            // Log the event
            $this->logger->logEvent(
                $eventType,
                $params['cv_email'] ?? $params['email'] ?? 'unknown',
                $body,
                $statusCode,
                $success ? 'success' : 'failure'
            );
            
            return [
                'success' => $success,
                'status_code' => $statusCode,
                'response' => $body
            ];
            
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            
            $this->logger->logEvent(
                $eventType,
                $params['cv_email'] ?? $params['email'] ?? 'unknown',
                $body,
                $statusCode,
                'failure'
            );
            
            return [
                'success' => false,
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
                'response' => $body
            ];
        }
    }
    
    /**
     * Generate Woopra tracking ID if not provided
     */
    protected function generateWoopraId(Event $event): string
    {
        // Generate a consistent ID based on email or random
        if ($event->email) {
            return substr(md5($event->email . $this->domain), 0, 16);
        }
        
        return bin2hex(random_bytes(8));
    }
    
    /**
     * Check if this event is from Marketbeat cobrand
     */
    protected function isMarketbeatCobrand(Event $event): bool
    {
        // Check if Marketbeat revenue is set in event data
        if (isset($event->event_data['marketbeat_revenue']) && $event->event_data['marketbeat_revenue'] > 0) {
            return true;
        }
        
        // Check if Marketbeat platform was successful
        if (isset($event->event_data['marketbeat_success']) && $event->event_data['marketbeat_success']) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Map event fields to Woopra format
     */
    public function mapFields(Event $event): array
    {
        // This is handled in individual tracking methods
        return [];
    }
    
    /**
     * Handle Woopra response
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
        if (empty($this->domain)) {
            throw new \Exception('Woopra domain is required');
        }
        
        if (empty($this->project)) {
            throw new \Exception('Woopra project is required');
        }
        
        return true;
    }
    
    /**
     * Test Woopra connection
     */
    public function testConnection(): bool
    {
        try {
            // Simple identify call to test connection
            $params = [
                'project' => $this->project,
                'cookie' => 'test_' . time(),
                'cv_email' => 'test@example.com'
            ];
            
            $result = $this->makeWoopraRequest('track/identify', $params, 'WoopraTest');
            return $result['success'];
            
        } catch (\Exception $e) {
            $this->logger->error('Woopra connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}