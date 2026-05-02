<?php
// tests/Unit/Services/MailServiceLogDriverTest.php
namespace Tests\Unit\Services;

use Core\Database\Database;
use Core\Services\MailService;
use Tests\TestCase;

/**
 * The 'log' MAIL_DRIVER appends each send to storage/logs/mail.log.
 * Verifies (a) the file is created on first send, (b) banner-delimited
 * blocks are appended in order, (c) recipient + subject + HTML survive.
 *
 * The doSend() path isn't directly exposed; we drive it through the
 * full send() pipeline which writes a row to message_log first. We
 * stub Database so no real DB is needed.
 */
final class FakeMailDb extends Database
{
    public array $inserts = [];
    public array $updates = [];

    public function __construct() { /* skip parent */ }

    public function insert(string $table, array $data): int
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return count($this->inserts);
    }

    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        // currentAttempts() reads attempts; pretend we're on attempt 0.
        return ['attempts' => 0, 'max_attempts' => 3];
    }

    public function update(string $table, array $data, string $where = '', array $whereBindings = []): int
    {
        $this->updates[] = ['table' => $table, 'data' => $data];
        return 1;
    }
}

final class MailServiceLogDriverTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockDatabase(new FakeMailDb());

        // Force the log driver via the env. IntegrationConfig::config('email')
        // reads MAIL_DRIVER + MAIL_FROM_*; set the minimum needed.
        $_ENV['MAIL_DRIVER']       = 'log';
        $_ENV['MAIL_FROM_ADDRESS'] = 'noreply@test.local';
        $_ENV['MAIL_FROM_NAME']    = 'Test App';
        $_ENV['APP_NAME']          = 'TestApp';

        $this->logPath = BASE_PATH . '/storage/logs/mail.log';
        @unlink($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        unset($_ENV['MAIL_DRIVER']);
        parent::tearDown();
    }

    public function test_log_driver_creates_file_and_writes_banner(): void
    {
        $svc = new MailService();
        $ok = $svc->send('alice@test.local', 'Hello', '<p>Body</p>', 'Body');
        $this->assertTrue($ok);
        $this->assertFileExists($this->logPath);

        $contents = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('========',           $contents);
        $this->assertStringContainsString('To:      alice@test.local', $contents);
        $this->assertStringContainsString('Subject: Hello',      $contents);
        $this->assertStringContainsString('<p>Body</p>',        $contents);
        $this->assertStringContainsString('MAIL_DRIVER=log',    $contents);
    }

    public function test_log_driver_appends_subsequent_sends(): void
    {
        $svc = new MailService();
        $svc->send('a@test.local', 'First',  '<p>1</p>');
        $svc->send('b@test.local', 'Second', '<p>2</p>');

        $contents = (string) file_get_contents($this->logPath);
        $this->assertSame(2, substr_count($contents, 'MAIL_DRIVER=log'));
        $this->assertStringContainsString('To:      a@test.local', $contents);
        $this->assertStringContainsString('To:      b@test.local', $contents);
        // Order preserved.
        $this->assertLessThan(
            strpos($contents, 'b@test.local'),
            strpos($contents, 'a@test.local'),
            'Earlier sends should appear earlier in the log.'
        );
    }

    public function test_log_driver_marks_message_log_row_sent(): void
    {
        $db = new FakeMailDb();
        $this->mockDatabase($db);
        $svc = new MailService();
        $svc->send('c@test.local', 'Hi', '<p>x</p>');

        // First update on the log row should mark status=sent.
        $this->assertNotEmpty($db->updates);
        $this->assertSame('sent', $db->updates[0]['data']['status']);
    }
}
