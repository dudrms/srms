<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;

/** 설치/운영 점검(`php bin/doctor.php`). 각 항목은 ok / warn / fail. */
final class Doctor
{
    private const EXTS = ['pdo_sqlite', 'sqlite3', 'mbstring', 'fileinfo', 'openssl', 'ctype', 'filter', 'hash'];

    /** @return list<array{level:string,name:string,detail:string}> */
    public static function checks(): array
    {
        $out = [];
        $add = static function (string $level, string $name, string $detail = '') use (&$out): void {
            $out[] = ['level' => $level, 'name' => $name, 'detail' => $detail];
        };

        $add(PHP_VERSION_ID >= 80500 ? 'ok' : 'warn', 'PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80500 ? '' : '개발·검증은 8.5.x 에서 수행했습니다');
        foreach (self::EXTS as $e) {
            $add(extension_loaded($e) ? 'ok' : 'fail', 'ext ' . $e, extension_loaded($e) ? '' : '필수 확장이 없습니다');
        }
        $add(extension_loaded('intl') ? 'ok' : 'warn', 'ext intl', extension_loaded('intl') ? '' : '선택 확장(없어도 동작)');
        $add(ini_get('expose_php') ? 'warn' : 'ok', 'expose_php', ini_get('expose_php') ? 'php.ini 에서 expose_php=Off 로 설정하세요' : 'Off');

        $env = is_file(APP_ROOT . '/.env');
        $add($env ? 'ok' : 'warn', '.env', $env ? '' : '.env 가 없습니다(.env.example 을 복사해 입력)');
        $prod = (string)env('APP_ENV', 'production') === 'production';
        $url = (string)env('APP_URL', '');
        $add(!$prod || (str_starts_with($url, 'https://') && !str_contains($url, 'localhost')) ? 'ok' : 'warn', 'APP_URL', $url . ($prod ? ' (운영은 https 주소를 입력)' : ''));

        $dbFile = Db::path();
        foreach ([dirname($dbFile), AttachmentService::baseDir() . '/attachments', AttachmentService::baseDir() . '/logs', Backup::dir()] as $d) {
            $ok = (is_dir($d) || @mkdir($d, 0750, true)) && is_writable($d);
            $add($ok ? 'ok' : 'fail', '쓰기 권한 ' . $d, $ok ? '' : '웹서버/PHP 실행 사용자에게 쓰기 권한이 필요합니다');
        }

        if (!is_file($dbFile)) {
            $add('fail', 'DB', '파일이 없습니다: php bin/install.php 를 실행하세요');
            return $out;
        }
        try {
            $v = (string)Db::val('SELECT sqlite_version()');
            $add(version_compare($v, '3.35.0', '>=') ? 'ok' : 'fail', 'SQLite ' . $v, '3.35 이상 필요');
            $mode = strtolower((string)Db::val('PRAGMA journal_mode'));
            $add($mode === 'wal' ? 'ok' : 'warn', 'journal_mode', $mode);
            $applied = array_column(Db::all('SELECT version FROM schema_migration'), 'version');
            $files = array_map('basename', glob(APP_ROOT . '/database/migrations/*.sql') ?: []);
            $pending = array_values(array_diff($files, $applied));
            $add($pending === [] ? 'ok' : 'fail', '마이그레이션', $pending === [] ? count($files) . '개 적용됨' : '미적용: ' . implode(', ', $pending) . ' → php bin/migrate.php');
            $companies = Db::all('SELECT company_cd FROM company WHERE use_yn = 1');
            $add($companies !== [] ? 'ok' : 'fail', '회사', $companies === [] ? '활성 회사가 없습니다' : count($companies) . '개');
            $fixed = Auth::fixedCompany();
            if ($fixed !== null) {
                $exists = in_array($fixed, array_column($companies, 'company_cd'), true);
                $add($exists ? 'ok' : 'fail', 'SINGLE_COMPANY', $fixed . ($exists ? '' : ' (활성 회사에 없음 - 아무도 로그인할 수 없습니다)'));
            }
            if ($pending === []) {
                $miss = Db::all(
                    'SELECT c.company_cd, s.service_cd FROM service s JOIN company c ON c.company_cd = s.company_cd AND c.use_yn = 1
                      WHERE s.use_yn = 1 AND s.biz_dept_id IS NOT NULL AND NOT EXISTS (
                            SELECT 1 FROM service_biz_it b
                              JOIN user u ON u.company_cd = b.company_cd AND u.user_id = b.user_id AND u.use_yn = 1 AND u.dept_id = s.biz_dept_id
                              JOIN user_role r ON r.company_cd = u.company_cd AND r.user_id = u.user_id AND r.role_cd = \'BIZ_IT\'
                             WHERE b.company_cd = s.company_cd AND b.service_id = s.service_id)
                      ORDER BY c.company_cd, s.service_cd'
                );
                $names = array_map(static fn(array $r): string => $r['company_cd'] . '/' . $r['service_cd'], array_slice($miss, 0, 5));
                $add($miss === [] ? 'ok' : 'warn', '현업 IT 담당자', $miss === []
                    ? '주관부서가 있는 서비스는 모두 지정됨'
                    : count($miss) . '개 서비스에 유효한 지정자가 없어 주관부서 현업 전산담당 전원이 처리합니다: ' . implode(', ', $names) . (count($miss) > 5 ? ' 외' : ''));
            }
        } catch (\Throwable $e) {
            $add('fail', 'DB 접근', $e->getMessage());
            return $out;
        }

        $mode = Auth::mode();
        $add('ok', 'AUTH_MODE', $mode . ($mode === 'LOCAL' ? '' : ' (SSO 어댑터 구현 전에는 SSO 로그인이 거부됩니다)'));
        $driver = MailService::driver();
        $smtp = (string)env('SMTP_HOST', '') !== '' && (string)env('MAIL_FROM', '') !== '';
        $add($driver === 'smtp' && $smtp ? 'ok' : 'warn', '메일 발송', $driver . ($driver === 'smtp' && !$smtp ? ' (SMTP_HOST/MAIL_FROM 미설정)' : '') . ($driver === 'off' ? ' - 메일이 대기열에만 쌓입니다' : ''));

        $cron = AttachmentService::baseDir() . '/logs/cron.last';
        $age = is_file($cron) ? time() - (int)filemtime($cron) : null;
        $add($age !== null && $age < 3 * 3600 ? 'ok' : 'warn', '주기 작업(cron)', $age === null ? '아직 실행 기록이 없습니다: php bin/cron.php 를 스케줄러에 등록' : intdiv($age, 60) . '분 전 실행');
        $last = Backup::lastRun();
        $add($last !== null && time() - $last < 2 * 86400 ? 'ok' : 'warn', '백업', $last === null ? '백업 기록이 없습니다: php bin/backup.php 를 스케줄러에 등록' : date('Y-m-d H:i', $last));
        return $out;
    }

    public static function hasFailure(array $checks): bool
    {
        return in_array('fail', array_column($checks, 'level'), true);
    }
}
