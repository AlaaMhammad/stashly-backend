<?php

namespace App\Services\Collections;

use App\Models\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Public read-only sharing of a collection (DEC-041). The address (share_slug) is a readable prefix
 * from the collection name plus ten random characters, so it cannot be guessed; it is created the
 * first time sharing is switched on and kept afterwards, so switching sharing off and on again
 * restores the same address.
 */
class CollectionSharingService
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function setPublic(Collection $collection, bool $public): Collection
    {
        if ($public && $collection->share_slug === null) {
            for ($attempt = 0; ; $attempt++) {
                $collection->share_slug = $this->slug($collection->name);

                try {
                    $collection->is_public = true;
                    $collection->save();

                    return $collection;
                } catch (UniqueConstraintViolationException $e) {
                    // Two collections drew the same slug; draw again (three misses in a row is not a coincidence).
                    $collection->share_slug = null;
                    if ($attempt >= 2) {
                        throw $e;
                    }
                }
            }
        }

        $collection->is_public = $public;
        $collection->save();

        return $collection;
    }

    public function slug(string $name): string
    {
        $prefix = trim(Str::slug(Str::limit($name, 40, '')), '-');
        $random = '';
        for ($i = 0; $i < 10; $i++) {
            $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return ($prefix !== '' ? $prefix : 'collection').'-'.$random;
    }
}
