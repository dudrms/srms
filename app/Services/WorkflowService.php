<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Repo;

/** 요청 진행 엔진: 제출/단계 처리/반려/취소. 모든 전이는 하나의 쓰기 트랜잭션에서 수행한다. */
final class WorkflowService
{
    public const ST_DRAFT = 'DRAFT';
    public const ST_PROGRESS = 'IN_PROGRESS';
    public const ST_RETURNED = 'RETURNED';
    public const ST_DONE = 'COMPLETED';
    public const ST_CANCELED = 'CANCELED';

    /** 단계 유형 → 승인 이력 action */
    public const ACTION = [
        'REVIEW' => 'APPROVE', 'APPROVE' => 'APPROVE', 'VERIFY' => 'APPROVE',
        'AGREE' => 'AGREE', 'WORK' => 'COMPLETE', 'CLOSE' => 'COMPLETE',
    ];

    /** @return array<string,mixed> */
    public static function request(int $rid): array
    {
        return Repo::find('request', ['request_id' => $rid]) ?? throw new ProcessError('process.err_not_found');
    }

    /** @return array<string,mixed> */
    public static function service(array $req): array
    {
        return Repo::find('service', ['service_id' => (int)$req['service_id']]) ?? throw new ProcessError('process.err_not_found');
    }

    /** 서비스×유형에 매핑된 프로세스의 오늘 유효한 버전. */
    public static function resolveVersionFor(int $serviceId, string $maintType): array
    {
        $map = Repo::find('process_map', ['service_id' => $serviceId, 'maint_type' => $maintType]);
        if ($map === null) {
            throw new ProcessError('request.err_no_process');
        }
        return ProcessService::resolveVersion((int)$map['process_id']) ?? throw new ProcessError('request.err_no_version');
    }

    /** 임시저장/반려 상태의 요청을 제출(또는 재제출)한다. */
    public static function submit(int $rid, int $userId): void
    {
        Db::tx(static function () use ($rid, $userId): void {
            $req = self::request($rid);
            if ((int)$req['requester_id'] !== $userId) {
                throw new ProcessError('request.err_forbidden');
            }
            $status = (string)$req['status'];
            if (!in_array($status, [self::ST_DRAFT, self::ST_RETURNED], true)) {
                throw new ProcessError('request.err_state');
            }
            $svc = self::service($req);
            if ((int)$svc['use_yn'] !== 1) {
                throw new ProcessError('request.err_service');
            }
            if (HtmlSanitizer::text((string)$req['content_html']) === '' || trim((string)$req['title']) === '') {
                throw new ProcessError('request.err_content');
            }
            if (!Dates::isDate((string)$req['req_due_date']) || ($status === self::ST_DRAFT && (string)$req['req_due_date'] < date('Y-m-d'))) {
                throw new ProcessError('request.err_due');
            }
            $now = now_str();
            if ($status === self::ST_DRAFT) {
                $ver = self::resolveVersionFor((int)$req['service_id'], (string)$req['maint_type']);
                Repo::update('request', ['request_id' => $rid], ['version_id' => (int)$ver['version_id']]);
                $req['version_id'] = $ver['version_id'];
            }
            $steps = ProcessService::steps((int)$req['version_id']);
            if (count($steps) < 2) {
                throw new ProcessError('request.err_steps');
            }
            self::assertSubmittable($req, $svc, $steps);

            if ($status === self::ST_DRAFT) {
                Repo::delete('request_step', ['request_id' => $rid]);
                foreach ($steps as $s) {
                    Repo::insert('request_step', [
                        'request_id' => $rid, 'step_no' => (int)$s['step_no'], 'step_nm' => $s['step_nm'],
                        'status' => 'WAITING', 'round' => 0,
                    ]);
                }
            }
            $first = self::rstep($rid, 1);
            Repo::update('request_step', ['rstep_id' => $first['rstep_id']], [
                'status' => 'DONE', 'round' => (int)$first['round'] + 1, 'started_at' => $first['started_at'] ?? $now, 'ended_at' => $now,
            ]);
            self::logApproval((int)$first['rstep_id'], $userId, 'SUBMIT', null, (int)$first['round'] + 1);
            self::activate($rid, $steps[1], $now);
            $submitted = [
                'status' => self::ST_PROGRESS, 'current_step_no' => (int)$steps[1]['step_no'], 'updated_at' => $now,
            ];
            if ($req['request_date'] === null) {
                $submitted['request_date'] = substr($now, 0, 10); // 최초 제출일, 재제출해도 바꾸지 않는다
            }
            Repo::update('request', ['request_id' => $rid], $submitted);
            Notifier::transition($req, $svc, $steps[0], $steps[1], $userId);
        });
    }

    /**
     * 현재 단계 처리.
     * @param string $decision PASS(승인/합의/완료) 또는 REJECT(반려)
     * @return string ADVANCED / COMPLETED / WAITING_OTHERS / RETURNED / REWORK
     */
    public static function act(int $rid, int $userId, string $decision, string $opinion): string
    {
        return (string)Db::tx(static function () use ($rid, $userId, $decision, $opinion): string {
            $req = self::request($rid);
            if ((string)$req['status'] !== self::ST_PROGRESS) {
                throw new ProcessError('request.err_state');
            }
            $svc = self::service($req);
            $no = (int)$req['current_step_no'];
            $def = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => $no]) ?? throw new ProcessError('process.err_not_found');
            $rs = self::rstep($rid, $no);
            if ((string)$rs['status'] !== 'ACTIVE') {
                throw new ProcessError('request.err_state');
            }
            if (!ActorResolver::isActor($def, $req, $svc, $userId)) {
                throw new ProcessError('request.err_not_actor');
            }
            if (Repo::count('schedule_change', ['request_id' => $rid, 'status' => 'PROPOSED']) > 0) {
                throw new ProcessError('request.err_schedule_locked');
            }
            $round = (int)$rs['round'];
            if (Repo::count('approval', ['rstep_id' => $rs['rstep_id'], 'actor_id' => $userId, 'round' => $round]) > 0) {
                throw new ProcessError('request.err_already');
            }
            $opinion = trim($opinion);
            if (mb_strlen($opinion) > 2000) {
                throw new ProcessError('request.err_opinion_len');
            }
            $now = now_str();

            if ($decision === 'REJECT') {
                return self::reject($req, $def, $rs, $userId, $opinion, $now);
            }
            if ($decision !== 'PASS') {
                throw new ProcessError('request.err_state');
            }
            if ($def['attach_mode'] === 'REQUIRED' && Repo::count('attachment', ['request_id' => $rid, 'step_no' => $no]) < 1) {
                throw new ProcessError('request.err_attach_required');
            }
            self::logApproval((int)$rs['rstep_id'], $userId, self::ACTION[(string)$def['step_type']] ?? 'COMPLETE', $opinion === '' ? null : $opinion, $round);

            if ($def['approval_mode'] === 'ALL') {
                $need = ActorResolver::userIds($def, $req, $svc);
                $done = array_map('intval', array_column(Repo::query(
                    "SELECT DISTINCT actor_id FROM approval WHERE company_cd = :company_cd AND rstep_id = :r AND round = :n AND action <> 'REJECT'",
                    ['r' => $rs['rstep_id'], 'n' => $round]
                ), 'actor_id'));
                if (array_diff($need, $done) !== []) {
                    Repo::update('request', ['request_id' => $rid], ['updated_at' => $now]);
                    return 'WAITING_OTHERS';
                }
            }
            Repo::update('request_step', ['rstep_id' => $rs['rstep_id']], ['status' => 'DONE', 'ended_at' => $now]);
            $next = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => $no + 1]);
            if ($next === null) {
                Repo::update('request', ['request_id' => $rid], [
                    'status' => self::ST_DONE, 'actual_end' => date('Y-m-d'), 'updated_at' => $now,
                ]);
                Notifier::transition($req, $svc, $def, null, $userId);
                return 'COMPLETED';
            }
            self::activate($rid, $next, $now);
            Repo::update('request', ['request_id' => $rid], ['current_step_no' => (int)$next['step_no'], 'updated_at' => $now]);
            Notifier::transition($req, $svc, $def, $next, $userId);
            return 'ADVANCED';
        });
    }

    /** 요청자만 취소할 수 있다(임시저장/진행 중/반려 상태). */
    public static function cancel(int $rid, int $userId): void
    {
        Db::tx(static function () use ($rid, $userId): void {
            $req = self::request($rid);
            if ((int)$req['requester_id'] !== $userId) {
                throw new ProcessError('request.err_forbidden');
            }
            if (!in_array((string)$req['status'], [self::ST_DRAFT, self::ST_PROGRESS, self::ST_RETURNED], true)) {
                throw new ProcessError('request.err_state');
            }
            $now = now_str();
            $cur = $req['current_step_no'] === null ? null : Repo::find('request_step', ['request_id' => $rid, 'step_no' => (int)$req['current_step_no']]);
            if ($cur !== null) {
                self::logApproval((int)$cur['rstep_id'], $userId, 'CANCEL', null, (int)$cur['round']);
            }
            ScheduleService::withdrawPending($rid);
            Repo::update('request', ['request_id' => $rid], ['status' => self::ST_CANCELED, 'canceled_at' => substr($now, 0, 10), 'updated_at' => $now]);
        });
    }

    // ------------------------------------------------------------------ 내부

    private static function reject(array $req, array $def, array $rs, int $userId, string $opinion, string $now): string
    {
        if ((int)$def['allow_reject'] !== 1) {
            throw new ProcessError('request.err_no_reject');
        }
        if ($opinion === '') {
            throw new ProcessError('request.err_opinion_required');
        }
        $rid = (int)$req['request_id'];
        self::logApproval((int)$rs['rstep_id'], $userId, 'REJECT', $opinion, (int)$rs['round']);
        $target = $def['reject_to_step'] === null ? 1 : (int)$def['reject_to_step'];
        foreach (Repo::list('request_step', ['request_id' => $rid]) as $row) {
            if ((int)$row['step_no'] > $target) {
                Repo::update('request_step', ['rstep_id' => $row['rstep_id']], ['status' => 'WAITING', 'ended_at' => null]);
            }
        }
        if ($target <= 1) {
            $first = self::rstep($rid, 1);
            Repo::update('request_step', ['rstep_id' => $first['rstep_id']], [
                'status' => 'ACTIVE', 'round' => (int)$first['round'] + 1, 'started_at' => $now, 'ended_at' => null, 'due_at' => null,
            ]);
            Repo::update('request', ['request_id' => $rid], ['status' => self::ST_RETURNED, 'current_step_no' => 1, 'updated_at' => $now]);
            Notifier::transition($req, self::service($req), null, Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => 1]), $userId, $opinion);
            return 'RETURNED';
        }
        $targetDef = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => $target]) ?? throw new ProcessError('process.err_not_found');
        self::activate($rid, $targetDef, $now);
        Repo::update('request', ['request_id' => $rid], ['current_step_no' => $target, 'updated_at' => $now]);
        Notifier::transition($req, self::service($req), null, $targetDef, $userId, $opinion);
        return 'REWORK';
    }

    /** 단계를 활성화(회차 증가, 시작/기한 기록). */
    private static function activate(int $rid, array $def, string $now): void
    {
        $rs = self::rstep($rid, (int)$def['step_no']);
        $due = $def['due_days'] === null ? null : Dates::addBusinessDays(date('Y-m-d'), (int)$def['due_days']);
        Repo::update('request_step', ['rstep_id' => $rs['rstep_id']], [
            'status' => 'ACTIVE', 'round' => (int)$rs['round'] + 1, 'started_at' => $now, 'ended_at' => null, 'due_at' => $due,
        ]);
    }

    /** @return array<string,mixed> */
    private static function rstep(int $rid, int $no): array
    {
        return Repo::find('request_step', ['request_id' => $rid, 'step_no' => $no]) ?? throw new ProcessError('process.err_not_found');
    }

    private static function logApproval(int $rstepId, int $userId, string $action, ?string $opinion, int $round): void
    {
        Repo::insert('approval', [
            'rstep_id' => $rstepId, 'actor_id' => $userId, 'action' => $action, 'opinion' => $opinion,
            'acted_at' => now_str(), 'round' => $round,
        ]);
    }

    /** 제출 가능 조건: 1단계 첨부 필수 충족, 각 단계에 처리 가능한 사용자 존재. */
    private static function assertSubmittable(array $req, array $svc, array $steps): void
    {
        $rid = (int)$req['request_id'];
        if ($steps[0]['attach_mode'] === 'REQUIRED' && Repo::count('attachment', ['request_id' => $rid, 'step_no' => 1]) < 1) {
            throw new ProcessError('request.err_attach_required');
        }
        foreach (array_slice($steps, 1) as $s) {
            if (ActorResolver::userIds($s, $req, $svc) === []) {
                $reason = t('request.noactor.' . (string)$s['actor_type'], [
                    'v' => (string)$s['actor_type'] === 'ROLE' ? master_name('ROLE', (string)$s['actor_value'], (string)$s['actor_value']) : '',
                    'svc' => master_name('SERVICE', (string)$svc['service_cd'], (string)$svc['service_nm']),
                ]);
                throw new ProcessError('request.err_no_actor', ['step' => (string)$s['step_nm'], 'reason' => $reason]);
            }
        }
    }
}
