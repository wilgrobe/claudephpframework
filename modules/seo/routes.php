<?php
// modules/seo/routes.php
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

// The Builder's own apex host keeps its own robots/sitemap (moving it onto this module is phase 5 of the plan).
if (class_exists(\App\Tenancy\Tenant::class) && \App\Tenancy\Tenant::current() === null) {
    return;
}

$S = 'Modules\Seo\Controllers\SearchController';
// ⚠ Module routes load before routes/web.php, and the router takes the FIRST exact match, so these answer
// /robots.txt and /sitemap.xml instead of App\Controllers\SitemapController (which they call for the body).
$router->get('/robots.txt',       "$S@robots");
$router->get('/sitemap.xml',      "$S@sitemap");
$router->get('/llms.txt',         "$S@llms");
$router->get('/indexnow-key.txt', "$S@indexNowKey");

$A = 'Modules\Seo\Controllers\SearchSettingsController';
$router->get ('/admin/settings/search', "$A@show", [AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/settings/search', "$A@save", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
