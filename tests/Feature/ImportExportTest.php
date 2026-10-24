<?php

namespace Tests\Feature;

use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * API-LINK-010 POST /links/import and API-LINK-011 GET /links/export (DEC-041).
 */
class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stashly.rate_limits.import_per_minute' => 1000]);
        $this->user = User::factory()->create();
        $this->token = $this->tokenFor($this->user);
    }

    private const NETSCAPE = <<<'HTML'
<!DOCTYPE NETSCAPE-Bookmark-file-1>
<!-- This is an automatically generated file. -->
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<TITLE>Bookmarks</TITLE>
<H1>Bookmarks</H1>
<DL><p>
    <DT><H3 ADD_DATE="1600000000" LAST_MODIFIED="1600000001" PERSONAL_TOOLBAR_FOLDER="true">Bookmarks bar</H3>
    <DL><p>
        <DT><A HREF="https://example.com/one?utm_source=news" ADD_DATE="1600000000" ICON="data:image/png;base64,AAAA">First &amp; best</A>
        <DT><H3>Dev</H3>
        <DL><p>
            <DT><A HREF="https://example.com/two" TAGS="Laravel, php ,#PHP">Second</A>
            <DD>A note about the second link
            <DT><A HREF="https://example.com/three">Third <b>bold</b></A>
        </DL><p>
    </DL><p>
    <DT><A HREF="https://example.com/top-level">Top level</A>
    <DT><A HREF="javascript:alert(1)">Script</A>
    <DT><A HREF="ftp://example.com/file">FTP</A>
    <DT><A HREF="">Empty</A>
    <DT><A HREF="https://example.com/one?utm_medium=x">First again</A>
</DL><p>
HTML;

    private function upload(string $content, string $name = 'bookmarks.html', array $extra = [], ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->post('/api/v1/links/import', ['file' => UploadedFile::fake()->createWithContent($name, $content)] + $extra, ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------------ import: Netscape HTML

    public function test_a_netscape_file_is_imported_with_folders_tags_and_notes(): void
    {
        Queue::fake();

        $this->upload(self::NETSCAPE)->assertOk()->assertExactJson(['data' => [
            'imported_count' => 4,
            'skipped_duplicates_count' => 1,
            'skipped_invalid_count' => 3,
            'collections_created' => 3,
            'total_in_file' => 8,
            'next_offset' => null,
        ]]);

        $links = $this->user->links()->with(['collections', 'tags'])->get()->keyBy('normalized_url');
        $this->assertCount(4, $links);

        $first = $links['https://example.com/one'];
        $this->assertSame('First & best', $first->title);
        $this->assertSame(['Bookmarks bar'], $first->collections->pluck('name')->all());
        $this->assertSame('https://example.com/one?utm_source=news', $first->original_url);

        $second = $links['https://example.com/two'];
        $this->assertSame(['Dev'], $second->collections->pluck('name')->all(), 'The innermost folder wins.');
        $this->assertSame(['laravel', 'php'], $second->tags->pluck('name')->all());
        $this->assertSame('A note about the second link', $second->note);

        $this->assertSame('Third bold', $links['https://example.com/three']->title, 'Markup in a title is dropped.');
        $this->assertSame(['Imported'], $links['https://example.com/top-level']->collections->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Bookmarks bar', 'Dev', 'Imported'], $this->user->collections()->pluck('name')->all());
    }

    public function test_imported_links_go_through_the_normal_metadata_queue(): void
    {
        Queue::fake();

        $this->upload(self::NETSCAPE)->assertOk();

        Queue::assertPushed(FetchLinkMetadata::class, 4);
        $this->assertSame(4, $this->user->links()->where('metadata_status', 'pending')->count());
    }

    public function test_duplicates_of_saved_links_and_inside_the_file_are_skipped_and_a_second_import_adds_nothing(): void
    {
        $this->saveLink($this->user, 'https://example.com/two', ['title' => 'Mine already']);

        $first = $this->upload(self::NETSCAPE)->assertOk()->json('data');
        $this->assertSame(3, $first['imported_count']);
        $this->assertSame(2, $first['skipped_duplicates_count']);
        $this->assertSame('Mine already', $this->user->links()->where('normalized_url', 'https://example.com/two')->value('title'), 'An existing link is never overwritten.');

        $this->resetAuth();
        $again = $this->upload(self::NETSCAPE)->assertOk()->json('data');

        $this->assertSame(0, $again['imported_count']);
        $this->assertSame(5, $again['skipped_duplicates_count']);
        $this->assertSame(0, $again['collections_created'], 'Folders are not created for links that are skipped.');
        $this->assertSame(4, $this->user->links()->count());
        $this->assertSame(3, $this->user->collections()->count());
    }

    public function test_a_folder_is_not_created_when_all_its_links_are_duplicates(): void
    {
        $this->saveLink($this->user, 'https://example.com/known', ['title' => 'Known']);
        $html = '<DL><DT><H3>Brand new folder</H3><DL><DT><A HREF="https://example.com/known">Known</A></DL></DL>';

        $this->upload($html)->assertOk()
            ->assertJsonPath('data.skipped_duplicates_count', 1)->assertJsonPath('data.collections_created', 0);

        $this->assertSame(0, $this->user->collections()->count());
    }

    public function test_a_file_with_binary_bytes_is_rejected_even_if_it_contains_links(): void
    {
        $this->assertApiError($this->upload("url\nhttps://example.com/a\0\n", 'links.csv'), 422, 'VALIDATION_FAILED');
        $this->resetAuth();
        $this->assertApiError($this->upload("<DL><DT><A HREF=\"https://example.com/a\">a</A></DL>\0", 'links.html'), 422, 'VALIDATION_FAILED');
        $this->assertSame(0, $this->user->links()->count());
    }

    public function test_another_users_links_are_not_duplicates(): void
    {
        $this->saveLink(User::factory()->create(), 'https://example.com/two', ['title' => 'Theirs']);

        $this->upload(self::NETSCAPE)->assertOk()->assertJsonPath('data.imported_count', 4);

        $this->assertSame(1, $this->user->links()->where('normalized_url', 'https://example.com/two')->count());
    }

    public function test_existing_collections_are_reused_ignoring_case(): void
    {
        $existing = $this->user->collections()->create(['name' => 'DEV']);

        $this->upload(self::NETSCAPE)->assertOk()->assertJsonPath('data.collections_created', 2);

        $this->assertSame(1, $this->user->collections()->whereIn('name_key', ['dev'])->count());
        $this->assertSame(2, $existing->links()->count());
    }

    public function test_only_web_addresses_are_imported(): void
    {
        $html = '<DL><p><DT><A HREF="javascript:alert(1)">a</A><DT><A HREF="data:text/html,x">b</A><DT><A HREF="file:///etc/passwd">c</A><DT><A HREF="place:sort=8">d</A><DT><A HREF="https://example.com/ok">ok</A></DL>';

        $this->upload($html)->assertOk()->assertJsonPath('data.imported_count', 1)->assertJsonPath('data.skipped_invalid_count', 4);

        $this->assertSame(['https://example.com/ok'], $this->user->links()->pluck('original_url')->all());
    }

    public function test_titles_are_cleaned_and_limited(): void
    {
        $long = str_repeat('x', 400);
        $html = "<DL><DT><A HREF=\"https://example.com/a\"><script>alert(1)</script>Hello\n\tworld</A><DT><A HREF=\"https://example.com/b\">$long</A><DT><A HREF=\"https://example.com/c\"></A></DL>";

        $this->upload($html)->assertOk()->assertJsonPath('data.imported_count', 3);

        $titles = $this->user->links()->orderBy('original_url')->pluck('title')->all();
        $this->assertSame('alert(1)Hello world', $titles[0], 'Tags are stripped; the text of a script element is plain text here, never executed.');
        $this->assertSame(300, mb_strlen($titles[1]));
        $this->assertNull($titles[2]);
    }

    public function test_tags_are_normalized_deduplicated_and_capped(): void
    {
        $tags = implode(',', array_map(fn ($i) => "t$i", range(1, 30)));
        $html = "<DL><DT><A HREF=\"https://example.com/a\" TAGS=\"$tags,T1,,#t2,bad,tag\">a</A></DL>";

        $this->upload($html)->assertOk()->assertJsonPath('data.imported_count', 1);

        $this->assertSame(20, $this->user->links()->first()->tags()->count());
    }

    public function test_windows_1252_files_and_a_byte_order_mark_are_read_correctly(): void
    {
        $html = "\xEF\xBB\xBF<DL><DT><A HREF=\"https://example.com/a\">Caf\xE9</A></DL>";
        $this->upload($html)->assertOk();
        $this->assertSame('Café', $this->user->links()->value('title'));

        $this->resetAuth();
        $utf8 = '<DL><DT><A HREF="https://example.com/b">مرحبا بالعالم</A></DL>';
        $this->upload($utf8)->assertOk();
        $this->assertSame('مرحبا بالعالم', $this->user->links()->where('original_url', 'https://example.com/b')->value('title'));
    }

    public function test_quoting_styles_and_uppercase_markup_are_understood(): void
    {
        $html = "<dl><dt><a href='https://example.com/single'>Single</a><DT><A HREF=https://example.com/bare>Bare</A></dl>";

        $this->upload($html)->assertOk()->assertJsonPath('data.imported_count', 2);
    }

    // ------------------------------------------------------------------ import: CSV

    public function test_a_csv_with_a_header_row_is_imported(): void
    {
        $csv = "Name,URL,Folder,Tags,Notes\n\"Laravel, docs\",https://laravel.com/docs,Dev,\"php;laravel\",\"Read \"\"first\"\"\"\nNo folder,https://example.com/x,,,\nBroken,notaurl,Dev,,\n";

        $this->upload($csv, 'links.csv')->assertOk()->assertExactJson(['data' => [
            'imported_count' => 2, 'skipped_duplicates_count' => 0, 'skipped_invalid_count' => 1,
            'collections_created' => 2, 'total_in_file' => 3, 'next_offset' => null,
        ]]);

        $docs = $this->user->links()->where('normalized_url', 'like', '%laravel.com%')->first();
        $this->assertSame('Laravel, docs', $docs->title);
        $this->assertSame('Read "first"', $docs->note);
        $this->assertSame(['laravel', 'php'], $docs->tags()->pluck('name')->all());
        $this->assertSame(['Dev'], $docs->collections()->pluck('name')->all());
    }

    public function test_a_csv_without_a_header_uses_url_title_folder_tags_note(): void
    {
        $csv = "https://example.com/a,Alpha,Work,x;y,hello\nhttps://example.com/b\n";

        $this->upload($csv, 'links.csv')->assertOk()->assertJsonPath('data.imported_count', 2);

        $a = $this->user->links()->where('original_url', 'https://example.com/a')->first();
        $this->assertSame('Alpha', $a->title);
        $this->assertSame('hello', $a->note);
        $this->assertSame(['Work'], $a->collections()->pluck('name')->all());
    }

    public function test_a_txt_file_is_detected_by_its_content(): void
    {
        $this->upload("url,title\nhttps://example.com/t,Text\n", 'links.txt', [])->assertOk()->assertJsonPath('data.imported_count', 1);
        $this->resetAuth();
        $this->upload('<DL><DT><A HREF="https://example.com/h">Html</A></DL>', 'links.txt')->assertOk()->assertJsonPath('data.imported_count', 1);
    }

    // ------------------------------------------------------------------ import: batches and limits

    public function test_a_big_file_is_imported_100_links_per_request_with_next_offset(): void
    {
        Queue::fake();
        $html = '<DL>';
        for ($i = 1; $i <= 250; $i++) {
            $html .= "<DT><A HREF=\"https://example.com/p$i\">P$i</A>\n";
        }
        $html .= '</DL>';

        $first = $this->upload($html)->assertOk()->json('data');
        $this->assertSame([100, 250, 100], [$first['imported_count'], $first['total_in_file'], $first['next_offset']]);
        $this->assertSame(100, $this->user->links()->count());

        $this->resetAuth();
        $second = $this->upload($html, extra: ['offset' => $first['next_offset']])->assertOk()->json('data');
        $this->assertSame([100, 200], [$second['imported_count'], $second['next_offset']]);

        $this->resetAuth();
        $third = $this->upload($html, extra: ['offset' => $second['next_offset']])->assertOk()->json('data');
        $this->assertSame([50, null], [$third['imported_count'], $third['next_offset']]);

        $this->assertSame(250, $this->user->links()->count());
        $this->assertSame(1, $this->user->collections()->count(), 'One "Imported" collection, not one per request.');
    }

    public function test_an_offset_past_the_end_imports_nothing(): void
    {
        $this->upload(self::NETSCAPE, extra: ['offset' => 500])->assertOk()
            ->assertJsonPath('data.imported_count', 0)->assertJsonPath('data.next_offset', null);
    }

    public function test_links_are_still_saved_when_no_more_collections_can_be_created(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->user->collections()->create(['name' => "C$i"]);
        }

        $this->upload(self::NETSCAPE)->assertOk()->assertJsonPath('data.imported_count', 4)->assertJsonPath('data.collections_created', 0);

        $this->assertSame(4, $this->user->links()->count());
        $this->assertSame(100, $this->user->collections()->count());
    }

    // ------------------------------------------------------------------ import: validation and security

    public function test_the_upload_is_validated(): void
    {
        $cases = [
            'no file' => [fn () => $this->withToken($this->token)->postJson('/api/v1/links/import', []), 'file'],
            'a text field instead of a file' => [fn () => $this->withToken($this->token)->postJson('/api/v1/links/import', ['file' => 'https://example.com']), 'file'],
            'png image' => [fn () => $this->withToken($this->token)->post('/api/v1/links/import', ['file' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json']), 'file'],
            'php script' => [fn () => $this->upload('<?php echo 1;', 'shell.php'), 'file'],
            'html extension but binary content' => [fn () => $this->upload("\x89PNG\r\n\x1a\n\x00\x00\x00", 'b.html'), 'file'],
            'zip' => [fn () => $this->upload("PK\x03\x04\x00\x00", 'b.csv'), 'file'],
            'too large' => [fn () => $this->withToken($this->token)->post('/api/v1/links/import', ['file' => UploadedFile::fake()->createWithContent('big.html', str_repeat('a', 2049 * 1024))], ['Accept' => 'application/json']), 'file'],
            'no bookmarks inside' => [fn () => $this->upload('<html><body>Hello</body></html>'), 'file'],
            'empty file' => [fn () => $this->upload(''), 'file'],
            'negative offset' => [fn () => $this->upload(self::NETSCAPE, extra: ['offset' => -1]), 'offset'],
            'text offset' => [fn () => $this->upload(self::NETSCAPE, extra: ['offset' => 'abc']), 'offset'],
        ];

        foreach ($cases as $label => [$request, $field]) {
            $this->resetAuth();
            $response = $this->assertApiError($request(), 422, 'VALIDATION_FAILED');
            $this->assertArrayHasKey($field, $response->json('error.details'), $label);
        }

        $this->assertSame(0, $this->user->links()->count());
    }

    public function test_a_file_just_under_the_size_limit_is_accepted(): void
    {
        $html = '<DL><DT><A HREF="https://example.com/a">a</A></DL><!--'.str_repeat('x', 2000 * 1024).'-->';

        $this->upload($html)->assertOk()->assertJsonPath('data.imported_count', 1);
    }

    public function test_an_import_needs_a_token(): void
    {
        $this->flushHeaders();

        $this->assertApiError($this->post('/api/v1/links/import', ['file' => UploadedFile::fake()->createWithContent('b.html', self::NETSCAPE)], ['Accept' => 'application/json']), 401, 'UNAUTHENTICATED');
    }

    public function test_the_import_is_limited_to_three_requests_per_minute(): void
    {
        config(['stashly.rate_limits.import_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->upload(self::NETSCAPE)->assertOk();
        }

        $this->assertApiError($this->upload(self::NETSCAPE), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_the_import_log_has_counts_only(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        $this->upload(self::NETSCAPE, 'my-secret-bookmarks.html')->assertOk();

        $all = implode("\n", $logged);
        $this->assertStringContainsString('Bookmarks imported.', $all);
        foreach (['example.com', 'my-secret-bookmarks', 'First', 'Bookmarks bar', $this->token] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    // ------------------------------------------------------------------ export

    private function library(): array
    {
        $dev = $this->user->collections()->create(['name' => 'Dev & "Ops"', 'description' => 'tools']);
        $other = $this->user->collections()->create(['name' => 'Reading']);
        $a = $this->saveLink($this->user, 'https://example.com/a?x=1&y=2', ['title' => 'Alpha <b>', 'note' => "Line one\nline two", 'tags' => ['php', 'laravel'], 'collection_ids' => [$dev->id, $other->id]]);
        $b = $this->saveLink($this->user, 'https://example.com/b', ['title' => 'Beta', 'collection_ids' => [$other->id]]);
        $c = $this->saveLink($this->user, 'https://example.com/c');
        $c->forceFill(['metadata_title' => 'Fetched title'])->save();
        $a->forceFill(['ai_summary' => '- a summary', 'ai_tags' => ['php'], 'ai_insights' => ['difficulty_level' => 'beginner'], 'ai_action_plan' => ['objective' => 'Do it']])->save();

        return [$a, $b, $c];
    }

    public function test_the_html_export_is_a_netscape_bookmark_file(): void
    {
        $this->library();

        $response = $this->withToken($this->token)->get('/api/v1/links/export?format=html')->assertOk();
        $html = $response->streamedContent();

        $this->assertStringStartsWith('<!DOCTYPE NETSCAPE-Bookmark-file-1>', $html);
        $this->assertStringContainsString('<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">', $html);
        $this->assertStringContainsString('<DT><H3', $html);
        $this->assertStringContainsString('>Dev &amp; &quot;Ops&quot;</H3>', $html);
        $this->assertStringContainsString('HREF="https://example.com/a?x=1&amp;y=2"', $html);
        $this->assertStringContainsString('TAGS="laravel,php"', $html);
        $this->assertStringContainsString('>Alpha &lt;b&gt;</A>', $html);
        $this->assertStringContainsString('<DD>Line one line two', $html);
        $this->assertStringContainsString('>Fetched title</A>', $html);
        $this->assertSame(substr_count($html, '<DL>'), substr_count($html, '</DL>'));
        $this->assertSame(4, substr_count($html, 'HREF='), 'Alpha twice (two collections), Beta, Gamma.');
        $this->assertStringNotContainsString('a summary', $html);
        $this->assertStringNotContainsString($this->user->email, $html);

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=stashly-bookmarks-', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_html_export_can_be_imported_back_into_a_clean_account(): void
    {
        $this->library();
        $html = $this->withToken($this->token)->get('/api/v1/links/export?format=html')->streamedContent();

        $other = User::factory()->create();
        $this->resetAuth();
        $this->flushHeaders();
        $this->upload($html, 'back.html', [], $this->tokenFor($other))->assertOk()
            ->assertJsonPath('data.imported_count', 3)->assertJsonPath('data.skipped_invalid_count', 0);

        $this->assertEqualsCanonicalizing(['Dev & "Ops"', 'Reading', 'Imported'], $other->collections()->pluck('name')->all());
        $alpha = $other->links()->where('normalized_url', 'like', '%example.com/a%')->first();
        $this->assertSame('Alpha <b>', $alpha->title);
        $this->assertSame(['laravel', 'php'], $alpha->tags()->pluck('name')->all());
        $this->assertSame('Line one line two', $alpha->note);
        $this->assertSame('https://example.com/a?x=1&y=2', $alpha->original_url);
    }

    public function test_the_json_export_has_the_library_with_ai_data_and_nothing_of_other_users(): void
    {
        [$a] = $this->library();
        $stranger = User::factory()->create(['email' => 'stranger@example.test']);
        $this->saveLink($stranger, 'https://stranger.example/secret', ['title' => 'Stranger title']);

        $response = $this->withToken($this->token)->get('/api/v1/links/export')->assertOk();
        $raw = $response->streamedContent();
        $data = json_decode($raw, true);

        $this->assertSame(1, $data['schema_version']);
        $this->assertCount(3, $data['links']);
        $this->assertCount(2, $data['collections']);
        $this->assertEqualsCanonicalizing(['laravel', 'php'], $data['tags']);
        $alpha = collect($data['links'])->firstWhere('id', $a->id);
        $this->assertSame('- a summary', $alpha['ai']['summary']);
        $this->assertSame(['difficulty_level' => 'beginner'], $alpha['ai_insights']);
        $this->assertSame(['objective' => 'Do it'], $alpha['ai_action_plan']);
        $this->assertNull(collect($data['links'])->firstWhere('title', 'Beta')['ai_insights']);
        $this->assertSame('Line one'."\n".'line two', $alpha['note']);
        foreach (['Stranger title', 'stranger@example.test', 'password', 'token'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, $needle);
        }

        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=stashly-library-', $response->headers->get('Content-Disposition'));
    }

    public function test_the_format_defaults_to_json_and_is_validated(): void
    {
        $this->assertSame(1, json_decode($this->withToken($this->token)->get('/api/v1/links/export?format=json')->streamedContent(), true)['schema_version']);
        $this->resetAuth();
        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/export?format=xml'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/export?format[]=html'), 422, 'VALIDATION_FAILED');
    }

    public function test_an_empty_library_exports_valid_files(): void
    {
        $html = $this->withToken($this->token)->get('/api/v1/links/export?format=html')->streamedContent();
        $this->assertStringContainsString('<DL><p>', $html);
        $this->assertStringNotContainsString('HREF', $html);
        $this->resetAuth();

        $json = json_decode($this->withToken($this->token)->get('/api/v1/links/export')->streamedContent(), true);
        $this->assertSame([], $json['links']);
    }

    public function test_a_large_library_is_exported_in_chunks(): void
    {
        for ($i = 1; $i <= 450; $i++) {
            $this->saveLink($this->user, "https://example.com/l$i", ['title' => "L$i"]);
        }

        $html = $this->withToken($this->token)->get('/api/v1/links/export?format=html')->streamedContent();

        $this->assertSame(450, substr_count($html, '<DT><A HREF='));
    }

    public function test_the_export_needs_a_token_and_is_limited(): void
    {
        $this->flushHeaders();
        $this->assertApiError($this->getJson('/api/v1/links/export'), 401, 'UNAUTHENTICATED');

        config(['stashly.rate_limits.links_export_per_ten_minutes' => 2]);
        $this->resetAuth();
        $this->withToken($this->token)->get('/api/v1/links/export?format=html')->assertOk();
        $this->withToken($this->token)->get('/api/v1/links/export')->assertOk();
        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/export'), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_the_fixed_paths_do_not_clash_with_link_ids(): void
    {
        $link = $this->saveLink($this->user, 'https://example.com/a', ['title' => 'A']);

        $this->withToken($this->token)->getJson('/api/v1/links/'.$link->id)->assertOk()->assertJsonPath('data.link.id', $link->id);
        $this->assertSame(1, Link::count());
    }
}
