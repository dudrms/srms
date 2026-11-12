<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Repo;

/** 요청별 IT 담당자 지정과 SR 분할(같은 서비스·유형으로 새 SR 1건을 만들어 현재 단계에서 이어 진행). */
final class SplitService
{
    /** 분할할 수 있는 현재 단계 유형(접수·검수·종료 단계는 제외) */
    public const STEP_TYPES = ['REVIEW', 'AGREE', 'APPROVE', 'WORK'];

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} [현재 단계 정의, 현재 request_step] */
    private static function current(array $req): array
    {
        if ((string)$req['status'] !== WorkflowService::ST_PROGRESS || WorklogService::isLog($req) || $req['current_step_no'] === null) {
            throw new ProcessError('split.err_state');
        }
        $no = (int)$req['current_step_no'];
        $def = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => $no]) ?? throw new ProcessError('process.err_not_found');
        $rs = Repo::find('request_step', ['request_id' => (int)$req['request_id'], 'step_no' => $no]) ?? throw new ProcessError('process.err_not_found');
        return [$def, $rs];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private static function checkSplit(array $req): array
    {
        [$def, $rs] = self::current($req);
        if (!in_array((string)$def['step_type'], self::STEP_TYPES, true) || (string)$rs['status'] !== 'ACTIVE') {
            throw new ProcessError('split.err_step');
        }
        if (RequestService::isLocked((int)$req['request_id'])) {
            throw new ProcessError('request.err_schedule_locked');
        }
        return [$def, $rs];
    }

    public static function canAssign(array $req): bool
    {
        try {
            self::current($req);
            return true;
        } catch (ProcessError) {
            return false;
        }
    }

    public static function canSplit(array $req): bool
    {
        try {
            self::checkSplit($req);
            return true;
        } catch (ProcessError) {
            return false;
        }
    }

    /** 요청별 담당자가 있으면 그 사용자, 없으면 서비스 전산 담당자. @return array<string,mixed>|null */
    public static function assignee(array $req, array $svc): ?array
    {
        $id = $req['it_assignee_id'] ?? $svc['it_owner_id'];
        return $id === null ? null : Repo::find('user', ['user_id' => (int)$id]);
    }

    /** @param int|null $assigneeId null 이면 서비스 전산 담당자로 되돌린다. */
    public static function assign(int $rid, int $byUserId, ?int $assigneeId): void
    {
        Db::tx(static function () use ($rid, $byUserId, $assigneeId): void {
            $req = WorkflowService::request($rid);
            [$def, $rs] = self::current($req);
            if ($assigneeId !== null && Repo::find('user', ['user_id' => $assigneeId, 'use_yn' => 1]) === null) {
                throw new ProcessError('split.err_assignee');
            }
            $current = $req['it_assignee_id'] === null ? null : (int)$req['it_assignee_id'];
            if ($current === $assigneeId) {
                return;
            }
            Repo::update('request', ['request_id' => $rid], ['it_assignee_id' => $assigneeId, 'updated_at' => now_str()]);
            self::notifyAssigned($rid, $def, $rs, $assigneeId, $byUserId);
        });
    }

    /**
     * 원본은 그대로 두고 같은 서비스·유형의 새 SR 을 만든다. 새 SR 은 원본이 진행 중인 단계에서 이어 시작한다.
     * @param array<string,string> $in title, note, new_assignee_id, orig_assignee_id(선택: 비우면 변경 없음)
     * @return int 새 request_id
     */
    public static function split(int $rid, int $byUserId, array $in): int
    {
        return (int)Db::tx(static function () use ($rid, $byUserId, $in): int {
            $req = WorkflowService::request($rid);
            [$def, $rs] = self::checkSplit($req);
            $title = trim((string)($in['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 200) {
                throw new ProcessError('request.err_title');
            }
            $note = trim((string)($in['note'] ?? ''));
            if ($note === '' || mb_strlen($note) > 1000) {
                throw new ProcessError('split.err_note');
            }
            $new = self::activeUser((string)($in['new_assignee_id'] ?? '')) ?? throw new ProcessError('split.err_assignee');
            $origRaw = trim((string)($in['orig_assignee_id'] ?? ''));
            $orig = null;
            if ($origRaw !== '') {
                $orig = self::activeUser($origRaw) ?? throw new ProcessError('split.err_assignee');
            }
            $content = HtmlSanitizer::clean('<p><strong>' . e(t('split.note_label')) . '</strong> ' . e($note) . '</p>' . $req['content_html']);
            if (strlen($content) > 200000) {
                throw new ProcessError('request.err_content_len');
            }

            $now = now_str();
            $cur = (int)$req['current_step_no'];
            $child = Repo::insert('request', [
                'request_no' => RequestService::nextNo(), 'service_id' => (int)$req['service_id'], 'maint_type' => $req['maint_type'],
                'title' => $title, 'content_html' => $content, 'priority' => $req['priority'], 'version_id' => (int)$req['version_id'],
                'requester_id' => (int)$req['requester_id'], 'it_assignee_id' => $new, 'status' => WorkflowService::ST_PROGRESS,
                'request_date' => $req['request_date'] ?? substr($now, 0, 10),
                'current_step_no' => $cur, 'req_due_date' => $req['req_due_date'], 'plan_start' => $req['plan_start'], 'plan_end' => $req['plan_end'],
                'parent_id' => $rid, 'split_by' => $byUserId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (Repo::list('request_step', ['request_id' => $rid], 'step_no') as $s) {
                $no = (int)$s['step_no'];
                $row = [
                    'request_id' => $child, 'step_no' => $no, 'step_nm' => $s['step_nm'],
                    'status' => $no < $cur ? 'DONE' : ($no === $cur ? 'ACTIVE' : 'WAITING'), 'round' => $no <= $cur ? 1 : 0,
                ];
                if ($no === $cur) {
                    $row += ['started_at' => $now, 'due_at' => $rs['due_at']];
                }
                Repo::insert('request_step', $row);
            }

            $svc = WorkflowService::service($req);
            $childReq = WorkflowService::request($child);
            Notifier::transition($childReq, $svc, null, $def, $byUserId);
            Notifier::split($req, $childReq, $byUserId);
            if ($orig !== null && $orig !== ($req['it_assignee_id'] === null ? null : (int)$req['it_assignee_id'])) {
                Repo::update('request', ['request_id' => $rid], ['it_assignee_id' => $orig, 'updated_at' => $now]);
                self::notifyAssigned($rid, $def, $rs, $orig, $byUserId);
            }
            return $child;
        });
    }

    private static function activeUser(string $v): ?int
    {
        $id = ctype_digit($v) ? (int)$v : 0;
        return $id > 0 && Repo::find('user', ['user_id' => $id, 'use_yn' => 1]) !== null ? $id : null;
    }

    private static function notifyAssigned(int $rid, array $def, array $rs, ?int $assigneeId, int $byUserId): void
    {
        if ($assigneeId === null || $assigneeId === $byUserId) {
            return;
        }
        $req = WorkflowService::request($rid);
        if (ActorResolver::isActor($def, $req, WorkflowService::service($req), $assigneeId)) {
            Notifier::assigned($req, $def, $rs, $assigneeId, $byUserId);
        }
    }
}
