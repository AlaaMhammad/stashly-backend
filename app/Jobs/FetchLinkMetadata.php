<?php

namespace App\Jobs;

use App\Services\Metadata\MetadataProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Fetches page metadata for a saved link (contract 4.2). Runs on the
 * dedicated "metadata" queue so its worker can be deployed with restricted
 * network egress (security/ssrf.md): php artisan queue:work --queue=metadata
 *
 * Retries are scheduled by MetadataProcessor (temporary errors only), so the
 * queue itself never re-runs a job.
 */
class FetchLinkMetadata implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'metadata';

    public int $tries = 1;

    /** Hard stop for a hung worker; the lookup itself is limited to 8 seconds. */
    public int $timeout = 30;

    public function __construct(
        public readonly string $linkId,
        public readonly string $requestId,
        public readonly int $attempt = 1,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(MetadataProcessor $processor): void
    {
        $processor->process($this->linkId, $this->requestId, $this->attempt);
    }

    public function failed(?Throwable $exception): void
    {
        app(MetadataProcessor::class)->abandon($this->linkId, $this->requestId, $this->attempt);
    }
}
