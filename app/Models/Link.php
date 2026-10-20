<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesAsUtc;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Link extends Model
{
    use HasUlids, SerializesDatesAsUtc;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Ownership (user_id) and URL fields are set explicitly by LinkService,
     * never mass-assigned from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'note',
        'is_favorite',
    ];

    /**
     * Internal columns; LinkResource is the only serialization path anyway.
     *
     * @var list<string>
     */
    protected $hidden = [
        'user_id',
        'normalized_url_hash',
        'search_text',
    ];

    /**
     * Keep the q-search column in step with every saved change, including
     * metadata written by the queue job. Bulk query-builder updates bypass this.
     */
    protected static function booted(): void
    {
        static::saving(function (Link $link) {
            $link->search_text = SearchText::index([
                $link->title,
                $link->metadata_title,
                $link->metadata_description,
                $link->note,
                $link->source_domain,
            ]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_favorite' => 'boolean',
            'metadata_fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Title shown in lists: the user's title, else the fetched title, else
     * the domain. Provisional fallback (DEC-025).
     */
    public function displayTitle(): string
    {
        return $this->title ?? $this->metadata_title ?? $this->source_domain;
    }
}
