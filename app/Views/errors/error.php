<?php
/** @var int $status */
/** @var string $detail */
$key = in_array($status, [403, 404, 405], true) ? 'err.' . $status : 'err.500';
?>
<div class="text-center py-5">
  <h1 class="display-4"><?= e($status) ?></h1>
  <p class="lead"><?= e(t($key)) ?></p>
  <?php if ($detail !== ''): ?><pre class="text-start small text-muted"><?= e($detail) ?></pre><?php endif; ?>
  <a href="<?= e(url('/')) ?>" class="btn btn-primary"><?= e(t('common.home')) ?></a>
</div>
