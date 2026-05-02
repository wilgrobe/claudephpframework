<?php
// tests/Unit/Module/CorePremiumIntegrityTest.php
namespace Tests\Unit\Module;

use Core\Container\Container;
use Core\Module\AlwaysGrantEntitlement;
use Core\Module\EntitlementCheck;
use Core\Module\ModuleProvider;
use Core\Module\ModuleRegistry;
use Tests\TestCase;

/**
 * Tier-integrity gate.
 *
 * Enforces the framework's strictest cross-tier rule: a CORE module must
 * never depend on a PREMIUM module. The reverse is fine; premium-on-core
 * is the normal direction.
 *
 * Why this matters: the open-source "core" repo ships standalone. Users
 * who clone only the core (no premium sibling) get a working install. If
 * any core module declared `requires(['store'])`, the moment such an
 * install boots without the premium repo present, the framework would
 * mark that core module as `disabled_dependency` and remove it from the
 * router — silently breaking the open-source experience.
 *
 * The test runs in TWO scenarios:
 *
 *   1. Core-only CI (most common — the open-source repo's CI runner only
 *      checked out the core repo). The hard gate compares core modules'
 *      requires() against the static KNOWN_PREMIUM_MODULES list below.
 *
 *   2. Local dev / paired CI (premium repo mounted as a sibling
 *      checkout). The hard gate still runs, AND a second test verifies
 *      KNOWN_PREMIUM_MODULES matches reality so the constant doesn't
 *      drift away from the live premium repo.
 *
 * Maintenance: when premium gains or loses a module, update
 * KNOWN_PREMIUM_MODULES below. The drift-detection test (when run with
 * premium mounted) will tell you exactly what to add or remove.
 */
final class CorePremiumIntegrityTest extends TestCase
{
    /**
     * Names — what each premium provider returns from name(), NOT folder
     * names — of every module that ships from claudephpframeworkpremium.
     *
     * Six modules in the framework have folder/name divergence:
     *   apikeys/api_keys, auditlogviewer/audit_log_viewer,
     *   featureflags/feature_flags, importexport/import_export,
     *   activityfeed/activity_feed, knowledgebase/knowledge_base.
     * Of those, two (activity_feed, knowledge_base) are premium and
     * appear here in their name() form.
     *
     * The list is sorted alphabetically. Keep it that way for diff sanity.
     */
    private const KNOWN_PREMIUM_MODULES = [
        'activity_feed',
        'block',
        'blog',
        'comments',
        'content',
        'coupons',
        'events',
        'forms',
        'groups',
        'helpdesk',
        'i18n',
        'invoicing',
        'knowledge_base',
        'messaging',
        'moderation',
        'polls',
        'reviews',
        'scheduling',
        'social',
        'store',
        'subscriptions',
    ];

    /**
     * The hard CI gate: no core module's requires() may reference any
     * name in KNOWN_PREMIUM_MODULES. Runs in every test environment,
     * with or without the premium repo mounted.
     */
    public function test_no_core_module_requires_a_premium_module(): void
    {
        $registry = $this->buildCoreOnlyRegistry();

        $violations = [];
        foreach ($registry->all() as $name => $provider) {
            // Premium modules requiring premium is fine; we only police
            // the core → premium direction.
            if ($provider->tier() !== 'core') continue;

            foreach ($provider->requires() as $depName) {
                if (in_array($depName, self::KNOWN_PREMIUM_MODULES, true)) {
                    $violations[] = "core module '$name' requires premium module '$depName'";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Tier integrity violation — core modules must not depend on premium modules:\n  - "
            . implode("\n  - ", $violations)
            . "\n\nFix options:\n"
            . "  (a) Reclassify the dependency as core (move it to claudephpframework/modules/ + tier()='core')\n"
            . "  (b) Remove the dependency from the core module (use class_exists() guards instead)\n"
            . "  (c) Reclassify the dependent module as premium (move it to claudephpframeworkpremium/)"
        );
    }

    /**
     * Sanity: also guard against a core module declaring tier()='premium'
     * by accident (e.g. someone copy-pasted from a premium provider).
     * This catches the inverse of the main rule — a "premium" module
     * sitting in the core repo would never load on tenants without an
     * EntitlementCheck binding.
     */
    public function test_no_module_in_core_repo_declares_premium_tier(): void
    {
        $registry = $this->buildCoreOnlyRegistry();

        $misclassified = [];
        foreach ($registry->all() as $name => $provider) {
            if ($provider->tier() === 'premium') {
                $misclassified[] = "$name (in core repo, declares tier()='premium')";
            }
        }

        $this->assertSame(
            [],
            $misclassified,
            "Misclassified modules in the core repo:\n  - " . implode("\n  - ", $misclassified)
        );
    }

    /**
     * Drift detection — only runs when the premium repo is mounted as a
     * sibling checkout (typical local dev). Skipped silently in pure
     * core-only CI. Catches: a new premium module added to the premium
     * repo without updating KNOWN_PREMIUM_MODULES, or a premium module
     * removed without updating the constant.
     */
    public function test_known_premium_list_matches_premium_repo_when_mounted(): void
    {
        $premiumPath = realpath(BASE_PATH . '/../claudephpframeworkpremium/modules');
        if ($premiumPath === false || !is_dir($premiumPath)) {
            $this->markTestSkipped(
                'premium repo not mounted as sibling checkout — skipping drift detection. '
                . 'This is expected in core-only CI runs.'
            );
        }

        $registry = $this->buildRegistryForRoot($premiumPath);

        $actual = [];
        foreach ($registry->all() as $name => $provider) {
            // Defensive: a module in the premium repo MUST declare
            // tier()='premium'. If not, it'd be ambiguous whether to
            // include it in the list. The earlier test asserts the
            // inverse for the core repo; this assertion asserts it
            // for the premium repo when present.
            $this->assertSame(
                'premium',
                $provider->tier(),
                "Module '$name' lives in claudephpframeworkpremium but declares tier()='" . $provider->tier() . "'"
            );
            $actual[] = $name;
        }
        sort($actual);

        $expected = self::KNOWN_PREMIUM_MODULES;
        sort($expected);

        $this->assertSame(
            $expected,
            $actual,
            "KNOWN_PREMIUM_MODULES is out of sync with the premium repo on disk.\n"
            . "Update the constant in this file to match the names listed above. "
            . "Then commit the change to the core repo so CI stays green."
        );
    }

    /**
     * Build a registry that has discovered ONLY the core repo's
     * modules/. Used by the hard gate so the result doesn't depend on
     * whether the premium repo happens to be mounted in this test
     * environment — the gate must produce identical results in
     * core-only CI and in fully-paired local dev.
     */
    private function buildCoreOnlyRegistry(): ModuleRegistry
    {
        $container = new Container();
        Container::setGlobal($container);
        $container->instance(EntitlementCheck::class, new AlwaysGrantEntitlement());

        $registry = new ModuleRegistry($container);
        $registry->discover(BASE_PATH . '/modules');
        return $registry;
    }

    /**
     * Build a registry that has discovered ONE specific root. Same
     * pattern as buildCoreOnlyRegistry() but parameterised so the
     * drift-detection test can scan the premium root in isolation.
     */
    private function buildRegistryForRoot(string $root): ModuleRegistry
    {
        $container = new Container();
        Container::setGlobal($container);
        $container->instance(EntitlementCheck::class, new AlwaysGrantEntitlement());

        $registry = new ModuleRegistry($container);
        $registry->discover($root);
        return $registry;
    }
}
