<?php
// tests/Unit/Modules/Forms/FormServiceScheduledExportsTest.php
namespace Tests\Unit\Modules\Forms;

use Core\Database\Database;
use Modules\Forms\Services\FormService;
use Tests\TestCase;

/**
 * dispatchScheduledExports walks every enabled form whose settings JSON
 * contains a non-empty export_schedule, computes "is this period due
 * since export_last_run_at?", and emails the recipients. Stamps
 * export_last_run_at after a dispatch so re-runs skip until the next
 * period elapses. We mock both the DB (for form rows + the post-dispatch
 * UPDATE) and observe MailService through Database insert calls.
 */
final class FakeExportDb extends Database
{
    public array $forms     = [];
    public array $emails    = []; // MailService::send → message_log inserts
    public array $updates   = [];
    public array $rowCounts = [];

    public function __construct() { /* skip parent */ }

    public function fetchAll(string $sql, array $bindings = []): array
    {
        if (str_contains($sql, 'FROM forms')) return $this->forms;
        return [];
    }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        if (str_contains($sql, 'FROM message_log')) {
            return ['attempts' => 0, 'max_attempts' => 3];
        }
        if (str_contains($sql, 'FROM form_submissions')) return null;
        return null;
    }

    public function fetchColumn(string $sql, array $bindings = [], int $col = 0): mixed
    {
        // dispatchScheduledExports counts submissions in the period.
        return 0;
    }

    public function insert(string $table, array $data): int
    {
        if ($table === 'message_log') {
            $this->emails[] = $data;
        }
        return 1;
    }

    public function update(string $table, array $data, string $where = '', array $whereBindings = []): int
    {
        $this->updates[] = ['table' => $table, 'data' => $data];
        return 1;
    }
}

final class FormServiceScheduledExportsTest extends TestCase
{
    private FakeExportDb $db;
    private FormService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeExportDb();
        $this->mockDatabase($this->db);
        $this->svc = new FormService();

        // Set the mail driver to log so we don't try real SMTP.
        $_ENV['MAIL_DRIVER'] = 'log';
        $_ENV['APP_NAME']    = 'TestApp';
        $_ENV['APP_URL']     = 'http://example.test';
        @unlink(BASE_PATH . '/storage/logs/mail.log');
    }

    protected function tearDown(): void
    {
        @unlink(BASE_PATH . '/storage/logs/mail.log');
        unset($_ENV['MAIL_DRIVER']);
        parent::tearDown();
    }

    public function test_skips_forms_without_export_schedule(): void
    {
        $this->db->forms = [[
            'id'       => 1,
            'name'     => 'Contact Us',
            'slug'     => 'contact-us',
            'settings' => json_encode([]),
        ]];
        $stats = $this->svc->dispatchScheduledExports();
        $this->assertSame(0, $stats['forms']);
        $this->assertSame(0, $stats['dispatched']);
    }

    public function test_skips_when_no_recipients(): void
    {
        $this->db->forms = [[
            'id'       => 1,
            'name'     => 'Daily',
            'slug'     => 'daily',
            'settings' => json_encode(['export_schedule' => 'daily', 'export_recipients' => '']),
        ]];
        $stats = $this->svc->dispatchScheduledExports();
        $this->assertSame(0, $stats['forms']);
    }

    public function test_dispatches_when_due_and_marks_last_run(): void
    {
        $this->db->forms = [[
            'id'       => 7,
            'name'     => 'Weekly Survey',
            'slug'     => 'weekly-survey',
            'settings' => json_encode([
                'export_schedule'   => 'weekly',
                'export_recipients' => 'analytics@example.test',
                // no export_last_run_at → first run, due immediately
            ]),
        ]];
        $stats = $this->svc->dispatchScheduledExports();
        $this->assertSame(1,  $stats['forms']);
        $this->assertSame(1,  $stats['dispatched']);
        $this->assertCount(1, $this->db->emails, 'One email per recipient');
        $this->assertSame('analytics@example.test', $this->db->emails[0]['recipient']);
        // Form's settings updated with export_last_run_at. MailService also
        // writes a message_log status update during send, so we filter to
        // just the forms-table updates rather than indexing [0] blindly.
        $formUpdates = array_values(array_filter($this->db->updates,
            fn($u) => $u['table'] === 'forms'));
        $this->assertNotEmpty($formUpdates, 'dispatch should stamp the form settings');
        $newSettings = json_decode($formUpdates[0]['data']['settings'], true);
        $this->assertIsArray($newSettings);
        $this->assertArrayHasKey('export_last_run_at', $newSettings);
    }

    public function test_skips_when_period_has_not_elapsed(): void
    {
        $this->db->forms = [[
            'id'       => 9,
            'name'     => 'Monthly',
            'slug'     => 'monthly',
            'settings' => json_encode([
                'export_schedule'    => 'monthly',
                'export_recipients'  => 'a@b.c',
                'export_last_run_at' => date('Y-m-d H:i:s', time() - 3600), // 1h ago
            ]),
        ]];
        $stats = $this->svc->dispatchScheduledExports();
        $this->assertSame(1, $stats['forms']);
        $this->assertSame(0, $stats['dispatched']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertEmpty($this->db->emails);
    }

    public function test_invalid_recipients_are_filtered_out(): void
    {
        $this->db->forms = [[
            'id'       => 11,
            'name'     => 'Multi-recipient',
            'slug'     => 'multi',
            'settings' => json_encode([
                'export_schedule'   => 'daily',
                'export_recipients' => 'good@example.test, not-an-email, also@valid.io;,',
            ]),
        ]];
        $this->svc->dispatchScheduledExports();
        $this->assertCount(2, $this->db->emails,
            'Only well-formed addresses survive the filter — not-an-email is dropped silently.');
    }
}
