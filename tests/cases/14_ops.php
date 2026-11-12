<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Env;
use App\Services\Backup;
use App\Services\Doctor;
use App\Services\MailService;
use App\Services\Mail\MemoryTransport;

function tmp_dir(): string
{
    $d = sys_get_temp_dir() . '/srms_ops_' . bin2hex(random_bytes(4));
    mkdir($d, 0775, true);
    return $d;
}

test('backup: 파일 DB 스냅샷(VACUUM INTO) 생성·검증·복원 가능, 첨부 증분 복사, 보관기간 정리', function () {
    $dir = tmp_dir();
    $dbFile = $dir . '/live.sqlite';
    Db::reset();
    Env::set('DB_PATH', $dbFile);
    Env::set('STORAGE_PATH', $dir . '/store');
    try {
        App\Core\Migrator::run();
        App\Services\CompanySetup::provision('HQ', 'Head', 'ko', 'admin', 'Admin', 'a@example.com', 'Admin1234');
        mkdir($dir . '/store/attachments/HQ/2026/11', 0775, true);
        file_put_contents($dir . '/store/attachments/HQ/2026/11/aaaa', 'hello');
        // 오래된 스냅샷은 정리, 최근 것은 유지
        mkdir($dir . '/bk', 0775, true);
        file_put_contents($dir . '/bk/srms-20200101-000000.sqlite', 'old');
        touch($dir . '/bk/srms-20200101-000000.sqlite', time() - 30 * 86400);
        file_put_contents($dir . '/bk/srms-20991231-000000.sqlite', 'recent');
        $r = Backup::run($dir . '/bk', 14);
        ok(is_file($r['db']));
        eq(1, $r['attachments']);
        eq(1, $r['removed']);
        ok(!is_file($dir . '/bk/srms-20200101-000000.sqlite'));
        ok(is_file($dir . '/bk/srms-20991231-000000.sqlite'), '최근 파일 유지');
        eq('hello', file_get_contents($dir . '/bk/attachments/HQ/2026/11/aaaa'));
        // 스냅샷을 열어 실제 데이터 확인(복원 가능성)
        $c = new PDO('sqlite:' . $r['db']);
        eq('HQ', $c->query('SELECT company_cd FROM company')->fetchColumn());
        eq('admin', $c->query('SELECT login_id FROM user')->fetchColumn());
        $c = null;
        // 증분: 변경 없으면 복사 0, 새 파일만 복사
        usleep(1_100_000);
        file_put_contents($dir . '/store/attachments/HQ/2026/11/bbbb', 'world!');
        $r2 = Backup::run($dir . '/bk', 14);
        eq(1, $r2['attachments']);
        ok($r2['db'] !== $r['db']);
        ok(Backup::lastRun($dir . '/bk') !== null);
        // 손상된 스냅샷 검증 실패
        file_put_contents($dir . '/bad.sqlite', str_repeat('x', 200));
        throws(fn() => Backup::verify($dir . '/bad.sqlite'), RuntimeException::class);
        ok(!is_file($dir . '/bad.sqlite'), '검증 실패 파일은 삭제');
        // 쓸 수 없는 경로
        throws(fn() => Backup::run($dir . '/live.sqlite/x', 14), RuntimeException::class);
    } finally {
        Db::reset();
        Env::set('DB_PATH', ':memory:');
        Env::set('STORAGE_PATH', 'storage');
        fresh();
        rm_rf($dir);
    }
});

test('doctor: 점검 항목(정상/미적용 마이그레이션/잘못된 SINGLE_COMPANY)', function () {
    fresh();
    seed_two_companies();
    $dir = tmp_dir();
    Env::set('STORAGE_PATH', $dir);
    try {
        // 메모리 DB 는 파일이 없으므로 DB 항목은 실패로 보고된다
        $names = array_column(Doctor::checks(), 'level', 'name');
        ok(in_array('fail', $names, true) || isset($names['DB']));
    } finally {
        Env::set('STORAGE_PATH', 'storage');
        rm_rf($dir);
    }
    // 파일 DB 에서의 점검
    $dir = tmp_dir();
    Db::reset();
    Env::set('DB_PATH', $dir . '/d.sqlite');
    Env::set('STORAGE_PATH', $dir . '/store');
    try {
        $before = Doctor::checks();
        eq('fail', array_column($before, 'level', 'name')['DB']);
        App\Core\Migrator::run();
        App\Services\CompanySetup::provision('HQ', 'Head', 'ko', 'admin', 'Admin', 'a@example.com', 'Admin1234');
        $c = array_column(Doctor::checks(), null, 'name');
        eq('ok', $c['마이그레이션']['level']);
        eq('ok', $c['회사']['level']);
        eq('ok', $c['SQLite ' . Db::val('SELECT sqlite_version()')]['level']);
        eq('warn', $c['백업']['level'], '백업 기록 없음');
        eq('warn', $c['주기 작업(cron)']['level']);
        ok(!Doctor::hasFailure(Doctor::checks()));
        Env::set('SINGLE_COMPANY', 'NOPE');
        $c = array_column(Doctor::checks(), null, 'name');
        eq('fail', $c['SINGLE_COMPANY']['level']);
        Env::set('SINGLE_COMPANY', 'HQ');
        eq('ok', array_column(Doctor::checks(), null, 'name')['SINGLE_COMPANY']['level']);
        Env::set('SINGLE_COMPANY', '');
        Db::exec('DELETE FROM schema_migration WHERE version = :v', ['v' => '005_mail_queue.sql']);
        $c = array_column(Doctor::checks(), null, 'name');
        eq('fail', $c['마이그레이션']['level']);
        ok(str_contains($c['마이그레이션']['detail'], '005_mail_queue.sql'));
        Backup::run($dir . '/store/backup', 14, false);
        eq('ok', array_column(Doctor::checks(), null, 'name')['백업']['level']);
    } finally {
        Db::reset();
        Env::set('DB_PATH', ':memory:');
        Env::set('STORAGE_PATH', 'storage');
        Env::set('SINGLE_COMPANY', '');
        fresh();
        rm_rf($dir);
    }
});

test('mailtest: 현재 발송 방식으로 즉시 전송, off 면 오류', function () {
    $tr = new MemoryTransport();
    MailService::setTransport($tr);
    try {
        MailService::testSend('to@example.com');
        eq('to@example.com', $tr->sent[0]['to']);
        ok(str_contains($tr->sent[0]['subject'], 'test mail'));
    } finally {
        MailService::setTransport(null);
    }
    Env::set('MAIL_DRIVER', 'off');
    try {
        throws(fn() => MailService::testSend('to@example.com'), RuntimeException::class);
    } finally {
        Env::set('MAIL_DRIVER', '');
    }
});

test('cdn: 화면/스크립트/스타일에 외부 URL 로드가 없다(CDN 금지)', function () {
    $files = [];
    foreach (['/app/Views', '/public/assets/js', '/public/assets/css', '/public/assets/vendor'] as $d) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . $d, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (in_array($f->getExtension(), ['php', 'css'], true) || ($f->getExtension() === 'js' && !str_contains($f->getPathname(), 'vendor'))) {
                $files[] = $f->getPathname();
            }
        }
    }
    ok(count($files) > 20);
    $hits = [];
    foreach ($files as $f) {
        $c = (string)file_get_contents($f);
        if (preg_match('~(?:src|href)\s*=\s*["\']?(?:https?:)?//|url\(\s*["\']?(?:https?:)?//|@import\s+["\']?https?:~i', $c) === 1) {
            $hits[] = basename($f);
        }
    }
    eq([], $hits, '외부 URL 을 참조하는 파일');
});
