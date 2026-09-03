<?php

namespace App\Models;

use App\Models\Traits\CanEncryptField;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBackupDestination extends Model
{
    use CanEncryptField;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'type',
        'config',
        'is_active',
        'last_run_at',
        'last_run_status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * config holds destination credentials (and the per-destination backup
     * encryption password) — it is ALWAYS encrypted at rest via
     * CanEncryptField, regardless of the global useEncryption setting (C11).
     */
    protected function alwaysEncryptFields(): bool
    {
        return true;
    }

    /**
     * config is stored as JSON, always encrypted (CanEncryptField).
     * Serialize arrays to JSON on write, deserialize on read.
     */
    public function getConfigAttribute(mixed $value): mixed
    {
        $decrypted = $this->decryptOrReturn($value);

        if (is_string($decrypted)) {
            $decoded = json_decode($decrypted, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $decrypted;
        }

        return $decrypted;
    }

    public function setConfigAttribute(mixed $value): void
    {
        $asString = is_array($value) ? json_encode($value) : $value;
        $this->attributes['config'] = $this->encryptOrReturn($asString);
    }
}
