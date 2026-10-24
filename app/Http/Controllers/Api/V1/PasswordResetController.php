<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\VerifyResetCodeRequest;
use App\Models\User;
use App\Services\Auth\PasswordResetCodeService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * API-AUTH-005: always 200, whether or not the account exists (or a code was sent a moment ago).
     * A known account gets a 6-digit code by e-mail, sent right after the response (DEC-040).
     */
    public function forgot(ForgotPasswordRequest $request, PasswordResetCodeService $codes): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();
        $code = $user === null ? null : $codes->issue($user);

        if ($user !== null && $code !== null) {
            $codes->send($user, $code);
        }

        return ApiResponse::data([
            'message' => 'If an account exists for this email, a password reset code has been sent.',
        ]);
    }

    /**
     * API-AUTH-009 (DEC-040): lets the app check the code before it asks for the new password.
     * 200 {valid: true} or 422 TOKEN_INVALID_OR_EXPIRED; the code is not used up. Wrong guesses
     * count towards the 5 allowed per code.
     */
    public function verify(VerifyResetCodeRequest $request, PasswordResetCodeService $codes): JsonResponse
    {
        if (! $codes->check($request->validated('email'), $request->validated('code'))) {
            return $this->invalidCode();
        }

        return ApiResponse::data(['valid' => true]);
    }

    /**
     * API-AUTH-006: 15-minute single-use 6-digit code; revokes every token of the user.
     * An unknown email, a wrong code and an expired code all answer TOKEN_INVALID_OR_EXPIRED.
     */
    public function reset(ResetPasswordRequest $request, PasswordResetCodeService $codes): JsonResponse
    {
        $email = $request->validated('email');

        if (! $codes->check($email, $request->validated('code'), consume: true)) {
            return $this->invalidCode();
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return $this->invalidCode();
        }

        DB::transaction(function () use ($user, $request) {
            $user->forceFill(['password' => $request->validated('password')])
                ->setRememberToken(Str::random(60));
            $user->save();
            $user->tokens()->delete();
        });

        return ApiResponse::data(['message' => 'Your password has been reset.']);
    }

    private function invalidCode(): JsonResponse
    {
        return ApiError::response(ApiError::TOKEN_INVALID_OR_EXPIRED, 'This code is invalid or has expired.', 422);
    }
}
