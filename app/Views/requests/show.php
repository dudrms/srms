<?php
/**
 * @var array $req
 * @var array $svc
 * @var array|null $requester
 * @var array|null $process
 * @var list<array> $steps
 * @var array|null $cur
 * @var list<array> $attachments
 * @var list<array> $timeline
 * @var bool $canAct
 * @var bool $canEdit
 * @var bool $canCancel
 * @var int|null $uploadStep
 * @var bool $locked
 * @var bool $canEffort
 * @var array $sched
 * @var bool $isRequester
 * @var array|null $log
 * @var array|null $itUser
 * @var bool $canAssign
 * @var bool $canSplit
 * @var list<array> $users
 * @var array|null $parent
 * @var list<array> $children
 * @var array<string,string> $typeNames
 * @var array<string,string> $prioNames
 */

use App\Services\AttachmentService;
use App\Services\HtmlSanitizer;

$badge = ['DRAFT' => 'secondary', 'IN_PROGRESS' => 'primary', 'RETURNED' => 'warning', 'COMPLETED' => 'success', 'CANCELED' => 'dark'];
$stepBadge = ['DONE' => 'success', 'ACTIVE' => 'primary', 'WAITING' => 'secondary'];
$today = date('Y-m-d');
$rid = (int)$req['request_id'];
$status = (string)$req['status'];
$late = false;
$lateDays = 0;
if (in_array($status, ['IN_PROGRESS', 'RETURNED'], true) && $req['req_due_date'] < $today) {
    $late = true;
    $lateDays = \App\Services\Dates::daysLate((string)$req['req_due_date'], $today);
}
if ($status === 'COMPLETED' && $req['actual_end'] !== null && $req['actual_end'] > $req['req_due_date']) {
    $late = true;
    $lateDays = \App\Services\Dates::daysLate((string)$req['req_due_date'], (string)$req['actual_end']);
}
$lastReject = null;
foreach ($timeline as $ev) {
    if ($ev['kind'] === 'APPROVAL' && $ev['action'] === 'REJECT') {
        $lastReject = $ev;
    }
}
$byStep = [];
foreach ($attachments as $a) {
    $byStep[(int)$a['step_no']][] = $a;
}
$stepNameOf = [];
$stepTypeOf = [];
foreach ($steps as $s) {
    $stepNameOf[(int)$s['step_no']] = master_name('STEP', (string)$s['step_id'], (string)$s['step_nm']);
    $stepTypeOf[(int)$s['step_no']] = (string)$s['step_type'];
}
$policy = $cur !== null ? AttachmentService::policy($cur) : null;
?>
<link rel="stylesheet" href="<?= e(asset('vendor/quill/quill.snow.css')) ?>">

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
  <div>
    <div class="text-muted small"><?= e($req['request_no']) ?></div>
    <h2 class="h4 mb-1"><?= e($req['title']) ?>
      <span class="badge text-bg-<?= e($badge[$status] ?? 'secondary') ?>"><?= e(t('rstatus.' . $status)) ?></span>
      <?php if ($log !== null): ?><span class="badge text-bg-info"><i class="bi bi-journal-text"></i> <?= e(t('worklog.badge')) ?></span><?php endif; ?>
      <?php if ($parent !== null): ?><span class="badge text-bg-secondary"><i class="bi bi-diagram-2"></i> <?= e(t('split.badge')) ?></span><?php endif; ?>
      <?php if ($late): ?><span class="badge text-bg-danger"><?= e(t('request.late_days', ['n' => $lateDays])) ?></span><?php endif; ?>
      <?php if ($locked): ?><span class="badge text-bg-warning"><i class="bi bi-lock"></i> <?= e(t('request.locked_badge')) ?></span><?php endif; ?></h2>
  </div>
  <div class="d-flex gap-2">
    <?php if ($log !== null && $log['canManage']): ?><a class="btn btn-outline-primary" href="<?= e(url('/logs/' . $rid . '/edit')) ?>"><?= e(t('common.edit')) ?></a><?php endif; ?>
    <?php if ($canEdit): ?><a class="btn btn-outline-primary" href="<?= e(url('/requests/' . $rid . '/edit')) ?>"><?= e(t('common.edit')) ?></a><?php endif; ?>
    <?php if ($canCancel): ?>
      <form method="post" action="<?= e(url('/requests/' . $rid . '/cancel')) ?>" data-confirm="<?= e(t('request.confirm_cancel')) ?>">
        <?= csrf_field() ?><button class="btn btn-outline-danger"><?= e(t('request.cancel')) ?></button>
      </form>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= e(url('/requests')) ?>"><?= e(t('request.back_list')) ?></a>
  </div>
</div>

<?php if ($status === 'RETURNED' && $isRequester): ?>
  <div class="alert alert-warning">
    <strong><?= e(t('request.returned_notice')) ?></strong>
    <?php if ($lastReject !== null): ?><div class="mt-1"><?= e($lastReject['user']) ?>: <?= nl2br(e((string)$lastReject['text'])) ?></div><?php endif; ?>
  </div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
  <h3 class="h6"><?= e(t('request.flow')) ?></h3>
  <div class="d-flex flex-wrap align-items-stretch gap-2">
    <?php foreach ($steps as $i => $s):
        $st = (string)$s['state'];
        $isCur = $cur !== null && (int)$cur['step_no'] === (int)$s['step_no'] && $status === 'IN_PROGRESS';
    ?>
      <?php if ($i > 0): ?><div class="align-self-center"><i class="bi bi-chevron-right text-muted"></i></div><?php endif; ?>
      <div class="border rounded px-2 py-1 bg-<?= $st === 'DONE' ? 'success-subtle' : ($st === 'ACTIVE' ? 'primary-subtle' : 'body-tertiary') ?><?= $isCur ? ' border-primary border-2' : '' ?>" style="min-width: 150px;">
        <div><span class="badge text-bg-<?= e($stepBadge[$st] ?? 'secondary') ?> border"><?= (int)$s['step_no'] ?></span>
          <strong><?= e($stepNameOf[(int)$s['step_no']]) ?></strong></div>
        <div class="small text-muted"><?= e(t('sstate.' . $st)) ?> · <?= e(t('ptype.' . $s['step_type'])) ?></div>
        <div class="small"><i class="bi bi-person"></i> <?= e($s['actor_names'] === [] ? t('actor.' . $s['actor_type']) : implode(', ', $s['actor_names'])) ?></div>
        <?php if ($st === 'ACTIVE' && $s['due_at'] !== null): ?><div class="small text-muted"><?= e(t('request.step_due')) ?>: <?= e(fmt_date($s['due_at'])) ?></div><?php endif; ?>
        <?php if ($s['ended_at'] !== null && $st === 'DONE'): ?><div class="small text-muted"><?= e(fmt_date($s['ended_at'])) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div></div>

<div class="row g-3">
  <div class="col-lg-8">
    <?php if ($parent !== null || $children !== []): ?>
      <div class="alert alert-secondary py-2 small"><i class="bi bi-diagram-2"></i>
        <?php if ($parent !== null): ?>
          <?= e(t('split.parent')) ?>: <a href="<?= e(url('/requests/' . (int)$parent['request_id'])) ?>"><?= e($parent['request_no']) ?></a> <?= e($parent['title']) ?>
        <?php endif; ?>
        <?php if ($children !== []): ?>
          <?= e(t('split.children')) ?>:
          <?php foreach ($children as $c): ?><a href="<?= e(url('/requests/' . (int)$c['request_id'])) ?>"><?= e($c['request_no']) ?></a> <?= e($c['title']) ?>;<?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($canSplit): ?>
      <details class="card mb-3" id="split">
        <summary class="card-body py-2 fw-semibold"><i class="bi bi-diagram-2"></i> <?= e(t('split.title')) ?></summary>
        <div class="card-body pt-0">
          <p class="small text-muted"><?= e(t('split.hint')) ?></p>
          <form method="post" action="<?= e(url('/requests/' . $rid . '/split')) ?>" data-confirm="<?= e(t('split.confirm')) ?>">
            <?= csrf_field() ?>
            <div class="mb-2"><label class="form-label required"><?= e(t('split.new_title')) ?></label>
              <input class="form-control" name="title" maxlength="200" value="<?= e(mb_substr((string)$req['title'], 0, 190) . ' ' . t('split.title_suffix')) ?>" required></div>
            <div class="mb-2"><label class="form-label required"><?= e(t('split.note')) ?></label>
              <textarea class="form-control" name="note" rows="3" maxlength="1000" placeholder="<?= e(t('split.note_ph')) ?>" required></textarea></div>
            <div class="row g-2 mb-3">
              <div class="col-md-6"><label class="form-label required"><?= e(t('split.new_assignee')) ?></label>
                <select class="form-select form-select-sm" name="new_assignee_id" required data-filter data-filter-short="<?= e(t('common.search')) ?>" data-filter-ph="<?= e(t('common.filter_ph')) ?>" data-filter-empty="<?= e(t('common.empty')) ?>">
                  <option value=""><?= e(t('common.select')) ?></option><?= user_options($users, null) ?></select></div>
              <div class="col-md-6"><label class="form-label"><?= e(t('split.orig_assignee')) ?></label>
                <select class="form-select form-select-sm" name="orig_assignee_id" data-filter data-filter-short="<?= e(t('common.search')) ?>" data-filter-ph="<?= e(t('common.filter_ph')) ?>" data-filter-empty="<?= e(t('common.empty')) ?>">
                  <option value=""><?= e(t('split.keep')) ?></option><?= user_options($users, null) ?></select></div>
            </div>
            <button class="btn btn-primary"><?= e(t('split.submit')) ?></button>
          </form>
        </div>
      </details>
    <?php endif; ?>
    <?php if (!$canAct && $status === 'IN_PROGRESS' && $cur !== null):
        $waitNames = [];
        foreach ($steps as $s) {
            if ((int)$s['step_no'] === (int)$cur['step_no']) {
                $waitNames = $s['actor_names'];
            }
        }
        if ($waitNames !== []): ?>
      <div class="alert alert-secondary py-2 small"><i class="bi bi-hourglass-split"></i> <?= e(t('request.waiting_for', ['step' => $stepNameOf[(int)$cur['step_no']], 'names' => implode(', ', $waitNames)])) ?></div>
    <?php endif; endif; ?>
    <?php if ($canAct && $cur !== null): ?>
    <div class="card border-primary mb-3"><div class="card-body">
      <h3 class="h6"><i class="bi bi-pencil-square"></i> <?= e(t('request.action_title', ['step' => $stepNameOf[(int)$cur['step_no']]])) ?></h3>
      <?php if ($locked): ?>
        <div class="alert alert-warning py-2 mb-0"><i class="bi bi-lock"></i> <?= e(t('request.err_schedule_locked')) ?></div>
      <?php else: ?>
        <form method="post" action="<?= e(url('/requests/' . $rid . '/act')) ?>">
          <?= csrf_field() ?>
          <div class="mb-2"><textarea class="form-control" name="opinion" rows="3" maxlength="2000" placeholder="<?= e(t('request.opinion')) ?>"></textarea></div>
          <?php if ($cur['attach_mode'] === 'REQUIRED'): ?><div class="form-text mb-2 text-danger"><?= e(t('request.attach_required')) ?></div><?php endif; ?>
          <button class="btn btn-primary" name="decision" value="PASS"><?= e(t('act.pass.' . $cur['step_type'])) ?></button>
          <?php if ((int)$cur['allow_reject'] === 1): ?>
            <button class="btn btn-outline-danger" name="decision" value="REJECT" data-confirm="<?= e(t('request.confirm_reject')) ?>"><?= e(t('act.reject')) ?></button>
            <span class="form-text ms-2"><?= e(t('request.reject_hint')) ?></span>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?= \App\Core\View::render('requests/_schedule', ['req' => $req, 'sched' => $sched, 'stepNameOf' => $stepNameOf], null) ?>

    <div class="card mb-3"><div class="card-body">
      <h3 class="h6"><?= e(t('request.content')) ?></h3>
      <div class="ql-snow"><div class="ql-editor px-0"><?= HtmlSanitizer::clean((string)$req['content_html']) ?></div></div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
      <h3 class="h6"><i class="bi bi-paperclip"></i> <?= e(t('request.attachments')) ?></h3>
      <?php if ($attachments === []): ?><div class="text-muted small"><?= e(t('common.none')) ?></div><?php endif; ?>
      <?php foreach ($byStep as $no => $list): ?>
        <div class="fw-semibold small mt-2"><?= e($stepNameOf[$no] ?? ('#' . $no)) ?></div>
        <?php foreach ($list as $a): ?>
          <div class="d-flex align-items-center gap-2 small">
            <i class="bi bi-file-earmark"></i>
            <a href="<?= e(url('/attachments/' . (int)$a['attach_id'])) ?>"><?= e($a['orig_name']) ?></a>
            <span class="text-muted"><?= e(fmt_size((int)$a['size_byte'])) ?> · <?= e($a['user_nm']) ?> · <?= e(fmt_date($a['uploaded_at'])) ?></span>
            <?php if ($uploadStep === (int)$a['step_no'] && (int)$a['uploaded_by'] === (int)\App\Core\Auth::user()['user_id']): ?>
              <form method="post" action="<?= e(url('/requests/' . $rid . '/attachments/' . (int)$a['attach_id'] . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>">
                <?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if ($uploadStep !== null && !($status === 'DRAFT' || $status === 'RETURNED')): ?>
        <form class="mt-3" method="post" action="<?= e(url('/requests/' . $rid . '/attachments')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <div class="input-group input-group-sm">
            <input class="form-control" type="file" name="files[]" multiple data-file-list data-file-remove="<?= e(t('common.delete')) ?>" data-file-summary="<?= e(t('common.files_summary')) ?>">
            <button class="btn btn-outline-primary"><?= e(t('request.upload')) ?></button>
          </div>
          <?php if ($policy !== null): ?>
            <div class="form-text"><?= e(t('request.upload_policy', ['mb' => $policy['maxMb'], 'n' => $policy['maxCnt'], 'exts' => implode(', ', $policy['exts'])])) ?></div>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div></div>

    <div class="card" id="timeline"><div class="card-body">
      <h3 class="h6"><?= e(t('request.timeline')) ?></h3>
      <ul class="list-unstyled mb-3">
        <?php foreach ($timeline as $ev):
            $key = $ev['kind'] === 'APPROVAL' ? 'tl.' . $ev['action'] : ($ev['kind'] === 'SCHEDULE' ? 'tl.' . 'SCHEDULE_' . $ev['action'] : 'tl.' . $ev['kind']);
            if ($ev['kind'] === 'APPROVAL' && $ev['action'] === 'APPROVE' && in_array($stepTypeOf[(int)$ev['step_no']] ?? '', ['REVIEW', 'VERIFY'], true)) {
                $key = 'tl.' . $stepTypeOf[(int)$ev['step_no']];
            }
            $icon = match ($ev['kind'] === 'APPROVAL' ? $ev['action'] : $ev['kind']) {
                'SCHEDULE' => 'bi-calendar-event text-warning',
                'SPLIT', 'SPLIT_FROM' => 'bi-diagram-2 text-secondary', 'ASSIGN' => 'bi-person-check text-primary',
                'REJECT' => 'bi-arrow-return-left text-danger', 'COMMENT' => 'bi-chat-left-text', 'ATTACH' => 'bi-paperclip',
                'CANCEL' => 'bi-x-circle text-body', 'CREATED', 'SUBMIT' => 'bi-send text-primary', default => 'bi-check-circle text-success',
            };
        ?>
          <li class="mb-2">
            <i class="bi <?= e($icon) ?>"></i>
            <strong><?= e($ev['user']) ?></strong>
            <span><?= e(t($key)) ?></span>
            <?php if ($ev['step_no'] !== null): ?><span class="badge bg-body-secondary text-body border"><?= e($stepNameOf[(int)$ev['step_no']] ?? ('#' . $ev['step_no'])) ?></span><?php endif; ?>
            <span class="text-muted small"><?= e(fmt_date($ev['at'])) ?></span>
            <?php if ($ev['text'] !== null && $ev['text'] !== ''): ?><div class="ms-4 small"><?= $ev['kind'] === 'ATTACH' ? e($ev['text']) : nl2br(e((string)$ev['text'])) ?></div><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($status !== 'DRAFT'): ?>
      <form method="post" action="<?= e(url('/requests/' . $rid . '/comments')) ?>">
        <?= csrf_field() ?>
        <div class="input-group input-group-sm">
          <input class="form-control" name="body" maxlength="2000" placeholder="<?= e(t('request.comment_ph')) ?>" required>
          <button class="btn btn-outline-primary"><?= e(t('request.comment_add')) ?></button>
        </div>
      </form>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <dl class="row small mb-0">
        <dt class="col-5"><?= e(t('request.service')) ?></dt><dd class="col-7"><?= e(master_name('SERVICE', (string)$svc['service_cd'], (string)$svc['service_nm'])) ?></dd>
        <dt class="col-5"><?= e(t('request.type')) ?></dt><dd class="col-7"><?= e(master_name('CODE', 'MAINT_TYPE:' . $req['maint_type'], $typeNames[$req['maint_type']] ?? (string)$req['maint_type'])) ?></dd>
        <?php if ($log === null): ?>
        <dt class="col-5"><?= e(t('request.priority')) ?></dt><dd class="col-7"><?= e(master_name('CODE', 'PRIORITY:' . $req['priority'], $prioNames[$req['priority']] ?? (string)$req['priority'])) ?></dd>
        <?php endif; ?>
        <dt class="col-5"><?= e(t('request.requester')) ?></dt><dd class="col-7"><?= e($requester['user_nm'] ?? '') ?></dd>
        <?php if ($log === null): ?>
          <dt class="col-5"><?= e(t('split.assignee')) ?></dt>
          <dd class="col-7"><?= e($itUser['user_nm'] ?? '-') ?>
            <?php if ($req['it_assignee_id'] !== null): ?><span class="badge text-bg-secondary"><?= e(t('split.by_request')) ?></span><?php endif; ?>
            <?php if ($canAssign): ?>
              <form method="post" action="<?= e(url('/requests/' . $rid . '/assignee')) ?>" class="mt-1">
                <?= csrf_field() ?>
                <select class="form-select form-select-sm mb-1" name="assignee_id" data-filter data-filter-short="<?= e(t('common.search')) ?>" data-filter-ph="<?= e(t('common.filter_ph')) ?>" data-filter-empty="<?= e(t('common.empty')) ?>">
                  <option value=""><?= e(t('split.service_default')) ?></option><?= user_options($users, $req['it_assignee_id'] === null ? null : (int)$req['it_assignee_id']) ?></select>
                <button class="btn btn-sm btn-outline-primary"><?= e(t('split.assign_btn')) ?></button>
              </form>
            <?php endif; ?></dd>
        <?php endif; ?>
        <?php if ($log !== null): ?>
          <dt class="col-5"><?= e(t('worklog.request_date')) ?></dt><dd class="col-7"><?= e(fmt_date($req['request_date'])) ?></dd>
          <dt class="col-5"><?= e(t('worklog.channel')) ?></dt><dd class="col-7"><?= e($log['channel'] === null ? '-' : master_name('CODE', \App\Services\WorklogService::CHANNEL_GROUP . ':' . $log['channel']['code_cd'], (string)$log['channel']['code_nm'])) ?></dd>
          <dt class="col-5"><?= e(t('worklog.reg_by')) ?></dt><dd class="col-7"><?= e($log['reg']['user_nm'] ?? '') ?></dd>
        <?php endif; ?>
        <?php if ($log === null): ?>
        <dt class="col-5"><?= e(t('request.due')) ?></dt><dd class="col-7"><?= e(fmt_date($req['req_due_date'])) ?></dd>
        <dt class="col-5"><?= e(t('request.plan_end')) ?></dt><dd class="col-7"><?= e($req['plan_end'] !== null ? fmt_date($req['plan_end']) : '-') ?></dd>
        <?php endif; ?>
        <dt class="col-5"><?= e(t('request.actual_end')) ?></dt><dd class="col-7"><?= e($req['actual_end'] !== null ? fmt_date($req['actual_end']) : '-') ?></dd>
        <dt class="col-5"><?= e(t('request.effort')) ?></dt>
        <dd class="col-7"><?= e($req['effort_hours'] !== null ? \App\Services\Effort::fmt($req['effort_hours']) . t('effort.unit_h') : '-') ?>
          <?php if ($canEffort): ?>
            <form method="post" action="<?= e(url('/requests/' . (int)$req['request_id'] . '/effort')) ?>" class="mt-1">
              <?= csrf_field() ?>
              <?= effort_input($req['effort_hours'] !== null ? \App\Services\Effort::fmt($req['effort_hours']) : '', true, '', '<button class="btn btn-sm btn-outline-primary">' . e(t('common.save')) . '</button>') ?>
            </form>
          <?php endif; ?></dd>
        <dt class="col-5"><?= e(t('request.process')) ?></dt>
        <dd class="col-7"><?= e($process !== null ? master_name('PROCESS', (string)$process['process_cd'], (string)$process['process_nm']) . ' v' . $process['version_no'] : '') ?></dd>
        <dt class="col-5"><?= e(t('request.created')) ?></dt><dd class="col-7"><?= e(fmt_date($req['created_at'])) ?></dd>
      </dl>
    </div></div>
  </div>
</div>
