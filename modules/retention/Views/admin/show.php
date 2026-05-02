<?php $pageTitle = 'Retention rule — ' . $rule['label']; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>

<div style="max-width:880px;margin:0 auto;padding:0 1rem">

<div style="font-size:12px;color:#6b7280;margin-bottom:.25rem">
    <a href="/admin/retention" style="color:#4f46e5;text-decoration:none">← Retention rules</a>
</div>
<h1 style="margin:0 0 .25rem;font-size:1.3rem;font-weight:700">
    <?= htmlspecialchars((string) $rule['label'], ENT_QUOTES) ?>
</h1>
<div style="color:#6b7280;font-size:13px;margin-bottom:1.5rem">
    Module: <code><?= htmlspecialchars((string) $rule['module'], ENT_QUOTES) ?></code>
    · key: <code><?= htmlspecialchars((string) $rule['key'], ENT_QUOTES) ?></code>
</div>

<?php if ($rule['description']): ?>
<div style="background:#fafafa;border-left:3px solid #4f46e5;padding:.75rem 1rem;margin-bottom:1.5rem;font-size:13.5px;line-height:1.55;color:#374151">
    <?= htmlspecialchars((string) $rule['description'], ENT_QUOTES) ?>
</div>
<?php endif; ?>

<!-- Edit rule -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem">
        <h2 style="margin:0 0 .75rem;font-size:1rem">Configuration</h2>
        <form method="POST" action="/admin/retention/<?= (int) $rule['id'] ?>/edit">
            <?= csrf_field() ?>
            <div style="display:grid;grid-template-columns:auto auto 1fr;gap:.75rem;align-items:end">
                <label>
                    <span style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">Days kept</span>
                    <input type="number" name="days_keep" value="<?= (int) $rule['days_keep'] ?>" min="0" style="width:100px">
                </label>
                <label>
                    <span style="display:block;font-size:12px;color:#6b7280;margin-bottom:.2rem">Action</span>
                    <select name="action">
                        <option value="purge"     <?= $rule['action'] === 'purge'     ? 'selected' : '' ?>>purge (DELETE)</option>
                        <option value="anonymize" <?= $rule['action'] === 'anonymize' ? 'selected' : '' ?>>anonymize (UPDATE)</option>
                    </select>
                </label>
                <label style="display:flex;align-items:center;gap:.5rem;font-size:13px">
                    <input type="checkbox" name="is_enabled" value="1" <?= $rule['is_enabled'] ? 'checked' : '' ?>>
                    Enabled
                </label>
            </div>
            <div style="margin-top:.75rem;font-size:12.5px;color:#6b7280;line-height:1.55">
                <strong>Table:</strong> <code><?= htmlspecialchars((string) $rule['table_name'], ENT_QUOTES) ?></code><br>
                <strong>WHERE:</strong> <code><?= htmlspecialchars((string) $rule['where_clause'], ENT_QUOTES) ?></code>
                (<code>{cutoff}</code> = <code>NOW() - <?= (int) $rule['days_keep'] ?> days</code>)
                <?php if ($rule['anonymize_columns']): ?>
                    <br><strong>Anonymize columns:</strong> <code><?= htmlspecialchars((string) $rule['anonymize_columns'], ENT_QUOTES) ?></code>
                <?php endif; ?>
            </div>
            <div style="margin-top:1rem">
                <button type="submit" class="btn btn-primary" style="font-size:13px">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Run + preview controls -->
<div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="padding:1rem 1.25rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <form method="POST" action="/admin/retention/<?= (int) $rule['id'] ?>/preview" style="display:inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary" style="font-size:13px">
                Preview (count rows that would be affected)
            </button>
        </form>
        <form method="POST" action="/admin/retention/<?= (int) $rule['id'] ?>/run"
              data-confirm="Run this rule now? Rows older than the cutoff will be <?= htmlspecialchars((string) $rule['action'], ENT_QUOTES) ?>d immediately."
              style="display:inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" style="font-size:13px">Run now</button>
        </form>
    </div>
</div>

<!-- Run history -->
<div class="card">
    <div class="card-header" style="padding:.75rem 1rem"><strong style="font-size:13.5px">Run history (last 100)</strong></div>
    <table class="table" style="width:100%;font-size:12.5px;margin:0">
        <thead style="background:#f9fafb">
            <tr>
                <th style="text-align:left;padding:.4rem .75rem">When</th>
                <th style="text-align:left;padding:.4rem .75rem">By</th>
                <th style="text-align:right;padding:.4rem .75rem">Rows</th>
                <th style="text-align:right;padding:.4rem .75rem">Duration</th>
                <th style="text-align:left;padding:.4rem .75rem">Type</th>
                <th style="text-align:left;padding:.4rem .75rem">Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($runs)): ?>
                <tr><td colspan="6" style="padding:1.5rem;text-align:center;color:#6b7280">No runs yet. Use the preview or run-now buttons above to see what this rule would do.</td></tr>
            <?php else: foreach ($runs as $r): ?>
                <tr style="border-top:1px solid #f3f4f6">
                    <td style="padding:.4rem .75rem;color:#6b7280;white-space:nowrap"><?= htmlspecialchars(date('M j, g:ia', strtotime((string) $r['started_at'])), ENT_QUOTES) ?></td>
                    <td style="padding:.4rem .75rem;color:#6b7280"><?= $r['triggered_by_username'] ? htmlspecialchars((string) $r['triggered_by_username'], ENT_QUOTES) : 'cron' ?></td>
                    <td style="padding:.4rem .75rem;text-align:right"><?= $r['rows_affected'] !== null ? number_format((int) $r['rows_affected']) : '—' ?></td>
                    <td style="padding:.4rem .75rem;text-align:right;color:#6b7280"><?= $r['duration_ms'] !== null ? (int) $r['duration_ms'] . ' ms' : '—' ?></td>
                    <td style="padding:.4rem .75rem">
                        <?php if ((int) $r['dry_run'] === 1): ?>
                            <span style="font-size:10px;background:#dbeafe;color:#1e40af;padding:.1rem .35rem;border-radius:999px">dry-run</span>
                        <?php elseif (!empty($r['error_message'])): ?>
                            <span style="font-size:10px;background:#fee2e2;color:#991b1b;padding:.1rem .35rem;border-radius:999px">failed</span>
                        <?php else: ?>
                            <span style="font-size:10px;background:#d1fae5;color:#065f46;padding:.1rem .35rem;border-radius:999px">ok</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.4rem .75rem;color:#9ca3af;font-size:11.5px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= htmlspecialchars((string) ($r['error_message'] ?? ''), ENT_QUOTES) ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

</div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
