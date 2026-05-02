<?php $pageTitle = 'Content Settings'; $activePanel = 'content'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">Content</h1>
<p style="color:#6b7280;font-size:13.5px;margin:0 0 1.25rem;max-width:560px">
    Defaults for user-generated content surfaces — comments, reviews,
    posts, polls, forms. Per-target overrides (e.g. a specific page
    that disables comments) live on the editing forms for those
    individual items.
</p>

<div class="card">
    <form method="post" action="/admin/settings/content">
        <?= csrf_field() ?>
        <div class="card-body">

            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Comments</h3>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:.75rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('comments_require_moderation', !empty($values['comments_require_moderation']) && $values['comments_require_moderation'] !== 'false') ?>
                    Hold new comments for moderator approval
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    When on, comments land in <code>pending</code> and aren't visible until
                    a moderator approves them at <a href="/admin/comments" style="color:#4338ca;text-decoration:underline">/admin/comments</a>.
                </div>
            </div>

            <div class="form-group">
                <label for="comments_edit_window_seconds">Edit window (seconds)</label>
                <input id="comments_edit_window_seconds" name="comments_edit_window_seconds" type="number" min="0" max="86400" class="form-control"
                       value="<?= e((string) ($values['comments_edit_window_seconds'] ?? '900')) ?>">
                <small style="color:#6b7280">Author may edit / delete their own comment for this many seconds after posting. 0 to disable. Moderators can always edit.</small>
            </div>

            <div class="form-group">
                <label for="comments_max_depth">Maximum reply nesting depth</label>
                <input id="comments_max_depth" name="comments_max_depth" type="number" min="1" max="10" class="form-control"
                       value="<?= e((string) ($values['comments_max_depth'] ?? '3')) ?>">
                <small style="color:#6b7280">Replies beyond this depth are flattened to a sibling of the deepest visible parent — keeps long threads readable.</small>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:.75rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('comments_notify_moderators', !empty($values['comments_notify_moderators']) && $values['comments_notify_moderators'] !== 'false') ?>
                    Notify moderators of pending comments
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Digest notification fires every <em>N</em> minutes (below) per moderator
                    if they have pending comments. Master kill-switch for the
                    NotifyModeratorsJob scheduled task.
                </div>
            </div>

            <div class="form-group">
                <label for="comments_notify_interval_minutes">Notification window (minutes)</label>
                <input id="comments_notify_interval_minutes" name="comments_notify_interval_minutes" type="number" min="5" max="1440" class="form-control"
                       value="<?= e((string) ($values['comments_notify_interval_minutes'] ?? '60')) ?>">
                <small style="color:#6b7280">Throttle. Each moderator receives at most one digest per this many minutes.</small>
            </div>

        </div>
        <div class="card-body" style="background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save Content</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:.95rem">Other content surfaces</h3></div>
    <div class="card-body" style="display:grid;gap:.5rem;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr))">
        <a href="/admin/reviews" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Reviews moderation</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Review queue + actions. Per-target reviews toggles on the Commerce panel.</div>
        </a>
        <a href="/admin/forms" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Forms</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Form builder + submissions + per-form settings.</div>
        </a>
        <a href="/admin/polls" style="padding:.75rem;border:1px solid #e5e7eb;border-radius:6px;color:inherit;text-decoration:none">
            <strong>Polls</strong>
            <div style="color:#6b7280;font-size:12.5px;margin-top:.2rem">Poll definitions + result reads.</div>
        </a>
    </div>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
