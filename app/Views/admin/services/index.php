<?php
/** @var list<array> $rows */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="h4 mb-0"><?= e(t('service.title')) ?></h2>
  <a class="btn btn-primary" href="<?= e(url('/admin/services/new')) ?>"><i class="bi bi-plus-lg"></i> <?= e(t('service.new')) ?></a>
</div>
<table class="table table-sm table-hover align-middle tbl-grid">
  <thead><tr><th><?= e(t('service.code')) ?></th><th><?= e(t('service.name')) ?></th><th><?= e(t('service.biz_dept')) ?></th>
    <th><?= e(t('service.biz_it')) ?></th><th><?= e(t('service.it_owner')) ?></th><th><?= e(t('common.active')) ?></th><th><?= e(t('common.manage')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr<?= (int)$r['use_yn'] === 1 ? '' : ' class="row-inactive"' ?>>
      <td><code><?= e($r['service_cd']) ?></code></td>
      <td><?= e(master_name('SERVICE', (string)$r['service_cd'], (string)$r['service_nm'])) ?></td>
      <td><?= e($r['dept_nm'] ?? '') ?></td>
      <td><?php if ($r['biz_dept_id'] === null): ?>
        <?php elseif ((int)$r['biz_cnt'] === 0): ?><span class="badge text-bg-secondary"><?= e(t('service.biz_it_none')) ?></span>
        <?php else: ?><?= e((string)$r['biz_names']) ?><?php if ((int)$r['biz_ok'] === 0): ?> <span class="badge text-bg-danger"><?= e(t('service.biz_it_check')) ?></span><?php endif; ?>
        <?php endif; ?></td>
      <td><?= e($r['owner_nm'] ?? '') ?></td>
      <td class="text-center"><?= (int)$r['use_yn'] === 1 ? '<span class="badge text-bg-success">' . e(t('common.active')) . '</span>' : '<span class="badge text-bg-danger">' . e(t('common.inactive')) . '</span>' ?></td>
      <td class="text-center"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/services/' . (int)$r['service_id'] . '/edit')) ?>"><?= e(t('common.edit')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
