<?php
/** @var string $content */

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Session;

$u = Auth::user();
$back = Request::path();
$isAdmin = can('USER_EDIT') || can('CODE_EDIT') || can('SERVICE_EDIT') || can('PROCESS_EDIT');
$langNames = \App\Core\I18n::NAMES;
?>
<!doctype html>
<html lang="<?= e(I18n::lang()) ?>" data-bs-theme="<?= e(theme()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(t('app.name')) ?></title>
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= e(url('/')) ?>"><i class="bi bi-tools"></i> <?= e(t('app.name')) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a></li>
        <?php if (can('REQUEST_CREATE')): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/requests/new')) ?>"><?= e(t('nav.request_new')) ?></a></li>
        <?php endif; ?>
        <?php if (can('REQUEST_VIEW_OWN')): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/requests')) ?>"><?= e(t('nav.requests')) ?></a></li>
        <?php endif; ?>
        <?php if (can('STEP_ACT')): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/inbox')) ?>"><?= e(t('nav.inbox')) ?></a></li>
        <?php endif; ?>
        <?php if (can('WORKLOG_REG')): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/logs')) ?>"><?= e(t('nav.worklog')) ?></a></li>
        <?php endif; ?>
        <?php if (can('REPORT_VIEW')): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/reports')) ?>"><?= e(t('nav.reports')) ?></a></li>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><?= e(t('nav.admin')) ?></a>
          <ul class="dropdown-menu">
            <?php if (can('USER_EDIT')): ?>
              <li><a class="dropdown-item" href="<?= e(url('/admin/users')) ?>"><?= e(t('nav.users')) ?></a></li>
              <li><a class="dropdown-item" href="<?= e(url('/admin/depts')) ?>"><?= e(t('nav.depts')) ?></a></li>
              <li><a class="dropdown-item" href="<?= e(url('/admin/roles')) ?>"><?= e(t('nav.roles')) ?></a></li>
            <?php endif; ?>
            <?php if (can('CODE_EDIT')): ?>
              <li><a class="dropdown-item" href="<?= e(url('/admin/codes')) ?>"><?= e(t('nav.codes')) ?></a></li>
            <?php endif; ?>
            <?php if (can('SERVICE_EDIT')): ?>
              <li><a class="dropdown-item" href="<?= e(url('/admin/services')) ?>"><?= e(t('nav.services')) ?></a></li>
            <?php endif; ?>
            <?php if (can('PROCESS_EDIT')): ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= e(url('/admin/processes')) ?>"><?= e(t('nav.processes')) ?></a></li>
              <li><a class="dropdown-item" href="<?= e(url('/admin/process-map')) ?>"><?= e(t('nav.process_map')) ?></a></li>
              <li><a class="dropdown-item" href="<?= e(url('/admin/mail-templates')) ?>"><?= e(t('nav.mail_templates')) ?></a></li>
              <li><a class="dropdown-item" href="<?= e(url('/admin/mail-log')) ?>"><?= e(t('nav.mail_log')) ?></a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item">
          <form method="post" action="<?= e(url('/theme')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="theme" value="<?= theme() === 'dark' ? 'light' : 'dark' ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button class="nav-link" title="<?= e(t(theme() === 'dark' ? 'nav.theme_to_light' : 'nav.theme_to_dark')) ?>">
              <i class="bi <?= theme() === 'dark' ? 'bi-sun' : 'bi-moon-stars' ?>"></i> <?= e(t(theme() === 'dark' ? 'nav.theme_light' : 'nav.theme_dark')) ?>
            </button>
          </form>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><i class="bi bi-translate"></i> <?= e($langNames[I18n::lang()] ?? '') ?></a>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php foreach (I18n::enabled() as $l): ?>
              <li>
                <form method="post" action="<?= e(url('/lang')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="lang" value="<?= e($l) ?>">
                  <input type="hidden" name="back" value="<?= e($back) ?>">
                  <button class="dropdown-item<?= $l === I18n::lang() ? ' active' : '' ?>"><?= e($langNames[$l]) ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>
        <?php if ($u !== null): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><i class="bi bi-person-circle"></i> <?= e($u['user_nm']) ?></a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><?= e(t('nav.profile')) ?></a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?>
                <button class="dropdown-item"><?= e(t('nav.logout')) ?></button>
              </form>
            </li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<main class="container-fluid py-3">
  <?php foreach (Session::pullFlash() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
      <?= e($f['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
