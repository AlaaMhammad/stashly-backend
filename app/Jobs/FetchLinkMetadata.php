<?php

namespace App\Jobs;

use App\Models\Link;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches page metadata for a saved link (contract 4.2).
 *
 * PHASE 6 PLACEHOLDER: dispatched after a link is saved, but it does not
 * fetch anything yet. Remote fetching must not be added without the SSRF
 * controls in Stashly_Backend_Docs/security/ssrf.md. Until then links stay
 * in metadata status "pending".
 */
class FetchLinkMetadata implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $linkId) {}

    public function handle(): void
    {
        // A link deleted while the job was queued is simply skipped.
        if (! Link::query()->whereKey($this->linkId)->exists()) {
            return;
        }

        // Fetching is implemented in Phase 6.
    }
}
