<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use PDO;

/** 운영 백업: SQLite 일관 스냅샷(VACUUM INTO) + 첨부 증분 복사 + 보관기간 정리. */
final class Backup
{
    public static function dir(): string
    {
        $p = (string)env('BACKUP_PATH', '');
        if ($p === '') {
            return AttachmentService::baseDir() . '/backup';
        }
        return preg_match('~^([A-Za-z]:[\\\\/]|/)~', $p) === 1 ? $p : APP_ROOT . '/' . $p;
    }

    public static function keepDays(): int
    {
        return max(1, (int)env('BACKUP_KEEP_DAYS', '14'));
    }

    /**
     * @return array{db:string,attachments:int,removed:int}
     * @throws \RuntimeException 스냅샷 생성/검증 실패
     */
    public static function run(?string $dir = null, ?int $keepDays = null, bool $withAttachments = true): array
    {
        $dir ??= self::dir();
        $keepDays ??= self::keepDays();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('backup directory is not writable: ' . $dir);
        }
        $target = rtrim($dir, '/\\') . '/srms-' . date('Ymd-His') . '.sqlite';
        if (file_exists($target)) {
            throw new \RuntimeException('backup file already exists: ' . $target);
        }
        $pdo = Db::pdo();
        $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
        self::verify($target);

        $copied = $withAttachments ? self::mirrorAttachments(AttachmentService::baseDir() . '/attachments', rtrim($dir, '/\\') . '/attachments') : 0;
        return ['db' => $target, 'attachments' => $copied, 'removed' => self::prune($dir, $keepDays, $target)];
    }

    /** 스냅샷을 열어 무결성과 핵심 테이블 존재를 확인한다. 실패하면 파일을 지운다. */
    public static function verify(string $file): void
    {
        $c = null;
        try {
            $c = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $ok = (string)$c->query('PRAGMA integrity_check')->fetchColumn() === 'ok'
                && $c->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'schema_migration'")->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $ok = false;
        }
        $c = null;
        if (!$ok) {
            @unlink($file);
            throw new \RuntimeException('backup verification failed: ' . $file);
        }
    }

    /** 새로 생겼거나 크기가 달라진 첨부만 복사한다(원본은 지우지 않으므로 삭제된 첨부도 보관기간 동안 복구 가능). */
    private static function mirrorAttachments(string $src, string $dst): int
    {
        if (!is_dir($src)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = substr($f->getPathname(), strlen($src) + 1);
            $to = $dst . '/' . str_replace('\\', '/', $rel);
            if (is_file($to) && filesize($to) === $f->getSize()) {
                continue;
            }
            if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0750, true) && !is_dir(dirname($to))) {
                throw new \RuntimeException('cannot create ' . dirname($to));
            }
            if (!copy($f->getPathname(), $to)) {
                throw new \RuntimeException('cannot copy attachment ' . $rel);
            }
            $n++;
        }
        return $n;
    }

    /** 보관기간이 지난 DB 스냅샷 삭제(방금 만든 것은 유지). */
    private static function prune(string $dir, int $keepDays, string $keep): int
    {
        $removed = 0;
        $limit = time() - $keepDays * 86400;
        foreach (glob(rtrim($dir, '/\\') . '/srms-*.sqlite') ?: [] as $f) {
            if ($f !== $keep && filemtime($f) < $limit && @unlink($f)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** 가장 최근 스냅샷의 수정 시각(없으면 null). */
    public static function lastRun(?string $dir = null): ?int
    {
        $latest = null;
        foreach (glob(rtrim($dir ?? self::dir(), '/\\') . '/srms-*.sqlite') ?: [] as $f) {
            $latest = max($latest ?? 0, (int)filemtime($f));
        }
        return $latest;
    }
}
