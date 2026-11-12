<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Repo;

/** 단계 처리자/참조자 해석. 특정 사용자 판정(isActor)과 목록 해석(userIds)을 제공한다. */
final class ActorResolver
{
    /** @return list<string> */
    public static function roleCodes(int $userId): array
    {
        return array_column(Repo::list('user_role', ['user_id' => $userId]), 'role_cd');
    }

    /** 요청별 담당자가 있으면 그 사람, 없으면 서비스 전산 담당자. */
    private static function itOwnerId(array $req, array $svc): ?int
    {
        $id = $req['it_assignee_id'] ?? $svc['it_owner_id'];
        return $id === null ? null : (int)$id;
    }

    /** @param array<string,mixed> $svc  @return list<int> 서비스 주관부서의 활성 현업 전산담당(BIZ_IT) 전원 */
    private static function bizDeptUserIds(array $svc): array
    {
        if ($svc['biz_dept_id'] === null) {
            return [];
        }
        return array_values(array_map('intval', array_column(Repo::query(
            'SELECT DISTINCT u.user_id FROM user u JOIN user_role ur ON ur.company_cd = u.company_cd AND ur.user_id = u.user_id
              WHERE u.company_cd = :company_cd AND u.use_yn = 1 AND ur.role_cd = \'BIZ_IT\' AND u.dept_id = :d',
            ['d' => (int)$svc['biz_dept_id']]
        ), 'user_id')));
    }

    /** @return list<int> 서비스에 지정된 현업 IT 담당자(자격 여부와 무관) */
    public static function designatedBizOwners(int $serviceId): array
    {
        return array_values(array_map('intval', array_column(Repo::list('service_biz_it', ['service_id' => $serviceId]), 'user_id')));
    }

    /**
     * 지정된 현업 IT 담당자 중 지금 자격이 있는 사람(활성, BIZ_IT 롤, 서비스 주관부서 소속).
     * 이 규칙은 RequestAccess::bizOwnerCond(SQL)와 같아야 한다.
     * @param array<string,mixed> $svc  @return list<int>
     */
    public static function eligibleBizOwners(array $svc): array
    {
        if ($svc['biz_dept_id'] === null) {
            return [];
        }
        return array_values(array_map('intval', array_column(Repo::query(
            'SELECT DISTINCT u.user_id FROM service_biz_it b
               JOIN user u ON u.company_cd = b.company_cd AND u.user_id = b.user_id
               JOIN user_role ur ON ur.company_cd = u.company_cd AND ur.user_id = u.user_id
              WHERE b.company_cd = :company_cd AND b.service_id = :s AND u.use_yn = 1 AND u.dept_id = :d AND ur.role_cd = \'BIZ_IT\'',
            ['s' => (int)$svc['service_id'], 'd' => (int)$svc['biz_dept_id']]
        ), 'user_id')));
    }

    /** @param array<string,mixed> $step process_step 행  @param array<string,mixed> $req  @param array<string,mixed> $svc */
    public static function isActor(array $step, array $req, array $svc, int $userId): bool
    {
        $u = Repo::find('user', ['user_id' => $userId, 'use_yn' => 1]);
        if ($u === null) {
            return false;
        }
        return match ((string)$step['actor_type']) {
            'ROLE' => in_array((string)$step['actor_value'], self::roleCodes($userId), true),
            'USER' => (string)$step['actor_value'] === (string)$userId,
            'REQUESTER' => (int)$req['requester_id'] === $userId,
            'SERVICE_IT_OWNER' => ($owner = self::itOwnerId($req, $svc)) !== null && $owner === $userId,
            'SERVICE_BIZ_DEPT' => $svc['biz_dept_id'] !== null && $u['dept_id'] !== null
                && (int)$svc['biz_dept_id'] === (int)$u['dept_id'] && in_array('BIZ_IT', self::roleCodes($userId), true),
            'SERVICE_BIZ_OWNER' => in_array($userId, self::bizOwnerIds($svc), true),
            default => false,
        };
    }

    /** @return list<int> 해당 단계를 처리할 수 있는 활성 사용자 */
    public static function userIds(array $step, array $req, array $svc): array
    {
        $ids = match ((string)$step['actor_type']) {
            'ROLE' => array_column(Repo::query(
                'SELECT DISTINCT u.user_id FROM user u JOIN user_role ur ON ur.company_cd = u.company_cd AND ur.user_id = u.user_id
                  WHERE u.company_cd = :company_cd AND u.use_yn = 1 AND ur.role_cd = :r',
                ['r' => (string)$step['actor_value']]
            ), 'user_id'),
            'USER' => array_column(Repo::list('user', ['user_id' => (int)$step['actor_value'], 'use_yn' => 1]), 'user_id'),
            'REQUESTER' => array_column(Repo::list('user', ['user_id' => (int)$req['requester_id'], 'use_yn' => 1]), 'user_id'),
            'SERVICE_IT_OWNER' => ($owner = self::itOwnerId($req, $svc)) === null ? [] : array_column(Repo::list('user', ['user_id' => $owner, 'use_yn' => 1]), 'user_id'),
            'SERVICE_BIZ_DEPT' => self::bizDeptUserIds($svc),
            'SERVICE_BIZ_OWNER' => self::bizOwnerIds($svc),
            default => [],
        };
        return array_values(array_map('intval', $ids));
    }

    /** 지정된 현업 IT 담당자중 자격 있는 사람, 없으면 주관부서 현업 전산담당 전원(미지정·자격 상실 폴백). @return list<int> */
    private static function bizOwnerIds(array $svc): array
    {
        $el = self::eligibleBizOwners($svc);
        return $el !== [] ? $el : self::bizDeptUserIds($svc);
    }

    /** @param array<string,mixed> $cc process_step_cc 행 @return list<int> 참조자로 해석된 활성 사용자 */
    public static function ccUserIds(array $cc, array $req, array $svc): array
    {
        if ((string)$cc['cc_type'] === 'DEPT') {
            return array_values(array_map('intval', array_column(Repo::list('user', ['dept_id' => (int)$cc['cc_value'], 'use_yn' => 1]), 'user_id')));
        }
        return self::userIds(['actor_type' => $cc['cc_type'], 'actor_value' => $cc['cc_value']], $req, $svc);
    }
}
