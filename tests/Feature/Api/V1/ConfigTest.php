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

    public function test_unset_release_values_are_null_not_invented(): void
    {
        config([
            'stashly.app_versions.min_supported' => null,
            'stashly.app_versions.latest' => null,
            'stashly.terms.version' => null,
            'stashly.terms.url' => null,
            'stashly.privacy.url' => null,
        ]);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.min_supported_app_version', null)
            ->assertJsonPath('data.latest_app_version', null)
            ->assertJsonPath('data.terms', ['version' => null, 'url' => null])
            ->assertJsonPath('data.privacy', ['url' => null]);
    }

    public function test_is_public_and_cacheable_for_five_minutes(): void
    {
        $cacheControl = $this->getJson('/api/v1/config')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=300', $cacheControl);
    }
}
