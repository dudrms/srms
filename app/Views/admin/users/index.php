<?php
/** @var list<array> $rows */
/** @var string $q */
/** @var array<string,string> $roleMap */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="h4 mb-0"><?= e(t('user.title')) ?></h2>
  <a class="btn btn-primary" href="<?= e(url('/admin/users/new')) ?>"><i class="bi bi-plus-lg"></i> <?= e(t('user.new')) ?></a>
</div>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/admin/users')) ?>">
  <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('user.search_ph')) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary"><?= e(t('common.search')) ?></button></div>
</form>
<div class="table-responsive">
<table class="table table-sm table-hover align-middle tbl-grid">
  <thead><tr>
    <th><?= e(t('user.login_id')) ?></th><th><?= e(t('user.name')) ?></th><th><?= e(t('user.email')) ?></th>
    <th><?= e(t('user.dept')) ?></th><th><?= e(t('user.roles')) ?></th><th><?= e(t('user.status')) ?></th>
    <th><?= e(t('user.last_login')) ?></th><th><?= e(t('common.manage')) ?></th>
  </tr></thead>
  <tbody>
  <?php $roleTone = ['SYS_ADMIN' => 'danger', 'IT_TEAM' => 'primary', 'BIZ_IT' => 'info', 'REQUESTER' => 'secondary']; ?>
  <?php foreach ($rows as $r): ?>
    <tr<?= (int)$r['use_yn'] === 1 ? '' : ' class="row-inactive"' ?>>
      <td><?= e($r['login_id']) ?></td>
      <td><?= e($r['user_nm']) ?></td>
      <td><?= e($r['email']) ?></td>
      <td><?= e($r['dept_nm'] ?? '') ?></td>
      <td><?php foreach (array_filter(explode(',', (string)$r['roles'])) as $rc): ?>
        <span class="badge text-bg-<?= e($roleTone[$rc] ?? 'secondary') ?>"><?= e($roleMap[$rc] ?? $rc) ?></span>
      <?php endforeach; ?></td>
      <td class="text-center"><?= (int)$r['use_yn'] === 1 ? '<span class="badge text-bg-success">' . e(t('common.active')) . '</span>' : '<span class="badge text-bg-danger">' . e(t('common.inactive')) . '</span>' ?></td>
      <td class="text-center text-nowrap"><?= e(fmt_date($r['last_login'])) ?></td>
      <td class="text-center"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/users/' . (int)$r['user_id'] . '/edit')) ?>"><?= e(t('common.edit')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="8" class="text-center text-muted"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
