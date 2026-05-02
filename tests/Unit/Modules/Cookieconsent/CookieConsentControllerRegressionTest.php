<?php
// tests/Unit/Modules/Cookieconsent/CookieConsentControllerRegressionTest.php
namespace Tests\Unit\Modules\Cookieconsent;

use Tests\TestCase;

/**
 * Static regression test for the bug fixed 2026-05-01: the admin-index
 * controller called Database::fetchRow() (which doesn't exist; correct
 * method is fetchOne). The browser pass found this fatal first because
 * the path is rarely exercised.
 *
 * To make sure the typo never returns:
 * 1. The controller file must mention `fetchOne` and NOT `fetchRow`.
 * 2. The framework's Database class must continue to expose `fetchOne`
 *    (so this test catches a future rename of that primitive too).
 */
final class CookieConsentControllerRegressionTest extends TestCase
{
    public function test_controller_uses_fetchOne_not_fetchRow(): void
    {
        $path = BASE_PATH . '/modules/cookieconsent/Controllers/CookieConsentController.php';
        $this->assertFileExists($path);
        $src  = (string) file_get_contents($path);

        $this->assertStringNotContainsString(
            '->fetchRow(', $src,
            'CookieConsentController must not call Database::fetchRow() — that method does not exist. '
            . 'The 2026-05-01 fix was to use fetchOne(); regressing reintroduces the admin /admin/cookie-consent fatal.'
        );
        $this->assertStringContainsString('->fetchOne(', $src,
            'After the 2026-05-01 fix the stats query calls fetchOne; this assertion is a tripwire if anyone reverts.');
    }

    public function test_database_class_still_exposes_fetch_one(): void
    {
        $this->assertTrue(method_exists(\Core\Database\Database::class, 'fetchOne'),
            'Removing Database::fetchOne would re-break every site code path that depends on it.');
    }
}
