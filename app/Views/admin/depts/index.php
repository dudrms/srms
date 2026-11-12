<?php
/** @var list<array> $rows */
?>
<h2 class="h4 mb-3"><?= e(t('dept.title')) ?></h2>
<div class="card mb-3"><div class="card-body">
  <form class="row g-2 align-items-center" method="post" action="<?= e(url('/admin/depts')) ?>">
    <?= csrf_field() ?>
    <div class="col-md-5"><input class="form-control" name="dept_nm" placeholder="<?= e(t('dept.name')) ?> *" maxlength="100" required></div>
    <div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_it" value="1" id="new_is_it">
      <label class="form-check-label" for="new_is_it"><?= e(t('dept.is_it')) ?></label></div></div>
    <div class="col-auto"><button class="btn btn-primary"><?= e(t('common.add')) ?></button></div>
  </form>
</div></div>
<table class="table table-sm align-middle tbl-grid">
  <thead><tr><th colspan="3"><?= e(t('dept.name')) ?></th>
    <th><?= e(t('dept.users')) ?></th><th><?= e(t('dept.services')) ?></th><th><?= e(t('common.manage')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $id = (int)$r['dept_id']; ?>
    <tr>
      <td colspan="3">
        <form class="row g-2 align-items-center" method="post" action="<?= e(url('/admin/depts/' . $id)) ?>">
          <?= csrf_field() ?>
          <div class="col-6"><input class="form-control form-control-sm" name="dept_nm" value="<?= e($r['dept_nm']) ?>" maxlength="100" required></div>
          <div class="col-auto"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="is_it" value="1" id="is_it_<?= $id ?>"<?= (int)$r['is_it'] === 1 ? ' checked' : '' ?>>
            <label class="form-check-label" for="is_it_<?= $id ?>"><?= e(t('dept.is_it')) ?></label></div></div>
          <div class="col-auto"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="use_yn" value="1" id="use_yn_<?= $id ?>"<?= (int)$r['use_yn'] === 1 ? ' checked' : '' ?>>
            <label class="form-check-label" for="use_yn_<?= $id ?>"><?= e(t('common.active')) ?></label></div></div>
          <div class="col-auto"><button class="btn btn-sm btn-outline-primary"><?= e(t('common.save')) ?></button></div>
        </form>
      </td>
      <td class="text-end"><?= (int)$r['user_cnt'] ?></td>
      <td class="text-end"><?= (int)$r['service_cnt'] ?></td>
      <td class="text-center">
        <form method="post" action="<?= e(url('/admin/depts/' . $id . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-outline-danger"><?= e(t('common.delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
