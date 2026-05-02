<?php
// modules/profile/module.php
use Core\Module\ModuleProvider;

/**
 * Profile module — user's own profile view + edit (name, avatar, bio, phone,
 * password). Does NOT include the 2FA settings routes: /profile/2fa/* routes
 * stay in core via TwoFactorController because 2FA is a framework primitive
 * that authentication flows depend on.
 */
return new class extends ModuleProvider {
    public function name(): string            { return 'profile'; }
    public function routesFile(): ?string     { return __DIR__ . '/routes.php'; }
    public function viewsPath(): ?string      { return __DIR__ . '/Views'; }
    public function migrationsPath(): ?string { return __DIR__ . '/migrations'; }

    public function blocks(): array
    {
        // Field weights for the completeness meter — declared once and
        // referenced by both the meter render and the percentage math.
        // Fields not listed here (created_at, password_hash, etc) don't
        // count toward completeness. Total weight should sum to 100.
        $completenessFields = [
            ['key' => 'first_name',        'label' => 'First name',  'weight' => 15],
            ['key' => 'last_name',         'label' => 'Last name',   'weight' => 15],
            ['key' => 'email',             'label' => 'Email',       'weight' => 10],
            ['key' => 'email_verified_at', 'label' => 'Email verified', 'weight' => 15],
            ['key' => 'avatar_url',        'label' => 'Profile photo', 'weight' => 10],
            ['key' => 'bio',               'label' => 'Bio',         'weight' => 10],
            ['key' => 'phone',             'label' => 'Phone',       'weight' => 10],
            ['key' => 'two_factor_enabled','label' => 'Two-factor auth', 'weight' => 15],
        ];

        return [
            // ── User Card ──────────────────────────────────────────────
            // Avatar (or initial letter fallback), name, optional role
            // badges. Useful in headers, group sidebars, and profile
            // landing pages.
            new \Core\Module\BlockDescriptor(
                key:         'profile.user_card',
                label:       'User Card',
                description: 'Avatar, name, role badges for the viewing user. Compact tile.',
                category:    'Profile',
                defaultSize: 'small',
                audience:    'auth',
                render: function (array $context, array $settings): string {
                    $auth = \Core\Auth\Auth::getInstance();
                    if ($auth->guest()) return '';

                    $user  = $auth->user();
                    $first = (string) ($user['first_name'] ?? '');
                    $last  = (string) ($user['last_name'] ?? '');
                    $name  = trim($first . ' ' . $last);
                    if ($name === '') $name = (string) ($user['username'] ?? $user['email'] ?? 'You');
                    $avatar = (string) ($user['avatar_url'] ?? '');
                    $initial = strtoupper(substr($first !== '' ? $first : ($user['username'] ?? '?'), 0, 1));

                    // Role badges from the user's roles array. SA mode wins.
                    $badges = '';
                    if ($auth->isSuperAdmin()) {
                        $badges .= '<span class="badge badge-danger" style="font-size:10.5px;margin-left:.35rem">SA</span>';
                    }
                    if ($auth->hasRole('admin')) {
                        $badges .= '<span class="badge badge-primary" style="font-size:10.5px;margin-left:.35rem">admin</span>';
                    }

                    $avatarHtml = $avatar !== ''
                        ? '<img src="' . htmlspecialchars($avatar, ENT_QUOTES | ENT_HTML5) . '" alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover;flex-shrink:0">'
                        : '<div style="width:48px;height:48px;border-radius:50%;background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem;flex-shrink:0">' . htmlspecialchars($initial, ENT_QUOTES | ENT_HTML5) . '</div>';

                    return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem;display:flex;align-items:center;gap:.85rem">'
                         . $avatarHtml
                         . '<div style="min-width:0;flex:1">'
                         .   '<div style="font-weight:600;color:#111827;font-size:14.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
                         .     htmlspecialchars($name, ENT_QUOTES | ENT_HTML5) . $badges
                         .   '</div>'
                         .   '<div style="color:#9ca3af;font-size:11.5px;margin-top:.15rem">'
                         .     '<a href="/profile" style="color:#4f46e5;text-decoration:none">View profile</a>'
                         .   '</div>'
                         . '</div>'
                         . '</div></div>';
                }
            ),

            // ── Completeness Meter ─────────────────────────────────────
            // Onboarding nudge: shows what % of the viewer's profile is
            // populated and which fields are missing. Each field has a
            // weight so verifying email + enabling 2FA contribute more
            // than filling in a phone number.
            new \Core\Module\BlockDescriptor(
                key:         'profile.completeness_meter',
                label:       'Profile Completeness Meter',
                description: 'Onboarding nudge showing what % of the viewer\'s profile is populated, with the missing fields listed.',
                category:    'Profile',
                defaultSize: 'small',
                audience:    'auth',
                render: function (array $context, array $settings) use ($completenessFields): string {
                    $auth = \Core\Auth\Auth::getInstance();
                    if ($auth->guest()) return '';

                    $user = $auth->user();
                    $earned = 0;
                    $total  = 0;
                    $missing = [];
                    foreach ($completenessFields as $f) {
                        $total += $f['weight'];
                        if (!empty($user[$f['key']])) {
                            $earned += $f['weight'];
                        } else {
                            $missing[] = $f['label'];
                        }
                    }
                    $pct = $total > 0 ? (int) round(($earned * 100) / $total) : 100;

                    // 100% — congratulate quickly and stay out of the way.
                    if ($pct === 100) {
                        return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem;text-align:center">'
                             . '<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;font-weight:600">Profile</div>'
                             . '<div style="font-size:1.5rem;font-weight:700;color:#10b981;margin-top:.35rem">100%</div>'
                             . '<div style="font-size:12.5px;color:#6b7280;margin-top:.15rem">Complete — nice!</div>'
                             . '</div></div>';
                    }

                    $barColor = $pct >= 70 ? '#10b981' : ($pct >= 40 ? '#f59e0b' : '#dc2626');
                    $missingShort = array_slice($missing, 0, 3);
                    $remaining = count($missing) - count($missingShort);
                    $missingTxt = htmlspecialchars(implode(', ', $missingShort) . ($remaining > 0 ? " +$remaining more" : ''), ENT_QUOTES | ENT_HTML5);

                    return '<div class="card"><div class="card-body" style="padding:1rem 1.25rem">'
                         . '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.5rem">'
                         .   '<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;font-weight:600">Profile</div>'
                         .   '<div style="font-size:1.1rem;font-weight:700;color:' . $barColor . '">' . $pct . '%</div>'
                         . '</div>'
                         . '<div style="background:#e5e7eb;height:6px;border-radius:3px;overflow:hidden">'
                         .   '<div style="background:' . $barColor . ';height:100%;width:' . $pct . '%;transition:width .3s"></div>'
                         . '</div>'
                         . '<div style="font-size:12px;color:#6b7280;margin-top:.5rem;line-height:1.4">Add: ' . $missingTxt . '</div>'
                         . '<a href="/profile/edit" style="display:block;text-align:center;font-size:12.5px;color:#4f46e5;text-decoration:none;margin-top:.65rem;font-weight:500">Complete profile →</a>'
                         . '</div></div>';
                }
            ),

            // ── Account Quick Actions ──────────────────────────────────
            // Link grid to the user's most-used self-service surfaces.
            // Each link is gated on the relevant module being active so
            // disabling e.g. the messaging module hides "Messages" from
            // the grid (matches the layout-chrome gating pattern).
            new \Core\Module\BlockDescriptor(
                key:         'profile.account_quick_actions',
                label:       'Account Quick Actions',
                description: 'Link grid to common account self-service surfaces (profile, 2FA, sessions, billing, API keys, messages).',
                category:    'Profile',
                defaultSize: 'medium',
                audience:    'auth',
                render: function (array $context, array $settings): string {
                    $auth = \Core\Auth\Auth::getInstance();
                    if ($auth->guest()) return '';

                    // Each entry: [icon, label, url, module-name (for active-check) or null]
                    $actions = [
                        ['👤', 'Profile',          '/profile',          'profile'],
                        ['🛡️', 'Two-Factor Auth',  '/profile/2fa',      'profile'],
                        ['📱', 'Active Sessions',  '/account/sessions', null],
                        ['💳', 'Billing',          '/billing',          'subscriptions'],
                        ['🔑', 'API Keys',         '/account/api-keys', 'api_keys'],
                        ['💬', 'Messages',         '/messages',         'messaging'],
                    ];

                    // Filter out entries whose owning module is disabled.
                    $available = array_filter($actions, function ($a) {
                        if ($a[3] === null) return true;
                        return function_exists('module_active') ? module_active($a[3]) : true;
                    });

                    // Account-sessions is gated by a separate site setting,
                    // not a module. Filter that one explicitly.
                    if (function_exists('setting') && !(bool) setting('account_sessions_enabled', true)) {
                        $available = array_filter($available, fn($a) => $a[2] !== '/account/sessions');
                    }

                    $h = '<div class="card"><div class="card-header"><h3 style="margin:0;font-size:.95rem">Quick Actions</h3></div>'
                       . '<div class="card-body" style="padding:.5rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.4rem">';
                    foreach ($available as $a) {
                        [$icon, $label, $url, ] = $a;
                        $h .= '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) . '" '
                            . 'style="display:flex;align-items:center;gap:.5rem;padding:.65rem .75rem;background:#f9fafb;border:1px solid #f3f4f6;border-radius:6px;text-decoration:none;color:#111827;font-size:13px;transition:background .15s">'
                            . '<span style="font-size:1.05rem">' . $icon . '</span>'
                            . '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5) . '</span>'
                            . '</a>';
                    }
                    return $h . '</div></div>';
                }
            ),
        ];
    }
};
