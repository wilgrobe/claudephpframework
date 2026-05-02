<?php
// core/Module/AlwaysGrantEntitlement.php
namespace Core\Module;

/**
 * Default EntitlementCheck implementation: grants every premium module
 * that is physically on disk.
 *
 * This is the right behaviour for self-hosted installs and for local
 * development against the paired claudephpframeworkpremium checkout.
 * If you have the files, you have the licence.
 *
 * The hosted web-app builder swaps in a tenant-aware implementation
 * (see EntitlementCheck docblock) by binding a different concrete to
 * the EntitlementCheck contract in config/services.php.
 */
final class AlwaysGrantEntitlement implements EntitlementCheck
{
    public function isEntitled(string $moduleName): bool
    {
        return true;
    }
}
