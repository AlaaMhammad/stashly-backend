<?php

namespace App\Services\Tags;

use App\Exceptions\ApiException;
use App\Models\Tag;
use App\Models\User;
use App\Support\Api\ApiError;
use Illuminate\Validation\ValidationException;

/**
 * Tags are per user and identified by normalized name (contract 4.3): trim,
 * lowercase, remove leading #, collapse spaces. Tags left with no links are deleted.
 */
class TagService
{
    public static function normalize(string $name): string
    {
        $name = ltrim(trim(mb_strtolower($name)), '#');

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Normalize and validate a submitted tag list; duplicates collapse to one.
     *
     * @param  array<int, string>  $raw
     * @return list<string> normalized, unique names
     *
     * @throws ValidationException per-entry problems
     * @throws ApiException LIMIT_EXCEEDED for more than 20 distinct tags
     */
    public function prepare(array $raw, string $field = 'tags'): array
    {
        $maxLength = config('stashly.limits.tag_max_length');
        $names = [];
        $errors = [];

        foreach (array_values($raw) as $index => $value) {
            $name = self::normalize($value);

            if ($name === '') {
                $errors["$field.$index"] = 'A tag cannot be empty.';
            } elseif (mb_strlen($name) > $maxLength) {
                $errors["$field.$index"] = "A tag may not be longer than $maxLength characters.";
            } elseif (str_contains($name, ',')) {
                // Commas separate tags in GET /links?tags=a,b (DEC-028).
                $errors["$field.$index"] = 'A tag cannot contain a comma.';
            } else {
                $names[$name] = true;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $max = config('stashly.limits.tags_per_link_max');
        if (count($names) > $max) {
            throw new ApiException(ApiError::LIMIT_EXCEEDED, 422, "A link can have at most $max tags.");
        }

        return array_keys($names);
    }

    /**
     * Ids of the user's tags with these names, creating missing ones. Safe
     * against a concurrent request creating the same tag.
     *
     * @param  list<string>  $names  normalized names
     * @return list<string>
     */
    public function resolve(User $user, array $names): array
    {
        return array_map(
            fn (string $name) => Tag::query()->createOrFirst(['user_id' => $user->getKey(), 'name' => $name])->getKey(),
            $names,
        );
    }

    /**
     * Delete the given tags if no link uses them any more.
     *
     * @param  array<int, string>  $tagIds
     */
    public function pruneUnused(User $user, array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        Tag::query()->whereBelongsTo($user)->whereIn('id', $tagIds)->whereDoesntHave('links')->delete();
    }
}
