<?php

namespace App\Services\Bookmarks;

/**
 * Reads bookmark files into plain entries (DEC-041): the Netscape bookmark HTML that Chrome, Edge,
 * Brave, Firefox and Safari export, and CSV. Nothing is fetched or executed; the text is only scanned.
 *
 * Folders: an <H3> names the <DL> that follows it. Nested folders are flattened to the innermost
 * folder's name. Entries outside any folder have no folder.
 *
 * @phpstan-type Entry array{url: string, title: string, folder: string, tags: list<string>, note: string}
 */
class BookmarkParser
{
    /**
     * @return list<Entry>
     */
    public function parse(string $content, string $format): array
    {
        $content = $this->utf8($content);

        return $format === 'csv' ? $this->csv($content) : $this->netscape($content);
    }

    /**
     * Which parser to use for an upload: by extension, and by content for .txt.
     */
    public function formatFor(string $extension, string $content): string
    {
        $extension = strtolower($extension);

        if ($extension === 'csv') {
            return 'csv';
        }
        if (in_array($extension, ['html', 'htm'], true)) {
            return 'html';
        }

        return preg_match('/<a\s[^>]*href\s*=|NETSCAPE-Bookmark-file/i', $content) === 1 ? 'html' : 'csv';
    }

    /**
     * @return list<Entry>
     */
    private function netscape(string $html): array
    {
        $entries = [];
        $stack = [];
        $pending = null;

        preg_match_all('#<(/?)DL\b[^>]*>|<H3\b[^>]*>(.*?)</H3\s*>|<A\b([^>]*)>(.*?)</A\s*>|<DD\b[^>]*>([^<]*)#is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $token = strtolower(substr($m[0], 0, 3));

            if ($token === '<dl') {
                $stack[] = $pending ?? end($stack) ?: '';
                $pending = null;
            } elseif ($token === '</d') {
                array_pop($stack);
                $pending = null;
            } elseif ($token === '<h3') {
                $pending = $this->text($m[2]);
            } elseif ($token === '<a ' || $token === '<a>' || str_starts_with($token, '<a')) {
                $attributes = $this->attributes($m[3]);
                $url = html_entity_decode($attributes['href'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $entries[] = [
                    'url' => trim($url),
                    'title' => $this->text($m[4]),
                    'folder' => (string) end($stack),
                    'tags' => isset($attributes['tags']) ? array_map('trim', explode(',', html_entity_decode($attributes['tags'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : [],
                    'note' => '',
                ];
            } elseif ($token === '<dd' && $entries !== []) {
                // A <DD> right after a bookmark is its description.
                $last = array_key_last($entries);
                if ($entries[$last]['note'] === '') {
                    $entries[$last]['note'] = $this->text($m[5] ?? '');
                }
            }
        }

        return $entries;
    }

    /**
     * Columns are found by header name (url|link|href, title|name, folder|collection|category, tags|tag,
     * note|notes|description|comment). Without a header row the columns are url, title, folder, tags, note.
     *
     * @return list<Entry>
     */
    private function csv(string $csv): array
    {
        $rows = [];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $row);
        }
        fclose($stream);

        if ($rows === []) {
            return [];
        }

        $names = [
            'url' => ['url', 'link', 'href', 'address'],
            'title' => ['title', 'name'],
            'folder' => ['folder', 'collection', 'category'],
            'tags' => ['tags', 'tag'],
            'note' => ['note', 'notes', 'description', 'comment', 'comments'],
        ];

        $header = array_map(fn ($cell) => strtolower($cell), $rows[0]);
        $columns = [];
        foreach ($names as $field => $aliases) {
            foreach ($header as $index => $cell) {
                if (in_array($cell, $aliases, true)) {
                    $columns[$field] = $index;
                    break;
                }
            }
        }

        if (isset($columns['url'])) {
            array_shift($rows);
        } else {
            $columns = ['url' => 0, 'title' => 1, 'folder' => 2, 'tags' => 3, 'note' => 4];
        }

        $entries = [];
        foreach ($rows as $row) {
            $cell = fn (string $field) => isset($columns[$field]) ? ($row[$columns[$field]] ?? '') : '';
            $entries[] = [
                'url' => $cell('url'),
                'title' => $this->text($cell('title')),
                'folder' => $this->text($cell('folder')),
                'tags' => array_map('trim', preg_split('/[;,]/', $cell('tags')) ?: []),
                'note' => $this->text($cell('note')),
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, string> lowercase name => raw value
     */
    private function attributes(string $raw): array
    {
        preg_match_all('/([a-z_:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $raw, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $attribute) {
            $out[strtolower($attribute[1])] = $attribute[2] !== '' ? $attribute[2] : ($attribute[3] ?? '').($attribute[4] ?? '');
        }

        return $out;
    }

    /**
     * Visible text of a fragment: tags removed, entities decoded, whitespace and control characters collapsed.
     */
    private function text(string $fragment): string
    {
        $text = html_entity_decode(strip_tags($fragment), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $text));
    }

    private function utf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // Older exports are Windows-1252.
        return mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
}
