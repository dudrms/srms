<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Repo;

/** 프로세스 마스터/버전/단계/참조자 관리와 버전 활성화 규칙(설계서 6.1~6.2). */
final class ProcessService
{
    public const STEP_TYPES = ['SUBMIT', 'REVIEW', 'AGREE', 'APPROVE', 'WORK', 'VERIFY', 'CLOSE'];
    public const ACTOR_TYPES = ['ROLE', 'USER', 'REQUESTER', 'SERVICE_IT_OWNER', 'SERVICE_BIZ_OWNER', 'SERVICE_BIZ_DEPT'];
    public const CC_TYPES = ['ROLE', 'USER', 'DEPT', 'REQUESTER', 'SERVICE_IT_OWNER', 'SERVICE_BIZ_OWNER', 'SERVICE_BIZ_DEPT'];
    public const NOTIFY_ON = ['NONE', 'ACTIVATE', 'COMPLETE', 'BOTH'];
    public const ATTACH_MODES = ['NONE', 'OPTIONAL', 'REQUIRED'];
    public const APPROVAL_MODES = ['ANY', 'ALL'];
    /** 단계 설정으로도 허용할 수 없는 확장자 */
    public const BLOCKED_EXTS = [
        'php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'sh', 'js', 'vbs', 'ps1', 'msi', 'dll', 'jar',
        'html', 'htm', 'svg', 'hta', 'scr', 'lnk', 'cgi', 'pl', 'py',
    ];

    public static function today(): string
    {
        return date('Y-m-d');
    }

    // ---------------------------------------------------------------- 마스터

    /** @param array<string,string> $tr 언어 => 번역 */
    public static function createProcess(string $code, string $name, string $desc, array $tr): int
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $code) !== 1) {
            throw new ProcessError('process.err_code');
        }
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($desc) > 500) {
            throw new ProcessError('process.err_name');
        }
        if (Repo::find('process_master', ['process_cd' => $code]) !== null) {
            throw new ProcessError('process.err_duplicate');
        }
        return (int)Db::tx(static function () use ($code, $name, $desc, $tr): int {
            $pid = Repo::insert('process_master', [
                'process_cd' => $code, 'process_nm' => $name, 'description' => $desc === '' ? null : $desc,
            ]);
            Translations::save('PROCESS', $code, $tr);
            $vid = self::insertVersion($pid, 1, self::today(), null);
            self::insertStepRaw($vid, 1, '접수', 'SUBMIT', 'REQUESTER', null, 'ANY', 0, null, null, 'OPTIONAL');
            self::insertStepRaw($vid, 2, '종료', 'CLOSE', 'ROLE', 'IT_TEAM', 'ANY', 0, null, null, 'NONE');
            return $pid;
        });
    }

    /** @param array<string,string> $tr */
    public static function updateProcess(int $pid, string $name, string $desc, array $tr): void
    {
        $p = self::process($pid);
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($desc) > 500) {
            throw new ProcessError('process.err_name');
        }
        Repo::update('process_master', ['process_id' => $pid], ['process_nm' => $name, 'description' => $desc === '' ? null : $desc]);
        Translations::save('PROCESS', (string)$p['process_cd'], $tr);
    }

    public static function deleteProcess(int $pid): void
    {
        $p = self::process($pid);
        self::assertNotSystem($p);
        if (Repo::count('process_map', ['process_id' => $pid]) > 0) {
            throw new ProcessError('process.err_mapped');
        }
        $used = (int)Repo::queryVal(
            'SELECT COUNT(*) FROM request r JOIN process_version v ON v.company_cd = r.company_cd AND v.version_id = r.version_id
              WHERE r.company_cd = :company_cd AND v.process_id = :p',
            ['p' => $pid]
        );
        if ($used > 0) {
            throw new ProcessError('process.err_used');
        }
        Db::tx(static function () use ($pid, $p): void {
            foreach (Repo::list('process_version', ['process_id' => $pid]) as $v) {
                self::purgeVersion((int)$v['version_id']);
            }
            Repo::delete('process_master', ['process_id' => $pid]);
            Translations::remove('PROCESS', (string)$p['process_cd']);
        });
    }

    /** @return array<string,mixed> */
    public static function process(int $pid): array
    {
        return Repo::find('process_master', ['process_id' => $pid]) ?? throw new ProcessError('process.err_not_found');
    }

    /** 작업일지가 쓰는 시스템 기본 프로세스: 버전·단계를 바꾸거나 삭제할 수 없다(이름·설명 번역만 수정 가능). */
    public static function isSystem(array $process): bool
    {
        return (string)$process['process_cd'] === WorklogService::PROCESS;
    }

    private static function assertNotSystem(array $process): void
    {
        if (self::isSystem($process)) {
            throw new ProcessError('process.err_system');
        }
    }

    // ---------------------------------------------------------------- 버전

    /** @return list<array<string,mixed>> 최신 버전 우선, 단계 수/사용 건수 포함 */
    public static function versions(int $pid): array
    {
        $rows = Repo::query(
            'SELECT v.*,
                    (SELECT COUNT(*) FROM process_step s WHERE s.company_cd = v.company_cd AND s.version_id = v.version_id) AS step_cnt,
                    (SELECT COUNT(*) FROM request r WHERE r.company_cd = v.company_cd AND r.version_id = v.version_id) AS request_cnt
               FROM process_version v WHERE v.company_cd = :company_cd AND v.process_id = :p ORDER BY v.version_no DESC',
            ['p' => $pid]
        );
        foreach ($rows as &$r) {
            $r['state'] = self::state($r);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function version(int $pid, int $vid): ?array
    {
        $v = Repo::find('process_version', ['version_id' => $vid, 'process_id' => $pid]);
        if ($v !== null) {
            $v['state'] = self::state($v);
        }
        return $v;
    }

    /** DRAFT / SCHEDULED(적용 예정) / IN_USE(적용 중) / EXPIRED(기간 만료) / RETIRED(종료) */
    public static function state(array $v, ?string $today = null): string
    {
        $today ??= self::today();
        $status = (string)$v['status'];
        if ($status !== 'ACTIVE') {
            return $status;
        }
        if ((string)$v['valid_from'] > $today) {
            return 'SCHEDULED';
        }
        if ($v['valid_to'] !== null && (string)$v['valid_to'] < $today) {
            return 'EXPIRED';
        }
        return 'IN_USE';
    }

    /** 지정일에 적용되는 활성 버전(없으면 null). 요청 접수 시 사용한다. */
    public static function resolveVersion(int $pid, ?string $date = null): ?array
    {
        $date ??= self::today();
        return Repo::queryOne(
            "SELECT * FROM process_version WHERE company_cd = :company_cd AND process_id = :p AND status = 'ACTIVE'
                AND valid_from <= :d AND (valid_to IS NULL OR valid_to >= :d) ORDER BY version_no DESC LIMIT 1",
            ['p' => $pid, 'd' => $date]
        );
    }

    /** 새 DRAFT 버전 생성. $fromVid 가 있으면 단계/참조자/번역을 복제한다. */
    public static function createVersion(int $pid, ?int $fromVid): int
    {
        self::assertNotSystem(self::process($pid));
        $from = null;
        if ($fromVid !== null) {
            $from = self::version($pid, $fromVid) ?? throw new ProcessError('process.err_not_found');
        }
        if (Repo::count('process_version', ['process_id' => $pid, 'status' => 'DRAFT']) > 0) {
            throw new ProcessError('process.err_draft_exists');
        }
        return (int)Db::tx(static function () use ($pid, $from): int {
            $no = (int)Repo::queryVal(
                'SELECT COALESCE(MAX(version_no), 0) + 1 FROM process_version WHERE company_cd = :company_cd AND process_id = :p',
                ['p' => $pid]
            );
            $vid = self::insertVersion($pid, $no, self::today(), null);
            if ($from === null) {
                return $vid;
            }
            $map = [];
            foreach (self::steps((int)$from['version_id']) as $s) {
                $old = (int)$s['step_id'];
                unset($s['step_id'], $s['company_cd']);
                $s['version_id'] = $vid;
                $new = Repo::insert('process_step', $s);
                $map[$old] = $new;
                $tr = Translations::get('STEP', (string)$old);
                if ($tr !== []) {
                    Translations::save('STEP', (string)$new, $tr);
                }
            }
            foreach ($map as $old => $new) {
                foreach (Repo::list('process_step_cc', ['step_id' => $old]) as $c) {
                    Repo::insert('process_step_cc', [
                        'step_id' => $new, 'cc_type' => $c['cc_type'], 'cc_value' => $c['cc_value'], 'notify_on' => $c['notify_on'],
                    ]);
                }
            }
            return $vid;
        });
    }

    /** DRAFT 버전의 유효기간 기본값을 수정한다(활성화 시 다시 지정 가능). */
    public static function setDates(int $pid, int $vid, string $from, string $to): void
    {
        self::assertNotSystem(self::process($pid));
        $v = self::version($pid, $vid) ?? throw new ProcessError('process.err_not_found');
        self::assertDraft($v);
        [$from, $toVal] = self::dates($from, $to);
        Repo::update('process_version', ['version_id' => $vid], ['valid_from' => $from, 'valid_to' => $toVal]);
    }

    /**
     * 활성화. 구조 검증 → 날짜 검증 → 기존 활성 버전과의 겹침 처리.
     * 종료일이 없는 새 버전은 시작일이 앞선 기존 버전을 시작일 전날로 자동 종료한다.
     */
    public static function activate(int $pid, int $vid, string $from, string $to): void
    {
        self::assertNotSystem(self::process($pid));
        $v = self::version($pid, $vid) ?? throw new ProcessError('process.err_not_found');
        self::assertDraft($v);
        [$from, $toVal] = self::dates($from, $to);
        $errors = self::validateVersion($vid);
        if ($errors !== []) {
            throw new ProcessError('process.err_invalid', ['errors' => $errors]);
        }
        Db::tx(static function () use ($pid, $vid, $from, $toVal): void {
            $today = self::today();
            foreach (Repo::query(
                "SELECT * FROM process_version WHERE company_cd = :company_cd AND process_id = :p AND status = 'ACTIVE' AND version_id <> :v",
                ['p' => $pid, 'v' => $vid]
            ) as $o) {
                $oFrom = (string)$o['valid_from'];
                $oTo = $o['valid_to'] === null ? null : (string)$o['valid_to'];
                $intersect = ($oTo === null || $oTo >= $from) && ($toVal === null || $oFrom <= $toVal);
                if (!$intersect) {
                    continue;
                }
                if ($toVal !== null) {
                    throw new ProcessError('process.err_overlap');
                }
                if ($oFrom < $from) {
                    $newTo = date('Y-m-d', strtotime($from . ' -1 day'));
                    Repo::update('process_version', ['version_id' => $o['version_id']], [
                        'valid_to' => $newTo, 'status' => $newTo < $today ? 'RETIRED' : 'ACTIVE',
                    ]);
                    continue;
                }
                // 기존 버전이 새 버전 시작일 이후에 시작: 사용 이력이 없거나 오늘 이후 시작이면 대체(종료), 아니면 충돌
                if (Repo::count('request', ['version_id' => $o['version_id']]) > 0 && $oFrom < $today) {
                    throw new ProcessError('process.err_overlap');
                }
                Repo::update('process_version', ['version_id' => $o['version_id']], ['status' => 'RETIRED', 'valid_to' => $oFrom]);
            }
            Repo::update('process_version', ['version_id' => $vid], ['status' => 'ACTIVE', 'valid_from' => $from, 'valid_to' => $toVal]);
        });
    }

    /** 활성 버전을 종료한다(신규 접수 중단). 진행 중 건은 영향 없음. */
    public static function retire(int $pid, int $vid): void
    {
        self::assertNotSystem(self::process($pid));
        $v = self::version($pid, $vid) ?? throw new ProcessError('process.err_not_found');
        if ((string)$v['status'] !== 'ACTIVE') {
            throw new ProcessError('process.err_not_active');
        }
        $today = self::today();
        $to = $v['valid_to'] === null || (string)$v['valid_to'] > $today ? $today : (string)$v['valid_to'];
        if ($to < (string)$v['valid_from']) {
            $to = (string)$v['valid_from'];
        }
        Repo::update('process_version', ['version_id' => $vid], ['status' => 'RETIRED', 'valid_to' => $to]);
    }

    /** DRAFT 는 항상, RETIRED 는 사용 이력이 없을 때만 삭제 가능. */
    public static function deleteVersion(int $pid, int $vid): void
    {
        $v = self::version($pid, $vid) ?? throw new ProcessError('process.err_not_found');
        $status = (string)$v['status'];
        if ($status !== 'DRAFT') {
            self::assertNotSystem(self::process($pid));
        }
        if ($status === 'ACTIVE') {
            throw new ProcessError('process.err_delete_active');
        }
        if ($status === 'RETIRED' && Repo::count('request', ['version_id' => $vid]) > 0) {
            throw new ProcessError('process.err_used');
        }
        Db::tx(static fn() => self::purgeVersion($vid));
    }

    // ---------------------------------------------------------------- 단계

    /** @return list<array<string,mixed>> */
    public static function steps(int $vid): array
    {
        return Repo::list('process_step', ['version_id' => $vid], 'step_no');
    }

    /** @return array<int,list<array<string,mixed>>> step_id => 참조자 목록 */
    public static function ccsByStep(int $vid): array
    {
        $out = [];
        foreach (Repo::query(
            'SELECT c.* FROM process_step_cc c JOIN process_step s ON s.company_cd = c.company_cd AND s.step_id = c.step_id
              WHERE c.company_cd = :company_cd AND s.version_id = :v ORDER BY c.cc_id',
            ['v' => $vid]
        ) as $c) {
            $out[(int)$c['step_id']][] = $c;
        }
        return $out;
    }

    /** @param array<string,string> $in 폼 입력 */
    public static function addStep(int $pid, int $vid, array $in): int
    {
        self::editable($pid, $vid);
        $no = (int)Repo::queryVal(
            'SELECT COALESCE(MAX(step_no), 0) + 1 FROM process_step WHERE company_cd = :company_cd AND version_id = :v',
            ['v' => $vid]
        );
        $row = self::stepRow($in, $no);
        return (int)Db::tx(static function () use ($vid, $no, $row, $in): int {
            $sid = Repo::insert('process_step', ['version_id' => $vid, 'step_no' => $no] + $row);
            Translations::save('STEP', (string)$sid, Translations::collect($in));
            return $sid;
        });
    }

    /** @param array<string,string> $in */
    public static function updateStep(int $pid, int $vid, int $sid, array $in): void
    {
        self::editable($pid, $vid);
        $s = self::step($vid, $sid);
        $row = self::stepRow($in, (int)$s['step_no']);
        Db::tx(static function () use ($sid, $row, $in): void {
            Repo::update('process_step', ['step_id' => $sid], $row);
            Translations::save('STEP', (string)$sid, Translations::collect($in));
        });
    }

    public static function deleteStep(int $pid, int $vid, int $sid): void
    {
        self::editable($pid, $vid);
        self::step($vid, $sid);
        Db::tx(static function () use ($vid, $sid): void {
            Repo::delete('process_step_cc', ['step_id' => $sid]);
            Repo::delete('process_step', ['step_id' => $sid]);
            Translations::remove('STEP', (string)$sid);
            $ids = array_map(static fn($s) => (int)$s['step_id'], self::steps($vid));
            self::applyOrder($vid, $ids);
        });
    }

    /** @param list<int> $orderedIds 단계 ID 의 새 순서(전체 집합이어야 함) */
    public static function reorder(int $pid, int $vid, array $orderedIds): void
    {
        self::editable($pid, $vid);
        $current = array_map(static fn($s) => (int)$s['step_id'], self::steps($vid));
        $given = array_map('intval', $orderedIds);
        $a = $current;
        $b = $given;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new ProcessError('process.err_order');
        }
        Db::tx(static fn() => self::applyOrder($vid, $given));
    }

    public static function addCc(int $pid, int $vid, int $sid, string $type, string $value, string $notifyOn): void
    {
        self::editable($pid, $vid);
        self::step($vid, $sid);
        if (!in_array($type, self::CC_TYPES, true) || !in_array($notifyOn, self::NOTIFY_ON, true)) {
            throw new ProcessError('process.err_cc');
        }
        $val = self::refValue($type, $value, true);
        if (Repo::queryVal(
            'SELECT COUNT(*) FROM process_step_cc WHERE company_cd = :company_cd AND step_id = :s AND cc_type = :t AND COALESCE(cc_value, \'\') = :v',
            ['s' => $sid, 't' => $type, 'v' => $val ?? '']
        ) > 0) {
            throw new ProcessError('process.err_cc_duplicate');
        }
        Repo::insert('process_step_cc', ['step_id' => $sid, 'cc_type' => $type, 'cc_value' => $val, 'notify_on' => $notifyOn]);
    }

    public static function deleteCc(int $pid, int $vid, int $sid, int $ccId): void
    {
        self::editable($pid, $vid);
        self::step($vid, $sid);
        if (Repo::delete('process_step_cc', ['cc_id' => $ccId, 'step_id' => $sid]) === 0) {
            throw new ProcessError('process.err_not_found');
        }
    }

    // ---------------------------------------------------------------- 검증

    /** @return list<array{0:string,1:array<string,mixed>}> [번역키, 치환값] 목록. 비어 있으면 활성화 가능 */
    public static function validateVersion(int $vid): array
    {
        $err = [];
        $steps = self::steps($vid);
        $n = count($steps);
        if ($n < 2) {
            $err[] = ['process.v_min_steps', []];
        }
        foreach ($steps as $i => $s) {
            $no = (int)$s['step_no'];
            $last = $i === $n - 1;
            if ($i === 0 && $s['step_type'] !== 'SUBMIT') {
                $err[] = ['process.v_first_submit', []];
            }
            if ($i > 0 && $s['step_type'] === 'SUBMIT') {
                $err[] = ['process.v_submit_only_first', ['no' => $no]];
            }
            if ($last && $s['step_type'] !== 'CLOSE') {
                $err[] = ['process.v_last_close', []];
            }
            if (!$last && $s['step_type'] === 'CLOSE') {
                $err[] = ['process.v_close_only_last', ['no' => $no]];
            }
            if ($s['step_type'] === 'SUBMIT' && $s['actor_type'] !== 'REQUESTER') {
                $err[] = ['process.v_submit_actor', ['no' => $no]];
            }
            if ($s['reject_to_step'] !== null && ((int)$s['reject_to_step'] < 1 || (int)$s['reject_to_step'] >= $no)) {
                $err[] = ['process.v_reject_to', ['no' => $no]];
            }
            if ($s['actor_type'] === 'ROLE' && Repo::find('role', ['role_cd' => (string)$s['actor_value']]) === null) {
                $err[] = ['process.v_actor', ['no' => $no]];
            }
            if ($s['actor_type'] === 'USER' && Repo::find('user', ['user_id' => (int)$s['actor_value'], 'use_yn' => 1]) === null) {
                $err[] = ['process.v_actor', ['no' => $no]];
            }
        }
        foreach (self::ccsByStep($vid) as $list) {
            foreach ($list as $c) {
                $bad = ($c['cc_type'] === 'ROLE' && Repo::find('role', ['role_cd' => (string)$c['cc_value']]) === null)
                    || ($c['cc_type'] === 'USER' && Repo::find('user', ['user_id' => (int)$c['cc_value'], 'use_yn' => 1]) === null)
                    || ($c['cc_type'] === 'DEPT' && Repo::find('dept', ['dept_id' => (int)$c['cc_value'], 'use_yn' => 1]) === null);
                if ($bad) {
                    $err[] = ['process.v_cc', []];
                }
            }
        }
        return $err;
    }

    // ---------------------------------------------------------------- 서비스 × 타입 매핑

    /** @return array{services:list<array>,types:list<array>,processes:list<array>,map:array<string,int>} */
    public static function mappingMatrix(): array
    {
        $map = [];
        foreach (Repo::list('process_map') as $m) {
            $map[$m['service_id'] . '|' . $m['maint_type']] = (int)$m['process_id'];
        }
        return [
            'services' => Repo::list('service', ['use_yn' => 1], 'service_cd'),
            'types' => array_values(array_filter(
                Repo::list('code', ['group_cd' => 'MAINT_TYPE', 'use_yn' => 1], 'sort_no, code_cd'),
                static fn(array $c): bool => $c['code_cd'] !== WorklogService::TYPE
            )),
            'processes' => array_values(array_filter(
                Repo::list('process_master', [], 'process_cd'),
                static fn(array $p): bool => $p['process_cd'] !== WorklogService::PROCESS
            )),
            'map' => $map,
        ];
    }

    /** @param array<int|string,array<string,string>> $post [service_id][maint_type] => process_id('' = 해제) */
    public static function saveMapping(array $post): void
    {
        $m = self::mappingMatrix();
        $serviceIds = array_map(static fn($s) => (int)$s['service_id'], $m['services']);
        $types = array_column($m['types'], 'code_cd');
        $procIds = array_map(static fn($p) => (int)$p['process_id'], $m['processes']);
        Db::tx(static function () use ($post, $serviceIds, $types, $procIds): void {
            foreach ($serviceIds as $sid) {
                foreach ($types as $type) {
                    if (!isset($post[$sid]) || !is_array($post[$sid]) || !array_key_exists($type, $post[$sid])) {
                        continue;
                    }
                    $raw = $post[$sid][$type];
                    $pid = is_string($raw) && ctype_digit($raw) ? (int)$raw : 0;
                    if ($pid !== 0 && !in_array($pid, $procIds, true)) {
                        throw new ProcessError('process.err_map');
                    }
                    Repo::delete('process_map', ['service_id' => $sid, 'maint_type' => $type]);
                    if ($pid !== 0) {
                        Repo::insert('process_map', ['service_id' => $sid, 'maint_type' => $type, 'process_id' => $pid]);
                    }
                }
            }
        });
    }

    // ---------------------------------------------------------------- 내부

    /** @return array<string,mixed> DRAFT 버전 */
    private static function editable(int $pid, int $vid): array
    {
        self::assertNotSystem(self::process($pid));
        $v = self::version($pid, $vid) ?? throw new ProcessError('process.err_not_found');
        self::assertDraft($v);
        return $v;
    }

    private static function assertDraft(array $v): void
    {
        if ((string)$v['status'] !== 'DRAFT') {
            throw new ProcessError('process.err_not_draft');
        }
    }

    /** @return array<string,mixed> */
    private static function step(int $vid, int $sid): array
    {
        return Repo::find('process_step', ['step_id' => $sid, 'version_id' => $vid]) ?? throw new ProcessError('process.err_not_found');
    }

    private static function insertVersion(int $pid, int $no, string $from, ?string $to): int
    {
        $u = Auth::user();
        return Repo::insert('process_version', [
            'process_id' => $pid, 'version_no' => $no, 'valid_from' => $from, 'valid_to' => $to, 'status' => 'DRAFT',
            'created_by' => $u === null ? null : (int)$u['user_id'], 'created_at' => now_str(),
        ]);
    }

    /** 검증 없이 단계 행을 추가(시드/초기 생성용). */
    public static function insertStepRaw(
        int $vid, int $no, string $name, string $type, string $actorType, ?string $actorValue, string $mode,
        int $allowReject, ?int $rejectTo, ?int $dueDays, string $attach, ?string $notify = null,
    ): int {
        return Repo::insert('process_step', [
            'version_id' => $vid, 'step_no' => $no, 'step_nm' => $name, 'step_type' => $type, 'actor_type' => $actorType,
            'actor_value' => $actorValue, 'approval_mode' => $mode, 'allow_reject' => $allowReject, 'reject_to_step' => $rejectTo,
            'due_days' => $dueDays, 'notify_tmpl' => $notify, 'attach_mode' => $attach,
        ]);
    }

    private static function purgeVersion(int $vid): void
    {
        foreach (self::steps($vid) as $s) {
            Repo::delete('process_step_cc', ['step_id' => $s['step_id']]);
            Translations::remove('STEP', (string)$s['step_id']);
        }
        Repo::delete('process_step', ['version_id' => $vid]);
        Repo::delete('process_version', ['version_id' => $vid]);
    }

    /**
     * 단계 순서 적용. 단계번호가 바뀌므로 반려 이동 대상도 새 번호로 바꾸고, 삭제된 대상은 해제한다.
     * @param list<int> $orderedIds
     */
    private static function applyOrder(int $vid, array $orderedIds): void
    {
        $byId = [];
        foreach (self::steps($vid) as $s) {
            $byId[(int)$s['step_id']] = $s;
        }
        $noMap = [];
        foreach ($orderedIds as $i => $id) {
            $noMap[(int)$byId[$id]['step_no']] = $i + 1;
        }
        foreach ($byId as $id => $s) {
            Repo::update('process_step', ['step_id' => $id], ['step_no' => -$id]);
        }
        foreach ($orderedIds as $i => $id) {
            $rej = $byId[$id]['reject_to_step'];
            Repo::update('process_step', ['step_id' => $id], [
                'step_no' => $i + 1,
                'reject_to_step' => $rej === null ? null : ($noMap[(int)$rej] ?? null),
            ]);
        }
    }

    /** @return array{0:string,1:?string} */
    private static function dates(string $from, string $to): array
    {
        $ok = static fn(string $d): bool => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
        if (!$ok($from) || ($to !== '' && !$ok($to))) {
            throw new ProcessError('process.err_date');
        }
        if ($to !== '' && $to < $from) {
            throw new ProcessError('process.err_date_range');
        }
        return [$from, $to === '' ? null : $to];
    }

    /** 처리자/참조자 값 검증: ROLE·USER·DEPT 는 존재 확인, 그 외는 null. */
    private static function refValue(string $type, string $value, bool $allowDept): ?string
    {
        switch ($type) {
            case 'ROLE':
                if (Repo::find('role', ['role_cd' => $value]) === null) {
                    throw new ProcessError('process.err_actor');
                }
                return $value;
            case 'USER':
                if (!ctype_digit($value) || Repo::find('user', ['user_id' => (int)$value, 'use_yn' => 1]) === null) {
                    throw new ProcessError('process.err_actor');
                }
                return (string)(int)$value;
            case 'DEPT':
                if (!$allowDept || !ctype_digit($value) || Repo::find('dept', ['dept_id' => (int)$value, 'use_yn' => 1]) === null) {
                    throw new ProcessError('process.err_actor');
                }
                return (string)(int)$value;
            default:
                return null;
        }
    }

    /**
     * 폼 입력을 process_step 컬럼 값으로 검증/변환한다.
     * @param array<string,string> $in
     * @return array<string,mixed>
     */
    private static function stepRow(array $in, int $ownNo): array
    {
        $name = trim((string)($in['step_nm'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ProcessError('process.err_step_name');
        }
        $type = (string)($in['step_type'] ?? '');
        $actor = (string)($in['actor_type'] ?? '');
        $allowedActors = self::ACTOR_TYPES;
        if (!in_array($type, self::STEP_TYPES, true) || !in_array($actor, $allowedActors, true)) {
            throw new ProcessError('process.err_step_type');
        }
        $mode = (string)($in['approval_mode'] ?? 'ANY');
        $attach = (string)($in['attach_mode'] ?? 'NONE');
        if (!in_array($mode, self::APPROVAL_MODES, true) || !in_array($attach, self::ATTACH_MODES, true)) {
            throw new ProcessError('process.err_step_type');
        }
        $reject = self::optInt($in['reject_to_step'] ?? '', 1, 999, 'process.err_reject_to');
        if ($reject !== null && $reject >= $ownNo) {
            throw new ProcessError('process.err_reject_to');
        }
        $notify = trim((string)($in['notify_tmpl'] ?? ''));
        if ($notify !== '' && preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $notify) !== 1) {
            throw new ProcessError('process.err_notify');
        }
        return [
            'step_nm' => $name,
            'step_type' => $type,
            'actor_type' => $actor,
            'actor_value' => self::refValue($actor, trim((string)($in['actor_value'] ?? '')), false),
            'approval_mode' => $mode,
            'allow_reject' => ($in['allow_reject'] ?? '') === '1' ? 1 : 0,
            'reject_to_step' => $reject,
            'due_days' => self::optInt($in['due_days'] ?? '', 1, 365, 'process.err_due'),
            'notify_tmpl' => $notify === '' ? null : $notify,
            'attach_mode' => $attach,
            'attach_max_cnt' => self::optInt($in['attach_max_cnt'] ?? '', 1, 50, 'process.err_attach'),
            'attach_max_mb' => self::optInt($in['attach_max_mb'] ?? '', 1, 200, 'process.err_attach'),
            'attach_exts' => self::exts((string)($in['attach_exts'] ?? '')),
        ];
    }

    private static function optInt(string $v, int $min, int $max, string $errKey): ?int
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (!ctype_digit($v) || (int)$v < $min || (int)$v > $max) {
            throw new ProcessError($errKey);
        }
        return (int)$v;
    }

    private static function exts(string $raw): ?string
    {
        $list = array_values(array_unique(array_filter(array_map(
            static fn(string $e): string => strtolower(ltrim(trim($e), '.')),
            preg_split('/[,\s]+/', $raw) ?: []
        ))));
        if ($list === []) {
            return null;
        }
        foreach ($list as $e) {
            if (preg_match('/^[a-z0-9]{1,10}$/', $e) !== 1 || in_array($e, self::BLOCKED_EXTS, true)) {
                throw new ProcessError('process.err_ext', ['ext' => $e]);
            }
        }
        return implode(',', $list);
    }
}
