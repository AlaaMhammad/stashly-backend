<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\LogoutRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Support\Api\ApiError;
use App\Support\Api\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly TokenIssuer $tokens) {}

    /**
     * API-AUTH-001: 201 {user, token}. 422 invalid or email already used.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            [$user, $token] = DB::transaction(function () use ($data) {
                $user = User::create([
                    'display_name' => $data['display_name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    // Without an explicit version the user accepted the current one.
                    'terms_version' => $data['terms_version'] ?? config('stashly.terms.version'),
                    'terms_accepted_at' => now(),
                ]);

                return [$user, $this->tokens->issue($user, $data['device_name'] ?? null)];
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent registration with the same email slipped past the unique rule.
            throw ValidationException::withMessages([
                'email' => __('validation.unique', ['attribute' => 'email']),
            ]);
        }

        return ApiResponse::data([
            'user' => UserResource::make($user)->resolve($request),
            'token' => $this->tokens->present($token),
        ], 201);
    }

    /**
     * API-AUTH-002: 200 {user, token}. 401 INVALID_CREDENTIALS, identical for
     * an unknown email and a wrong password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || $user->password === null) {
            // Spend the same hashing time as a real check so response timing does not reveal
            // whether the email is registered, or that it is a Google-only account (no password).
            Hash::make($data['password']);

            return $this->invalidCredentials();
        }

        if (! Hash::check($data['password'], $user->password)) {
            return $this->invalidCredentials();
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $data['password']])->save();
        }

        $token = $this->tokens->issue($user, $data['device_name'] ?? null);

        return ApiResponse::data([
            'user' => UserResource::make($user)->resolve($request),
            'token' => $this->tokens->present($token),
        ]);
    }

    /**
     * API-AUTH-003: revoke this token, or every token when all_devices is true. 204.
     */
    public function logout(LogoutRequest $request): Response
    {
        $user = $request->user();

        if ($request->boolean('all_devices')) {
            $user->tokens()->delete();
        } else {
            $user->currentAccessToken()->delete();
        }

        return response()->noContent();
    }

    /**
     * API-AUTH-004: swap the current token for a new 60-day token and revoke the old one.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        $token = DB::transaction(function () use ($user, $current) {
            $token = $this->tokens->issue($user, $current->name);
            $current->delete();

            return $token;
        });

        return ApiResponse::data(['token' => $this->tokens->present($token)]);
    }

    private function invalidCredentials(): JsonResponse
    {
        return ApiError::response(
            ApiError::INVALID_CREDENTIALS,
            'The email or password is incorrect.',
            401,
        );
    }
}
