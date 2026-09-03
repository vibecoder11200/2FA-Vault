<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Team extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'owner_id',
        'invite_code',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the owner of the team.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all users in the team.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'team_users')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    /**
     * Get all shared accounts in the team.
     */
    public function sharedAccounts()
    {
        return $this->hasMany(SharedAccount::class);
    }

    /**
     * Get all invitations for this team.
     */
    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the activity log entries for this team.
     */
    public function activityLogs()
    {
        return $this->hasMany(\App\Models\TeamActivityLog::class);
    }

    /**
     * Scope a query to only include teams accessible by a specific user.
     */
    public function scopeAccessibleByUser($query, $userId)
    {
        // B14: group the orWhere in a closure so the SoftDeletes global scope
        // (whereNull deleted_at) still applies to the whole query — an ungrouped
        // orWhere('owner_id') made soft-deleted teams match for their owner.
        return $query->where(function ($q) use ($userId) {
            $q->whereHas('users', function ($sq) use ($userId) {
                $sq->where('users.id', $userId);
            })->orWhere('owner_id', $userId);
        });
    }

    /**
     * Generate a unique invite code.
     */
    public function generateInviteCode(): string
    {
        do {
            $code = Str::random(16);
        } while (self::where('invite_code', $code)->exists());

        $this->invite_code = $code;
        $this->save();

        return $code;
    }

    /**
     * Check if a user is a member of this team.
     */
    public function hasMember($userId): bool
    {
        return $this->users()->where('users.id', $userId)->exists() || $this->owner_id == $userId;
    }

    /**
     * Get the role of a user in this team.
     */
    public function getUserRole($userId): ?string
    {
        if ($this->owner_id == $userId) {
            return 'owner';
        }

        $membership = $this->users()->where('users.id', $userId)->first();
        return $membership ? $membership->pivot->role : null;
    }
}
