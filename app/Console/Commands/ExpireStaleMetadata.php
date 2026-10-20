<?php

namespace App\Console\Commands;

use App\Services\Metadata\MetadataProcessor;
use Illuminate\Console\Command;

class ExpireStaleMetadata extends Command
{
    protected $signature = 'links:expire-stale-metadata';

    protected $description = 'Mark links stuck in pending metadata for over 15 minutes as failed (STALE_PENDING)';

    public function handle(MetadataProcessor $processor): int
    {
        $this->info('Expired '.$processor->expireStale().' stale pending metadata lookup(s).');

        return self::SUCCESS;
    }
}
