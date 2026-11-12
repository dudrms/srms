<?php
declare(strict_types=1);

// SMTP 설정 확인용 테스트 메일(대기열을 거치지 않고 바로 전송): php bin/mailtest.php 받는사람@회사.com
require __DIR__ . '/../app/bootstrap.php';

use App\Services\MailService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$to = (string)($argv[1] ?? '');
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "usage: php bin/mailtest.php recipient@example.com\n");
    exit(1);
}
try {
    echo 'driver: ' . MailService::driver() . "\n";
    MailService::testSend($to);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "sent to $to\n";
