<?php $pageTitle = 'Cookie Consent'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:880px;margin:0 auto">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <div>
        <div style="font-size:12px;color:#6b7280">
            <a href="/admin" style="color:#4f46e5;text-decoration:none">← Admin</a>
        </div>
        <h1 style="margin:.25rem 0 0;font-size:1.3rem;font-weight:700">Cookie Consent</h1>
        <p style="margin:.25rem 0 0;color:#6b7280;font-size:13px">
            GDPR-style consent banner shown to every visitor until they accept,
            reject, or customise their cookie preferences. Bump the policy
            version to re-prompt all visitors after a policy change.
        </p>
    </div>
</div>

<!-- ── Activity summary (last 30 days) ──────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-body" style="display:flex;gap:1rem;flex-wrap:wrap;padding:1rem 1.25rem">
        <?php
        $stats   = $stats ?? [];
        $cards   = [
            ['Accepts',   (int) ($stats['accepts']   ?? 0), '#10b981'],
            ['Rejects',   (int) ($stats['rejects']   ?? 0), '#ef4444'],
            ['Customs',   (int) ($stats['customs']   ?? 0), '#3b82f6'],
            ['Withdraws', (int) ($stats['withdraws'] ?? 0), '#f59e0b'],
            ['Total',     (int) ($stats['total']     ?? 0), '#6b7280'],
        ];
        foreach ($cards as [$label, $value, $color]):
        ?>
            <div style="flex:1 1 140px;text-align:center;padding:.5rem;border-left:3px solid <?= $color ?>;background:#fafafa;border-radius:4px">
                <div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em"><?= $label ?></div>
                <div style="font-size:1.4rem;font-weight:700;margin-top:.15rem"><?= $value ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="padding:.5rem 1.25rem;font-size:12px;color:#6b7280;border-top:1px solid #f3f4f6">
        Activity in the last 30 days. Detail rows below show the most recent 50 events.
    </div>
</div>

<!-- ── Settings form ─────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <form method="POST" action="/admin/cookie-consent">
        <?= csrf_field() ?>
        <div class="card-body" style="padding:1.25rem">

            <!-- Master toggle -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= function_exists('toggle_switch')
                        ? toggle_switch('cookieconsent_enabled', !empty($values['cookieconsent_enabled']) && $values['cookieconsent_enabled'] !== 'false')
                        : '<input type="checkbox" name="cookieconsent_enabled" value="1"' . ((!empty($values['cookieconsent_enabled']) && $values['cookieconsent_enabled'] !== 'false') ? ' checked' : '') . '>'
                    ?>
                    Cookie consent banner enabled
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Master switch. When off, the banner never renders and
                    <code>consent_allowed()</code> returns false for every non-essential
                    category. Existing consent records in the database stay intact.
                </div>
            </div>

            <!-- Policy version + bump button -->
            <div class="form-group" style="margin-bottom:1.25rem">
                <label for="cookieconsent_policy_version" style="display:block;font-weight:500;margin-bottom:.35rem">Policy version</label>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <input type="text" name="cookieconsent_policy_version"
                           value="<?= htmlspecialchars((string) ($values['cookieconsent_policy_version'] ?? '1'), ENT_QUOTES) ?>"
                           style="max-width:120px" id="cookieconsent_policy_version">
                    <span style="font-size:12.5px;color:#6b7280">
                        Bump this number after any change to the cookies you set or to
                        the policy text. Every visitor will see the banner again on
                        their next page view.
                    </span>
                </div>
                <div style="margin-top:.5rem">
                    <form method="POST" action="/admin/cookie-consent/bump-version" style="display:inline"
                          data-confirm="Re-prompt all visitors? They'll see the banner on their next page view.">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-secondary"
                                style="padding:.35rem .75rem;font-size:12px">
                            Bump version + re-prompt everyone
                        </button>
                    </form>
                </div>
            </div>

            <!-- Policy URL -->
            <div class="form-group" style="margin-bottom:1.25rem">
                <label for="cookieconsent_policy_url" style="display:block;font-weight:500;margin-bottom:.35rem">Cookie policy URL</label>
                <input type="text" name="cookieconsent_policy_url"
                       value="<?= htmlspecialchars((string) ($values['cookieconsent_policy_url'] ?? ''), ENT_QUOTES)?>"
                       placeholder="/page/cookie-policy" style="width:100%" aria-label="/page/cookie-policy">
                <div style="font-size:12.5px;color:#6b7280;margin-top:.25rem">
                    Linked from the banner's "Read our cookie policy →" hyperlink.
                    Typically points at a public page you've created at
                    <code>/page/cookie-policy</code>.
                </div>
            </div>

            <hr style="margin:1.25rem 0;border:0;border-top:1px solid #e5e7eb">

            <!-- Banner copy -->
            <h2 style="font-size:1rem;margin:0 0 .75rem">Banner copy</h2>

            <div class="form-group" style="margin-bottom:1rem">
                <label for="cookieconsent_title" style="display:block;font-weight:500;margin-bottom:.35rem">Title</label>
                <input type="text" name="cookieconsent_title"
                       value="<?= htmlspecialchars((string) ($values['cookieconsent_title'] ?? ''), ENT_QUOTES)?>"
                       style="width:100%" aria-label="Cookieconsent title">
            </div>

            <div class="form-group" style="margin-bottom:1.25rem">
                <label for="cookieconsent_body" style="display:block;font-weight:500;margin-bottom:.35rem">Body text</label>
                <textarea name="cookieconsent_body" rows="3" style="width:100%" id="cookieconsent_body"><?= htmlspecialchars((string) ($values['cookieconsent_body'] ?? ''), ENT_QUOTES) ?></textarea>
            </div>

            <hr style="margin:1.25rem 0;border:0;border-top:1px solid #e5e7eb">

            <!-- Per-category copy -->
            <h2 style="font-size:1rem;margin:0 0 .75rem">Categories</h2>
            <p style="margin:0 0 1rem;font-size:12.5px;color:#6b7280">
                Labels and descriptions shown for each cookie category in the
                Customize modal. Edit to match the cookies your site actually
                sets.
            </p>

            <?php foreach (['necessary','preferences','analytics','marketing'] as $cat): ?>
                <div class="form-group" style="margin-bottom:1rem;padding:.75rem 1rem;border:1px solid #e5e7eb;border-radius:6px">
                    <label style="display:block;font-weight:600;margin-bottom:.5rem;text-transform:capitalize"><?= $cat ?></label>

                    <label for="cc-label-<?= $cat ?>" style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">Label shown in the banner</label>
                    <input type="text" id="cc-label-<?= $cat ?>" name="cookieconsent_label_<?= $cat ?>"
                           value="<?= htmlspecialchars((string) ($values['cookieconsent_label_'.$cat] ?? ''), ENT_QUOTES) ?>"
                           style="width:100%;margin-bottom:.5rem">

                    <label for="cc-desc-<?= $cat ?>" style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">Description shown in the Customize modal</label>
                    <textarea id="cc-desc-<?= $cat ?>" name="cookieconsent_desc_<?= $cat ?>" rows="2" style="width:100%"><?= htmlspecialchars((string) ($values['cookieconsent_desc_'.$cat] ?? ''), ENT_QUOTES) ?></textarea>
                </div>
            <?php endforeach; ?>

        </div>
        <div class="card-footer" style="display:flex;justify-content:flex-end;padding:.85rem 1.25rem;background:#f9fafb;border-top:1px solid #e5e7eb">
            <button type="submit" class="btn btn-primary">Save settings</button>
        </div>
    </form>
</div>

<!-- ── Recent consent events ────────────────────────────────────────── -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:.85rem 1.25rem">
        <h2 style="margin:0;font-size:1rem">Recent consent events</h2>
        <span style="font-size:12px;color:#6b7280">Last 50</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table" style="width:100%;font-size:13px">
            <thead style="background:#f9fafb">
                <tr>
                    <th style="text-align:left;padding:.5rem .75rem">When</th>
                    <th style="text-align:left;padding:.5rem .75rem">User</th>
                    <th style="text-align:left;padding:.5rem .75rem">Anon ID</th>
                    <th style="text-align:left;padding:.5rem .75rem">Action</th>
                    <th style="text-align:center;padding:.5rem .75rem">Pref</th>
                    <th style="text-align:center;padding:.5rem .75rem">Anlx</th>
                    <th style="text-align:center;padding:.5rem .75rem">Mktg</th>
                    <th style="text-align:left;padding:.5rem .75rem">Ver</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="8" style="padding:1rem;text-align:center;color:#6b7280">
                        No consent events recorded yet. The first visitor to interact with the banner will appear here.
                    </td></tr>
                <?php else: foreach ($recent as $row):
                    $colors = [
                        'accept_all' => '#10b981',
                        'reject_all' => '#ef4444',
                        'custom'     => '#3b82f6',
                        'withdraw'   => '#f59e0b',
                    ];
                    $color = $colors[$row['action']] ?? '#6b7280';
                ?>
                    <tr style="border-top:1px solid #f3f4f6">
                        <td style="padding:.5rem .75rem;color:#6b7280;font-size:12px;white-space:nowrap"><?= htmlspecialchars(date('M j, Y g:ia', strtotime((string) $row['created_at'])), ENT_QUOTES) ?></td>
                        <td style="padding:.5rem .75rem">
                            <?= $row['username']
                                ? '<a href="/users/' . htmlspecialchars((string) $row['username'], ENT_QUOTES) . '" style="color:#4f46e5;text-decoration:none">' . htmlspecialchars((string) $row['username'], ENT_QUOTES) . '</a>'
                                : '<span style="color:#9ca3af">(guest)</span>' ?>
                        </td>
                        <td style="padding:.5rem .75rem;font-family:monospace;font-size:11px;color:#9ca3af"><?= htmlspecialchars(substr((string) $row['anon_id'], 0, 8), ENT_QUOTES) ?>…</td>
                        <td style="padding:.5rem .75rem">
                            <span style="display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:11px;font-weight:600;color:#fff;background:<?= $color ?>"><?= htmlspecialchars((string) $row['action'], ENT_QUOTES) ?></span>
                        </td>
                        <td style="padding:.5rem .75rem;text-align:center"><?= $row['preferences'] ? '✓' : '—' ?></td>
                        <td style="padding:.5rem .75rem;text-align:center"><?= $row['analytics']   ? '✓' : '—' ?></td>
                        <td style="padding:.5rem .75rem;text-align:center"><?= $row['marketing']   ? '✓' : '—' ?></td>
                        <td style="padding:.5rem .75rem;font-size:12px;color:#6b7280">v<?= htmlspecialchars((string) $row['policy_version'], ENT_QUOTES) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="margin:1.5rem 0;padding:1rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:12.5px;color:#92400e">
    <strong>For developers:</strong> gate any tracking script with
    <code>&lt;?php if (consent_allowed('analytics')): ?&gt;…&lt;?php endif; ?&gt;</code>.
    Categories are <code>preferences</code>, <code>analytics</code>, <code>marketing</code>
    (and <code>necessary</code> which always returns true).
</div>

</div><!-- /max-width -->

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
