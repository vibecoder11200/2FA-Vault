<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmergencyContact extends Model
{
    use HasFactory;
    protected $fillable = [
        'owner_id', 'trusted_user_id', 'email',
        'status', 'access_type', 'wait_days',
        'encrypted_key', 'granted_at',
        'grantee_public_key_fingerprint',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'wait_days'  => 'integer',
    ];

    protected $hidden = ['encrypted_key'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function trustedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trusted_user_id');
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(EmergencyAccessRequest::class, 'contact_id');
    }
}
