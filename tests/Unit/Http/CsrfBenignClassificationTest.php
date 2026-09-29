<?php
// tests/Unit/Http/CsrfBenignClassificationTest.php
namespace Tests\Unit\Http;

use Tests\TestCase;

/**
 * A guest's stale sign-in form is not an attack, and must not be logged as one.
 *
 * The health check counts `security.*` and `auth.failed*` audit rows as blocked
 * attempts; a WARNING texts and emails the owner. Before this classification,
 * every owner whose own session timed out got paged when they signed back in —
 * and an alert that fires on the owner's routine behaviour is one they learn to
 * ignore. Built on MarketOtter, carried by the Builder, and brought into the
 * framework 2026-09-29 because generated sites take this file from here (#390).
 *
 * Nothing else fails if a merge drops it, so this pins its shape.
 */
final class CsrfBenignClassificationTest extends TestCase
{
    private function src(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(BASE_PATH . '/app/Middleware/CsrfMiddleware.php'));
    }

    public function test_benign_requires_a_browser_a_guest_form_and_no_signed_in_user(): void
    {
        $this->assertStringContainsString('$benign = !$isAjax && $onForm && !$signedIn;', $this->src(),
            'benign only when ALL hold — any AJAX caller, any other path, or a signed-in user stays a security event');
    }

    public function test_the_benign_case_logs_under_a_prefix_the_health_check_does_not_count(): void
    {
        $src = $this->src();
        $this->assertStringContainsString("\$benign ? 'auth.csrf_refreshed' : 'security.csrf_mismatch'", $src);
        $this->assertStringNotContainsString("auditLog('security.csrf_mismatch'", $src,
            'an unconditional security.csrf_mismatch would page the owner for their own expired login form');
        $this->assertStringStartsNotWith('security.', 'auth.csrf_refreshed');
        $this->assertStringStartsNotWith('auth.failed', 'auth.csrf_refreshed');
    }
}
