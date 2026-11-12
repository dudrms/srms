<?php
/** @var array $companies */
/** @var string $selected */
/** @var string|null $fixedNm */
/** @var bool $localOn */
/** @var bool $ssoOn */
?>
<div class="card shadow-sm">
  <div class="card-body">
    <h2 class="h5 mb-3"><?= e(t('auth.title')) ?></h2>
    <?php if ($ssoOn): ?>
      <a class="btn btn-outline-primary w-100 mb-3" href="<?= e(url('/sso/login')) ?>"><i class="bi bi-shield-lock"></i> <?= e(t('auth.sso_login')) ?></a>
    <?php endif; ?>
    <?php if ($localOn): ?>
    <form method="post" action="<?= e(url('/login')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <?php if ($fixedNm !== null): ?>
      <div class="mb-3 text-muted small"><?= e(t('auth.company')) ?>: <strong><?= e($fixedNm) ?></strong></div>
      <?php else: ?>
      <div class="mb-3">
        <label class="form-label" for="company_cd"><?= e(t('auth.company')) ?></label>
        <select class="form-select" name="company_cd" id="company_cd" required>
          <option value=""><?= e(t('common.select')) ?></option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= e($c['company_cd']) ?>"<?= $c['company_cd'] === $selected ? ' selected' : '' ?>><?= e($c['company_nm']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="mb-3">
        <label class="form-label" for="login_id"><?= e(t('auth.login_id')) ?></label>
        <input class="form-control" name="login_id" id="login_id" maxlength="64" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password"><?= e(t('auth.password')) ?></label>
        <input class="form-control" type="password" name="password" id="password" required autocomplete="current-password">
      </div>
      <button class="btn btn-primary w-100"><?= e(t('auth.login')) ?></button>
    </form>
    <?php endif; ?>
  </div>
</div>
