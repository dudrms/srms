<?php
/**
 * @var array|null $req
 * @var array $old
 * @var list<array> $users
 * @var list<array> $services
 * @var list<array> $channels
 * @var list<array> $attachments
 * @var string $today
 */

$editing = $req !== null;
$rid = $editing ? (int)$req['request_id'] : 0;
$action = $editing ? '/logs/' . $rid : '/logs';
$v = static fn(string $k): string => (string)($old[$k] ?? '');
$effort = $v('effort_hours') !== '' && is_numeric($v('effort_hours')) ? \App\Services\Effort::fmt($v('effort_hours')) : $v('effort_hours');
?>
<link rel="stylesheet" href="<?= e(asset('vendor/quill/quill.snow.css')) ?>">
<h2 class="h4 mb-1"><?= e(t($editing ? 'worklog.edit' : 'worklog.new')) ?>
  <?php if ($editing): ?><small class="text-muted fs-6"><?= e($req['request_no']) ?></small><?php endif; ?></h2>
<p class="text-muted small"><?= e(t('worklog.intro')) ?></p>
<form method="post" action="<?= e(url($action)) ?>" enctype="multipart/form-data" id="request-form">
  <?= csrf_field() ?>
  <div class="card mb-3"><div class="card-body">
    <div class="row g-2 mb-3">
      <div class="col-md-3"><label class="form-label required"><?= e(t('worklog.request_date')) ?></label>
        <input class="form-control" type="date" name="request_date" value="<?= e($v('request_date')) ?>" max="<?= e($today) ?>" required></div>
      <div class="col-md-5"><label class="form-label required"><?= e(t('request.requester')) ?></label>
        <select class="form-select" name="requester_id" required data-filter data-filter-short="<?= e(t('common.search')) ?>" data-filter-ph="<?= e(t('common.filter_ph')) ?>" data-filter-empty="<?= e(t('common.empty')) ?>">
          <option value=""><?= e(t('common.select')) ?></option>
          <?= user_options($users, (int)$v('requester_id') ?: null) ?>
        </select></div>
      <div class="col-md-4"><label class="form-label"><?= e(t('worklog.channel')) ?></label>
        <select class="form-select" name="channel">
          <option value=""><?= e(t('common.none')) ?></option>
          <?php foreach ($channels as $c): ?>
            <option value="<?= e($c['code_cd']) ?>"<?= $v('channel') === $c['code_cd'] ? ' selected' : '' ?>><?= e(master_name('CODE', \App\Services\WorklogService::CHANNEL_GROUP . ':' . $c['code_cd'], (string)$c['code_nm'])) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-md-4"><label class="form-label required"><?= e(t('request.service')) ?></label>
        <select class="form-select" name="service_id" required>
          <option value=""><?= e(t('common.select')) ?></option>
          <?php foreach ($services as $s): ?>
            <option value="<?= (int)$s['service_id'] ?>"<?= $v('service_id') === (string)$s['service_id'] ? ' selected' : '' ?>><?= e(master_name('SERVICE', (string)$s['service_cd'], (string)$s['service_nm'])) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label required"><?= e(t('worklog.actual_end')) ?></label>
        <input class="form-control" type="date" name="actual_end" value="<?= e($v('actual_end')) ?>" max="<?= e($today) ?>" required></div>
      <div class="col-md-4"><label class="form-label required"><?= e(t('request.effort')) ?></label>
        <?= effort_input($effort, false, '2') ?></div>
    </div>
    <div class="mb-3"><label class="form-label required"><?= e(t('request.title')) ?></label>
      <input class="form-control" name="title" value="<?= e($v('title')) ?>" maxlength="200" required></div>
    <div class="mb-3"><label class="form-label required"><?= e(t('request.content')) ?></label>
      <div id="editor" style="min-height: 220px;"></div>
      <textarea class="d-none" name="content_html" id="content_html"><?= e($v('content_html')) ?></textarea></div>
    <div class="mb-3"><label class="form-label"><i class="bi bi-paperclip"></i> <?= e(t('request.attachments')) ?></label>
      <input class="form-control" type="file" name="files[]" multiple data-file-list data-file-remove="<?= e(t('common.delete')) ?>" data-file-summary="<?= e(t('common.files_summary')) ?>">
      <div class="form-text"><?= e(t('request.attach_hint', ['mb' => \App\Services\AttachmentService::DEFAULT_MAX_MB])) ?></div>
      <?php foreach ($attachments as $a): ?>
        <div class="d-flex align-items-center gap-2 small mt-1">
          <i class="bi bi-file-earmark"></i> <a href="<?= e(url('/attachments/' . (int)$a['attach_id'])) ?>"><?= e($a['orig_name']) ?></a>
          <span class="text-muted"><?= e(fmt_size((int)$a['size_byte'])) ?></span>
          <button form="del_<?= (int)$a['attach_id'] ?>" class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
        </div>
      <?php endforeach; ?></div>
    <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
    <a class="btn btn-link" href="<?= e(url('/logs')) ?>"><?= e(t('common.cancel')) ?></a>
    <?php if ($editing): ?>
      <button form="del_log" class="btn btn-outline-danger float-end"><?= e(t('common.delete')) ?></button>
    <?php endif; ?>
  </div></div>
</form>
<?php foreach ($attachments as $a): ?>
  <form id="del_<?= (int)$a['attach_id'] ?>" method="post" action="<?= e(url('/logs/' . $rid . '/attachments/' . (int)$a['attach_id'] . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>">
    <?= csrf_field() ?>
  </form>
<?php endforeach; ?>
<?php if ($editing): ?>
  <form id="del_log" method="post" action="<?= e(url('/logs/' . $rid . '/delete')) ?>" data-confirm="<?= e(t('worklog.confirm_delete')) ?>">
    <?= csrf_field() ?>
  </form>
<?php endif; ?>
<script src="<?= e(asset('vendor/quill/quill.js')) ?>"></script>
<script src="<?= e(asset('js/request.js')) ?>"></script>
