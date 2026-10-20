<?php

namespace App\Services\Collections;

use App\Exceptions\ApiException;
use App\Models\Collection;
use App\Models\User;
use App\Support\Api\ApiError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionService
{
    /**
     * @throws ModelNotFoundException unknown or another user's collection (404)
     */
    public function findOwned(User $user, string $id): Collection
    {
        return $user->collections()->findOrFail(strtolower($id));
    }

    /**
     * API-COLL-002: name unique per user ignoring case; at most 100 per user.
     *
     * @param  array{name: string, description?: ?string}  $data
     */
    public function create(User $user, array $data): Collection
    {
        return DB::transaction(function () use ($user, $data) {
            // Serialize creates per user so the 100-collection limit holds under
            // concurrent requests, not just as a count check.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $max = config('stashly.limits.collections_per_user_max');
            if ($user->collections()->count() >= $max) {
                throw new ApiException(ApiError::LIMIT_EXCEEDED, 422, "You can have at most $max collections.");
            }

            $this->assertNameAvailable($user, $data['name']);

            $collection = new Collection([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $collection->user()->associate($user);

            return $this->saveOrDuplicate($collection);
        });
    }

    /**
     * API-COLL-004: rename and/or re-describe.
     *
     * @param  array{name?: string, description?: ?string}  $data
     */
    public function update(Collection $collection, array $data): Collection
    {
        if (array_key_exists('name', $data)) {
            $this->assertNameAvailable($collection->user, $data['name'], $collection);
        }

        return $this->saveOrDuplicate($collection->fill($data));
    }

    /**
     * API-COLL-006: add and/or remove links in one atomic step.
     *
     * @param  list<string>  $attach
     * @param  list<string>  $detach
     */
    public function updateLinks(Collection $collection, array $attach, array $detach): Collection
    {
        $attach = array_values(array_unique(array_map('strtolower', $attach)));
        $detach = array_values(array_unique(array_map('strtolower', $detach)));

        if (array_intersect($attach, $detach) !== []) {
            throw ValidationException::withMessages(['attach' => 'A link cannot be both attached and detached.']);
        }

        $this->assertOwnedLinks($collection->user, ['attach' => $attach, 'detach' => $detach]);

        DB::transaction(function () use ($collection, $attach, $detach) {
            $collection->links()->syncWithoutDetaching($attach);
            $collection->links()->detach($detach);
            $collection->touch();
        });

        return $collection;
    }

    /**
     * Every id must be one of the user's links. Unknown and foreign ids get the
     * same error, so the response reveals nothing about other users' data.
     *
     * @param  array<string, list<string>>  $idsByField
     */
    private function assertOwnedLinks(User $user, array $idsByField): void
    {
        $all = array_merge(...array_values($idsByField));
        $owned = $all === [] ? [] : $user->links()->whereIn('id', $all)->pluck('id')->all();

        $errors = [];
        foreach ($idsByField as $field => $ids) {
            foreach ($ids as $index => $id) {
                if (! in_array($id, $owned, true)) {
                    $errors["$field.$index"] = 'The selected link is invalid.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertNameAvailable(User $user, string $name, ?Collection $except = null): void
    {
        $taken = $user->collections()
            ->where('name_key', Collection::keyFor($name))
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw self::duplicateName();
        }
    }

    private function saveOrDuplicate(Collection $collection): Collection
    {
        try {
            $collection->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request took the name between the check and the insert.
            throw self::duplicateName();
        }

        return $collection;
    }

    private static function duplicateName(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'A collection with this name already exists.']);
    }
}
