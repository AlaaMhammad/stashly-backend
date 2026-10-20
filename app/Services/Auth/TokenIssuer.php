<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Api\ApiDate;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues Sanctum bearer tokens with the contract's 60-day expiry and formats
 * the shared "token" object: {access_token, token_type, expires_at}.
 */
class TokenIssuer
{
    public const DEFAULT_DEVICE_NAME = 'unnamed device';

    public function issue(User $user, ?string $deviceName): NewAccessToken
    {
        return $user->createToken(
            $deviceName ?: self::DEFAULT_DEVICE_NAME,
            ['*'],
            now()->addDays(config('stashly.token_ttl_days')),
        );
    }

    /**
     * @return array{access_token: string, token_type: string, expires_at: string}
     */
    public function present(NewAccessToken $token): array
    {
        return [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => ApiDate::format($token->accessToken->expires_at),
        ];
    }
}
