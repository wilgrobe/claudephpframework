<?php
// modules/feedback/module.php
use Core\Module\ModuleProvider;

/**
 * Feedback module — a standardized public /feedback page for every site.
 *
 * Visitors can leave feedback (anonymously or with their name/email, and if
 * identified may request a reply) or submit a testimonial (never anonymous).
 * Prompts nudge useful answers ("What did you think of…", "What suggestions…",
 * "Tell us about your experience"). Submissions land in an admin queue at
 * /admin/site-feedback; published testimonials can be showcased via the
 * `feedback.testimonials` block or the public /testimonials page.
 *
 * Inclusion is a single wizard toggle: the `builder.feedback.enabled` site
 * setting (default off) gates the public /feedback + /testimonials pages and
 * the footer link. The admin queue is always reachable for site admins.
 */
return new class extends ModuleProvider {
    public function name(): string { return 'feedback'; }

    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function register(\Core\Container\Container $c): void
    {
        // In-app alert raised for site admins when a customer files an issue
        // report from the corner widget. Registering it (rather than relying
        // on the send() call alone) is what puts the type in the user's
        // notification-preferences list, so an admin can mute the bell and
        // keep the email — or the reverse.
        if (method_exists(\Core\Services\NotificationService::class, 'registerType')) {
            \Core\Services\NotificationService::registerType('feedback.issue_reported', [
                'label'    => 'Issue reported on your site',
                'group'    => 'Site',
                'channels' => ['in_app', 'email'],
            ]);
        }
    }

    public function pages(): array
    {
        return [
            new \Core\Module\PageDescriptor(
                slug:        '/feedback',
                label:       'Feedback page',
                description: 'Standardized feedback form (anonymous or identified, optional reply request) + testimonials.',
                public:      true,
                editable:    false,
                icon:        '💬',
                module:      'feedback',
            ),
        ];
    }

    public function blocks(): array
    {
        return [
            // Showcase published testimonials on any composer page.
            new \Core\Module\BlockDescriptor(
                key:         'feedback.testimonials',
                label:       'Testimonials',
                description: 'Grid of published testimonials (from the Feedback page). Settings: limit, heading.',
                category:    'Social proof',
                defaultSize: 'large',
                defaultSettings: ['limit' => 6, 'heading' => 'What people are saying'],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $limit   = max(1, min(24, (int) ($settings['limit'] ?? 6)));
                    $heading = (string) ($settings['heading'] ?? 'What people are saying');
                    try {
                        $rows = \Core\Database\Database::getInstance()->fetchAll(
                            "SELECT name, message, rating FROM feedback_submissions
                              WHERE kind = 'testimonial' AND status = 'published'
                              ORDER BY responded_at DESC, id DESC LIMIT ?",
                            [$limit]
                        );
                    } catch (\Throwable) {
                        $rows = [];
                    }
                    if (empty($rows)) return '';

                    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5);
                    $h = '<section style="max-width:1100px;margin:0 auto;padding:1.5rem 1rem;">'
                       . '<h2 style="text-align:center;font-size:1.5rem;font-weight:700;margin:0 0 1.5rem;color:var(--text-default)">' . $e($heading) . '</h2>'
                       . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">';
                    foreach ($rows as $r) {
                        $stars = '';
                        $rating = (int) ($r['rating'] ?? 0);
                        if ($rating > 0) {
                            $stars = '<div style="color:#f59e0b;font-size:14px;margin-bottom:.5rem;">'
                                   . str_repeat('★', min(5, $rating)) . str_repeat('☆', max(0, 5 - $rating)) . '</div>';
                        }
                        $h .= '<figure class="card" style="margin:0;"><div class="card-body">'
                            . $stars
                            . '<blockquote style="margin:0 0 .75rem;font-size:14.5px;line-height:1.6;color:var(--text-default)">“' . $e($r['message']) . '”</blockquote>'
                            . '<figcaption style="font-size:13px;font-weight:600;color:var(--color-primary)">— ' . $e($r['name'] ?: 'A happy customer') . '</figcaption>'
                            . '</div></figure>';
                    }
                    return $h . '</div></section>';
                }
            ),

            // A compact "share your feedback" call-to-action card.
            new \Core\Module\BlockDescriptor(
                key:         'feedback.cta',
                label:       'Feedback call-to-action',
                description: 'A card inviting visitors to share feedback, linking to the Feedback page.',
                category:    'Social proof',
                defaultSize: 'medium',
                defaultSettings: ['heading' => 'We’d love your feedback', 'body' => 'Tell us what you think — it only takes a minute.'],
                audience:    'any',
                render: function (array $context, array $settings): string {
                    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5);
                    $heading = (string) ($settings['heading'] ?? 'We’d love your feedback');
                    $body    = (string) ($settings['body'] ?? 'Tell us what you think — it only takes a minute.');
                    return '<div class="card" style="text-align:center;"><div class="card-body" style="padding:1.75rem 1.25rem;">'
                         . '<div style="font-size:1.75rem;margin-bottom:.35rem;">💬</div>'
                         . '<h3 style="margin:0 0 .35rem;font-size:1.1rem;color:var(--text-default)">' . $e($heading) . '</h3>'
                         . '<p style="margin:0 0 1rem;color:var(--text-subtle);font-size:13.5px;">' . $e($body) . '</p>'
                         . '<a href="/feedback" class="btn btn-primary">Share your feedback</a>'
                         . '</div></div>';
                }
            ),
        ];
    }
};
