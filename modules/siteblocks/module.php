<?php
// modules/siteblocks/module.php
use Core\Module\ModuleProvider;

/**
 * Siteblocks module — universal site-building primitives that every
 * page-composer-style framework ships out of the box.
 *
 * The module owns NO domain data. It's a bag of presentation blocks
 * (HTML, hero, image, video, CTA, spacer, search box, login/register/
 * newsletter forms) that admins drop onto pages to compose marketing
 * surfaces without writing PHP.
 *
 * Markdown is shipped via Core\Support\Markdown — a small inline
 * renderer covering the practical 90% case. Wrapping league/commonmark
 * is a future composer change; the inline implementation has zero
 * external deps and is small enough to read in one sitting.
 *
 * The newsletter_signup block writes to the `newsletter_signups` table
 * via NewsletterController. Real-world deployments will likely sync to
 * Mailchimp / SendGrid / etc; that integration is deferred and lives
 * outside this module — see the external-services list.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'siteblocks'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        return [
            // ── Raw HTML ────────────────────────────────────────────────
            // The most-used block in any composable system. Admin types
            // HTML, the same allowlist the pages.body field uses runs at
            // render time so script tags and on-* handlers are stripped.
            // Optional card wrapper for visual consistency with other tiles.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.html',
                label:       'Raw HTML',
                description: 'Admin-authored HTML, sanitised through the framework allowlist on render.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: ['html' => '', 'wrap_in_card' => false],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'html',         'label' => 'HTML',         'type' => 'textarea', 'default' => '', 'placeholder' => '<p>Hello</p>'],
                    ['key' => 'wrap_in_card', 'label' => 'Wrap in card', 'type' => 'checkbox', 'default' => false],
                ],
                render: function (array $context, array $settings): string {
                    $html  = (string) ($settings['html'] ?? '');
                    if ($html === '') return '';
                    $clean = \Core\Validation\Validator::sanitizeHtml($html);
                    if (!empty($settings['wrap_in_card'])) {
                        return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem">' . $clean . '</div></div>';
                    }
                    return '<div class="siteblock-html" style="line-height:1.7">' . $clean . '</div>';
                }
            ),

            // ── Markdown ────────────────────────────────────────────────
            // Admin types Markdown, Core\Support\Markdown renders it to
            // HTML. The renderer escapes raw HTML in the source up front
            // and filters link/image URLs through a safelist (http(s),
            // mailto, fragment, relative paths) — javascript: hrefs are
            // neutered to about:blank. If a caller needs raw HTML they
            // should use siteblocks.html instead, which runs the heavier
            // sanitiser allowlist.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.markdown',
                label:       'Markdown',
                description: 'Admin-authored Markdown rendered to HTML. Headings, lists, code, links, blockquotes, hr.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: ['body' => '', 'wrap_in_card' => false],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'body',         'label' => 'Markdown source', 'type' => 'textarea', 'default' => '', 'placeholder' => "# Heading\n\nParagraph text…"],
                    ['key' => 'wrap_in_card', 'label' => 'Wrap in card',    'type' => 'checkbox', 'default' => false, 'help' => 'Wraps the rendered HTML in the standard card container.'],
                ],
                render: function (array $context, array $settings): string {
                    $body = (string) ($settings['body'] ?? '');
                    if (trim($body) === '') return '';
                    $html = \Core\Support\Markdown::render($body);
                    if (!empty($settings['wrap_in_card'])) {
                        return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem;line-height:1.7">' . $html . '</div></div>';
                    }
                    return '<div class="siteblock-markdown" style="line-height:1.7">' . $html . '</div>';
                }
            ),

            // ── Image ───────────────────────────────────────────────────
            // Pure presentation. URL + alt + optional caption + optional
            // wrapping link. CSP keeps img-src honest (data: + https:),
            // so file:// or javascript: hrefs in the link can't sneak in.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.image',
                label:       'Image',
                description: 'Single image with alt text, optional caption + link.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: ['src' => '', 'alt' => '', 'caption' => '', 'link' => '', 'max_width_px' => 0],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $src = trim((string) ($settings['src'] ?? ''));
                    if ($src === '') return '';

                    $alt     = htmlspecialchars((string) ($settings['alt']     ?? ''), ENT_QUOTES | ENT_HTML5);
                    $caption = htmlspecialchars((string) ($settings['caption'] ?? ''), ENT_QUOTES | ENT_HTML5);
                    $link    = trim((string) ($settings['link'] ?? ''));
                    $max     = max(0, (int) ($settings['max_width_px'] ?? 0));

                    // Defensive on link target: only http(s) and same-origin
                    // paths. javascript: / data: URIs get dropped.
                    if ($link !== '' && !preg_match('#^(?:https?://|/)#i', $link)) {
                        $link = '';
                    }

                    $imgStyle = 'max-width:100%;height:auto;border-radius:6px;display:block;margin:0 auto;'
                              . ($max > 0 ? 'max-width:' . $max . 'px;' : '');
                    $img = '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_HTML5) . '" alt="' . $alt . '" style="' . $imgStyle . '">';
                    if ($link !== '') {
                        $img = '<a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_HTML5) . '" style="display:block">' . $img . '</a>';
                    }

                    $h = '<figure style="margin:0;padding:.5rem 0">' . $img;
                    if ($caption !== '') {
                        $h .= '<figcaption style="text-align:center;font-size:12.5px;color:var(--text-muted);margin-top:.5rem">' . $caption . '</figcaption>';
                    }
                    return $h . '</figure>';
                }
            ),

            // ── Video Embed ─────────────────────────────────────────────
            // YouTube / Vimeo URL → responsive iframe. ID extraction is
            // pure regex — no API calls, no key required. Other providers
            // can be added by extending the parser switch.
            //
            // CSP NOTE: the iframe loads from www.youtube.com /
            // www.youtube-nocookie.com / player.vimeo.com. The framework's
            // default CSP allows those origins in frame-src.
            //
            // We use youtube.com/embed (not youtube-nocookie.com) because
            // the nocookie variant requires a strict referer policy that
            // many videos refuse playback under (YouTube error 153). Cookie
            // policy on the live site is bounded by the visitor's own
            // browser settings; the privacy delta vs. nocookie is small.
            // The iframe inherits the document's Referrer-Policy header
            // (strict-origin-when-cross-origin), which sends just the
            // origin to YouTube — enough for YouTube's embed verification
            // without leaking the path/query.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.video_embed',
                label:       'Video Embed',
                description: 'Responsive YouTube or Vimeo embed. Settings: url.',
                category:    'Site Building',
                defaultSize: 'large',
                defaultSettings: ['url' => '', 'aspect' => '16:9'],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $url = trim((string) ($settings['url'] ?? ''));
                    if ($url === '') return '';

                    $embedSrc = null;
                    // YouTube — handles youtu.be/ID, youtube.com/watch?v=ID,
                    // and youtube.com/embed/ID variants.
                    if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|v/)|youtu\.be/)([a-zA-Z0-9_-]{6,15})#', $url, $m)) {
                        $embedSrc = 'https://www.youtube.com/embed/' . $m[1];
                    }
                    // Vimeo — vimeo.com/ID
                    elseif (preg_match('#vimeo\.com/(?:.*?/)?(\d{6,15})#', $url, $m)) {
                        $embedSrc = 'https://player.vimeo.com/video/' . $m[1];
                    }
                    if ($embedSrc === null) {
                        $auth = \Core\Auth\Auth::getInstance();
                        return $auth->hasRole(['super-admin','admin'])
                            ? '<div class="siteblock-video-error" style="background:#fef3c7;border:1px dashed #fcd34d;color:#92400e;padding:.6rem 1rem;border-radius:6px;font-size:12.5px">Video URL not recognised — supports YouTube and Vimeo. Got: <code>' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) . '</code></div>'
                            : '';
                    }

                    [$w, $h] = array_pad(explode(':', (string) ($settings['aspect'] ?? '16:9'), 2), 2, '9');
                    $w = max(1, (int) $w);
                    $h = max(1, (int) $h);
                    $pad = (($h * 100) / $w) . '%';

                    // No iframe-level referrerpolicy override — let the
                    // document-level Referrer-Policy (strict-origin-when-
                    // cross-origin) apply, which sends just the origin.
                    // YouTube needs that to verify the embed; stripping it
                    // entirely with referrerpolicy="no-referrer" caused
                    // error-153 playback failures.
                    return '<div style="position:relative;width:100%;padding-bottom:' . $pad . ';overflow:hidden;border-radius:6px;background:#000">'
                         . '<iframe src="' . htmlspecialchars($embedSrc, ENT_QUOTES | ENT_HTML5) . '" '
                         . 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" '
                         . 'allowfullscreen loading="lazy"></iframe>'
                         . '</div>';
                }
            ),

            // ── Hero ────────────────────────────────────────────────────
            // The marketing-page starter. Heading + subheading + CTA
            // button + optional background image. Used at the top of
            // landing pages.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.hero',
                label:       'Hero',
                description: 'Big heading + subheading + CTA button + optional background image.',
                category:    'Site Building',
                defaultSize: 'large',
                // Defaults are EMPTY for the optional content fields so an
                // empty settings JSON renders nothing (consistent with the
                // other site-building blocks). text_align and min_height_px
                // get layout defaults because they shape the hero even when
                // it has content.
                defaultSettings: [
                    'title'         => '',
                    'subtitle'      => '',
                    'cta_label'     => '',
                    'cta_url'       => '',
                    'bg_image_url'  => '',
                    'text_align'    => 'center',
                    'min_height_px' => 320,
                ],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $title    = (string) ($settings['title']    ?? '');
                    $subtitle = (string) ($settings['subtitle'] ?? '');
                    $cta      = (string) ($settings['cta_label'] ?? '');
                    $ctaUrl   = (string) ($settings['cta_url']   ?? '');
                    $bg       = (string) ($settings['bg_image_url'] ?? '');
                    $align    = in_array((string) ($settings['text_align'] ?? 'center'), ['left','center','right'], true)
                                ? (string) $settings['text_align'] : 'center';
                    $minH     = max(160, (int) ($settings['min_height_px'] ?? 320));

                    if ($title === '' && $subtitle === '' && $cta === '') return '';

                    // Defensive on URL inputs.
                    if ($bg     !== '' && !preg_match('#^(?:https?://|/)#i', $bg))     $bg = '';
                    if ($ctaUrl !== '' && !preg_match('#^(?:https?://|/)#i', $ctaUrl)) $ctaUrl = '';

                    $bgStyle = $bg !== ''
                        ? "background:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),url('" . htmlspecialchars($bg, ENT_QUOTES | ENT_HTML5) . "') center/cover no-repeat;color:#fff"
                        : 'background:linear-gradient(135deg,var(--color-primary) 0%,var(--color-primary-dark) 100%);color:#fff';

                    $h = '<section style="' . $bgStyle . ';padding:3rem 1.5rem;border-radius:8px;text-align:' . $align . ';min-height:' . $minH . 'px;display:flex;flex-direction:column;justify-content:center">';
                    if ($title !== '') {
                        $h .= '<h1 style="margin:0 0 .5rem;font-size:2.25rem;font-weight:700;line-height:1.2">' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5) . '</h1>';
                    }
                    if ($subtitle !== '') {
                        $h .= '<p style="margin:0 0 1.5rem;font-size:1.1rem;opacity:.9;line-height:1.5">' . htmlspecialchars($subtitle, ENT_QUOTES | ENT_HTML5) . '</p>';
                    }
                    if ($cta !== '' && $ctaUrl !== '') {
                        $h .= '<div><a href="' . htmlspecialchars($ctaUrl, ENT_QUOTES | ENT_HTML5) . '" '
                            . 'style="display:inline-block;background:#fff;color:var(--color-primary);padding:.7rem 1.6rem;border-radius:6px;font-weight:600;font-size:.95rem;text-decoration:none">'
                            . htmlspecialchars($cta, ENT_QUOTES | ENT_HTML5) . '</a></div>';
                    }
                    return $h . '</section>';
                }
            ),

            // ── CTA Banner ──────────────────────────────────────────────
            // Lighter than hero; for breaking up long pages with a focused
            // call to action. Inline rather than full-bleed.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.cta_banner',
                label:       'Call-to-Action Banner',
                description: 'Mid-page banner with title, optional description, and a single CTA button.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: ['title' => '', 'description' => '', 'cta_label' => '', 'cta_url' => '', 'tone' => 'primary'],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $title  = (string) ($settings['title'] ?? '');
                    $desc   = (string) ($settings['description'] ?? '');
                    $cta    = (string) ($settings['cta_label'] ?? '');
                    $ctaUrl = (string) ($settings['cta_url']   ?? '');
                    $tone   = (string) ($settings['tone']      ?? 'primary');

                    if ($title === '' && $desc === '' && $cta === '') return '';
                    if ($ctaUrl !== '' && !preg_match('#^(?:https?://|/)#i', $ctaUrl)) $ctaUrl = '';

                    $palette = match ($tone) {
                        'success' => ['bg' => '#ecfdf5', 'border' => '#6ee7b7', 'fg' => '#065f46', 'btnBg' => 'var(--color-success)'],
                        'warning' => ['bg' => '#fef3c7', 'border' => '#fcd34d', 'fg' => '#92400e', 'btnBg' => 'var(--color-warning)'],
                        'danger'  => ['bg' => '#fee2e2', 'border' => '#fca5a5', 'fg' => '#991b1b', 'btnBg' => '#dc2626'],
                        default   => ['bg' => 'var(--accent-subtle)', 'border' => '#c7d2fe', 'fg' => '#4338ca', 'btnBg' => 'var(--color-primary)'],
                    };

                    $h = '<div style="background:' . $palette['bg'] . ';border:1px solid ' . $palette['border']
                       . ';border-radius:8px;padding:1.25rem 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1.25rem;flex-wrap:wrap">'
                       . '<div style="flex:1;min-width:240px">';
                    if ($title !== '') {
                        $h .= '<div style="font-weight:700;font-size:1rem;color:' . $palette['fg'] . '">' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5) . '</div>';
                    }
                    if ($desc !== '') {
                        $h .= '<div style="font-size:13.5px;color:' . $palette['fg'] . ';margin-top:.25rem;line-height:1.5">' . htmlspecialchars($desc, ENT_QUOTES | ENT_HTML5) . '</div>';
                    }
                    $h .= '</div>';
                    if ($cta !== '' && $ctaUrl !== '') {
                        $h .= '<a href="' . htmlspecialchars($ctaUrl, ENT_QUOTES | ENT_HTML5) . '" '
                            . 'style="background:' . $palette['btnBg'] . ';color:#fff;padding:.55rem 1.2rem;border-radius:6px;font-weight:600;font-size:.9rem;text-decoration:none;flex-shrink:0">'
                            . htmlspecialchars($cta, ENT_QUOTES | ENT_HTML5) . '</a>';
                    }
                    return $h . '</div>';
                }
            ),

            // ── Spacer / Divider ────────────────────────────────────────
            // Pure layout primitive. Vertical space, optionally with a
            // horizontal rule for visual separation.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.spacer',
                label:       'Spacer / Divider',
                description: 'Vertical space, optionally with a horizontal rule.',
                category:    'Site Building',
                defaultSize: 'small',
                defaultSettings: ['height_px' => 32, 'show_divider' => false],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $h = max(0, min(400, (int) ($settings['height_px'] ?? 32)));
                    $showDivider = !empty($settings['show_divider']);
                    if ($showDivider) {
                        $half = (int) round($h / 2);
                        return '<div style="height:' . $half . 'px"></div>'
                             . '<hr style="border:0;border-top:1px solid var(--border-default);margin:0">'
                             . '<div style="height:' . $half . 'px"></div>';
                    }
                    return '<div style="height:' . $h . 'px" aria-hidden="true"></div>';
                }
            ),

            // ── Search Box ──────────────────────────────────────────────
            // GET form to /search (or wherever the framework's search
            // entry-point lives). The search subsystem owns the actual
            // query handling; this block just provides the input.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.search_box',
                label:       'Search Box',
                description: 'Search input form. Submits to /search by default.',
                category:    'Site Building',
                defaultSize: 'small',
                defaultSettings: ['placeholder' => 'Search…', 'action_url' => '/search', 'cta_label' => 'Search'],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $placeholder = htmlspecialchars((string) ($settings['placeholder'] ?? 'Search…'), ENT_QUOTES | ENT_HTML5);
                    $action      = (string) ($settings['action_url'] ?? '/search');
                    if (!preg_match('#^(?:https?://|/)#i', $action)) $action = '/search';
                    $action      = htmlspecialchars($action, ENT_QUOTES | ENT_HTML5);
                    $cta         = htmlspecialchars((string) ($settings['cta_label'] ?? 'Search'), ENT_QUOTES | ENT_HTML5);

                    return '<form method="GET" action="' . $action . '" role="search" style="display:flex;gap:.5rem;width:100%">'
                         . '<input type="search" name="q" placeholder="' . $placeholder . '" '
                         . 'class="form-control" style="flex:1;padding:.6rem .9rem;border:1px solid var(--border-strong);border-radius:6px;font-size:14px" aria-label="' . $placeholder . '">'
                         . '<button type="submit" class="btn btn-primary" style="padding:.6rem 1.1rem">' . $cta . '</button>'
                         . '</form>';
                }
            ),

            // ── Login Form ──────────────────────────────────────────────
            // Inline form posting to /login. When the viewer is already
            // signed in, renders an "Already signed in as @user — Sign
            // out" panel instead so the block degrades gracefully on
            // pages an authed user can still see (the public.page authed
            // view, for instance).
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.login_form',
                label:       'Login Form (inline)',
                description: 'Inline login form. Shows a "you are signed in" panel for authed viewers.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: ['redirect_to' => '/dashboard', 'show_register_link' => true],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $auth = \Core\Auth\Auth::getInstance();
                    $redirectTo = (string) ($settings['redirect_to'] ?? '/dashboard');
                    if (!preg_match('#^/#', $redirectTo)) $redirectTo = '/dashboard';

                    if ($auth->check()) {
                        $u = $auth->user();
                        $name = htmlspecialchars((string) ($u['username'] ?? $u['email'] ?? 'you'), ENT_QUOTES | ENT_HTML5);
                        return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem;text-align:center">'
                             . '<p style="margin:0 0 .75rem;font-size:14px;color:#374151">You\'re signed in as <strong>@' . $name . '</strong>.</p>'
                             . '<form method="POST" action="/logout" style="display:inline">' . csrf_field()
                             . '<button type="submit" class="btn btn-sm btn-secondary">Sign out</button>'
                             . '</form>'
                             . '</div></div>';
                    }

                    $showRegister = !empty($settings['show_register_link']);
                    $h = '<div class="card"><div class="card-header"><h3 style="margin:0;font-size:1rem">Sign in</h3></div>'
                       . '<form method="POST" action="/login" class="card-body" style="padding:1rem 1.25rem">'
                       . csrf_field()
                       . '<input type="hidden" name="intended" value="' . htmlspecialchars($redirectTo, ENT_QUOTES | ENT_HTML5) . '">'
                       . '<div class="form-group" style="margin-bottom:.75rem">'
                       .   '<label for="email" style="display:block;font-weight:500;font-size:13px;margin-bottom:.25rem">Email</label>'
                       .   '<input type="email" name="email" class="form-control" required autocomplete="email" id="email">'
                       . '</div>'
                       . '<div class="form-group" style="margin-bottom:1rem">'
                       .   '<label for="password" style="display:block;font-weight:500;font-size:13px;margin-bottom:.25rem">Password</label>'
                       .   '<input type="password" name="password" class="form-control" required autocomplete="current-password" id="password">'
                       . '</div>'
                       . '<button type="submit" class="btn btn-primary" style="width:100%">Sign in</button>';
                    if ($showRegister) {
                        $h .= '<div style="text-align:center;margin-top:.75rem;font-size:13px;color:var(--text-muted)">'
                            . 'No account? <a href="/register" style="color:var(--color-primary);text-decoration:none">Create one</a>'
                            . ' · <a href="/password/forgot" style="color:var(--color-primary);text-decoration:none">Forgot password?</a>'
                            . '</div>';
                    }
                    return $h . '</form></div>';
                }
            ),

            // ── Register Form ───────────────────────────────────────────
            // Inline registration form posting to /register. Honours the
            // allow_registration site setting — when off, the block
            // renders the "registration is closed" message instead of
            // exposing a form that would 403/redirect on submit.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.register_form',
                label:       'Register Form (inline)',
                description: 'Inline registration form. Honours the allow_registration setting.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: [],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $auth = \Core\Auth\Auth::getInstance();
                    if ($auth->check()) return ''; // signed-in users see nothing

                    if (!(bool) setting('allow_registration', true)) {
                        return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem;text-align:center;color:var(--text-muted);font-size:14px">'
                             . 'Registration is closed.'
                             . '</div></div>';
                    }

                    return '<div class="card"><div class="card-header"><h3 style="margin:0;font-size:1rem">Create account</h3></div>'
                         . '<form method="POST" action="/register" class="card-body" style="padding:1rem 1.25rem">'
                         . csrf_field()
                         . '<div class="form-group" style="margin-bottom:.75rem;display:grid;grid-template-columns:1fr 1fr;gap:.5rem">'
                         . '<div><label for="first_name" style="display:block;font-weight:500;font-size:13px;margin-bottom:.25rem">First name</label>'
                         . '<input type="text" name="first_name" class="form-control" required id="first_name"></div>'
                         . '<div><label for="last_name" style="display:block;font-weight:500;font-size:13px;margin-bottom:.25rem">Last name</label>'
                         . '<input type="text" name="last_name" class="form-control" required id="last_name"></div>'
                         . '</div>'
                         . '<div class="form-group" style="margin-bottom:.75rem">'
                         .   '<label for="email" style="display:block;font-weight:500;font-size:13px;margin-bottom:.25rem">Email</label>'
                         .   '<input type="email" name="email" class="form-control" required autocomplete="email" id="email">'
                         . '</div>'
                         . '<div class="form-group" style="margin-bottom:.75rem">'
                         .   '<label for="password" style="display:block;font-weight:500;font-size:13px;margin-bottom:.25rem">Password</label>'
                         .   '<input type="password" name="password" class="form-control" required autocomplete="new-password" minlength="12" id="password">'
                         . '</div>'
                         . '<div class="form-group" style="margin-bottom:1rem">'
                         .   '<label for="password_confirm" style="display:block;font-weight:500;font-size:13px;margin-bottom:.25rem">Confirm password</label>'
                         .   '<input type="password" name="password_confirm" class="form-control" required autocomplete="new-password" id="password_confirm">'
                         . '</div>'
                         . '<button type="submit" class="btn btn-primary" style="width:100%">Create account</button>'
                         . '<div style="text-align:center;margin-top:.75rem;font-size:13px;color:var(--text-muted)">'
                         .   'Already have an account? <a href="/login" style="color:var(--color-primary);text-decoration:none">Sign in</a>'
                         . '</div>'
                         . '</form></div>';
                }
            ),

            // ── Newsletter Signup ───────────────────────────────────────
            // Email capture posting to /newsletter/subscribe (handled by
            // NewsletterController in this module). The signup is stored
            // locally for now; a future integration with Mailchimp /
            // SendGrid / etc. is deferred. After submit the user lands
            // back on the source page with a generic success flash.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.newsletter_signup',
                label:       'Newsletter Signup',
                description: 'Email capture posting to /newsletter/subscribe. Stored locally; sync to providers via a follow-up integration.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: [
                    'heading'     => 'Stay in the loop',
                    'description' => '',
                    'placeholder' => 'you@example.com',
                    'cta_label'   => 'Subscribe',
                ],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $heading = htmlspecialchars((string) ($settings['heading']     ?? 'Stay in the loop'), ENT_QUOTES | ENT_HTML5);
                    $desc    = htmlspecialchars((string) ($settings['description'] ?? ''),                ENT_QUOTES | ENT_HTML5);
                    $ph      = htmlspecialchars((string) ($settings['placeholder'] ?? 'you@example.com'), ENT_QUOTES | ENT_HTML5);
                    $cta     = htmlspecialchars((string) ($settings['cta_label']   ?? 'Subscribe'),       ENT_QUOTES | ENT_HTML5);

                    // Capture current URL as source so the redirect lands
                    // back on the same page after subscribe.
                    $source  = htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/'), ENT_QUOTES | ENT_HTML5);

                    $h = '<div class="card"><div class="card-body" style="padding:1.25rem 1.5rem">'
                       . '<h3 style="margin:0 0 .35rem;font-size:1.05rem;font-weight:700">' . $heading . '</h3>';
                    if ($desc !== '') {
                        $h .= '<p style="margin:0 0 .85rem;color:var(--text-muted);font-size:13.5px;line-height:1.5">' . $desc . '</p>';
                    }
                    $h .= '<form method="POST" action="/newsletter/subscribe" style="display:flex;gap:.5rem;flex-wrap:wrap">'
                        . csrf_field()
                        . '<input type="hidden" name="source_url" value="' . $source . '">'
                        . '<input type="email" name="email" required placeholder="' . $ph . '" '
                        .   'class="form-control" style="flex:1;min-width:200px;padding:.6rem .9rem;border:1px solid var(--border-strong);border-radius:6px" aria-label="' . $ph . '">'
                        . '<button type="submit" class="btn btn-primary" style="padding:.6rem 1.2rem">' . $cta . '</button>'
                        . '</form>'
                        . '</div></div>';
                    return $h;
                }
            ),

            // ── Spotlight ──────────────────────────────────────────────
            // Cross-target featured-content rotator. Pulls one random
            // featured row from the union of pages / products / kb
            // articles, renders a single-tile spotlight with title +
            // optional summary + link. Different on every page render
            // (RAND() in MySQL); admins can scope to a subset of types
            // via the `types` setting (comma-separated list of:
            // pages, products, articles).
            //
            // Each source is queried separately and merged in PHP rather
            // than via UNION ALL — the column shapes differ enough
            // (pages has slug, products has slug, articles has slug
            // but different URL shape) that joining client-side keeps
            // the SQL simple and the URL builder co-located. Missing
            // tables (module disabled) are caught silently.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.spotlight',
                label:       'Spotlight (random featured)',
                description: 'Single random featured item across pages/products/articles. Settings: types.',
                category:    'Site Building',
                defaultSize: 'medium',
                defaultSettings: ['types' => 'pages,products,articles'],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $types = array_map('trim', explode(',', (string) ($settings['types'] ?? 'pages,products,articles')));
                    $allow = array_flip(array_filter($types, fn($t) => in_array($t, ['pages','products','articles'], true)));
                    if (empty($allow)) return '';

                    $pool = [];
                    $db   = \Core\Database\Database::getInstance();

                    if (isset($allow['pages'])) {
                        try {
                            foreach ($db->fetchAll(
                                "SELECT slug, title, NULL AS summary, 'page' AS kind
                                   FROM pages
                                  WHERE status='published' AND is_public=1 AND featured=1"
                            ) as $r) {
                                $r['url'] = '/' . rawurlencode((string) $r['slug']);
                                $pool[] = $r;
                            }
                        } catch (\Throwable) {}
                    }
                    if (isset($allow['products'])) {
                        try {
                            foreach ($db->fetchAll(
                                "SELECT slug, name AS title, short_description AS summary, 'product' AS kind
                                   FROM store_products
                                  WHERE active=1 AND featured=1"
                            ) as $r) {
                                $r['url'] = '/shop/' . rawurlencode((string) $r['slug']);
                                $pool[] = $r;
                            }
                        } catch (\Throwable) {}
                    }
                    if (isset($allow['articles'])) {
                        try {
                            foreach ($db->fetchAll(
                                "SELECT slug, title, summary, 'article' AS kind
                                   FROM kb_articles
                                  WHERE status='published' AND featured=1"
                            ) as $r) {
                                $r['url'] = '/kb/' . rawurlencode((string) $r['slug']);
                                $pool[] = $r;
                            }
                        } catch (\Throwable) {}
                    }

                    if (empty($pool)) return '';
                    $pick    = $pool[array_rand($pool)];
                    $title   = htmlspecialchars((string) ($pick['title'] ?? '(untitled)'), ENT_QUOTES | ENT_HTML5);
                    $summary = (string) ($pick['summary'] ?? '');
                    $excerpt = mb_strlen($summary) > 200 ? mb_substr($summary, 0, 197) . '…' : $summary;
                    $excerpt = htmlspecialchars($excerpt, ENT_QUOTES | ENT_HTML5);
                    $kind    = htmlspecialchars((string) $pick['kind'], ENT_QUOTES | ENT_HTML5);
                    $url     = htmlspecialchars((string) $pick['url'], ENT_QUOTES | ENT_HTML5);

                    return '<div class="card" style="background:linear-gradient(135deg,var(--color-primary) 0%,#7c3aed 100%);color:#fff;border:none">'
                         . '<div class="card-body" style="padding:1.5rem 1.75rem">'
                         .   '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;opacity:.85">⭐ ' . ucfirst($kind) . ' Spotlight</div>'
                         .   '<div style="font-size:1.35rem;font-weight:700;margin-top:.5rem;line-height:1.3">' . $title . '</div>'
                         .   ($excerpt !== '' ? '<div style="font-size:13.5px;margin-top:.5rem;opacity:.92;line-height:1.5">' . $excerpt . '</div>' : '')
                         .   '<a href="' . $url . '" style="display:inline-block;margin-top:.85rem;padding:.5rem 1rem;background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;text-decoration:none;font-size:13.5px;font-weight:500">Read more →</a>'
                         . '</div></div>';
                }
            ),

            // ── Pricing Table ──────────────────────────────────────────
            // Marketing primitive — 2-4 plan tiers in a row. Settings:
            //   heading: optional H2 above the table
            //   plans: [{name, price, period, features[], cta_label,
            //            cta_url, popular}]
            // The "popular" plan gets an accent border + subtle scale-up.
            // CTA URL filtered to http(s)/relative; javascript: dropped.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.pricing_table',
                label:       'Pricing Table',
                description: 'Side-by-side plan tiers with name, price, features list, and CTA. Highlight one tier as popular.',
                category:    'Marketing',
                defaultSize: 'large',
                defaultSettings: ['heading' => '', 'plans' => []],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'heading', 'label' => 'Heading (optional)', 'type' => 'text', 'default' => ''],
                    ['key' => 'plans', 'label' => 'Plans', 'type' => 'repeater', 'default' => [],
                     'item_label' => 'Plan',
                     'help' => 'Each plan is a tier in the pricing table. The "popular" plan gets an accent border + scale-up.',
                     'item_schema' => [
                         ['key' => 'name',      'label' => 'Plan name',      'type' => 'text',     'default' => '', 'placeholder' => 'Pro'],
                         ['key' => 'price',     'label' => 'Price',          'type' => 'text',     'default' => '', 'placeholder' => '$29'],
                         ['key' => 'period',    'label' => 'Period',         'type' => 'text',     'default' => '', 'placeholder' => '/month'],
                         ['key' => 'features',  'label' => 'Features',       'type' => 'string_list', 'default' => [],
                          'item_label' => 'feature',
                          'help' => 'One bullet per line. Renders with a ✓ in front.'],
                         ['key' => 'cta_label', 'label' => 'CTA label',      'type' => 'text',     'default' => 'Choose plan'],
                         ['key' => 'cta_url',   'label' => 'CTA URL',        'type' => 'text',     'default' => '#',
                          'help' => 'http(s), root-relative, mailto:, or # — anything else is dropped at render.'],
                         ['key' => 'popular',   'label' => 'Highlight as most popular', 'type' => 'checkbox', 'default' => false],
                     ]],
                ],
                render: function (array $context, array $settings): string {
                    $plans = is_array($settings['plans'] ?? null) ? $settings['plans'] : [];
                    if (empty($plans)) return '';
                    $heading = trim((string) ($settings['heading'] ?? ''));

                    $cardsHtml = '';
                    foreach ($plans as $p) {
                        $name     = htmlspecialchars((string) ($p['name'] ?? ''), ENT_QUOTES | ENT_HTML5);
                        $price    = htmlspecialchars((string) ($p['price'] ?? ''), ENT_QUOTES | ENT_HTML5);
                        $period   = htmlspecialchars((string) ($p['period'] ?? ''), ENT_QUOTES | ENT_HTML5);
                        $cta      = htmlspecialchars((string) ($p['cta_label'] ?? 'Choose'), ENT_QUOTES | ENT_HTML5);
                        $popular  = !empty($p['popular']);
                        $features = is_array($p['features'] ?? null) ? $p['features'] : [];

                        // CTA URL safelist — same shape used by siteblocks.image.
                        $ctaUrl = (string) ($p['cta_url'] ?? '#');
                        if (!preg_match('#^(?:https?://|/|\#|mailto:)#i', $ctaUrl)) $ctaUrl = '#';
                        $ctaUrl = htmlspecialchars($ctaUrl, ENT_QUOTES | ENT_HTML5);

                        $featureHtml = '';
                        foreach ($features as $f) {
                            $featureHtml .= '<li style="padding:.35rem 0;color:#374151">✓ '
                                          . htmlspecialchars((string) $f, ENT_QUOTES | ENT_HTML5) . '</li>';
                        }

                        $border = $popular ? '2px solid var(--color-primary)' : '1px solid var(--border-default)';
                        $shadow = $popular ? 'box-shadow:0 8px 24px -8px rgba(79,70,229,.35)' : '';
                        $badge  = $popular ? '<div style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--color-primary);color:#fff;padding:.25rem .75rem;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase">Most popular</div>' : '';
                        $btnCls = $popular ? 'btn btn-primary' : 'btn btn-secondary';

                        $cardsHtml .= '<div style="position:relative;background:#fff;border:' . $border . ';border-radius:12px;padding:1.5rem 1.25rem;display:flex;flex-direction:column;' . $shadow . '">'
                                    . $badge
                                    . '<div style="font-size:.95rem;font-weight:600;color:var(--text-default)">' . $name . '</div>'
                                    . '<div style="margin-top:.5rem"><span style="font-size:2rem;font-weight:700;color:var(--text-default)">' . $price . '</span>'
                                    . ($period !== '' ? '<span style="color:var(--text-muted);font-size:.9rem;margin-left:.25rem">' . $period . '</span>' : '')
                                    . '</div>'
                                    . '<ul style="list-style:none;padding:0;margin:1rem 0;font-size:13.5px;flex:1">' . $featureHtml . '</ul>'
                                    . '<a href="' . $ctaUrl . '" class="' . $btnCls . '" style="text-align:center;justify-content:center">' . $cta . '</a>'
                                    . '</div>';
                    }

                    $colCount = max(1, min(4, count($plans)));
                    $h = '<div style="padding:1rem 0">';
                    if ($heading !== '') {
                        $h .= '<h2 style="text-align:center;font-size:1.5rem;font-weight:700;margin:0 0 1.5rem;color:var(--text-default)">'
                            . htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5) . '</h2>';
                    }
                    $h .= '<div style="display:grid;gap:1rem;grid-template-columns:repeat(' . $colCount . ',minmax(220px,1fr))">'
                        . $cardsHtml . '</div></div>';
                    return $h;
                }
            ),

            // ── Testimonials ───────────────────────────────────────────
            // Quote block. Two layouts:
            //   single — one large featured quote, centered
            //   grid   — 2-3 column grid of smaller quotes
            // Avatar URL is optional + safelist-filtered. Empty avatar
            // falls back to a colored initial circle.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.testimonials',
                label:       'Testimonials',
                description: 'Customer quotes with author + role + optional avatar. Single featured or multi-column grid.',
                category:    'Marketing',
                defaultSize: 'large',
                defaultSettings: ['heading' => '', 'layout' => 'grid', 'items' => []],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'heading', 'label' => 'Heading (optional)', 'type' => 'text',   'default' => ''],
                    ['key' => 'layout',  'label' => 'Layout',             'type' => 'select', 'default' => 'grid',
                     'options' => ['grid' => 'Grid (2-3 cols)', 'single' => 'Single featured quote']],
                    ['key' => 'items', 'label' => 'Quotes', 'type' => 'repeater', 'default' => [],
                     'item_label' => 'Quote',
                     'help' => 'Single layout uses only the first quote.',
                     'item_schema' => [
                         ['key' => 'quote',      'label' => 'Quote',      'type' => 'textarea', 'default' => ''],
                         ['key' => 'author',     'label' => 'Author',     'type' => 'text',     'default' => '', 'placeholder' => 'Jane Doe'],
                         ['key' => 'role',       'label' => 'Role / company (optional)', 'type' => 'text', 'default' => ''],
                         ['key' => 'avatar_url', 'label' => 'Avatar URL (optional)', 'type' => 'text', 'default' => '',
                          'help' => 'http(s) or root-relative. Empty falls back to a colored initial circle.'],
                     ]],
                ],
                render: function (array $context, array $settings): string {
                    $items = is_array($settings['items'] ?? null) ? $settings['items'] : [];
                    if (empty($items)) return '';
                    $heading = trim((string) ($settings['heading'] ?? ''));
                    $layout  = ($settings['layout'] ?? 'grid') === 'single' ? 'single' : 'grid';

                    $renderQuote = function (array $t, bool $featured) {
                        $quote  = htmlspecialchars((string) ($t['quote']  ?? ''), ENT_QUOTES | ENT_HTML5);
                        $author = htmlspecialchars((string) ($t['author'] ?? ''), ENT_QUOTES | ENT_HTML5);
                        $role   = htmlspecialchars((string) ($t['role']   ?? ''), ENT_QUOTES | ENT_HTML5);

                        $avatar = (string) ($t['avatar_url'] ?? '');
                        if ($avatar !== '' && !preg_match('#^(?:https?://|/)#i', $avatar)) $avatar = '';

                        $initial = strtoupper(substr((string) ($t['author'] ?? '?'), 0, 1));
                        $avatarHtml = $avatar !== ''
                            ? '<img src="' . htmlspecialchars($avatar, ENT_QUOTES | ENT_HTML5) . '" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0">'
                            : '<div style="width:44px;height:44px;border-radius:50%;background:var(--color-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0">' . htmlspecialchars($initial, ENT_QUOTES | ENT_HTML5) . '</div>';

                        $qSize = $featured ? '1.25rem' : '14.5px';
                        return '<div style="background:#fff;border:1px solid var(--border-default);border-radius:12px;padding:1.5rem 1.25rem">'
                             . '<div style="font-size:' . $qSize . ';line-height:1.55;color:var(--text-default)">"' . $quote . '"</div>'
                             . '<div style="display:flex;align-items:center;gap:.75rem;margin-top:1rem">'
                             . $avatarHtml
                             . '<div><div style="font-weight:600;color:var(--text-default);font-size:13.5px">' . $author . '</div>'
                             . ($role !== '' ? '<div style="color:var(--text-muted);font-size:12.5px">' . $role . '</div>' : '')
                             . '</div></div></div>';
                    };

                    $h = '<div style="padding:1rem 0">';
                    if ($heading !== '') {
                        $h .= '<h2 style="text-align:center;font-size:1.5rem;font-weight:700;margin:0 0 1.5rem;color:var(--text-default)">'
                            . htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5) . '</h2>';
                    }
                    if ($layout === 'single') {
                        $h .= '<div style="max-width:700px;margin:0 auto">' . $renderQuote($items[0], true) . '</div>';
                    } else {
                        $count = max(1, min(3, count($items)));
                        $h .= '<div style="display:grid;gap:1rem;grid-template-columns:repeat(' . $count . ',minmax(220px,1fr))">';
                        foreach ($items as $t) $h .= $renderQuote($t, false);
                        $h .= '</div>';
                    }
                    return $h . '</div>';
                }
            ),

            // ── Feature Grid ───────────────────────────────────────────
            // Icon + title + description tile, repeated in a responsive
            // grid. `icon` accepts an emoji or short text label; for
            // SVG icons use siteblocks.html instead.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.feature_grid',
                label:       'Feature Grid',
                description: 'Grid of icon + title + description tiles. Settings: heading, columns (2/3/4), features[].',
                category:    'Marketing',
                defaultSize: 'large',
                defaultSettings: ['heading' => '', 'columns' => 3, 'features' => []],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'heading',  'label' => 'Heading (optional)', 'type' => 'text',   'default' => ''],
                    ['key' => 'columns',  'label' => 'Columns',            'type' => 'select', 'default' => 3,
                     'options' => [2 => '2', 3 => '3', 4 => '4']],
                    ['key' => 'features', 'label' => 'Features', 'type' => 'repeater', 'default' => [],
                     'item_label' => 'Feature',
                     'item_schema' => [
                         ['key' => 'icon',        'label' => 'Icon',        'type' => 'text',     'default' => '✨',
                          'placeholder' => '✨', 'help' => 'Emoji or short text. For SVG icons use the Raw HTML block instead.'],
                         ['key' => 'title',       'label' => 'Title',       'type' => 'text',     'default' => ''],
                         ['key' => 'description', 'label' => 'Description (optional)', 'type' => 'textarea', 'default' => ''],
                     ]],
                ],
                render: function (array $context, array $settings): string {
                    $features = is_array($settings['features'] ?? null) ? $settings['features'] : [];
                    if (empty($features)) return '';
                    $heading = trim((string) ($settings['heading'] ?? ''));
                    $cols    = max(1, min(4, (int) ($settings['columns'] ?? 3)));

                    $tilesHtml = '';
                    foreach ($features as $f) {
                        $icon  = htmlspecialchars((string) ($f['icon']        ?? '✨'), ENT_QUOTES | ENT_HTML5);
                        $title = htmlspecialchars((string) ($f['title']       ?? ''),   ENT_QUOTES | ENT_HTML5);
                        $desc  = htmlspecialchars((string) ($f['description'] ?? ''),   ENT_QUOTES | ENT_HTML5);
                        $tilesHtml .= '<div style="padding:1.25rem 1rem">'
                                    . '<div style="font-size:1.75rem;line-height:1">' . $icon . '</div>'
                                    . '<div style="font-weight:600;color:var(--text-default);margin-top:.65rem;font-size:14.5px">' . $title . '</div>'
                                    . ($desc !== '' ? '<div style="color:var(--text-muted);margin-top:.35rem;font-size:13.5px;line-height:1.5">' . $desc . '</div>' : '')
                                    . '</div>';
                    }

                    $h = '<div style="padding:1rem 0">';
                    if ($heading !== '') {
                        $h .= '<h2 style="text-align:center;font-size:1.5rem;font-weight:700;margin:0 0 1.5rem;color:var(--text-default)">'
                            . htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5) . '</h2>';
                    }
                    $h .= '<div style="display:grid;gap:.5rem;grid-template-columns:repeat(' . $cols . ',minmax(180px,1fr))">'
                        . $tilesHtml . '</div></div>';
                    return $h;
                }
            ),

            // ── Logo Cloud ─────────────────────────────────────────────
            // Row of "trusted by" logos. Each logo is an <img> wrapped
            // in an optional link. With grayscale=true logos render
            // muted by default and color on hover, the standard
            // trusted-by pattern.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.logo_cloud',
                label:       'Logo Cloud',
                description: 'Row of partner/client/customer logos. Settings: heading, grayscale, logos[{src, alt, link}].',
                category:    'Marketing',
                defaultSize: 'medium',
                defaultSettings: ['heading' => 'Trusted by teams at', 'grayscale' => true, 'logos' => []],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'heading',   'label' => 'Heading (optional)', 'type' => 'text',     'default' => 'Trusted by teams at'],
                    ['key' => 'grayscale', 'label' => 'Grayscale by default (color on hover)', 'type' => 'checkbox', 'default' => true],
                    ['key' => 'logos', 'label' => 'Logos', 'type' => 'repeater', 'default' => [],
                     'item_label' => 'Logo',
                     'item_schema' => [
                         ['key' => 'src',  'label' => 'Image URL',          'type' => 'text', 'default' => '',
                          'help' => 'http(s), root-relative, or data: URI. Other URL schemes are dropped silently at render.'],
                         ['key' => 'alt',  'label' => 'Alt text',           'type' => 'text', 'default' => ''],
                         ['key' => 'link', 'label' => 'Link URL (optional)', 'type' => 'text', 'default' => '',
                          'help' => 'http(s) or root-relative. Other schemes are dropped.'],
                     ]],
                ],
                render: function (array $context, array $settings): string {
                    $logos = is_array($settings['logos'] ?? null) ? $settings['logos'] : [];
                    if (empty($logos)) return '';
                    $heading   = trim((string) ($settings['heading'] ?? ''));
                    $grayscale = !empty($settings['grayscale']);

                    $imgStyle = 'max-height:32px;max-width:140px;object-fit:contain;'
                              . ($grayscale ? 'filter:grayscale(1);opacity:.55;transition:filter .2s,opacity .2s' : '');

                    $logosHtml = '';
                    foreach ($logos as $l) {
                        $src = (string) ($l['src'] ?? '');
                        if ($src === '' || !preg_match('#^(?:https?://|/|data:)#i', $src)) continue;
                        $src = htmlspecialchars($src, ENT_QUOTES | ENT_HTML5);
                        $alt = htmlspecialchars((string) ($l['alt'] ?? ''), ENT_QUOTES | ENT_HTML5);

                        $link = (string) ($l['link'] ?? '');
                        if ($link !== '' && !preg_match('#^(?:https?://|/)#i', $link)) $link = '';

                        $imgHtml = '<img src="' . $src . '" alt="' . $alt . '" style="' . $imgStyle . '"'
                                 . ($grayscale ? ' onmouseover="this.style.filter=\'\';this.style.opacity=1" onmouseout="this.style.filter=\'grayscale(1)\';this.style.opacity=0.55"' : '')
                                 . '>';
                        $logosHtml .= $link !== ''
                            ? '<a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_HTML5) . '" rel="noopener noreferrer">' . $imgHtml . '</a>'
                            : $imgHtml;
                    }
                    if ($logosHtml === '') return '';

                    $h = '<div style="padding:1.5rem 0">';
                    if ($heading !== '') {
                        $h .= '<div style="text-align:center;color:var(--text-muted);font-size:12.5px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-bottom:1.25rem">'
                            . htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5) . '</div>';
                    }
                    $h .= '<div style="display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:1.5rem 2.5rem">'
                        . $logosHtml . '</div></div>';
                    return $h;
                }
            ),

            // ── Stats Showcase ─────────────────────────────────────────
            // Big-number row. "10K+ customers", "99.9% uptime",
            // "$12M raised". prefix and suffix wrap the value so the
            // accent stays on the number itself. Auto-fits to 1-5
            // columns based on item count.
            new \Core\Module\BlockDescriptor(
                key:         'siteblocks.stats_showcase',
                label:       'Stats Showcase',
                description: 'Row of big-number stats with optional prefix/suffix. Settings: heading, stats[{value, label, prefix, suffix}].',
                category:    'Marketing',
                defaultSize: 'large',
                defaultSettings: ['heading' => '', 'stats' => []],
                audience:    'any',
                settingsSchema: [
                    ['key' => 'heading', 'label' => 'Heading (optional)', 'type' => 'text', 'default' => ''],
                    ['key' => 'stats', 'label' => 'Stats', 'type' => 'repeater', 'default' => [],
                     'item_label' => 'Stat',
                     'item_schema' => [
                         ['key' => 'value',  'label' => 'Big value',     'type' => 'text', 'default' => '', 'placeholder' => '99.9'],
                         ['key' => 'label',  'label' => 'Label below',   'type' => 'text', 'default' => '', 'placeholder' => 'Uptime'],
                         ['key' => 'prefix', 'label' => 'Prefix (optional)', 'type' => 'text', 'default' => '', 'placeholder' => '$'],
                         ['key' => 'suffix', 'label' => 'Suffix (optional)', 'type' => 'text', 'default' => '', 'placeholder' => '%'],
                     ]],
                ],
                render: function (array $context, array $settings): string {
                    $stats = is_array($settings['stats'] ?? null) ? $settings['stats'] : [];
                    if (empty($stats)) return '';
                    $heading = trim((string) ($settings['heading'] ?? ''));

                    $cellsHtml = '';
                    foreach ($stats as $s) {
                        $value  = htmlspecialchars((string) ($s['value']  ?? ''), ENT_QUOTES | ENT_HTML5);
                        $label  = htmlspecialchars((string) ($s['label']  ?? ''), ENT_QUOTES | ENT_HTML5);
                        $prefix = htmlspecialchars((string) ($s['prefix'] ?? ''), ENT_QUOTES | ENT_HTML5);
                        $suffix = htmlspecialchars((string) ($s['suffix'] ?? ''), ENT_QUOTES | ENT_HTML5);
                        $cellsHtml .= '<div style="text-align:center;padding:1rem">'
                                    . '<div style="font-size:2.5rem;font-weight:800;color:var(--color-primary);line-height:1.1">'
                                    .   ($prefix !== '' ? '<span style="font-size:1.5rem;font-weight:700;vertical-align:top;color:#6366f1">' . $prefix . '</span>' : '')
                                    .   $value
                                    .   ($suffix !== '' ? '<span style="font-size:1.5rem;font-weight:700;color:#6366f1">' . $suffix . '</span>' : '')
                                    . '</div>'
                                    . ($label !== '' ? '<div style="color:var(--text-muted);font-size:13px;margin-top:.35rem;text-transform:uppercase;letter-spacing:.05em;font-weight:600">' . $label . '</div>' : '')
                                    . '</div>';
                    }

                    $cols = max(1, min(5, count($stats)));
                    $h = '<div style="padding:1.5rem 0">';
                    if ($heading !== '') {
                        $h .= '<h2 style="text-align:center;font-size:1.5rem;font-weight:700;margin:0 0 1.5rem;color:var(--text-default)">'
                            . htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5) . '</h2>';
                    }
                    $h .= '<div style="display:grid;gap:.5rem;grid-template-columns:repeat(' . $cols . ',minmax(140px,1fr))">'
                        . $cellsHtml . '</div></div>';
                    return $h;
                }
            ),
        ];
    }

    /**
     * GDPR handlers — newsletter signups have no user_id (collected
     * pre-account) but they DO have email PII. We can't reliably link
     * a newsletter signup to a user_id at erasure time, so we don't
     * declare a registry handler for it. Instead, the canonical
     * pattern is to email-match: when a user's account is erased,
     * the framework's mail_suppressions row (created by the email
     * compliance module) ensures the (now-orphaned) newsletter signup
     * never receives mail — even if the row itself sticks around.
     *
     * Newsletter_signups stays as legal-hold consent evidence, same
     * shape as cookie_consents.
     */
    public function gdprHandlers(): array
    {
        if (!class_exists(\Modules\Gdpr\Services\GdprHandler::class)) return [];

        return [];
    }
};
