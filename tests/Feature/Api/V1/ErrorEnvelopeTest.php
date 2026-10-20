<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_test')->group(function () {
            Route::post('/validate', fn (Request $request) => $request->validate([
                'email' => ['required', 'email'],
                'name' => ['required', 'string'],
            ]));
            Route::get('/protected', fn () => ['ok' => true])->middleware('auth:sanctum');
            Route::get('/missing-model', fn () => User::findOrFail('01JAAAAAAAAAAAAAAAAAAAAAAA'));
            Route::get('/forbidden', fn () => abort(403));
            Route::get('/maintenance', fn () => abort(503));
            Route::get('/crash', fn () => throw new RuntimeException('internal secret detail'));
        });
    }

    public function test_unknown_api_route_returns_not_found_envelope(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $this->assertApiError($response, 404, 'NOT_FOUND');
        $this->assertStringContainsString('"details":{}', $response->getContent());
    }

    public function test_api_errors_are_json_even_without_accept_header(): void
    {
        $this->assertApiError($this->get('/api/v1/does-not-exist'), 404, 'NOT_FOUND');
    }

    public function test_wrong_method_returns_not_found_code_with_405_status(): void
    {
        $this->assertApiError($this->postJson('/api/v1/health'), 405, 'NOT_FOUND');
    }

    public function test_validation_failure_maps_fields_to_messages(): void
    {
        $response = $this->postJson('/api/v1/_test/validate', ['email' => 'not-an-email']);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['email' => [0], 'name' => [0]]]]);
    }

    public function test_missing_token_returns_unauthenticated_without_login_redirect(): void
    {
        // No Accept header: must not try to redirect to a "login" route.
        $this->assertApiError($this->get('/api/v1/_test/protected'), 401, 'UNAUTHENTICATED');
    }

    public function test_invalid_token_returns_unauthenticated(): void
    {
        $response = $this->withToken('1|not-a-real-token')->getJson('/api/v1/_test/protected');

        $this->assertApiError($response, 401, 'UNAUTHENTICATED');
    }

    public function test_missing_model_returns_not_found_without_leaking_model_class(): void
    {
        $response = $this->getJson('/api/v1/_test/missing-model');

        $this->assertApiError($response, 404, 'NOT_FOUND')
            ->assertJsonPath('error.message', 'Resource not found.');
        $this->assertStringNotContainsString('App\\\\Models', $response->getContent());
        $this->assertStringNotContainsString('User', $response->getContent());
    }

    public function test_forbidden_maps_to_forbidden_code(): void
    {
        $this->assertApiError($this->getJson('/api/v1/_test/forbidden'), 403, 'FORBIDDEN');
    }

    public function test_service_unavailable_maps_to_service_unavailable_code(): void
    {
        $this->assertApiError($this->getJson('/api/v1/_test/maintenance'), 503, 'SERVICE_UNAVAILABLE');
    }

    public function test_unexpected_exception_returns_generic_server_error(): void
    {
        $response = $this->getJson('/api/v1/_test/crash');

        $this->assertApiError($response, 500, 'SERVER_ERROR')
            ->assertJsonPath('error.message', 'Something went wrong. Please try again later.');
        $this->assertStringNotContainsString('internal secret detail', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
    }

    public function test_each_request_gets_a_distinct_request_id_shared_with_log_context(): void
    {
        $first = $this->getJson('/api/v1/does-not-exist')->json('error.request_id');
        $this->assertSame($first, Context::get('request_id'));

        $second = $this->getJson('/api/v1/does-not-exist')->json('error.request_id');
        $this->assertSame($second, Context::get('request_id'));

        $this->assertNotSame($first, $second);
    }

    public function test_client_supplied_request_id_is_ignored(): void
    {
        $response = $this->withHeader('X-Request-Id', 'forged-id')->getJson('/api/v1/does-not-exist');

        $this->assertNotSame('forged-id', $response->json('error.request_id'));
    }

    public function test_web_routes_are_not_rendered_as_api_errors(): void
    {
        $response = $this->get('/does-not-exist');

        $response->assertNotFound();
        $this->assertStringNotContainsString('request_id', $response->getContent());
    }
}
