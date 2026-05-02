<?php
// core/Module/DependencyChecker.php
namespace Core\Module;

/**
 * Resolves module-to-module dependencies declared via ModuleProvider::requires().
 *
 * Algorithm (iterative removal until stable):
 *   1. Start with the full set of discovered module names.
 *   2. For each module still in the set, check that every name in its
 *      requires() is also in the set. If not, remove the module and note
 *      WHICH dependencies were missing.
 *   3. Repeat until a full pass makes no removals — handles transitive
 *      breakage (A requires B, B requires C, C is missing → both A and B
 *      get disabled, A's missing list will say "B" since that's what A
 *      directly needed).
 *
 * The result split is mechanical:
 *   - `active`  — modules whose entire dep tree is satisfied
 *   - `skipped` — modules with at least one missing direct dep, plus the
 *                 list of names that were missing (for SA-facing display)
 *
 * The check is pure: no I/O, no DB, no logging. Persistence + notification
 * happens in ModuleRegistry::loadAll() based on the result.
 */
class DependencyChecker
{
    /**
     * @param array<string, ModuleProvider> $providers  moduleName => provider
     * @return array{
     *     active:  array<string, ModuleProvider>,
     *     skipped: array<string, array{provider: ModuleProvider, missing: string[]}>,
     * }
     */
    public function check(array $providers): array
    {
        $active  = $providers;
        $skipped = [];

        // Iterate until a full pass produces no removals. Bounded by
        // count($providers) — each pass either removes ≥1 or terminates.
        $limit = count($providers) + 1;
        for ($i = 0; $i < $limit; $i++) {
            $availableNames = array_keys($active);
            $availableSet   = array_flip($availableNames);

            $removedThisPass = [];
            foreach ($active as $name => $provider) {
                $needs   = $provider->requires();
                $missing = [];
                foreach ($needs as $req) {
                    if (!isset($availableSet[$req])) {
                        $missing[] = (string) $req;
                    }
                }
                if (!empty($missing)) {
                    $skipped[$name] = [
                        'provider' => $provider,
                        'missing'  => $missing,
                    ];
                    $removedThisPass[] = $name;
                }
            }

            if (empty($removedThisPass)) break;

            foreach ($removedThisPass as $name) {
                unset($active[$name]);
            }
        }

        return [
            'active'  => $active,
            'skipped' => $skipped,
        ];
    }
}
