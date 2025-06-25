<?php

namespace UnifiedEvents\Platforms;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Utilities\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Carbon\Carbon;

class MarketbeatPlatform extends AbstractPlatform
{
    protected string $platformCode = 'marketbeat';
    protected string $displayName = 'Marketbeat';
    protected Client $client;
    protected Logger $logger;
    
    // Marketbeat configuration
    protected string $source = 'tgcoreg';
    protected string $sitePrefix = 'wswd';
    protected float $revenuePerLead = 2.00;
    protected float $acquisitionCost = 2.00;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->source = $config['source'] ?? 'tgcoreg';
        $this->sitePrefix = $config['site_prefix'] ?? 'wswd';
        $this->revenuePerLead = (float)($config['revenue_per_lead'] ?? 2.00);
        $this->acquisitionCost = (float)($config['acquisition_cost'] ?? 2.00);
        
        $this->client = new Client([
            'base_uri' => 'https://www.americanconsumernews.net',
            'timeout' => $this->timeout,
            'verify' => true
        ]);
        
        $this->logger = new Logger();
    }
    
    /**
     * Send lead to Marketbeat
     */
    public function send(Event $event): array
    {
        if (!$event->email) {
            return [
                'success' => false,
                'error' => 'Email is required for Marketbeat'
            ];
        }
        
        try {
            // Build query parameters
            $params = [
                'Email' => $event->email,
                'Source' => $this->source,
                'Site' => $this->sitePrefix . '-' . ($event->campaign ?? $event->acq_campaign ?? ''),
                'name' => urlencode($event->first_name ?? 'Reader'),
                'AcquisitionCost' => $this->acquisitionCost
            ];
            
            // Make the request
            $response = $this->client->get('scripts/mobileemail.ashx', [
                'query' => $params,
                'timeout' => $this->timeout
            ]);
            
            $statusCode = $response->getStatusCode();
            $body = trim((string)$response->getBody());
            
            // Check if successful
            $isSuccess = $body === 'Success';
            $revenue = $isSuccess ? $this->revenuePerLead : 0;
            
            // Log to event log
            $this->logger->logEvent(
                'Marketbeat',
                $event->email,
                $body,
                $statusCode,
                $isSuccess ? 'success' : 'failure'
            );
            
            // Log to cobrand CSV (matching your format)
            $this->logToCobrandCsv($event, $body);
            
            // Update event data with Marketbeat results
            if ($event->event_data === null) {
                $event->event_data = [];
            }
            $event->event_data['marketbeat_success'] = $isSuccess;
            $event->event_data['marketbeat_revenue'] = $revenue;
            $event->save();
            
            return [
                'success' => $isSuccess,
                'status_code' => $statusCode,
                'platform_response' => $body,
                'revenue' => $revenue,
                'message' => $isSuccess ? 'Lead accepted by Marketbeat' : 'Lead rejected: ' . $body
            ];
            
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            
            $this->logger->logEvent(
                'Marketbeat',
                $event->email,
                $body,
                $statusCode,
                'failure'
            );
            
            $this->logToCobrandCsv($event, 'Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $statusCode,
                'platform_response' => $body,
                'revenue' => 0
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Marketbeat general error', [
                'email' => $event->email,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'revenue' => 0
            ];
        }
    }
    
    /**
     * Log to cobrand CSV file (matching your original format)
     */
    protected function logToCobrandCsv(Event $event, string $response): void
    {
        try {
            $logDir = Logger::getLogPath();
            $csvPath = $logDir . 'mb_cobrand.csv';
            
            $csvLine = sprintf(
                "%s, %s, %s, %s\n",
                Carbon::now()->format('F j, Y, g:i a'),
                $this->sitePrefix,
                $event->email,
                $response
            );
            
            file_put_contents($csvPath, $csvLine, FILE_APPEND | LOCK_EX);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to write to cobrand CSV', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Map fields (minimal for Marketbeat)
     */
    public function mapFields(Event $event): array
    {
        return [
            'Email' => $event->email,
            'name' => $event->first_name ?? 'Reader',
            'Source' => $this->source,
            'Site' => $this->sitePrefix . '-' . ($event->campaign ?? '')
        ];
    }
    
    /**
     * Handle Marketbeat response
     */
    public function handleResponse($response): array
    {
        if (is_string($response)) {
            $success = trim($response) === 'Success';
            return [
                'success' => $success,
                'response' => $response,
                'revenue' => $success ? $this->revenuePerLead : 0
            ];
        }
        
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
        if ($this->revenuePerLead <= 0) {
            throw new \Exception('Revenue per lead must be greater than 0');
        }
        
        return true;
    }
    
    /**
     * Test connection
     */
    public function testConnection(): bool
    {
        try {
            // Test with a known invalid email to verify endpoint is reachable
            $response = $this->client->get('scripts/mobileemail.ashx', [
                'query' => [
                    'Email' => 'test@invalid-domain-test.com',
                    'Source' => $this->source,
                    'Site' => $this->sitePrefix . '-test',
                    'name' => 'Test',
                    'AcquisitionCost' => 0
                ],
                'timeout' => 5
            ]);
            
            // If we get any response, the connection works
            return $response->getStatusCode() > 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Marketbeat connection test failed', [
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
            'revenue_per_lead' => $this->revenuePerLead,
            'source' => $this->source,
            'site_prefix' => $this->sitePrefix
        ];
    }
    
    /**
     * Calculate total revenue for a period
     */
    public function calculateRevenue(Carbon $startDate, Carbon $endDate): array
    {
        try {
            $logDir = Logger::getLogPath();
            $csvPath = $logDir . 'mb_cobrand.csv';
            
            if (!file_exists($csvPath)) {
                return [
                    'total_leads' => 0,
                    'successful_leads' => 0,
                    'total_revenue' => 0.00
                ];
            }
            
            $totalLeads = 0;
            $successfulLeads = 0;
            
            $handle = fopen($csvPath, 'r');
            while (($line = fgets($handle)) !== false) {
                // Parse CSV line
                $parts = str_getcsv($line);
                if (count($parts) >= 4) {
                    $dateStr = trim($parts[0]);
                    $response = trim($parts[3]);
                    
                    // Parse date
                    try {
                        $date = Carbon::parse($dateStr);
                        if ($date->between($startDate, $endDate)) {
                            $totalLeads++;
                            if ($response === 'Success') {
                                $successfulLeads++;
                            }
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
            fclose($handle);
            
            return [
                'total_leads' => $totalLeads,
                'successful_leads' => $successfulLeads,
                'total_revenue' => $successfulLeads * $this->revenuePerLead
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate Marketbeat revenue', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'total_leads' => 0,
                'successful_leads' => 0,
                'total_revenue' => 0.00
            ];
        }
    }
}