<?php

namespace App\Http\Controllers;

use App\Models\Vault;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VaultController extends Controller
{
    public function __construct(protected VaultService $service) {}

    public function index(): JsonResponse
    {
        $vaults = Auth::user()->vaults()
            ->withCount(['accounts', 'groups'])
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json($vaults);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:100']);

        try {
            $vault = $this->service->createVault(Auth::user(), $validated['name']);
            return response()->json($vault, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vault = Auth::user()->vaults()->findOrFail($id);
        $validated = $request->validate(['name' => 'required|string|max:100']);
        $vault = $this->service->renameVault($vault, $validated['name']);
        return response()->json($vault);
    }

    public function destroy(int $id): JsonResponse
    {
        $vault = Auth::user()->vaults()->findOrFail($id);
        try {
            $this->service->deleteVault($vault);
            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function lock(int $id): JsonResponse
    {
        $vault = Auth::user()->vaults()->findOrFail($id);
        $this->service->lock($vault);
        return response()->json(['message' => 'Vault locked']);
    }

    public function unlock(int $id): JsonResponse
    {
        $vault = Auth::user()->vaults()->findOrFail($id);
        $this->service->unlock($vault);
        return response()->json(['message' => 'Vault unlocked']);
    }

    public function setupEncryption(Request $request, int $id): JsonResponse
    {
        $vault = Auth::user()->vaults()->findOrFail($id);

        // B11: refuse when encryption is already configured — blindly
        // overwriting the salt would brick every secret encrypted under the
        // previous key (same guard as the user-level EncryptionService).
        if ($vault->encryption_salt !== null) {
            return response()->json([
                'message' => 'Encryption is already configured for this vault',
            ], 409);
        }

        $validated = $request->validate([
            'salt'       => 'required|string',
            'test_value' => 'required|string',
        ]);
        $this->service->setupEncryption($vault, $validated['salt'], $validated['test_value']);
        return response()->json(['message' => 'Encryption configured for vault']);
    }
}
