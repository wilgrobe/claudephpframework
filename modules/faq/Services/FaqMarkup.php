<?php
// modules/faq/Services/FaqMarkup.php
namespace Modules\Faq\Services;

/**
 * schema.org FAQPage for the public /faq page (24 Nov 2026): the same questions and answers a visitor reads, for
 * search engines and AI assistants. Plain text, so an answer's own markup never reaches the JSON.
 *
 * Pure.
 */
final class FaqMarkup
{
    public const MAX_QUESTIONS = 200;

    /**
     * @param list<array<string,mixed>> $categories    rows with faqs_list (question, answer)
     * @param list<array<string,mixed>> $uncategorized rows with question, answer
     */
    public static function graph(array $categories, array $uncategorized = []): array
    {
        $rows = [];
        foreach ($categories as $c) {
            foreach ((array) ($c['faqs_list'] ?? []) as $f) { $rows[] = $f; }
        }
        foreach ($uncategorized as $f) { $rows[] = $f; }

        $items = [];
        foreach ($rows as $f) {
            if (count($items) >= self::MAX_QUESTIONS) { break; }
            $q = self::text((string) ($f['question'] ?? ''));
            $a = self::text((string) ($f['answer'] ?? ''));
            if ($q === '' || $a === '') { continue; }
            $items[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
        }
        return $items ? ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items] : [];
    }

    /** The <script> tag, or ''. JSON_HEX_* keeps a "</script>" inside an answer from closing it early. */
    public static function script(array $graph): string
    {
        if (!$graph) { return ''; }
        $json = json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /** Block tags become spaces first, so "<li>a</li><li>b</li>" reads "a b", not "ab". */
    private static function text(string $html): string
    {
        $t = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $t = strip_tags((string) preg_replace('#<(/?(p|div|li|ul|ol|br|h[1-6]|tr|td|table|blockquote)\b[^>]*)>#i', ' <$1>', $t));
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
