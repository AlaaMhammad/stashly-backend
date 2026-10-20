<?php

namespace App\Services\Links;

use App\Exceptions\ApiException;
use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class LinkService
{
    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly ContentTypeClassifier $classifier,
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

    public function findDuplicate(User $user, NormalizedUrl $url): ?Link
    {
        return $user->links()->where('normalized_url_hash', $url->hash())->first();
    }

    /**
     * API-LINK-002 save flow: normalize, check the user's duplicates, persist
     * with metadata pending, and queue the metadata job only after commit.
     * Never waits for, or fails because of, metadata.
     *
     * @param  array{url: string, title?: ?string, note?: ?string, is_favorite?: bool}  $data
     */
    public function save(User $user, array $data): SaveLinkResult
    {
        $url = $this->normalize($data['url']);

        if ($existing = $this->findDuplicate($user, $url)) {
            return SaveLinkResult::duplicate($existing);
        }

        try {
            $link = DB::transaction(function () use ($user, $url, $data) {
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

                FetchLinkMetadata::dispatch($link->id)->afterCommit();

                return $link;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent save of the same URL won the race; report it as the duplicate.
            return SaveLinkResult::duplicate($this->findDuplicate($user, $url) ?? throw new \LogicException('Duplicate link vanished.'));
        }

        return SaveLinkResult::created($link);
    }
}
