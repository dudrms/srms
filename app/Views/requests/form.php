<?php
/**
 * @var array|null $req
 * @var array $old
 * @var list<array> $services
 * @var list<array> $types
 * @var list<array> $priorities
 * @var list<array> $attachments
 * @var string $today
 */

$editing = $req !== null;
$returned = $editing && $req['status'] === 'RETURNED';
$action = $editing ? '/requests/' . (int)$req['request_id'] : '/requests';
$v = static fn(string $k): string => (string)($old[$k] ?? '');
?>
<link rel="stylesheet" href="<?= e(asset('vendor/quill/quill.snow.css')) ?>">
<h2 class="h4 mb-3"><?= e(t($editing ? 'request.edit' : 'request.new')) ?>
  <?php if ($editing): ?><small class="text-muted fs-6"><?= e($req['request_no']) ?></small><?php endif; ?></h2>
<?php if ($returned): ?><div class="alert alert-warning py-2"><?= e(t('request.returned_notice')) ?></div><?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>" enctype="multipart/form-data" id="request-form">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3"><div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-md-4"><label class="form-label required"><?= e(t('request.service')) ?></label>
            <select class="form-select" name="service_id" id="service_id" required<?= $returned ? ' disabled' : '' ?>>
              <option value=""><?= e(t('common.select')) ?></option>
              <?php foreach ($services as $s): ?>
                <option value="<?= (int)$s['service_id'] ?>"<?= $v('service_id') === (string)$s['service_id'] ? ' selected' : '' ?>><?= e(master_name('SERVICE', (string)$s['service_cd'], (string)$s['service_nm'])) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label required"><?= e(t('request.type')) ?></label>
            <select class="form-select" name="maint_type" id="maint_type" required<?= $returned ? ' disabled' : '' ?>>
              <option value=""><?= e(t('common.select')) ?></option>
              <?php foreach ($types as $t): ?>
                <option value="<?= e($t['code_cd']) ?>"<?= $v('maint_type') === $t['code_cd'] ? ' selected' : '' ?>><?= e(master_name('CODE', 'MAINT_TYPE:' . $t['code_cd'], (string)$t['code_nm'])) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><label class="form-label"><?= e(t('request.priority')) ?></label>
            <select class="form-select" name="priority">
              <?php foreach ($priorities as $p): ?>
                <option value="<?= e($p['code_cd']) ?>"<?= ($v('priority') ?: 'NORMAL') === $p['code_cd'] ? ' selected' : '' ?>><?= e(master_name('CODE', 'PRIORITY:' . $p['code_cd'], (string)$p['code_nm'])) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="mb-3"><label class="form-label required"><?= e(t('request.title')) ?></label>
          <input class="form-control" name="title" value="<?= e($v('title')) ?>" maxlength="200" required></div>
        <div class="mb-3"><label class="form-label required"><?= e(t('request.due')) ?></label>
          <input class="form-control" style="max-width: 220px;" type="date" name="req_due_date" value="<?= e($v('req_due_date')) ?>"<?= $returned ? '' : ' min="' . e($today) . '"' ?> required>
          <div class="form-text"><?= e(t('request.due_hint')) ?></div></div>
        <div class="mb-3"><label class="form-label"><?= e(t('request.content')) ?></label>
          <div id="editor" style="min-height: 220px;"></div>
          <textarea class="d-none" name="content_html" id="content_html"><?= e($v('content_html')) ?></textarea></div>
        <div class="mb-3"><label class="form-label"><i class="bi bi-paperclip"></i> <?= e(t('request.attachments')) ?></label>
          <input class="form-control" type="file" name="files[]" multiple data-file-list data-file-remove="<?= e(t('common.delete')) ?>" data-file-summary="<?= e(t('common.files_summary')) ?>">
          <div class="form-text"><?= e(t('request.attach_hint', ['mb' => \App\Services\AttachmentService::DEFAULT_MAX_MB])) ?></div>
          <?php foreach ($attachments as $a): if ((int)$a['step_no'] !== 1) { continue; } ?>
            <div class="d-flex align-items-center gap-2 small mt-1">
              <i class="bi bi-file-earmark"></i> <a href="<?= e(url('/attachments/' . (int)$a['attach_id'])) ?>"><?= e($a['orig_name']) ?></a>
              <span class="text-muted"><?= e(fmt_size((int)$a['size_byte'])) ?></span>
              <button form="del_<?= (int)$a['attach_id'] ?>" class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
            </div>
          <?php endforeach; ?></div>
        <button class="btn btn-outline-secondary" name="submit" value="0"><?= e(t($returned ? 'request.save' : 'request.save_draft')) ?></button>
        <button class="btn btn-primary" name="submit" value="1"><?= e(t($returned ? 'request.resubmit' : 'request.submit')) ?></button>
        <a class="btn btn-link" href="<?= e(url($editing ? '/requests/' . (int)$req['request_id'] : '/requests')) ?>"><?= e(t('common.cancel')) ?></a>
      </div></div>
    </div>
    <div class="col-lg-4">
      <div class="card"><div class="card-body" id="flow-preview"
           data-url="<?= e(url('/requests/preview')) ?>" data-empty="<?= e(t('request.preview_pick')) ?>">
        <h3 class="h6"><?= e(t('request.flow')) ?></h3>
        <div id="flow-body" class="small text-muted"><?= e(t('request.preview_pick')) ?></div>
      </div></div>
    </div>
  </div>
</form>
<?php foreach ($attachments as $a): if ((int)$a['step_no'] !== 1) { continue; } ?>
  <form id="del_<?= (int)$a['attach_id'] ?>" method="post" action="<?= e(url('/requests/' . (int)$req['request_id'] . '/attachments/' . (int)$a['attach_id'] . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>">
    <?= csrf_field() ?>
  </form>
<?php endforeach; ?>
<script src="<?= e(asset('vendor/quill/quill.js')) ?>"></script>
<script src="<?= e(asset('js/request.js')) ?>"></script>
