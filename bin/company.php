<?php
declare(strict_types=1);

// 회사 관리: php bin/company.php list
//            php bin/company.php add <코드> <회사명> --admin=ID --admin-name=이름 --admin-email=메일 [--lang=ko] [--password=...]

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Db;
use App\Core\Migrator;
use App\Services\CompanySetup;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

Migrator::run();
$cmd = $argv[1] ?? '';

if ($cmd === 'list') {
    foreach (Db::all('SELECT company_cd, company_nm, default_lang, use_yn FROM company ORDER BY company_cd') as $r) {
        printf("%-12s %-30s %s %s\n", $r['company_cd'], $r['company_nm'], $r['default_lang'], $r['use_yn'] ? '' : '(inactive)');
    }
    exit(0);
}

if ($cmd === 'add' && isset($argv[2], $argv[3])) {
    // getopt 는 위치 인자 뒤의 옵션을 읽지 못하므로 직접 파싱한다.
    $opt = [];
    foreach (array_slice($argv, 4) as $a) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) === 1) {
            $opt[$m[1]] = $m[2];
        }
    }
    foreach (['admin', 'admin-name', 'admin-email'] as $k) {
        if (empty($opt[$k]) || !is_string($opt[$k])) {
            fwrite(STDERR, "missing --$k\n");
            exit(1);
        }
    }
    try {
        $pw = CompanySetup::provision(
            $argv[2],
            $argv[3],
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
    echo "company {$argv[2]} created. admin: {$opt['admin']}\n";
    if (!isset($opt['password'])) {
        echo "password: $pw\n";
    }
    exit(0);
}

fwrite(STDERR, "usage: php bin/company.php list | add <code> <name> --admin=ID --admin-name=NAME --admin-email=MAIL\n");
exit(1);
