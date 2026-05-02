<?php $pageTitle = 'Privacy & Compliance'; $activePanel = 'privacy'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">Privacy &amp; Compliance</h1>
<p style="color:#6b7280;font-size:13.5px;margin:0 0 1.25rem;max-width:560px">
    Master toggles for the regulatory modules. Detailed configuration —
    banner copy, opt-out form text, retention schedules — lives on each
    module's dedicated admin page; the links below jump straight there.
</p>

<div class="card">
    <form method="post" action="/admin/settings/privacy">
        <?= csrf_field() ?>
        <div class="card-body">

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('cookieconsent_enabled', !empty($values['cookieconsent_enabled']) && $values['cookieconsent_enabled'] !== 'false') ?>
                    GDPR cookie-consent banner
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    When on, shows the banner until each visitor accepts or rejects.
                    Configure banner text + per-category descriptions on the
                    <a href="/admin/cookieconsent" style="color:#4338ca;text-decoration:underline">Cookie Consent</a> page.
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-top:.75rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('ccpa_enabled', !empty($values['ccpa_enabled']) && $values['ccpa_enabled'] !== 'false') ?>
                    CCPA "Do Not Sell" footer link + opt-out form
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    California compliance. Adds the link to the footer and exposes
                    <code>/do-not-sell</code>. Configure label + disclosure URL on the
                    <a href="/admin/ccpa" style="color:#4338ca;text-decoration:underline">CCPA admin page</a>.
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-top:.75rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('ccpa_honor_gpc_signal', !empty($values['ccpa_honor_gpc_signal']) && $values['ccpa_honor_gpc_signal'] !== 'false') ?>
                    Honor browser <code>Sec-GPC: 1</code> signal automatically
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    When the visitor's browser sends Global Privacy Control,
                    treat it as an automatic opt-out without requiring them
                    to click the form. Recommended on.
                </div>
            </div>

        </div>
        <div class="card-body" style="background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save Privacy</button>
        </div>
    </form>
</div>

<!-- Operational links — these are tools, not settings -->
<div class="card" style="margin-top:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:.95rem">Compliance tools</h3></div>
    <div class="card-body" style="display:grid;gap:.5rem;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr))">
        <a href="/admin/gdpr" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>GDPR / DSAR queue</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Export + erasure requests, with SLA tracking.</div>
        </a>
        <a href="/admin/policies" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Policies</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">ToS / Privacy Policy versioning + acceptance log.</div>
        </a>
        <a href="/admin/retention" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Data retention</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Per-table retention rules + dry-run + sweep.</div>
        </a>
        <a href="/admin/audit-log" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Audit log</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">HMAC-chained event log of admin + user actions.</div>
        </a>
    </div>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
