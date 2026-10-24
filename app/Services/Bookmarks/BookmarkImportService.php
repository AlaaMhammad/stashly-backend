<?php

namespace App\Services\Bookmarks;

use App\Exceptions\ApiException;
use App\Models\Collection;
use App\Models\User;
use App\Services\Collections\CollectionService;
use App\Services\Links\LinkService;
use App\Services\Tags\TagService;
use Illuminate\Validation\ValidationException;

/**
 * POST /links/import (DEC-041): saves one slice of a bookmark file synchronously, at most
 * stashly.import.max_links_per_request links per call, through the normal save flow (URL
 * normalization, per-user duplicate check, tag rules, metadata queued as for any saved link).
 * The caller continues with next_offset until it is null. Re-sending the same file is harmless:
 * links that are already saved are skipped.
 */
class BookmarkImportService
{
    private const DEFAULT_FOLDER = 'Imported';

    public function __construct(
        private readonly BookmarkParser $parser,
        private readonly LinkService $links,
        private readonly CollectionService $collections,
        private readonly TagService $tags,
    ) {}

    /**
     * @return array{imported_count: int, skipped_duplicates_count: int, skipped_invalid_count: int, collections_created: int, total_in_file: int, next_offset: ?int}
     */
    public function import(User $user, string $content, string $format, int $offset): array
    {
        $entries = $this->parser->parse($content, $format);
        $total = count($entries);
        $batch = (int) config('stashly.import.max_links_per_request');
        $slice = array_slice($entries, $offset, $batch);

        $imported = $duplicates = $invalid = $created = 0;
        /** @var array<string, ?string> $folderIds lowercase name => collection id (null: could not be created) */
        $folderIds = [];

        foreach ($slice as $entry) {
            if (! preg_match('#^https?://#i', $entry['url'])) {
                $invalid++;

                continue;
            }

            try {
                $normalized = $this->links->normalize($entry['url']);
            } catch (ApiException) {
                $invalid++;

                continue;
            }

            if ($this->links->findDuplicate($user, $normalized) !== null) {
                $duplicates++;

                continue;
            }

            $folder = $entry['folder'] !== '' ? $entry['folder'] : self::DEFAULT_FOLDER;
            $collectionId = $this->collectionFor($user, $folder, $folderIds, $created);

            try {
                $result = $this->links->save($user, [
                    'url' => $entry['url'],
                    'title' => $entry['title'] !== '' ? mb_substr($entry['title'], 0, (int) config('stashly.limits.title_max_length')) : null,
                    'note' => $entry['note'] !== '' ? mb_substr($entry['note'], 0, (int) config('stashly.limits.note_max_length')) : null,
                    'collection_ids' => $collectionId === null ? [] : [$collectionId],
                    'tags' => $this->cleanTags($entry['tags']),
                ]);
            } catch (ApiException|ValidationException) {
                $invalid++;

                continue;
            }

            $result->created ? $imported++ : $duplicates++;
        }

        $next = $offset + count($slice);

        return [
            'imported_count' => $imported,
            'skipped_duplicates_count' => $duplicates,
            'skipped_invalid_count' => $invalid,
            'collections_created' => $created,
            'total_in_file' => $total,
            'next_offset' => $next < $total ? $next : null,
        ];
    }

    /**
     * The user's collection with this name (any case), created on first use. Null when the
     * 100-collection limit stops a new one: the link is then saved without a collection.
     *
     * @param  array<string, ?string>  $cache
     */
    private function collectionFor(User $user, string $name, array &$cache, int &$created): ?string
    {
        $name = mb_substr($name, 0, (int) config('stashly.limits.collection_name_max_length'));
        $key = Collection::keyFor($name);

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $existing = $user->collections()->where('name_key', $key)->first();
        if ($existing !== null) {
            return $cache[$key] = $existing->id;
        }

        try {
            $collection = $this->collections->create($user, ['name' => $name]);
            $created++;

            return $cache[$key] = $collection->id;
        } catch (ApiException|ValidationException) {
            return $cache[$key] = null;
        }
    }

    /**
     * @param  list<string>  $raw
     * @return list<string>
     */
    private function cleanTags(array $raw): array
    {
        $max = (int) config('stashly.limits.tag_max_length');
        $names = [];

        foreach ($raw as $tag) {
            $name = TagService::normalize($tag);
            if ($name !== '' && mb_strlen($name) <= $max && ! str_contains($name, ',')) {
                $names[$name] = true;
            }
        }

        return array_slice(array_keys($names), 0, (int) config('stashly.limits.tags_per_link_max'));
    }
}
