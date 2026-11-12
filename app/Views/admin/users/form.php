<?php
/** @var array|null $user */
/** @var array $old */
/** @var list<array> $depts */
/** @var array<string,string> $roleMap */
/** @var list<string> $langs */

$editing = $user !== null;
$langNames = \App\Core\I18n::NAMES;
$action = $editing ? '/admin/users/' . (int)$user['user_id'] : '/admin/users';
$selRoles = $old['roles'] ?? [];
?>
<h2 class="h4 mb-3"><?= e(t($editing ? 'user.edit' : 'user.new')) ?></h2>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <form method="post" action="<?= e(url($action)) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label required"><?= e(t('user.login_id')) ?></label>
          <input class="form-control" name="login_id" value="<?= e($old['login_id'] ?? '') ?>" maxlength="64" required<?= $editing ? ' disabled' : '' ?>></div>
        <div class="mb-3"><label class="form-label required"><?= e(t('user.name')) ?></label>
          <input class="form-control" name="user_nm" value="<?= e($old['user_nm'] ?? '') ?>" maxlength="100" required></div>
        <div class="mb-3"><label class="form-label required"><?= e(t('user.email')) ?></label>
          <input class="form-control" type="email" name="email" value="<?= e($old['email'] ?? '') ?>" required></div>
        <div class="mb-3"><label class="form-label"><?= e(t('user.dept')) ?></label>
          <select class="form-select" name="dept_id">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($depts as $d): ?>
              <option value="<?= (int)$d['dept_id'] ?>"<?= (int)($old['dept_id'] ?? 0) === (int)$d['dept_id'] ? ' selected' : '' ?>><?= e($d['dept_nm']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label"><?= e(t('user.lang')) ?></label>
          <select class="form-select" name="lang">
            <?php foreach ($langs as $l): ?>
              <option value="<?= e($l) ?>"<?= ($old['lang'] ?? 'ko') === $l ? ' selected' : '' ?>><?= e($langNames[$l]) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label d-block"><?= e(t('user.roles')) ?></label>
          <?php foreach ($roleMap as $cd => $nm): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="roles[]" id="r_<?= e($cd) ?>" value="<?= e($cd) ?>"<?= in_array($cd, $selRoles, true) ? ' checked' : '' ?>>
              <label class="form-check-label" for="r_<?= e($cd) ?>"><?= e($nm) ?></label>
            </div>
          <?php endforeach; ?></div>
        <?php if ($editing): ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="use_yn" value="1" id="use_yn"<?= (int)($old['use_yn'] ?? 1) === 1 ? ' checked' : '' ?>>
            <label class="form-check-label" for="use_yn"><?= e(t('common.active')) ?></label>
          </div>
        <?php else: ?>
          <div class="mb-3"><label class="form-label required"><?= e(t('user.password')) ?></label>
            <input class="form-control" type="password" name="password" required autocomplete="new-password">
            <div class="form-text"><?= e(t('pw.policy')) ?></div></div>
        <?php endif; ?>
        <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/admin/users')) ?>"><?= e(t('common.cancel')) ?></a>
      </form>
    </div></div>
  </div>
  <?php if ($editing): ?>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h3 class="h6"><?= e(t('user.reset_password')) ?></h3>
      <form method="post" action="<?= e(url($action . '/password')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3"><input class="form-control" type="password" name="password" required autocomplete="new-password">
          <div class="form-text"><?= e(t('pw.policy')) ?></div></div>
        <button class="btn btn-outline-danger"><?= e(t('user.reset_password')) ?></button>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
</div>
