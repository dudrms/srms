<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Repo;

/**
 * 완료요구일 협의(설계서 6.4). 작업자(현재 단계 처리자)가 대안 완료일을 제안하고 요청자 본인만 합의/반려/대안을 낸다.
 * 협의 대기(PROPOSED) 중에는 WorkflowService 가 단계 처리를 거부한다.
 */
final class ScheduleService
{
    public const REASON_GROUP = 'SCHEDULE_REASON';
    public const STATUSES = ['PROPOSED', 'AGREED', 'REJECTED', 'COUNTERED', 'WITHDRAWN'];

    public static function responseDays(): int
    {
        return max(1, (int)env('SCHEDULE_RESPONSE_DAYS', '3'));
    }

    /** 현재 유효한 완료 목표일: 합의된 계획일이 있으면 그것, 아니면 완료요구일. */
    public static function effectiveDue(array $req): string
    {
        return (string)($req['plan_end'] ?? $req['req_due_date']);
    }

    /** @return array<string,mixed>|null */
    public static function pending(int $rid): ?array
    {
        return Repo::find('schedule_change', ['request_id' => $rid, 'status' => 'PROPOSED']);
    }

    public static function responseDue(array $change): string
    {
        return Dates::addBusinessDays(substr((string)$change['proposed_at'], 0, 10), self::responseDays());
    }

    /** 지금 이 사용자가 일정 변경을 요청할 수 있는가(작업자 전용, 협의 중 아님). */
    public static function canPropose(array $req, array $svc, int $userId): bool
    {
        try {
            self::assertCanPropose($req, $svc, $userId);
            return true;
        } catch (ProcessError) {
            return false;
        }
    }

    public static function propose(int $rid, int $userId, string $newEnd, string $reasonCd, string $reasonText): int
    {
        return (int)Db::tx(static function () use ($rid, $userId, $newEnd, $reasonCd, $reasonText): int {
            $req = WorkflowService::request($rid);
            $svc = WorkflowService::service($req);
            self::assertCanPropose($req, $svc, $userId);
            $prev = self::effectiveDue($req);
            if (!Dates::isDate($newEnd)) {
                throw new ProcessError('sched.err_date');
            }
            if ($newEnd <= $prev) {
                throw new ProcessError('sched.err_date_not_later', ['date' => $prev]);
            }
            if (Repo::find('code', ['group_cd' => self::REASON_GROUP, 'code_cd' => $reasonCd, 'use_yn' => 1]) === null) {
                throw new ProcessError('sched.err_reason');
            }
            $text = trim($reasonText);
            if ($text === '' || mb_strlen($text) > 1000) {
                throw new ProcessError('sched.err_reason_text');
            }
            try {
                $cid = Repo::insert('schedule_change', [
                    'request_id' => $rid, 'step_no' => (int)$req['current_step_no'], 'prev_end' => $prev, 'new_end' => $newEnd,
                    'reason_cd' => $reasonCd, 'reason_text' => $text, 'proposer_id' => $userId,
                    'status' => 'PROPOSED', 'proposed_at' => now_str(),
                ]);
            } catch (\PDOException) {
                throw new ProcessError('sched.err_pending');
            }
            Notifier::scheduleProposed($req, Repo::find('schedule_change', ['change_id' => $cid]) ?? []);
            return $cid;
        });
    }

    /** 요청자 본인만 응답한다. AGREE 는 일정을 확정(plan_end)하고, REJECT/COUNTER 는 기존 일정을 유지한다. */
    public static function respond(int $rid, int $changeId, int $userId, string $decision, string $comment, string $counterEnd = ''): void
    {
        Db::tx(static function () use ($rid, $changeId, $userId, $decision, $comment, $counterEnd): void {
            $req = WorkflowService::request($rid);
            if ((int)$req['requester_id'] !== $userId) {
                throw new ProcessError('sched.err_forbidden');
            }
            $ch = Repo::find('schedule_change', ['change_id' => $changeId, 'request_id' => $rid]) ?? throw new ProcessError('process.err_not_found');
            if ((string)$ch['status'] !== 'PROPOSED') {
                throw new ProcessError('sched.err_not_pending');
            }
            $comment = trim($comment);
            if (mb_strlen($comment) > 1000) {
                throw new ProcessError('sched.err_comment_len');
            }
            $now = now_str();
            $status = match ($decision) {
                'AGREE' => 'AGREED',
                'REJECT' => 'REJECTED',
                'COUNTER' => 'COUNTERED',
                default => throw new ProcessError('sched.err_decision'),
            };
            $counter = null;
            if ($decision !== 'AGREE' && $comment === '') {
                throw new ProcessError('sched.err_comment_required');
            }
            if ($decision === 'COUNTER') {
                if (!Dates::isDate($counterEnd) || $counterEnd < date('Y-m-d') || $counterEnd >= (string)$ch['new_end']) {
                    throw new ProcessError('sched.err_counter_date', ['date' => (string)$ch['new_end']]);
                }
                $counter = $counterEnd;
            }
            Repo::insert('schedule_agreement', [
                'change_id' => $changeId, 'user_id' => $userId, 'decision' => $decision,
                'comment' => $comment === '' ? null : $comment, 'counter_end' => $counter, 'decided_at' => $now,
            ]);
            Repo::update('schedule_change', ['change_id' => $changeId], ['status' => $status, 'resolved_at' => $now]);
            if ($decision === 'AGREE') {
                Repo::update('request', ['request_id' => $rid], ['plan_end' => (string)$ch['new_end'], 'updated_at' => $now]);
            }
            Notifier::scheduleResponded($req, $ch, $decision, $comment, $counter, $userId);
        });
    }

    /** 제안자 본인이 협의 대기 중인 제안을 철회한다. */
    public static function withdraw(int $rid, int $changeId, int $userId): void
    {
        Db::tx(static function () use ($rid, $changeId, $userId): void {
            WorkflowService::request($rid);
            $ch = Repo::find('schedule_change', ['change_id' => $changeId, 'request_id' => $rid]) ?? throw new ProcessError('process.err_not_found');
            if ((int)$ch['proposer_id'] !== $userId) {
                throw new ProcessError('sched.err_not_proposer');
            }
            if ((string)$ch['status'] !== 'PROPOSED') {
                throw new ProcessError('sched.err_not_pending');
            }
            Repo::update('schedule_change', ['change_id' => $changeId], ['status' => 'WITHDRAWN', 'resolved_at' => now_str()]);
        });
    }

    /** 요청이 취소될 때 협의 대기 건을 정리한다. */
    public static function withdrawPending(int $rid): void
    {
        Repo::update('schedule_change', ['request_id' => $rid, 'status' => 'PROPOSED'], ['status' => 'WITHDRAWN', 'resolved_at' => now_str()]);
    }

    /** @return list<array<string,mixed>> 최신 제안 우선, 응답 목록 포함 */
    public static function history(int $rid): array
    {
        $rows = Repo::query(
            'SELECT sc.*, u.user_nm AS proposer_nm, rs.step_nm
               FROM schedule_change sc
               JOIN user u ON u.company_cd = sc.company_cd AND u.user_id = sc.proposer_id
               LEFT JOIN request_step rs ON rs.company_cd = sc.company_cd AND rs.request_id = sc.request_id AND rs.step_no = sc.step_no
              WHERE sc.company_cd = :company_cd AND sc.request_id = :r ORDER BY sc.change_id DESC',
            ['r' => $rid]
        );
        foreach ($rows as &$r) {
            $r['agreements'] = Repo::query(
                'SELECT a.*, u.user_nm FROM schedule_agreement a JOIN user u ON u.company_cd = a.company_cd AND u.user_id = a.user_id
                  WHERE a.company_cd = :company_cd AND a.change_id = :c ORDER BY a.agreement_id',
                ['c' => (int)$r['change_id']]
            );
        }
        return $rows;
    }

    private static function assertCanPropose(array $req, array $svc, int $userId): void
    {
        if ((string)$req['status'] !== WorkflowService::ST_PROGRESS || $req['current_step_no'] === null) {
            throw new ProcessError('sched.err_state');
        }
        if ((int)$req['requester_id'] === $userId) {
            throw new ProcessError('sched.err_requester_self');
        }
        $def = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => (int)$req['current_step_no']]);
        if ($def === null || !ActorResolver::isActor($def, $req, $svc, $userId)) {
            throw new ProcessError('sched.err_not_actor');
        }
        if (self::pending((int)$req['request_id']) !== null) {
            throw new ProcessError('sched.err_pending');
        }
    }
}
