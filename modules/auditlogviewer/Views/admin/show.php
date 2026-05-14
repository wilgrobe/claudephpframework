<?php
/** @var array      $row */
/** @var array{old: ?array, new: ?array} $values */
/** @var int|null   $prev_id */
/** @var int|null   $next_id */
$pageTitle = 'Audit row #' . (int) $row['id'];
include BASE_PATH . '/app/Views/layout/header.php';

// Build a unified field-level diff. Iterate the union of old + new
// keys; render each as one of: unchanged / changed / added / removed.
$old = is_array($values['old']) ? $values['old'] : [];
$new = is_array($values['new']) ? $values['new'] : [];
$keys = array_unique(array_merge(array_keys($old), array_keys($new)));
sort($keys);

$fieldRows = [];
foreach ($keys as $k) {
    $hasOld = array_key_exists($k, $old);
    $hasNew = array_key_exists($k, $new);
    $oldV   = $hasOld ? $old[$k] : null;
    $newV   = $hasNew ? $new[$k] : null;

    if ($hasOld && $hasNew) {
        if (json_encode($oldV) === json_encode($newV)) {
            $kind = 'unchanged';
        } else {
            $kind = 'changed';
        }
    } elseif ($hasNew) {
        $kind = 'added';
    } else {
        $kind = 'removed';
    }
    $fieldRows[$k] = ['kind' => $kind, 'old' => $oldV, 'new' => $newV];
}

$fmt = function ($v): string {
    if ($v === null)  return '<em style="color:#9ca3af">null</em>';
    if (is_bool($v))  return $v ? '<code>true</code>' : '<code>false</code>';
    if (is_scalar($v)) return '<code style="word-break:break-word">' . e((string) $v) . '</code>';
    return '<pre style="margin:0;font-size:11.5px;white-space:pre-wrap;word-break:break-word">' . e(json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
};
?>
<style>
.al-show-shell { max-width:1100px; margin:0 auto; }
.al-show-nav   { display:flex; justify-content:space-between; align-items:center; margin-bottom:.85rem; font-size:13px; }
.al-show-nav a { color:#4f46e5; text-decoration:none; font-weight:600; }
.al-show-nav a:hover { text-decoration:underline; }

.al-meta-card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:1rem 1.25rem; margin-bottom:1rem; }
.al-meta-grid { display:grid; gap:.4rem 1rem; grid-template-columns:140px 1fr; font-size:13px; }
.al-meta-grid dt { color:#6b7280; font-weight:500; }
.al-meta-grid dd { margin:0; word-break:break-word; }

.al-diff-section { background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
.al-diff-section h2 { margin:0; padding:.7rem 1rem; background:#fafafa; font-size:13px; font-weight:700; color:#374151; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
.al-diff-section .legend span { font-size:10.5px; padding:.1rem .4rem; border-radius:3px; margin-left:.3rem; font-weight:600; }
.al-diff-section .legend .added    { background:#ecfdf5; color:#047857; }
.al-diff-section .legend .removed  { background:#fee2e2; color:#991b1b; }
.al-diff-section .legend .changed  { background:#fef3c7; color:#92400e; }

.al-field-row    { display:grid; grid-template-columns:160px 1fr 1fr; gap:.85rem; padding:.65rem 1rem; border-bottom:1px solid #f3f4f6; font-size:12.5px; align-items:start; }
.al-field-row:last-child { border-bottom:0; }
.al-field-row .field-name { font-family:monospace; color:#374151; font-weight:600; word-break:break-word; }
.al-field-row .field-kind { display:inline-block; font-size:10.5px; padding:.05rem .35rem; border-radius:3px; font-weight:700; margin-top:.15rem; text-transform:uppercase; letter-spacing:.05em; }
.al-field-row.changed   .field-kind { background:#fef3c7; color:#92400e; }
.al-field-row.added     .field-kind { background:#ecfdf5; color:#047857; }
.al-field-row.removed   .field-kind { background:#fee2e2; color:#991b1b; }
.al-field-row.unchanged .field-kind { background:#f3f4f6; color:#6b7280; }

.al-old-val, .al-new-val { padding:.4rem .6rem; border-radius:4px; min-height:1.2rem; }
.al-field-row.changed   .al-old-val { background:#fef2f2; color:#991b1b; text-decoration:line-through; opacity:.85; }
.al-field-row.changed   .al-new-val { background:#f0fdf4; color:#047857; }
.al-field-row.added     .al-old-val { background:#fafafa; color:#9ca3af; font-style:italic; }
.al-field-row.added     .al-new-val { background:#f0fdf4; color:#047857; }
.al-field-row.removed   .al-old-val { background:#fef2f2; color:#991b1b; text-decoration:line-through; }
.al-field-row.removed   .al-new-val { background:#fafafa; color:#9ca3af; font-style:italic; }
.al-field-row.unchanged .al-old-val,
.al-field-row.unchanged .al-new-val { background:#fafafa; color:#6b7280; }

.al-no-diff { padding:1.5rem; text-align:center; color:#9ca3af; font-size:13px; font-style:italic; }
.badge-superadmin-pill { display:inline-block; padding:.1rem .5rem; border-radius:999px; background:#fef3c7; color:#92400e; font-size:10.5px; font-weight:700; }
</style>

<div class="al-show-shell">
    <div class="al-show-nav">
        <a href="/admin/audit-log">← Audit log</a>
        <div>
            <?php if ($prev_id !== null): ?>
                <a href="/admin/audit-log/<?= (int) $prev_id ?>" style="margin-right:.5rem">‹ Prev #<?= (int) $prev_id ?></a>
            <?php else: ?>
                <span style="color:#d1d5db;margin-right:.5rem">‹ Prev</span>
            <?php endif; ?>
            <?php if ($next_id !== null): ?>
                <a href="/admin/audit-log/<?= (int) $next_id ?>">Next #<?= (int) $next_id ?> ›</a>
            <?php else: ?>
                <span style="color:#d1d5db">Next ›</span>
            <?php endif; ?>
        </div>
    </div>

    <h1 style="margin:0 0 .85rem">Audit row #<?= (int) $row['id'] ?></h1>

    <div class="al-meta-card">
        <dl class="al-meta-grid">
            <dt>When</dt>
            <dd><?= e(date('M j, Y H:i:s', strtotime((string) $row['created_at']))) ?>
                <span style="color:#9ca3af;font-size:11.5px">· <?= e((string) $row['created_at']) ?></span>
            </dd>
            <dt>Actor</dt>
            <dd>
                <?php if (!empty($row['actor_username'])): ?>
                    @<?= e((string) $row['actor_username']) ?>
                    <span style="color:#9ca3af;font-size:11.5px">(user #<?= (int) $row['actor_user_id'] ?>)</span>
                <?php else: ?>
                    <span style="color:#9ca3af">system</span>
                <?php endif; ?>
                <?php if ((int) $row['superadmin_mode'] === 1): ?>
                    <span class="badge-superadmin-pill" style="margin-left:.3rem">superadmin</span>
                <?php endif; ?>
            </dd>
            <?php if (!empty($row['emulated_username'])): ?>
            <dt>Emulated by</dt>
            <dd>@<?= e((string) $row['emulated_username']) ?> <span style="color:#9ca3af;font-size:11.5px">(user #<?= (int) $row['emulated_user_id'] ?>)</span></dd>
            <?php endif; ?>
            <dt>Action</dt>
            <dd><code style="background:#f3f4f6;padding:.15rem .4rem;border-radius:3px"><?= e((string) $row['action']) ?></code></dd>
            <dt>Model</dt>
            <dd>
                <?php if (!empty($row['model'])): ?>
                    <code><?= e((string) $row['model']) ?></code>
                    <?php if (!empty($row['model_id'])): ?> #<?= (int) $row['model_id'] ?><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </dd>
            <dt>IP</dt>
            <dd><code style="font-size:12px"><?= e((string) ($row['ip_address'] ?? '—')) ?></code></dd>
            <?php if (!empty($row['user_agent'])): ?>
            <dt>User agent</dt>
            <dd style="font-size:11.5px;color:#6b7280;font-family:monospace;word-break:break-all"><?= e((string) $row['user_agent']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($row['notes'])): ?>
            <dt>Notes</dt>
            <dd style="white-space:pre-wrap"><?= e((string) $row['notes']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($row['row_hash'])): ?>
            <dt>Chain hash</dt>
            <dd style="font-family:monospace;font-size:10.5px;color:#9ca3af;word-break:break-all"><?= e(substr((string) $row['row_hash'], 0, 16)) ?>…</dd>
            <?php endif; ?>
        </dl>
    </div>

    <?php if (empty($fieldRows)): ?>
        <div class="al-diff-section">
            <h2>Value changes</h2>
            <div class="al-no-diff">No before/after values were recorded for this action.</div>
        </div>
    <?php else: ?>
        <div class="al-diff-section">
            <h2>
                Value changes
                <span class="legend">
                    <?php
                    $counts = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0];
                    foreach ($fieldRows as $row2) { $counts[$row2['kind']]++; }
                    ?>
                    <?php if ($counts['changed']): ?><span class="changed"><?= $counts['changed'] ?> changed</span><?php endif; ?>
                    <?php if ($counts['added']): ?><span class="added">+<?= $counts['added'] ?> added</span><?php endif; ?>
                    <?php if ($counts['removed']): ?><span class="removed">−<?= $counts['removed'] ?> removed</span><?php endif; ?>
                    <?php if ($counts['unchanged']): ?><span style="color:#9ca3af;font-size:10.5px;font-weight:500;padding:.1rem .35rem"><?= $counts['unchanged'] ?> unchanged</span><?php endif; ?>
                </span>
            </h2>
            <div class="al-field-row" style="background:#fafafa;border-bottom:1px solid #e5e7eb;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;font-weight:700">
                <div>FIELD</div>
                <div>OLD</div>
                <div>NEW</div>
            </div>
            <?php foreach ($fieldRows as $name => $row2): ?>
                <div class="al-field-row <?= $row2['kind'] ?>">
                    <div>
                        <div class="field-name"><?= e($name) ?></div>
                        <span class="field-kind"><?= $row2['kind'] ?></span>
                    </div>
                    <div class="al-old-val"><?= $row2['kind'] === 'added' ? '<em>(not set)</em>' : $fmt($row2['old']) ?></div>
                    <div class="al-new-val"><?= $row2['kind'] === 'removed' ? '<em>(removed)</em>' : $fmt($row2['new']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
