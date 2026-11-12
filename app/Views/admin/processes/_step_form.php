<?php
/**
 * 단계 입력 폼(추가/수정 공용).
 * @var array|null $s 수정 대상 단계(null 이면 추가)
 * @var array<string,string> $tr 단계명 번역 ja/en
 * @var string $action
 * @var list<array> $roles
 * @var list<array> $users
 */

use App\Services\ProcessService;

$val = static fn(string $k, string $d = ''): string => $s !== null && $s[$k] !== null ? (string)$s[$k] : $d;
$actorTypes = ProcessService::ACTOR_TYPES;
$curActor = $val('actor_type', 'ROLE');
$curVal = $val('actor_value');
?>
<form method="post" action="<?= e(url($action)) ?>" class="row g-2">
  <?= csrf_field() ?>
  <div class="col-md-3"><label class="form-label required"><?= e(t('process.step_name')) ?> (한국어)</label>
    <input class="form-control form-control-sm" name="step_nm" value="<?= e($val('step_nm')) ?>" maxlength="100" required></div>
  <?php foreach (\App\Services\Translations::LANGS as $l): ?>
    <div class="col-md-2"><label class="form-label"><?= e(lang_name($l)) ?></label>
      <input class="form-control form-control-sm" name="name_<?= e($l) ?>" value="<?= e($tr[$l] ?? '') ?>" maxlength="100"></div>
  <?php endforeach; ?>
  <div class="col-md-2"><label class="form-label"><?= e(t('process.step_type')) ?></label>
    <select class="form-select form-select-sm" name="step_type">
      <?php foreach (ProcessService::STEP_TYPES as $x): ?>
        <option value="<?= e($x) ?>"<?= $val('step_type', 'REVIEW') === $x ? ' selected' : '' ?>><?= e(t('ptype.' . $x)) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="col-md-3"><label class="form-label"><?= e(t('process.actor_type')) ?></label>
    <select class="form-select form-select-sm" name="actor_type">
      <?php foreach ($actorTypes as $x): ?>
        <option value="<?= e($x) ?>"<?= $curActor === $x ? ' selected' : '' ?>><?= e(t('actor.' . $x)) ?></option>
      <?php endforeach; ?>
    </select></div>

  <div class="col-md-3"><label class="form-label"><?= e(t('process.actor_role')) ?></label>
    <select class="form-select form-select-sm" name="actor_role">
      <?php foreach ($roles as $r): ?>
        <option value="<?= e($r['role_cd']) ?>"<?= $curActor === 'ROLE' && $curVal === $r['role_cd'] ? ' selected' : '' ?>><?= e(master_name('ROLE', (string)$r['role_cd'], (string)$r['role_nm'])) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="col-md-3"><label class="form-label"><?= e(t('process.actor_user')) ?></label>
    <select class="form-select form-select-sm" name="actor_user" data-filter data-filter-short="<?= e(t('common.search')) ?>" data-filter-ph="<?= e(t('common.filter_ph')) ?>" data-filter-empty="<?= e(t('common.empty')) ?>">
      <?= user_options($users, $curActor === 'USER' && ctype_digit((string)$curVal) ? (int)$curVal : null) ?>
    </select></div>
  <div class="col-md-2"><label class="form-label"><?= e(t('process.approval_mode')) ?></label>
    <select class="form-select form-select-sm" name="approval_mode">
      <?php foreach (ProcessService::APPROVAL_MODES as $x): ?>
        <option value="<?= e($x) ?>"<?= $val('approval_mode', 'ANY') === $x ? ' selected' : '' ?>><?= e(t('amode.' . $x)) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="col-md-2"><label class="form-label"><?= e(t('process.reject_to')) ?></label>
    <input class="form-control form-control-sm" type="number" min="1" name="reject_to_step" value="<?= e($val('reject_to_step')) ?>" placeholder="<?= e(t('process.reject_to_ph')) ?>"></div>
  <div class="col-md-2"><label class="form-label"><?= e(t('process.due_days')) ?></label>
    <input class="form-control form-control-sm" type="number" min="1" max="365" name="due_days" value="<?= e($val('due_days')) ?>"></div>

  <div class="col-md-2"><label class="form-label"><?= e(t('process.attach_mode')) ?></label>
    <select class="form-select form-select-sm" name="attach_mode">
      <?php foreach (ProcessService::ATTACH_MODES as $x): ?>
        <option value="<?= e($x) ?>"<?= $val('attach_mode', 'NONE') === $x ? ' selected' : '' ?>><?= e(t('attach.' . $x)) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="col-md-2"><label class="form-label"><?= e(t('process.attach_max_cnt')) ?></label>
    <input class="form-control form-control-sm" type="number" min="1" max="50" name="attach_max_cnt" value="<?= e($val('attach_max_cnt')) ?>"></div>
  <div class="col-md-2"><label class="form-label"><?= e(t('process.attach_max_mb')) ?></label>
    <input class="form-control form-control-sm" type="number" min="1" max="200" name="attach_max_mb" value="<?= e($val('attach_max_mb')) ?>"></div>
  <div class="col-md-3"><label class="form-label"><?= e(t('process.attach_exts')) ?></label>
    <input class="form-control form-control-sm" name="attach_exts" value="<?= e($val('attach_exts')) ?>" placeholder="pdf,xlsx,png"></div>
  <div class="col-md-3"><label class="form-label"><?= e(t('process.notify_tmpl')) ?></label>
    <input class="form-control form-control-sm" name="notify_tmpl" value="<?= e($val('notify_tmpl')) ?>" placeholder="STEP_ACTIVE" pattern="[A-Z][A-Z0-9_]{0,39}"></div>
  <div class="col-12">
    <div class="form-check form-check-inline">
      <input class="form-check-input" type="checkbox" name="allow_reject" value="1" id="ar_<?= e($s['step_id'] ?? 'new') ?>"<?= $val('allow_reject', '1') === '1' ? ' checked' : '' ?>>
      <label class="form-check-label" for="ar_<?= e($s['step_id'] ?? 'new') ?>"><?= e(t('process.allow_reject')) ?></label>
    </div>
    <button class="btn btn-sm btn-primary"><?= e(t($s === null ? 'common.add' : 'common.save')) ?></button>
    <span class="form-text ms-2"><?= e(t('process.attach_exts_hint')) ?></span>
  </div>
</form>
