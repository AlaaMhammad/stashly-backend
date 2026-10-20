<?php

namespace App\Services\Links;

use App\Exceptions\ApiException;
use App\Jobs\FetchLinkMetadata;
use App\Models\Collection;
use App\Models\Link;
use App\Models\User;
use App\Services\Tags\TagService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkService
{
    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly ContentTypeClassifier $classifier,
        private readonly TagService $tags,
    ) {}

    /**
     * @throws ApiException INVALID_URL
     */
    public function normalize(string $url): NormalizedUrl
    {
        try {
            return $this->normalizer->normalize($url);
        } catch (InvalidUrl) {
            throw ApiException::invalidUrl();
        }
    }

    public function classify(NormalizedUrl $url): string
    {
        return $this->classifier->fromHost($url->host);
    }

    /**
     * One of the user's links. Another user's id is indistinguishable from an
     * unknown one: both throw and render as 404 NOT_FOUND.
     *
     * @throws ModelNotFoundException
     */
    public function findOwned(User $user, string $id): Link
    {
        // ULIDs are case-insensitive; HasUlids stores them lowercase.
        return $user->links()->findOrFail(strtolower($id));
    }

    public function findDuplicate(User $user, NormalizedUrl $url): ?Link
    {
        return $user->links()->where('normalized_url_hash', $url->hash())->first();
    }

    /**
     * API-LINK-002 save flow: normalize, check the user's duplicates, validate
     * collections and tags, persist with metadata pending, and queue the
     * metadata job only after commit. Never waits for, or fails because of, metadata.
     *
     * @param  array{url: string, title?: ?string, note?: ?string, is_favorite?: bool, collection_ids?: ?array<int, string>, tags?: ?array<int, string>}  $data
     */
    public function save(User $user, array $data): SaveLinkResult
    {
        $url = $this->normalize($data['url']);

        if ($existing = $this->findDuplicate($user, $url)) {
            return SaveLinkResult::duplicate($existing);
        }

        // Validate everything before writing, so a bad id never leaves a partial save.
        $collectionIds = $this->ownedCollectionIds($user, $data['collection_ids'] ?? []);
        $tagNames = $this->tags->prepare($data['tags'] ?? []);

        try {
            $link = DB::transaction(function () use ($user, $url, $data, $collectionIds, $tagNames) {
                $link = new Link([
                    'title' => $data['title'] ?? null,
                    'note' => $data['note'] ?? null,
                    'is_favorite' => $data['is_favorite'] ?? false,
                ]);
                $link->user()->associate($user);
                $link->forceFill([
                    'original_url' => $url->original,
                    'normalized_url' => $url->normalized,
                    'normalized_url_hash' => $url->hash(),
                    'source_domain' => $url->host,
                    'content_type' => $this->classify($url),
                    'metadata_status' => Link::STATUS_PENDING,
                ])->save();

                $link->collections()->attach($collectionIds);
                $link->tags()->attach($this->tags->resolve($user, $tagNames));
                $this->touchCollections($collectionIds);

                FetchLinkMetadata::dispatch($link->id)->afterCommit();

                return $link;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent save of the same URL won the race; report it as the duplicate.
            return SaveLinkResult::duplicate($this->findDuplicate($user, $url) ?? throw new \LogicException('Duplicate link vanished.'));
        }

        return SaveLinkResult::created($link);
    }

    /**
     * API-LINK-007: full replacement; an empty list leaves the link Unsorted.
     *
     * @param  array<int, string>  $collectionIds
     */
    public function replaceCollections(Link $link, array $collectionIds): Link
    {
        $ids = $this->ownedCollectionIds($link->user, $collectionIds);

        DB::transaction(function () use ($link, $ids) {
            $changes = $link->collections()->sync($ids);
            $this->touchCollections(array_merge($changes['attached'], $changes['detached']));
            $link->touch();
        });

        return $link->unsetRelation('collections');
    }

    /**
     * API-LINK-008: full replacement by name; tags left with no links are deleted.
     *
     * @param  array<int, string>  $tags
     */
    public function replaceTags(Link $link, array $tags): Link
    {
        $names = $this->tags->prepare($tags);

        DB::transaction(function () use ($link, $names) {
            $changes = $link->tags()->sync($this->tags->resolve($link->user, $names));
            $this->tags->pruneUnused($link->user, $changes['detached']);
            $link->touch();
        });

        return $link->unsetRelation('tags');
    }

    /**
     * API-LINK-006: delete the link (its collection memberships go with it,
     * the collections stay) and remove tags no other link uses.
     */
    public function delete(Link $link): void
    {
        DB::transaction(function () use ($link) {
            $tagIds = $link->tags()->pluck('tags.id')->all();
            $link->delete();
            $this->tags->pruneUnused($link->user, $tagIds);
        });
    }

    /**
     * API-LINK-009: mark metadata pending again and queue a new lookup.
     */
    public function refreshMetadata(Link $link): Link
    {
        DB::transaction(function () use ($link) {
            $link->forceFill([
                'metadata_status' => Link::STATUS_PENDING,
                'metadata_error_code' => null,
            ])->save();

            FetchLinkMetadata::dispatch($link->id)->afterCommit();
        });

        return $link;
    }

    /**
     * Every id must be one of the user's collections ("422 if a collection is
     * not the user's"). Unknown and foreign ids get the same error.
     *
     * @param  array<int, string>  $ids
     * @return list<string> lowercased, unique
     */
    private function ownedCollectionIds(User $user, array $ids): array
    {
        $ids = array_values(array_map('strtolower', $ids));
        if ($ids === []) {
            return [];
        }

        $owned = $user->collections()->whereIn('id', $ids)->pluck('id')->all();
        $errors = [];
        foreach ($ids as $index => $id) {
            if (! in_array($id, $owned, true)) {
                $errors["collection_ids.$index"] = 'The selected collection is invalid.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Membership changes count as collection activity (used by home quick_access).
     *
     * @param  array<int, string>  $collectionIds
     */
    private function touchCollections(array $collectionIds): void
    {
        if ($collectionIds !== []) {
            Collection::query()->whereIn('id', $collectionIds)->update(['updated_at' => now()]);
        }
    }
}
