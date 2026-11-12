<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Repo;

/** 요청 조회 범위(SQL 조건) 생성. 별칭: r=request, s=service. */
final class RequestAccess
{
    /**
     * @param array<string,mixed> $user
     * @param list<string> $roles
     * @param list<string> $perms
     * @return array{0:array<string,mixed>,1:list<string>,2:list<string>} [params, roles, perms]
     */
    private static function params(array $user, array $roles, array $perms): array
    {
        $p = [
            'u_uid' => (int)$user['user_id'],
            'u_uids' => (string)(int)$user['user_id'],
            'u_dept' => $user['dept_id'] === null ? null : (int)$user['dept_id'],
            'u_depts' => $user['dept_id'] === null ? null : (string)(int)$user['dept_id'],
            'u_biz' => in_array('BIZ_IT', $roles, true) ? 1 : 0,
            'u_vall' => in_array('REQUEST_VIEW_ALL', $perms, true) ? 1 : 0,
            'u_vdept' => in_array('REQUEST_VIEW_DEPT', $perms, true) ? 1 : 0,
            'u_vcc' => in_array('REQUEST_VIEW_CC', $perms, true) ? 1 : 0,
        ];
        foreach (array_values($roles) as $i => $r) {
            $p['u_role' . $i] = $r;
        }
        return [$p, $roles, $perms];
    }

    private static function roleIn(string $col, array $roles): string
    {
        if ($roles === []) {
            return '0 = 1';
        }
        $ph = [];
        foreach (array_keys(array_values($roles)) as $i) {
            $ph[] = ':u_role' . $i;
        }
        return $col . ' IN (' . implode(',', $ph) . ')';
    }

    /** 사용자가 단계 정의($ps)의 처리자인지 판정하는 SQL 조건. */
    public static function actorExpr(string $ps, array $roles): string
    {
        return '((' . $ps . ".actor_type = 'ROLE' AND " . self::roleIn($ps . '.actor_value', $roles) . ')'
            . ' OR (' . $ps . ".actor_type = 'USER' AND " . $ps . '.actor_value = :u_uids)'
            . ' OR (' . $ps . ".actor_type = 'REQUESTER' AND r.requester_id = :u_uid)"
            . ' OR (' . $ps . ".actor_type = 'SERVICE_IT_OWNER' AND COALESCE(r.it_assignee_id, s.it_owner_id) = :u_uid)"
            . ' OR (' . $ps . ".actor_type = 'SERVICE_BIZ_DEPT' AND :u_biz = 1 AND s.biz_dept_id IS NOT NULL AND s.biz_dept_id = :u_dept)"
            . ' OR ' . self::bizOwnerCond($ps . '.actor_type', 'SERVICE_BIZ_OWNER') . ')';
    }

    /**
     * 현업 IT 담당자 유형 판정: 지정된 자격자(활성·BIZ_IT·주관부서 소속)가 있으면 그 사람만, 없으면 주관부서 BIZ_IT 전원.
     * 규칙은 ActorResolver::eligibleBizOwners/bizOwnerIds 와 같아야 한다.
     */
    private static function bizOwnerCond(string $typeCol, string $type): string
    {
        return '(' . $typeCol . " = '" . $type . "' AND :u_biz = 1 AND s.biz_dept_id IS NOT NULL AND s.biz_dept_id = :u_dept AND ("
            . 'EXISTS (SELECT 1 FROM service_biz_it sb1 WHERE sb1.company_cd = s.company_cd AND sb1.service_id = s.service_id AND sb1.user_id = :u_uid)'
            . ' OR NOT EXISTS (SELECT 1 FROM service_biz_it sb2'
            . ' JOIN user su ON su.company_cd = sb2.company_cd AND su.user_id = sb2.user_id AND su.use_yn = 1 AND su.dept_id = s.biz_dept_id'
            . " JOIN user_role sr ON sr.company_cd = su.company_cd AND sr.user_id = su.user_id AND sr.role_cd = 'BIZ_IT'"
            . ' WHERE sb2.company_cd = s.company_cd AND sb2.service_id = s.service_id)))';
    }

    /** 참조자 정의($c)에 해당하는지 판정하는 SQL 조건. */
    private static function ccExpr(string $c, array $roles): string
    {
        return '((' . $c . ".cc_type = 'ROLE' AND " . self::roleIn($c . '.cc_value', $roles) . ')'
            . ' OR (' . $c . ".cc_type = 'USER' AND " . $c . '.cc_value = :u_uids)'
            . ' OR (' . $c . ".cc_type = 'DEPT' AND " . $c . '.cc_value = :u_depts)'
            . ' OR (' . $c . ".cc_type = 'REQUESTER' AND r.requester_id = :u_uid)"
            . ' OR (' . $c . ".cc_type = 'SERVICE_IT_OWNER' AND COALESCE(r.it_assignee_id, s.it_owner_id) = :u_uid)"
            . ' OR (' . $c . ".cc_type = 'SERVICE_BIZ_DEPT' AND :u_biz = 1 AND s.biz_dept_id IS NOT NULL AND s.biz_dept_id = :u_dept)"
            . ' OR ' . self::bizOwnerCond($c . '.cc_type', 'SERVICE_BIZ_OWNER') . ')';
    }

    /**
     * 열람 가능 조건. 본인/전체/주관부서/처리자/참조자. 임시저장은 요청자만.
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function visible(array $user, array $roles, array $perms): array
    {
        [$params] = self::params($user, $roles, $perms);
        $sql = "((r.status <> 'DRAFT' OR r.requester_id = :u_uid) AND ("
            . 'r.requester_id = :u_uid'
            . ' OR r.reg_by = :u_uid'
            . ' OR :u_vall = 1'
            . ' OR (:u_vdept = 1 AND s.biz_dept_id IS NOT NULL AND s.biz_dept_id = :u_dept)'
            . ' OR EXISTS (SELECT 1 FROM process_step ps0 WHERE ps0.company_cd = r.company_cd AND ps0.version_id = r.version_id AND ' . self::actorExpr('ps0', $roles) . ')'
            . ' OR (:u_vcc = 1 AND EXISTS (SELECT 1 FROM process_step ps1 JOIN process_step_cc c ON c.company_cd = ps1.company_cd AND c.step_id = ps1.step_id'
            . ' WHERE ps1.company_cd = r.company_cd AND ps1.version_id = r.version_id AND ' . self::ccExpr('c', $roles) . '))'
            . '))';
        return [$sql, $params];
    }

    /** 처리 대기(현재 단계의 처리자이며 이번 회차에 아직 처리하지 않음). 별칭 rs=현재 request_step, ps=process_step. */
    public static function inbox(array $user, array $roles, array $perms): array
    {
        [$params] = self::params($user, $roles, $perms);
        $params = array_filter($params, static fn(string $k): bool => preg_match('/^(u_uid|u_uids|u_biz|u_dept|u_role\d+)$/', $k) === 1, ARRAY_FILTER_USE_KEY);
        $sql = "(r.status = 'IN_PROGRESS' AND " . self::actorExpr('ps', $roles)
            . ' AND NOT EXISTS (SELECT 1 FROM approval a WHERE a.company_cd = rs.company_cd AND a.rstep_id = rs.rstep_id AND a.round = rs.round AND a.actor_id = :u_uid))';
        return [$sql, $params];
    }

    /** 참조자로 지정된 요청(임시저장 제외). */
    public static function cc(array $user, array $roles, array $perms): array
    {
        [$params] = self::params($user, $roles, $perms);
        $params = array_filter($params, static fn(string $k): bool => preg_match('/^(u_uid|u_uids|u_biz|u_dept|u_depts|u_role\d+)$/', $k) === 1, ARRAY_FILTER_USE_KEY);
        $sql = "(r.status <> 'DRAFT' AND EXISTS (SELECT 1 FROM process_step ps1 JOIN process_step_cc c ON c.company_cd = ps1.company_cd AND c.step_id = ps1.step_id"
            . ' WHERE ps1.company_cd = r.company_cd AND ps1.version_id = r.version_id AND ' . self::ccExpr('c', $roles) . '))';
        return [$sql, $params];
    }

    public static function canView(int $requestId, array $user, array $roles, array $perms): bool
    {
        [$cond, $params] = self::visible($user, $roles, $perms);
        return Repo::queryOne(
            'SELECT 1 FROM request r JOIN service s ON s.company_cd = r.company_cd AND s.service_id = r.service_id
              WHERE r.company_cd = :company_cd AND r.request_id = :rid AND ' . $cond,
            $params + ['rid' => $requestId]
        ) !== null;
    }
}
