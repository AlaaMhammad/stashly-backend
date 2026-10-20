<?php

namespace Tests\Unit;

use App\Support\Search\SearchText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract 4.3: case-insensitive, Arabic-aware (ignore diacritics, unify alef forms).
 */
class SearchTextTest extends TestCase
{
    #[DataProvider('normalizations')]
    public function test_normalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, SearchText::normalize($input));
    }

    public static function normalizations(): array
    {
        return [
            'lowercases latin' => ['Hello WORLD', 'hello world'],
            'removes harakat' => ['مُحَمَّد', 'محمد'],
            'removes tanween and sukun' => ['كِتَابٌ مَكْتُوبٌ', 'كتاب مكتوب'],
            'removes superscript alef' => ['هٰذا', 'هذا'],
            'unifies alef with hamza above' => ['أحمد', 'احمد'],
            'unifies alef with hamza below' => ['إسلام', 'اسلام'],
            'unifies alef with madda' => ['آمن', 'امن'],
            'unifies alef wasla' => ['ٱلله', 'الله'],
            'removes tatweel' => ['جـــميل', 'جميل'],
            'collapses whitespace' => ["  a \n\t b  ", 'a b'],
            'keeps other arabic letters' => ['مدرسة على', 'مدرسة على'],
        ];
    }

    public function test_words_are_normalized_and_deduplicated(): void
    {
        $this->assertSame(['اسلام', 'news'], SearchText::words('  إسلام NEWS   news إِسلام '));
        $this->assertSame([], SearchText::words('   '));
    }

    public function test_index_joins_non_empty_fields(): void
    {
        $this->assertSame('my title example.com', SearchText::index(['My Title', null, '', 'example.com']));
    }
}
