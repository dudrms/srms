<?php
/**
 * @var array $p
 * @var array $v
 * @var list<array> $steps
 * @var array<int,array<string,string>> $stepTr
 * @var array<int,list<array>> $ccs
 * @var list<array{0:string,1:array}> $problems
 * @var list<array> $roles
 * @var list<array> $users
 * @var list<array> $depts
 */

use App\Core\View;

$draft = $v['status'] === 'DRAFT';
$pid = (int)$p['process_id'];
$base = '/admin/processes/' . $pid . '/versions/' . (int)$v['version_id'];
$badge = ['DRAFT' => 'secondary', 'SCHEDULED' => 'info', 'IN_USE' => 'success', 'EXPIRED' => 'warning', 'RETIRED' => 'dark'];

$roleName = [];
foreach ($roles as $r) {
    $roleName[(string)$r['role_cd']] = master_name('ROLE', (string)$r['role_cd'], (string)$r['role_nm']);
}
$userName = [];
foreach ($users as $u) {
    $userName[(string)$u['user_id']] = (string)$u['user_nm'];
}
$deptName = [];
foreach ($depts as $d) {
    $deptName[(string)$d['dept_id']] = (string)$d['dept_nm'];
}
$ref = static function (string $type, ?string $value) use ($roleName, $userName, $deptName): string {
    return match ($type) {
        'ROLE' => $roleName[(string)$value] ?? (string)$value,
        'USER' => $userName[(string)$value] ?? (string)$value,
        'DEPT' => $deptName[(string)$value] ?? (string)$value,
        default => '',
    };
};
$actorLabel = static function (array $s) use ($ref): string {
    $extra = $ref((string)$s['actor_type'], $s['actor_value']);
    return t('actor.' . $s['actor_type']) . ($extra !== '' ? ': ' . $extra : '');
};
?>
<div class="mb-3">
  <a href="<?= e(url('/admin/processes/' . $pid)) ?>" class="text-decoration-none">&larr; <?= e(master_name('PROCESS', (string)$p['process_cd'], (string)$p['process_nm'])) ?></a>
  <h2 class="h4 mt-2">v<?= (int)$v['version_no'] ?>
    <span class="badge text-bg-<?= e($badge[$v['state']] ?? 'secondary') ?>"><?= e(t('pstate.' . $v['state'])) ?></span>
    <small class="text-muted fs-6"><?= e(fmt_date($v['valid_from'])) ?> ~ <?= e($v['valid_to'] === null ? t('process.unlimited') : fmt_date($v['valid_to'])) ?></small></h2>
</div>

<div class="card mb-3"><div class="card-body">
  <h3 class="h6"><?= e(t('process.flow_preview')) ?></h3>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <?php foreach ($steps as $i => $s): ?>
      <?php if ($i > 0): ?><i class="bi bi-chevron-right text-muted"></i><?php endif; ?>
      <div class="border rounded px-2 py-1 bg-body">
        <span class="badge text-bg-secondary"><?= (int)$s['step_no'] ?></span>
        <strong><?= e(master_name('STEP', (string)$s['step_id'], (string)$s['step_nm'])) ?></strong>
        <div class="small text-muted"><?= e(t('ptype.' . $s['step_type'])) ?> · <?= e($actorLabel($s)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div></div>

<?php if ($draft): ?>
  <?php foreach ($problems as [$key, $params]): ?>
    <div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle"></i> <?= e(t($key, $params)) ?></div>
  <?php endforeach; ?>
  <div class="card mb-3"><div class="card-body">
    <h3 class="h6"><?= e(t('process.activate')) ?></h3>
    <form class="row g-2 align-items-end" method="post" action="<?= e(url($base . '/activate')) ?>">
      <?= csrf_field() ?>
      <div class="col-md-3"><label class="form-label required"><?= e(t('process.valid_from')) ?></label>
        <input class="form-control form-control-sm" type="date" name="valid_from" value="<?= e($v['valid_from']) ?>" required></div>
      <div class="col-md-3"><label class="form-label"><?= e(t('process.valid_to')) ?></label>
        <input class="form-control form-control-sm" type="date" name="valid_to" value="<?= e($v['valid_to'] ?? '') ?>"></div>
      <div class="col-auto">
        <button class="btn btn-sm btn-outline-secondary" formaction="<?= e(url($base . '/dates')) ?>"><?= e(t('process.dates_save')) ?></button>
        <button class="btn btn-sm btn-success"<?= $problems !== [] ? ' disabled' : '' ?>><?= e(t('process.activate')) ?></button>
      </div>
      <div class="col-12 form-text"><?= e(t('process.valid_hint')) ?></div>
    </form>
  </div></div>
<?php else: ?>
  <div class="alert alert-secondary py-2"><i class="bi bi-lock"></i> <?= e(t('process.readonly_notice')) ?></div>
<?php endif; ?>

<h3 class="h5"><?= e(t('process.step_list')) ?></h3>
<?php if ($draft): ?><div class="form-text mb-2"><i class="bi bi-grip-vertical"></i> <?= e(t('process.drag_hint')) ?></div><?php endif; ?>
<ul class="list-group mb-4" id="step-list"<?= $draft ? ' data-order-url="' . e(url($base . '/steps/order')) . '" data-csrf="' . e(csrf_token()) . '"' : '' ?>>
<?php foreach ($steps as $s): $sid = (int)$s['step_id']; $list = $ccs[$sid] ?? []; ?>
  <li class="list-group-item" data-id="<?= $sid ?>">
    <div class="d-flex align-items-center gap-2">
      <?php if ($draft): ?><i class="bi bi-grip-vertical drag-handle fs-5"></i><?php endif; ?>
      <span class="badge text-bg-secondary"><?= (int)$s['step_no'] ?></span>
      <strong><?= e(master_name('STEP', (string)$sid, (string)$s['step_nm'])) ?></strong>
      <span class="badge bg-body-secondary text-body border"><?= e(t('ptype.' . $s['step_type'])) ?></span>
      <span class="small text-muted"><?= e($actorLabel($s)) ?></span>
      <?php if ($s['attach_mode'] !== 'NONE'): ?><span class="badge text-bg-info"><i class="bi bi-paperclip"></i> <?= e(t('attach.' . $s['attach_mode'])) ?></span><?php endif; ?>
      <?php if ($list !== []): ?><span class="badge bg-body-secondary text-body border"><i class="bi bi-people"></i> <?= count($list) ?></span><?php endif; ?>
      <div class="ms-auto d-flex gap-1">
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#s<?= $sid ?>"><?= e(t($draft ? 'common.edit' : 'process.detail')) ?></button>
        <?php if ($draft): ?>
          <form method="post" action="<?= e(url($base . '/steps/' . $sid . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>">
            <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="collapse mt-3" id="s<?= $sid ?>">
      <?php if ($draft): ?>
        <?= View::render('admin/processes/_step_form', [
            's' => $s, 'tr' => $stepTr[$sid] ?? [], 'action' => $base . '/steps/' . $sid,
            'roles' => $roles, 'users' => $users,
        ], null) ?>
      <?php else: ?>
        <dl class="row small mb-2">
          <dt class="col-sm-2"><?= e(t('process.approval_mode')) ?></dt><dd class="col-sm-4"><?= e(t('amode.' . $s['approval_mode'])) ?></dd>
          <dt class="col-sm-2"><?= e(t('process.reject_to')) ?></dt><dd class="col-sm-4"><?= (int)$s['allow_reject'] === 1 ? e($s['reject_to_step'] ?? t('process.reject_to_ph')) : '-' ?></dd>
          <dt class="col-sm-2"><?= e(t('process.due_days')) ?></dt><dd class="col-sm-4"><?= e($s['due_days'] ?? '-') ?></dd>
          <dt class="col-sm-2"><?= e(t('process.attach_mode')) ?></dt>
          <dd class="col-sm-4"><?= e(t('attach.' . $s['attach_mode'])) ?>
            <?= $s['attach_mode'] !== 'NONE' ? e(' (' . ($s['attach_max_cnt'] ?? '-') . ' / ' . ($s['attach_max_mb'] ?? '-') . 'MB / ' . ($s['attach_exts'] ?? '*') . ')') : '' ?></dd>
        </dl>
      <?php endif; ?>

      <div class="border-top pt-2 mt-2">
        <div class="fw-semibold small mb-1"><i class="bi bi-people"></i> <?= e(t('process.cc')) ?></div>
        <?php foreach ($list as $c): ?>
          <div class="d-flex align-items-center gap-2 mb-1 small">
            <span class="badge bg-body-secondary text-body border"><?= e(t('cctype.' . $c['cc_type'])) ?></span>
            <span><?= e($ref((string)$c['cc_type'], $c['cc_value'])) ?></span>
            <span class="text-muted"><i class="bi bi-envelope"></i> <?= e(t('ccn.' . $c['notify_on'])) ?></span>
            <?php if ($draft): ?>
              <form class="ms-2" method="post" action="<?= e(url($base . '/steps/' . $sid . '/cc/' . (int)$c['cc_id'] . '/delete')) ?>">
                <?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($list === []): ?><div class="small text-muted"><?= e(t('common.none')) ?></div><?php endif; ?>
        <?php if ($draft): ?>
          <form class="row g-2 mt-1" method="post" action="<?= e(url($base . '/steps/' . $sid . '/cc')) ?>">
            <?= csrf_field() ?>
            <div class="col-md-2">
              <select class="form-select form-select-sm" name="cc_type">
                <?php foreach (\App\Services\ProcessService::CC_TYPES as $x): ?><option value="<?= e($x) ?>"><?= e(t('cctype.' . $x)) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-2"><select class="form-select form-select-sm" name="cc_role" title="<?= e(t('process.cc_role')) ?>">
              <?php foreach ($roles as $r): ?><option value="<?= e($r['role_cd']) ?>"><?= e($roleName[(string)$r['role_cd']]) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="cc_user" title="<?= e(t('process.cc_user')) ?>" data-filter data-filter-short="<?= e(t('common.search')) ?>" data-filter-ph="<?= e(t('common.filter_ph')) ?>" data-filter-empty="<?= e(t('common.empty')) ?>">
              <?= user_options($users) ?></select></div>
            <div class="col-md-2"><select class="form-select form-select-sm" name="cc_dept" title="<?= e(t('process.cc_dept')) ?>">
              <?php foreach ($depts as $d): ?><option value="<?= (int)$d['dept_id'] ?>"><?= e($d['dept_nm']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><select class="form-select form-select-sm" name="notify_on">
              <?php foreach (\App\Services\ProcessService::NOTIFY_ON as $x): ?><option value="<?= e($x) ?>"><?= e(t('ccn.' . $x)) ?></option><?php endforeach; ?></select></div>
            <div class="col-auto"><button class="btn btn-sm btn-outline-primary"><?= e(t('process.cc_add')) ?></button></div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </li>
<?php endforeach; ?>
</ul>

<?php if ($draft): ?>
<div class="card"><div class="card-body">
  <h3 class="h6"><?= e(t('process.step_add')) ?></h3>
  <?= View::render('admin/processes/_step_form', [
      's' => null, 'tr' => [], 'action' => $base . '/steps', 'roles' => $roles, 'users' => $users,
  ], null) ?>
</div></div>
<script src="<?= e(asset('vendor/sortablejs/Sortable.min.js')) ?>"></script>
<script src="<?= e(asset('js/process.js')) ?>"></script>
<?php endif; ?>
