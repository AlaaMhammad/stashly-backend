<?php

namespace App\Services\Metadata;

use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one metadata lookup attempt and moves the link through
 * pending -> ready | failed | blocked (contract 4.2).
 *
 * - Only the metadata_* columns and content_type are written; the user's
 *   title and note are never touched.
 * - A job whose request id no longer matches (refresh, stale clean-up, delete)
 *   does nothing, so late or duplicate jobs cannot overwrite newer state.
 * - Only temporary failures are retried, after 2 min / 15 min / 2 h, up to 4 attempts.
 * - Logs carry the link id, domain and error code only: never full URLs.
 */
class MetadataProcessor
{
    public function __construct(private readonly MetadataLookup $lookup) {}

    public function process(string $linkId, string $requestId, int $attempt): void
    {
        $link = Link::query()->find($linkId);

        if (! $this->isCurrent($link, $requestId)) {
            return;
        }

        try {
            $metadata = $this->lookup->lookup(
                $link->original_url,
                (float) config('stashly.metadata.timeout_seconds'),
                $link->content_type,
            );
        } catch (FetchFailure $failure) {
            $this->handleFailure($link, $requestId, $attempt, $failure);

            return;
        } catch (Throwable $e) {
            // Class name only: messages can contain URLs.
            Log::warning('Metadata lookup crashed.', ['link_id' => $linkId, 'exception' => $e::class]);
            $this->finish($linkId, $requestId, $attempt, Link::STATUS_FAILED, FetchFailure::INTERNAL_ERROR);

            return;
        }

        $this->apply($linkId, $requestId, function (Link $locked) use ($metadata, $attempt) {
            $locked->forceFill([
                'metadata_status' => Link::STATUS_READY,
                'metadata_title' => $metadata->title,
                'metadata_description' => $metadata->description,
                'metadata_site_name' => $metadata->siteName,
                'metadata_favicon_url' => $metadata->faviconUrl,
                'metadata_preview_image_url' => $metadata->previewImageUrl,
                'metadata_fetched_at' => now(),
                'metadata_error_code' => null,
                'metadata_pending_since' => null,
                'metadata_attempts' => $attempt,
                'content_type' => $metadata->contentType,
            ]);
        });
    }

    /**
     * Called when the queue gives up on a job (crash, worker timeout).
     */
    public function abandon(string $linkId, string $requestId, int $attempt): void
    {
        $this->finish($linkId, $requestId, $attempt, Link::STATUS_FAILED, FetchFailure::INTERNAL_ERROR);
    }

    /**
     * DEC-006: pending for more than the stale window after the request (or
     * after a scheduled retry was due) becomes failed / STALE_PENDING.
     */
    public function expireStale(): int
    {
        $cutoff = now()->subMinutes((int) config('stashly.metadata.stale_after_minutes'));

        $expired = Link::query()
            ->where('metadata_status', Link::STATUS_PENDING)
            ->where(fn ($q) => $q->where('metadata_pending_since', '<=', $cutoff)->orWhereNull('metadata_pending_since'))
            ->update([
                'metadata_status' => Link::STATUS_FAILED,
                'metadata_error_code' => FetchFailure::STALE_PENDING,
                'metadata_fetched_at' => now(),
                'metadata_pending_since' => null,
                // A job that is still running or queued for this lookup is now a no-op.
                'metadata_request_id' => null,
            ]);

        if ($expired > 0) {
            Log::warning('Expired stale pending metadata.', ['count' => $expired]);
        }

        return $expired;
    }

    private function handleFailure(Link $link, string $requestId, int $attempt, FetchFailure $failure): void
    {
        Log::info('Metadata lookup failed.', [
            'link_id' => $link->id,
            'domain' => $link->source_domain,
            'error_code' => $failure->errorCode,
            'attempt' => $attempt,
            'blocked' => $failure->blocked,
        ]);

        $delays = config('stashly.metadata.retry_delays_seconds');
        $canRetry = $failure->temporary
            && $attempt < config('stashly.metadata.max_attempts')
            && isset($delays[$attempt - 1]);

        if (! $canRetry) {
            $this->finish(
                $link->id,
                $requestId,
                $attempt,
                $failure->blocked ? Link::STATUS_BLOCKED : Link::STATUS_FAILED,
                $failure->errorCode,
            );

            return;
        }

        $delay = (int) $delays[$attempt - 1];
        $scheduled = $this->apply($link->id, $requestId, function (Link $locked) use ($attempt, $delay) {
            // The stale clock restarts at the retry time, so a waiting retry is never "stuck".
            $locked->forceFill(['metadata_pending_since' => now()->addSeconds($delay), 'metadata_attempts' => $attempt]);
        });

        if ($scheduled) {
            FetchLinkMetadata::dispatch($link->id, $requestId, $attempt + 1)->delay($delay);
        }
    }

    private function finish(string $linkId, string $requestId, int $attempt, string $status, string $errorCode): void
    {
        $this->apply($linkId, $requestId, function (Link $locked) use ($status, $errorCode, $attempt) {
            $locked->forceFill([
                'metadata_status' => $status,
                'metadata_error_code' => $errorCode,
                'metadata_fetched_at' => now(),
                'metadata_pending_since' => null,
                'metadata_attempts' => $attempt,
            ]);
        });
    }

    /**
     * Apply a change under a row lock, only while this lookup is still current.
     *
     * @param  callable(Link): void  $change
     */
    private function apply(string $linkId, string $requestId, callable $change): bool
    {
        return DB::transaction(function () use ($linkId, $requestId, $change) {
            $locked = Link::query()->whereKey($linkId)->lockForUpdate()->first();

            if (! $this->isCurrent($locked, $requestId)) {
                return false;
            }

            $change($locked);
            $locked->save();

            return true;
        });
    }

    private function isCurrent(?Link $link, string $requestId): bool
    {
        return $link !== null
            && $link->metadata_status === Link::STATUS_PENDING
            && $link->metadata_request_id === $requestId;
    }
}
