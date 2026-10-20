<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesAsUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    use HasUlids, SerializesDatesAsUtc;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'user_id',
        'name_key',
    ];

    /**
     * name_key always follows name, so uniqueness ignores case everywhere.
     */
    protected static function booted(): void
    {
        static::saving(function (Collection $collection) {
            $collection->name_key = self::keyFor($collection->name);
        });
    }

    public static function keyFor(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Link, $this>
     */
    public function links(): BelongsToMany
    {
        return $this->belongsToMany(Link::class)->withTimestamps();
    }
}
