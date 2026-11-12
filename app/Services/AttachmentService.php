<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Repo;

/** 첨부파일 정책/저장/삭제. 저장소는 웹루트 밖이며 파일명은 난수로 저장한다. */
final class AttachmentService
{
    public const DEFAULT_EXTS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'log', 'md',
        'png', 'jpg', 'jpeg', 'gif', 'zip', '7z', 'hwp', 'hwpx',
    ];
    public const DEFAULT_MAX_MB = 20;
    public const DEFAULT_MAX_COUNT = 10;
    public const REQUEST_TOTAL_MB = 100;
    private const BLOCKED_MIME = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/x-php', 'application/x-httpd-php',
        'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib', 'application/x-msdownload',
        'application/x-msdos-program', 'application/x-sh', 'text/x-shellscript', 'application/javascript',
        'text/javascript', 'application/java-archive',
    ];

    public static function baseDir(): string
    {
        $p = (string)env('STORAGE_PATH', 'storage');
        return preg_match('~^([A-Za-z]:[\\\\/]|/)~', $p) === 1 ? $p : APP_ROOT . '/' . $p;
    }

    /** @return array{mode:string,maxCnt:int,maxMb:int,exts:list<string>} */
    public static function policy(array $step): array
    {
        $exts = $step['attach_exts'] === null || $step['attach_exts'] === '' ? self::DEFAULT_EXTS : explode(',', (string)$step['attach_exts']);
        return [
            'mode' => (string)$step['attach_mode'],
            'maxCnt' => $step['attach_max_cnt'] === null ? self::DEFAULT_MAX_COUNT : (int)$step['attach_max_cnt'],
            'maxMb' => $step['attach_max_mb'] === null ? self::DEFAULT_MAX_MB : (int)$step['attach_max_mb'],
            'exts' => array_values(array_diff($exts, ProcessService::BLOCKED_EXTS)),
        ];
    }

    /** 이 사용자가 지금 이 요청에 첨부할 수 있는 단계 번호(없으면 null). */
    public static function uploadStep(array $req, int $userId): ?int
    {
        $status = (string)$req['status'];
        if (WorklogService::isLog($req)) {
            return WorklogService::canManage($req, $userId) ? 1 : null;
        }
        if (in_array($status, ['DRAFT', 'RETURNED'], true)) {
            return (int)$req['requester_id'] === $userId ? 1 : null;
        }
        if ($status !== 'IN_PROGRESS' || $req['current_step_no'] === null) {
            return null;
        }
        $step = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => (int)$req['current_step_no']]);
        $svc = Repo::find('service', ['service_id' => (int)$req['service_id']]);
        if ($step === null || $svc === null || !ActorResolver::isActor($step, $req, $svc, $userId)) {
            return null;
        }
        return (int)$step['step_no'];
    }

    public static function ingest(array $req, int $stepNo, int $userId, string $tmpPath, string $origName): int
    {
        if (self::uploadStep($req, $userId) !== $stepNo) {
            throw new ProcessError('attach.err_forbidden');
        }
        $step = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => $stepNo])
            ?? throw new ProcessError('process.err_not_found');
        $pol = self::policy($step);
        if ($pol['mode'] === 'NONE') {
            throw new ProcessError('attach.err_not_allowed');
        }
        $name = self::cleanName($origName);
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $pol['exts'], true)) {
            throw new ProcessError('attach.err_ext', ['ext' => $ext === '' ? '-' : $ext]);
        }
        $size = is_file($tmpPath) ? (int)filesize($tmpPath) : 0;
        if ($size <= 0) {
            throw new ProcessError('attach.err_empty');
        }
        if ($size > $pol['maxMb'] * 1048576) {
            throw new ProcessError('attach.err_size', ['mb' => $pol['maxMb']]);
        }
        $rid = (int)$req['request_id'];
        if (Repo::count('attachment', ['request_id' => $rid, 'step_no' => $stepNo]) >= $pol['maxCnt']) {
            throw new ProcessError('attach.err_count', ['n' => $pol['maxCnt']]);
        }
        $total = (int)Repo::queryVal(
            'SELECT COALESCE(SUM(size_byte), 0) FROM attachment WHERE company_cd = :company_cd AND request_id = :r',
            ['r' => $rid]
        );
        if ($total + $size > self::REQUEST_TOTAL_MB * 1048576) {
            throw new ProcessError('attach.err_total', ['mb' => self::REQUEST_TOTAL_MB]);
        }
        $mime = self::sniff($tmpPath);
        if (in_array($mime, self::BLOCKED_MIME, true) || !self::mimeMatchesExt($ext, $mime)) {
            throw new ProcessError('attach.err_type');
        }

        $rel = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16));
        $dest = self::fullPath(\App\Core\Scope::cd(), $rel);
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
            throw new \RuntimeException('cannot create storage directory');
        }
        if (!copy($tmpPath, $dest)) {
            throw new \RuntimeException('cannot store attachment');
        }
        @unlink($tmpPath);
        return Repo::insert('attachment', [
            'request_id' => $rid, 'step_no' => $stepNo, 'orig_name' => $name, 'stored_name' => $rel,
            'mime_type' => $mime, 'size_byte' => $size, 'sha256' => hash_file('sha256', $dest),
            'uploaded_by' => $userId, 'uploaded_at' => now_str(),
        ]);
    }

    /** 업로더 본인이 업로드 가능한 상태(현재 단계)에서만 삭제할 수 있다. */
    public static function remove(array $req, int $attachId, int $userId): void
    {
        $a = Repo::find('attachment', ['attach_id' => $attachId, 'request_id' => (int)$req['request_id']])
            ?? throw new ProcessError('process.err_not_found');
        if (WorklogService::isLog($req)) {
            if (!WorklogService::canManage($req, $userId)) {
                throw new ProcessError('attach.err_forbidden');
            }
        } elseif ((int)$a['uploaded_by'] !== $userId || self::uploadStep($req, $userId) !== (int)$a['step_no']) {
            throw new ProcessError('attach.err_forbidden');
        }
        Repo::delete('attachment', ['attach_id' => $attachId]);
        $path = self::fullPath(\App\Core\Scope::cd(), (string)$a['stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array{0:array<string,mixed>,1:string} [행, 실제 경로] */
    public static function locate(int $attachId): array
    {
        $a = Repo::find('attachment', ['attach_id' => $attachId]) ?? throw new ProcessError('process.err_not_found');
        $path = self::fullPath(\App\Core\Scope::cd(), (string)$a['stored_name']);
        if (!is_file($path)) {
            throw new ProcessError('process.err_not_found');
        }
        return [$a, $path];
    }

    public static function fullPath(string $company, string $rel): string
    {
        if (preg_match('~^\d{4}/\d{2}/[a-f0-9]{32}\z~', $rel) !== 1) {
            throw new \InvalidArgumentException('invalid stored name');
        }
        return self::baseDir() . '/attachments/' . $company . '/' . $rel;
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name, " .\t");
        if (mb_strlen($name) > 150) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 140) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name === '' ? 'file' : $name;
    }

    private static function sniff(string $path): string
    {
        $mime = @(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime === false) {
            throw new ProcessError('attach.err_upload');
        }
        return $mime;
    }

    private static function mimeMatchesExt(string $ext, string $mime): bool
    {
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            return str_starts_with($mime, 'image/');
        }
        if ($ext === 'pdf') {
            return $mime === 'application/pdf';
        }
        if (in_array($ext, ['txt', 'csv', 'log', 'md'], true)) {
            return str_starts_with($mime, 'text/') || $mime === 'application/csv' || $mime === 'application/octet-stream';
        }
        return true;
    }
}
