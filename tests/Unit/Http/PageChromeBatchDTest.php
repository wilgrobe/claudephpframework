<?php
// tests/Unit/Http/PageChromeBatchDTest.php
namespace Tests\Unit\Http;

use Tests\TestCase;

/**
 * Page-chrome Batch D aggregate regression test for the premium-repo
 * conversions. Same data-driven shape as PageChromeBatchCTest, but
 * pointed at the sibling premium checkout (C:\www\claudephpframeworkpremium
 * by default; configurable via the MODULE_PREMIUM_PATH env var the
 * core/Module/ModuleRegistry already supports).
 *
 * Skips cleanly when the premium repo isn't mounted (open-source-only
 * installs or CI workspaces without the premium checkout) so the core
 * suite stays green either way. Static-source assertions only — same
 * tripwire-style checks as Batch B/C: slug seeded by migration,
 * controller chains withLayout, view doesn't include header/footer.
 */
final class PageChromeBatchDTest extends TestCase
{
    /**
     * @return array<int, array{
     *   slug: string,
     *   migration: string,
     *   controller: string,
     *   view: string,
     *   needle: string,
     * }>
     */
    private static function surfaces(): array
    {
        return [
            [
                'slug'       => 'messages',
                'migration'  => 'modules/messaging/migrations/2026_05_02_700000_seed_messages_chrome.php',
                'controller' => 'modules/messaging/Controllers/MessagingController.php',
                'view'       => 'modules/messaging/Views/public/inbox.php',
                'needle'     => "->withLayout('messages')",
            ],
            [
                'slug'       => 'feed',
                'migration'  => 'modules/social/migrations/2026_05_02_710000_seed_feed_chrome.php',
                'controller' => 'modules/social/Controllers/FeedController.php',
                'view'       => 'modules/social/Views/public/feed.php',
                'needle'     => "->withLayout('feed')",
            ],
            [
                'slug'       => 'events',
                'migration'  => 'modules/events/migrations/2026_05_02_720000_seed_events_chrome.php',
                'controller' => 'modules/events/Controllers/EventController.php',
                'view'       => 'modules/events/Views/public/index.php',
                'needle'     => "->withLayout('events')",
            ],
            [
                'slug'       => 'kb',
                'migration'  => 'modules/knowledgebase/migrations/2026_05_02_730000_seed_kb_chrome.php',
                'controller' => 'modules/knowledgebase/Controllers/KnowledgeBaseController.php',
                'view'       => 'modules/knowledgebase/Views/public/index.php',
                'needle'     => "->withLayout('kb')",
            ],
            [
                'slug'       => 'support',
                'migration'  => 'modules/helpdesk/migrations/2026_05_02_740000_seed_support_chrome.php',
                'controller' => 'modules/helpdesk/Controllers/SupportController.php',
                'view'       => 'modules/helpdesk/Views/public/index.php',
                'needle'     => "->withLayout('support')",
            ],
            [
                'slug'       => 'groups',
                'migration'  => 'modules/groups/migrations/2026_05_02_750000_seed_groups_chrome.php',
                'controller' => 'modules/groups/Controllers/GroupController.php',
                'view'       => 'modules/groups/Views/my_groups.php',
                'needle'     => "->withLayout('groups')",
            ],
            [
                'slug'       => 'polls',
                'migration'  => 'modules/polls/migrations/2026_05_02_760000_seed_polls_chrome.php',
                'controller' => 'modules/polls/Controllers/PollController.php',
                'view'       => 'modules/polls/Views/public/index.php',
                'needle'     => "->withLayout('polls')",
            ],
            // Shop + orders share a single migration in modules/store/.
            [
                'slug'       => 'shop',
                'migration'  => 'modules/store/migrations/2026_05_02_770000_seed_shop_chrome.php',
                'controller' => 'modules/store/Controllers/ShopController.php',
                'view'       => 'modules/store/Views/public/index.php',
                'needle'     => "->withLayout('shop')",
            ],
            [
                'slug'       => 'orders',
                'migration'  => 'modules/store/migrations/2026_05_02_770000_seed_shop_chrome.php',
                'controller' => 'modules/store/Controllers/ShopController.php',
                'view'       => 'modules/store/Views/public/my_orders.php',
                'needle'     => "->withLayout('orders')",
            ],
            [
                'slug'       => 'billing',
                'migration'  => 'modules/subscriptions/migrations/2026_05_02_780000_seed_billing_chrome.php',
                'controller' => 'modules/subscriptions/Controllers/BillingController.php',
                'view'       => 'modules/subscriptions/Views/billing/index.php',
                'needle'     => "->withLayout('billing')",
            ],
        ];
    }

    /**
     * Resolve the premium-repo root. Honours MODULE_PREMIUM_PATH (the
     * env var ModuleRegistry already reads), then falls back to the
     * sibling-checkout convention `C:\www\claudephpframeworkpremium`.
     */
    private static function premiumRoot(): ?string
    {
        $env = (string) ($_ENV['MODULE_PREMIUM_PATH'] ?? getenv('MODULE_PREMIUM_PATH') ?: '');
        if ($env !== '' && is_dir($env)) return rtrim($env, '/\\');

        $sibling = dirname(BASE_PATH) . DIRECTORY_SEPARATOR . 'claudephpframeworkpremium';
        if (is_dir($sibling)) return $sibling;

        return null;
    }

    public function test_each_surface_seeds_slug_chains_withLayout_and_view_is_a_fragment(): void
    {
        $root = self::premiumRoot();
        if ($root === null) {
            // Premium repo not mounted — open-source-only install.
            // Skip cleanly so the core suite stays green. The shim's
            // assertTrue here serves as a noop assertion so the test
            // doesn't show as risky-with-no-assertions.
            $this->assertTrue(true,
                'Premium repo not available — skipping Batch D tripwires.');
            return;
        }

        foreach (self::surfaces() as $s) {
            $migPath = $root . '/' . $s['migration'];
            $ctlPath = $root . '/' . $s['controller'];
            $viewPath = $root . '/' . $s['view'];

            $this->assertFileExists($migPath, "Migration missing for slug `{$s['slug']}`");
            $this->assertFileExists($ctlPath, "Controller missing for slug `{$s['slug']}`");
            $this->assertFileExists($viewPath, "View missing for slug `{$s['slug']}`");

            $migSrc  = (string) file_get_contents($migPath);
            $ctlSrc  = (string) file_get_contents($ctlPath);
            $viewSrc = (string) file_get_contents($viewPath);

            $this->assertStringContainsString(
                "'{$s['slug']}'", $migSrc,
                "Migration `{$s['migration']}` must seed slug `{$s['slug']}`."
            );
            $this->assertStringContainsString(
                $s['needle'], $ctlSrc,
                "Controller `{$s['controller']}` must chain `{$s['needle']}`."
            );
            $this->assertFalse(
                str_contains($viewSrc, "/app/Views/layout/header.php"),
                "View `{$s['view']}` was converted to a fragment — re-adding the header.php "
                . 'include would double-wrap when chrome is active.'
            );
            $this->assertFalse(
                str_contains($viewSrc, "/app/Views/layout/footer.php"),
                "View `{$s['view']}` was converted to a fragment — re-adding the footer.php "
                . 'include would double-close the document.'
            );

            // Each migration must declare chromed_url so the admin
            // index can render the View ↗ button.
            $this->assertStringContainsString(
                "'chromed_url'", $migSrc,
                "Migration `{$s['migration']}` must pass chromed_url so /admin/system-layouts "
                . 'can show a "View ↗" button next to Edit.'
            );
        }
    }

    public function test_forms_show_was_intentionally_skipped(): void
    {
        // /forms/{slug} appears in the page-chrome plan's Batch D list
        // but the forms module's show.php has its own layout_style
        // mechanism (default / wide / minimal) that conditionally skips
        // header.php for landing-page-style embeds. Adding chrome on
        // top would conflict with that, so the conversion is deferred.
        // This test pins the decision so a future contributor doesn't
        // re-attempt the conversion and break the minimal-style embed.
        $root = self::premiumRoot();
        if ($root === null) {
            $this->assertTrue(true);
            return;
        }
        $ctlPath = $root . '/modules/forms/Controllers/FormController.php';
        if (!file_exists($ctlPath)) {
            $this->assertTrue(true);
            return;
        }
        $src = (string) file_get_contents($ctlPath);
        $this->assertFalse(
            str_contains($src, "->withLayout('forms.show')"),
            'forms/Controllers/FormController.php must NOT chain ->withLayout(\'forms.show\'). '
            . 'The forms module has its own per-form layout_style (default/wide/minimal); '
            . 'adding chrome on top would double-wrap the `default`/`wide` styles and '
            . 'break the `minimal` landing-page style. Convert in a future polish pass '
            . 'with explicit conditional-chrome logic if needed.'
        );
    }
}
