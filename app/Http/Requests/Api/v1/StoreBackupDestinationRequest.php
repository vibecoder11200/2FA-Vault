<?php

namespace App\Http\Requests\Api\v1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreBackupDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label'     => ['required', 'string', 'max:100'],
            'type'      => ['required', 'string', Rule::in(['local', 's3', 'email', 'webdav'])],
            'is_active' => ['sometimes', 'boolean'],
            // config shape depends on type — validated in the controller by type
            'config'                 => ['required', 'array'],
            'config.path'            => ['sometimes', 'nullable', 'string', 'max:255'],
            // email
            'config.email'           => ['sometimes', 'nullable', 'email', 'max:255'],
            // s3
            'config.access_key'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.secret_key'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.region'          => ['sometimes', 'nullable', 'string', 'max:64'],
            'config.bucket'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.endpoint'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.prefix'          => ['sometimes', 'nullable', 'string', 'max:255'],
            // webdav
            'config.url'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.username'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.password'        => ['sometimes', 'nullable', 'string', 'max:255'],
            // C1 (F3): per-destination backup encryption password (stored
            // inside the always-encrypted config column).
            'config.encryption_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Email destinations must explicitly opt in to attachments —
            // and AutoBackupJob only attaches password-encrypted envelopes.
            'config.email_attachments'   => ['sometimes', 'boolean'],
        ];
    }
}
