<?php
/** @var list<array> $roles */
/** @var list<string> $perms */
/** @var array<string,array<string,bool>> $granted */
/** @var array<string,array<string,string>> $names */
/** @var array<string,int> $used */
/** @var string $locked */
/** @var list<string> $systemRoles */
?>
<h2 class="h4 mb-3"><?= e(t('role.title')) ?></h2>
<form method="post" action="<?= e(url('/admin/roles/save')) ?>">
  <?= csrf_field() ?>
  <div class="card mb-3"><div class="card-body">
    <h3 class="h6"><?= e(t('role.names')) ?></h3>
    <table class="table table-sm align-middle tbl-grid">
      <thead><tr><th><?= e(t('role.code')) ?></th><?php foreach (\App\Core\I18n::SUPPORTED as $l): ?><th><?= e(lang_name($l)) ?></th><?php endforeach; ?><th><?= e(t('role.users')) ?></th><th><?= e(t('common.manage')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($roles as $r): $cd = (string)$r['role_cd']; ?>
        <tr>
          <td><code><?= e($cd) ?></code></td>
          <?php foreach (\App\Core\I18n::SUPPORTED as $l): ?>
            <td><input class="form-control form-control-sm" name="name[<?= e($cd) ?>][<?= e($l) ?>]" value="<?= e($names[$cd][$l] ?? '') ?>" maxlength="100"<?= $l === 'ko' ? ' required' : '' ?>></td>
          <?php endforeach; ?>
          <td class="text-end"><?= (int)($used[$cd] ?? 0) ?></td>
          <td class="text-center">
            <?php if (!in_array($cd, $systemRoles, true) && (int)($used[$cd] ?? 0) === 0): ?>
              <button class="btn btn-sm btn-outline-danger" type="submit" formaction="<?= e(url('/admin/roles/' . rawurlencode($cd) . '/delete')) ?>" data-confirm="<?= e(t('common.confirm_delete')) ?>"><?= e(t('common.delete')) ?></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

  <div class="card mb-3"><div class="card-body">
    <h3 class="h6"><?= e(t('role.perm_matrix')) ?></h3>
    <div class="table-responsive tbl-scroll">
    <table class="table table-sm align-middle text-center tbl-grid">
      <thead><tr><th><?= e(t('role.permission')) ?></th>
        <?php foreach ($roles as $r): ?><th><?= e(master_name('ROLE', (string)$r['role_cd'], (string)$r['role_nm'])) ?><div class="small fw-normal text-muted"><code><?= e($r['role_cd']) ?></code></div></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php
      $groupOf = static fn(string $p): string => match (true) {
          str_starts_with($p, 'REQUEST_') => 'request',
          in_array($p, ['STEP_ACT', 'SCHEDULE_PROPOSE', 'WORKLOG_REG'], true) => 'process',
          $p === 'REPORT_VIEW' => 'report',
          str_ends_with($p, '_EDIT') => 'admin',
          default => 'etc',
      };
      $grouped = [];
      foreach ($perms as $p) {
          $grouped[$groupOf($p)][] = $p;
      }
      foreach (['request', 'process', 'report', 'admin', 'etc'] as $gk):
          if (empty($grouped[$gk])) { continue; }
      ?>
        <tr class="tbl-group"><td colspan="<?= count($roles) + 1 ?>"><?= e(t('role.grp.' . $gk)) ?></td></tr>
        <?php foreach ($grouped[$gk] as $p): ?>
        <tr>
          <td class="text-start"><?= e(t('perm.' . $p)) ?> <small class="text-muted"><code><?= e($p) ?></code></small></td>
          <?php foreach ($roles as $r): $cd = (string)$r['role_cd']; $isLocked = $cd === $locked; ?>
            <td><input class="form-check-input" type="checkbox" name="perm[<?= e($cd) ?>][<?= e($p) ?>]" value="1"
              <?= $isLocked || !empty($granted[$cd][$p]) ? 'checked' : '' ?> <?= $isLocked ? 'disabled' : '' ?>></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="form-text mb-3"><?= e(t('role.locked_admin')) ?></div>
    <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </div></div>
</form>

<div class="card"><div class="card-body">
  <h3 class="h6"><?= e(t('role.new')) ?></h3>
  <form class="row g-2" method="post" action="<?= e(url('/admin/roles')) ?>">
    <?= csrf_field() ?>
    <div class="col-md-3"><input class="form-control" name="role_cd" placeholder="ROLE_CODE *" pattern="[A-Za-z][A-Za-z0-9_]{1,29}" required></div>
    <div class="col-md-4"><input class="form-control" name="role_nm" placeholder="<?= e(t('role.names')) ?> *" maxlength="100" required></div>
    <div class="col-auto"><button class="btn btn-outline-primary"><?= e(t('common.add')) ?></button></div>
  </form>
</div></div>
