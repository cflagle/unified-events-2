<?php

namespace UnifiedEvents\Services;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Models\BotRegistry;
use UnifiedEvents\Models\EmailValidationRegistry;
use UnifiedEvents\Utilities\Logger;
use UnifiedEvents\Utilities\Database;

class ValidationService
{
    private Logger $logger;
    private Database $db;
    private array $honeypotFields = ['zipcode', 'phonenumber']; // From your code
    private int $validationCacheDays = 30;
    
    public function __construct()
    {
        $this->logger = new Logger();
        $this->db = new Database();
        $this->validationCacheDays = (int)($_ENV['VALIDATION_CACHE_DAYS'] ?? 30);
    }
    
    /**
     * Run all pre-processing validations on an event
     * Returns array with validation results
     */
    public function validateEvent(Event $event, array $rawRequest): array
    {
        $results = [
            'is_valid' => true,
            'is_bot' => false,
            'bot_reason' => null,
            'email_valid' => null,
            'email_validation_source' => null,
            'needs_zerobounce' => true,
            'validation_errors' => []
        ];
        
        // Step 1: Honeypot bot check
        $botCheck = $this->checkHoneypot($rawRequest);
        if ($botCheck['is_bot']) {
            $results['is_valid'] = false;
            $results['is_bot'] = true;
            $results['bot_reason'] = 'honeypot_triggered';
            
            // Record the bot
            $this->recordBot($event, $rawRequest, $botCheck['triggered_fields']);
            
            $this->logger->logBotDetection(
                $event->email ?? 'unknown',
                $event->ip_address ?? 'unknown',
                $botCheck['triggered_fields']
            );
            
            return $results; // Stop here if bot detected
        }
        
        // Step 2: Check if email/IP is in bot registry
        if ($this->isKnownBot($event)) {
            $results['is_valid'] = false;
            $results['is_bot'] = true;
            $results['bot_reason'] = 'known_bot';
            
            $this->logger->info('Known bot attempted submission', [
                'email' => $event->email,
                'ip' => $event->ip_address
            ]);
            
            return $results;
        }
        
        // Step 3: Check email validation cache
        if ($event->email) {
            $cachedValidation = $this->checkEmailCache($event->email);
            
            if ($cachedValidation !== null) {
                $results['email_valid'] = $cachedValidation['is_valid'];
                $results['email_validation_source'] = 'cache';
                $results['needs_zerobounce'] = $cachedValidation['needs_revalidation'];
                
                // If email is known invalid, mark event as invalid
                if (!$cachedValidation['is_valid']) {
                    $results['is_valid'] = false;
                    $results['validation_errors'][] = 'Email address is invalid';
                }
                
                // Update event with cached data
                if (isset($cachedValidation['zb_last_active'])) {
                    $event->zb_last_active = $cachedValidation['zb_last_active'];
                }
            }
        }
        
        // Step 4: Basic email format validation
        if ($event->email && !filter_var($event->email, FILTER_VALIDATE_EMAIL)) {
            $results['is_valid'] = false;
            $results['email_valid'] = false;
            $results['validation_errors'][] = 'Invalid email format';
        }
        
        // Step 5: Phone number validation (if provided)
        if ($event->phone) {
            $phoneValidation = $this->validatePhoneNumber($event->phone);
            if (!$phoneValidation['is_valid']) {
                $results['validation_errors'][] = $phoneValidation['error'];
            }
        }
        
        return $results;
    }
    
    /**
     * Check honeypot fields
     */
    private function checkHoneypot(array $request): array
    {
        $triggeredFields = [];
        
        foreach ($this->honeypotFields as $field) {
            if (isset($request[$field]) && !empty($request[$field])) {
                $triggeredFields[] = $field;
            }
        }
        
        return [
            'is_bot' => !empty($triggeredFields),
            'triggered_fields' => $triggeredFields
        ];
    }
    
    /**
     * Record bot in registry
     */
    private function recordBot(Event $event, array $request, array $triggeredFields): void
    {
        try {
            $botData = [
                'email' => $event->email,
                'phone' => $event->phone,
                'phone_number' => $event->phone,
                'ip_address' => $event->ip_address,
                'ipv4' => $event->ip_address,
                'first_name' => $event->first_name,
                'last_name' => $event->last_name
            ];
            
            // Add honeypot field values
            foreach ($triggeredFields as $field) {
                if (isset($request[$field])) {
                    $botData[$field] = $request[$field];
                }
            }
            
            BotRegistry::recordHoneypotBot($botData, $triggeredFields);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to record bot', [
                'error' => $e->getMessage(),
                'email' => $event->email
            ]);
        }
    }
    
    /**
     * Check if email/phone/IP is a known bot
     */
    private function isKnownBot(Event $event): bool
    {
        return BotRegistry::isBot(
            $event->email,
            $event->phone,
            $event->ip_address
        );
    }
    
    /**
     * Check email validation cache
     */
    private function checkEmailCache(string $email): ?array
    {
        $cached = EmailValidationRegistry::findByEmail($email);
        
        if (!$cached) {
            return null;
        }
        
        // Determine if email is valid based on our status mapping
        $validStatuses = ['valid', 'catch-all', 'unknown', 'role'];
        $isValid = in_array($cached->validation_status, $validStatuses);
        
        return [
            'is_valid' => $isValid,
            'status' => $cached->validation_status,
            'sub_status' => $cached->validation_sub_status,
            'zb_last_active' => $cached->zb_last_active,
            'last_validated' => $cached->last_validated_at,
            'needs_revalidation' => $cached->needsRevalidation($this->validationCacheDays)
        ];
    }
    
    /**
     * Validate phone number format
     */
    private function validatePhoneNumber(string $phone): array
    {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        
        // Check length
        if (strlen($cleaned) === 10) {
            // Add country code
            $formatted = '1' . $cleaned;
        } elseif (strlen($cleaned) === 11 && $cleaned[0] === '1') {
            // Already has country code
            $formatted = $cleaned;
        } else {
            return [
                'is_valid' => false,
                'error' => 'Invalid phone number format',
                'formatted' => null
            ];
        }
        
        return [
            'is_valid' => true,
            'error' => null,
            'formatted' => $formatted
        ];
    }
    
    /**
     * Format phone number (matching your formatNumber function)
     */
    public function formatPhoneNumber(?string $number): ?string
    {
        if (empty($number)) {
            return null;
        }
        
        $number = preg_replace('/[^0-9]/', '', $number);
        
        if (strlen($number) == 10) {
            return '1' . $number;
        } elseif (strlen($number) == 11 && $number[0] == '1') {
            return $number;
        }
        
        return null;
    }
    
    /**
     * Process ZeroBounce validation response
     */
    public function processZeroBounceResponse(string $email, array $zbResponse): bool
    {
        try {
            // Record in validation registry
            $validation = EmailValidationRegistry::recordZeroBounceValidation($email, $zbResponse);
            
            // Log the validation
            $this->logger->logEvent(
                'ZeroBounce Validation',
                $email,
                'Validation check successful',
                $zbResponse['status'] ?? 'unknown',
                'success'
            );
            
            // Return whether email is valid
            $validStatuses = ['valid', 'catch-all', 'unknown'];
            return in_array($validation->validation_status, $validStatuses);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process ZeroBounce response', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get validation statistics
     */
    public function getValidationStats(): array
    {
        return [
            'bot_stats' => BotRegistry::getStats(),
            'email_validation_stats' => EmailValidationRegistry::getStats(),
            'cache_hit_rate' => $this->calculateCacheHitRate()
        ];
    }
    
    /**
     * Calculate cache hit rate for email validations
     */
    private function calculateCacheHitRate(): float
    {
        // For now, return 0 since we don't have validation_source column
        // This can be implemented later if needed
        return 0.0;
    }
}