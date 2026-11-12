<?php
/** @var array $group */
/** @var list<array> $items */
/** @var array<string,array<string,string>> $names */

$g = rawurlencode((string)$group['group_cd']);
?>
<div class="mb-3">
  <a href="<?= e(url('/admin/codes')) ?>" class="text-decoration-none">&larr; <?= e(t('code.groups')) ?></a>
  <h2 class="h4 mt-2"><?= e(master_name('CODE_GROUP', (string)$group['group_cd'], (string)$group['group_nm'])) ?> <code class="fs-6"><?= e($group['group_cd']) ?></code></h2>
</div>
<div class="card mb-3"><div class="card-body">
  <form class="row g-2" method="post" action="<?= e(url('/admin/codes/' . $g . '/items')) ?>">
    <?= csrf_field() ?>
    <div class="col-md-2"><input class="form-control" name="code_cd" placeholder="CODE *" pattern="[A-Za-z][A-Za-z0-9_]{1,29}" required></div>
    <div class="col-md-2"><input class="form-control" name="code_nm" placeholder="한국어 *" maxlength="100" required></div>
    <?php foreach (\App\Services\Translations::LANGS as $l): ?>
      <div class="col-md-2"><input class="form-control" name="name_<?= e($l) ?>" placeholder="<?= e(lang_name($l)) ?>" maxlength="100"></div>
    <?php endforeach; ?>
    <div class="col-md-1"><input class="form-control" name="sort_no" type="number" min="0" placeholder="#" value="<?= (int)max(array_column($items, 'sort_no') ?: [0]) + 1 ?>"></div>
    <div class="col-auto"><button class="btn btn-primary"><?= e(t('common.add')) ?></button></div>
  </form>
</div></div>
<table class="table table-sm align-middle tbl-grid">
  <thead><tr><th><?= e(t('code.code_cd')) ?></th><th>한국어</th><?php foreach (\App\Services\Translations::LANGS as $l): ?><th><?= e(lang_name($l)) ?></th><?php endforeach; ?><th><?= e(t('code.sort')) ?></th><th><?= e(t('common.active')) ?></th><th><?= e(t('common.manage')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($items as $it): $cd = (string)$it['code_cd']; ?>
    <tr>
      <td><code><?= e($cd) ?></code>
        <form method="post" action="<?= e(url('/admin/codes/' . $g . '/items/' . rawurlencode($cd))) ?>" id="f_<?= e($cd) ?>"><?= csrf_field() ?></form></td>
      <td><input form="f_<?= e($cd) ?>" class="form-control form-control-sm" name="code_nm" value="<?= e($it['code_nm']) ?>" maxlength="100" required></td>
      <?php foreach (\App\Services\Translations::LANGS as $l): ?>
        <td><input form="f_<?= e($cd) ?>" class="form-control form-control-sm" name="name_<?= e($l) ?>" value="<?= e($names[$cd][$l] ?? '') ?>" maxlength="100"></td>
      <?php endforeach; ?>
      <td style="width:90px"><input form="f_<?= e($cd) ?>" class="form-control form-control-sm" type="number" min="0" name="sort_no" value="<?= (int)$it['sort_no'] ?>"></td>
      <td class="text-center"><input form="f_<?= e($cd) ?>" class="form-check-input" type="checkbox" name="use_yn" value="1"<?= (int)$it['use_yn'] === 1 ? ' checked' : '' ?>></td>
      <td class="text-center"><button form="f_<?= e($cd) ?>" class="btn btn-sm btn-outline-primary"><?= e(t('common.save')) ?></button></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
