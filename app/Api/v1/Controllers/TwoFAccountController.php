<?php

namespace App\Api\v1\Controllers;

use App\Api\v1\Requests\ReorderRequest;
use App\Api\v1\Requests\TwoFAccountBatchRequest;
use App\Api\v1\Requests\TwoFAccountDynamicRequest;
use App\Api\v1\Requests\TwoFAccountExportRequest;
use App\Api\v1\Requests\TwoFAccountImportRequest;
use App\Api\v1\Requests\TwoFAccountIndexRequest;
use App\Api\v1\Requests\TwoFAccountStoreRequest;
use App\Api\v1\Requests\TwoFAccountUpdateRequest;
use App\Api\v1\Requests\TwoFAccountUriRequest;
use App\Api\v1\Resources\EncryptedTwoFAccountResource;
use App\Api\v1\Resources\TwoFAccountCollection;
use App\Api\v1\Resources\TwoFAccountExportCollection;
use App\Api\v1\Resources\TwoFAccountReadResource;
use App\Api\v1\Resources\TwoFAccountStoreResource;
use App\Enums\PersonalAction;
use App\Facades\Groups;
use App\Facades\TwoFAccounts;
use App\Helpers\Helpers;
use App\Http\Controllers\Controller;
use App\Models\OtpLog;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\PersonalActivityLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TwoFAccountController extends Controller
{
    public function __construct(protected PersonalActivityLogger $activityLogger) {}

    /**
     * List all resources
     *
     * @return \App\Api\v1\Resources\TwoFAccountCollection
     */
    public function index(TwoFAccountIndexRequest $request)
    {
        // Quick fix for #176
        if (config('auth.defaults.guard') === 'reverse-proxy-guard' && User::count() === 1) {
            if (TwoFAccount::orphans()->exists()) {
                $twofaccounts = TwoFAccount::orphans()->get();
                TwoFAccounts::setUser($twofaccounts, $request->user());
            }
        }

        $validated = $request->validated();

        if (Arr::has($validated, 'ids')) {
            return new TwoFAccountCollection(
                $request->user()->twofaccounts()
                    ->whereIn('id', Helpers::commaSeparatedToArray($validated['ids']))
                    ->with('tags')
                    ->get()
                    ->sortBy('order_column')
            );
        }

        // Advanced search/filter
        if ($request->hasAny(['q', 'types', 'algorithms', 'digits', 'group_id', 'tags', 'encrypted', 'last_used_from', 'last_used_to', 'sort'])) {
            $query = $request->user()->twofaccounts()->with('tags');

            if ($q = $request->get('q')) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('service', 'LIKE', "%{$q}%")
                        ->orWhere('account', 'LIKE', "%{$q}%");
                });
            }
            if ($request->filled('types')) {
                $query->whereIn('otp_type', explode(',', $request->types));
            }
            if ($request->filled('algorithms')) {
                $query->whereIn('algorithm', explode(',', $request->algorithms));
            }
            if ($request->filled('digits')) {
                $query->whereIn('digits', array_map('intval', explode(',', $request->digits)));
            }
            if ($request->filled('group_id')) {
                $groupId = (int) $request->group_id;

                // Virtual sharing groups: filter by SharedAccount membership
                // instead of the group_id column.
                if ($groupId === \App\Models\Group::SHARED_BY_ME_ID) {
                    $query->whereHas('sharedAccounts', function ($q) use ($request) {
                        $q->where('shared_by', $request->user()->id);
                    });
                } elseif ($groupId === \App\Models\Group::SHARED_WITH_ME_ID) {
                    // B9: shared-with-me accounts are NOT owned by the requester,
                    // so the query must be rebuilt without the user_id scope —
                    // the previous `own accounts AND shared with me` intersection
                    // could never match and the group was always empty.
                    return new TwoFAccountCollection(
                        TwoFAccount::whereHas('sharedAccounts', function ($q) use ($request) {
                            $q->where('member_id', $request->user()->id);
                        })->with('tags')->get()->sortBy('order_column')
                    );
                } else {
                    $query->where('group_id', $groupId);
                }
            }
            if ($request->filled('tags')) {
                $tagIds = array_filter(array_map('intval', explode(',', $request->tags)));
                $mode   = $request->get('tag_mode', 'or');
                if ($mode === 'and') {
                    foreach ($tagIds as $tagId) {
                        $query->whereHas('tags', fn ($q) => $q->where('tags.id', $tagId));
                    }
                } else {
                    $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds));
                }
            }
            if ($request->has('encrypted')) {
                $query->where('encrypted', $request->boolean('encrypted'));
            }
            if ($request->filled('last_used_from')) {
                $query->where('last_used_at', '>=', $request->last_used_from);
            }
            if ($request->filled('last_used_to')) {
                $query->where('last_used_at', '<=', $request->last_used_to);
            }
            $sortField = in_array($request->get('sort'), ['service', 'account', 'created_at', 'last_used_at', 'order_column']) ? $request->get('sort') : 'order_column';
            $sortDir   = $request->get('sort_dir', 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($sortField, $sortDir);

            return new TwoFAccountCollection($query->get());
        }

        return new TwoFAccountCollection($request->user()->twofaccounts()->with('tags')->get()->sortBy('order_column'));
    }

    /**
     * Display a 2FA account
     *
     * @return \App\Api\v1\Resources\TwoFAccountReadResource
     */
    public function show(TwoFAccount $twofaccount)
    {
        $this->authorize('view', $twofaccount);

        // $icon = $twofaccount->icon;
        // $iconRes = $twofaccount->icon()->get();

        return new TwoFAccountReadResource($twofaccount);
    }

    public function encrypted(Request $request)
    {
        return EncryptedTwoFAccountResource::collection(
            $request->user()->twofaccounts()->where('encrypted', true)->get()->sortBy('order_column')
        );
    }

    /**
     * Store a new 2FA account
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(TwoFAccountDynamicRequest $request)
    {
        $this->authorize('create', TwoFAccount::class);

        // Two possible cases :
        // - The most common case, an URI is provided by the QuickForm, thanks to a QR code live scan or file upload
        //     -> We use that URI to define the account
        // - The advanced form has been used and all individual parameters
        //     -> We use the parameters array to define the account

        $validated   = $request->validated();
        $twofaccount = new TwoFAccount;

        if (Arr::has($validated, 'uri')) {
            $twofaccount->fillWithURI($validated['uri'], Arr::get($validated, 'custom_otp') === TwoFAccount::STEAM_TOTP);
        } else {
            $twofaccount->fillWithOtpParameters($validated);
        }

        // Detect E2EE encrypted secrets and set the encrypted flag
        if (str_starts_with($twofaccount->secret ?? '', '{') && str_contains($twofaccount->secret ?? '', 'ciphertext')) {
            $twofaccount->encrypted = true;
        }

        $request->user()->twofaccounts()->save($twofaccount);

        // Possible group association
        try {
            Groups::assign($twofaccount->id, $request->user(), Arr::get($validated, 'group_id', null));
        } catch (\Throwable $th) {
            Log::warning('Group assignment failed after account creation', [
                'account_id' => $twofaccount->id,
                'error'      => $th->getMessage(),
            ]);
        }

        $this->activityLogger->log($request->user(), PersonalAction::ACCOUNT_CREATED, [], $twofaccount->id);

        return (new TwoFAccountReadResource($twofaccount->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a 2FA account
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(TwoFAccountUpdateRequest $request, TwoFAccount $twofaccount)
    {
        $this->authorize('update', $twofaccount);

        $validated = $request->validated();

        // RT7/B7: remember the current secret ciphertext so we can detect an
        // actual secret change below (metadata-only edits must NOT revoke shares).
        $secretBefore = $twofaccount->getOriginal('secret');

        $twofaccount->fillWithOtpParameters($validated, $twofaccount->icon && is_null(Arr::get($validated, 'icon', null)));
        $request->user()->twofaccounts()->save($twofaccount);

        // If the secret changed and the account is shared, the members' wrapped
        // keys are now stale — under E2EE the server cannot re-wrap the secret
        // for each member, so the shares are revoked and the owner UI is told
        // to re-share (it holds the member public keys client-side).
        $sharesRevoked = false;
        $revokedMembers = collect();
        if ($secretBefore !== $twofaccount->secret) {
            $staleShares = \App\Models\SharedAccount::with('member:id,name,email')
                ->where('twofaccount_id', $twofaccount->id)
                ->whereNotNull('member_id')
                ->get();

            if ($staleShares->isNotEmpty()) {
                \App\Models\SharedAccount::where('twofaccount_id', $twofaccount->id)->delete();
                $sharesRevoked = true;
                $revokedMembers = $staleShares->pluck('member')->filter();
                Log::notice('Shares revoked after secret change', [
                    'twofaccount_id' => $twofaccount->id,
                    'members'        => $revokedMembers->pluck('id')->all(),
                ]);
            }
        }

        // Possible group change
        $groupId = Arr::get($validated, 'group_id', null);
        if ($twofaccount->group_id != $groupId) {
            if ((int) $groupId === 0) {
                TwoFAccounts::withdraw($twofaccount->id);
            } else {
                try {
                    Groups::assign($twofaccount->id, $request->user(), $groupId);
                } catch (ModelNotFoundException $exc) {
                    // The destination group no longer exists, the twofaccount is withdrawn
                    TwoFAccounts::withdraw($twofaccount->id);
                }
            }
            $twofaccount->refresh();
        }

        $this->activityLogger->log($request->user(), PersonalAction::ACCOUNT_UPDATED, [], $twofaccount->id);

        $response = (new TwoFAccountReadResource($twofaccount))->response()->setStatusCode(200);

        if ($sharesRevoked) {
            // RT7: tell the owner UI which members lost access so it can offer
            // a one-click re-share (the client holds member public keys).
            $response->setData(collect($response->getData(true))->merge([
                'shares_revoked' => true,
                'revoked_members' => $revokedMembers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email])->values(),
            ]));
        }

        return $response;
    }

    /**
     * Update a HOTP counter without revalidating or touching the encrypted secret.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCounter(Request $request, TwoFAccount $twofaccount)
    {
        $this->authorize('update', $twofaccount);

        $validated = $request->validate([
            'counter' => 'required|integer|min:0',
        ]);

        if ($twofaccount->otp_type !== TwoFAccount::HOTP) {
            throw ValidationException::withMessages([
                'counter' => 'Only HOTP accounts can update a counter.',
            ]);
        }

        $currentCounter = (int) ($twofaccount->counter ?? TwoFAccount::DEFAULT_COUNTER);
        if ((int) $validated['counter'] <= $currentCounter) {
            throw ValidationException::withMessages([
                'counter' => 'The counter must be greater than the current HOTP counter.',
            ]);
        }

        $updated = $request->user()->twofaccounts()
            ->whereKey($twofaccount->getKey())
            ->where(function ($query) use ($validated) {
                $query->whereNull('counter')
                    ->orWhere('counter', '<', $validated['counter']);
            })
            ->update(['counter' => $validated['counter']]);

        if ($updated === 0) {
            throw ValidationException::withMessages([
                'counter' => 'The counter must be greater than the current HOTP counter.',
            ]);
        }

        $twofaccount->refresh();

        return (new TwoFAccountReadResource($twofaccount))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Convert a migration resource to a valid TwoFAccounts collection
     *
     * @return \Illuminate\Http\JsonResponse|\App\Api\v1\Resources\TwoFAccountCollection
     */
    public function migrate(TwoFAccountImportRequest $request)
    {
        $validated = $request->validated();

        if (Arr::has($validated, 'file')) {
            $migrationResource = $request->file('file');

            return $migrationResource instanceof \Illuminate\Http\UploadedFile
                ? new TwoFAccountCollection(TwoFAccounts::migrate($migrationResource->get()))
                : response()->json(['message' => __('error.file_upload_failed')], 500);
        } else {
            return new TwoFAccountCollection(TwoFAccounts::migrate($request->payload));
        }
    }

    /**
     * Save 2FA accounts order
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorder(ReorderRequest $request)
    {
        $validated = $request->validated();

        $twofaccounts = TwoFAccount::whereIn('id', $validated['orderedIds'])->get();
        $this->authorize('updateEach', [new TwoFAccount, $twofaccounts]);

        TwoFAccount::setNewOrder($validated['orderedIds']);
        $orderedIds = $request->user()->twofaccounts->sortBy('order_column')->pluck('id');

        return response()->json([
            'message'    => 'order saved',
            'orderedIds' => $orderedIds,
        ], 200);
    }

    /**
     * Preview account using an uri, without any db moves
     *
     * @return \App\Api\v1\Resources\TwoFAccountStoreResource
     */
    public function preview(TwoFAccountUriRequest $request)
    {
        $twofaccount = new TwoFAccount;
        $twofaccount->fillWithURI($request->uri, $request->custom_otp === TwoFAccount::STEAM_TOTP);

        return new TwoFAccountStoreResource($twofaccount);
    }

    /**
     * Export accounts
     *
     * @return TwoFAccountExportCollection|\Illuminate\Http\JsonResponse
     */
    public function export(TwoFAccountExportRequest $request)
    {
        $validated = $request->validated();

        if ($this->tooManyIds($validated['ids'])) {
            return response()->json([
                'message' => 'bad request',
                'reason'  => [__('error.too_many_ids')],
            ], 400);
        }

        $twofaccounts = TwoFAccounts::export($validated['ids']);
        $this->authorize('viewEach', [new TwoFAccount, $twofaccounts]);

        return new TwoFAccountExportCollection($twofaccounts);
    }

    /**
     * Get a One-Time Password
     *
     * @param  string|null  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function otp(Request $request, $id = null)
    {
        $inputs = $request->all();

        // The request input is the ID of an existing account
        if ($id) {
            $twofaccount = TwoFAccount::findOrFail((int) $id);
            $this->authorize('view', $twofaccount);
            $twofaccount->last_used_at = now();
            $twofaccount->save();
        }

        // The request input is an uri
        elseif ($request->has('uri')) {
            // return 404 if uri is provided with any parameter other than otp_type
            if ((count($inputs) == 2 && $request->missing('custom_otp')) || count($inputs) > 2) {
                return response()->json([
                    'message' => 'bad request',
                    'reason'  => ['uri' => __('validation.onlyCustomOtpWithUri')],
                ], 400);
            } else {
                $validatedData = $request->validate((new TwoFAccountUriRequest)->rules());
                $twofaccount   = new TwoFAccount;
                $twofaccount->fillWithURI($validatedData['uri'], Arr::get($validatedData, 'custom_otp') === TwoFAccount::STEAM_TOTP, true);
            }
        }

        // The request inputs should define an account
        else {
            $validatedData = $request->validate((new TwoFAccountStoreRequest)->rules());
            $twofaccount   = new TwoFAccount;
            $twofaccount->fillWithOtpParameters($validatedData, true);
        }

        $otp = $twofaccount->getOTP();

        // Audit: record that an OTP was generated. Only persisted for a real
        // (stored) account so transient URI/parameter previews are not logged.
        if ($id !== null) {
            $user = $request->user();
            if ($user !== null) {
                $this->logOtpGeneration($user, $twofaccount);
            }
        }

        return response()->json($otp, 200);
    }

    /**
     * Persist an OTP generation audit entry.
     *
     * Requester and owner are identical for a user's own accounts; the duality
     * becomes meaningful for shared accounts (Đợt 5 Hybrid Sharing).
     */
    private function logOtpGeneration(User $user, TwoFAccount $twofaccount) : void
    {
        // Fire-and-forget: logging must not block OTP delivery.
        try {
            OtpLog::create([
                'requester_id'   => $user->id,
                'owner_id'       => $twofaccount->user_id ?? $user->id,
                'twofaccount_id' => $twofaccount->id,
                'otp_type'       => $twofaccount->otp_type,
                'counter'        => $twofaccount->counter,
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
                'generated_at'   => now(),
            ]);
        } catch (\Throwable) { // @codeCoverageIgnore
            // Silent failure — OTP generation must always succeed for the user.
        }
    }

    /**
     * A simple and light method to get the account count.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function count(Request $request)
    {
        return response()->json(['count' => $request->user()->twofaccounts()->count()], 200);
    }

    /**
     * Transfer ownership of a TwoFAccount to another user.
     *
     * The server reassigns user_id and records previous_owner_id. Because
     * accounts are E2EE, the client must have already re-encrypted the
     * account secret for the new owner before calling this endpoint.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function transferOwnership(Request $request, TwoFAccount $twofaccount)
    {
        $validated = $request->validate([
            'new_owner_id' => 'required|integer|exists:users,id',
        ]);

        $this->authorize('transferOwnership', $twofaccount);

        // B20 (dispositioned): under E2EE the server cannot verify that the
        // owner re-encrypted the secret for the new owner's key — the client
        // is trusted to re-wrap before transferring, and the UI shows an
        // explicit confirmation for this reason. Server-side verification is
        // impossible without breaking zero-knowledge; documented limitation.
        $newOwner = User::findOrFail($validated['new_owner_id']);

        $service     = app(\App\Services\TwoFAccountService::class);
        $twofaccount = $service->transferOwnership($twofaccount, $newOwner);

        return response()->json([
            'message'        => 'Ownership transferred successfully',
            'twofaccount_id' => $twofaccount->id,
            'new_owner_id'   => $newOwner->id,
        ], 200);
    }

    /**
     * Withdraw one or more accounts from their group
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function withdraw(TwoFAccountBatchRequest $request)
    {
        $validated = $request->validated();

        if ($this->tooManyIds($validated['ids'])) {
            return response()->json([
                'message' => 'bad request',
                'reason'  => [__('error.too_many_ids')],
            ], 400);
        }

        $ids          = Helpers::commaSeparatedToArray($validated['ids']);
        $twofaccounts = TwoFAccount::whereIn('id', $ids)->get();

        $this->authorize('updateEach', [new TwoFAccount, $twofaccounts]);

        TwoFAccounts::withdraw($ids);

        return response()->json(['message' => 'accounts withdrawn'], 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(TwoFAccount $twofaccount)
    {
        $this->authorize('delete', $twofaccount);

        $accountId = $twofaccount->id;
        $twofaccount->delete();

        $this->activityLogger->log(request()->user(), PersonalAction::ACCOUNT_DELETED, [], $accountId);

        return response()->json(null, 204);
    }

    /**
     * Remove the specified resources from storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDestroy(TwoFAccountBatchRequest $request)
    {
        $validated = $request->validated();

        if ($this->tooManyIds($validated['ids'])) {
            return response()->json([
                'message' => 'bad request',
                'reason'  => [__('error.too_many_ids')],
            ], 400);
        }

        $ids          = Helpers::commaSeparatedToArray($validated['ids']);
        $twofaccounts = TwoFAccount::whereIn('id', $ids)->get();

        $this->authorize('deleteEach', [new TwoFAccount, $twofaccounts]);

        TwoFAccounts::delete($validated['ids']);

        return response()->json(null, 204);
    }

    /**
     * Checks ids length
     *
     * @param  string  $ids  comma-separated ids
     * @return bool whether or not the number of ids is acceptable
     */
    private function tooManyIds(string $ids) : bool
    {
        $arIds = explode(',', $ids, 100);
        $nb    = count($arIds);

        return $nb > 99 ? true : false;
    }
}
