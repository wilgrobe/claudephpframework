<?php
// modules/seo/routes.php
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RequireAdmin;

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
// The Search health report (21 Nov 2026): the live site checked the way a search engine and an AI crawler see it.
$router->post('/admin/settings/search/check', "$A@check", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
$router->post('/admin/settings/search/pages', "$A@savePages", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
// The title and description of any public address (24 Nov 2026): module pages have no SEO fields of their own.
$router->post('/admin/settings/search/text', "$A@saveText", [CsrfMiddleware::class, AuthMiddleware::class, RequireAdmin::class]);
