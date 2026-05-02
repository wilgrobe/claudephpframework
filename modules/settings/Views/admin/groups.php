<?php $pageTitle = 'Group Policy'; $activePanel = 'members'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/settings/members" style="color:#4f46e5;text-decoration:none">← Members</a>
</div>
<h1 style="margin:0 0 1rem;font-size:1.4rem">Group Policy</h1>

<div class="card">
    <div class="card-header"><h2 style="margin:0;font-size:1rem">Membership &amp; Creation</h2></div>
    <div class="card-body">
        <form method="POST" action="/admin/settings/groups">
            <?= csrf_field() ?>

            <!-- Allow group creation -->
            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:1rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('allow_group_creation', !empty($values['allow_group_creation'])) ?>
                    Allow users to create groups
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    When off, the <code>/groups/create</code> page is blocked for regular users.
                    Admins and superadmins can still create groups so they can seed and manage
                    group structure regardless of this setting.
                </div>
            </div>

            <!-- Single group only -->
            <div class="form-group" style="padding:.85rem 1rem;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;margin-bottom:1.25rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;color:#78350f;margin:0">
                    <?= toggle_switch('single_group_only', !empty($values['single_group_only'])) ?>
                    Limit users to a single group
                </label>
                <div style="font-size:12.5px;color:#78350f;margin-top:.35rem;line-height:1.5">
                    When on, a user may belong to at most one group at a time. Attempts to
                    accept a second invitation or create a second group are rejected with a
                    message directing the user to leave their current group first.
                    <strong>Does not retroactively remove</strong> users from existing extra
                    groups — only blocks new additions. Superadmin mode bypasses the check
                    so you can clean up conflicts.
                </div>
            </div>

            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">Save Group Policy</button>
                <a href="/admin/settings" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

</main></div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
