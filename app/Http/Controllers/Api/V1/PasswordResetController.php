<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * API-AUTH-005: always 200, whether or not the account exists. The broker
     * status (sent, unknown user, throttled) is deliberately not exposed.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink(['email' => $request->validated('email')]);

        return ApiResponse::data([
            'message' => 'If an account exists for this email, a password reset link has been sent.',
        ]);
    }

    /**
     * API-AUTH-006: 60-minute single-use token; revokes every token of the user.
     * An unknown email and a bad token both answer TOKEN_INVALID_OR_EXPIRED.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->safe()->only(['email', 'token', 'password']),
            function (User $user, string $password) {
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill(['password' => $password])
                        ->setRememberToken(Str::random(60));
                    $user->save();
                    $user->tokens()->delete();
                });
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiError::response(
                ApiError::TOKEN_INVALID_OR_EXPIRED,
                'This password reset link is invalid or has expired.',
                422,
            );
        }

        return ApiResponse::data(['message' => 'Your password has been reset.']);
    }
}
