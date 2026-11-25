<?php
// tests/Unit/Modules/Faq/FaqPageMarkupTest.php
namespace Tests\Unit\Modules\Faq;

use Modules\Faq\Services\FaqMarkup;
use Tests\TestCase;

/** /faq: every question is a heading, and the page carries the same questions as schema.org FAQPage (24 Nov 2026). */
final class FaqPageMarkupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(FaqMarkup::class, false)) { require_once BASE_PATH . '/modules/faq/Services/FaqMarkup.php'; }
    }

    private static function cats(): array
    {
        return [
            ['name' => 'Start', 'faqs_list' => [
                ['question' => 'What is it?', 'answer' => '<p>A <b>tool</b>.</p><ul><li>One</li><li>Two &amp; three</li></ul><script>bad()</script>'],
                ['question' => '', 'answer' => 'no question'],
                ['question' => 'No answer?', 'answer' => ' <p></p> '],
            ]],
            ['name' => 'Empty', 'faqs_list' => []],
        ];
    }

    public function testTheQuestionsAVisitorReadsAreTheOnesInTheMarkup(): void
    {
        $g = FaqMarkup::graph(self::cats(), [['question' => 'Uncategorised?', 'answer' => 'Yes.']]);
        $this->assertSame('FAQPage', $g['@type']);
        $this->assertSame(['What is it?', 'Uncategorised?'], array_column($g['mainEntity'], 'name'));
        $this->assertSame('A tool. One Two & three', $g['mainEntity'][0]['acceptedAnswer']['text']);
        $this->assertStringNotContainsString('bad()', json_encode($g));
    }

    public function testNothingToSayMeansNoMarkup(): void
    {
        $this->assertSame([], FaqMarkup::graph([], []));
        $this->assertSame([], FaqMarkup::graph([['faqs_list' => [['question' => 'Q', 'answer' => '']]]]));
        $this->assertSame('', FaqMarkup::script([]));
    }

    public function testAnAnswerCannotCloseTheScript(): void
    {
        $s = FaqMarkup::script(FaqMarkup::graph([], [['question' => 'Q', 'answer' => 'use &lt;/script&gt; here']]));
        $this->assertSame(1, substr_count($s, '</script>'));
        $json = json_decode(substr($s, strlen('<script type="application/ld+json">'), -strlen("</script>\n")), true);
        $this->assertSame('use </script> here', $json['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testThereIsALimit(): void
    {
        $rows = array_fill(0, 500, ['question' => 'Q', 'answer' => 'A']);
        $this->assertCount(FaqMarkup::MAX_QUESTIONS, FaqMarkup::graph([], $rows)['mainEntity']);
    }

    public function testThePageShowsEachQuestionAsAHeadingAndCarriesTheMarkup(): void
    {
        if (!function_exists('e')) { $this->markTestSkipped('no e() helper here'); }
        $categories = self::cats();
        $uncategorized = [['question' => 'Uncategorised?', 'answer' => 'Yes.']];
        ob_start();
        (static function () use ($categories, $uncategorized): void { include BASE_PATH . '/modules/faq/Views/public.php'; })();
        $html = (string) ob_get_clean();

        $this->assertSame(1, preg_match_all('#<h1\b#', $html));
        $this->assertSame(4, preg_match_all('#<h3\b[^>]*>\s*<button\b#', $html), 'every question, in both lists, is a heading around its button');
        $this->assertSame(4, preg_match_all('#aria-expanded="false"#', $html));
        $this->assertSame(1, substr_count($html, 'application/ld+json'));
        // The button is inside the heading now: the answer is no longer its next sibling.
        $this->assertStringNotContainsString('btn.nextElementSibling', $html);
        $this->assertStringContainsString("item.querySelector('.faq-answer')", $html);
    }
}
