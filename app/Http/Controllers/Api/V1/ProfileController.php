<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\DeleteAccountRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Accounts\AccountDeletionService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * API-PROF-001: current user; also the app's session check.
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::data(['user' => UserResource::make($request->user())->resolve($request)]);
    }

    /**
     * API-PROF-002: change the display name.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['display_name' => $request->validated('display_name')]);

        return ApiResponse::data(['user' => UserResource::make($user)->resolve($request)]);
    }

    /**
     * API-AUTH-007: change password and revoke the user's other tokens.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        DB::transaction(function () use ($user, $current, $request) {
            $user->forceFill(['password' => $request->validated('password')])->save();
            $user->tokens()->whereKeyNot($current->getKey())->delete();
        });

        return ApiResponse::data(['message' => 'Your password has been changed.']);
    }

    /**
     * API-PROF-005: permanent deletion after a password check. 204.
     */
    public function destroy(DeleteAccountRequest $request, AccountDeletionService $deletion): Response
    {
        $deletion->delete($request->user());

        return response()->noContent();
    }
}
