<?php
/** @var list<string> $langs */

use App\Core\Auth;

$u = Auth::user();
$langNames = \App\Core\I18n::NAMES;
?>
<h2 class="h4 mb-3"><?= e(t('profile.title')) ?></h2>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <form method="post" action="<?= e(url('/profile')) ?>">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label"><?= e(t('user.login_id')) ?></label>
          <input class="form-control" value="<?= e($u['login_id']) ?>" disabled></div>
        <div class="mb-3"><label class="form-label required"><?= e(t('user.name')) ?></label>
          <input class="form-control" name="user_nm" value="<?= e($u['user_nm']) ?>" maxlength="100" required></div>
        <div class="mb-3"><label class="form-label required"><?= e(t('user.email')) ?></label>
          <input class="form-control" type="email" name="email" value="<?= e($u['email']) ?>" required></div>
        <div class="mb-3"><label class="form-label"><?= e(t('user.lang')) ?></label>
          <select class="form-select" name="lang">
            <?php foreach ($langs as $l): ?>
              <option value="<?= e($l) ?>"<?= $l === $u['lang'] ? ' selected' : '' ?>><?= e($langNames[$l]) ?></option>
            <?php endforeach; ?>
          </select></div>
        <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h3 class="h6"><?= e(t('profile.password_title')) ?></h3>
      <form method="post" action="<?= e(url('/profile/password')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label required"><?= e(t('profile.current_password')) ?></label>
          <input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="mb-3"><label class="form-label required"><?= e(t('profile.new_password')) ?></label>
          <input class="form-control" type="password" name="new_password" required autocomplete="new-password"></div>
        <div class="mb-3"><label class="form-label required"><?= e(t('profile.new_password2')) ?></label>
          <input class="form-control" type="password" name="new_password2" required autocomplete="new-password"></div>
        <div class="form-text mb-3"><?= e(t('pw.policy')) ?></div>
        <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
      </form>
    </div></div>
  </div>
</div>
