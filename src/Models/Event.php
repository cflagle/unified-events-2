<?php

namespace UnifiedEvents\Models;

use UnifiedEvents\Utilities\Database;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;

class Event
{
    protected string $table = 'events';
    protected ?Database $db;
    
    // Event properties
    public ?int $id = null;
    public string $event_id;
    public string $event_type;
    public string $status = 'pending';
    public ?string $blocked_reason = null;
    
    // Identification fields
    public ?string $email = null;
    public ?string $email_md5 = null;
    public ?string $phone = null;
    public ?string $first_name = null;
    public ?string $last_name = null;
    public ?string $ip_address = null;
    
    // Lead acquisition data
    public ?string $acq_source = null;
    public ?string $acq_campaign = null;
    public ?string $acq_term = null;
    public ?Carbon $acq_date = null;
    public ?string $acq_form_title = null;
    
    // Current event attribution
    public ?string $source = null;
    public ?string $medium = null;
    public ?string $campaign = null;
    public ?string $content = null;
    public ?string $term = null;
    public ?string $gclid = null;
    public ?string $ga_client_id = null;
    
    // Purchase-specific fields
    public ?string $offer = null;
    public ?string $publisher = null;
    public ?float $amount = null;
    public ?string $traffic_source = null;
    public ?string $purchase_creative = null;
    public ?string $purchase_campaign = null;
    public ?string $purchase_content = null;
    public ?string $purchase_term = null;
    public ?string $traffic_source_account = null;
    public ?string $purchase_source = null;
    public ?string $purchase_lp = null;
    public ?string $sid202 = null;
    public ?string $source_site = null;
    
    // Validation status
    public ?string $email_validation_status = null;
    public ?string $phone_validation_status = null;
    public ?int $zb_last_active = null;
    
    // JSON data and timestamps
    public ?array $event_data = null;
    public ?Carbon $processed_at = null;
    public Carbon $created_at;
    public Carbon $updated_at;
    
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: new Database();
        $this->event_id = Uuid::uuid4()->toString();
        $this->created_at = Carbon::now();
        $this->updated_at = Carbon::now();
    }
    
    /**
     * Create a new event from request data
     */
    public static function createFromRequest(array $data, string $eventType): self
    {
        $event = new self();
        $event->event_type = $eventType;
        
        // Map common fields
        $event->email = $data['email'] ?? null;
        $event->phone = $data['phone'] ?? $data['phone_number'] ?? null;
        $event->first_name = $data['first_name'] ?? null;
        $event->last_name = $data['last_name'] ?? null;
        $event->ip_address = $data['ip_address'] ?? $data['ipv4'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        
        // Generate MD5 if email exists
        if ($event->email) {
            $event->email_md5 = md5(strtolower(trim($event->email)));
        }
        
        // Map attribution fields
        $event->source = $data['source'] ?? $data['utm_source'] ?? null;
        $event->medium = $data['medium'] ?? $data['utm_medium'] ?? null;
        $event->campaign = $data['campaign'] ?? $data['utm_campaign'] ?? null;
        $event->content = $data['content'] ?? $data['utm_content'] ?? null;
        $event->term = $data['term'] ?? $data['utm_term'] ?? null;
        $event->gclid = $data['gclid'] ?? null;
        $event->ga_client_id = $data['ga_client_id'] ?? $data['client_id'] ?? null;
        
        // Handle event-specific fields
        if ($eventType === 'lead') {
            // For leads, acquisition data is current data
            $event->acq_source = $event->source;
            $event->acq_campaign = $event->campaign;
            $event->acq_term = $event->term;
            $event->acq_date = Carbon::now();
            $event->acq_form_title = $data['form_title'] ?? $data['formTitle'] ?? null;
        } elseif ($eventType === 'purchase') {
            // For purchases, map purchase-specific fields
            $event->offer = $data['offer'] ?? null;
            $event->publisher = $data['publisher'] ?? null;
            $event->amount = isset($data['amt']) ? (float)$data['amt'] : null;
            $event->traffic_source = $data['traffic_source'] ?? null;
            $event->purchase_creative = $data['purchase_creative'] ?? null;
            $event->purchase_campaign = $data['purchase_campaign'] ?? null;
            $event->purchase_content = $data['purchase_content'] ?? null;
            $event->purchase_term = $data['purchase_term'] ?? null;
            $event->traffic_source_account = $data['traffic_source_account'] ?? null;
            $event->purchase_source = $data['purchase_source'] ?? null;
            $event->purchase_lp = $data['purchase_lp'] ?? null;
            $event->sid202 = $data['sid202'] ?? null;
            $event->source_site = $data['source_site'] ?? null;
            
            // Carry over acquisition data if provided
            $event->acq_source = $data['acq_source'] ?? null;
            $event->acq_campaign = $data['acq_campaign'] ?? null;
            $event->acq_term = $data['acq_term'] ?? null;
            $event->acq_form_title = $data['acq_form_title'] ?? null;
            if (isset($data['acq_date'])) {
                $event->acq_date = Carbon::parse($data['acq_date']);
            }
        }
        
        // Store any additional data in event_data
        $event->event_data = array_diff_key($data, array_flip([
            'email', 'phone', 'phone_number', 'first_name', 'last_name',
            'ip_address', 'ipv4', 'source', 'utm_source', 'medium', 'utm_medium',
            'campaign', 'utm_campaign', 'content', 'utm_content', 'term', 'utm_term',
            'gclid', 'ga_client_id', 'client_id', 'form_title', 'formTitle',
            'offer', 'publisher', 'amt', 'traffic_source', 'purchase_creative',
            'purchase_campaign', 'purchase_content', 'purchase_term',
            'traffic_source_account', 'purchase_source', 'purchase_lp',
            'sid202', 'source_site', 'acq_source', 'acq_campaign', 'acq_term',
            'acq_form_title', 'acq_date'
        ]));
        
        return $event;
    }
    
    /**
     * Save the event to database
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
     * Find event by ID
     */
    public static function find(int $id): ?self
    {
        $db = new Database();
        $data = $db->findOne('events', ['id' => $id]);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Find event by event_id (UUID)
     */
    public static function findByEventId(string $eventId): ?self
    {
        $db = new Database();
        $data = $db->findOne('events', ['event_id' => $eventId]);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Find events by email
     */
    public static function findByEmail(string $email): array
    {
        $db = new Database();
        $rows = $db->findAll('events', ['email' => $email], 'created_at DESC');
        
        return array_map(fn($row) => self::fromArray($row), $rows);
    }
    
    /**
     * Create instance from database array
     */
    public static function fromArray(array $data): self
    {
        $event = new self();
        
        foreach ($data as $key => $value) {
            if (property_exists($event, $key)) {
                if (in_array($key, ['acq_date', 'processed_at', 'created_at', 'updated_at']) && $value !== null) {
                    $event->$key = Carbon::parse($value);
                } elseif ($key === 'event_data' && is_string($value)) {
                    $event->$key = json_decode($value, true);
                } else {
                    $event->$key = $value;
                }
            }
        }
        
        return $event;
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
            } else {
                $data[$key] = $value;
            }
        }
        
        return $data;
    }
    
    /**
     * Block this event with a reason
     */
    public function block(string $reason): void
    {
        $this->status = 'blocked';
        $this->blocked_reason = $reason;
        $this->save();
    }
    
    /**
     * Mark as processed
     */
    public function markAsProcessed(): void
    {
        $this->status = 'completed';
        $this->processed_at = Carbon::now();
        $this->save();
    }
    
    /**
     * Check if this is a purchase event
     */
    public function isPurchase(): bool
    {
        return $this->event_type === 'purchase';
    }
    
    /**
     * Check if this is a lead event
     */
    public function isLead(): bool
    {
        return $this->event_type === 'lead';
    }
    
    /**
     * Get related events (e.g., purchases from this lead)
     */
    public function getRelatedEvents(): array
    {
        if (!$this->email) {
            return [];
        }
        
        return self::findByEmail($this->email);
    }
    
    /**
     * Link this event to a parent event
     */
    public function linkToParent(int $parentEventId, string $relationshipType = 'lead_to_purchase'): bool
    {
        $db = new Database();
        
        return $db->insert('event_relationships', [
            'parent_event_id' => $parentEventId,
            'child_event_id' => $this->id,
            'relationship_type' => $relationshipType,
            'matching_criteria' => json_encode([
                'email' => true,
                'ip' => $this->ip_address === $db->findOne('events', ['id' => $parentEventId])['ip_address']
            ]),
            'created_at' => Carbon::now()->toDateTimeString()
        ]) !== false;
    }
}