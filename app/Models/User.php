<?php

// app/Models/User.php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $fillable = [
        'owner_address',
        'primary_vault_address',
    ];

    protected $hidden = ['remember_token'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Get all channel identities where this user is the owner
    public function channelIdentities()
    {
        return $this->hasMany(ChannelIdentity::class);
    }
}