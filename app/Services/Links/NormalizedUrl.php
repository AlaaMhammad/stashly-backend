<?php

namespace App\Services\Links;

final class NormalizedUrl
{
    public function __construct(
        /** The user's input, trimmed; stored as original_url. */
        public readonly string $original,
        public readonly string $normalized,
        /** Lowercased host without www./m.; stored as source_domain. */
        public readonly string $host,
        /**
         * What the metadata fetcher requests: the normalized URL but with the
         * user's own host (www./m. kept), so dropping them for duplicate
         * detection never changes which site is contacted (DEC-026).
         */
        public readonly string $fetchUrl,
    ) {}

    /**
     * Fixed-length key for the (user_id, normalized_url) uniqueness constraint;
     * a 2048-character utf8mb4 column is too long for a MySQL unique index.
     */
    public function hash(): string
    {
        return hash('sha256', $this->normalized);
    }
}
