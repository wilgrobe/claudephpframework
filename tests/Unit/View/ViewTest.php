<?php
// tests/Unit/View/ViewTest.php
namespace Tests\Unit\View;

use Core\View;
use Tests\TestCase;

final class ViewTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = sys_get_temp_dir() . '/view_test_' . uniqid();
        mkdir("$this->fixtures/layouts", 0755, true);
        mkdir("$this->fixtures/components", 0755, true);
        View::setViewsPath($this->fixtures);

        file_put_contents("$this->fixtures/layouts/app.php",
            '<title><?= \Core\View::yield("title", "D") ?></title>' .
            '<main><?= \Core\View::yield("content") ?></main>');

        file_put_contents("$this->fixtures/page.php",
            '<?php \Core\View::extend("layouts.app"); ?>' .
            '<?php \Core\View::section("title", "Hi"); ?>' .
            '<?php \Core\View::section("content"); ?>body<?php \Core\View::endSection(); ?>');

        file_put_contents("$this->fixtures/plain.php", 'plain:<?= e($x) ?>');

        file_put_contents("$this->fixtures/components/alert.php",
            '<alert><?= e($msg) ?></alert>');
    }

    protected function tearDown(): void
    {
        // Clean up fixture tree
        $this->rrmdir($this->fixtures);
        View::setViewsPath('');
        parent::tearDown();
    }

    public function test_plain_view_renders_with_extracted_data(): void
    {
        $this->assertSame('plain:hello', View::render('plain', ['x' => 'hello']));
    }

    public function test_layout_with_sections_renders_correctly(): void
    {
        $out = View::render('page');
        $this->assertStringContainsString('<title>Hi</title>', $out);
        $this->assertStringContainsString('<main>body</main>', $out);
    }

    public function test_default_yield_used_when_section_missing(): void
    {
        file_put_contents("$this->fixtures/layouts/has_default.php",
            '[<?= \Core\View::yield("missing", "DEFAULT") ?>]');
        file_put_contents("$this->fixtures/child.php",
            '<?php \Core\View::extend("layouts.has_default"); ?>x');
        $this->assertSame('[DEFAULT]', View::render('child'));
    }

    public function test_endSection_without_section_throws(): void
    {
        $this->expectException(\LogicException::class);
        View::endSection();
    }

    public function test_component_renders_with_components_prefix(): void
    {
        $out = View::component('alert', ['msg' => 'hi']);
        $this->assertSame('<alert>hi</alert>', $out);
    }

    public function test_nested_renders_do_not_leak_state(): void
    {
        // page.php uses layout + sections; render it twice and confirm
        // no stale section bleed between calls.
        $a = View::render('page');
        $b = View::render('page');
        $this->assertSame($a, $b);
    }

    public function test_invalid_view_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        View::render('../etc/passwd');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = "$dir/$e";
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
