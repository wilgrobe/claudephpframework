<?php
// core/Auth.php — thin alias so \Core\Auth resolves correctly
namespace Core;

/**
 * Alias: Core\Auth → Core\Auth\Auth
 * Routes and middleware use Core\Auth::check(), Core\Auth::getInstance(), etc.
 */
class Auth extends \Core\Auth\Auth {}
