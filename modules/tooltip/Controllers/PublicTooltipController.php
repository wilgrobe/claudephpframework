<?php
// modules/tooltip/Controllers/PublicTooltipController.php
namespace Modules\Tooltip\Controllers;

use Core\Auth\RateLimiter;
use Core\Module\SubmoduleRegistry;
use Core\Request;
use Core\Response;
use Modules\Tooltip\Services\TooltipRenderer;
use Modules\Tooltip\Services\TooltipService;

/**
 * Public JSON surface — content fetch (for trigger=manual JS callers) and the
 * analytics tracking ping.
 *   GET  /api/tooltips/{slug}        — JSON content (active only)
 *   POST /api/tooltips/{slug}/track  — analytics ping (gated + rate-limited)
 */
class PublicTooltipController
{
    public function show(Request $request): Response
    {
        $slug = (string) $request->param(0);
        $tip = (new TooltipService())->get($slug, $this->context($request));
        if (!$tip) return Response::json(['error' => 'not_found'], 404);

        $content = (new TooltipRenderer())->content($tip, $tip['_content'] ?? null);
        return Response::json([
            'slug'         => (string) $tip['slug'],
            'label'        => (string) $tip['label'],
            'content'      => $content,
            'placement'    => (string) $tip['placement'],
            'theme'        => (string) $tip['theme'],
            'trigger'      => (string) $tip['trigger'],
            'max_width_px' => (int) $tip['max_width_px'],
        ]);
    }

    public function track(Request $request): Response
    {
        if (!SubmoduleRegistry::featureEnabled('tooltip', 'analytics')) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $slug = (string) $request->param(0);
        $ip = $request->ip();
        $limiter = new RateLimiter();
        $key = 'tooltip-track:' . $slug;
        if ($limiter->tooManyAttempts($key, $ip)) {
            return Response::json(['error' => 'rate_limited'], 429);
        }
        $limiter->hit($key, $ip);
        $ok = (new TooltipService())->track($slug, 'view');
        return Response::json(['ok' => $ok]);
    }

    /** @return array{page:string,route:string,roles:string[]} */
    private function context(Request $request): array
    {
        return [
            'page'  => (string) $request->query('page', ''),
            'route' => (string) $request->query('route', ''),
            'roles' => [],
        ];
    }
}
