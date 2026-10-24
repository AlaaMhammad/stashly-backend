<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesAsUtc;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

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
        'sort_title',
        'metadata_request_id',
        'metadata_pending_since',
        'metadata_attempts',
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
                $link->ai_summary,
            ]);
            $link->sort_title = mb_substr(mb_strtolower((string) ($link->title ?? $link->metadata_title ?? $link->source_domain)), 0, 255);
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
            'metadata_pending_since' => 'datetime',
            'ai_tags' => 'array',
            'ai_generated_at' => 'datetime',
            'is_broken' => 'boolean',
            'last_checked_at' => 'datetime',
            'ai_insights' => 'array',
            'ai_action_plan' => 'array',
        ];
    }

    /**
     * Start a new metadata lookup: status pending, a fresh request id (which
     * makes any job from an earlier lookup a no-op) and a new pending clock.
     * Previously fetched metadata stays visible until the new lookup finishes.
     */
    public function markMetadataPending(): void
    {
        $this->forceFill([
            'metadata_status' => self::STATUS_PENDING,
            'metadata_error_code' => null,
            'metadata_request_id' => strtolower((string) Str::ulid()),
            'metadata_pending_since' => now(),
            'metadata_attempts' => 0,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Collection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class)->withTimestamps()->orderBy('collections.name_key');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('tags.name');
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
