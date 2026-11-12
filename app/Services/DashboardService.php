<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Repo;

/**
 * 롤별 대시보드 데이터(설계서 8.2). 위젯 범위는 권한으로 결정한다.
 * 지연: 진행 중(보완요청 포함) 건의 유효 완료일(합의 계획일 우선, 없으면 완료요구일)이 오늘보다 이전. 임박: 오늘부터 SOON_DAYS 일 이내.
 */
final class DashboardService
{
    public const LIMIT = 8;
    public const SOON_DAYS = 3;
    private const OPEN = "('IN_PROGRESS', 'RETURNED')";

    private const SEL = "SELECT r.request_id, r.request_no, r.title, r.status, r.req_due_date, r.plan_end, r.current_step_no,
            COALESCE(r.plan_end, r.req_due_date) AS due, rs.step_nm AS step_nm, rs.due_at AS step_due,
            s.service_cd, s.service_nm, u.user_nm AS requester_nm, ow.user_nm AS owner_nm,
            (SELECT COUNT(*) FROM schedule_change sc WHERE sc.company_cd = r.company_cd AND sc.request_id = r.request_id AND sc.status = 'PROPOSED') AS sched_pending";
    private const FROM = " FROM request r
            JOIN service s ON s.company_cd = r.company_cd AND s.service_id = r.service_id
            JOIN user u ON u.company_cd = r.company_cd AND u.user_id = r.requester_id
            LEFT JOIN user ow ON ow.company_cd = s.company_cd AND ow.user_id = COALESCE(r.it_assignee_id, s.it_owner_id)
            LEFT JOIN request_step rs ON rs.company_cd = r.company_cd AND rs.request_id = r.request_id AND rs.step_no = r.current_step_no";

    /**
     * @param array<string,mixed> $user
     * @param list<string> $roles
     * @param list<string> $perms
     * @return array<string,mixed>
     */
    public static function build(array $user, array $roles, array $perms, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $uid = (int)$user['user_id'];
        $d = [
            'today' => $today,
            'mine' => self::mine($uid),
            'actions' => self::actions($user, $roles, $perms),
            'open' => self::openMine($uid),
            'mails' => Repo::query(
                'SELECT noti_id, request_id, subject, created_at, status FROM notification_log
                  WHERE company_cd = :company_cd AND to_user_id = :u ORDER BY noti_id DESC LIMIT 5',
                ['u' => $uid]
            ),
        ];
        if (in_array('REQUEST_VIEW_DEPT', $perms, true) && $user['dept_id'] !== null) {
            $d['dept'] = self::scoped("r.status <> 'DRAFT' AND s.biz_dept_id = :dept", ['dept' => (int)$user['dept_id']], $today, false);
        }
        if (in_array('REQUEST_VIEW_ALL', $perms, true)) {
            $d['all'] = self::scoped("r.status <> 'DRAFT'", [], $today, true);
        }
        return $d;
    }

    /** @return array<string,int> 내 요청 상태별 건수 */
    private static function mine(int $uid): array
    {
        $out = array_fill_keys(RequestService::STATUSES, 0);
        foreach (Repo::query(
            'SELECT status, COUNT(*) AS n FROM request WHERE company_cd = :company_cd AND requester_id = :u GROUP BY status',
            ['u' => $uid]
        ) as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** 내가 지금 조치해야 하는 건: 단계 처리 대기 + 보완요청 + 일정 협의 회신. @return array{total:int,rows:list<array<string,mixed>>} */
    private static function actions(array $user, array $roles, array $perms): array
    {
        $res = RequestService::listFor($user, $roles, $perms, ['scope' => 'inbox']);
        $uid = (int)$user['user_id'];
        $rows = [];
        foreach (array_slice($res['rows'], 0, self::LIMIT) as $r) {
            $r['kind'] = match (true) {
                (string)$r['status'] === 'RETURNED' => 'return',
                (int)$r['sched_pending'] > 0 && (int)$r['requester_id'] === $uid => 'schedule',
                default => 'step',
            };
            $rows[] = $r;
        }
        return ['total' => $res['total'], 'rows' => $rows];
    }

    /** 진행 중인 내 요청의 단계 진행 요약. @return list<array<string,mixed>> */
    private static function openMine(int $uid): array
    {
        $rows = Repo::query(
            "SELECT r.request_id, r.request_no, r.title, r.status, r.version_id, r.current_step_no, r.service_id,
                    COALESCE(r.plan_end, r.req_due_date) AS due, rs.step_nm, rs.due_at AS step_due,
                    (SELECT COUNT(*) FROM request_step x WHERE x.company_cd = r.company_cd AND x.request_id = r.request_id) AS total_steps,
                    (SELECT COUNT(*) FROM request_step x WHERE x.company_cd = r.company_cd AND x.request_id = r.request_id AND x.status = 'DONE') AS done_steps
               FROM request r
               LEFT JOIN request_step rs ON rs.company_cd = r.company_cd AND rs.request_id = r.request_id AND rs.step_no = r.current_step_no
              WHERE r.company_cd = :company_cd AND r.requester_id = :u AND r.status IN " . self::OPEN . '
              ORDER BY r.updated_at DESC, r.request_id DESC LIMIT 5',
            ['u' => $uid]
        );
        foreach ($rows as &$r) {
            $req = WorkflowService::request((int)$r['request_id']);
            $def = $r['current_step_no'] === null ? null : Repo::find('process_step', ['version_id' => (int)$r['version_id'], 'step_no' => (int)$r['current_step_no']]);
            $names = [];
            if ($def !== null) {
                foreach (array_slice(ActorResolver::userIds($def, $req, WorkflowService::service($req)), 0, 3) as $id) {
                    $names[] = (string)(Repo::find('user', ['user_id' => $id])['user_nm'] ?? '');
                }
            }
            $r['actor_names'] = $names;
            $r['step_id'] = $def['step_id'] ?? null;
        }
        return $rows;
    }

    /**
     * 범위(주관부서/전체) 현황.
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function scoped(string $scope, array $p, string $today, bool $full): array
    {
        $soon = date('Y-m-d', strtotime($today . ' +' . self::SOON_DAYS . ' day'));
        $open = 'r.status IN ' . self::OPEN;
        $due = 'COALESCE(r.plan_end, r.req_due_date)';
        $sched = "EXISTS (SELECT 1 FROM schedule_change sc WHERE sc.company_cd = r.company_cd AND sc.request_id = r.request_id AND sc.status = 'PROPOSED')";
        $base = ' WHERE r.company_cd = :company_cd AND ' . $scope;
        $count = static fn(string $cond, array $extra = []): int => (int)Repo::queryVal(
            'SELECT COUNT(*)' . self::FROM . $base . ' AND ' . $cond, $p + $extra
        );
        $list = static fn(string $cond, string $order, array $extra = [], int $limit = self::LIMIT): array => Repo::query(
            self::SEL . self::FROM . $base . ' AND ' . $cond . ' ORDER BY ' . $order . ' LIMIT ' . $limit, $p + $extra
        );

        $status = array_fill_keys(RequestService::STATUSES, 0);
        foreach (Repo::query('SELECT r.status, COUNT(*) AS n' . self::FROM . $base . ' GROUP BY r.status', $p) as $r) {
            $status[(string)$r['status']] = (int)$r['n'];
        }
        $out = [
            'status' => $status,
            'byStep' => Repo::query(
                'SELECT rs.step_nm AS step_nm, MIN(rs.step_no) AS sno, COUNT(*) AS n' . self::FROM . $base
                . " AND r.status = 'IN_PROGRESS' GROUP BY rs.step_nm ORDER BY sno", $p
            ),
            'lateCount' => $count($open . ' AND ' . $due . ' < :today', ['today' => $today]),
            'late' => $list($open . ' AND ' . $due . ' < :today', $due, ['today' => $today]),
            'soonCount' => $count($open . ' AND ' . $due . ' BETWEEN :today AND :soon', ['today' => $today, 'soon' => $soon]),
            'soon' => $list($open . ' AND ' . $due . ' BETWEEN :today AND :soon', $due, ['today' => $today, 'soon' => $soon]),
            'schedCount' => $count($sched),
            'sched' => $list($sched, 'r.updated_at DESC'),
        ];
        if (!$full) {
            return $out;
        }
        $out['newCount'] = $count("r.status = 'IN_PROGRESS' AND r.current_step_no = 2");
        $out['new'] = $list("r.status = 'IN_PROGRESS' AND r.current_step_no = 2", 'r.updated_at DESC, r.request_id DESC', [], 5);
        $out['byService'] = Repo::query(
            'SELECT s.service_cd, s.service_nm, COUNT(*) AS n' . self::FROM . $base
            . " AND r.status = 'IN_PROGRESS' GROUP BY s.service_id ORDER BY n DESC, s.service_cd", $p
        );
        $out['workload'] = Repo::query(
            'SELECT ow.user_nm AS owner_nm, COUNT(*) AS n, SUM(CASE WHEN ' . $due . ' < :today THEN 1 ELSE 0 END) AS late' . self::FROM . $base
            . " AND r.status = 'IN_PROGRESS' GROUP BY ow.user_id ORDER BY n DESC, ow.user_nm", $p + ['today' => $today]
        );
        $out['kanban'] = self::kanban($base, $p, $due);
        return $out;
    }

    /** 단계별 칸반: 단계명 컬럼마다 건수와 임박 순 카드 5장. @return list<array{step_nm:string,n:int,cards:list<array<string,mixed>>}> */
    private static function kanban(string $base, array $p, string $due): array
    {
        $cols = [];
        foreach (Repo::query(
            'SELECT rs.step_nm AS step_nm, MIN(rs.step_no) AS sno, COUNT(*) AS n' . self::FROM . $base
            . " AND r.status = 'IN_PROGRESS' GROUP BY rs.step_nm ORDER BY sno", $p
        ) as $c) {
            $cols[(string)$c['step_nm']] = ['step_nm' => (string)$c['step_nm'], 'n' => (int)$c['n'], 'cards' => []];
        }
        $rows = Repo::query(
            'SELECT * FROM (' . self::SEL . ', ROW_NUMBER() OVER (PARTITION BY rs.step_nm ORDER BY ' . $due . ', r.request_id) AS rn'
            . self::FROM . $base . " AND r.status = 'IN_PROGRESS') WHERE rn <= 5 ORDER BY rn", $p
        );
        foreach ($rows as $r) {
            $cols[(string)$r['step_nm']]['cards'][] = $r;
        }
        return array_values($cols);
    }
}
