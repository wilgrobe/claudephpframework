<?php
// tests/Unit/Modules/Feedback/IssueWidgetExamplesTest.php
namespace Tests\Unit\Modules\Feedback;

use Core\Database\Database;
use Modules\Feedback\Services\IssueWidget;
use Tests\TestCase;

/**
 * The example text inside the "Report an issue" form.
 *
 * It was hard-coded with MarketOtter's own tasks ("Mark this week's LinkedIn
 * post as posted"), so the first other site to switch the widget on —
 * StoriesDen, 2026-10-14 — asked authors about LinkedIn posts. Now each site
 * sets its own, and an unset site gets an example that fits anywhere.
 */
final class IssueWidgetExamplesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(IssueWidget::class, false)) {
            require_once BASE_PATH . '/modules/feedback/Services/IssueWidget.php';
        }
    }

    /** @param array<string,string> $settings site-scope key => value */
    private function withSettings(array $settings): void
    {
        $this->mockDatabase(new class($settings) extends Database {
            public function __construct(private array $settings) {}
            public function fetchAll(string $sql, array $bind = []): array
            {
                if (!str_contains($sql, 'FROM settings')) return [];
                $rows = [];
                foreach ($this->settings as $k => $v) $rows[] = ['key' => $k, 'value' => $v, 'type' => 'string'];
                return $rows;
            }
            public function fetchOne(string $sql, array $bind = []): ?array { return null; }
        });
    }

    public function test_an_unset_site_gets_examples_that_fit_any_site(): void
    {
        $this->withSettings([]);

        $this->assertSame(IssueWidget::DEFAULT_INTENT_EXAMPLE, IssueWidget::intentExample());
        $this->assertSame(IssueWidget::DEFAULT_OUTCOME_EXAMPLE, IssueWidget::outcomeExample());
        $this->assertStringNotContainsStringIgnoringCase('linkedin', IssueWidget::intentExample(),
            'The default example is MarketOtter-specific again; every other site would show it.');
    }

    public function test_a_site_uses_its_own_examples(): void
    {
        $this->withSettings([
            'builder.feedback.widget.intent_example'  => 'e.g. Export my book as an EPUB',
            'builder.feedback.widget.outcome_example' => 'e.g. The download finished but the file would not open',
        ]);

        $this->assertSame('e.g. Export my book as an EPUB', IssueWidget::intentExample());
        $this->assertSame('e.g. The download finished but the file would not open', IssueWidget::outcomeExample());
    }

    public function test_a_blank_setting_falls_back_to_the_default(): void
    {
        // The admin form saves a blank field as '' — that must mean "default",
        // not an empty placeholder.
        $this->withSettings(['builder.feedback.widget.intent_example' => '   ']);

        $this->assertSame(IssueWidget::DEFAULT_INTENT_EXAMPLE, IssueWidget::intentExample());
    }

    public function test_the_form_reads_the_examples_instead_of_hard_coding_them(): void
    {
        $view = (string) file_get_contents(BASE_PATH . '/modules/feedback/Views/widget.php');

        $this->assertStringContainsString('IssueWidget::intentExample()', $view);
        $this->assertStringContainsString('IssueWidget::outcomeExample()', $view);
        $this->assertStringNotContainsStringIgnoringCase('LinkedIn post', $view);
    }
}
