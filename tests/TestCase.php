<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Assert the contract error envelope: { "error": { "code", "message", "details", "request_id" } }.
     */
    protected function assertApiError(TestResponse $response, int $status, string $code): TestResponse
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']])
            ->assertJsonPath('error.code', $code);

        $this->assertCount(1, $response->json(), 'Error body must contain only the "error" key.');
        $this->assertTrue(Str::isUlid($response->json('error.request_id')), 'request_id must be a ULID.');
        $this->assertIsObject($response->getData()->error->details, 'details must be a JSON object.');

        return $response;
    }
}
