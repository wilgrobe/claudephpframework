<?php
// modules/featureflags/Helpers/helpers.php
/**
 * Global helpers.
 *
 *   <?php if (feature('new_checkout')): ?>
 *     <!-- new UI -->
 *   <?php else: ?>
 *     <!-- old UI -->
 *   <?php endif; ?>
 *
 * `feature($key, $userId = null)` resolves for the current user by
 * default. Pass an explicit user id if you need to check for someone
 * other than the caller (e.g. admin preview).
 */

if (!function_exists('feature')) {
    function feature(string $key, ?int $userId = null): bool
    {
        if ($userId === null) {
            $auth = \Core\Auth\Auth::getInstance();
            $userId = $auth->guest() ? null : (int) $auth->id();
        }
        return (new \Modules\FeatureFlags\Services\FeatureFlagService())->isEnabled($key, $userId);
    }
}
