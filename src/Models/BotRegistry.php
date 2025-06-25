<?php

namespace UnifiedEvents\Models;

use UnifiedEvents\Utilities\Database;
use Carbon\Carbon;

class BotRegistry
{
    protected string $table = 'bot_registry';
    protected ?Database $db;
    
    public ?int $id = null;
    public string $identifier_type; // 'email', 'phone', 'ip'
    public string $identifier_value;
    public ?string $detection_method = null;
    public ?array $honeypot_fields = null;
    public Carbon $first_seen_date;
    public Carbon $last_seen_date;
    public int $attempt_count = 1;
    public ?array $associated_emails = null;
    public ?array $associated_ips = null;
    public ?array $associated_phones = null;
    public ?string $notes = null;
    public string $severity = 'medium';
    public bool $is_active = true;
    public Carbon $created_at;
    public Carbon $updated_at;
    
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: new Database();
        $this->first_seen_date = Carbon::now();
        $this->last_seen_date = Carbon::now();
        $this->created_at = Carbon::now();
        $this->updated_at = Carbon::now();
    }
    
    /**
     * Record a bot detection from honeypot
     */
    public static function recordHoneypotBot(array $requestData, array $honeypotFieldsTriggered): self
    {
        $bot = new self();
        
        // Primary identifier is email if available, otherwise IP
        if (!empty($requestData['email'])) {
            $bot->identifier_type = 'email';
            $bot->identifier_value = strtolower(trim($requestData['email']));
        } elseif (!empty($requestData['ip_address']) || !empty($requestData['ipv4'])) {
            $bot->identifier_type = 'ip';
            $bot->identifier_value = $requestData['ip_address'] ?? $requestData['ipv4'] ?? $_SERVER['REMOTE_ADDR'];
        } else {
            throw new \Exception('No valid identifier found for bot registry');
        }
        
        $bot->detection_method = 'honeypot';
        $bot->honeypot_fields = $honeypotFieldsTriggered;
        
        // Collect all associated data
        if (!empty($requestData['email'])) {
            $bot->associated_emails = [strtolower(trim($requestData['email']))];
        }
        
        if (!empty($requestData['phone']) || !empty($requestData['phone_number'])) {
            $phone = $requestData['phone'] ?? $requestData['phone_number'];
            $bot->associated_phones = [$phone];
        }
        
        $ip = $requestData['ip_address'] ?? $requestData['ipv4'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) {
            $bot->associated_ips = [$ip];
        }
        
        // Check if this bot already exists
        $existing = self::findBot($bot->identifier_type, $bot->identifier_value);
        if ($existing) {
            return $existing->updateBotActivity($requestData, $honeypotFieldsTriggered);
        }
        
        $bot->save();
        return $bot;
    }
    
    /**
     * Update existing bot record with new activity
     */
    public function updateBotActivity(array $requestData, array $honeypotFieldsTriggered = []): self
    {
        $this->last_seen_date = Carbon::now();
        $this->attempt_count++;
        
        // Merge honeypot fields
        if (!empty($honeypotFieldsTriggered)) {
            $existing = $this->honeypot_fields ?? [];
            $this->honeypot_fields = array_unique(array_merge($existing, $honeypotFieldsTriggered));
        }
        
        // Update associated data
        if (!empty($requestData['email'])) {
            $this->associated_emails = array_unique(array_merge(
                $this->associated_emails ?? [],
                [strtolower(trim($requestData['email']))]
            ));
        }
        
        if (!empty($requestData['phone']) || !empty($requestData['phone_number'])) {
            $phone = $requestData['phone'] ?? $requestData['phone_number'];
            $this->associated_phones = array_unique(array_merge(
                $this->associated_phones ?? [],
                [$phone]
            ));
        }
        
        $ip = $requestData['ip_address'] ?? $requestData['ipv4'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) {
            $this->associated_ips = array_unique(array_merge(
                $this->associated_ips ?? [],
                [$ip]
            ));
        }
        
        // Increase severity based on attempts
        if ($this->attempt_count >= 10) {
            $this->severity = 'high';
        } elseif ($this->attempt_count >= 5) {
            $this->severity = 'medium';
        }
        
        $this->save();
        return $this;
    }
    
    /**
     * Check if an identifier is a known bot
     */
    public static function isBot(string $email = null, string $phone = null, string $ip = null): bool
    {
        $db = new Database();
        
        $conditions = [];
        $params = [];
        
        if ($email) {
            $conditions[] = "(identifier_type = 'email' AND identifier_value = ?)";
            $params[] = strtolower(trim($email));
            
            // Also check if email is in associated emails
            $conditions[] = "JSON_CONTAINS(associated_emails, JSON_QUOTE(?))";
            $params[] = strtolower(trim($email));
        }
        
        if ($phone) {
            $conditions[] = "(identifier_type = 'phone' AND identifier_value = ?)";
            $params[] = $phone;
            
            $conditions[] = "JSON_CONTAINS(associated_phones, JSON_QUOTE(?))";
            $params[] = $phone;
        }
        
        if ($ip) {
            $conditions[] = "(identifier_type = 'ip' AND identifier_value = ?)";
            $params[] = $ip;
            
            $conditions[] = "JSON_CONTAINS(associated_ips, JSON_QUOTE(?))";
            $params[] = $ip;
        }
        
        if (empty($conditions)) {
            return false;
        }
        
        $sql = "SELECT COUNT(*) as count FROM bot_registry WHERE is_active = 1 AND (" . implode(' OR ', $conditions) . ")";
        $result = $db->query($sql, $params);
        
        return $result[0]['count'] > 0;
    }
    
    /**
     * Find a specific bot record
     */
    public static function findBot(string $type, string $value): ?self
    {
        $db = new Database();
        $data = $db->findOne('bot_registry', [
            'identifier_type' => $type,
            'identifier_value' => strtolower(trim($value))
        ]);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Get all active bots
     */
    public static function getActiveBots(int $limit = 100, int $offset = 0): array
    {
        $db = new Database();
        $sql = "SELECT * FROM bot_registry WHERE is_active = 1 ORDER BY last_seen_date DESC LIMIT ? OFFSET ?";
        $rows = $db->query($sql, [$limit, $offset]);
        
        return array_map(fn($row) => self::fromArray($row), $rows);
    }
    
    /**
     * Get bots by severity
     */
    public static function getBotsBySeverity(string $severity): array
    {
        $db = new Database();
        $rows = $db->findAll('bot_registry', [
            'severity' => $severity,
            'is_active' => 1
        ], 'last_seen_date DESC');
        
        return array_map(fn($row) => self::fromArray($row), $rows);
    }
    
    /**
     * Save bot record
     */
    public function save(): bool
    {
        $data = $this->toArray();
        
        if ($this->id) {
            // Update existing
            $data['updated_at'] = Carbon::now()->toDateTimeString();
            unset($data['id'], $data['created_at']);
            
            return $this->db->update($this->table, $data, ['id' => $this->id]);
        } else {
            // Insert new
            $data['created_at'] = $this->created_at->toDateTimeString();
            $data['updated_at'] = $this->updated_at->toDateTimeString();
            
            $this->id = $this->db->insert($this->table, $data);
            return $this->id !== false;
        }
    }
    
    /**
     * Deactivate a bot entry
     */
    public function deactivate(): bool
    {
        $this->is_active = false;
        return $this->save();
    }
    
    /**
     * Add a note to the bot record
     */
    public function addNote(string $note): bool
    {
        $timestamp = Carbon::now()->toDateTimeString();
        $this->notes = $this->notes ? $this->notes . "\n[$timestamp] $note" : "[$timestamp] $note";
        return $this->save();
    }
    
    /**
     * Create instance from database array
     */
    public static function fromArray(array $data): self
    {
        $bot = new self();
        
        foreach ($data as $key => $value) {
            if (property_exists($bot, $key)) {
                if (in_array($key, ['first_seen_date', 'last_seen_date', 'created_at', 'updated_at']) && $value !== null) {
                    $bot->$key = Carbon::parse($value);
                } elseif (in_array($key, ['honeypot_fields', 'associated_emails', 'associated_ips', 'associated_phones']) && is_string($value)) {
                    $bot->$key = json_decode($value, true);
                } elseif ($key === 'is_active') {
                    $bot->$key = (bool)$value;
                } else {
                    $bot->$key = $value;
                }
            }
        }
        
        return $bot;
    }
    
    /**
     * Convert to array for database
     */
    public function toArray(): array
    {
        $data = [];
        
        $properties = get_object_vars($this);
        unset($properties['db'], $properties['table']);
        
        foreach ($properties as $key => $value) {
            if ($value === null) {
                $data[$key] = null;
            } elseif ($value instanceof Carbon) {
                $data[$key] = $value->toDateTimeString();
            } elseif (is_array($value)) {
                $data[$key] = json_encode($value);
            } elseif (is_bool($value)) {
                $data[$key] = $value ? 1 : 0;
            } else {
                $data[$key] = $value;
            }
        }
        
        return $data;
    }
    
    /**
     * Get statistics about bot activity
     */
    public static function getStats(): array
    {
        $db = new Database();
        
        $stats = [
            'total_bots' => $db->count('bot_registry', ['is_active' => 1]),
            'high_severity' => $db->count('bot_registry', ['is_active' => 1, 'severity' => 'high']),
            'medium_severity' => $db->count('bot_registry', ['is_active' => 1, 'severity' => 'medium']),
            'low_severity' => $db->count('bot_registry', ['is_active' => 1, 'severity' => 'low']),
            'honeypot_detections' => $db->count('bot_registry', ['is_active' => 1, 'detection_method' => 'honeypot']),
        ];
        
        // Get recent activity
        $sql = "SELECT COUNT(*) as count FROM bot_registry WHERE is_active = 1 AND last_seen_date >= ?";
        $stats['active_last_24h'] = $db->query($sql, [Carbon::now()->subDay()->toDateTimeString()])[0]['count'];
        $stats['active_last_7d'] = $db->query($sql, [Carbon::now()->subWeek()->toDateTimeString()])[0]['count'];
        
        return $stats;
    }
}