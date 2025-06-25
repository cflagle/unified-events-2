<?php

namespace UnifiedEvents\Models;

use UnifiedEvents\Utilities\Database;
use Carbon\Carbon;

class EmailValidationRegistry
{
    protected string $table = 'email_validation_registry';
    protected ?Database $db;
    
    public ?int $id = null;
    public string $email;
    public string $email_md5;
    public string $validation_status; // 'valid', 'invalid', 'disposable', 'role', 'catch-all', 'unknown'
    public ?string $validation_sub_status = null;
    
    // ZeroBounce specific fields
    public ?string $zb_status = null;
    public ?string $zb_sub_status = null;
    public ?int $zb_last_active = null;
    public ?bool $zb_free_email = null;
    public ?bool $zb_mx_found = null;
    public ?string $zb_smtp_provider = null;
    
    // Metadata
    public Carbon $last_validated_at;
    public int $validation_count = 1;
    public string $validation_source = 'zerobounce';
    public ?Carbon $first_seen_valid = null;
    public ?Carbon $first_seen_invalid = null;
    public ?array $status_change_history = null;
    
    public Carbon $created_at;
    public Carbon $updated_at;
    
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: new Database();
        $this->last_validated_at = Carbon::now();
        $this->created_at = Carbon::now();
        $this->updated_at = Carbon::now();
    }
    
    /**
     * Record a validation result from ZeroBounce
     */
    public static function recordZeroBounceValidation(string $email, array $zbResponse): self
    {
        $registry = self::findByEmail($email) ?? new self();
        
        $registry->email = strtolower(trim($email));
        $registry->email_md5 = md5($registry->email);
        
        // Map ZeroBounce response
        $registry->zb_status = $zbResponse['status'] ?? 'unknown';
        $registry->zb_sub_status = $zbResponse['sub_status'] ?? null;
        $registry->zb_last_active = isset($zbResponse['active_in_days']) ? (int)$zbResponse['active_in_days'] : null;
        $registry->zb_free_email = $zbResponse['free_email'] ?? null;
        $registry->zb_mx_found = $zbResponse['mx_found'] ?? null;
        $registry->zb_smtp_provider = $zbResponse['smtp_provider'] ?? null;
        
        // Map to our validation status
        $registry->validation_status = self::mapZeroBounceStatus($zbResponse['status'] ?? 'unknown');
        $registry->validation_sub_status = $zbResponse['sub_status'] ?? null;
        
        // Update metadata
        $registry->last_validated_at = Carbon::now();
        $registry->validation_source = 'zerobounce';
        
        if ($registry->id) {
            // Existing record - update counts and history
            $registry->validation_count++;
            
            // Track status changes
            if ($registry->validation_status !== self::mapZeroBounceStatus($zbResponse['status'] ?? 'unknown')) {
                $history = $registry->status_change_history ?? [];
                $history[] = [
                    'date' => Carbon::now()->toDateTimeString(),
                    'old_status' => $registry->validation_status,
                    'new_status' => self::mapZeroBounceStatus($zbResponse['status'] ?? 'unknown')
                ];
                $registry->status_change_history = $history;
            }
        }
        
        // Track first seen valid/invalid
        if ($registry->validation_status === 'valid' && !$registry->first_seen_valid) {
            $registry->first_seen_valid = Carbon::now();
        } elseif ($registry->validation_status === 'invalid' && !$registry->first_seen_invalid) {
            $registry->first_seen_invalid = Carbon::now();
        }
        
        $registry->save();
        return $registry;
    }
    
    /**
     * Map ZeroBounce status to our internal status
     */
    protected static function mapZeroBounceStatus(string $zbStatus): string
    {
        $mapping = [
            'valid' => 'valid',
            'invalid' => 'invalid',
            'catch-all' => 'catch-all',
            'unknown' => 'unknown',
            'spamtrap' => 'invalid',
            'abuse' => 'invalid',
            'do_not_mail' => 'invalid',
            'role' => 'role',
            'disposable' => 'disposable',
            'toxic' => 'invalid'
        ];
        
        return $mapping[$zbStatus] ?? 'unknown';
    }
    
    /**
     * Check if an email needs revalidation
     */
    public function needsRevalidation(int $daysSinceLastCheck = 30): bool
    {
        // Never revalidate permanently invalid emails
        if (in_array($this->validation_status, ['invalid', 'disposable']) && 
            in_array($this->zb_sub_status, ['mailbox_not_found', 'mailbox_invalid', 'no_dns_entries'])) {
            return false;
        }
        
        // Check if it's been too long since last validation
        return $this->last_validated_at->lt(Carbon::now()->subDays($daysSinceLastCheck));
    }
    
    /**
     * Find validation record by email
     */
    public static function findByEmail(string $email): ?self
    {
        $db = new Database();
        $data = $db->findOne('email_validation_registry', [
            'email' => strtolower(trim($email))
        ]);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Find validation record by MD5
     */
    public static function findByMd5(string $md5): ?self
    {
        $db = new Database();
        $data = $db->findOne('email_validation_registry', [
            'email_md5' => $md5
        ]);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Get all invalid emails
     */
    public static function getInvalidEmails(int $limit = 1000): array
    {
        $db = new Database();
        $sql = "SELECT email FROM email_validation_registry WHERE validation_status = 'invalid' LIMIT ?";
        $rows = $db->query($sql, [$limit]);
        
        return array_column($rows, 'email');
    }
    
    /**
     * Get validation statistics
     */
    public static function getStats(): array
    {
        $db = new Database();
        
        $stats = [
            'total_validated' => $db->count('email_validation_registry'),
            'valid' => $db->count('email_validation_registry', ['validation_status' => 'valid']),
            'invalid' => $db->count('email_validation_registry', ['validation_status' => 'invalid']),
            'disposable' => $db->count('email_validation_registry', ['validation_status' => 'disposable']),
            'role' => $db->count('email_validation_registry', ['validation_status' => 'role']),
            'catch_all' => $db->count('email_validation_registry', ['validation_status' => 'catch-all']),
            'unknown' => $db->count('email_validation_registry', ['validation_status' => 'unknown'])
        ];
        
        // Recent validations
        $sql = "SELECT COUNT(*) as count FROM email_validation_registry WHERE last_validated_at >= ?";
        $stats['validated_last_24h'] = $db->query($sql, [Carbon::now()->subDay()->toDateTimeString()])[0]['count'];
        $stats['validated_last_7d'] = $db->query($sql, [Carbon::now()->subWeek()->toDateTimeString()])[0]['count'];
        
        return $stats;
    }
    
    /**
     * Mark email as invalid from bounce or complaint
     */
    public static function markAsInvalid(string $email, string $reason, string $source = 'bounce'): self
    {
        $registry = self::findByEmail($email) ?? new self();
        
        $registry->email = strtolower(trim($email));
        $registry->email_md5 = md5($registry->email);
        $registry->validation_status = 'invalid';
        $registry->validation_sub_status = $reason;
        $registry->validation_source = $source;
        $registry->last_validated_at = Carbon::now();
        
        if (!$registry->first_seen_invalid) {
            $registry->first_seen_invalid = Carbon::now();
        }
        
        if ($registry->id) {
            $registry->validation_count++;
        }
        
        $registry->save();
        return $registry;
    }
    
    /**
     * Save validation record
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
     * Create instance from database array
     */
    public static function fromArray(array $data): self
    {
        $registry = new self();
        
        foreach ($data as $key => $value) {
            if (property_exists($registry, $key)) {
                if (in_array($key, ['last_validated_at', 'first_seen_valid', 'first_seen_invalid', 'created_at', 'updated_at']) && $value !== null) {
                    $registry->$key = Carbon::parse($value);
                } elseif ($key === 'status_change_history' && is_string($value)) {
                    $registry->$key = json_decode($value, true);
                } elseif (in_array($key, ['zb_free_email', 'zb_mx_found'])) {
                    $registry->$key = $value !== null ? (bool)$value : null;
                } else {
                    $registry->$key = $value;
                }
            }
        }
        
        return $registry;
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
}