<?php
/** @var list<array> $rows */

$badge = ['DRAFT' => 'secondary', 'SCHEDULED' => 'info', 'IN_USE' => 'success', 'EXPIRED' => 'warning', 'RETIRED' => 'dark'];
?>
<h2 class="h4 mb-3"><?= e(t('process.title')) ?></h2>
<div class="card mb-3"><div class="card-body">
  <h3 class="h6"><?= e(t('process.new')) ?></h3>
  <form class="row g-2" method="post" action="<?= e(url('/admin/processes')) ?>">
    <?= csrf_field() ?>
    <div class="col-md-2"><input class="form-control" name="process_cd" placeholder="PROCESS_CODE *" pattern="[A-Za-z][A-Za-z0-9_]{1,29}" required></div>
    <div class="col-md-2"><input class="form-control" name="process_nm" placeholder="한국어 *" maxlength="100" required></div>
    <?php foreach (\App\Services\Translations::LANGS as $l): ?>
      <div class="col-md-2"><input class="form-control" name="name_<?= e($l) ?>" placeholder="<?= e(lang_name($l)) ?>" maxlength="100"></div>
    <?php endforeach; ?>
    <div class="col-md-3"><input class="form-control" name="description" placeholder="<?= e(t('process.description')) ?>" maxlength="500"></div>
    <div class="col-auto"><button class="btn btn-primary"><?= e(t('common.add')) ?></button></div>
  </form>
</div></div>
<table class="table table-sm table-hover align-middle tbl-grid">
  <thead><tr>
    <th><?= e(t('process.code')) ?></th><th><?= e(t('process.name')) ?></th><th><?= e(t('process.current_version')) ?></th>
    <th><?= e(t('process.versions')) ?></th><th><?= e(t('process.mapped')) ?></th><th><?= e(t('common.manage')) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $cur = $r['current']; ?>
    <tr>
      <td><code><?= e($r['process_cd']) ?></code></td>
      <td><?= e(master_name('PROCESS', (string)$r['process_cd'], (string)$r['process_nm'])) ?>
        <?php if (\App\Services\ProcessService::isSystem($r)): ?><span class="badge text-bg-info" title="<?= e(t('process.system_note')) ?>"><i class="bi bi-lock"></i> <?= e(t('process.system_badge')) ?></span><?php endif; ?></td>
      <td><?php if ($cur !== null): ?>
        <span class="badge text-bg-success">v<?= (int)$cur['version_no'] ?></span>
        <small class="text-muted"><?= e(fmt_date($cur['valid_from'])) ?> ~ <?= e(fmt_date($cur['valid_to'])) ?></small>
      <?php else: ?><span class="badge text-bg-warning"><?= e(t('process.no_active')) ?></span><?php endif; ?></td>
      <td class="text-end"><?= (int)$r['version_cnt'] ?></td>
      <td class="text-end"><?= (int)$r['mapped_cnt'] ?></td>
      <td class="text-center"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/processes/' . (int)$r['process_id'])) ?>"><?= e(t('process.open')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
