<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Repo;

/** 작업일지: IT 담당자가 전화/메일/메신저 등으로 받은 요청을 사후에 완료 상태로 기록한다(승인·알림 없음). reg_by 가 있으면 작업일지 건. */
final class WorklogService
{
    public const TYPE = 'WORKLOG';
    public const PROCESS = 'WORKLOG';
    public const CHANNEL_GROUP = 'CONTACT_CHANNEL';

    private const CHANNELS = [
        'PHONE' => ['전화', '電話', 'Phone', '电话'],
        'MAIL' => ['메일', 'メール', 'Email', '邮件'],
        'MESSENGER' => ['메신저', 'メッセンジャー', 'Messenger', '即时通讯'],
        'VISIT' => ['방문', '訪問', 'Visit', '来访'],
        'ETC' => ['기타', 'その他', 'Other', '其他'],
    ];

    /** 시스템 기본 프로세스의 설명. 관리자가 바꾼 설명은 보강 때 건드리지 않는다. */
    private const PROCESS_DESC = '시스템 기본 프로세스: 접수→작업완료 (IT 담당자가 사후 등록하는 완료 건, 승인 없음)';
    private const PROCESS_DESC_OLD = '접수→작업완료 (IT 담당자가 사후 등록하는 완료 건, 승인 없음)';

    public static function isLog(array $req): bool
    {
        return ($req['reg_by'] ?? null) !== null;
    }

    /** 수정·삭제·첨부 관리는 등록자 본인과 시스템 관리자만 한다. */
    public static function canManage(array $req, int $userId): bool
    {
        return self::isLog($req)
            && ((int)$req['reg_by'] === $userId || in_array('SYS_ADMIN', ActorResolver::roleCodes($userId), true));
    }

    /** 유형 코드, 접수 채널 코드, 작업일지 프로세스를 없으면 만든다(멱등). @return int 프로세스 ID */
    public static function ensure(): int
    {
        return (int)Db::tx(static function (): int {
            if (Repo::find('code', ['group_cd' => 'MAINT_TYPE', 'code_cd' => self::TYPE]) === null) {
                $max = (int)Repo::queryVal('SELECT COALESCE(MAX(sort_no), 0) FROM code WHERE company_cd = :company_cd AND group_cd = :g', ['g' => 'MAINT_TYPE']);
                Repo::insert('code', ['group_cd' => 'MAINT_TYPE', 'code_cd' => self::TYPE, 'code_nm' => '기타작업', 'sort_no' => $max + 1, 'use_yn' => 1]);
                Translations::save('CODE', 'MAINT_TYPE:' . self::TYPE, ['ja' => 'その他作業', 'en' => 'Other Work', 'zh' => '其他作业']);
            }
            if (Repo::find('app_setting', ['setting_key' => 'seed.worklog']) === null) {
                if (Repo::find('code_group', ['group_cd' => self::CHANNEL_GROUP]) === null) {
                    Repo::insert('code_group', ['group_cd' => self::CHANNEL_GROUP, 'group_nm' => '접수 채널', 'use_yn' => 1]);
                    Translations::save('CODE_GROUP', self::CHANNEL_GROUP, ['ja' => '受付チャネル', 'en' => 'Contact Channel', 'zh' => '受理渠道']);
                    $i = 0;
                    foreach (self::CHANNELS as $cd => [$ko, $ja, $en, $zh]) {
                        Repo::insert('code', ['group_cd' => self::CHANNEL_GROUP, 'code_cd' => $cd, 'code_nm' => $ko, 'sort_no' => ++$i, 'use_yn' => 1]);
                        Translations::save('CODE', self::CHANNEL_GROUP . ':' . $cd, ['ja' => $ja, 'en' => $en, 'zh' => $zh]);
                    }
                }
                Repo::insert('app_setting', ['setting_key' => 'seed.worklog', 'setting_value' => '1']);
            }
            $m = Repo::find('process_master', ['process_cd' => self::PROCESS]);
            if ($m !== null) {
                return (int)$m['process_id'];
            }
            $pid = Repo::insert('process_master', [
                'process_cd' => self::PROCESS, 'process_nm' => '기타작업일지',
                'description' => self::PROCESS_DESC,
            ]);
            Translations::save('PROCESS', self::PROCESS, ['ja' => 'その他作業日誌', 'en' => 'Work Log', 'zh' => '其他作业日志']);
            $vid = Repo::insert('process_version', [
                'process_id' => $pid, 'version_no' => 1, 'valid_from' => date('Y-m-d'), 'valid_to' => null,
                'status' => 'ACTIVE', 'created_by' => null, 'created_at' => now_str(),
            ]);
            $steps = [
                ['접수', '受付', 'Submission', '受理', 'SUBMIT', 'REQUESTER', null, 'OPTIONAL'],
                ['작업완료', '作業完了', 'Work Done', '作业完成', 'CLOSE', 'SERVICE_IT_OWNER', null, 'NONE'],
            ];
            foreach ($steps as $i => [$ko, $ja, $en, $zh, $type, $actor, $val, $attach]) {
                $sid = ProcessService::insertStepRaw($vid, $i + 1, $ko, $type, $actor, $val, 'ANY', 0, null, null, $attach);
                Translations::save('STEP', (string)$sid, ['ja' => $ja, 'en' => $en, 'zh' => $zh]);
            }
            return $pid;
        });
    }

    /** 이미 만들어진 작업일지 프로세스의 작업완료 처리자를 전산팀 롤에서 담당자(=등록자)로 바꾼다(멱등). 활성 버전 수정 제한을 의도적으로 우회한다. */
    public static function upgradeProcess(): void
    {
        $m = Repo::find('process_master', ['process_cd' => self::PROCESS]);
        if ($m === null) {
            return;
        }
        foreach (Repo::list('process_version', ['process_id' => (int)$m['process_id']]) as $v) {
            foreach (Repo::list('process_step', ['version_id' => (int)$v['version_id'], 'step_type' => 'CLOSE', 'actor_type' => 'ROLE', 'actor_value' => 'IT_TEAM']) as $s) {
                Repo::update('process_step', ['step_id' => (int)$s['step_id']], ['actor_type' => 'SERVICE_IT_OWNER', 'actor_value' => null]);
            }
        }
        if ((string)$m['description'] === self::PROCESS_DESC_OLD) {
            Repo::update('process_master', ['process_id' => (int)$m['process_id']], ['description' => self::PROCESS_DESC]);
        }
    }

    /** @param array<string,string> $in request_date, requester_id, service_id, channel, title, content_html, effort_hours, actual_end */
    public static function save(?int $rid, int $userId, array $in): int
    {
        return (int)Db::tx(static function () use ($rid, $userId, $in): int {
            $pid = self::ensure();
            $old = null;
            if ($rid !== null) {
                $old = WorkflowService::request($rid);
                if (!self::isLog($old)) {
                    throw new ProcessError('process.err_not_found');
                }
                if (!self::canManage($old, $userId)) {
                    throw new ProcessError('request.err_forbidden');
                }
            }
            $today = date('Y-m-d');
            $date = trim((string)($in['request_date'] ?? ''));
            if (!Dates::isDate($date) || $date > $today) {
                throw new ProcessError('worklog.err_request_date');
            }
            $end = trim((string)($in['actual_end'] ?? ''));
            if (!Dates::isDate($end) || $end < $date || $end > $today) {
                throw new ProcessError('worklog.err_end');
            }
            $title = trim((string)($in['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 200) {
                throw new ProcessError('request.err_title');
            }
            $content = HtmlSanitizer::clean((string)($in['content_html'] ?? ''));
            if (HtmlSanitizer::text($content) === '') {
                throw new ProcessError('worklog.err_content');
            }
            if (strlen($content) > 200000) {
                throw new ProcessError('request.err_content_len');
            }
            $requesterId = ctype_digit((string)($in['requester_id'] ?? '')) ? (int)$in['requester_id'] : 0;
            $sameRequester = $old !== null && (int)$old['requester_id'] === $requesterId;
            if ($requesterId <= 0 || Repo::find('user', $sameRequester ? ['user_id' => $requesterId] : ['user_id' => $requesterId, 'use_yn' => 1]) === null) {
                throw new ProcessError('worklog.err_requester');
            }
            $serviceId = ctype_digit((string)($in['service_id'] ?? '')) ? (int)$in['service_id'] : 0;
            $sameService = $old !== null && (int)$old['service_id'] === $serviceId;
            if ($serviceId <= 0 || Repo::find('service', $sameService ? ['service_id' => $serviceId] : ['service_id' => $serviceId, 'use_yn' => 1]) === null) {
                throw new ProcessError('request.err_service');
            }
            $effort = Effort::parse((string)($in['effort_hours'] ?? ''));
            if ($effort === null || $effort < 0.01) {
                throw new ProcessError('worklog.err_effort');
            }
            $channel = trim((string)($in['channel'] ?? ''));
            if ($channel !== '' && Repo::find('code', ['group_cd' => self::CHANNEL_GROUP, 'code_cd' => $channel, 'use_yn' => 1]) === null
                && !($old !== null && (string)$old['channel'] === $channel)) {
                throw new ProcessError('worklog.err_channel');
            }
            $now = now_str();
            $data = [
                'service_id' => $serviceId, 'title' => $title, 'content_html' => $content, 'requester_id' => $requesterId,
                'req_due_date' => $end, 'actual_end' => $end, 'effort_hours' => $effort, 'request_date' => $date,
                'channel' => $channel === '' ? null : $channel, 'updated_at' => $now,
            ];

            if ($old !== null) {
                Repo::update('request', ['request_id' => $rid], $data);
                Repo::update('request_step', ['request_id' => $rid], ['started_at' => $date . ' 00:00:00', 'ended_at' => $end . ' 00:00:00']);
                Repo::update('request_step', ['request_id' => $rid, 'step_no' => 1], ['ended_at' => $date . ' 00:00:00']);
                return $rid;
            }

            $ver = ProcessService::resolveVersion($pid) ?? throw new ProcessError('request.err_no_version');
            $steps = ProcessService::steps((int)$ver['version_id']);
            if (count($steps) < 2) {
                throw new ProcessError('request.err_steps');
            }
            $new = Repo::insert('request', $data + [
                'request_no' => RequestService::nextNo(), 'maint_type' => self::TYPE, 'priority' => 'NORMAL',
                'version_id' => (int)$ver['version_id'], 'it_assignee_id' => $userId, 'status' => WorkflowService::ST_DONE,
                'current_step_no' => null, 'reg_by' => $userId, 'created_at' => $now,
            ]);
            foreach ($steps as $i => $s) {
                $rsid = Repo::insert('request_step', [
                    'request_id' => $new, 'step_no' => (int)$s['step_no'], 'step_nm' => $s['step_nm'], 'status' => 'DONE',
                    'started_at' => $date . ' 00:00:00', 'ended_at' => ($i === 0 ? $date : $end) . ' 00:00:00', 'round' => 1,
                ]);
                Repo::insert('approval', [
                    'rstep_id' => $rsid, 'actor_id' => $userId, 'round' => 1, 'opinion' => null, 'acted_at' => $now,
                    'action' => $i === 0 ? 'SUBMIT' : (WorkflowService::ACTION[(string)$s['step_type']] ?? 'COMPLETE'),
                ]);
            }
            return $new;
        });
    }

    /** 요청과 딸린 이력·첨부를 모두 지운다. @return string 삭제한 요청번호 */
    public static function delete(int $rid, int $userId): string
    {
        $files = [];
        $no = (string)Db::tx(static function () use ($rid, $userId, &$files): string {
            $req = WorkflowService::request($rid);
            if (!self::canManage($req, $userId)) {
                throw new ProcessError('request.err_forbidden');
            }
            foreach (Repo::list('attachment', ['request_id' => $rid]) as $a) {
                $files[] = AttachmentService::fullPath(\App\Core\Scope::cd(), (string)$a['stored_name']);
            }
            Repo::delete('attachment', ['request_id' => $rid]);
            foreach (Repo::list('schedule_change', ['request_id' => $rid]) as $c) {
                Repo::delete('schedule_agreement', ['change_id' => $c['change_id']]);
            }
            Repo::delete('schedule_change', ['request_id' => $rid]);
            foreach (Repo::list('request_step', ['request_id' => $rid]) as $s) {
                Repo::delete('approval', ['rstep_id' => $s['rstep_id']]);
            }
            Repo::delete('request_step', ['request_id' => $rid]);
            Repo::delete('comment', ['request_id' => $rid]);
            Repo::delete('notification_log', ['request_id' => $rid]);
            Repo::delete('request', ['request_id' => $rid]);
            return (string)$req['request_no'];
        });
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        return $no;
    }

    /**
     * 작업일지 목록(회사 내 전체).
     * @param array{from?:string,to?:string,requester_id?:int,service_id?:int,q?:string,page?:int} $f
     * @return array{rows:list<array>,total:int,page:int,pages:int}
     */
    public static function listFor(array $f): array
    {
        $join = ' FROM request r JOIN service s ON s.company_cd = r.company_cd AND s.service_id = r.service_id'
            . ' JOIN user u ON u.company_cd = r.company_cd AND u.user_id = r.requester_id'
            . ' JOIN user g ON g.company_cd = r.company_cd AND g.user_id = r.reg_by'
            . ' LEFT JOIN code ch ON ch.company_cd = r.company_cd AND ch.group_cd = \'' . self::CHANNEL_GROUP . '\' AND ch.code_cd = r.channel';
        $where = ['r.company_cd = :company_cd', 'r.reg_by IS NOT NULL'];
        $p = [];
        foreach (['from' => 'r.request_date >= :f_from', 'to' => 'r.request_date <= :f_to'] as $k => $cond) {
            $v = trim((string)($f[$k] ?? ''));
            if (Dates::isDate($v)) {
                $where[] = $cond;
                $p['f_' . $k] = $v;
            }
        }
        if (($f['requester_id'] ?? 0) > 0) {
            $where[] = 'r.requester_id = :f_req';
            $p['f_req'] = (int)$f['requester_id'];
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
        $size = RequestService::pageSize();
        $pages = max(1, (int)ceil($total / $size));
        $page = min(max(1, (int)($f['page'] ?? 1)), $pages);
        $rows = Repo::query(
            'SELECT r.request_id, r.request_no, r.title, r.request_date, r.actual_end, r.effort_hours, r.channel, r.reg_by, r.requester_id,
                    s.service_cd, s.service_nm, ch.code_nm AS channel_nm, u.user_nm AS requester_nm, g.user_nm AS reg_nm'
            . $join . ' WHERE ' . $w . ' ORDER BY r.request_date DESC, r.request_id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size),
            $p
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
