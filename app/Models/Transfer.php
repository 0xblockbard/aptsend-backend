<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transfer extends Model
{
    use HasFactory, SoftDeletes;

    // Status constants
    const STATUS_FAILED     = 0;
    const STATUS_COMPLETED  = 1;
    const STATUS_PENDING    = 2;
    const STATUS_PROCESSING = 3;

    protected $fillable = [
        'source_type',
        'from_channel',
        'from_user_id',
        'to_channel',
        'to_user_id',
        'amount',
        'token',
        'status',
        'tx_hash',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'status' => 'integer',
        'error_message' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Status mutations
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(string $txHash): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'tx_hash' => $txHash,
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $errors = $this->error_message ?? [];
        $errors[] = [
            'message' => $error,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errors,
            'processed_at' => now(),
        ]);
    }
    
    /**
     * Check if same channel transfer
     */
    public function isSameChannel(): bool
    {
        return $this->from_channel === $this->to_channel;
    }

    /**
     * Get contract function name
     */
    public function getContractFunction(): string
    {
        return $this->isSameChannel() 
            ? 'transfer_within_channel' 
            : 'process_transfer';
    }

    /**
     * Convert amount to APT (from octas)
     */
    public function getAmountInApt(): float
    {
        return $this->amount / 100000000; // 1 APT = 10^8 octas
    }
}