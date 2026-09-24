<?php
namespace Tests\Unit\Modules\Settings;

use Tests\TestCase;

/**
 * A settings panel is offered in exactly two places, and they have to agree.
 *
 * Three panels configure OTHER modules -- Privacy, Content and Commerce -- and
 * an install without those modules was offered all three. The dead links were
 * not the worst of it: THE FORMS SAVED. "Show reviews on product pages" could
 * be switched on and confirmed with no product pages anywhere for it to
 * affect. The app agreed with you.
 *
 * So hiding the nav link is not the fix, and neither is dropping the route on
 * its own: one leaves a URL that still writes, the other leaves a link that
 * 404s. Both gates have to exist and they have to gate on the SAME modules --
 * which is a thing a test can hold, and nothing else here does.
 */
final class PanelsMatchTheirModulesTest extends TestCase
{
    private function navPath(): string    { return BASE_PATH . '/modules/settings/Views/admin/_nav.php'; }
    private function routesPath(): string { return BASE_PATH . '/modules/settings/routes.php'; }

    /** panel key => the modules its nav row names, for rows that name any. */
    private function navRequirements(): array
    {
        $src = (string) file_get_contents($this->navPath());
        $out = [];
        // ['commerce', 'Commerce', 'icon', 'description',
        //                                  ['store']],
        if (preg_match_all(
            "/\['([a-z_]+)',[^\]]*?\[([^\]]*)\]\],/s",
            $src,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $mods = array_values(array_filter(array_map(
                    static fn (string $s): string => trim($s, " \t\r\n'\""),
                    explode(',', $row[2])
                )));
                if ($mods) { $out[$row[1]] = $mods; }
            }
        }
        ksort($out);
        return $out;
    }

    /** panel key => the modules its route guard names. */
    private function routeRequirements(): array
    {
        $src = (string) file_get_contents($this->routesPath());
        $out = [];
        if (preg_match_all(
            '/settingsHasModule\(\[([^\]]*)\]\)\) \{(.*?)\n\}/s',
            $src,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $block) {
                $mods = array_values(array_filter(array_map(
                    static fn (string $s): string => trim($s, " \t\r\n'\""),
                    explode(',', $block[1])
                )));
                if (preg_match("#/admin/settings/([a-z_]+)'#", $block[2], $k)) { $out[$k[1]] = $mods; }
            }
        }
        ksort($out);
        return $out;
    }

    public function test_the_nav_and_the_route_gate_on_the_same_modules(): void
    {
        $nav    = $this->navRequirements();
        $routes = $this->routeRequirements();

        $this->assertNotSame([], $nav, 'No settings nav row names the modules it configures, so nothing is gated.');
        $this->assertNotSame([], $routes, 'No settings route is gated on a module, so every panel URL answers and saves.');

        $this->assertSame(
            array_keys($nav),
            array_keys($routes),
            'A settings panel is gated in one place and not the other. Hiding the link leaves a URL that '
            . 'still saves; dropping the route leaves a link that 404s. Both, or neither.'
        );

        foreach ($nav as $panel => $mods) {
            $this->assertSame(
                $mods,
                $routes[$panel],
                "The '$panel' panel's nav row and its route ask for different modules, so on some install "
                . 'one will show the panel while the other refuses it.'
            );
        }
    }

    /**
     * The three that were wrong. Named explicitly, because the test above is
     * satisfied by gating NOTHING in both places, and that is the state this
     * whole change exists to leave behind.
     */
    public function test_the_panels_that_configure_another_module_are_gated(): void
    {
        $nav = $this->navRequirements();

        foreach (['privacy', 'content', 'commerce'] as $panel) {
            $this->assertArrayHasKey(
                $panel,
                $nav,
                "The '$panel' settings panel no longer names the modules it configures, so it is offered on "
                . 'installs that have nothing for it to configure -- and its form saves there.'
            );
        }

        $this->assertSame(['store'], $nav['commerce'] ?? [], 'Commerce should ask for the store module.');
    }

    /** Whatever a row asks for has to be a real module, or the panel can never appear. */
    public function test_every_named_module_is_a_real_module_directory_name(): void
    {
        $known = [];
        foreach (glob(BASE_PATH . '/modules/*', GLOB_ONLYDIR) as $d) { $known[basename($d)] = true; }
        // The premium and framework trees are the other places a module can come from.
        foreach ([BASE_PATH . '/../claudephpframeworkpremium/modules', BASE_PATH . '/../claudephpframework/modules'] as $extra) {
            foreach (glob($extra . '/*', GLOB_ONLYDIR) ?: [] as $d) { $known[basename($d)] = true; }
        }
        $this->assertNotSame([], $known, 'No modules found at all -- the check below would pass vacuously.');

        foreach ($this->navRequirements() as $panel => $mods) {
            foreach ($mods as $m) {
                $this->assertArrayHasKey(
                    $m,
                    $known,
                    "The '$panel' panel is gated on a module called '$m', which is not a module directory "
                    . 'anywhere, so the panel can never be shown.'
                );
            }
        }
    }
}