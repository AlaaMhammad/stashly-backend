<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deployment smoke check of the security-relevant configuration
 * (operations/deployment-and-operations.md). FAIL = must fix, WARN = review.
 * It never prints secret values.
 */
class Preflight extends Command
{
    protected $signature = 'stashly:preflight';

    protected $description = 'Check production configuration: debug, HTTPS, secrets, database, queue, mail, CA bundle';

    public function handle(): int
    {
        $checks = [
            $this->check('APP_ENV is production', config('app.env') === 'production'),
            $this->check('APP_DEBUG is false', config('app.debug') === false),
            $this->check('APP_URL uses https', str_starts_with((string) config('app.url'), 'https://')),
            $this->check('APP_KEY is set', filled(config('app.key'))),
            $this->check('Database is MySQL/MariaDB', in_array(config('database.default'), ['mysql', 'mariadb'], true)),
            $this->check('Database user is set', filled(config('database.connections.'.config('database.default').'.username'))),
            $this->check('Database is reachable', $this->databaseReachable()),
            $this->check('Queue is asynchronous (not sync)', config('queue.default') !== 'sync'),
            $this->check('Cache is shared (not array)', config('cache.default') !== 'array'),
            $this->check('Mail sends (not log/array)', ! in_array(config('mail.default'), ['log', 'array'], true)),
            $this->check('Mail from address is not a placeholder (MAIL_FROM_ADDRESS)', filled(config('mail.from.address')) && ! preg_match('/@(example\.(com|test|org)|yourdomain\.com)$/i', (string) config('mail.from.address')), warn: true),
            // DEC-021: without these, /config points at the DRAFT pages served by this backend.
            $this->check('Terms/Privacy URLs and a final (non-draft) terms version are configured', filled(config('stashly.terms.url')) && filled(config('stashly.privacy.url')) && filled(config('stashly.terms.version')) && config('stashly.terms.version') !== 'draft-1', warn: true),
            // DEC-035: without it POST /auth/google answers 503 and GET /config reports auth.google_enabled=false.
            $this->check('Google sign-in client id is configured (GOOGLE_CLIENT_ID)', filled(config('services.google.client_id')), warn: true),
            // DEC-038: without it the AI endpoints answer 503 AI_SERVICE_UNAVAILABLE and GET /config reports ai.enabled=false.
            $this->check('AI assistant key is configured (GEMINI_API_KEY)', filled(config('services.gemini.api_key')), warn: true),
            $this->check('App version limits are set', filled(config('stashly.app_versions.min_supported')) && filled(config('stashly.app_versions.latest')), warn: true),
            $this->check('Database user is not root', config('database.connections.'.config('database.default').'.username') !== 'root', warn: true),
            $this->check('LOG_LEVEL is not debug', config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug', warn: true),
            $this->check('CA bundle for metadata fetching exists', $this->caBundleExists(), warn: true),
            $this->check('TRUSTED_PROXIES set (needed behind a load balancer for per-IP limits)', filled(env('TRUSTED_PROXIES')), warn: true),
        ];

        $this->table(['Result', 'Check'], array_map(fn ($c) => [$c['result'], $c['name']], $checks));

        $failed = count(array_filter($checks, fn ($c) => $c['result'] === 'FAIL'));
        $warned = count(array_filter($checks, fn ($c) => $c['result'] === 'WARN'));
        $this->line("$failed failure(s), $warned warning(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{result: string, name: string}
     */
    private function check(string $name, bool $ok, bool $warn = false): array
    {
        return ['result' => $ok ? 'OK' : ($warn ? 'WARN' : 'FAIL'), 'name' => $name];
    }

    private function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function caBundleExists(): bool
    {
        foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return true;
            }
        }

        return false;
    }
}
