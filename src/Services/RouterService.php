<?php

namespace UnifiedEvents\Services;

use UnifiedEvents\Models\Event;
use UnifiedEvents\Utilities\Database;
use UnifiedEvents\Utilities\Logger;

class RouterService
{
    private Database $db;
    private Logger $logger;
    private array $platformCache = [];
    private array $routingRulesCache = [];
    
    public function __construct()
    {
        $this->db = new Database();
        $this->logger = new Logger();
        $this->loadPlatforms();
        $this->loadRoutingRules();
    }
    
    /**
     * Get all platforms that should receive this event
     */
    public function getRoutesForEvent(Event $event): array
    {
        $platforms = [];
        
        // Get all active routing rules for this event type
        $rules = $this->getActiveRulesForEventType($event->event_type);
        
        foreach ($rules as $rule) {
            // Check if rule conditions match
            if ($this->evaluateRuleConditions($event, $rule['conditions'])) {
                $platform = $this->getPlatformById($rule['platform_id']);
                if ($platform && $platform['is_active']) {
                    $platforms[] = $platform;
                }
            }
        }
        
        // Remove duplicates (in case multiple rules point to same platform)
        $platforms = array_unique($platforms, SORT_REGULAR);
        
        // Sort by priority (if needed)
        usort($platforms, function($a, $b) {
            return ($a['priority'] ?? 100) - ($b['priority'] ?? 100);
        });
        
        $this->logger->debug('Routes determined for event', [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'platforms' => array_column($platforms, 'platform_code')
        ]);
        
        return $platforms;
    }
    
    /**
     * Get the ZeroBounce platform configuration
     */
    public function getZeroBouncePlatform(): ?array
    {
        foreach ($this->platformCache as $platform) {
            if ($platform['platform_code'] === 'zerobounce' && $platform['is_active']) {
                return $platform;
            }
        }
        return null;
    }
    
    /**
     * Get platform by ID
     */
    public function getPlatformById(int $id): ?array
    {
        return $this->platformCache[$id] ?? null;
    }
    
    /**
     * Get platform by code
     */
    public function getPlatformByCode(string $code): ?array
    {
        foreach ($this->platformCache as $platform) {
            if ($platform['platform_code'] === $code) {
                return $platform;
            }
        }
        return null;
    }
    
    /**
     * Load all active platforms into cache
     */
    private function loadPlatforms(): void
    {
        $platforms = $this->db->findAll('platforms', ['is_active' => 1]);
        
        foreach ($platforms as $platform) {
            // Decode API configuration if it's a string
            if (!empty($platform['api_config']) && is_string($platform['api_config'])) {
                $decoded = json_decode($platform['api_config'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $platform['api_config'] = $decoded;
                } else {
                    $this->logger->error('Failed to decode platform API config', [
                        'platform' => $platform['platform_code'],
                        'error' => json_last_error_msg()
                    ]);
                    $platform['api_config'] = [];
                }
            }
            
            $this->platformCache[$platform['id']] = $platform;
        }
        
        $this->logger->info('Loaded platforms', [
            'count' => count($this->platformCache)
        ]);
    }
    
    /**
     * Load all active routing rules into cache
     */
    private function loadRoutingRules(): void
    {
        $sql = "SELECT r.*, et.type_code 
                FROM routing_rules r
                JOIN event_types et ON r.event_type_id = et.id
                WHERE r.is_active = 1
                ORDER BY r.priority ASC";
        
        $rules = $this->db->query($sql);
        
        foreach ($rules as $rule) {
            // Parse conditions JSON
            if (!empty($rule['conditions'])) {
                $rule['conditions'] = json_decode($rule['conditions'], true);
            }
            
            // Group by event type for faster lookup
            $eventType = $rule['type_code'];
            if (!isset($this->routingRulesCache[$eventType])) {
                $this->routingRulesCache[$eventType] = [];
            }
            
            $this->routingRulesCache[$eventType][] = $rule;
        }
        
        $this->logger->info('Loaded routing rules', [
            'event_types' => array_keys($this->routingRulesCache),
            'total_rules' => count($rules)
        ]);
    }
    
    /**
     * Get active rules for an event type
     */
    private function getActiveRulesForEventType(string $eventType): array
    {
        return $this->routingRulesCache[$eventType] ?? [];
    }
    
    /**
     * Evaluate if an event matches rule conditions
     */
    private function evaluateRuleConditions(Event $event, ?array $conditions): bool
    {
        if (empty($conditions)) {
            return true; // No conditions means always route
        }
        
        foreach ($conditions as $field => $condition) {
            if (!$this->evaluateCondition($event, $field, $condition)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Evaluate a single condition
     */
    private function evaluateCondition(Event $event, string $field, $condition): bool
    {
        // Get the field value from the event
        $value = $this->getEventFieldValue($event, $field);
        
        // Handle different condition types
        if (is_array($condition)) {
            // Complex condition with operator
            if (isset($condition['equals'])) {
                return $value == $condition['equals'];
            }
            if (isset($condition['not_equals'])) {
                return $value != $condition['not_equals'];
            }
            if (isset($condition['contains'])) {
                return stripos($value, $condition['contains']) !== false;
            }
            if (isset($condition['not_contains'])) {
                return stripos($value, $condition['not_contains']) === false;
            }
            if (isset($condition['in'])) {
                return in_array($value, $condition['in']);
            }
            if (isset($condition['not_in'])) {
                return !in_array($value, $condition['not_in']);
            }
            if (isset($condition['greater_than'])) {
                return $value > $condition['greater_than'];
            }
            if (isset($condition['less_than'])) {
                return $value < $condition['less_than'];
            }
            if (isset($condition['regex'])) {
                return preg_match($condition['regex'], $value);
            }
        } else {
            // Simple equality check
            return $value == $condition;
        }
        
        return false;
    }
    
    /**
     * Get field value from event
     */
    private function getEventFieldValue(Event $event, string $field): mixed
    {
        // Direct property access
        if (property_exists($event, $field)) {
            return $event->$field;
        }
        
        // Check event_data
        if (isset($event->event_data[$field])) {
            return $event->event_data[$field];
        }
        
        // Special field mappings
        switch ($field) {
            case 'email_domain':
                if ($event->email) {
                    $parts = explode('@', $event->email);
                    return $parts[1] ?? '';
                }
                return '';
                
            case 'has_phone':
                return !empty($event->phone);
                
            case 'revenue_amount':
                return $event->amount ?? 0;
                
            case 'is_gmail':
                return stripos($event->email ?? '', '@gmail.com') !== false;
                
            case 'is_mobile':
                return !empty($event->phone) && strlen($event->phone) >= 10;
        }
        
        return null;
    }
    
    /**
     * Add a dynamic routing rule (runtime only, not persisted)
     */
    public function addDynamicRule(string $eventType, string $platformCode, array $conditions, int $priority = 100): void
    {
        $platform = $this->getPlatformByCode($platformCode);
        if (!$platform) {
            throw new \Exception("Platform not found: $platformCode");
        }
        
        $rule = [
            'id' => 'dynamic_' . uniqid(),
            'rule_name' => 'Dynamic rule for ' . $platformCode,
            'platform_id' => $platform['id'],
            'conditions' => $conditions,
            'priority' => $priority,
            'is_active' => true
        ];
        
        if (!isset($this->routingRulesCache[$eventType])) {
            $this->routingRulesCache[$eventType] = [];
        }
        
        $this->routingRulesCache[$eventType][] = $rule;
        
        // Re-sort by priority
        usort($this->routingRulesCache[$eventType], function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
    }
    
    /**
     * Get default routing configuration for common scenarios
     */
    public function getDefaultRoutes(string $eventType): array
    {
        $defaults = [
            'lead' => [
                'zerobounce' => ['priority' => 1],  // Always validate first
                'activecampaign' => ['priority' => 10],
                'woopra' => ['priority' => 20],
                'marketbeat' => [
                    'priority' => 30,
                    'conditions' => ['email_validation_status' => 'valid']
                ],
                'limecellular' => [
                    'priority' => 40,
                    'conditions' => ['has_phone' => true]
                ]
            ],
            'purchase' => [
                'activecampaign' => ['priority' => 10],
                'woopra' => ['priority' => 20]
            ]
        ];
        
        return $defaults[$eventType] ?? [];
    }
    
    /**
     * Reload platforms and rules from database
     */
    public function reload(): void
    {
        $this->platformCache = [];
        $this->routingRulesCache = [];
        $this->loadPlatforms();
        $this->loadRoutingRules();
    }
    
    /**
     * Get routing statistics
     */
    public function getRoutingStats(): array
    {
        return [
            'active_platforms' => count($this->platformCache),
            'total_rules' => array_sum(array_map('count', $this->routingRulesCache)),
            'rules_by_event_type' => array_map('count', $this->routingRulesCache)
        ];
    }
}