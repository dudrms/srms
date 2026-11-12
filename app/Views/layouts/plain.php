<?php
/** @var string $content */

use App\Core\I18n;
use App\Core\Request;
use App\Core\Session;

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
<body class="bg-body-tertiary">
<div class="container py-5" style="max-width: 460px;">
  <div class="text-center mb-4">
    <h1 class="h3"><i class="bi bi-tools"></i> <?= e(t('app.name')) ?></h1>
    <div class="text-muted"><?= e(t('app.title')) ?></div>
  </div>
  <?php foreach (Session::pullFlash() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
  <form method="post" action="<?= e(url('/lang')) ?>" class="text-center mt-3">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e(Request::path()) ?>">
    <?php foreach (I18n::enabled() as $l): ?>
      <button name="lang" value="<?= e($l) ?>" class="btn btn-sm btn-link<?= $l === I18n::lang() ? ' fw-bold' : '' ?>"><?= e($langNames[$l]) ?></button>
    <?php endforeach; ?>
  </form>
  <form method="post" action="<?= e(url('/theme')) ?>" class="text-center">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e(Request::path()) ?>">
    <button name="theme" value="<?= theme() === 'dark' ? 'light' : 'dark' ?>" class="btn btn-sm btn-link">
      <i class="bi <?= theme() === 'dark' ? 'bi-sun' : 'bi-moon-stars' ?>"></i> <?= e(t(theme() === 'dark' ? 'nav.theme_light' : 'nav.theme_dark')) ?>
    </button>
  </form>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
