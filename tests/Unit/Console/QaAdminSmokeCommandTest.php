<?php
// tests/Unit/Console/QaAdminSmokeCommandTest.php
namespace Tests\Unit\Console;

use Core\Console\Commands\QaAdminSmokeCommand;
use Core\Container\Container;
use Tests\TestCase;

/**
 * Coverage for the qa:admin-smoke command's pure helpers + name/desc
 * contract. The full handle() flow needs a live container + router +
 * DB fixtures, which is integration territory. This unit test pins
 * the command's identity + flag-parsing + ensures the class loads.
 */
final class QaAdminSmokeCommandTest extends TestCase
{
    private QaAdminSmokeCommand $cmd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cmd = new QaAdminSmokeCommand(new Container());
    }

    public function test_name_and_description(): void
    {
        $this->assertSame('qa:admin-smoke', $this->cmd->name());
        $this->assertNotEmpty($this->cmd->description());
    }

    public function test_flag_helper_extracts_value(): void
    {
        // PHP 8.1+: private/protected members are reflectively accessible
        // by default — setAccessible(true) is a no-op and was deprecated
        // in 8.5. Just construct the ReflectionMethod and call invoke().
        $r = new \ReflectionMethod(QaAdminSmokeCommand::class, 'flag');
        $this->assertSame(
            'admin@example.com',
            $r->invoke($this->cmd, ['--user=admin@example.com', '--json'], '--user')
        );
        $this->assertNull($r->invoke($this->cmd, ['--json', '--include-params'], '--user'));
    }
}
