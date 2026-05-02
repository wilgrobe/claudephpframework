<?php
// modules/settings/routes.php
/** @var \Core\Router\Router $router */

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireSuperadmin;

$router->get ('/admin/settings',            'Modules\Settings\Controllers\SettingsController@index',
    [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/settings',            'Modules\Settings\Controllers\SettingsController@save',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/settings/delete',     'Modules\Settings\Controllers\SettingsController@delete',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);
$router->get ('/admin/settings/footer',     'Modules\Settings\Controllers\SettingsController@footer',
    [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/settings/footer',     'Modules\Settings\Controllers\SettingsController@saveFooter',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);
$router->get ('/admin/settings/groups',     'Modules\Settings\Controllers\SettingsController@groups',
    [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/settings/groups',     'Modules\Settings\Controllers\SettingsController@saveGroups',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);
$router->get ('/admin/settings/appearance', 'Modules\Settings\Controllers\SettingsController@appearance',
    [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/settings/appearance', 'Modules\Settings\Controllers\SettingsController@saveAppearance',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);
$router->get ('/admin/settings/security',   'Modules\Settings\Controllers\SettingsController@security',
    [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/settings/security',   'Modules\Settings\Controllers\SettingsController@saveSecurity',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);
$router->get ('/admin/settings/access',     'Modules\Settings\Controllers\SettingsController@access',
    [AuthMiddleware::class, RequireSuperadmin::class]);
$router->post('/admin/settings/access',     'Modules\Settings\Controllers\SettingsController@saveAccess',
    [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class]);

// ── New-shell panels (2026-05-01 hub redesign) ──────────────────────────
// Each panel renders the shared left-nav partial. Saves audit-log under
// settings.{panel}.save. Members is a composite — links to the existing
// access + groups editors which keep their own POST endpoints.
$auth     = [AuthMiddleware::class, RequireSuperadmin::class];
$authCsrf = [CsrfMiddleware::class, AuthMiddleware::class, RequireSuperadmin::class];

$router->get ('/admin/settings/general',      'Modules\Settings\Controllers\SettingsController@general',         $auth);
$router->post('/admin/settings/general',      'Modules\Settings\Controllers\SettingsController@saveGeneral',     $authCsrf);
$router->get ('/admin/settings/layout',       'Modules\Settings\Controllers\SettingsController@layout',          $auth);
$router->post('/admin/settings/layout',       'Modules\Settings\Controllers\SettingsController@saveLayout',      $authCsrf);
$router->get ('/admin/settings/members',      'Modules\Settings\Controllers\SettingsController@members',         $auth);
$router->get ('/admin/settings/privacy',      'Modules\Settings\Controllers\SettingsController@privacy',         $auth);
$router->post('/admin/settings/privacy',      'Modules\Settings\Controllers\SettingsController@savePrivacy',     $authCsrf);
$router->get ('/admin/settings/content',      'Modules\Settings\Controllers\SettingsController@content',         $auth);
$router->post('/admin/settings/content',      'Modules\Settings\Controllers\SettingsController@saveContent',     $authCsrf);
$router->get ('/admin/settings/commerce',     'Modules\Settings\Controllers\SettingsController@commerce',        $auth);
$router->post('/admin/settings/commerce',     'Modules\Settings\Controllers\SettingsController@saveCommerce',    $authCsrf);
$router->get ('/admin/settings/integrations', 'Modules\Settings\Controllers\SettingsController@integrations',    $auth);
$router->post('/admin/settings/integrations', 'Modules\Settings\Controllers\SettingsController@saveIntegrations',$authCsrf);
$router->get ('/admin/settings/other',        'Modules\Settings\Controllers\SettingsController@other',           $auth);
