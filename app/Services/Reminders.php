<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Repo;

/** 크론에서 호출하는 독촉: 단계 기한 임박/초과, 일정 변경 회신 기한 초과. 같은 건은 중복 적재하지 않는다. */
final class Reminders
{
    public static function soonDays(): int
    {
        return max(0, (int)env('DUE_SOON_DAYS', '1'));
    }

    /** @return array{soon:int,over:int,schedule:int} 새로 대기열에 넣은 메일 수 */
    public static function run(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $out = ['soon' => 0, 'over' => 0, 'schedule' => 0];
        $limit = Dates::addBusinessDays($today, self::soonDays());
        $rows = Repo::query(
            "SELECT r.request_id, rs.step_no, rs.rstep_id, rs.round, rs.due_at
               FROM request r JOIN request_step rs ON rs.company_cd = r.company_cd AND rs.request_id = r.request_id AND rs.step_no = r.current_step_no
              WHERE r.company_cd = :company_cd AND r.status = 'IN_PROGRESS' AND rs.status = 'ACTIVE' AND rs.due_at IS NOT NULL AND rs.due_at <= :lim
                AND NOT EXISTS (SELECT 1 FROM schedule_change sc WHERE sc.company_cd = r.company_cd AND sc.request_id = r.request_id AND sc.status = 'PROPOSED')
              ORDER BY r.request_id",
            ['lim' => $limit]
        );
        foreach ($rows as $row) {
            $req = WorkflowService::request((int)$row['request_id']);
            $svc = WorkflowService::service($req);
            $def = Repo::find('process_step', ['version_id' => (int)$req['version_id'], 'step_no' => (int)$row['step_no']]);
            if ($def === null) {
                continue;
            }
            $over = (string)$row['due_at'] < $today;
            $done = array_map('intval', array_column(Repo::list('approval', ['rstep_id' => (int)$row['rstep_id'], 'round' => (int)$row['round']]), 'actor_id'));
            foreach (array_diff(ActorResolver::userIds($def, $req, $svc), $done) as $uid) {
                $id = MailService::enqueue(
                    $req, $uid, $over ? 'DUE_OVER' : 'DUE_SOON',
                    ['step' => ['STEP', (string)$def['step_id'], (string)$def['step_nm']], 'due' => (string)$row['due_at']],
                    'TO', ($over ? 'over:' : 'soon:') . $row['rstep_id'] . ':' . $row['round'] . ':' . $uid
                );
                if ($id !== null) {
                    $out[$over ? 'over' : 'soon']++;
                }
            }
        }
        foreach (Repo::list('schedule_change', ['status' => 'PROPOSED']) as $ch) {
            if (ScheduleService::responseDue($ch) >= $today) {
                continue;
            }
            $req = WorkflowService::request((int)$ch['request_id']);
            $worker = Repo::find('user', ['user_id' => (int)$ch['proposer_id']]);
            $id = MailService::enqueue($req, (int)$req['requester_id'], 'SCHEDULE_REMIND', [
                'worker' => (string)($worker['user_nm'] ?? ''), 'from' => (string)$ch['prev_end'], 'to' => (string)$ch['new_end'],
                'response_due' => ScheduleService::responseDue($ch),
            ], 'TO', 'srm:' . $ch['change_id'] . ':' . $today);
            if ($id !== null) {
                $out['schedule']++;
            }
        }
        return $out;
    }
}
