<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Named limiters from the contract's "Rate limits" row. Exceeding one
     * returns 429 RATE_LIMITED with Retry-After (see ApiExceptionRenderer).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(self::limit('api_per_minute'))
            ->by(self::userOrIpKey($request)));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(self::limit('login_per_minute'))
            ->by(self::emailKey($request).'|'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(self::limit('register_per_hour'))
            ->by('ip:'.$request->ip()));

        RateLimiter::for('forgot-password', fn (Request $request) => Limit::perHour(self::limit('forgot_password_per_hour'))
            ->by(self::emailKey($request)));

        RateLimiter::for('link-preview', fn (Request $request) => Limit::perMinute(self::limit('link_preview_per_minute'))
            ->by(self::userOrIpKey($request)));

        RateLimiter::for('link-save', fn (Request $request) => Limit::perMinute(self::limit('link_save_per_minute'))
            ->by(self::userOrIpKey($request)));

        RateLimiter::for('export', fn (Request $request) => Limit::perMinutes(10, self::limit('export_per_ten_minutes'))
            ->by(self::userOrIpKey($request)));
    }

    private static function limit(string $name): int
    {
        return (int) config('stashly.rate_limits.'.$name);
    }

    private static function userOrIpKey(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier();

        return $userId !== null ? 'user:'.$userId : 'ip:'.$request->ip();
    }

    private static function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return 'email:'.(is_string($email) ? Str::lower(trim($email)) : '');
    }
}
