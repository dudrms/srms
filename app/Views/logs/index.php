<?php
/**
 * @var list<array> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array $filter
 * @var list<array> $services
 * @var list<array> $users
 * @var int $uid
 * @var bool $isAdmin
 */

$qs = static function (array $over) use ($filter): string {
    $p = array_merge([
        'from' => $filter['from'], 'to' => $filter['to'], 'requester_id' => $filter['requester_id'] ?: '',
        'service_id' => $filter['service_id'] ?: '', 'q' => $filter['q'],
    ], $over);
    return '?' . http_build_query(array_filter($p, static fn($v): bool => $v !== '' && $v !== null));
};
$size = \App\Services\RequestService::pageSize();
$rangeText = $total > 0
    ? t('request.range', ['from' => ($page - 1) * $size + 1, 'to' => min($total, $page * $size), 'n' => $total])
    : t('request.total', ['n' => $total]);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="h4 mb-0"><?= e(t('worklog.list_title')) ?></h2>
  <a class="btn btn-primary" href="<?= e(url('/logs/new')) ?>"><i class="bi bi-plus-lg"></i> <?= e(t('worklog.new')) ?></a>
</div>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/logs')) ?>">
  <div class="col-auto"><input class="form-control form-control-sm" type="date" name="from" value="<?= e($filter['from']) ?>" title="<?= e(t('worklog.from')) ?>"></div>
  <div class="col-auto align-self-center">~</div>
  <div class="col-auto"><input class="form-control form-control-sm" type="date" name="to" value="<?= e($filter['to']) ?>" title="<?= e(t('worklog.to')) ?>"></div>
  <div class="col-auto"><select class="form-select form-select-sm" name="requester_id" data-filter data-filter-short="<?= e(t('common.search')) ?>" data-filter-ph="<?= e(t('common.filter_ph')) ?>" data-filter-empty="<?= e(t('common.empty')) ?>">
    <option value=""><?= e(t('worklog.all_requester')) ?></option>
    <?= user_options($users, $filter['requester_id'] ?: null) ?>
  </select></div>
  <div class="col-auto"><select class="form-select form-select-sm" name="service_id">
    <option value=""><?= e(t('request.all_service')) ?></option>
    <?php foreach ($services as $s): ?><option value="<?= (int)$s['service_id'] ?>"<?= (int)$filter['service_id'] === (int)$s['service_id'] ? ' selected' : '' ?>><?= e(master_name('SERVICE', (string)$s['service_cd'], (string)$s['service_nm'])) ?></option><?php endforeach; ?>
  </select></div>
  <div class="col-auto"><input class="form-control form-control-sm" name="q" value="<?= e($filter['q']) ?>" placeholder="<?= e(t('request.search_ph')) ?>"></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-secondary"><?= e(t('common.search')) ?></button></div>
  <div class="col-auto align-self-center text-muted small"><?= e($rangeText) ?></div>
</form>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle tbl-grid">
  <thead><tr>
    <th><?= e(t('request.no')) ?></th><th><?= e(t('worklog.request_date')) ?></th><th><?= e(t('request.requester')) ?></th>
    <th><?= e(t('request.service')) ?></th><th><?= e(t('request.title')) ?></th><th><?= e(t('worklog.channel')) ?></th>
    <th><?= e(t('worklog.actual_end')) ?></th><th><?= e(t('request.effort')) ?></th><th><?= e(t('worklog.reg_by')) ?></th><th><?= e(t('common.manage')) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $canManage = $isAdmin || (int)$r['reg_by'] === $uid;
  ?>
    <tr>
      <td class="text-nowrap"><a href="<?= e(url('/requests/' . (int)$r['request_id'])) ?>"><?= e($r['request_no']) ?></a></td>
      <td class="text-nowrap text-center"><?= e(fmt_date($r['request_date'])) ?></td>
      <td><?= e($r['requester_nm']) ?></td>
      <td><?= e(master_name('SERVICE', (string)$r['service_cd'], (string)$r['service_nm'])) ?></td>
      <td><span class="tbl-title" title="<?= e($r['title']) ?>"><?= e($r['title']) ?></span></td>
      <td><?= e($r['channel'] === null ? '' : master_name('CODE', \App\Services\WorklogService::CHANNEL_GROUP . ':' . $r['channel'], (string)($r['channel_nm'] ?? $r['channel']))) ?></td>
      <td class="text-nowrap text-center"><?= e(fmt_date($r['actual_end'])) ?></td>
      <td class="text-end"><?= e(\App\Services\Effort::fmt($r['effort_hours'])) ?></td>
      <td><?= e($r['reg_nm']) ?></td>
      <td class="text-nowrap text-end">
        <?php if ($canManage): ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/logs/' . (int)$r['request_id'] . '/edit')) ?>"><?= e(t('common.edit')) ?></a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="10" class="text-center text-muted py-3"><?= e(t('common.empty')) ?>
    <?php if ($filter['from'] !== '' || $filter['to'] !== '' || (int)$filter['requester_id'] !== 0 || (int)$filter['service_id'] !== 0 || $filter['q'] !== ''): ?><a href="<?= e(url('/logs')) ?>"><?= e(t('common.reset_filter')) ?></a><?php endif; ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1):
    $nums = array_unique(array_merge([1], range(max(1, $page - 2), min($pages, $page + 2)), [$pages]));
    sort($nums);
    $prev = $page > 1 ? url('/logs' . $qs(['page' => $page - 1])) : null;
    $next = $page < $pages ? url('/logs' . $qs(['page' => $page + 1])) : null;
?>
<nav><ul class="pagination pagination-sm flex-wrap">
  <li class="page-item<?= $prev === null ? ' disabled' : '' ?>"><?php if ($prev !== null): ?><a class="page-link" href="<?= e($prev) ?>">&lsaquo; <?= e(t('common.page_prev')) ?></a><?php else: ?><span class="page-link">&lsaquo; <?= e(t('common.page_prev')) ?></span><?php endif; ?></li>
  <?php $lastShown = 0; foreach ($nums as $i): ?>
    <?php if ($lastShown > 0 && $i > $lastShown + 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(url('/logs' . $qs(['page' => $i]))) ?>"><?= $i ?></a></li>
    <?php $lastShown = $i; ?>
  <?php endforeach; ?>
  <li class="page-item<?= $next === null ? ' disabled' : '' ?>"><?php if ($next !== null): ?><a class="page-link" href="<?= e($next) ?>"><?= e(t('common.page_next')) ?> &rsaquo;</a><?php else: ?><span class="page-link"><?= e(t('common.page_next')) ?> &rsaquo;</span><?php endif; ?></li>
</ul></nav>
<?php endif; ?>
