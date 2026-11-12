<?php
/**
 * @var list<string> $codes
 * @var array<string,int> $used
 */
?>
<h2 class="h4 mb-3"><?= e(t('mailtpl.title')) ?></h2>
<p class="text-muted small"><?= e(t('mailtpl.help')) ?></p>
<table class="table table-sm table-hover align-middle tbl-grid">
  <thead><tr><th><?= e(t('mailtpl.code')) ?></th><th><?= e(t('mailtpl.kind')) ?></th><th><?= e(t('mailtpl.used')) ?></th><th><?= e(t('common.manage')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($codes as $c): ?>
    <tr>
      <td><code><?= e($c) ?></code></td>
      <td class="text-center"><?= \App\Services\MailTemplates::isBuiltin($c) ? '<span class="badge text-bg-secondary">' . e(t('mailtpl.builtin')) . '</span>' : '<span class="badge text-bg-info">' . e(t('mailtpl.custom')) . '</span>' ?></td>
      <td class="text-end"><?= (int)($used[$c] ?? 0) ?></td>
      <td class="text-center"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/mail-templates/' . $c)) ?>"><?= e(t('common.edit')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<form method="post" action="<?= e(url('/admin/mail-templates')) ?>" class="row g-2 align-items-end">
  <?= csrf_field() ?>
  <div class="col-auto"><label class="form-label small mb-0 required"><?= e(t('mailtpl.new_code')) ?></label>
    <input class="form-control form-control-sm" name="tmpl_cd" pattern="[A-Za-z][A-Za-z0-9_]{0,39}" required placeholder="REVIEW_REQ"></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary"><?= e(t('mailtpl.create')) ?></button></div>
  <div class="col-12 form-text"><?= e(t('mailtpl.create_hint')) ?></div>
</form>
