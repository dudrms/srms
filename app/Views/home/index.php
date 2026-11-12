<?php
/**
 * @var string $companyNm
 * @var list<string> $roleNames
 * @var array<string,mixed> $dash
 */

use App\Core\Auth;
use App\Core\View;

$u = Auth::user();
$today = (string)$dash['today'];
$badge = ['DRAFT' => 'secondary', 'IN_PROGRESS' => 'primary', 'RETURNED' => 'warning', 'COMPLETED' => 'success', 'CANCELED' => 'dark'];
$rows = static fn(array $rs, bool $owner = false): string => View::render('home/_rows', ['rows' => $rs, 'showOwner' => $owner, 'today' => $today], null);
$stat = static function (string $label, int|string $n, string $href = '', string $tone = ''): string {
    $inner = '<div class="card-body py-2"><div class="text-muted small">' . e($label) . '</div><div class="fs-3 lh-sm' . ($tone !== '' && (int)$n > 0 ? ' text-' . $tone : '') . '">' . e((string)$n) . '</div></div>';
    $card = '<div class="card h-100' . ($tone !== '' && (int)$n > 0 ? ' border-' . $tone : '') . '">' . $inner . '</div>';
    return '<div class="col-6 col-md-3 col-xl">' . ($href === '' ? $card : '<a class="text-decoration-none" href="' . e($href) . '">' . $card . '</a>') . '</div>';
};
$mine = $dash['mine'];
?>
<div class="card mb-3">
  <div class="card-body py-3">
    <h2 class="h5 mb-1"><?= e(t('home.welcome', ['name' => $u['user_nm']])) ?></h2>
    <div class="text-muted small"><?= e($companyNm) ?> · <?= e(implode(', ', $roleNames)) ?></div>
  </div>
</div>

<h3 class="h6 text-muted"><?= e(t('dash.my_requests')) ?></h3>
<div class="row g-2 mb-3">
  <?php if (can('STEP_ACT')): ?><?= $stat(t('home.inbox'), (int)$dash['actions']['total'], url('/inbox'), 'primary') ?><?php endif; ?>
  <?= $stat(t('rstatus.IN_PROGRESS'), (int)$mine['IN_PROGRESS'], url('/requests?status=IN_PROGRESS')) ?>
  <?= $stat(t('rstatus.RETURNED'), (int)$mine['RETURNED'], url('/requests?status=RETURNED'), 'warning') ?>
  <?= $stat(t('rstatus.COMPLETED'), (int)$mine['COMPLETED'], url('/requests?status=COMPLETED')) ?>
  <?= $stat(t('rstatus.DRAFT'), (int)$mine['DRAFT'], url('/requests?status=DRAFT')) ?>
</div>

<div class="row g-3 mb-3">
  <?php if (can('STEP_ACT') || $dash['actions']['rows'] !== []): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h3 class="h6"><i class="bi bi-inbox"></i> <?= e(t('dash.my_actions')) ?> <span class="badge text-bg-primary"><?= (int)$dash['actions']['total'] ?></span></h3>
    <?php if ($dash['actions']['rows'] === []): ?><div class="text-muted small"><?= e(t('dash.none')) ?></div><?php else: ?>
    <ul class="list-group list-group-flush small">
      <?php foreach ($dash['actions']['rows'] as $r): ?>
        <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
          <span><a href="<?= e(url('/requests/' . (int)$r['request_id'])) ?>"><?= e($r['request_no']) ?></a> <?= e($r['title']) ?>
            <span class="text-muted">· <?= e($r['step_nm'] ?? '') ?></span></span>
          <span class="badge text-bg-<?= $r['kind'] === 'step' ? 'primary' : 'warning' ?>"><?= e(t('dash.action.' . $r['kind'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ((int)$dash['actions']['total'] > count($dash['actions']['rows'])): ?><a class="small" href="<?= e(url('/inbox')) ?>"><?= e(t('dash.view_all')) ?></a><?php endif; ?>
    <?php endif; ?>
  </div></div></div>
  <?php endif; ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h3 class="h6"><i class="bi bi-hourglass-split"></i> <?= e(t('dash.my_open')) ?></h3>
    <?php if ($dash['open'] === []): ?><div class="text-muted small"><?= e(t('dash.none')) ?></div><?php endif; ?>
    <?php foreach ($dash['open'] as $o):
        $total = max(1, (int)$o['total_steps']);
        $pct = (int)round(((int)$o['done_steps']) / $total * 100);
        $stepNm = $o['step_id'] !== null ? master_name('STEP', (string)$o['step_id'], (string)$o['step_nm']) : (string)$o['step_nm'];
    ?>
      <div class="mb-2 small">
        <div class="d-flex justify-content-between">
          <span><a href="<?= e(url('/requests/' . (int)$o['request_id'])) ?>"><?= e($o['request_no']) ?></a> <?= e($o['title']) ?></span>
          <span class="badge text-bg-<?= e($badge[$o['status']] ?? 'secondary') ?>"><?= e(t('rstatus.' . $o['status'])) ?></span>
        </div>
        <div class="progress" style="height: 6px"><div class="progress-bar" style="width: <?= $pct ?>%"></div></div>
        <div class="text-muted"><?= e(t('dash.progress', ['done' => (int)$o['done_steps'], 'total' => (int)$o['total_steps']])) ?> ·
          <?= e($stepNm) ?><?= $o['actor_names'] !== [] ? ' (' . e(implode(', ', $o['actor_names'])) . ')' : '' ?> · <?= e(t('request.due')) ?> <?= e(fmt_date((string)$o['due'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div></div></div>
</div>

<?php if (isset($dash['dept'])): $d = $dash['dept']; ?>
<h3 class="h6 text-muted mt-4"><?= e(t('dash.dept_title')) ?></h3>
<div class="row g-2 mb-3">
  <?= $stat(t('rstatus.IN_PROGRESS'), (int)$d['status']['IN_PROGRESS'], url('/requests?scope=dept&status=IN_PROGRESS')) ?>
  <?= $stat(t('dash.delayed'), (int)$d['lateCount'], '', 'danger') ?>
  <?= $stat(t('dash.imminent'), (int)$d['soonCount'], '', 'warning') ?>
  <?= $stat(t('dash.schedule_pending'), (int)$d['schedCount'], '', 'warning') ?>
  <?= $stat(t('rstatus.COMPLETED'), (int)$d['status']['COMPLETED'], url('/requests?scope=dept&status=COMPLETED')) ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="card h-100"><div class="card-body">
    <h3 class="h6"><?= e(t('dash.by_step')) ?></h3>
    <?php if ($d['byStep'] === []): ?><div class="text-muted small"><?= e(t('dash.none')) ?></div><?php endif; ?>
    <?php foreach ($d['byStep'] as $s): ?>
      <div class="d-flex justify-content-between small border-bottom py-1"><span><?= e($s['step_nm']) ?></span><strong><?= (int)$s['n'] ?></strong></div>
    <?php endforeach; ?>
  </div></div></div>
  <div class="col-lg-8"><div class="card h-100"><div class="card-body">
    <h3 class="h6 text-danger"><?= e(t('dash.delayed')) ?> (<?= (int)$d['lateCount'] ?>)</h3><?= $rows($d['late']) ?>
    <h3 class="h6 mt-3"><?= e(t('dash.schedule_pending')) ?> (<?= (int)$d['schedCount'] ?>)</h3><?= $rows($d['sched']) ?>
  </div></div></div>
</div>
<?php endif; ?>

<?php if (isset($dash['all'])): $a = $dash['all']; ?>
<h3 class="h6 text-muted mt-4"><?= e(t('dash.all_title')) ?></h3>
<div class="row g-2 mb-3">
  <?= $stat(t('rstatus.IN_PROGRESS'), (int)$a['status']['IN_PROGRESS'], url('/requests?scope=all&status=IN_PROGRESS')) ?>
  <?= $stat(t('dash.new_requests'), (int)$a['newCount'], '', 'primary') ?>
  <?= $stat(t('dash.delayed'), (int)$a['lateCount'], '', 'danger') ?>
  <?= $stat(t('dash.imminent'), (int)$a['soonCount'], '', 'warning') ?>
  <?= $stat(t('dash.schedule_pending'), (int)$a['schedCount'], '', 'warning') ?>
  <?= $stat(t('rstatus.RETURNED'), (int)$a['status']['RETURNED'], url('/requests?scope=all&status=RETURNED')) ?>
</div>

<div class="card mb-3"><div class="card-body">
  <h3 class="h6"><i class="bi bi-kanban"></i> <?= e(t('dash.kanban')) ?></h3>
  <?php if ($a['kanban'] === []): ?><div class="text-muted small"><?= e(t('dash.none')) ?></div><?php endif; ?>
  <div class="d-flex gap-2 overflow-auto pb-1">
    <?php foreach ($a['kanban'] as $col): ?>
      <div class="flex-shrink-0 bg-body-tertiary rounded p-2" style="width: 220px">
        <div class="d-flex justify-content-between fw-semibold small mb-2"><span><?= e($col['step_nm']) ?></span><span class="badge text-bg-secondary"><?= (int)$col['n'] ?></span></div>
        <?php foreach ($col['cards'] as $c): $late = (string)$c['due'] < $today; ?>
          <a class="card mb-2 text-decoration-none border-<?= $late ? 'danger' : 'secondary-subtle' ?>" href="<?= e(url('/requests/' . (int)$c['request_id'])) ?>"><div class="card-body p-2 small">
            <div class="text-muted"><?= e($c['request_no']) ?><?= (int)$c['sched_pending'] > 0 ? ' <i class="bi bi-calendar-event text-warning"></i>' : '' ?></div>
            <div class="text-body"><?= e($c['title']) ?></div>
            <div class="<?= $late ? 'text-danger' : 'text-muted' ?>"><?= e($c['owner_nm'] ?? t('dash.unassigned')) ?> · <?= e(fmt_date((string)$c['due'])) ?></div>
          </div></a>
        <?php endforeach; ?>
        <?php if ((int)$col['n'] > count($col['cards'])): ?><div class="small text-muted text-center">+<?= (int)$col['n'] - count($col['cards']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div></div>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h3 class="h6 text-danger"><?= e(t('dash.delayed')) ?> (<?= (int)$a['lateCount'] ?>)</h3><?= $rows($a['late'], true) ?>
    <h3 class="h6 mt-3"><?= e(t('dash.imminent')) ?> (<?= (int)$a['soonCount'] ?>)</h3><?= $rows($a['soon'], true) ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h3 class="h6"><?= e(t('dash.schedule_pending')) ?> (<?= (int)$a['schedCount'] ?>)</h3><?= $rows($a['sched'], true) ?>
    <h3 class="h6 mt-3"><?= e(t('dash.new_requests')) ?> (<?= (int)$a['newCount'] ?>)</h3><?= $rows($a['new'], true) ?>
  </div></div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h3 class="h6"><?= e(t('dash.workload')) ?></h3>
    <table class="table table-sm small mb-0"><thead><tr><th><?= e(t('dash.owner')) ?></th><th class="text-end"><?= e(t('rstatus.IN_PROGRESS')) ?></th><th class="text-end"><?= e(t('dash.delayed')) ?></th></tr></thead><tbody>
    <?php foreach ($a['workload'] as $w): ?>
      <tr><td><?= e($w['owner_nm'] ?? t('dash.unassigned')) ?></td><td class="text-end"><?= (int)$w['n'] ?></td><td class="text-end<?= (int)$w['late'] > 0 ? ' text-danger' : '' ?>"><?= (int)$w['late'] ?></td></tr>
    <?php endforeach; ?>
    <?php if ($a['workload'] === []): ?><tr><td colspan="3" class="text-muted"><?= e(t('dash.none')) ?></td></tr><?php endif; ?>
    </tbody></table>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h3 class="h6"><?= e(t('dash.by_service')) ?></h3>
    <?php foreach ($a['byService'] as $s): ?>
      <div class="d-flex justify-content-between small border-bottom py-1"><span><?= e(master_name('SERVICE', (string)$s['service_cd'], (string)$s['service_nm'])) ?></span><strong><?= (int)$s['n'] ?></strong></div>
    <?php endforeach; ?>
    <?php if ($a['byService'] === []): ?><div class="text-muted small"><?= e(t('dash.none')) ?></div><?php endif; ?>
  </div></div></div>
</div>
<?php endif; ?>

<h3 class="h6 text-muted mt-4"><?= e(t('dash.recent_mail')) ?></h3>
<div class="card mb-3"><ul class="list-group list-group-flush small">
  <?php foreach ($dash['mails'] as $m): ?>
    <li class="list-group-item d-flex justify-content-between">
      <span><?php if ($m['request_id'] !== null): ?><a href="<?= e(url('/requests/' . (int)$m['request_id'])) ?>"><?= e($m['subject']) ?></a><?php else: ?><?= e($m['subject']) ?><?php endif; ?></span>
      <span class="text-muted"><?= e(fmt_date((string)$m['created_at'])) ?></span>
    </li>
  <?php endforeach; ?>
  <?php if ($dash['mails'] === []): ?><li class="list-group-item text-muted"><?= e(t('dash.none')) ?></li><?php endif; ?>
</ul></div>
