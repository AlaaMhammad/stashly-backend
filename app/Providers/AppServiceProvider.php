<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Links\UrlNormalizer;
use App\Services\Metadata\CurlTransport;
use App\Services\Metadata\DnsResolver;
use App\Services\Metadata\HttpTransport;
use App\Services\Metadata\MetadataUrlGuard;
use App\Services\Metadata\SafeFetcher;
use App\Services\Metadata\SystemDnsResolver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UrlNormalizer::class, fn () => new UrlNormalizer(
            config('stashly.limits.url_max_length'),
        ));

        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(HttpTransport::class, fn () => new CurlTransport(config('stashly.metadata.user_agent')));
        $this->app->bind(SafeFetcher::class, fn ($app) => new SafeFetcher(
            $app->make(MetadataUrlGuard::class),
            $app->make(HttpTransport::class),
            config('stashly.metadata.max_bytes'),
            config('stashly.metadata.max_redirects'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Reset emails link to the web page that calls POST /api/v1/auth/reset-password.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => url('/reset-password').'?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Observability: failed jobs and queue backlog become log entries (no payloads, no URLs).
        Event::listen(JobFailed::class, fn (JobFailed $e) => Log::error('Queue job failed.', [
            'job' => $e->job->resolveName(),
            'queue' => $e->job->getQueue(),
            'exception' => $e->exception::class,
        ]));
        Event::listen(QueueBusy::class, fn (QueueBusy $e) => Log::warning('Queue backlog.', [
            'queue' => $e->queue,
            'size' => $e->size,
        ]));

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

        RateLimiter::for('google-auth', fn (Request $request) => Limit::perMinute(self::limit('google_auth_per_minute'))
            ->by('ip:'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(self::limit('register_per_hour'))
            ->by('ip:'.$request->ip()));

        RateLimiter::for('forgot-password', fn (Request $request) => Limit::perHour(self::limit('forgot_password_per_hour'))
            ->by(self::emailKey($request)));

        RateLimiter::for('link-preview', fn (Request $request) => Limit::perMinute(self::limit('link_preview_per_minute'))
            ->by(self::userOrIpKey($request)));

        RateLimiter::for('link-save', fn (Request $request) => Limit::perMinute(self::limit('link_save_per_minute'))
            ->by(self::userOrIpKey($request)));

        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(self::limit('ai_per_minute'))
            ->by(self::userOrIpKey($request)));

        RateLimiter::for('export', fn (Request $request) => Limit::perMinutes(10, self::limit('export_per_ten_minutes'))
            ->by(self::userOrIpKey($request)));

        RateLimiter::for('metadata-refresh', fn (Request $request) => [
            Limit::perMinute(self::limit('metadata_refresh_per_link_per_minute'))
                ->by(self::userOrIpKey($request).'|link:'.strtolower((string) $request->route('link'))),
            Limit::perMinute(self::limit('metadata_refresh_per_minute'))
                ->by(self::userOrIpKey($request)),
        ]);
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
