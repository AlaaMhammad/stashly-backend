<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BrokenLinksRequest;
use App\Http\Requests\Api\V1\ExportLinksRequest;
use App\Http\Requests\Api\V1\ImportLinksRequest;
use App\Http\Requests\Api\V1\SearchLinksRequest;
use App\Http\Resources\Api\V1\LinkResource;
use App\Services\Accounts\DataExportService;
use App\Services\Bookmarks\BookmarkHtmlExporter;
use App\Services\Bookmarks\BookmarkImportService;
use App\Services\Bookmarks\BookmarkParser;
use App\Services\Links\LinkFilters;
use App\Services\Links\LinkHealthChecker;
use App\Services\Links\LinkSearch;
use App\Services\Links\LinkService;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Library tools of DEC-041: import, export, advanced search, broken links and the on-demand health check.
 */
class LinkLibraryController extends Controller
{
    /**
     * API-LINK-010: 200 {imported_count, skipped_duplicates_count, skipped_invalid_count,
     * collections_created, total_in_file, next_offset}. 422 for a wrong or empty file.
     */
    public function import(ImportLinksRequest $request, BookmarkParser $parser, BookmarkImportService $importer): JsonResponse
    {
        $file = $request->file('file');
        $content = (string) file_get_contents($file->getRealPath());
        if (str_contains($content, "\0")) {
            // Binary data (an image, a zip, a UTF-16 file) behind a text file name.
            throw ValidationException::withMessages(['file' => 'The file must be a UTF-8 text file.']);
        }

        $format = $parser->formatFor($file->getClientOriginalExtension(), $content);

        if ($parser->parse($content, $format) === []) {
            throw ValidationException::withMessages(['file' => 'No bookmarks were found in this file.']);
        }

        $result = $importer->import($request->user(), $content, $format, (int) $request->input('offset', 0));

        // Counts only: never the file name, URLs or titles.
        Log::info('Bookmarks imported.', ['user_id' => $request->user()->getKey()] + $result);

        return ApiResponse::data($result);
    }

    /**
     * API-LINK-011: format=json (default) the full library incl. AI summaries and action plans, or
     * format=html a Netscape bookmark file. Streamed attachment.
     */
    public function export(ExportLinksRequest $request, DataExportService $json, BookmarkHtmlExporter $html): StreamedResponse
    {
        $user = $request->user();
        $format = $request->input('format', 'json');
        Log::info('Library exported.', ['user_id' => $user->getKey(), 'format' => $format]);

        $stamp = now()->utc()->format('Ymd-His');
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

        if ($format === 'html') {
            return response()->streamDownload(function () use ($html, $user) {
                foreach ($html->stream($user) as $fragment) {
                    echo $fragment;
                }
            }, "stashly-bookmarks-$stamp.html", $headers + ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return response()->streamDownload(function () use ($json, $user, $request) {
            foreach ($json->stream($user, $request, withAiData: true) as $fragment) {
                echo $fragment;
            }
        }, "stashly-library-$stamp.json", $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * API-LINK-012: 200 {data:[link], meta}. 422 INVALID_CURSOR.
     */
    public function search(SearchLinksRequest $request, LinkSearch $search): JsonResponse
    {
        $validated = $request->validated();

        [$items, $meta] = $search->paginate(
            $request->user(),
            LinkFilters::fromSearchInput($validated),
            $validated['cursor'] ?? null,
            (int) ($validated['limit'] ?? 20),
        );

        return ApiResponse::list(LinkResource::collection($items)->resolve($request), $meta);
    }

    /**
     * API-LINK-013: the links whose last health check found them broken, newest first.
     */
    public function broken(BrokenLinksRequest $request, LinkSearch $search): JsonResponse
    {
        $validated = $request->validated();

        [$items, $meta] = $search->paginate(
            $request->user(),
            new LinkFilters(broken: true),
            $validated['cursor'] ?? null,
            (int) ($validated['limit'] ?? 20),
        );

        return ApiResponse::list(LinkResource::collection($items)->resolve($request), $meta);
    }

    /**
     * API-LINK-014: checks the link now (HEAD, 5 s, up to 3 redirects) and stores the result.
     * 200 {link_id, status_code, response_time_ms, is_broken, error, last_checked_at}.
     */
    public function checkHealth(Request $request, string $link, LinkService $links, LinkHealthChecker $checker): JsonResponse
    {
        $model = $links->findOwned($request->user(), $link);
        $result = $checker->check($model->original_url);

        if ($result->isBroken !== null) {
            // A plain update: the check must not touch updated_at or the search index.
            DB::table('links')->where('id', $model->getKey())->update(['is_broken' => $result->isBroken, 'last_checked_at' => now()]);
            $model->refresh();
        }

        return ApiResponse::data([
            'link_id' => $model->id,
            'status_code' => $result->status,
            'response_time_ms' => $result->responseTimeMs,
            'is_broken' => (bool) $model->is_broken,
            'error' => $result->error,
            'last_checked_at' => $model->last_checked_at ? ApiDate::format($model->last_checked_at) : null,
        ]);
    }
}
