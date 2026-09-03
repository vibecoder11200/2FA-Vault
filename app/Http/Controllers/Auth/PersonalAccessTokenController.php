<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Passport\Http\Controllers\PersonalAccessTokenController as PassportPatController;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenResult;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PersonalAccessTokenController extends PassportPatController
{
    /**
     * Get all of the personal access tokens for the authenticated user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Passport\Token>|\Illuminate\Http\JsonResponse
     */
    public function forUser(Request $request)
    {
        if (Gate::denies('manage-pat')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        return parent::forUser($request);
    }

    /**
     * Create a new personal access token for the user.
     *
     * @return \Laravel\Passport\PersonalAccessTokenResult<\Laravel\Passport\Token>
     */
    public function store(Request $request) : PersonalAccessTokenResult
    {
        if (Gate::denies('manage-pat')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        // A6 / RT1: a new PAT must explicitly request at least one valid
        // scope. The omitted-scopes default ([] from Passport's parent
        // implementation) must 422 — otherwise attackers could mint
        // unscoped, effectively-full-access tokens.
        $this->validation->make($request->all(), [
            'name'   => ['required', 'max:255'],
            'scopes' => ['required', 'array', 'min:1', Rule::in(Passport::scopeIds())],
        ])->validate();

        return $request->user()->createToken(
            $request->name,
            Passport::validScopes($request->scopes)
        );
    }

    /**
     * Delete the given token.
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, string $tokenId) : Response
    {
        if (Gate::denies('manage-pat')) {
            throw new AccessDeniedHttpException(__('error.unsupported_with_sso_only'));
        }

        return parent::destroy($request, $tokenId);
    }
}
