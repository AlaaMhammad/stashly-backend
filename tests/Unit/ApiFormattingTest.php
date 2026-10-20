<?php

namespace Tests\Unit;

use App\Support\Api\ApiDate;
use App\Support\Api\ApiExceptionRenderer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiFormattingTest extends TestCase
{
    public function test_dates_are_converted_to_utc_zulu_format(): void
    {
        $local = CarbonImmutable::parse('2026-10-16T12:30:12.987+03:00');

        $this->assertSame('2026-10-16T09:30:12Z', ApiDate::format($local));
    }

    #[DataProvider('statusCodes')]
    public function test_http_status_maps_to_contract_error_code(int $status, string $code): void
    {
        $this->assertSame($code, ApiExceptionRenderer::codeForStatus($status));
    }

    public static function statusCodes(): array
    {
        return [
            [400, 'VALIDATION_FAILED'],
            [401, 'UNAUTHENTICATED'],
            [403, 'FORBIDDEN'],
            [404, 'NOT_FOUND'],
            [405, 'NOT_FOUND'],
            [413, 'VALIDATION_FAILED'],
            [429, 'RATE_LIMITED'],
            [500, 'SERVER_ERROR'],
            [502, 'SERVER_ERROR'],
            [503, 'SERVICE_UNAVAILABLE'],
        ];
    }
}
