<?php

namespace App\Http\Controllers\Auth;

use App\Facades\Settings;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserStoreRequest;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\EmergencyAccessService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Handle a registration request for the application.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(UserStoreRequest $request)
    {
        $invitation = null;

        // Check if registration is via invitation
        if ($request->has('invitation')) {
            $invitation = UserInvitation::where('token', $request->invitation)->first();

            if (! $invitation || $invitation->isExpired() || ! $invitation->isPending()) {
                return response()->json(['message' => 'Invalid or expired invitation'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // The invitation is bound to the invited email: a registration
            // under any other email must not consume it (A13).
            if ($request->filled('email') && strtolower($request->input('email')) !== strtolower($invitation->email)) {
                return response()->json([
                    'message' => 'The email does not match the invited email address',
                    'errors'  => ['email' => ['The email does not match the invited email address']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Bypass registration open check for valid invitations
        } elseif (Settings::get('disableRegistration') == true) {
            return response()->json(['message' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validated();

        // Pre-fill email from invitation if available
        if ($invitation && empty($validated['email'])) {
            $validated['email'] = $invitation->email;
        }

        event(new Registered($user = $this->create($validated)));

        // B17: attach pending emergency contacts that were designated for
        // this email before the user registered (the owner is nudged to
        // re-save the wrapped key now that the grantee exists).
        app(EmergencyAccessService::class)->linkPendingContacts($user);

        // Mark invitation as accepted
        if ($invitation) {
            $invitation->update(['accepted_at' => now()]);
        }

        $this->guard()->login($user);

        // Session fixation hardening: the pre-authentication session id must
        // not survive authentication.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        /**
         * @var \App\Models\User|null
         */
        $user = $this->guard()->user();

        return response()->json([
            'message'       => 'account created',
            'name'          => $user->name,
            'email'         => $user->email,
            'preferences'   => $user->preferences,
            'is_admin'      => $user->isAdministrator(),
            'e2ee_required' => app(\App\Services\EncryptionService::class)->isEncryptionRequired($user),
        ], 201);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        $user = User::create([
            'name'                  => $data['name'],
            'email'                 => $data['email'],
            'password'              => Hash::make($data['password']),
            'encryption_enabled'    => false,
            'encryption_salt'       => null,
            'encryption_test_value' => null,
            'encryption_version'    => 0,
            'vault_locked'          => false,
        ]);

        Log::info(sprintf('User ID #%s created', $user->id));

        if (User::count() == 1) {
            $user->promoteToAdministrator();
            $user->save();
            Log::notice(sprintf('User ID #%s set as administrator', $user->id));
        }

        return $user;
    }
}
