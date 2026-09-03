<?php

namespace App\Http\Controllers;

use App\Enums\TeamAction;
use App\Models\Team;
use App\Services\TeamActivityLogger;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    protected TeamService $teamService;

    protected TeamActivityLogger $activityLogger;

    public function __construct(TeamService $teamService, TeamActivityLogger $activityLogger)
    {
        $this->teamService    = $teamService;
        $this->activityLogger = $activityLogger;
    }

    /**
     * List all teams for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $teams = Team::accessibleByUser($user->id)
            ->with(['owner', 'users'])
            ->withCount([
                'users',
                'sharedAccounts',
                'invitations as pending_invitations_count' => fn ($q) => $q->where('status', 'pending'),
            ])
            ->get()
            ->map(function ($team) use ($user) {
                return [
                    'id'                    => $team->id,
                    'name'                  => $team->name,
                    'owner_id'              => $team->owner_id,
                    'owner_name'            => $team->owner->name,
                    'role'                  => $team->getUserRole($user->id),
                    'members_count'         => $team->users_count,
                    'shared_accounts_count' => $team->shared_accounts_count,
                    'pending_invitations'   => $team->pending_invitations_count,
                    'created_at'            => $team->created_at,
                    'invite_code'           => $team->owner_id === $user->id ? $team->invite_code : null,
                ];
            });

        return response()->json($teams);
    }

    /**
     * Create a new team.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        try {
            $team = $this->teamService->createTeam($user, $validated['name']);
            $this->activityLogger->log($team, $user, TeamAction::TEAM_CREATED, ['name' => $team->name]);

            return response()->json($team, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Show team details with members.
     */
    public function show(Request $request, $id)
    {
        $team = Team::with(['owner', 'users'])->findOrFail($id);

        if (! Gate::allows('view', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $members = $team->users->map(function ($user) use ($team) {
            return [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'role'      => $team->getUserRole($user->id),
                'joined_at' => $user->pivot->joined_at,
            ];
        });

        $stats = $this->teamService->getTeamStats($team);

        return response()->json([
            'id'          => $team->id,
            'name'        => $team->name,
            'owner_id'    => $team->owner_id,
            'owner_name'  => $team->owner->name,
            'invite_code' => Gate::allows('invite', $team) ? $team->invite_code : null,
            'members'     => $members,
            'stats'       => $stats,
            'created_at'  => $team->created_at,
        ]);
    }

    /**
     * Update team name.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $team = $this->teamService->updateTeam($team, $user, $validated['name']);

            return response()->json($team);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Delete a team (soft delete).
     */
    public function destroy(Request $request, $id)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $this->teamService->deleteTeam($team, $user);

            return response()->json(['message' => 'Team deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Invite a user to the team.
     */
    public function invite(Request $request, $id)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'role'  => ['nullable', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $invitation = $this->teamService->inviteUser(
                $team,
                $user,
                $validated['email'],
                $validated['role'] ?? 'member'
            );

            return response()->json([
                'message'    => 'Invitation sent successfully',
                'invitation' => $invitation,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Accept team invitation.
     */
    public function acceptInvitation(Request $request, $token)
    {
        $invitation = \App\Models\TeamInvitation::where('token', $token)
            ->where('status', 'pending')
            ->firstOrFail();

        $user = Auth::user();

        try {
            $team = $this->teamService->acceptInvitation($invitation, $user);

            return response()->json([
                'message' => 'Successfully joined team',
                'team'    => $team,
            ]);
        } catch (\Exception $e) {
            $statusCode = $e->getMessage() === 'This invitation is not for your email address' ? 403 : 400;

            return response()->json(['message' => $e->getMessage()], $statusCode);
        }
    }

    /**
     * List pending invitations for a team.
     */
    public function invitations(Request $request, $id)
    {
        $team = Team::findOrFail($id);

        if (! Gate::allows('invite', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invitations = \App\Models\TeamInvitation::where('team_id', $team->id)
            ->where('status', 'pending')
            ->get()
            ->map(function ($inv) {
                return [
                    'id'         => $inv->id,
                    'email'      => $inv->email,
                    'role'       => $inv->role,
                    'status'     => $inv->status,
                    'expires_at' => $inv->expires_at,
                    'created_at' => $inv->created_at,
                ];
            });

        return response()->json($invitations);
    }

    /**
     * Cancel a pending invitation.
     */
    public function cancelInvitation(Request $request, $id, $invitationId)
    {
        $team = Team::findOrFail($id);

        if (! Gate::allows('invite', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invitation = \App\Models\TeamInvitation::where('id', $invitationId)
            ->where('team_id', $team->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $invitation->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Invitation cancelled']);
    }

    /**
     * Join a team via invite code.
     */
    public function join(Request $request)
    {
        $validated = $request->validate([
            'invite_code' => 'required|string|exists:teams,invite_code',
        ]);

        $user = Auth::user();

        try {
            $team = $this->teamService->joinByInviteCode($validated['invite_code'], $user);

            return response()->json([
                'message' => 'Successfully joined team',
                'team'    => $team,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Leave a team.
     */
    public function leave(Request $request, $id)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $this->teamService->leaveTeam($team, $user);
            $this->activityLogger->log($team, $user, TeamAction::MEMBER_LEFT);

            return response()->json(['message' => 'Successfully left the team']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Remove a member from the team.
     */
    public function removeMember(Request $request, $id, $userId)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $targetUser = \App\Models\User::find($userId);
            $this->teamService->removeMember($team, $user, $userId);
            if ($targetUser) {
                $this->activityLogger->log($team, $user, TeamAction::MEMBER_REMOVED, null, $targetUser);
            }

            return response()->json(['message' => 'Member removed successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Update member role.
     */
    public function updateMemberRole(Request $request, $id, $userId)
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        try {
            $this->teamService->updateMemberRole($team, $user, $userId, $validated['role']);

            return response()->json(['message' => 'Member role updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Transfer team ownership to another member.
     */
    public function transferOwnership(Request $request, $id)
    {
        $validated = $request->validate([
            'new_owner_id' => 'required|integer|exists:users,id',
        ]);

        $team = Team::findOrFail($id);
        $this->authorize('transferOwnership', $team);
        $user = Auth::user();

        try {
            $team = $this->teamService->transferOwnership($team, $user, $validated['new_owner_id']);

            $this->activityLogger->log(
                $team,
                $user,
                TeamAction::OWNERSHIP_TRANSFERRED,
                ['new_owner_id' => $validated['new_owner_id']]
            );

            return response()->json($team);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Share an account with a team (plain, non-E2EE).
     */
    public function shareAccount(Request $request, $id)
    {
        $validated = $request->validate([
            'twofaccount_id' => 'required|integer|exists:twofaccounts,id',
            'access_level'   => 'nullable|in:read,write,admin',
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        if (! $team->hasMember($user->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $account = \App\Models\TwoFAccount::where('id', $validated['twofaccount_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            $sharedAccount = $this->teamService->shareAccountWithTeam(
                $account,
                $team,
                $user,
                $validated['access_level'] ?? 'read'
            );

            $this->activityLogger->log($team, $user, TeamAction::ACCOUNT_SHARED, ['access_level' => $validated['access_level'] ?? 'read'], null, $account);

            return response()->json([
                'message'        => 'Account shared with team successfully',
                'shared_account' => $sharedAccount,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Share an account with per-member encrypted keys (E2EE sharing).
     * Owner wraps the account secret with each member's public key client-side.
     */
    public function shareEncrypted(Request $request, $id)
    {
        $validated = $request->validate([
            'twofaccount_id'            => 'required|integer|exists:twofaccounts,id',
            'access_level'              => 'nullable|in:read,write',
            'member_keys'               => 'required|array|min:1',
            'member_keys.*.member_id'   => 'required|integer|exists:users,id',
            'member_keys.*.wrapped_key' => 'required|string',
        ]);

        $team = Team::findOrFail($id);
        $user = Auth::user();

        $account = \App\Models\TwoFAccount::where('id', $validated['twofaccount_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            $this->teamService->shareEncryptedWithMembers(
                $account,
                $team,
                $user,
                $validated['access_level'] ?? 'read',
                $validated['member_keys']
            );

            return response()->json(['message' => 'Account shared with encrypted keys successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Get a team member's public key (for key wrapping).
     */
    public function memberPublicKey(Request $request, $id, $userId)
    {
        $team = Team::findOrFail($id);

        if (! Gate::allows('view', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $team->hasMember($userId)) {
            return response()->json(['message' => 'User is not a team member'], 404);
        }

        $member = \App\Models\User::select('id', 'name', 'public_key')->findOrFail($userId);

        return response()->json([
            'user_id'    => $member->id,
            'name'       => $member->name,
            'public_key' => $member->public_key,
        ]);
    }

    /**
     * Register the authenticated user's public key for E2EE sharing.
     */
    public function registerPublicKey(Request $request)
    {
        $validated = $request->validate([
            'public_key' => 'required|string|max:4096',
        ]);

        Auth::user()->update(['public_key' => $validated['public_key']]);

        return response()->json(['message' => 'Public key registered successfully']);
    }

    /**
     * Unshare an account from a team.
     *
     * B4: an account may have multiple shared_accounts rows for the same team
     * (one per member for E2EE shares). Unsharing revokes ALL of them.
     */
    public function unshareAccount(Request $request, $id, $accountId)
    {
        $team = Team::findOrFail($id);
        $user = Auth::user();

        $sharedAccounts = \App\Models\SharedAccount::where('team_id', $team->id)
            ->where('twofaccount_id', $accountId)
            ->get();

        if ($sharedAccounts->isEmpty()) {
            abort(404);
        }

        if ($sharedAccounts->first()->shared_by !== $user->id) {
            $role = $team->getUserRole($user->id);
            if (! in_array($role, ['owner', 'admin'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $accountToLog = $sharedAccounts->first()->twoFAccount;
        \App\Models\SharedAccount::where('team_id', $team->id)
            ->where('twofaccount_id', $accountId)
            ->delete();
        $this->activityLogger->log($team, $user, TeamAction::ACCOUNT_UNSHARED, null, null, $accountToLog);

        return response()->json(['message' => 'Account unshared successfully']);
    }

    /**
     * List shared accounts for a team.
     */
    public function sharedAccounts(Request $request, $id)
    {
        $team = Team::findOrFail($id);

        if (! Gate::allows('view', $team)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $requestingUserId = Auth::id();

        $sharedAccounts = $team->sharedAccounts()
            ->with(['twoFAccount', 'sharedBy'])
            // B15: deterministic order — member rows before legacy NULL-member
            // rows so unique('twofaccount_id') never drops a member's
            // wrapped-key row in favor of a legacy plain share.
            ->orderByDesc('member_id')
            ->get()
            // For encrypted shares, return only the row for the requesting user
            ->filter(function ($sa) use ($requestingUserId) {
                // Include if no member_id (legacy plain share) or if this row is for the requesting user
                return is_null($sa->member_id) || $sa->member_id === $requestingUserId;
            })
            ->unique('twofaccount_id')
            ->values()
            ->map(function ($sa) {
                return [
                    'id'              => $sa->id,
                    'twofaccount_id'  => $sa->twofaccount_id,
                    'account_service' => $sa->twoFAccount->service ?? '',
                    'account_name'    => $sa->twoFAccount->account ?? '',
                    'shared_by'       => $sa->sharedBy->name,
                    'shared_by_id'    => $sa->shared_by,
                    'access_level'    => $sa->access_level,
                    'wrapped_key'     => $sa->wrapped_key,
                    'created_at'      => $sa->created_at,
                ];
            });

        return response()->json($sharedAccounts);
    }
}
