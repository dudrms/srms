<?php
declare(strict_types=1);

// 주기 작업(1시간 권장): 기한/회신 독촉 적재 + 발송 대기 건 전송(실패 건 재시도). 모든 활성 회사를 순회한다.
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Db;
use App\Core\Scope;
use App\Services\MailService;
use App\Services\Reminders;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$base = App\Services\AttachmentService::baseDir();
if (!is_dir($base)) {
    @mkdir($base, 0750, true);
}
$lock = @fopen($base . '/cron.lock', 'c');
if ($lock === false) {
    fwrite(STDERR, "storage is not writable\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "cron is already running\n");
    exit(0);
}

foreach (Db::all('SELECT company_cd FROM company WHERE use_yn = 1 ORDER BY company_cd') as $c) {
    $cd = (string)$c['company_cd'];
    Scope::set($cd);
    try {
        $r = Reminders::run();
        $m = MailService::flush(500);
        echo sprintf(
            "%s: reminders soon=%d over=%d schedule=%d / mail sent=%d retry=%d failed=%d\n",
            $cd, $r['soon'], $r['over'], $r['schedule'], $m['sent'], $m['retry'], $m['failed']
        );
    } catch (Throwable $e) {
        fwrite(STDERR, $cd . ': ' . $e->getMessage() . "\n");
    }
}
Scope::set(null);

$logDir = App\Services\AttachmentService::baseDir() . '/logs';
if (is_dir($logDir) || @mkdir($logDir, 0750, true)) {
    @file_put_contents($logDir . '/cron.last', date('c') . "\n");
}
