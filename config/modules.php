<?php
// config/modules.php
/**
 * Module discovery configuration.
 *
 * The framework's ModuleRegistry can discover modules across multiple
 * roots. Roots are scanned in the order listed; if the same module
 * name appears in two roots, the FIRST root wins (and the duplicate
 * logs a warning to error_log).
 *
 * Conventional layout for a paired install:
 *
 *   <core repo>/modules/                    ← always present
 *   <premium repo>/modules/                 ← optional sibling checkout
 *
 * The premium root is read from MODULE_PREMIUM_PATH in .env so that
 * production deploys can point at a vendored copy, while local dev
 * uses the sibling repository convention. If the env var is unset OR
 * the path doesn't exist, the registry simply skips it — booting on
 * core alone is the open-source fallback and a tested code path.
 *
 * For a deploy that vendors premium modules into the core tree (e.g.
 * a single-tarball release), set MODULE_PREMIUM_PATH to an absolute
 * path inside the core checkout, or leave it empty and copy premium
 * modules directly into core/modules/. Both work.
 */

$base = defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/..';

// Default premium location: a sibling checkout. Tunable via env so a
// production server with a non-standard layout (e.g. /opt/cphpf-core
// + /opt/cphpf-premium) doesn't have to edit code.
$premium = $_ENV['MODULE_PREMIUM_PATH']
    ?? getenv('MODULE_PREMIUM_PATH')
    ?: realpath($base . '/../claudephpframeworkpremium/modules');

$paths = [
    $base . '/modules',
];
// Only register the premium root when it resolves to a real string.
// realpath() returns false for non-existent paths; skipping false here
// keeps the discovery path clean for core-only installs.
if (is_string($premium) && $premium !== '') {
    $paths[] = $premium;
}

return [
    /**
     * Ordered list of absolute directories the registry scans for
     * module.php files. The first match for any given module name
     * wins; later duplicates are ignored with an error_log warning.
     *
     * @var string[]
     */
    'paths' => $paths,

    /**
     * Where the deploy-time manifest is written by `php artisan module:cache`.
     * Relative to BASE_PATH. Override only if your storage/ layout differs
     * from the default.
     */
    'cache_file' => 'storage/cache/modules.php',
];
