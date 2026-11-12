<?php
/**
 * @var list<array> $services
 * @var list<array> $types
 * @var list<array> $processes
 * @var array<string,int> $map
 */
?>
<h2 class="h4 mb-3"><?= e(t('map.title')) ?></h2>
<p class="text-muted"><?= e(t('map.hint')) ?></p>
<?php if ($services === [] || $types === []): ?>
  <div class="alert alert-info"><?= e(t('map.empty')) ?></div>
<?php else: ?>
<form method="post" action="<?= e(url('/admin/process-map')) ?>">
  <?= csrf_field() ?>
  <div class="table-responsive">
  <table class="table table-sm align-middle tbl-grid">
    <thead><tr><th><?= e(t('map.service')) ?></th>
      <?php foreach ($types as $t): ?><th><?= e(master_name('CODE', 'MAINT_TYPE:' . $t['code_cd'], (string)$t['code_nm'])) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($services as $s): $sid = (int)$s['service_id']; ?>
      <tr>
        <td><code><?= e($s['service_cd']) ?></code> <?= e(master_name('SERVICE', (string)$s['service_cd'], (string)$s['service_nm'])) ?></td>
        <?php foreach ($types as $t): $cur = $map[$sid . '|' . $t['code_cd']] ?? 0; ?>
          <td>
            <select class="form-select form-select-sm" name="map[<?= $sid ?>][<?= e($t['code_cd']) ?>]">
              <option value=""><?= e(t('map.none')) ?></option>
              <?php foreach ($processes as $p): ?>
                <option value="<?= (int)$p['process_id'] ?>"<?= $cur === (int)$p['process_id'] ? ' selected' : '' ?>><?= e(master_name('PROCESS', (string)$p['process_cd'], (string)$p['process_nm'])) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
</form>
<?php endif; ?>
