<?php
// core/Services/FrameworkLinkSource.php
namespace Core\Services;

/**
 * Hardcoded list of well-known framework routes worth exposing in the
 * menu builder's palette. Curated rather than auto-introspected because
 * an automatic enumeration of every router-registered route would dump
 * /admin/*, /api/*, and dozens of internal endpoints into the picker
 * that nobody wants to link from a public menu.
 *
 * This list ships with the framework. Modules don't add to it - they
 * have their own LinkSource implementations.
 */
class FrameworkLinkSource implements LinkSource
{
    public function name(): string  { return 'framework'; }
    public function label(): string { return 'Built-in'; }

    public function items(): array
    {
        return [
            ['label' => 'Dashboard',         'url' => '/dashboard',        'icon' => null],
            ['label' => 'My Profile',        'url' => '/profile',          'icon' => null],
            ['label' => 'Active Sessions',   'url' => '/account/sessions', 'icon' => null],
            ['label' => 'Notifications',     'url' => '/notifications',    'icon' => null],
            ['label' => 'Search',            'url' => '/search',           'icon' => null],
            ['label' => 'Sign In',           'url' => '/login',            'icon' => null],
            ['label' => 'Sign Out',          'url' => '/logout',           'icon' => null],
            ['label' => 'Register',          'url' => '/register',         'icon' => null],
            ['label' => 'Forgot Password',   'url' => '/forgot-password',  'icon' => null],
        ];
    }
}
