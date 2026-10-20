<?php

namespace App\Services\Links;

use App\Models\Link;

final class SaveLinkResult
{
    private function __construct(
        public readonly Link $link,
        public readonly bool $created,
    ) {}

    public static function created(Link $link): self
    {
        return new self($link, true);
    }

    public static function duplicate(Link $existing): self
    {
        return new self($existing, false);
    }
}
