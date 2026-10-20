<?php

namespace App\Support\Search;

/**
 * Search normalization (contract 4.3): case-insensitive and Arabic-aware.
 * Applied both to the stored links.search_text column and to query words,
 * so matching is a plain substring test on every database engine.
 */
final class SearchText
{
    /** Arabic harakat, Quranic marks and superscript alef. */
    private const DIACRITICS = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u';

    /** Alef with hamza above/below, alef with madda, alef wasla. */
    private const ALEF_FORMS = '/[\x{0622}\x{0623}\x{0625}\x{0671}]/u';

    private const TATWEEL = '/\x{0640}/u';

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace([self::DIACRITICS, self::TATWEEL], '', $text);
        $text = preg_replace(self::ALEF_FORMS, "\u{0627}", $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Normalized, de-duplicated query words; every one of them must match.
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $normalized = self::normalize($query);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * @param  array<int, ?string>  $fields
     */
    public static function index(array $fields): string
    {
        return self::normalize(implode(' ', array_filter($fields, fn ($field) => $field !== null && $field !== '')));
    }
}
