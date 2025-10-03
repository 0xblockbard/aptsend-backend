<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChannelIdentity extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'channel',
        'channel_user_id',
        'credentials',
        'token_expires_at',
        'metadata',
        'target_vault_address',
        'vault_status',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'token_expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'credentials',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLinked(): bool
    {
        return $this->vault_status === 1;
    }

    public function isTemporary(): bool
    {
        return $this->vault_status === 0;
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function scopeLinked($query)
    {
        return $query->where('vault_status', 1);
    }

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeByOwnerAddress($query, string $ownerAddress)
    {
        return $query->whereHas('user', function($q) use ($ownerAddress) {
            $q->where('owner_address', $ownerAddress);
        });
    }
}