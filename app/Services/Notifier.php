<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Repo;

/**
 * 업무 이벤트 → 메일 대기열 적재. 수신 원칙(설계서 9.4): 피드백이 필요한 사람에게만 보내고,
 * 본인이 방금 한 행위는 제외하며 한 전이에서 같은 사람에게는 한 통만 보낸다. DB 적재만 하므로 호출 쪽 트랜잭션에 포함된다.
 */
final class Notifier
{
    /**
     * 단계 전이: 완료된 단계의 참조자(COMPLETE), 다음 단계 처리자(TO), 다음 단계 참조자(ACTIVATE)에게 알린다.
     * @param array<string,mixed>      $req
     * @param array<string,mixed>      $svc
     * @param array<string,mixed>|null $done 방금 완료된 단계 정의(반려는 null)
     * @param array<string,mixed>|null $next 새로 활성화된 단계 정의(요청 종료는 null)
     * @param string|null              $returnOpinion 반려 사유. 값이 있으면 보완/재처리 요청으로 보낸다.
     */
    public static function transition(array $req, array $svc, ?array $done, ?array $next, int $actorId, ?string $returnOpinion = null): void
    {
        $rid = (int)$req['request_id'];
        $seen = [$actorId => true];
        $actor = Repo::find('user', ['user_id' => $actorId]);
        if ($next !== null) {
            $rs = self::rstep($rid, $next);
            $returned = $returnOpinion !== null;
            $custom = trim((string)$next['notify_tmpl']);
            $tmpl = $returned ? 'STEP_RETURNED' : ($custom !== '' ? $custom : 'STEP_ACTIVE');
            $vars = [
                'step' => ['STEP', (string)$next['step_id'], (string)$next['step_nm']], 'due' => (string)($rs['due_at'] ?? '-'),
                'actor' => (string)($actor['user_nm'] ?? ''), 'opinion' => (string)$returnOpinion,
            ];
            foreach (ActorResolver::userIds($next, $req, $svc) as $uid) {
                if (isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                MailService::enqueue($req, $uid, $tmpl, $vars, 'TO', 'act:' . $rs['rstep_id'] . ':' . $rs['round'] . ':' . $uid . ($returned ? ':r' : ''));
            }
        }
        if ($done !== null) {
            self::copy($req, $svc, $done, 'COMPLETE', $seen);
        }
        if ($next !== null) {
            self::copy($req, $svc, $next, 'ACTIVATE', $seen);
        }
    }

    /** 담당자 지정 → 지정된 사람이 현재 단계 처리자이면 처리 요청 메일. */
    public static function assigned(array $req, array $def, array $rs, int $userId, int $actorId): void
    {
        $actor = Repo::find('user', ['user_id' => $actorId]);
        MailService::enqueue($req, $userId, 'STEP_ACTIVE', [
            'step' => ['STEP', (string)$def['step_id'], (string)$def['step_nm']], 'due' => (string)($rs['due_at'] ?? '-'),
            'actor' => (string)($actor['user_nm'] ?? ''), 'opinion' => '',
        ], 'TO', 'asg:' . $rs['rstep_id'] . ':' . $rs['round'] . ':' . $userId);
    }

    /** SR 분할 → 요청자에게 새 SR 안내. */
    public static function split(array $req, array $child, int $actorId): void
    {
        if ((int)$req['requester_id'] === $actorId) {
            return;
        }
        $actor = Repo::find('user', ['user_id' => $actorId]);
        MailService::enqueue($req, (int)$req['requester_id'], 'SR_SPLIT', [
            'actor' => (string)($actor['user_nm'] ?? ''), 'new_no' => (string)$child['request_no'], 'new_title' => (string)$child['title'],
            'new_link' => MailService::link((int)$child['request_id']),
        ], 'TO', 'split:' . $child['request_id']);
    }

    /** 일정 변경 제안 → 회신해야 하는 요청자. */
    public static function scheduleProposed(array $req, array $change): void
    {
        $worker = Repo::find('user', ['user_id' => (int)$change['proposer_id']]);
        MailService::enqueue($req, (int)$req['requester_id'], 'SCHEDULE_PROPOSED', [
            'worker' => (string)($worker['user_nm'] ?? ''), 'from' => (string)$change['prev_end'], 'to' => (string)$change['new_end'],
            'reason' => self::reason((string)$change['reason_cd']), 'reason_text' => (string)$change['reason_text'],
            'response_due' => ScheduleService::responseDue($change),
        ], 'TO', 'sp:' . $change['change_id']);
    }

    /** 일정 변경 반려/대안 → 재제안/회신이 필요한 작업자. 합의는 더 할 일이 없으므로 보내지 않는다. */
    public static function scheduleResponded(array $req, array $change, string $decision, string $comment, ?string $counter, int $responderId): void
    {
        $tmpl = match ($decision) {
            'REJECT' => 'SCHEDULE_REJECTED',
            'COUNTER' => 'SCHEDULE_COUNTERED',
            default => null,
        };
        if ($tmpl === null) {
            return;
        }
        $actor = Repo::find('user', ['user_id' => $responderId]);
        MailService::enqueue($req, (int)$change['proposer_id'], $tmpl, [
            'actor' => (string)($actor['user_nm'] ?? ''), 'from' => (string)$change['prev_end'], 'to' => (string)$change['new_end'],
            'opinion' => $comment, 'counter' => (string)$counter,
        ], 'TO', 'sr:' . $change['change_id']);
    }

    /** @return array{0:string,1:string,2:string} 수신자 언어로 번역될 사유명 */
    public static function reason(string $code): array
    {
        $row = Repo::find('code', ['group_cd' => ScheduleService::REASON_GROUP, 'code_cd' => $code]);
        return ['CODE', ScheduleService::REASON_GROUP . ':' . $code, (string)($row['code_nm'] ?? $code)];
    }

    /** @param array<int,bool> $seen 이미 메일을 받은(받을) 사용자 */
    private static function copy(array $req, array $svc, array $def, string $event, array &$seen): void
    {
        $rs = self::rstep((int)$req['request_id'], $def);
        $on = $event === 'ACTIVATE' ? ['ACTIVATE', 'BOTH'] : ['COMPLETE', 'BOTH'];
        foreach (Repo::list('process_step_cc', ['step_id' => (int)$def['step_id']], 'cc_id') as $cc) {
            if (!in_array((string)$cc['notify_on'], $on, true)) {
                continue;
            }
            foreach (ActorResolver::ccUserIds($cc, $req, $svc) as $uid) {
                if (isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                MailService::enqueue(
                    $req, $uid, $event === 'ACTIVATE' ? 'STEP_CC_ACTIVATED' : 'STEP_CC_COMPLETED',
                    ['step' => ['STEP', (string)$def['step_id'], (string)$def['step_nm']]],
                    'CC', 'cc' . strtolower($event[0]) . ':' . $rs['rstep_id'] . ':' . $rs['round'] . ':' . $uid
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private static function rstep(int $rid, array $def): array
    {
        return Repo::find('request_step', ['request_id' => $rid, 'step_no' => (int)$def['step_no']]) ?? throw new ProcessError('process.err_not_found');
    }
}
