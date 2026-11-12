<?php
/**
 * @var list<array> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var string $scope
 * @var array $filter
 * @var list<string> $scopes
 * @var list<array> $services
 * @var list<string> $statuses
 * @var array<string,string> $typeNames
 */

$badge = ['DRAFT' => 'secondary', 'IN_PROGRESS' => 'primary', 'RETURNED' => 'warning', 'COMPLETED' => 'success', 'CANCELED' => 'dark'];
$today = date('Y-m-d');
$qs = static function (array $over) use ($filter, $scope): string {
    $p = array_merge(['scope' => $scope, 'status' => $filter['status'], 'service_id' => $filter['service_id'] ?: '', 'q' => $filter['q']], $over);
    return '?' . http_build_query(array_filter($p, static fn($v): bool => $v !== '' && $v !== null));
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="h4 mb-0"><?= e(t('request.list_title')) ?></h2>
  <?php if (can('REQUEST_CREATE')): ?>
    <a class="btn btn-primary" href="<?= e(url('/requests/new')) ?>"><i class="bi bi-plus-lg"></i> <?= e(t('nav.request_new')) ?></a>
  <?php endif; ?>
</div>
<ul class="nav nav-tabs mb-3">
  <?php foreach ($scopes as $sc): ?>
    <li class="nav-item"><a class="nav-link<?= $sc === $scope ? ' active' : '' ?>" href="<?= e(url('/requests' . $qs(['scope' => $sc, 'page' => '']))) ?>"><?= e(t('scope.' . $sc)) ?></a></li>
  <?php endforeach; ?>
</ul>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/requests')) ?>">
  <input type="hidden" name="scope" value="<?= e($scope) ?>">
  <div class="col-auto"><select class="form-select form-select-sm" name="status">
    <option value=""><?= e(t('request.all_status')) ?></option>
    <?php foreach ($statuses as $st): ?><option value="<?= e($st) ?>"<?= $filter['status'] === $st ? ' selected' : '' ?>><?= e(t('rstatus.' . $st)) ?></option><?php endforeach; ?>
  </select></div>
  <div class="col-auto"><select class="form-select form-select-sm" name="service_id">
    <option value=""><?= e(t('request.all_service')) ?></option>
    <?php foreach ($services as $s): ?><option value="<?= (int)$s['service_id'] ?>"<?= (int)$filter['service_id'] === (int)$s['service_id'] ? ' selected' : '' ?>><?= e(master_name('SERVICE', (string)$s['service_cd'], (string)$s['service_nm'])) ?></option><?php endforeach; ?>
  </select></div>
  <div class="col-auto"><input class="form-control form-control-sm" name="q" value="<?= e($filter['q']) ?>" placeholder="<?= e(t('request.search_ph')) ?>"></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-secondary"><?= e(t('common.search')) ?></button></div>
<?php
    $size = \App\Services\RequestService::pageSize();
    $rangeText = $total > 0
        ? t('request.range', ['from' => ($page - 1) * $size + 1, 'to' => min($total, $page * $size), 'n' => $total])
        : t('request.total', ['n' => $total]);
?>
  <div class="col-auto align-self-center text-muted small"><?= e($rangeText) ?></div>
</form>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle tbl-grid">
  <thead><tr>
    <th><?= e(t('request.no')) ?></th><th><?= e(t('request.status')) ?></th><th><?= e(t('request.title')) ?></th><th><?= e(t('request.current_step')) ?></th>
    <th><?= e(t('request.service')) ?></th><th><?= e(t('request.type')) ?></th><th><?= e(t('request.requester')) ?></th>
    <th><?= e(t('request.due')) ?></th><th><?= e(t('request.updated')) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $open = in_array($r['status'], ['IN_PROGRESS', 'RETURNED'], true);
      $lateDays = 0;
      if ($open && $r['req_due_date'] < $today) { $lateDays = \App\Services\Dates::daysLate((string)$r['req_due_date'], $today); }
      if ($r['status'] === 'COMPLETED' && $r['actual_end'] !== null && $r['actual_end'] > $r['req_due_date']) { $lateDays = \App\Services\Dates::daysLate((string)$r['req_due_date'], (string)$r['actual_end']); }
      $late = $lateDays > 0;
  ?>
    <tr<?= $late && $open ? ' class="row-late"' : '' ?>>
      <td class="text-nowrap"><a href="<?= e(url('/requests/' . (int)$r['request_id'])) ?>"><?= e($r['request_no']) ?></a></td>
      <td class="text-center"><span class="badge text-bg-<?= e($badge[$r['status']] ?? 'secondary') ?>"><?= e(t('rstatus.' . $r['status'])) ?></span></td>
      <td><span class="tbl-title" title="<?= e($r['title']) ?>"><?= e($r['title']) ?></span>
        <?php if ($r['reg_by'] !== null): ?><span class="badge text-bg-info"><?= e(t('worklog.badge')) ?></span><?php endif; ?>
        <?php if ($r['parent_id'] !== null): ?><span class="badge text-bg-secondary"><?= e(t('split.badge')) ?></span><?php endif; ?>
        <?php if ((int)$r['sched_pending'] > 0): ?><span class="badge text-bg-warning"><i class="bi bi-calendar-event"></i> <?= e(t('request.locked_badge')) ?></span><?php endif; ?></td>
      <td><?= e($open ? (string)$r['step_nm'] : '') ?></td>
      <td><?= e(master_name('SERVICE', (string)$r['service_cd'], (string)$r['service_nm'])) ?></td>
      <td><?= e(master_name('CODE', 'MAINT_TYPE:' . $r['maint_type'], $typeNames[$r['maint_type']] ?? (string)$r['maint_type'])) ?></td>
      <td><?= e($r['requester_nm']) ?></td>
      <td class="text-nowrap text-center"><?= e(fmt_date($r['req_due_date'])) ?>
        <?php if ($late): ?><span class="badge text-bg-danger"><?= e(t('request.late_days', ['n' => $lateDays])) ?></span><?php endif; ?></td>
      <td class="text-nowrap text-center small text-muted"><?= e(fmt_date($r['updated_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="9" class="text-center text-muted py-3"><?= e(t('common.empty')) ?>
    <?php if ($filter['status'] !== '' || (int)$filter['service_id'] !== 0 || $filter['q'] !== ''): ?><a href="<?= e(url('/requests?scope=' . $scope)) ?>"><?= e(t('common.reset_filter')) ?></a><?php endif; ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1):
    $nums = array_unique(array_merge([1], range(max(1, $page - 2), min($pages, $page + 2)), [$pages]));
    sort($nums);
    $prev = $page > 1 ? url('/requests' . $qs(['page' => $page - 1])) : null;
    $next = $page < $pages ? url('/requests' . $qs(['page' => $page + 1])) : null;
?>
<nav><ul class="pagination pagination-sm flex-wrap">
  <li class="page-item<?= $prev === null ? ' disabled' : '' ?>"><?php if ($prev !== null): ?><a class="page-link" href="<?= e($prev) ?>">&lsaquo; <?= e(t('common.page_prev')) ?></a><?php else: ?><span class="page-link">&lsaquo; <?= e(t('common.page_prev')) ?></span><?php endif; ?></li>
  <?php $lastShown = 0; foreach ($nums as $i): ?>
    <?php if ($lastShown > 0 && $i > $lastShown + 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(url('/requests' . $qs(['page' => $i]))) ?>"><?= $i ?></a></li>
    <?php $lastShown = $i; ?>
  <?php endforeach; ?>
  <li class="page-item<?= $next === null ? ' disabled' : '' ?>"><?php if ($next !== null): ?><a class="page-link" href="<?= e($next) ?>"><?= e(t('common.page_next')) ?> &rsaquo;</a><?php else: ?><span class="page-link"><?= e(t('common.page_next')) ?> &rsaquo;</span><?php endif; ?></li>
</ul></nav>
<?php endif; ?>
