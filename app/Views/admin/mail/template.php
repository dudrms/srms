<?php
/**
 * @var string $code
 * @var array<string,array{0:string,1:string}> $rows
 * @var bool $builtin
 * @var list<string> $tmplVars
 * @var int $used
 */
$langName = \App\Core\I18n::NAMES;
?>
<a href="<?= e(url('/admin/mail-templates')) ?>" class="text-decoration-none">&larr; <?= e(t('mailtpl.title')) ?></a>
<h2 class="h4 mt-2"><code><?= e($code) ?></code>
  <?= $builtin ? '<span class="badge text-bg-secondary fs-6">' . e(t('mailtpl.builtin')) . '</span>' : '<span class="badge text-bg-info fs-6">' . e(t('mailtpl.custom')) . '</span>' ?></h2>
<p class="text-muted small"><?= e(t('mailtpl.vars_hint')) ?>
  <?php foreach ($tmplVars as $v): ?><code>{<?= e($v) ?>}</code> <?php endforeach; ?></p>
<form method="post" action="<?= e(url('/admin/mail-templates/' . $code)) ?>">
  <?= csrf_field() ?>
  <?php foreach ($rows as $lang => [$subject, $body]): ?>
    <div class="card mb-3"><div class="card-body">
      <h3 class="h6"><?= e($langName[$lang]) ?></h3>
      <div class="mb-2"><label class="form-label small mb-0 required"><?= e(t('mailtpl.subject')) ?></label>
        <input class="form-control form-control-sm" name="subject_<?= e($lang) ?>" maxlength="200" required value="<?= e($subject) ?>"></div>
      <div><label class="form-label small mb-0 required"><?= e(t('mailtpl.body')) ?></label>
        <textarea class="form-control form-control-sm font-monospace" name="body_<?= e($lang) ?>" rows="12" maxlength="5000" required><?= e($body) ?></textarea></div>
    </div></div>
  <?php endforeach; ?>
  <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
</form>
<div class="d-flex gap-2 mt-3">
  <?php if ($builtin): ?>
    <form method="post" action="<?= e(url('/admin/mail-templates/' . $code . '/reset')) ?>" data-confirm="<?= e(t('mailtpl.confirm_reset')) ?>">
      <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><?= e(t('mailtpl.reset')) ?></button>
    </form>
  <?php elseif ($used === 0): ?>
    <form method="post" action="<?= e(url('/admin/mail-templates/' . $code . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>">
      <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><?= e(t('common.delete')) ?></button>
    </form>
  <?php endif; ?>
</div>
