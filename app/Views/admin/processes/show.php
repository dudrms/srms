<?php
/** @var array $p */
/** @var array<string,string> $tr */
/** @var list<array> $versions */

$badge = ['DRAFT' => 'secondary', 'SCHEDULED' => 'info', 'IN_USE' => 'success', 'EXPIRED' => 'warning', 'RETIRED' => 'dark'];
$pid = (int)$p['process_id'];
$system = \App\Services\ProcessService::isSystem($p);
$hasDraft = false;
foreach ($versions as $v) {
    if ($v['status'] === 'DRAFT') {
        $hasDraft = true;
    }
}
?>
<div class="mb-3">
  <a href="<?= e(url('/admin/processes')) ?>" class="text-decoration-none">&larr; <?= e(t('process.title')) ?></a>
  <h2 class="h4 mt-2"><?= e(master_name('PROCESS', (string)$p['process_cd'], (string)$p['process_nm'])) ?>
    <code class="fs-6"><?= e($p['process_cd']) ?></code>
    <?php if ($system): ?><span class="badge text-bg-info fs-6"><i class="bi bi-lock"></i> <?= e(t('process.system_badge')) ?></span><?php endif; ?></h2>
</div>
<?php if ($system): ?>
  <div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> <?= e(t('process.system_note')) ?></div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
  <form class="row g-2" method="post" action="<?= e(url('/admin/processes/' . $pid)) ?>">
    <?= csrf_field() ?>
    <div class="col-md-3"><label class="form-label required">한국어</label><input class="form-control" name="process_nm" value="<?= e($p['process_nm']) ?>" maxlength="100" required></div>
    <?php foreach (\App\Services\Translations::LANGS as $l): ?>
      <div class="col-md-2"><label class="form-label"><?= e(lang_name($l)) ?></label><input class="form-control" name="name_<?= e($l) ?>" value="<?= e($tr[$l] ?? '') ?>" maxlength="100"></div>
    <?php endforeach; ?>
    <div class="col-md-4"><label class="form-label"><?= e(t('process.description')) ?></label><input class="form-control" name="description" value="<?= e($p['description'] ?? '') ?>" maxlength="500"></div>
    <div class="col-auto align-self-end"><button class="btn btn-primary"><?= e(t('common.save')) ?></button></div>
  </form>
</div></div>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h3 class="h5 mb-0"><?= e(t('process.versions')) ?></h3>
  <?php if (!$system): ?>
  <form class="d-flex gap-2" method="post" action="<?= e(url('/admin/processes/' . $pid . '/versions')) ?>">
    <?= csrf_field() ?>
    <select class="form-select form-select-sm" name="from_version">
      <option value=""><?= e(t('process.new_blank')) ?></option>
      <?php foreach ($versions as $i => $v): ?>
        <option value="<?= (int)$v['version_id'] ?>"<?= $i === 0 ? ' selected' : '' ?>><?= e(t('process.clone_from', ['no' => $v['version_no']])) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-primary"<?= $hasDraft ? ' disabled title="' . e(t('process.err_draft_exists')) . '"' : '' ?>><?= e(t('process.new_version')) ?></button>
  </form>
  <?php endif; ?>
</div>
<table class="table table-sm align-middle tbl-grid">
  <thead><tr><th><?= e(t('process.version')) ?></th><th><?= e(t('process.status')) ?></th><th><?= e(t('process.period')) ?></th>
    <th><?= e(t('process.steps')) ?></th><th><?= e(t('process.requests')) ?></th><th><?= e(t('common.manage')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($versions as $v): $vid = (int)$v['version_id']; ?>
    <tr>
      <td class="text-center">v<?= (int)$v['version_no'] ?></td>
      <td class="text-center"><span class="badge text-bg-<?= e($badge[$v['state']] ?? 'secondary') ?>"><?= e(t('pstate.' . $v['state'])) ?></span></td>
      <td class="text-center text-nowrap"><?= e(fmt_date($v['valid_from'])) ?> ~ <?= e($v['valid_to'] === null ? t('process.unlimited') : fmt_date($v['valid_to'])) ?></td>
      <td class="text-end"><?= (int)$v['step_cnt'] ?></td>
      <td class="text-end"><?= (int)$v['request_cnt'] ?></td>
      <td class="text-center text-nowrap">
        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/processes/' . $pid . '/versions/' . $vid)) ?>"><?= e($v['status'] === 'DRAFT' ? t('common.edit') : t('process.open')) ?></a>
        <?php if ($system && $v['status'] !== 'DRAFT'): ?>
        <?php elseif ($v['status'] === 'ACTIVE'): ?>
          <form class="d-inline" method="post" action="<?= e(url('/admin/processes/' . $pid . '/versions/' . $vid . '/retire')) ?>" data-confirm="<?= e(t('process.confirm_retire')) ?>">
            <?= csrf_field() ?><button class="btn btn-sm btn-outline-warning"><?= e(t('process.retire')) ?></button>
          </form>
        <?php elseif ((int)$v['request_cnt'] === 0): ?>
          <form class="d-inline" method="post" action="<?= e(url('/admin/processes/' . $pid . '/versions/' . $vid . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>">
            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><?= e(t('common.delete')) ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (!$system): ?><div class="form-text mb-4"><?= e(t('process.version_rule')) ?></div><?php endif; ?>

<?php if (!$system): ?>
<form method="post" action="<?= e(url('/admin/processes/' . $pid . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>">
  <?= csrf_field() ?><button class="btn btn-outline-danger"><?= e(t('process.delete_process')) ?></button>
</form>
<?php endif; ?>
