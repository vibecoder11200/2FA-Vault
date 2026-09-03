<?php

namespace App\Policies;

use App\Models\SharedAccount;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Log;

class TwoFAccountPolicy
{
    use HandlesAuthorization, OwnershipTrait;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    // public function viewAny(User $user)
    // {
    //     return false;
    // }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, TwoFAccount $twofaccount)
    {
        // The owner can always view.
        if ($this->isOwnerOf($user, $twofaccount)) {
            return true;
        }

        // A recipient of a shared account (member_id match) can also view
        // and generate OTPs for the shared account — but ONLY while they are
        // still an active member of the sharing team (B3 belt-and-braces:
        // member removal also deletes the shared_accounts rows, this guard
        // covers any row that survives out-of-band).
        $can = SharedAccount::where('twofaccount_id', $twofaccount->id)
            ->where('member_id', $user->id)
            ->whereHas('team', function ($q) use ($user) {
                $q->whereHas('users', function ($sq) use ($user) {
                    $sq->where('users.id', $user->id);
                });
            })
            ->exists();

        if (! $can) {
            Log::notice(sprintf('User ID #%s cannot view twofaccount ID #%s', $user->id, $twofaccount->id));
        }

        return $can;
    }

    /**
     * Determine whether the user can generate an OTP for the model.
     * This is equivalent to view (owner or shared recipient).
     *
     * @return bool
     */
    public function generateOtp(User $user, TwoFAccount $twofaccount)
    {
        return $this->view($user, $twofaccount);
    }

    /**
     * Determine whether the user can read the raw secret of the model.
     * Only the owner can read the secret; shared recipients can only
     * generate OTPs (view) but not see the secret itself.
     *
     * @return bool
     */
    public function readSecret(User $user, TwoFAccount $twofaccount)
    {
        $can = $this->isOwnerOf($user, $twofaccount);

        if (! $can) {
            Log::notice(sprintf('User ID #%s cannot read secret of twofaccount ID #%s', $user->id, $twofaccount->id));
        }

        return $can;
    }

    /**
     * Determine whether the user can transfer ownership of the model.
     * Only the owner can transfer.
     *
     * @return bool
     */
    public function transferOwnership(User $user, TwoFAccount $twofaccount)
    {
        $can = $this->isOwnerOf($user, $twofaccount);

        if (! $can) {
            Log::notice(sprintf('User ID #%s cannot transfer ownership of twofaccount ID #%s', $user->id, $twofaccount->id));
        }

        return $can;
    }

    /**
     * Determine whether the user can view all provided models.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\TwoFAccount>  $twofaccounts
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewEach(User $user, TwoFAccount $twofaccount, $twofaccounts)
    {
        $can = $this->isOwnerOfEach($user, $twofaccounts);

        if (! $can) {
            $ids = $twofaccounts->map(function ($twofaccount, $key) {
                return $twofaccount->id;
            });
            Log::notice(sprintf('User ID #%s cannot view all twofaccounts in IDs #%s', $user->id, implode(',', $ids->toArray())));
        }

        return $can;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        // Log::notice(sprintf('User ID #%s cannot create twofaccounts', $user->id));

        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, TwoFAccount $twofaccount)
    {
        // B12 semantics: shared-account recipients never get an update path —
        // even access_level=write shares can only read/generate OTP. Under
        // E2EE the server cannot re-wrap the secret for members, so allowing
        // member edits would desynchronize wrapped keys (see RT7: secret
        // changes revoke shares instead). If a member metadata-mutation path
        // is ever added, it MUST check SharedAccount.access_level == 'write'
        // here and forbid secret changes.
        $can = $this->isOwnerOf($user, $twofaccount);

        if (! $can) {
            Log::notice(sprintf('User ID #%s cannot update twofaccount ID #%s', $user->id, $twofaccount->id));
        }

        return $can;
    }

    /**
     * Determine whether the user can update all provided models.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\TwoFAccount>  $twofaccounts
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function updateEach(User $user, TwoFAccount $twofaccount, $twofaccounts)
    {
        $can = $this->isOwnerOfEach($user, $twofaccounts);

        if (! $can) {
            $ids = $twofaccounts->map(function ($twofaccount, $key) {
                return $twofaccount->id;
            });
            Log::notice(sprintf('User ID #%s cannot update all twofaccounts in IDs #%s', $user->id, implode(',', $ids->toArray())));
        }

        return $can;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, TwoFAccount $twofaccount)
    {
        $can = $this->isOwnerOf($user, $twofaccount);

        if (! $can) {
            Log::notice(sprintf('User ID #%s cannot delete twofaccount ID #%s', $user->id, $twofaccount->id));
        }

        return $can;
    }

    /**
     * Determine whether the user can delete all provided models.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\TwoFAccount>  $twofaccounts
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteEach(User $user, TwoFAccount $twofaccount, $twofaccounts)
    {
        $can = $this->isOwnerOfEach($user, $twofaccounts);

        if (! $can) {
            $ids = $twofaccounts->map(function ($twofaccount, $key) {
                return $twofaccount->id;
            });
            Log::notice(sprintf('User ID #%s cannot delete all twofaccounts in IDs #%s', $user->id, implode(',', $ids->toArray())));
        }

        return $can;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TwoFAccount  $twofaccount
     * @return \Illuminate\Auth\Access\Response|bool
     */
    // public function restore(User $user, TwoFAccount $twofaccount)
    // {

    // }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TwoFAccount  $twofaccount
     * @return \Illuminate\Auth\Access\Response|bool
     */
    // public function forceDelete(User $user, TwoFAccount $twofaccount)
    // {

    // }
}
