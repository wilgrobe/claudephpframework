<?php
/**
 * @var array $row contact_messages row
 */
$pageTitle = 'Contact message #' . $row['id'];
$mailtoSubject = ($row['subject'] ?? '') !== '' ? 'Re: ' . $row['subject'] : 'Re: your message';
?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<style>
.cmd-shell { max-width: 800px; margin: 0 auto; padding: 1.5rem; }
.cmd-back { font-size: 13px; color: var(--color-gray-500); text-decoration: none; }
.cmd-back:hover { color: var(--color-primary); }
.cmd-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin: .5rem 0 1.25rem; }
.cmd-head h1 { font-size: 1.4rem; margin: 0; font-weight: 700; }
.cmd-head .meta { font-size: 12.5px; color: var(--color-gray-500); margin-top: .25rem; }
.cmd-pill { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .35px; }
.cmd-pill.new      { background: #fef3c7; color: #92400e; }
.cmd-pill.read     { background: var(--color-gray-100); color: var(--color-gray-600); }
.cmd-pill.replied  { background: #d1fae5; color: #065f46; }
.cmd-pill.archived { background: #e0e7ff; color: #3730a3; }

.cmd-card { background: white; border: 1px solid var(--color-gray-200); border-radius: 8px; padding: 1.25rem; margin-bottom: 1.25rem; }
.cmd-card dl { display: grid; grid-template-columns: 110px 1fr; gap: .35rem .75rem; margin: 0; font-size: 14px; }
.cmd-card dt { color: var(--color-gray-500); font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: .35px; padding-top: 4px; }
.cmd-card dd { margin: 0; word-break: break-word; }
.cmd-card dd a { color: var(--color-primary); }

.cmd-body { background: #f9fafb; border-left: 3px solid var(--color-primary); padding: 1rem 1.25rem; white-space: pre-wrap; font-size: 14px; line-height: 1.6; border-radius: 0 4px 4px 0; }

.cmd-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
.cmd-actions form { display: inline; }
.cmd-actions button, .cmd-actions a.btn { padding: .55rem 1rem; border-radius: 4px; font-size: 13.5px; font-weight: 500; text-decoration: none; cursor: pointer; border: 1px solid var(--color-gray-200); background: white; color: var(--color-gray-700); }
.cmd-actions a.btn.reply { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.cmd-actions button.replied { background: #10b981; color: white; border-color: #10b981; }
.cmd-actions button.archive { color: var(--color-gray-600); }
.cmd-actions button.delete  { color: #dc2626; border-color: #fca5a5; }
.cmd-actions button:hover, .cmd-actions a.btn:hover { filter: brightness(95%); }

.cmd-meta-card { font-size: 12.5px; color: var(--color-gray-500); }
.cmd-meta-card code { background: var(--color-gray-100); padding: 1px 5px; border-radius: 3px; font-size: 11.5px; font-family: ui-monospace, Menlo, monospace; }
</style>

<div class="cmd-shell">
    <a href="/admin/contact-messages" class="cmd-back">← All messages</a>

    <div class="cmd-head">
        <div>
            <h1>From: <?= htmlspecialchars($row['name'], ENT_QUOTES) ?></h1>
            <div class="meta">Received <?= htmlspecialchars($row['created_at'], ENT_QUOTES) ?></div>
        </div>
        <span class="cmd-pill <?= htmlspecialchars($row['status'], ENT_QUOTES) ?>"><?= htmlspecialchars($row['status'], ENT_QUOTES) ?></span>
    </div>

    <div class="cmd-card">
        <dl>
            <dt>Email</dt>
            <dd><a href="mailto:<?= htmlspecialchars($row['email'], ENT_QUOTES) ?>?subject=<?= rawurlencode($mailtoSubject) ?>"><?= htmlspecialchars($row['email'], ENT_QUOTES) ?></a></dd>
            <?php if (!empty($row['phone'])): ?>
                <dt>Phone</dt>
                <dd><a href="tel:<?= htmlspecialchars($row['phone'], ENT_QUOTES) ?>"><?= htmlspecialchars($row['phone'], ENT_QUOTES) ?></a></dd>
            <?php endif; ?>
            <?php if (!empty($row['subject'])): ?>
                <dt>Subject</dt>
                <dd><?= htmlspecialchars($row['subject'], ENT_QUOTES) ?></dd>
            <?php endif; ?>
        </dl>
    </div>

    <div class="cmd-body"><?= htmlspecialchars($row['body'], ENT_QUOTES) ?></div>

    <div class="cmd-actions">
        <a class="btn reply" href="mailto:<?= htmlspecialchars($row['email'], ENT_QUOTES) ?>?subject=<?= rawurlencode($mailtoSubject) ?>">
            ✉ Reply by email
        </a>
        <?php if ($row['status'] !== 'replied'): ?>
            <form method="post" action="/admin/contact-messages/<?= (int) $row['id'] ?>/replied">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="replied">Mark replied</button>
            </form>
        <?php endif; ?>
        <?php if ($row['status'] !== 'archived'): ?>
            <form method="post" action="/admin/contact-messages/<?= (int) $row['id'] ?>/archive">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="archive">Archive</button>
            </form>
        <?php endif; ?>
        <form method="post" action="/admin/contact-messages/<?= (int) $row['id'] ?>/delete"
              onsubmit="return confirm('Delete this message permanently? This cannot be undone.');">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="delete">Delete</button>
        </form>
    </div>

    <div class="cmd-card cmd-meta-card" style="margin-top:1.5rem;">
        <strong>Submission metadata</strong>
        <div style="margin-top:6px;">
            IP: <code><?= htmlspecialchars($row['ip'] ?? '—', ENT_QUOTES) ?></code> ·
            <?php if (!empty($row['read_at'])): ?>read <?= htmlspecialchars($row['read_at'], ENT_QUOTES) ?> · <?php endif; ?>
            <?php if (!empty($row['replied_at'])): ?>replied <?= htmlspecialchars($row['replied_at'], ENT_QUOTES) ?> · <?php endif; ?>
            <?php if (!empty($row['archived_at'])): ?>archived <?= htmlspecialchars($row['archived_at'], ENT_QUOTES) ?><?php endif; ?>
        </div>
        <?php if (!empty($row['user_agent'])): ?>
            <div style="margin-top:6px;word-break:break-all;">UA: <code><?= htmlspecialchars($row['user_agent'], ENT_QUOTES) ?></code></div>
        <?php endif; ?>
    </div>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
