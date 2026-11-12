<?php
declare(strict_types=1);

// 최초 설치: php bin/install.php --company=HQ --company-name="본사" --admin=admin --admin-name="관리자" --admin-email=a@x.com [--lang=ko] [--password=...]

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Db;
use App\Core\Migrator;
use App\Services\CompanySetup;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$opt = getopt('', ['company:', 'company-name:', 'admin:', 'admin-name:', 'admin-email:', 'lang::', 'password::']);
foreach (['company', 'company-name', 'admin', 'admin-name', 'admin-email'] as $k) {
    if (empty($opt[$k]) || !is_string($opt[$k])) {
        fwrite(STDERR, "missing --$k\n");
        exit(1);
    }
}

$applied = Migrator::run();
echo 'DB: ' . Db::path() . "\n";
echo 'migrations applied: ' . ($applied === [] ? '(none)' : implode(', ', $applied)) . "\n";

try {
    $pw = CompanySetup::provision(
        $opt['company'],
        $opt['company-name'],
        is_string($opt['lang'] ?? null) ? $opt['lang'] : 'ko',
        $opt['admin'],
        $opt['admin-name'],
        $opt['admin-email'],
        is_string($opt['password'] ?? null) ? $opt['password'] : null
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "company  : {$opt['company']}\n";
echo "admin id : {$opt['admin']}\n";
if (!isset($opt['password'])) {
    echo "password : $pw   (최초 로그인 후 변경하세요)\n";
}
