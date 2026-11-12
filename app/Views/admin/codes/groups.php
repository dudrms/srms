<?php
/** @var list<array> $rows */
?>
<h2 class="h4 mb-3"><?= e(t('code.groups')) ?></h2>
<div class="card mb-3"><div class="card-body">
  <form class="row g-2" method="post" action="<?= e(url('/admin/codes')) ?>">
    <?= csrf_field() ?>
    <div class="col-md-2"><input class="form-control" name="group_cd" placeholder="GROUP_CODE *" pattern="[A-Za-z][A-Za-z0-9_]{1,29}" required></div>
    <div class="col-md-3"><input class="form-control" name="group_nm" placeholder="한국어 *" maxlength="100" required></div>
    <?php foreach (\App\Services\Translations::LANGS as $l): ?>
      <div class="col-md-2"><input class="form-control" name="name_<?= e($l) ?>" placeholder="<?= e(lang_name($l)) ?>" maxlength="100"></div>
    <?php endforeach; ?>
    <div class="col-auto"><button class="btn btn-primary"><?= e(t('common.add')) ?></button></div>
  </form>
</div></div>
<table class="table table-sm table-hover align-middle tbl-grid">
  <thead><tr><th><?= e(t('code.group_cd')) ?></th><th><?= e(t('code.group_nm')) ?></th><th><?= e(t('code.item_cnt')) ?></th><th><?= e(t('common.manage')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><code><?= e($r['group_cd']) ?></code></td>
      <td><?= e(master_name('CODE_GROUP', (string)$r['group_cd'], (string)$r['group_nm'])) ?></td>
      <td class="text-end"><?= (int)$r['item_cnt'] ?></td>
      <td class="text-center"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/codes/' . rawurlencode((string)$r['group_cd']))) ?>"><?= e(t('code.items')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
