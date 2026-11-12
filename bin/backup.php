<?php
declare(strict_types=1);

// DB 스냅샷 + 첨부 증분 복사 + 보관기간 정리. 1일 1회 스케줄러 등록 권장: php bin/backup.php
require __DIR__ . '/../app/bootstrap.php';

use App\Services\Backup;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

try {
    $r = Backup::run();
} catch (Throwable $e) {
    fwrite(STDERR, 'BACKUP FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "db        : {$r['db']}\n";
echo "attachments copied: {$r['attachments']}\n";
echo "old snapshots removed: {$r['removed']}\n";
