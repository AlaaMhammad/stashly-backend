<?php

namespace App\Services\Bookmarks;

use App\Models\Link;
use App\Models\User;
use Generator;

/**
 * GET /links/export?format=html (DEC-041): the user's library as a Netscape bookmark file that
 * Chrome, Edge, Brave, Firefox and Safari can import. Each collection becomes a folder (a link in
 * several collections appears in each); links in no collection sit at the top level. Streamed in
 * chunks. Only url, title, saved date, tags and note leave: no ids, AI data or account details.
 */
class BookmarkHtmlExporter
{
    private const CHUNK = 200;

    /**
     * @return Generator<int, string>
     */
    public function stream(User $user): Generator
    {
        yield "<!DOCTYPE NETSCAPE-Bookmark-file-1>\n"
            ."<!-- This is an automatically generated file. It will be read and overwritten. DO NOT EDIT! -->\n"
            ."<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=UTF-8\">\n"
            ."<TITLE>Bookmarks</TITLE>\n<H1>Bookmarks</H1>\n<DL><p>\n";

        foreach ($user->collections()->orderBy('name_key')->orderBy('id')->cursor() as $collection) {
            yield '    <DT><H3 ADD_DATE="'.$collection->created_at->timestamp.'">'.$this->e($collection->name)."</H3>\n    <DL><p>\n";
            yield from $this->links($collection->links()->with('tags'), '        ');
            yield "    </DL><p>\n";
        }

        yield from $this->links($user->links()->whereDoesntHave('collections')->with('tags'), '    ');

        yield "</DL><p>\n";
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Link>|\Illuminate\Database\Eloquent\Relations\Relation<Link, *, *>  $query
     * @return Generator<int, string>
     */
    private function links($query, string $indent): Generator
    {
        $lastId = null;

        do {
            $rows = (clone $query)
                ->when($lastId !== null, fn ($q) => $q->where('links.id', '>', $lastId))
                ->orderBy('links.id')
                ->limit(self::CHUNK)
                ->get();

            foreach ($rows as $link) {
                $tags = $link->tags->pluck('name')->implode(',');
                yield $indent.'<DT><A HREF="'.$this->e($link->original_url).'" ADD_DATE="'.$link->created_at->timestamp.'"'
                    .($tags !== '' ? ' TAGS="'.$this->e($tags).'"' : '').'>'.$this->e($link->displayTitle())."</A>\n";
                if (filled($link->note)) {
                    yield $indent.'<DD>'.$this->e($link->note)."\n";
                }
            }

            $lastId = $rows->last()?->getKey();
        } while ($rows->count() === self::CHUNK);
    }

    private function e(string $value): string
    {
        // Newlines would end a <DD> early in strict importers: keep one line.
        return htmlspecialchars((string) preg_replace('/[\r\n]+/', ' ', $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
