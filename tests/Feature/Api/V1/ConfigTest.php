<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

/**
 * API-SYS-002 GET /config.
 */
class ConfigTest extends TestCase
{
    public function test_returns_configured_values_in_contract_shape(): void
    {
        config([
            'stashly.app_versions.min_supported' => '1.0.0',
            'stashly.app_versions.latest' => '1.2.0',
            'stashly.terms.version' => '2026-10-01',
            'stashly.terms.url' => 'https://example.test/terms',
            'stashly.privacy.url' => 'https://example.test/privacy',
        ]);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertExactJson(['data' => [
                'api_version' => '1.0',
                'min_supported_app_version' => '1.0.0',
                'latest_app_version' => '1.2.0',
                'terms' => ['version' => '2026-10-01', 'url' => 'https://example.test/terms'],
                'privacy' => ['url' => 'https://example.test/privacy'],
                'auth' => ['google_enabled' => false],
                'limits' => [
                    'url_max_length' => 2048,
                    'title_max_length' => 300,
                    'note_max_length' => 2000,
                    'collection_name_max_length' => 60,
                    'collection_description_max_length' => 300,
                    'tag_max_length' => 40,
                    'tags_per_link_max' => 20,
                    'collections_per_user_max' => 100,
                    'page_size_max' => 50,
                ],
            ]]);
    }

    public function test_reports_whether_google_sign_in_is_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->getJson('/api/v1/config')->assertJsonPath('data.auth', ['google_enabled' => false]);

        config(['services.google.client_id' => '']);
        $this->getJson('/api/v1/config')->assertJsonPath('data.auth.google_enabled', false);

        config(['services.google.client_id' => '1234-abc.apps.googleusercontent.com']);
        $response = $this->getJson('/api/v1/config')->assertJsonPath('data.auth', ['google_enabled' => true]);
        $this->assertStringNotContainsString('1234-abc', $response->getContent(), 'The client id itself is not exposed.');
    }

    public function test_unset_app_versions_are_null_not_invented(): void
    {
        config(['stashly.app_versions.min_supported' => null, 'stashly.app_versions.latest' => null]);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.min_supported_app_version', null)
            ->assertJsonPath('data.latest_app_version', null);
    }

    public function test_unset_legal_urls_fall_back_to_the_draft_pages_served_by_this_backend(): void
    {
        config(['stashly.terms.url' => null, 'stashly.privacy.url' => null, 'stashly.terms.version' => 'draft-1']);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.terms', ['version' => 'draft-1', 'url' => url('/terms')])
            ->assertJsonPath('data.privacy', ['url' => url('/privacy')]);
    }

    public function test_empty_legal_urls_count_as_unset(): void
    {
        // .env.example ships these variables empty.
        config(['stashly.terms.url' => '', 'stashly.privacy.url' => '']);

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.terms.url', url('/terms'))
            ->assertJsonPath('data.privacy.url', url('/privacy'));
    }

    public function test_environment_values_override_the_draft_defaults(): void
    {
        config([
            'stashly.terms.version' => '2027-01-01',
            'stashly.terms.url' => 'https://legal.example.test/tos',
            'stashly.privacy.url' => 'https://legal.example.test/privacy',
        ]);

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.terms', ['version' => '2027-01-01', 'url' => 'https://legal.example.test/tos'])
            ->assertJsonPath('data.privacy', ['url' => 'https://legal.example.test/privacy']);
    }

    public function test_the_config_file_defaults_the_terms_version_to_draft_1_but_honours_the_environment(): void
    {
        $original = $_SERVER['STASHLY_TERMS_VERSION'] ?? $_ENV['STASHLY_TERMS_VERSION'] ?? getenv('STASHLY_TERMS_VERSION');
        $load = function (?string $value): array {
            if ($value === null) {
                unset($_SERVER['STASHLY_TERMS_VERSION'], $_ENV['STASHLY_TERMS_VERSION']);
                putenv('STASHLY_TERMS_VERSION');
            } else {
                $_SERVER['STASHLY_TERMS_VERSION'] = $_ENV['STASHLY_TERMS_VERSION'] = $value;
                putenv("STASHLY_TERMS_VERSION=$value");
            }

            return require config_path('stashly.php');
        };

        try {
            $this->assertSame('draft-1', $load(null)['terms']['version'], 'unset');
            $this->assertSame('draft-1', $load('')['terms']['version'], 'empty, as shipped in .env.example');
            $this->assertSame('2027-01-01', $load('2027-01-01')['terms']['version'], 'explicit override');
        } finally {
            $load($original === false ? null : $original);
        }
    }

    public function test_is_public_and_cacheable_for_five_minutes(): void
    {
        $cacheControl = $this->getJson('/api/v1/config')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=300', $cacheControl);
    }
}
