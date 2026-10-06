<?php
namespace Tests\Unit\Http;

use Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * Response::view(..., $status): a not-found page rendered as a view must answer 404, not 200.
 * Before 2026-10-06 the third argument was silently dropped, so policies/gdpr/ccpa "not found"
 * pages answered 200. Uses a throwaway view (the real errors/404 renders the layout, which needs a DB).
 */
final class ViewStatusTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__, 3));
        $this->file = BASE_PATH . '/app/Views/__view_status_probe.php';
        file_put_contents($this->file, '<p>probe</p>');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_a_view_keeps_the_status_it_is_given(): void
    {
        $r = Response::view('__view_status_probe', [], 404);
        $this->assertSame(404, $r->status());
    }

    public function test_the_default_is_still_200(): void
    {
        $this->assertSame(200, Response::view('__view_status_probe')->status());
    }
}
