<?php

namespace App\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\v1\StoreBackupDestinationRequest;
use App\Models\User;
use App\Models\UserBackupDestination;
use App\Services\BackupDestinationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserBackupDestinationController extends Controller
{
    public function __construct(private BackupDestinationService $destinations)
    {
    }

    /**
     * List the authenticated user's backup destinations.
     * Secrets are never returned — config is masked.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $destinations = $user->backupDestinations()->orderByDesc('created_at')->get();

        return response()->json(
            $destinations->map(fn (UserBackupDestination $d) => $this->mask($d))
        );
    }

    public function store(StoreBackupDestinationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $destination = $user->backupDestinations()->create([
            'label'     => $data['label'],
            'type'      => $data['type'],
            'config'    => $data['config'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($this->mask($destination), 201);
    }

    public function update(StoreBackupDestinationRequest $request, int $id): JsonResponse
    {
        $destination = $this->findOwned($request, $id);
        $data = $request->validated();

        // index()/show() never return raw credentials (masked payloads), so
        // an edit round-trip submits a partial config: merge it over the
        // stored one instead of replacing it (empty/null values keep the
        // stored secret; booleans like email_attachments pass through so an
        // opt-in can also be turned OFF).
        $submitted = array_filter($data['config'] ?? [], fn ($value) => $value !== null && $value !== '');
        $config = array_merge($destination->config ?? [], $submitted);

        $destination->update([
            'label'     => $data['label'] ?? $destination->label,
            'type'      => $data['type'] ?? $destination->type,
            'config'    => $config,
            'is_active' => array_key_exists('is_active', $data) ? $data['is_active'] : $destination->is_active,
        ]);

        return response()->json($this->mask($destination));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $destination = $this->findOwned($request, $id);
        $destination->delete();

        return response()->json(null, 204);
    }

    /**
     * Verify the destination credentials with a 0-byte probe write.
     */
    public function testConnection(Request $request, BackupDestinationService $service, int $id): JsonResponse
    {
        $destination = $this->findOwned($request, $id);
        $result = $service->test($destination);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    private function findOwned(Request $request, int $id): UserBackupDestination
    {
        /** @var User $user */
        $user = $request->user();

        return $user->backupDestinations()->findOrFail($id);
    }

    /**
     * Return a representation that never exposes raw credentials.
     */
    private function mask(UserBackupDestination $d): array
    {
        return [
            'id'             => $d->id,
            'label'          => $d->label,
            'type'           => $d->type,
            'is_active'      => (bool) $d->is_active,
            'last_run_at'    => $d->last_run_at?->toIso8601String(),
            'last_run_status'=> $d->last_run_status,
            'created_at'     => $d->created_at?->toIso8601String(),
            // Masked summary only — secrets are intentionally omitted
            'config_summary'        => $this->configSummary($d),
            // C1: lets the UI warn about destinations still pushing legacy
            // (unencrypted) envelopes.
            'has_encryption_password' => !empty(($d->config ?? [])['encryption_password']),
        ];
    }

    private function configSummary(UserBackupDestination $d): array
    {
        $config = $d->config ?? [];

        return match ($d->type) {
            'local'  => ['path' => $config['path'] ?? null],
            's3'     => [
                'bucket'   => $config['bucket'] ?? null,
                'region'   => $config['region'] ?? null,
                'endpoint' => $config['endpoint'] ?? null,
                'prefix'   => $config['prefix'] ?? null,
            ],
            'webdav' => ['url' => $config['url'] ?? null, 'path' => $config['path'] ?? null],
            'email'  => [
                'email'             => $config['email'] ?? null,
                'email_attachments' => (bool) ($config['email_attachments'] ?? false),
            ],
            default  => [],
        };
    }
}
