<?php
declare(strict_types=1);

// 설치/운영 점검: php bin/doctor.php  (실패 항목이 있으면 종료 코드 1)
require __DIR__ . '/../app/bootstrap.php';

use App\Services\Doctor;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$checks = Doctor::checks();
$mark = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]'];
foreach ($checks as $c) {
    echo $mark[$c['level']] . ' ' . $c['name'] . ($c['detail'] !== '' ? ' - ' . $c['detail'] : '') . "\n";
}
exit(Doctor::hasFailure($checks) ? 1 : 0);
