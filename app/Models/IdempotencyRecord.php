<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Stored first response for a (user, Idempotency-Key) pair on POST /links.
 */
class IdempotencyRecord extends Model
{
    use HasUlids, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'key',
        'request_fingerprint',
        'response_status',
        'response_body',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Expired records are removed by the scheduled model:prune command.
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
