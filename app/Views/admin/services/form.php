<?php
/** @var array|null $service */
/** @var array $old */
/** @var list<array> $depts */
/** @var list<array> $users */
/** @var list<array> $bizUsers */

$editing = $service !== null;
$action = $editing ? '/admin/services/' . (int)$service['service_id'] : '/admin/services';
$bizSel = array_map('intval', (array)($old['biz_it_ids'] ?? []));
?>
<h2 class="h4 mb-3"><?= e(t($editing ? 'service.edit' : 'service.new')) ?></h2>
<div class="card" style="max-width: 720px;"><div class="card-body">
  <form method="post" action="<?= e(url($action)) ?>">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label required"><?= e(t('service.code')) ?></label>
      <input class="form-control" name="service_cd" value="<?= e($old['service_cd'] ?? '') ?>" maxlength="30" pattern="[A-Za-z0-9_\-]{2,30}" required<?= $editing ? ' disabled' : '' ?>></div>
    <div class="row g-2 mb-3">
      <div class="col-md-4"><label class="form-label required"><?= e(t('service.name')) ?> (한국어)</label>
        <input class="form-control" name="service_nm" value="<?= e($old['service_nm'] ?? '') ?>" maxlength="100" required></div>
      <?php foreach (\App\Services\Translations::LANGS as $l): ?>
        <div class="col-md-2"><label class="form-label"><?= e(lang_name($l)) ?></label>
          <input class="form-control" name="name_<?= e($l) ?>" value="<?= e($old['name_' . $l] ?? '') ?>" maxlength="100"></div>
      <?php endforeach; ?>
    </div>
    <div class="mb-3"><label class="form-label"><?= e(t('service.biz_dept')) ?></label>
      <select class="form-select" name="biz_dept_id" id="biz_dept_id"><option value=""><?= e(t('common.none')) ?></option>
        <?php foreach ($depts as $d): ?>
          <option value="<?= (int)$d['dept_id'] ?>"<?= (int)($old['biz_dept_id'] ?? 0) === (int)$d['dept_id'] ? ' selected' : '' ?>><?= e($d['dept_nm']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="mb-3"><label class="form-label"><?= e(t('service.biz_it')) ?></label>
      <div class="border rounded p-2" id="biz-it-box" data-no-dept="<?= e(t('service.biz_it_no_dept')) ?>" data-no-user="<?= e(t('service.biz_it_no_user')) ?>">
        <?php foreach ($bizUsers as $b): ?>
          <div class="form-check" data-dept="<?= (int)$b['dept_id'] ?>">
            <input class="form-check-input" type="checkbox" name="biz_it_ids[]" value="<?= (int)$b['user_id'] ?>" id="bz<?= (int)$b['user_id'] ?>"<?= in_array((int)$b['user_id'], $bizSel, true) ? ' checked' : '' ?>>
            <label class="form-check-label" for="bz<?= (int)$b['user_id'] ?>"><?= e($b['user_nm'] . ' (' . $b['login_id'] . ')') ?></label>
          </div>
        <?php endforeach; ?>
        <div class="text-muted small" id="biz-it-msg"></div>
      </div>
      <div class="form-text"><?= e(t('service.biz_it_hint')) ?></div></div>
    <div class="mb-3"><label class="form-label"><?= e(t('service.it_owner')) ?></label>
      <select class="form-select" name="it_owner_id" data-filter data-filter-short="<?= e(t('common.search')) ?>" data-filter-ph="<?= e(t('common.filter_ph')) ?>" data-filter-empty="<?= e(t('common.empty')) ?>"><option value=""><?= e(t('common.none')) ?></option>
        <?= user_options($users, (int)($old['it_owner_id'] ?? 0) ?: null) ?>
      </select></div>
    <?php if ($editing): ?>
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="use_yn" value="1" id="use_yn"<?= (int)($old['use_yn'] ?? 1) === 1 ? ' checked' : '' ?>>
        <label class="form-check-label" for="use_yn"><?= e(t('common.active')) ?></label>
      </div>
    <?php endif; ?>
    <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/services')) ?>"><?= e(t('common.cancel')) ?></a>
  </form>
</div></div>
<script src="<?= e(asset('js/service.js')) ?>"></script>
