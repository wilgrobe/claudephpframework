<?php
// modules/tooltip/Services/TooltipRenderer.php
namespace Modules\Tooltip\Services;

use Core\Module\SubmoduleRegistry;
use Core\Support\Markdown;
use Core\Validation\Validator;

/**
 * Shared HTML emission for tooltips.
 *
 * Emits a `.tt-wrap` wrapper carrying a `.tt-trigger` and a `.tt-bubble`
 * (role="tooltip", aria-describedby linkage). The CSS (6 themes × 4 placements)
 * and the vanilla widget JS are emitted ONCE per page via a static guard so N
 * tooltips on a page don't duplicate the asset block (and the JS uses a single
 * shared listener per event type — Phase 43.185 leak-avoidance).
 *
 * Content rendering honours the `rich-content` submodule: when off, every
 * stored format collapses to escaped plain text; when on, markdown is rendered
 * and HTML is sanitised through the framework allowlist (Validator::sanitizeHtml).
 */
class TooltipRenderer
{
    private static bool $assetsEmitted = false;
    private static int $seq = 0;

    /** Reset the once-per-page guard (test harnesses). */
    public static function reset(): void { self::$assetsEmitted = false; self::$seq = 0; }

    /**
     * Convert a tooltip's raw source to safe display HTML.
     * @param array       $tip      the tooltip row
     * @param string|null $override raw source from a matching override (or null)
     */
    public function content(array $tip, ?string $override = null): string
    {
        $raw = (string) ($override ?? $tip['content_html'] ?? '');
        if (trim($raw) === '') return '';
        $format = (string) ($tip['content_format'] ?? 'markdown');

        if (!SubmoduleRegistry::featureEnabled('tooltip', 'rich-content')) {
            // Plain mode: no tags survive, newlines become <br>.
            return nl2br(htmlspecialchars(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $html = match ($format) {
            'plain' => nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'html'  => Validator::sanitizeHtml($raw),
            default => Validator::sanitizeHtml(Markdown::render($raw)),   // markdown
        };
        return $this->inlineSafe($html);
    }

    /**
     * Bubble content lives inside a `<span>` that may be embedded in prose (a
     * `<p>`), so it must be phrasing content. Markdown wraps paragraphs in
     * block `<p>` — collapse paragraph breaks to `<br><br>` and strip the outer
     * wrapper so the bubble stays inline-safe (an unwrapped block element would
     * make the browser parser relocate the bubble's content out of the span).
     */
    private function inlineSafe(string $html): string
    {
        if ($html === '') return '';
        $html = preg_replace('#</p>\s*<p[^>]*>#i', '<br><br>', $html) ?? $html;
        $html = preg_replace('#^\s*<p[^>]*>#i', '', $html) ?? $html;
        $html = preg_replace('#</p>\s*$#i', '', $html) ?? $html;
        return trim($html);
    }

    /**
     * Inline tooltip: a trigger span followed by its bubble, suitable for
     * dropping inside prose. $opts may override icon / trigger_text styling.
     */
    public function renderInline(array $tip, string $triggerText, array $opts = []): string
    {
        $bubbleId = $this->bubbleId((string) $tip['slug']);
        $icon = (string) ($opts['icon'] ?? '');
        $iconHtml = ($icon !== '' && $icon !== 'none')
            ? '<sup class="tt-icon" aria-hidden="true">' . htmlspecialchars($icon, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</sup>'
            : '';
        $triggerHtml = '<span class="tt-trigger" tabindex="0" role="button" aria-describedby="' . $bubbleId . '">'
            . htmlspecialchars($triggerText, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $iconHtml . '</span>';

        return $this->wrap($tip, $bubbleId, $triggerHtml, (string) ($opts['content'] ?? $this->content($tip)));
    }

    /**
     * Standalone help button (icon-only by default) with its tooltip — useful
     * next to a form field label.
     */
    public function renderHelpButton(array $tip, array $opts = []): string
    {
        $bubbleId = $this->bubbleId((string) $tip['slug']);
        $icon = (string) ($opts['icon'] ?? '?');
        if ($icon === 'none' || $icon === '') $icon = '?';
        $label = (string) ($opts['button_label'] ?? '');
        $labelHtml = $label !== '' ? '<span class="tt-help-label">' . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span> ' : '';
        $aria = $label !== '' ? htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Help';
        $triggerHtml = $labelHtml . '<button type="button" class="tt-trigger tt-help-btn" aria-label="' . $aria
            . '" aria-describedby="' . $bubbleId . '">' . htmlspecialchars($icon, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</button>';

        return $this->wrap($tip, $bubbleId, $triggerHtml, (string) ($opts['content'] ?? $this->content($tip)));
    }

    /** Build the .tt-wrap wrapper + bubble + the once-per-page asset block. */
    private function wrap(array $tip, string $bubbleId, string $triggerHtml, string $contentHtml): string
    {
        if (trim($contentHtml) === '') return '';   // graceful no-show on empty content

        $placement = $this->enum((string) ($tip['placement'] ?? 'auto'), ['top','right','bottom','left','auto'], 'auto');
        $theme     = $this->enum((string) ($tip['theme'] ?? 'default'), ['default','dark','light','accent','warning','info'], 'default');
        $trigger   = $this->enum((string) ($tip['trigger'] ?? 'hover'), ['hover','click','focus','manual'], 'hover');
        $maxW      = max(80, min(640, (int) ($tip['max_width_px'] ?? 280)));
        $showD     = max(0, min(5000, (int) ($tip['show_delay_ms'] ?? 200)));
        $hideD     = max(0, min(5000, (int) ($tip['hide_delay_ms'] ?? 100)));
        $slug      = htmlspecialchars((string) $tip['slug'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $bubble = '<span class="tt-bubble tt-theme-' . $theme . ' tt-place-' . $placement . '" id="' . $bubbleId
            . '" role="tooltip" style="max-width:' . $maxW . 'px">' . $contentHtml . '</span>';

        $wrap = '<span class="tt-wrap" data-tooltip-slug="' . $slug . '" data-placement="' . $placement
            . '" data-theme="' . $theme . '" data-trigger="' . $trigger . '" data-show-delay="' . $showD
            . '" data-hide-delay="' . $hideD . '">' . $triggerHtml . $bubble . '</span>';

        return $this->assets() . $wrap;
    }

    /**
     * Once-per-page assets. Emitted as a single `<script>` (valid phrasing
     * content — safe to sit inside prose / a `<p>`) which injects the CSS into
     * <head> and wires the widget. A bare inline `<style>` element would break a
     * containing paragraph, so the CSS is injected from JS instead.
     * Returns '' on every call after the first.
     */
    public function assets(): string
    {
        if (self::$assetsEmitted) return '';
        self::$assetsEmitted = true;
        $a11y = SubmoduleRegistry::featureEnabled('tooltip', 'a11y-keyboard');
        return $this->widgetJs($a11y);
    }

    private function bubbleId(string $slug): string
    {
        $safe = preg_replace('/[^a-z0-9]+/', '-', strtolower($slug)) ?: 'tt';
        return 'tt-bubble-' . trim((string) $safe, '-') . '-' . (++self::$seq);
    }

    private function enum(string $v, array $allowed, string $default): string
    {
        return in_array($v, $allowed, true) ? $v : $default;
    }

    private function cssText(): string
    {
        return <<<'CSS'
.tt-wrap{position:relative;display:inline-block}
.tt-trigger{cursor:help;border-bottom:1px dotted currentColor;outline:none}
.tt-trigger:focus-visible{outline:2px solid #6366f1;outline-offset:2px;border-radius:2px}
.tt-icon{font-size:.7em;margin-left:1px;opacity:.75}
.tt-help-btn{cursor:help;border:1px solid #cbd5e1;background:#f8fafc;color:#475569;border-radius:50%;width:1.25rem;height:1.25rem;line-height:1;font-size:.75rem;padding:0;display:inline-flex;align-items:center;justify-content:center}
.tt-help-btn:hover{background:#eef2ff;border-color:#6366f1;color:#4338ca}
.tt-help-label{font-size:.85em;color:#475569}
.tt-bubble{position:absolute;z-index:9999;padding:.5rem .65rem;border-radius:8px;font-size:13px;line-height:1.45;box-shadow:0 6px 24px rgba(0,0,0,.18);opacity:0;visibility:hidden;transform:translateY(2px);transition:opacity .12s ease,transform .12s ease,visibility .12s;pointer-events:none;text-align:left;white-space:normal;width:max-content}
.tt-bubble p{margin:0 0 .4rem}.tt-bubble p:last-child{margin:0}
.tt-bubble a{color:inherit;text-decoration:underline}
.tt-wrap.tt-open .tt-bubble{opacity:1;visibility:visible;transform:translateY(0);pointer-events:auto}
/* placements */
.tt-place-top{bottom:100%;left:50%;transform:translate(-50%,2px);margin-bottom:8px}
.tt-wrap.tt-open .tt-place-top{transform:translate(-50%,0)}
.tt-place-bottom{top:100%;left:50%;transform:translate(-50%,-2px);margin-top:8px}
.tt-wrap.tt-open .tt-place-bottom{transform:translate(-50%,0)}
.tt-place-left{right:100%;top:50%;transform:translate(2px,-50%);margin-right:8px}
.tt-wrap.tt-open .tt-place-left{transform:translate(0,-50%)}
.tt-place-right{left:100%;top:50%;transform:translate(-2px,-50%);margin-left:8px}
.tt-wrap.tt-open .tt-place-right{transform:translate(0,-50%)}
/* themes */
.tt-theme-default{background:#1f2937;color:#f9fafb}
.tt-theme-dark{background:#0b1220;color:#e5e7eb}
.tt-theme-light{background:#ffffff;color:#1f2937;border:1px solid #e5e7eb;box-shadow:0 6px 24px rgba(0,0,0,.10)}
.tt-theme-accent{background:#4f46e5;color:#eef2ff}
.tt-theme-warning{background:#b45309;color:#fffbeb}
.tt-theme-info{background:#0369a1;color:#f0f9ff}
CSS;
    }

    private function widgetJs(bool $a11y): string
    {
        $a11yFlag = $a11y ? 'true' : 'false';
        $cssJson = json_encode($this->cssText(), JSON_UNESCAPED_SLASHES);
        return <<<JS
<script>
(function(){
  if (!document.getElementById('tt-styles')) {
    var s = document.createElement('style'); s.id = 'tt-styles';
    s.textContent = $cssJson;
    (document.head || document.documentElement).appendChild(s);
  }
})();
(function(){
  if (window.__ttWired) return; window.__ttWired = true;
  var A11Y = $a11yFlag;
  var openTimers = new WeakMap(), closeTimers = new WeakMap();

  function bubble(w){ return w.querySelector('.tt-bubble'); }
  function show(w){
    clearTimeout(closeTimers.get(w));
    var d = parseInt(w.getAttribute('data-show-delay')||'0',10);
    openTimers.set(w, setTimeout(function(){ w.classList.add('tt-open'); placeAuto(w); }, d));
  }
  function hide(w){
    clearTimeout(openTimers.get(w));
    var d = parseInt(w.getAttribute('data-hide-delay')||'0',10);
    closeTimers.set(w, setTimeout(function(){ w.classList.remove('tt-open'); }, d));
  }
  function toggle(w){ w.classList.contains('tt-open') ? w.classList.remove('tt-open') : (w.classList.add('tt-open'), placeAuto(w)); }

  // placement=auto → pick the side with the most viewport clearance.
  function placeAuto(w){
    if (w.getAttribute('data-placement') !== 'auto') return;
    var b = bubble(w); if(!b) return;
    var r = w.getBoundingClientRect();
    var vw = window.innerWidth, vh = window.innerHeight;
    var space = { top: r.top, bottom: vh - r.bottom, left: r.left, right: vw - r.right };
    var best = Object.keys(space).sort(function(a,c){ return space[c]-space[a]; })[0];
    b.classList.remove('tt-place-top','tt-place-bottom','tt-place-left','tt-place-right');
    b.classList.add('tt-place-' + best);
  }

  function wrapOf(el){ return el.closest ? el.closest('.tt-wrap') : null; }

  // Single shared listeners per event type.
  document.addEventListener('mouseover', function(e){
    var t = e.target.closest && e.target.closest('.tt-trigger'); if(!t) return;
    var w = wrapOf(t); if(!w || w.getAttribute('data-trigger') !== 'hover') return;
    show(w);
  });
  document.addEventListener('mouseout', function(e){
    var t = e.target.closest && e.target.closest('.tt-trigger'); if(!t) return;
    var w = wrapOf(t); if(!w || w.getAttribute('data-trigger') !== 'hover') return;
    if (e.relatedTarget && w.contains(e.relatedTarget)) return; // moving within → keep open
    hide(w);
  });
  document.addEventListener('click', function(e){
    var t = e.target.closest && e.target.closest('.tt-trigger');
    if (t) {
      var w = wrapOf(t); if(w && w.getAttribute('data-trigger') === 'click'){ e.preventDefault(); toggle(w); return; }
    }
    // click-outside closes any open click-triggered tooltip
    document.querySelectorAll('.tt-wrap.tt-open').forEach(function(w){
      if (w.getAttribute('data-trigger') === 'click' && !w.contains(e.target)) w.classList.remove('tt-open');
    });
  });

  if (A11Y) {
    document.addEventListener('focusin', function(e){
      var t = e.target.closest && e.target.closest('.tt-trigger'); if(!t) return;
      var w = wrapOf(t); if(!w) return;
      var tr = w.getAttribute('data-trigger');
      if (tr === 'focus' || tr === 'hover' || tr === 'click') { w.classList.add('tt-open'); placeAuto(w); }
    });
    document.addEventListener('focusout', function(e){
      var t = e.target.closest && e.target.closest('.tt-trigger'); if(!t) return;
      var w = wrapOf(t); if(w) w.classList.remove('tt-open');
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') document.querySelectorAll('.tt-wrap.tt-open').forEach(function(w){ w.classList.remove('tt-open'); });
    });
  }

  window.addEventListener('resize', function(){ document.querySelectorAll('.tt-wrap.tt-open').forEach(placeAuto); });

  // Minimal public API for trigger=manual callers.
  window.Tooltip = window.Tooltip || {
    open: function(slug){ var w = document.querySelector('.tt-wrap[data-tooltip-slug="'+slug+'"]'); if(w){ w.classList.add('tt-open'); placeAuto(w);} },
    close: function(slug){ var w = document.querySelector('.tt-wrap[data-tooltip-slug="'+slug+'"]'); if(w) w.classList.remove('tt-open'); }
  };
})();
</script>
JS;
    }
}
