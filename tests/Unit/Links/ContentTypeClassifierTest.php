<?php

namespace Tests\Unit\Links;

use App\Services\Links\ContentTypeClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Provisional host rules (DEC-007).
 */
class ContentTypeClassifierTest extends TestCase
{
    #[DataProvider('hosts')]
    public function test_classifies_by_host(string $host, string $type): void
    {
        $this->assertSame($type, (new ContentTypeClassifier)->fromHost($host));
    }

    public static function hosts(): array
    {
        return [
            ['youtube.com', 'video'],
            ['music.youtube.com', 'video'],
            ['youtu.be', 'video'],
            ['x.com', 'social'],
            ['instagram.com', 'social'],
            ['notx.com', 'other'],
            ['amazon.com', 'product'],
            ['amazon.co.uk', 'product'],
            ['amazon.sa', 'product'],
            ['s3.amazonaws.com', 'other'],
            ['example.com', 'other'],
        ];
    }
}
