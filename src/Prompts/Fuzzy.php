<?php

namespace Phattarachai\RemotePrompt\Prompts;

/**
 * IDE-style matching: every word of the query must match, ranked
 *   0 — the text starts with it        ("d"   → "Delete…")
 *   1 — a word inside starts with it   ("upd" → "Checkout and Update")
 *   2 — it appears anywhere            ("ate" → "Update")
 *   3 — its letters appear in order    ("cal" → "cart-api-log")
 * Ties keep their original order.
 */
final class Fuzzy
{
    /**
     * @param  array<string, array{label: string, search: string}>  $entries
     * @return array<string, string> key => label
     */
    public static function filter(array $entries, string $query): array
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($query)), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $ranked = [];

        foreach ($entries as $key => $entry) {
            $score = self::score(mb_strtolower($entry['search']), $words);

            if ($score !== null) {
                $ranked[$score][$key] = $entry['label'];
            }
        }

        ksort($ranked);

        return array_merge(...array_values($ranked));
    }

    /**
     * @param  list<string>  $words
     */
    private static function score(string $haystack, array $words): ?int
    {
        $worst = 0;

        foreach ($words as $word) {
            $quoted = preg_quote($word, '/');

            $score = match (true) {
                str_starts_with($haystack, $word) => 0,
                preg_match("/(^|[\\s\\/\\-_.'])$quoted/u", $haystack) === 1 => 1,
                str_contains($haystack, $word) => 2,
                preg_match('/'.implode('.*', array_map(fn (string $char) => preg_quote($char, '/'), mb_str_split($word))).'/u', $haystack) === 1 => 3,
                default => null,
            };

            if ($score === null) {
                return null;
            }

            $worst = max($worst, $score);
        }

        return $worst;
    }
}
