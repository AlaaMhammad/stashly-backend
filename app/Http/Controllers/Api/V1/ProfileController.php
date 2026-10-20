<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\DeleteAccountRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Accounts\AccountDeletionService;
use App\Services\Accounts\DataExportService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * API-PROF-003: 200 {links_count, collections_count, favorites_count}.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::data([
            'links_count' => $user->links()->count(),
            'collections_count' => $user->collections()->count(),
            'favorites_count' => $user->links()->where('is_favorite', true)->count(),
        ]);
    }

    /**
     * API-PROF-004: JSON file attachment of all the user's data; limited to
     * 1 per 10 minutes (throttle:export). Logs counts-free audit only: never content.
     */
    public function export(Request $request, DataExportService $export): StreamedResponse
    {
        $user = $request->user();
        Log::info('Account data exported.', ['user_id' => $user->getKey()]);

        return response()->streamDownload(function () use ($export, $user, $request) {
            foreach ($export->stream($user, $request) as $fragment) {
                echo $fragment;
            }
        }, 'stashly-export-'.now()->utc()->format('Ymd-His').'.json', [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
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
