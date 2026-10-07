<?php
// tests/Unit/View/SystemLayoutRowTest.php
namespace Tests\Unit\View;

use Tests\TestCase;

/**
 * Board #407 (2026-10-07): the system-layout editor could not load at all on PHP 8. Its two <template> rows
 * are rendered with $i = '__INDEX__' (the page script swaps in the real index when a row is added), and the
 * row partial printed `$i + 1` in its aria labels — a TypeError for a non-numeric string on PHP 8. Every
 * /admin/system-layouts/{name} page died before reaching the form. The file is shared byte-for-byte with the
 * framework; this test is in both repos.
 */
final class SystemLayoutRowTest extends TestCase
{
    private function render(int|string $i): string
    {
        $p = ['row_index' => 0, 'col_index' => 1, 'sort_order' => 2, 'placement_type' => 'block',
              'block_key' => 'bookshelf.my_books', 'settings' => [], 'visible_to' => 'any'];
        ob_start();
        try {
            include BASE_PATH . '/app/Views/admin/system_layouts/_layout_row.php';
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    public function test_the_template_row_renders_with_its_placeholder_index(): void
    {
        $html = $this->render('__INDEX__');   // threw TypeError before the fix

        $this->assertStringContainsString('name="placements[__INDEX__][row]"', $html);
        $this->assertStringContainsString('aria-label="Placement __INDEX__ kind"', $html,
            'the template row must keep the placeholder for the page script to replace');
    }

    public function test_a_real_row_numbers_itself_from_one(): void
    {
        $html = $this->render(2);

        $this->assertStringContainsString('name="placements[2][row]"', $html);
        $this->assertStringContainsString('aria-label="Placement 3 kind"', $html);
        $this->assertStringNotContainsString('__INDEX__', $html);
    }
}
