<?php
// tests/Unit/Modules/Feedback/TakedownSeamTest.php
namespace Tests\Unit\Modules\Feedback;

use Core\Database\Database;
use Core\Request;
use Modules\Feedback\Controllers\Admin\FeedbackAdminController;
use Modules\Feedback\Services\TakedownHandler;
use Modules\Feedback\Services\TakedownRegistry;
use Tests\TestCase;

/**
 * An abuse report can take the reported item down — through the module that
 * published it, never by the feedback module reaching into that module's tables.
 *
 * The first version (built on MarketOtter, 2026-09-03) ran
 * `UPDATE cp_newsletter_widgets …` from the shared feedback controller, so every
 * other site would have shipped a takedown button querying a table it lacked.
 */
final class FakeFeedbackDb extends Database
{
    /** @var array<int,array> */
    public array $rows = [];
    /** @var list<array{0:string,1:array}> */
    public array $queries = [];

    public function __construct() {}

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        if (str_contains($sql, 'FROM feedback_submissions WHERE id = ?')) {
            return $this->rows[(int) $bindings[0]] ?? null;
        }
        return null;
    }

    public function query(string $sql, array $bindings = []): \PDOStatement
    {
        $this->queries[] = [$sql, $bindings];
        if (str_contains($sql, "UPDATE feedback_submissions SET status = 'reviewed'")) {
            $this->rows[(int) $bindings[0]]['status'] = 'reviewed';
        }
        return (new \ReflectionClass(\PDOStatement::class))->newInstanceWithoutConstructor();
    }
}

final class FakePageTakedown implements TakedownHandler
{
    /** @var array<string,bool> ref => published */
    public array $items = [];
    public bool $unpublishSticks = true;
    public bool $throws = false;
    /** @var list<string> */
    public array $unpublished = [];

    public function label(): string { return 'hosted page'; }
    public function url(string $ref): ?string { return "/p/$ref"; }
    public function isPublished(string $ref): ?bool { return $this->items[$ref] ?? null; }
    public function unpublish(string $ref): void
    {
        if ($this->throws) { throw new \RuntimeException('database went away'); }
        $this->unpublished[] = $ref;
        if ($this->unpublishSticks && isset($this->items[$ref])) { $this->items[$ref] = false; }
    }
}

final class TakedownSeamTest extends TestCase
{
    private FakeFeedbackDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        TakedownRegistry::reset();
        $this->db = new FakeFeedbackDb();
        $this->mockDatabase($this->db);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        TakedownRegistry::reset();
        $_SESSION = [];
        parent::tearDown();
    }

    private function report(int $id, array $abuse, string $kind = 'abuse'): void
    {
        $this->db->rows[$id] = ['kind' => $kind, 'status' => 'new', 'context' => json_encode(['abuse' => $abuse])];
    }

    private function takeDown(int $id): string
    {
        $_POST = [];
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => "/admin/site-feedback/$id/unpublish-page"];
        $req = Request::capture();
        $req->setParams([$id]);
        (new FeedbackAdminController())->unpublishPage($req);
        return (string) ($_SESSION['_flash']['success'] ?? $_SESSION['_flash']['error'] ?? '');
    }

    // ------------------------------------------------------------ resolution

    public function test_a_report_naming_its_source_goes_to_that_handler(): void
    {
        $pages = new FakePageTakedown();
        TakedownRegistry::register('page', $pages);
        TakedownRegistry::register('listing', new FakePageTakedown());

        $r = TakedownRegistry::resolve(['source' => 'page', 'ref' => 'abc123']);
        $this->assertSame($pages, $r['handler'] ?? null);
        $this->assertSame('abc123', $r['ref']);
    }

    public function test_an_old_report_with_only_a_code_is_owned_by_the_single_handler(): void
    {
        $pages = new FakePageTakedown();
        TakedownRegistry::register('page', $pages);
        $r = TakedownRegistry::resolve(['code' => 'abc123']);
        $this->assertSame($pages, $r['handler'] ?? null, 'reports filed before `source` existed must keep their takedown');
    }

    public function test_an_old_report_is_ambiguous_when_two_modules_could_own_it(): void
    {
        TakedownRegistry::register('page', new FakePageTakedown());
        TakedownRegistry::register('listing', new FakePageTakedown());
        $this->assertNull(TakedownRegistry::resolve(['code' => 'abc123']), 'guessing which module owns it could take down the wrong thing');
    }

    public function test_nothing_resolves_without_a_handler_or_a_ref(): void
    {
        $this->assertNull(TakedownRegistry::resolve(['source' => 'page', 'ref' => 'x']), 'no module registered');
        TakedownRegistry::register('page', new FakePageTakedown());
        $this->assertNull(TakedownRegistry::resolve(['source' => 'page']), 'a report that names no item takes nothing down');
        $this->assertNull(TakedownRegistry::resolve(['source' => 'nope', 'ref' => 'x']), 'an unknown source takes nothing down');
    }

    // ------------------------------------------------------------ the action

    public function test_a_takedown_goes_through_the_handler_and_is_read_back(): void
    {
        $pages = new FakePageTakedown();
        $pages->items['abc123'] = true;
        TakedownRegistry::register('page', $pages);
        $this->report(7, ['code' => 'abc123']);

        $msg = $this->takeDown(7);

        $this->assertSame(['abc123'], $pages->unpublished);
        $this->assertSame('Hosted page taken offline.', $msg);
        $this->assertSame('reviewed', $this->db->rows[7]['status'], 'a handled report is marked reviewed');
        foreach ($this->db->queries as [$sql]) {
            $this->assertStringNotContainsString('cp_newsletter_widgets', $sql, 'the feedback module must not touch another module\'s table');
        }
    }

    public function test_an_unpublish_that_did_not_stick_is_reported_as_failure(): void
    {
        $pages = new FakePageTakedown();
        $pages->items['abc123'] = true;
        $pages->unpublishSticks = false;
        TakedownRegistry::register('page', $pages);
        $this->report(7, ['code' => 'abc123']);

        $msg = $this->takeDown(7);

        $this->assertStringContainsString('still published', $msg, 'believing a takedown worked when it did not is the worst outcome');
        $this->assertSame('new', $this->db->rows[7]['status'], 'a failed takedown must not mark the report handled');
    }

    public function test_with_no_handler_the_action_says_so_and_changes_nothing(): void
    {
        $this->report(7, ['code' => 'abc123']);
        $msg = $this->takeDown(7);
        $this->assertStringContainsString('Nothing on this site can take that item down', $msg);
        $this->assertSame('new', $this->db->rows[7]['status']);
    }

    public function test_a_handler_error_is_reported_not_swallowed(): void
    {
        $pages = new FakePageTakedown();
        $pages->items['abc123'] = true;
        $pages->throws = true;
        TakedownRegistry::register('page', $pages);
        $this->report(7, ['code' => 'abc123']);

        $this->assertStringContainsString('Could not take it down: database went away', $this->takeDown(7));
        $this->assertSame('new', $this->db->rows[7]['status']);
    }

    public function test_only_an_abuse_report_can_trigger_a_takedown(): void
    {
        $pages = new FakePageTakedown();
        $pages->items['abc123'] = true;
        TakedownRegistry::register('page', $pages);
        $this->report(7, ['code' => 'abc123'], 'issue');

        $this->assertSame('That is not an abuse report.', $this->takeDown(7));
        $this->assertSame([], $pages->unpublished);
    }

    public function test_the_shared_module_names_no_other_modules_table(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BASE_PATH . '/modules/feedback', \FilesystemIterator::SKIP_DOTS)) as $f) {
            $code = (string) file_get_contents($f->getPathname());
            // Code only — the controller's comment records the history on purpose.
            $code = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $code);
            $this->assertStringNotContainsString('cp_newsletter_widgets', $code, $f->getFilename() . ' reaches into a table the feedback module does not own');
        }
    }
}
