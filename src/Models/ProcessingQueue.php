<?php

namespace UnifiedEvents\Models;

use UnifiedEvents\Utilities\Database;
use Carbon\Carbon;

class ProcessingQueue
{
    protected string $table = 'processing_queue';
    protected ?Database $db;
    
    public ?int $id = null;
    public int $event_id;
    public int $platform_id;
    public string $status = 'pending';
    public ?string $skip_reason = null;
    public int $attempts = 0;
    public int $max_retries = 3;
    public Carbon $process_after;
    public ?Carbon $locked_until = null;
    public ?string $locked_by = null;
    public ?int $response_code = null;
    public ?string $response_body = null;
    public float $revenue_amount = 0.00;
    public string $revenue_status = 'pending';
    public Carbon $created_at;
    public ?Carbon $processed_at = null;
    
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: new Database();
        $this->process_after = Carbon::now();
        $this->created_at = Carbon::now();
    }
    
    /**
     * Save the queue job
     */
    public function save(): bool
    {
        $data = $this->toArray();
        
        if ($this->id) {
            // Update existing
            unset($data['id'], $data['created_at']);
            return $this->db->update($this->table, $data, ['id' => $this->id]);
        } else {
            // Insert new
            $data['created_at'] = $this->created_at->toDateTimeString();
            $this->id = $this->db->insert($this->table, $data);
            return $this->id !== false;
        }
    }
    
    /**
     * Find job by ID
     */
    public static function find(int $id): ?self
    {
        $db = new Database();
        $data = $db->findOne('processing_queue', ['id' => $id]);
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    /**
     * Mark job as completed
     */
    public function complete(int $responseCode, string $responseBody): bool
    {
        $this->status = 'completed';
        $this->response_code = $responseCode;
        $this->response_body = $responseBody;
        $this->processed_at = Carbon::now();
        return $this->save();
    }
    
    /**
     * Mark job as failed
     */
    public function fail(string $error): bool
    {
        $this->status = 'failed';
        $this->response_body = $error;
        $this->processed_at = Carbon::now();
        return $this->save();
    }
    
    /**
     * Skip job with reason
     */
    public function skip(string $reason): bool
    {
        $this->status = 'skipped';
        $this->skip_reason = $reason;
        $this->processed_at = Carbon::now();
        return $this->save();
    }
    
    /**
     * Retry the job
     */
    public function retry(): bool
    {
        if ($this->attempts >= $this->max_retries) {
            return false;
        }
        
        $this->status = 'pending';
        $this->attempts++;
        $this->locked_by = null;
        $this->locked_until = null;
        $this->process_after = Carbon::now()->addMinutes(pow(2, $this->attempts) * 5);
        
        return $this->save();
    }
    
    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        $job = new self();
        
        foreach ($data as $key => $value) {
            if (property_exists($job, $key)) {
                if (in_array($key, ['process_after', 'locked_until', 'created_at', 'processed_at']) && $value !== null) {
                    $job->$key = Carbon::parse($value);
                } else {
                    $job->$key = $value;
                }
            }
        }
        
        return $job;
    }
    
    /**
     * Convert to array
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
            } else {
                $data[$key] = $value;
            }
        }
        
        return $data;
    }
}