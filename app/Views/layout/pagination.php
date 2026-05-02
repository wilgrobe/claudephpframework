<?php /* app/Views/layout/pagination.php */
/** @var array $log - paginated data: items, total, per_page, current_page, last_page */
$pag = $log ?? $pagination ?? [];
if (empty($pag) || ($pag['last_page'] ?? 1) <= 1) return;
?>
<div class="pagination">
    <?php if ($pag['current_page'] > 1): ?>
    <a href="?page=<?= $pag['current_page'] - 1 ?>">‹ Prev</a>
    <?php endif; ?>

    <?php
    $start = max(1, $pag['current_page'] - 2);
    $end   = min($pag['last_page'], $pag['current_page'] + 2);
    if ($start > 1): ?><a href="?page=1">1</a><?php if ($start > 2): ?><span>…</span><?php endif; endif;
    for ($p = $start; $p <= $end; $p++):
    ?>
    <?php if ($p === $pag['current_page']): ?>
    <span class="current"><?= $p ?></span>
    <?php else: ?>
    <a href="?page=<?= $p ?>"><?= $p ?></a>
    <?php endif; ?>
    <?php endfor;
    if ($end < $pag['last_page']): if ($end < $pag['last_page'] - 1): ?><span>…</span><?php endif; ?>
    <a href="?page=<?= $pag['last_page'] ?>"><?= $pag['last_page'] ?></a>
    <?php endif; ?>

    <?php if ($pag['current_page'] < $pag['last_page']): ?>
    <a href="?page=<?= $pag['current_page'] + 1 ?>">Next ›</a>
    <?php endif; ?>
</div>
<div style="font-size:12px;color:#9ca3af;margin-top:.35rem">
    Showing <?= (($pag['current_page']-1)*$pag['per_page'])+1 ?>–<?= min($pag['current_page']*$pag['per_page'], $pag['total']) ?> of <?= number_format($pag['total']) ?>
</div>
