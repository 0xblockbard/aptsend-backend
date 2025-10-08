<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class TweetCommand extends Model
{
    use HasFactory, SoftDeletes;

    // Status constants
    const STATUS_UNPROCESSED = 0;
    const STATUS_READY = 1;
    const STATUS_NEEDS_LOOKUP = 2;

    // Processed constants
    const NOT_SENT = 0;
    const SENT = 1;

    protected $fillable = [
        'tweet_id',
        'author_username',
        'author_user_id',
        'raw_text',
        'tweet_created_at',
        'amount',
        'token',
        'status',
        'processed',    
        'to_channel',
        'to_user_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'status' => 'integer',
        'processed' => 'integer',
        'tweet_created_at' => 'datetime',
    ];

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY)
            ->where('processed', self::NOT_SENT);
    }

    public function scopeNeedsLookup($query)
    {
        return $query->where('status', self::STATUS_NEEDS_LOOKUP);
    }

    public function markAsReady(): void
    {
        $this->update(['status' => self::STATUS_READY]);
    }

    public function markAsNeedsLookup(): void
    {
        $this->update(['status' => self::STATUS_NEEDS_LOOKUP]);
    }

    public function markAsSent(): void
    {
        $this->update(['processed' => self::SENT]);
    }

    public function setRecipientUserId(string $userId): void
    {
        $this->update([
            'to_user_id' => $userId,
            'status' => self::STATUS_READY,
        ]);
    }

    public function getAmountInApt(): float
    {
        return $this->amount / 100000000;
    }

    public static function isDuplicate(string $tweetId): bool
    {
        return self::where('tweet_id', $tweetId)->exists();
    }
}