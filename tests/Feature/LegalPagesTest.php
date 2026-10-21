<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DEC-021: draft Terms of Service and Privacy Policy pages (version draft-1).
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const BANNER = 'Draft — not final legal text (Version: draft-1)';

    #[DataProvider('pages')]
    public function test_page_is_public_html_with_the_draft_banner(string $path, string $heading): void
    {
        $response = $this->get($path);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee(self::BANNER)
            ->assertSee($heading)
            ->assertSee('Version draft-1')
            ->assertSee('Not legal advice')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
    }

    public static function pages(): array
    {
        return [
            'terms' => ['/terms', 'Stashly Terms of Service'],
            'privacy' => ['/privacy', 'Stashly Privacy Policy'],
        ];
    }

    public function test_the_banner_comes_before_the_document(): void
    {
        foreach (['/terms', '/privacy'] as $path) {
            $html = $this->get($path)->getContent();

            $this->assertLessThan(strpos($html, '<h1>'), strpos($html, self::BANNER), $path);
        }
    }

    public function test_terms_cover_link_saving_page_fetching_and_account_deletion(): void
    {
        $this->get('/terms')
            ->assertSee('save links')
            ->assertSee('our servers visit the public web address')
            ->assertSee('we do not download or store images or videos')
            ->assertSee('private or internal networks are blocked')
            ->assertSee('Deletion permanently removes your account, links, collections, tags and sign-in tokens');
    }

    public function test_privacy_covers_collected_data_fetching_export_and_deletion(): void
    {
        $this->get('/privacy')
            ->assertSee('hashed password')
            ->assertSee('Page details we fetch')
            ->assertSee('Our application logs do not contain passwords, tokens or full web addresses')
            ->assertSee('download all your data from the app')
            ->assertSee('permanently removes your account, links, collections, tags and tokens')
            ->assertSee('sign-in tokens expire after 60 days');
    }

    public function test_unfilled_placeholders_are_visible_so_the_drafts_cannot_pass_for_final_copy(): void
    {
        foreach (['/terms', '/privacy'] as $path) {
            $this->assertMatchesRegularExpression('/\[(DATE|CONTACT E-MAIL|COMPANY \/ OWNER NAME|E-MAIL)\]/', $this->get($path)->getContent(), $path);
        }
    }

    public function test_pages_link_to_each_other(): void
    {
        $this->get('/terms')->assertSee(route('privacy'), false)->assertSee(route('terms'), false);
        $this->get('/privacy')->assertSee(route('terms'), false);
    }

    public function test_the_config_urls_point_at_these_routes_and_resolve(): void
    {
        config(['stashly.terms.url' => null, 'stashly.privacy.url' => null]);
        $config = $this->getJson('/api/v1/config')->assertOk()->json('data');

        $this->assertSame(route('terms'), $config['terms']['url']);
        $this->assertSame(route('privacy'), $config['privacy']['url']);
        $this->get($config['terms']['url'])->assertOk();
        $this->get($config['privacy']['url'])->assertOk();
    }

    public function test_registration_without_a_version_stores_the_draft_version(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'display_name' => 'Lina', 'email' => 'lina@example.test', 'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse', 'terms_accepted' => true,
        ])->assertCreated();

        $this->assertSame(config('stashly.terms.version'), User::sole()->terms_version);
    }
}
