<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoogleAuthRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\GoogleSignInService;
use App\Services\Auth\GoogleTokenException;
use App\Services\Auth\TokenIssuer;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class GoogleAuthController extends Controller
{
    /**
     * API-AUTH-008 (DEC-035): sign in or sign up with a Google ID token.
     * 200 {user, token} for an existing or newly linked account, 201 for a new account.
     * 401 INVALID_GOOGLE_TOKEN, 422 VALIDATION_FAILED (new account without terms_accepted),
     * 503 SERVICE_UNAVAILABLE (Google sign-in not configured, or Google's keys unreachable).
     */
    public function __invoke(
        GoogleAuthRequest $request,
        GoogleIdTokenVerifier $verifier,
        GoogleSignInService $signIn,
        TokenIssuer $tokens,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $identity = $verifier->verify($data['id_token']);
            [$user, $created] = $signIn->resolve(
                $identity,
                (bool) ($data['terms_accepted'] ?? false),
                $data['terms_version'] ?? null,
            );
        } catch (GoogleTokenException $e) {
            // Reason code only is logged: never the token, the e-mail or any claim.
            throw $e->toApiException();
        }

        $token = $tokens->issue($user, $data['device_name']);

        return ApiResponse::data([
            'user' => UserResource::make($user)->resolve($request),
            'token' => $tokens->present($token),
        ], $created ? 201 : 200);
    }
}
