<?php
/**
 * @var list<array<string,mixed>> $rows
 * @var array<string,int> $counts
 * @var string $status
 * @var int $page
 * @var int $pages
 * @var string $driver
 */
$badge = ['QUEUED' => 'warning', 'SENDING' => 'info', 'SENT' => 'success', 'FAILED' => 'danger'];
$q = static fn(array $o): string => url('/admin/mail-log') . '?' . http_build_query(array_filter($o, static fn($v): bool => $v !== '' && $v !== 1));
?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h2 class="h4 mb-0"><?= e(t('maillog.title')) ?></h2>
  <span class="small text-muted"><?= e(t('maillog.driver')) ?>: <strong><?= e(t('maillog.driver.' . $driver)) ?></strong></span>
</div>
<?php if ($driver === 'off'): ?><div class="alert alert-warning py-2 small"><?= e(t('maillog.off_warn')) ?></div><?php endif; ?>
<div class="mb-2">
  <a class="btn btn-sm <?= $status === '' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= e($q([])) ?>"><?= e(t('maillog.all')) ?></a>
  <?php foreach ($counts as $st => $n): ?>
    <a class="btn btn-sm <?= $status === $st ? 'btn-' . $badge[$st] : 'btn-outline-' . $badge[$st] ?>" href="<?= e($q(['status' => $st])) ?>"><?= e(t('mlst.' . $st)) ?> <?= (int)$n ?></a>
  <?php endforeach; ?>
</div>
<div class="table-responsive">
<table class="table table-sm small align-middle tbl-grid">
  <thead><tr><th><?= e(t('maillog.time')) ?></th><th><?= e(t('maillog.to')) ?></th><th><?= e(t('maillog.request')) ?></th><th><?= e(t('maillog.subject')) ?></th>
    <th><?= e(t('maillog.template')) ?></th><th><?= e(t('request.status')) ?></th><th><?= e(t('maillog.attempts')) ?></th><th><?= e(t('common.manage')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr<?= $r['status'] === 'FAILED' ? ' class="row-late"' : '' ?>>
      <td class="text-nowrap text-center"><?= e(fmt_date((string)$r['created_at'])) ?></td>
      <td><?= e($r['to_email']) ?> <span class="badge bg-body-secondary text-body border"><?= e($r['rcpt_kind']) ?></span></td>
      <td><?php if ($r['request_id'] !== null): ?><a href="<?= e(url('/requests/' . (int)$r['request_id'])) ?>"><?= e($r['request_no']) ?></a><?php endif; ?></td>
      <td><?= e($r['subject']) ?></td>
      <td><code><?= e($r['tmpl_cd']) ?></code> <?= e($r['lang']) ?></td>
      <td><span class="badge text-bg-<?= e($badge[$r['status']] ?? 'secondary') ?>"><?= e(t('mlst.' . $r['status'])) ?></span>
        <?php if ($r['err_msg'] !== null): ?><div class="text-danger"><?= e($r['err_msg']) ?></div><?php endif; ?>
        <?php if ($r['status'] === 'QUEUED' && $r['next_try_at'] !== null): ?><div class="text-muted"><?= e(t('maillog.next_try')) ?> <?= e(fmt_date((string)$r['next_try_at'])) ?></div><?php endif; ?></td>
      <td class="text-end"><?= (int)$r['attempts'] ?></td>
      <td class="text-center"><?php if ($r['status'] === 'FAILED'): ?>
        <form method="post" action="<?= e(url('/admin/mail-log/' . (int)$r['noti_id'] . '/requeue')) ?>"><?= csrf_field() ?>
          <button class="btn btn-sm btn-outline-primary"><?= e(t('maillog.requeue')) ?></button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="8" class="text-center text-muted"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav><ul class="pagination pagination-sm">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e($q(['status' => $status, 'page' => $i])) ?>"><?= $i ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>
