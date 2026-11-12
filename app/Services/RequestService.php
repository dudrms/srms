<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Repo;

/** 요청 등록/수정/조회(목록, 상세, 타임라인, 댓글). */
final class RequestService
{
    public const DEFAULT_PAGE_SIZE = 20;

    /** 목록 한 페이지 건수(`.env` LIST_PAGE_SIZE, 5~200). */
    public static function pageSize(): int
    {
        return env_int('LIST_PAGE_SIZE', self::DEFAULT_PAGE_SIZE, 5, 200);
    }
    public const STATUSES = ['DRAFT', 'IN_PROGRESS', 'RETURNED', 'COMPLETED', 'CANCELED'];

    /** @param array<string,string> $in service_id, maint_type, title, content_html, priority, req_due_date */
    public static function save(?int $rid, int $userId, array $in): int
    {
        return (int)Db::tx(static function () use ($rid, $userId, $in): int {
            $title = trim((string)($in['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 200) {
                throw new ProcessError('request.err_title');
            }
            $content = HtmlSanitizer::clean((string)($in['content_html'] ?? ''));
            if (strlen($content) > 200000) {
                throw new ProcessError('request.err_content_len');
            }
            $due = trim((string)($in['req_due_date'] ?? ''));
            if (!Dates::isDate($due)) {
                throw new ProcessError('request.err_due_required');
            }
            $priority = trim((string)($in['priority'] ?? ''));
            if ($priority === '') {
                $priority = 'NORMAL';
            }
            if (Repo::find('code', ['group_cd' => 'PRIORITY', 'code_cd' => $priority, 'use_yn' => 1]) === null) {
                throw new ProcessError('request.err_priority');
            }
            $now = now_str();

            if ($rid === null) {
                [$sid, $type] = self::target($in);
                if ($due < date('Y-m-d')) {
                    throw new ProcessError('request.err_due');
                }
                $ver = WorkflowService::resolveVersionFor($sid, $type);
                return Repo::insert('request', [
                    'request_no' => self::nextNo(), 'service_id' => $sid, 'maint_type' => $type, 'title' => $title,
                    'content_html' => $content, 'priority' => $priority, 'version_id' => (int)$ver['version_id'],
                    'requester_id' => $userId, 'status' => WorkflowService::ST_DRAFT, 'req_due_date' => $due,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $req = WorkflowService::request($rid);
            if ((int)$req['requester_id'] !== $userId) {
                throw new ProcessError('request.err_forbidden');
            }
            $status = (string)$req['status'];
            if (!in_array($status, [WorkflowService::ST_DRAFT, WorkflowService::ST_RETURNED], true)) {
                throw new ProcessError('request.err_state');
            }
            $data = ['title' => $title, 'content_html' => $content, 'priority' => $priority, 'req_due_date' => $due, 'updated_at' => $now];
            if ($status === WorkflowService::ST_DRAFT) {
                [$sid, $type] = self::target($in);
                if ($due < date('Y-m-d')) {
                    throw new ProcessError('request.err_due');
                }
                $ver = WorkflowService::resolveVersionFor($sid, $type);
                $data += ['service_id' => $sid, 'maint_type' => $type, 'version_id' => (int)$ver['version_id']];
            }
            Repo::update('request', ['request_id' => $rid], $data);
            return $rid;
        });
    }

    /** @return array{0:int,1:string} */
    private static function target(array $in): array
    {
        $sid = ctype_digit((string)($in['service_id'] ?? '')) ? (int)$in['service_id'] : 0;
        $type = (string)($in['maint_type'] ?? '');
        if (Repo::find('service', ['service_id' => $sid, 'use_yn' => 1]) === null) {
            throw new ProcessError('request.err_service');
        }
        if ($type === WorklogService::TYPE || Repo::find('code', ['group_cd' => 'MAINT_TYPE', 'code_cd' => $type, 'use_yn' => 1]) === null) {
            throw new ProcessError('request.err_type');
        }
        return [$sid, $type];
    }

    public static function nextNo(): string
    {
        $ym = date('Ym');
        if (Repo::find('request_seq', ['ym' => $ym]) === null) {
            Repo::insert('request_seq', ['ym' => $ym, 'last_no' => 0]);
        }
        Repo::exec('UPDATE request_seq SET last_no = last_no + 1 WHERE company_cd = :company_cd AND ym = :ym', ['ym' => $ym]);
        $n = (int)Repo::queryVal('SELECT last_no FROM request_seq WHERE company_cd = :company_cd AND ym = :ym', ['ym' => $ym]);
        return sprintf('SR-%s-%04d', $ym, $n);
    }

    // ------------------------------------------------------------------ 목록

    /**
     * @param array<string,mixed> $user
     * @param list<string> $roles
     * @param list<string> $perms
     * @param array{scope?:string,status?:string,service_id?:int,q?:string,page?:int} $f
     * @return array{rows:list<array>,total:int,page:int,pages:int,scope:string}
     */
    public static function listFor(array $user, array $roles, array $perms, array $f): array
    {
        $scope = (string)($f['scope'] ?? 'mine');
        $allowed = self::scopes($perms, $user);
        if (!in_array($scope, $allowed, true)) {
            $scope = 'mine';
        }
        $uid = (int)$user['user_id'];
        $join = ' FROM request r JOIN service s ON s.company_cd = r.company_cd AND s.service_id = r.service_id'
            . ' JOIN user u ON u.company_cd = r.company_cd AND u.user_id = r.requester_id';
        $where = ['r.company_cd = :company_cd'];
        $p = [];
        switch ($scope) {
            case 'inbox':
                [$c, $ip] = RequestAccess::inbox($user, $roles, $perms);
                $join .= ' JOIN request_step rs ON rs.company_cd = r.company_cd AND rs.request_id = r.request_id AND rs.step_no = r.current_step_no AND rs.status = \'ACTIVE\''
                    . ' JOIN process_step ps ON ps.company_cd = r.company_cd AND ps.version_id = r.version_id AND ps.step_no = rs.step_no';
                $where[] = '(' . $c . " OR (r.status = 'RETURNED' AND r.requester_id = :u_uid)"
                    . " OR (r.requester_id = :u_uid AND EXISTS (SELECT 1 FROM schedule_change sc WHERE sc.company_cd = r.company_cd AND sc.request_id = r.request_id AND sc.status = 'PROPOSED')))";
                $p += $ip + ['u_uid' => $uid];
                break;
            case 'dept':
                $where[] = "r.status <> 'DRAFT' AND s.biz_dept_id IS NOT NULL AND s.biz_dept_id = :u_dept";
                $p['u_dept'] = (int)$user['dept_id'];
                break;
            case 'all':
                $where[] = "r.status <> 'DRAFT'";
                break;
            case 'cc':
                [$c, $cp] = RequestAccess::cc($user, $roles, $perms);
                $where[] = $c;
                $p += $cp;
                break;
            default:
                $where[] = 'r.requester_id = :u_uid';
                $p['u_uid'] = $uid;
        }
        $status = (string)($f['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'r.status = :f_status';
            $p['f_status'] = $status;
        }
        if (($f['service_id'] ?? 0) > 0) {
            $where[] = 'r.service_id = :f_service';
            $p['f_service'] = (int)$f['service_id'];
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(r.title LIKE :f_q OR r.request_no LIKE :f_q)';
            $p['f_q'] = '%' . $q . '%';
        }
        $w = implode(' AND ', $where);
        $total = (int)Repo::queryVal('SELECT COUNT(*)' . $join . ' WHERE ' . $w, $p);
        $size = self::pageSize();
        $pages = max(1, (int)ceil($total / $size));
        $page = min(max(1, (int)($f['page'] ?? 1)), $pages);
        $rows = Repo::query(
            'SELECT r.request_id, r.request_no, r.title, r.status, r.requester_id, r.current_step_no, r.req_due_date, r.plan_end, r.actual_end,
                    r.maint_type, r.priority, r.created_at, r.updated_at, r.reg_by, r.parent_id, s.service_cd, s.service_nm, u.user_nm AS requester_nm,
                    (SELECT x.step_nm FROM request_step x WHERE x.company_cd = r.company_cd AND x.request_id = r.request_id AND x.step_no = r.current_step_no) AS step_nm,
                    (SELECT COUNT(*) FROM schedule_change sc WHERE sc.company_cd = r.company_cd AND sc.request_id = r.request_id AND sc.status = \'PROPOSED\') AS sched_pending'
            . $join . ' WHERE ' . $w . ' ORDER BY r.updated_at DESC, r.request_id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size),
            $p
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'scope' => $scope];
    }

    /** @return list<string> */
    public static function scopes(array $perms, array $user): array
    {
        $s = ['mine'];
        if (in_array('STEP_ACT', $perms, true)) {
            $s[] = 'inbox';
        }
        if (in_array('REQUEST_VIEW_DEPT', $perms, true) && $user['dept_id'] !== null) {
            $s[] = 'dept';
        }
        if (in_array('REQUEST_VIEW_ALL', $perms, true)) {
            $s[] = 'all';
        }
        if (in_array('REQUEST_VIEW_CC', $perms, true)) {
            $s[] = 'cc';
        }
        return $s;
    }

    /** 처리 대기 건수(내 처리함). */
    public static function inboxCount(array $user, array $roles, array $perms): int
    {
        return in_array('STEP_ACT', $perms, true) ? self::listFor($user, $roles, $perms, ['scope' => 'inbox'])['total'] : 0;
    }

    // ------------------------------------------------------------------ 상세

    /** 단계 정의 + 진행 상태 + 처리자 이름. @return list<array<string,mixed>> */
    public static function stepper(array $req, array $svc): array
    {
        $rows = Repo::query(
            'SELECT ps.*, rs.status AS rs_status, rs.round AS rs_round, rs.due_at, rs.started_at, rs.ended_at, rs.step_nm AS rs_nm
               FROM process_step ps
               LEFT JOIN request_step rs ON rs.company_cd = ps.company_cd AND rs.request_id = :rid AND rs.step_no = ps.step_no
              WHERE ps.company_cd = :company_cd AND ps.version_id = :v ORDER BY ps.step_no',
            ['rid' => (int)$req['request_id'], 'v' => (int)$req['version_id']]
        );
        foreach ($rows as &$r) {
            $names = [];
            foreach (array_slice(ActorResolver::userIds($r, $req, $svc), 0, 5) as $uid) {
                $u = Repo::find('user', ['user_id' => $uid]);
                if ($u !== null) {
                    $names[] = (string)$u['user_nm'];
                }
            }
            $r['actor_names'] = $names;
            $r['state'] = $r['rs_status'] ?? 'WAITING';
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> 시간순 이력(승인/반려/제출/취소, 댓글, 첨부) */
    public static function timeline(int $rid): array
    {
        $ev = [];
        $req = WorkflowService::request($rid);
        $isLog = WorklogService::isLog($req);
        $isChild = $req['parent_id'] !== null;
        $creator = Repo::find('user', ['user_id' => (int)($isLog ? $req['reg_by'] : ($isChild ? $req['split_by'] : $req['requester_id']))]);
        $parent = $isChild ? Repo::find('request', ['request_id' => (int)$req['parent_id']]) : null;
        $ev[] = [
            'at' => (string)$req['created_at'], 'ord' => 0, 'id' => 0, 'kind' => $isLog ? 'WORKLOG' : ($isChild ? 'SPLIT_FROM' : 'CREATED'),
            'user' => (string)($creator['user_nm'] ?? ''), 'step_no' => null, 'step_nm' => null, 'action' => null, 'text' => $parent === null ? null : (string)$parent['request_no'],
        ];
        foreach (Repo::list('request', ['parent_id' => $rid], 'request_id') as $c) {
            $by = Repo::find('user', ['user_id' => (int)$c['split_by']]);
            $ev[] = ['at' => (string)$c['created_at'], 'ord' => 5, 'id' => (int)$c['request_id'], 'kind' => 'SPLIT', 'user' => (string)($by['user_nm'] ?? ''), 'step_no' => null, 'step_nm' => null, 'action' => null, 'text' => (string)$c['request_no']];
        }
        foreach (Repo::query(
            "SELECT a.audit_id, a.created_at, a.detail, u.user_nm FROM audit_log a LEFT JOIN user u ON u.company_cd = a.company_cd AND u.user_id = a.user_id
              WHERE a.company_cd = :company_cd AND a.action = 'REQUEST_ASSIGN' AND a.target = :t",
            ['t' => (string)$req['request_no']]
        ) as $a) {
            $to = ctype_digit((string)$a['detail']) ? Repo::find('user', ['user_id' => (int)$a['detail']]) : null;
            $ev[] = ['at' => (string)$a['created_at'], 'ord' => 5, 'id' => (int)$a['audit_id'], 'kind' => 'ASSIGN', 'user' => (string)($a['user_nm'] ?? ''), 'step_no' => null, 'step_nm' => null, 'action' => null, 'text' => $to === null ? null : (string)$to['user_nm']];
        }
        foreach ($isLog ? [] : Repo::query(
            'SELECT a.approval_id, a.acted_at, a.action, a.opinion, u.user_nm, rs.step_no, rs.step_nm
               FROM approval a JOIN request_step rs ON rs.company_cd = a.company_cd AND rs.rstep_id = a.rstep_id
               JOIN user u ON u.company_cd = a.company_cd AND u.user_id = a.actor_id
              WHERE a.company_cd = :company_cd AND rs.request_id = :rid',
            ['rid' => $rid]
        ) as $a) {
            $ev[] = ['at' => (string)$a['acted_at'], 'ord' => 1, 'id' => (int)$a['approval_id'], 'kind' => 'APPROVAL', 'user' => (string)$a['user_nm'],
                'step_no' => (int)$a['step_no'], 'step_nm' => (string)$a['step_nm'], 'action' => (string)$a['action'], 'text' => $a['opinion']];
        }
        foreach (Repo::query(
            'SELECT c.comment_id, c.created_at, c.body, u.user_nm FROM comment c JOIN user u ON u.company_cd = c.company_cd AND u.user_id = c.user_id
              WHERE c.company_cd = :company_cd AND c.request_id = :rid',
            ['rid' => $rid]
        ) as $c) {
            $ev[] = ['at' => (string)$c['created_at'], 'ord' => 2, 'id' => (int)$c['comment_id'], 'kind' => 'COMMENT', 'user' => (string)$c['user_nm'],
                'step_no' => null, 'step_nm' => null, 'action' => null, 'text' => (string)$c['body']];
        }
        foreach (Repo::query(
            'SELECT t.attach_id, t.uploaded_at, t.orig_name, t.step_no, u.user_nm FROM attachment t JOIN user u ON u.company_cd = t.company_cd AND u.user_id = t.uploaded_by
              WHERE t.company_cd = :company_cd AND t.request_id = :rid',
            ['rid' => $rid]
        ) as $t) {
            $ev[] = ['at' => (string)$t['uploaded_at'], 'ord' => 3, 'id' => (int)$t['attach_id'], 'kind' => 'ATTACH', 'user' => (string)$t['user_nm'],
                'step_no' => (int)$t['step_no'], 'step_nm' => null, 'action' => null, 'text' => (string)$t['orig_name']];
        }
        $reasons = array_column(Repo::list('code', ['group_cd' => ScheduleService::REASON_GROUP]), 'code_nm', 'code_cd');
        foreach (ScheduleService::history($rid) as $c) {
            $cid = (int)$c['change_id'];
            $reason = master_name('CODE', ScheduleService::REASON_GROUP . ':' . $c['reason_cd'], (string)($reasons[$c['reason_cd']] ?? $c['reason_cd']));
            $ev[] = ['at' => (string)$c['proposed_at'], 'ord' => 4, 'id' => $cid * 10, 'kind' => 'SCHEDULE', 'user' => (string)$c['proposer_nm'],
                'step_no' => $c['step_no'] === null ? null : (int)$c['step_no'], 'step_nm' => null, 'action' => 'PROPOSE',
                'text' => fmt_date((string)$c['prev_end']) . ' → ' . fmt_date((string)$c['new_end']) . ' · ' . $reason . ': ' . $c['reason_text']];
            foreach ($c['agreements'] as $i => $a) {
                $text = (string)($a['comment'] ?? '');
                if ($a['counter_end'] !== null) {
                    $text = fmt_date((string)$a['counter_end']) . ($text !== '' ? ' · ' . $text : '');
                }
                $ev[] = ['at' => (string)$a['decided_at'], 'ord' => 4, 'id' => $cid * 10 + 1 + $i, 'kind' => 'SCHEDULE', 'user' => (string)$a['user_nm'],
                    'step_no' => null, 'step_nm' => null, 'action' => (string)$a['decision'], 'text' => $text === '' ? null : $text];
            }
            if ((string)$c['status'] === 'WITHDRAWN' && $c['resolved_at'] !== null) {
                $ev[] = ['at' => (string)$c['resolved_at'], 'ord' => 4, 'id' => $cid * 10 + 9, 'kind' => 'SCHEDULE', 'user' => (string)$c['proposer_nm'],
                    'step_no' => null, 'step_nm' => null, 'action' => 'WITHDRAW', 'text' => null];
            }
        }
        usort($ev, static fn(array $a, array $b): int => [$a['at'], $a['ord'], $a['id']] <=> [$b['at'], $b['ord'], $b['id']]);
        return $ev;
    }

    /** @return list<array<string,mixed>> */
    public static function attachments(int $rid): array
    {
        return Repo::query(
            'SELECT t.*, u.user_nm FROM attachment t JOIN user u ON u.company_cd = t.company_cd AND u.user_id = t.uploaded_by
              WHERE t.company_cd = :company_cd AND t.request_id = :rid ORDER BY t.step_no, t.attach_id',
            ['rid' => $rid]
        );
    }

    /** 지금 이 사용자가 처리 가능한지(이번 회차 미처리, 일정 협의 잠금 아님). */
    public static function canAct(array $req, array $svc, int $userId): bool
    {
        if ((string)$req['status'] !== WorkflowService::ST_PROGRESS || $req['current_step_no'] === null) {
            return false;
        }
        $def = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => (int)$req['current_step_no']]);
        $rs = Repo::find('request_step', ['request_id' => (int)$req['request_id'], 'step_no' => (int)$req['current_step_no']]);
        if ($def === null || $rs === null || !ActorResolver::isActor($def, $req, $svc, $userId)) {
            return false;
        }
        return Repo::count('approval', ['rstep_id' => $rs['rstep_id'], 'actor_id' => $userId, 'round' => (int)$rs['round']]) === 0;
    }

    public static function isLocked(int $rid): bool
    {
        return Repo::count('schedule_change', ['request_id' => $rid, 'status' => 'PROPOSED']) > 0;
    }

    /** 투입시간(시간, 소수 둘째 자리)은 진행/완료 건에서 작업(WORK) 단계 처리자만 입력한다. */
    public static function canSetEffort(array $req, array $svc, int $userId): bool
    {
        if (!in_array((string)$req['status'], [WorkflowService::ST_PROGRESS, WorkflowService::ST_DONE], true) || WorklogService::isLog($req)) {
            return false;
        }
        foreach (Repo::list('process_step', ['version_id' => (int)$req['version_id'], 'step_type' => 'WORK']) as $def) {
            if (ActorResolver::isActor($def, $req, $svc, $userId)) {
                return true;
            }
        }
        return false;
    }

    public static function setEffort(int $rid, int $userId, string $value): void
    {
        $req = WorkflowService::request($rid);
        if (!self::canSetEffort($req, WorkflowService::service($req), $userId)) {
            throw new ProcessError('request.err_effort_forbidden');
        }
        $hours = Effort::parse($value);
        if ($hours === null) {
            throw new ProcessError('request.err_effort');
        }
        Repo::update('request', ['request_id' => $rid], ['effort_hours' => $hours, 'updated_at' => now_str()]);
    }

    public static function addComment(int $rid, int $userId, string $body): void
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            throw new ProcessError('request.err_comment');
        }
        $req = WorkflowService::request($rid);
        if ((string)$req['status'] === WorkflowService::ST_DRAFT) {
            throw new ProcessError('request.err_state');
        }
        Repo::insert('comment', ['request_id' => $rid, 'user_id' => $userId, 'body' => $body, 'created_at' => now_str()]);
    }
}
