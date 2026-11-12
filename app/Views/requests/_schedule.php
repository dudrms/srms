<?php
/**
 * 일정 협의(완료요구일) 카드.
 * @var array $req
 * @var array $sched
 * @var array<int,string> $stepNameOf
 */

$rid = (int)$req['request_id'];
$stBadge = ['PROPOSED' => 'warning', 'AGREED' => 'success', 'REJECTED' => 'danger', 'COUNTERED' => 'info', 'WITHDRAWN' => 'secondary'];
$reasonName = [];
foreach ($sched['reasons'] as $rs) {
    $reasonName[(string)$rs['code_cd']] = master_name('CODE', 'SCHEDULE_REASON:' . $rs['code_cd'], (string)$rs['code_nm']);
}
$p = $sched['pending'];
$status = (string)$req['status'];
$show = $p !== null || $sched['canPropose'] || $sched['history'] !== [];
if (!$show) {
    return;
}
?>
<div class="card mb-3<?= $p !== null ? ' border-warning' : '' ?>" id="schedule"><div class="card-body">
  <h3 class="h6"><i class="bi bi-calendar-event"></i> <?= e(t('sched.title')) ?></h3>

  <?php if ($p !== null): ?>
    <div class="alert alert-warning py-2">
      <div class="fw-semibold"><i class="bi bi-hourglass-split"></i> <?= e(t('sched.pending')) ?></div>
      <div class="small mt-1">
        <?= e(t('sched.pending_desc', [
            'name' => (string)($sched['pendingBy']['user_nm'] ?? ''),
            'from' => fmt_date((string)$p['prev_end']),
            'to' => fmt_date((string)$p['new_end']),
        ])) ?>
        <div><?= e($reasonName[$p['reason_cd']] ?? $p['reason_cd']) ?>: <?= nl2br(e((string)$p['reason_text'])) ?></div>
        <div class="text-muted"><?= e(t('sched.response_due')) ?>: <?= e(fmt_date($sched['responseDue'])) ?> · <?= e(t('request.err_schedule_locked')) ?></div>
      </div>
    </div>
    <?php if ($sched['canRespond']): ?>
      <form method="post" action="<?= e(url('/requests/' . $rid . '/schedule/' . (int)$p['change_id'] . '/respond')) ?>" class="row g-2 mb-2">
        <?= csrf_field() ?>
        <div class="col-md-8"><textarea class="form-control form-control-sm" name="comment" rows="2" maxlength="1000" placeholder="<?= e(t('sched.comment_ph')) ?>"></textarea></div>
        <div class="col-md-4"><label class="form-label small mb-0"><?= e(t('sched.counter_end')) ?></label>
          <input class="form-control form-control-sm" type="date" name="counter_end" min="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d', strtotime((string)$p['new_end'] . ' -1 day'))) ?>"></div>
        <div class="col-12 d-flex flex-wrap gap-2">
          <button class="btn btn-sm btn-success" name="decision" value="AGREE"><?= e(t('sched.agree')) ?></button>
          <button class="btn btn-sm btn-outline-danger" name="decision" value="REJECT"><?= e(t('sched.reject')) ?></button>
          <button class="btn btn-sm btn-outline-primary" name="decision" value="COUNTER"><?= e(t('sched.counter')) ?></button>
          <span class="form-text"><?= e(t('sched.respond_hint')) ?></span>
        </div>
      </form>
    <?php endif; ?>
    <?php if ($sched['canWithdraw']): ?>
      <form method="post" action="<?= e(url('/requests/' . $rid . '/schedule/' . (int)$p['change_id'] . '/withdraw')) ?>" data-confirm="<?= e(t('sched.confirm_withdraw')) ?>" class="mb-2">
        <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><?= e(t('sched.withdraw')) ?></button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($sched['canPropose']): ?>
    <details class="mb-2">
      <summary class="btn btn-sm btn-outline-warning"><?= e(t('sched.propose_btn')) ?></summary>
      <form method="post" action="<?= e(url('/requests/' . $rid . '/schedule')) ?>" class="row g-2 mt-1">
        <?= csrf_field() ?>
        <div class="col-12 form-text"><?= e(t('sched.propose_hint')) ?></div>
        <div class="col-md-3"><label class="form-label small mb-0 required"><?= e(t('sched.new_end')) ?></label>
          <input class="form-control form-control-sm" type="date" name="new_end" min="<?= e(date('Y-m-d', strtotime((string)$sched['effectiveDue'] . ' +1 day'))) ?>" required></div>
        <div class="col-md-3"><label class="form-label small mb-0 required"><?= e(t('sched.reason')) ?></label>
          <select class="form-select form-select-sm" name="reason_cd" required>
            <?php foreach ($sched['reasons'] as $rs): ?><option value="<?= e($rs['code_cd']) ?>"><?= e($reasonName[(string)$rs['code_cd']]) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6"><label class="form-label small mb-0 required"><?= e(t('sched.reason_text')) ?></label>
          <textarea class="form-control form-control-sm" name="reason_text" rows="2" maxlength="1000" required></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-warning"><?= e(t('sched.submit')) ?></button></div>
      </form>
    </details>
  <?php endif; ?>

  <?php if ($sched['history'] !== []): ?>
    <div class="fw-semibold small mt-2"><?= e(t('sched.history')) ?></div>
    <div class="table-responsive">
    <table class="table table-sm small align-middle mb-0">
      <thead><tr><th><?= e(t('sched.proposed_at')) ?></th><th><?= e(t('sched.step')) ?></th><th><?= e(t('sched.proposer')) ?></th>
        <th><?= e(t('sched.change')) ?></th><th><?= e(t('sched.reason')) ?></th><th><?= e(t('request.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($sched['history'] as $h): ?>
        <tr>
          <td class="text-nowrap"><?= e(fmt_date($h['proposed_at'])) ?></td>
          <td class="text-nowrap"><?= e($h['step_no'] === null ? '' : ($stepNameOf[(int)$h['step_no']] ?? ('#' . $h['step_no']))) ?></td>
          <td><?= e($h['proposer_nm']) ?></td>
          <td class="text-nowrap"><?= e(fmt_date((string)$h['prev_end'])) ?> &rarr; <strong><?= e(fmt_date((string)$h['new_end'])) ?></strong></td>
          <td><?= e($reasonName[$h['reason_cd']] ?? $h['reason_cd']) ?><div class="text-muted"><?= e($h['reason_text']) ?></div></td>
          <td><span class="badge text-bg-<?= e($stBadge[$h['status']] ?? 'secondary') ?>"><?= e(t('schst.' . $h['status'])) ?></span>
            <?php foreach ($h['agreements'] as $a): ?>
              <div class="text-muted"><?= e($a['user_nm']) ?>: <?= e(t('sched.' . strtolower((string)$a['decision']))) ?>
                <?= $a['counter_end'] !== null ? e('(' . fmt_date((string)$a['counter_end']) . ')') : '' ?>
                <?= $a['comment'] !== null ? e(' - ' . $a['comment']) : '' ?></div>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div></div>
