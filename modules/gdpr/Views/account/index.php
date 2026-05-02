<?php $pageTitle = 'Your Data'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:760px;margin:0 auto;padding:0 1rem">

<h1 style="margin:0 0 .25rem;font-size:1.4rem;font-weight:700">Your data &amp; privacy</h1>
<p style="margin:0 0 1.5rem;color:#6b7280;font-size:14px">
    Under the GDPR you have rights over the personal data we hold. You can
    download a copy, restrict how we process it, or delete your account
    entirely. Each action is logged for our records and yours.
</p>

<?php
$inGrace = !empty($user['deletion_grace_until']) && strtotime((string) $user['deletion_grace_until']) > time();
$restricted = !empty($user['processing_restricted_at']);
?>

<?php if ($inGrace): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:1rem 1.25rem;margin-bottom:1.5rem">
        <strong style="color:#991b1b">Account deletion scheduled.</strong>
        <p style="margin:.35rem 0 .75rem;color:#7f1d1d;font-size:13.5px">
            Your account will be permanently erased on
            <strong><?= htmlspecialchars(date('M j, Y g:ia', strtotime((string) $user['deletion_grace_until'])), ENT_QUOTES) ?></strong>.
            We sent a cancel link to <?= htmlspecialchars((string) $user['email'], ENT_QUOTES) ?>.
        </p>
        <a href="/account/data/erase/cancel/<?= htmlspecialchars((string) $user['deletion_token'], ENT_QUOTES) ?>"
           class="btn btn-secondary" style="font-size:13px">Cancel deletion now</a>
    </div>
<?php endif; ?>

<!-- ── Export data ────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-body" style="padding:1.25rem">
        <h2 style="margin:0 0 .5rem;font-size:1.05rem">Export your data</h2>
        <p style="margin:0 0 1rem;color:#6b7280;font-size:13px;line-height:1.6">
            Generate a ZIP file containing every record we hold about your
            account — your profile, comments, posts, orders, and more.
            Organised by module so you can see what we keep and where.
        </p>
        <form method="POST" action="/account/data/export">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" style="font-size:13.5px">
                Build export
            </button>
        </form>

        <?php if (!empty($exports)): ?>
            <div style="margin-top:1rem;border-top:1px solid #f3f4f6;padding-top:.75rem">
                <div style="font-size:12px;color:#6b7280;margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.04em">Recent exports</div>
                <?php foreach ($exports as $exp): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;font-size:13px;border-bottom:1px dashed #f3f4f6">
                        <div>
                            <?= htmlspecialchars(date('M j, Y g:ia', strtotime((string) $exp['requested_at'])), ENT_QUOTES) ?>
                            <span style="color:#6b7280">
                                — <?= htmlspecialchars((string) $exp['status'], ENT_QUOTES) ?>
                                <?= $exp['file_size'] ? '· ' . number_format((int) $exp['file_size'] / 1024, 1) . ' KB' : '' ?>
                            </span>
                        </div>
                        <div>
                            <?php if ($exp['status'] === 'ready' && $exp['download_token']): ?>
                                <a href="/account/data/download/<?= htmlspecialchars((string) $exp['download_token'], ENT_QUOTES) ?>"
                                   class="btn btn-secondary" style="font-size:12px;padding:.25rem .6rem">Download</a>
                            <?php elseif ($exp['status'] === 'expired'): ?>
                                <span style="color:#9ca3af;font-size:12px">expired</span>
                            <?php elseif ($exp['status'] === 'failed'): ?>
                                <span style="color:#ef4444;font-size:12px">failed</span>
                            <?php else: ?>
                                <span style="color:#6b7280;font-size:12px"><?= htmlspecialchars((string) $exp['status'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Restrict processing ────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-body" style="padding:1.25rem">
        <h2 style="margin:0 0 .5rem;font-size:1.05rem">
            Restrict processing
            <?php if ($restricted): ?>
                <span style="font-size:11px;background:#fef3c7;color:#92400e;padding:.15rem .5rem;border-radius:999px;margin-left:.5rem">RESTRICTED</span>
            <?php endif; ?>
        </h2>
        <p style="margin:0 0 1rem;color:#6b7280;font-size:13px;line-height:1.6">
            Pauses all non-essential writes to your account. Use this if you
            have a complaint or dispute and want us to stop using your data
            until it's resolved. You'll still be able to sign in and use the
            site, but features that store new personal data will be disabled.
        </p>
        <form method="POST" action="/account/data/restrict">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary" style="font-size:13.5px">
                <?= $restricted ? 'Lift restriction' : 'Apply restriction' ?>
            </button>
        </form>
    </div>
</div>

<!-- ── Delete account ─────────────────────────────────────────────── -->
<?php if (!$inGrace): ?>
<div class="card" style="margin-bottom:1.25rem;border-color:#fecaca">
    <div class="card-body" style="padding:1.25rem">
        <h2 style="margin:0 0 .5rem;font-size:1.05rem;color:#991b1b">Delete your account</h2>
        <p style="margin:0 0 1rem;color:#6b7280;font-size:13px;line-height:1.6">
            Permanently erases your profile and most of the data linked to
            it. Some records (invoices, audit logs) are kept for legal and
            regulatory reasons but anonymised so they no longer identify
            you. You'll have a 30-day grace window to change your mind
            before the erasure runs.
        </p>
        <form method="POST" action="/account/data/erase"
              data-confirm="Really request account deletion?">
            <?= csrf_field() ?>
            <label for="confirm" style="display:block;font-size:12.5px;color:#6b7280;margin-bottom:.4rem">
                To confirm, type <code>delete my account</code> below:
            </label>
            <input type="text" name="confirm" required style="width:100%;margin-bottom:.75rem"
                   placeholder="delete my account" autocomplete="off" id="confirm">
            <button type="submit" class="btn btn-danger" style="font-size:13.5px;background:#ef4444;color:#fff">
                Delete my account
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── My DSAR history ────────────────────────────────────────────── -->
<?php if (!empty($dsar)): ?>
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-body" style="padding:1.25rem">
        <h2 style="margin:0 0 .5rem;font-size:1.05rem">Your data requests</h2>
        <p style="margin:0 0 1rem;color:#6b7280;font-size:12.5px">A record of every formal request you've filed.</p>
        <?php foreach ($dsar as $d): ?>
            <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px dashed #f3f4f6;font-size:13px">
                <div>
                    <strong><?= htmlspecialchars(ucfirst((string) $d['kind']), ENT_QUOTES) ?></strong>
                    <span style="color:#6b7280">requested <?= htmlspecialchars(date('M j, Y', strtotime((string) $d['requested_at'])), ENT_QUOTES) ?></span>
                </div>
                <div style="color:#6b7280">
                    <?= htmlspecialchars((string) $d['status'], ENT_QUOTES) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
