<?php
/**
 * @var array<string,mixed> $data
 * @var array<string,mixed> $f
 * @var list<array<string,mixed>> $services
 * @var list<array<string,mixed>> $owners
 * @var list<array<string,mixed>> $types
 * @var string $tab monthly | detail
 * @var string $query
 */

use App\Services\ReportService;

$s = $data['summary'];
$stat = static fn(string $label, string $value, string $tone = ''): string =>
    '<div class="col-6 col-md-3 col-xl"><div class="card h-100"><div class="card-body py-2"><div class="text-muted small">' . e($label)
    . '</div><div class="fs-4 lh-sm' . ($tone !== '' ? ' text-' . $tone : '') . '">' . e($value) . '</div></div></div></div>';
$monthly = $tab === 'monthly';
$tabUrl = static fn(string $t): string => url('/reports') . '?' . http_build_query(
    ['tab' => $t] + array_filter($f, static fn($v): bool => $v !== '' && $v !== 0)
);
$hidden = static fn(string $name, mixed $value): string => '<input type="hidden" name="' . e($name) . '" value="' . e((string)$value) . '">';

// 적용 중인 조건(입력칸과 결과를 비교하지 않아도 되도록 결과 위에 보여준다)
$svcName = t('request.all_service');
foreach ($services as $sv) {
    if ((int)$f['service_id'] === (int)$sv['service_id']) {
        $svcName = master_name('SERVICE', (string)$sv['service_cd'], (string)$sv['service_nm']);
    }
}
$typeName = t('rpt.all');
foreach ($types as $ty) {
    if ($f['maint_type'] === $ty['code_cd']) {
        $typeName = master_name('CODE', 'MAINT_TYPE:' . $ty['code_cd'], (string)$ty['code_nm']);
    }
}
$ownerName = t('rpt.all');
foreach ($owners as $ow) {
    if ((int)$f['owner_id'] === (int)$ow['user_id']) {
        $ownerName = (string)$ow['user_nm'];
    }
}
$conds = $monthly
    ? [t('rpt.year') => (string)(int)$f['year']]
    : [t('rpt.period') => $f['from'] . ' ~ ' . $f['to'], t('rpt.grain') => t('rpt.grain.' . $f['grain'])];
$conds += [t('request.service') => $svcName, t('request.type') => $typeName, t('dash.owner') => $ownerName];
?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h2 class="h4 mb-0"><i class="bi bi-bar-chart-line"></i> <?= e(t('rpt.title')) ?></h2>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $monthly ? ' active' : '' ?>" href="<?= e($tabUrl('monthly')) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e(t('rpt.tab.monthly')) ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $monthly ? '' : ' active' ?>" href="<?= e($tabUrl('detail')) ?>"><i class="bi bi-table"></i> <?= e(t('rpt.tab.detail')) ?></a></li>
</ul>

<form method="get" action="<?= e(url('/reports')) ?>" class="row g-2 align-items-end mb-2">
  <?= $hidden('tab', $tab) ?>
  <?php if ($monthly): ?>
    <?= $hidden('from', $f['from']) . $hidden('to', $f['to']) . $hidden('grain', $f['grain']) ?>
    <div class="col-auto"><label class="form-label small mb-0"><?= e(t('rpt.year')) ?></label>
      <input type="number" class="form-control form-control-sm" name="year" min="2000" max="2100" value="<?= (int)$f['year'] ?>" style="width: 6rem"></div>
  <?php else: ?>
    <?= $hidden('year', $f['year']) ?>
    <div class="col-auto"><label class="form-label small mb-0"><?= e(t('rpt.from')) ?></label><input type="date" class="form-control form-control-sm" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="col-auto"><label class="form-label small mb-0"><?= e(t('rpt.to')) ?></label><input type="date" class="form-control form-control-sm" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="col-auto"><label class="form-label small mb-0"><?= e(t('rpt.grain')) ?></label>
      <select class="form-select form-select-sm" name="grain">
        <?php foreach (ReportService::GRAINS as $g): ?><option value="<?= e($g) ?>"<?= $f['grain'] === $g ? ' selected' : '' ?>><?= e(t('rpt.grain.' . $g)) ?></option><?php endforeach; ?>
      </select></div>
  <?php endif; ?>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t('request.service')) ?></label>
    <select class="form-select form-select-sm" name="service_id"><option value=""><?= e(t('request.all_service')) ?></option>
      <?php foreach ($services as $sv): ?><option value="<?= (int)$sv['service_id'] ?>"<?= (int)$f['service_id'] === (int)$sv['service_id'] ? ' selected' : '' ?>><?= e(master_name('SERVICE', (string)$sv['service_cd'], (string)$sv['service_nm'])) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t('request.type')) ?></label>
    <select class="form-select form-select-sm" name="maint_type"><option value=""><?= e(t('rpt.all')) ?></option>
      <?php foreach ($types as $t): ?><option value="<?= e($t['code_cd']) ?>"<?= $f['maint_type'] === $t['code_cd'] ? ' selected' : '' ?>><?= e(master_name('CODE', 'MAINT_TYPE:' . $t['code_cd'], (string)$t['code_nm'])) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><label class="form-label small mb-0"><?= e(t('dash.owner')) ?></label>
    <select class="form-select form-select-sm" name="owner_id"><option value=""><?= e(t('rpt.all')) ?></option>
      <?php foreach ($owners as $o): ?><option value="<?= (int)$o['user_id'] ?>"<?= (int)$f['owner_id'] === (int)$o['user_id'] ? ' selected' : '' ?>><?= e($o['user_nm']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary"><?= e(t('common.search')) ?></button></div>
</form>

<div class="d-flex flex-wrap align-items-center gap-1 mb-2">
  <span class="text-muted small me-1"><?= e(t('rpt.applied')) ?></span>
  <?php foreach ($conds as $label => $value): ?>
    <span class="badge bg-body-secondary text-body border fw-normal"><?= e($label) ?> <strong><?= e($value) ?></strong></span>
  <?php endforeach; ?>
</div>
<details class="mb-3">
  <summary class="small text-muted"><?= e(t('rpt.basis.' . $tab)) ?> <span class="text-primary">· <?= e(t('rpt.basis.more')) ?></span></summary>
  <ul class="small text-muted mt-2 mb-0">
    <?php foreach (($monthly ? ['m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7'] : ['d1', 'd2', 'd3', 'd4']) as $k): ?><li><?= e(t('rpt.def.' . $k)) ?></li><?php endforeach; ?>
  </ul>
</details>

<?php if (!$monthly): ?>
<div class="row g-2 mb-3">
  <?= $stat(t('rpt.col.total'), (string)$s['total']) ?>
  <?= $stat(t('rpt.col.done'), (string)$s['done']) ?>
  <?= $stat(t('rpt.col.open'), (string)$s['open']) ?>
  <?= $stat(t('rpt.canceled'), (string)$s['canceled']) ?>
  <?= $stat(t('rpt.col.late'), (string)$s['late'], $s['late'] > 0 ? 'danger' : '') ?>
  <?= $stat(t('rpt.col.avg_days'), $s['avg_days'] === null ? '-' : (string)$s['avg_days']) ?>
  <?= $stat(t('rpt.col.effort'), (string)$s['effort']) ?>
</div>
<?php endif; ?>

<?php
$tabSections = array_filter($data['sections'], static fn($k): bool => ($k === 'monthly') === $monthly, ARRAY_FILTER_USE_KEY);
// 지연·초과 열(양수면 빨강), 합계 열(굵게), 월간표 핵심 지표 행(굵게)
$lateHeads = [t('rpt.col.late'), t('rpt.col.late_done'), t('rpt.col.late_days'), t('rpt.col.avg_late_days'), t('rpt.col.open_over')];
$sumHeads = [t('rpt.col.sum'), t('rpt.col.total')];
$keyMetrics = [t('rpt.m.in'), t('rpt.col.done'), t('rpt.m.open')];
$lateMetrics = [t('rpt.col.open_over'), t('rpt.col.late_done')];
?>
<?php if (count($tabSections) > 1): ?>
<nav class="d-flex flex-wrap align-items-center gap-1 mb-3 rpt-nav">
  <?php foreach ($tabSections as $nk => $_): ?>
    <a class="btn btn-sm btn-outline-secondary" href="#rpt-<?= e($nk) ?>"><?= e(t('rpt.sec.' . $nk)) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php foreach ($tabSections as $key => $sec): ?>
  <?php
  $head = $sec['head'];
  $nCols = count($head);
  $grid = $key === 'monthly';
  $sumCol = $nCols > 2 && in_array($head[$nCols - 1], $sumHeads, true) ? $nCols - 1 : -1;
  $lateCols = array_keys(array_filter($head, static fn($h): bool => in_array($h, $lateHeads, true)));
  $side = $sec['chart'] !== null && !$grid && $nCols <= 8;
  ?>
  <div class="card mb-3 rpt-sec" id="rpt-<?= e($key) ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h3 class="h6 mb-0"><?= e(t('rpt.sec.' . $key)) ?></h3>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reports/export/' . $key . ($query !== '' ? '?' . $query : ''))) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
    </div>
    <?php if (($sec['note'] ?? '') !== ''): ?><p class="text-muted small mb-2"><?= e($sec['note']) ?></p><?php endif; ?>
    <?php if ($sec['rows'] === []): ?>
      <div class="text-muted small"><?= e(t('common.empty')) ?></div>
    <?php else: ?>
      <div class="row g-3 align-items-start">
      <?php if ($sec['chart'] !== null): ?>
        <div class="<?= $side ? 'col-xl-5' : 'col-12' ?>">
          <div class="position-relative" style="<?= $side ? '' : 'max-width: 760px; ' ?>height: 260px"><canvas data-chart="<?= e((string)json_encode($sec['chart'], JSON_UNESCAPED_UNICODE)) ?>"></canvas></div>
        </div>
      <?php endif; ?>
      <div class="<?= $side ? 'col-xl-7' : 'col-12' ?>">
      <div class="table-responsive"><table class="table table-sm table-hover small align-middle mb-0 tbl-grid rpt-grid">
        <thead><tr><?php foreach ($head as $i => $h): ?><th<?= $grid && $i < 2 ? ' class="rpt-c' . ($i + 1) . '"' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php
        $prevGrp = null;
        $alt = false;
        foreach ($sec['rows'] as $row):
            $trCls = [];
            $start = false;
            $metric = (string)($row[1] ?? '');
            if ($grid) {
                $start = $row[0] !== $prevGrp;
                if ($start && $prevGrp !== null) {
                    $alt = !$alt;
                    $trCls[] = 'rpt-grp-start';
                }
                if ($alt) { $trCls[] = 'rpt-alt'; }
                $prevGrp = $row[0];
                if (in_array($metric, $keyMetrics, true) && $row[0] !== t('rpt.share')) { $trCls[] = 'rpt-key'; }
            }
        ?>
          <tr<?= $trCls ? ' class="' . e(implode(' ', $trCls)) . '"' : '' ?>><?php foreach ($row as $i => $cell): ?>
            <?php
            $num = $i > 0 && (is_int($cell) || is_float($cell) || is_numeric($cell) || $cell === '-');
            $cls = [];
            if ($num) { $cls[] = 'text-end'; }
            if ($grid && $i < 2) { $cls[] = 'rpt-c' . ($i + 1); }
            if ($i === 0) { $cls[] = 'rpt-lead'; }
            if ($i === $sumCol) { $cls[] = 'rpt-sum'; }
            $isLate = $grid ? ($i > 1 && in_array($metric, $lateMetrics, true)) : in_array($i, $lateCols, true);
            if ($isLate && is_numeric($cell) && (float)$cell > 0) { $cls[] = 'text-danger fw-semibold'; }
            $text = $grid && $i === 0 && !$start ? '' : (string)$cell;
            ?>
            <td class="<?= e(implode(' ', $cls)) ?>"><?= e($text) ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      </div>
      </div>
    <?php endif; ?>
  </div></div>
<?php endforeach; ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset('js/report.js')) ?>"></script>
