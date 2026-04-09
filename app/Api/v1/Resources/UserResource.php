<?php

namespace App\Api\v1\Resources;

use App\Services\EncryptionService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/**
 * @property mixed $id
 * @property string $name
 * @property string $email
 * @property string $oauth_provider
 * @property \Illuminate\Support\Collection<array-key, mixed> $preferences
 * @property string $is_admin
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $encryptionService = App::make(EncryptionService::class);

        return [
            'id'                     => $this->id,
            'name'                   => $this->name,
            'email'                  => $this->email,
            'oauth_provider'         => $this->oauth_provider,
            'authenticated_by_proxy' => Auth::getDefaultDriver() === 'reverse-proxy-guard',
            'preferences'            => $this->preferences,
            'is_admin'               => $this->is_admin,
            'encryption_version'     => $this->encryption_version,
            'vault_locked'           => $this->vault_locked,
            'e2ee_required'          => $encryptionService->isEncryptionRequired($this->resource),
            'last_backup_at'         => $this->last_backup_at?->toIso8601String(),
        ];
    }
}
