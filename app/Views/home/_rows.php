<?php
/**
 * 요청 요약 표. 별칭 변수: $rows(list<array>), $showOwner(bool)
 * @var list<array<string,mixed>> $rows
 * @var bool $showOwner
 * @var string $today
 */
$today ??= date('Y-m-d');
$showOwner ??= false;
if ($rows === []) {
    echo '<div class="text-muted small px-1">' . e(t('dash.none')) . '</div>';
    return;
}
?>
<div class="table-responsive"><table class="table table-sm table-hover align-middle small mb-0">
  <thead><tr><th><?= e(t('request.no')) ?></th><th><?= e(t('request.title')) ?></th><th><?= e(t('request.current_step')) ?></th>
    <?php if ($showOwner): ?><th><?= e(t('dash.owner')) ?></th><?php endif; ?><th><?= e(t('request.due')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $late = (string)$r['due'] < $today && in_array($r['status'], ['IN_PROGRESS', 'RETURNED'], true); ?>
    <tr>
      <td class="text-nowrap"><a href="<?= e(url('/requests/' . (int)$r['request_id'])) ?>"><?= e($r['request_no']) ?></a></td>
      <td><?= e($r['title']) ?>
        <?php if ((int)$r['sched_pending'] > 0): ?><span class="badge text-bg-warning"><i class="bi bi-calendar-event"></i></span><?php endif; ?></td>
      <td><?= e($r['step_nm'] ?? '') ?></td>
      <?php if ($showOwner): ?><td><?= e($r['owner_nm'] ?? '') ?></td><?php endif; ?>
      <td class="text-nowrap<?= $late ? ' text-danger fw-semibold' : '' ?>"><?= e(fmt_date((string)$r['due'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
