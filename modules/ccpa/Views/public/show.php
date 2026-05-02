<?php $pageTitle = 'Do Not Sell or Share My Personal Information'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:680px;margin:0 auto;padding:0 1rem">

<h1 style="margin:0 0 .5rem;font-size:1.5rem;font-weight:700">Do Not Sell or Share My Personal Information</h1>
<p style="margin:0 0 1.5rem;color:#6b7280;font-size:14px;line-height:1.6">
    California residents have the right to opt out of the "sale" or "sharing"
    of their personal information under the CCPA / CPRA. This page lets you
    record that opt-out so we honor it across all of our services.
</p>

<?php if ($hasGpcSignal && $honorsGpc): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:1rem 1.25rem;border-radius:8px;margin-bottom:1.25rem;font-size:13.5px">
    <strong>Global Privacy Control detected.</strong>
    Your browser is sending a <code>Sec-GPC: 1</code> header, which we honor
    automatically as a valid opt-out signal — no further action is needed.
    Submitting the form below also creates a permanent server-side record.
</div>
<?php endif; ?>

<?php if ($isOptedOut): ?>
<div class="card" style="margin-bottom:1.25rem;border-color:#6ee7b7">
    <div class="card-body" style="padding:1.25rem">
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem">
            <span style="display:inline-flex;width:24px;height:24px;border-radius:50%;background:#10b981;color:#fff;align-items:center;justify-content:center;font-weight:700;font-size:14px">✓</span>
            <strong style="font-size:1.05rem">You're opted out</strong>
        </div>
        <p style="margin:0;color:#6b7280;font-size:13.5px;line-height:1.6">
            We have an active "Do Not Sell or Share" record for you. Your
            personal information will not be sold or shared with third
            parties for cross-context behavioral advertising or other CCPA-
            covered "sale" purposes.
        </p>
    </div>
</div>

<details style="margin:1rem 0">
    <summary style="cursor:pointer;color:#6b7280;font-size:13px">Withdraw opt-out (rare — opt back in)</summary>
    <form method="POST" action="/do-not-sell/withdraw"
          data-confirm="Really opt back in? You'll need to repeat this opt-out if you change your mind later."
          style="margin-top:.75rem">
        <?= csrf_field() ?>
        <?php if (!$user): ?>
            <label for="email" style="display:block;font-size:13px;color:#374151;margin-bottom:.4rem">Email used for the previous opt-out</label>
            <input type="email" name="email" required style="width:100%;max-width:380px;margin-bottom:.5rem" id="email">
        <?php endif; ?>
        <button type="submit" class="btn btn-secondary" style="font-size:13px">Withdraw my opt-out</button>
    </form>
</details>

<?php else: ?>

<div class="card" style="margin-bottom:1.25rem">
    <form method="POST" action="/do-not-sell">
        <?= csrf_field() ?>
        <div class="card-body" style="padding:1.25rem">
            <h2 style="margin:0 0 .75rem;font-size:1.05rem">Record your opt-out</h2>
            <p style="margin:0 0 1rem;color:#6b7280;font-size:13px;line-height:1.55">
                Submitting this form will create a permanent record that you
                don't want your personal information sold or shared. We'll
                honor it across the site (including for future visits from
                this device, via a long-lived cookie).
            </p>
            <?php if (!$user): ?>
                <label for="email" style="display:block;font-size:13.5px;font-weight:500;margin-bottom:.4rem">Your email address</label>
                <input type="email" name="email" required
                       style="width:100%;max-width:380px;padding:.55rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:13.5px"
                       placeholder="you@example.com" id="email">
                <div style="font-size:12px;color:#6b7280;margin-top:.35rem;line-height:1.5">
                    Required so we can match the opt-out against any future
                    records bearing your address. We won't add you to any
                    mailing list.
                </div>
            <?php else: ?>
                <p style="margin:0 0 .75rem;font-size:13px;color:#374151">
                    Signed in as <strong><?= e($user['email']) ?></strong>. Your opt-out
                    will be tied to your account.
                </p>
            <?php endif; ?>
        </div>
        <div class="card-footer" style="padding:.85rem 1.25rem;background:#f9fafb;border-top:1px solid #e5e7eb;text-align:right">
            <button type="submit" class="btn btn-primary" style="font-size:13.5px">Submit opt-out</button>
        </div>
    </form>
</div>

<?php endif; ?>

<div style="margin-top:2rem;padding-top:1rem;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12.5px;line-height:1.55">
    <strong>About this opt-out:</strong> "Sale" and "sharing" under California law include
    transferring your personal information to third parties for monetary or other
    valuable consideration, and disclosure for cross-context behavioral advertising
    even without monetary exchange. Most operational uses (account login,
    transactional email, customer support) are unaffected.
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
