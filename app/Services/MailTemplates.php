<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Repo;

/** 메일 템플릿 기본값과 조회. 회사별 DB 행(`mail_template`)이 있으면 그것을, 없으면 내장 기본값을 쓴다. */
final class MailTemplates
{
    public const LANGS = ['ko', 'ja', 'en', 'zh'];
    /** 치환 변수. 본문/제목에 {이름} 으로 쓴다. */
    public const VARS = [
        'recipient', 'company', 'request_no', 'title', 'requester', 'step', 'actor', 'due', 'link',
        'opinion', 'from', 'to', 'reason', 'reason_text', 'counter', 'response_due', 'worker',
        'new_no', 'new_title', 'new_link',
    ];
    public const FALLBACK = 'STEP_ACTIVE';
    public const CODE_RE = '/^[A-Z][A-Z0-9_]{0,39}$/';

    public static function isBuiltin(string $code): bool
    {
        return isset(self::defaults()[$code]);
    }

    /**
     * 언어별 제목/본문을 저장한다(해당 코드의 기존 행 교체).
     * @param array<string,array{0:string,1:string}> $byLang
     * @throws \InvalidArgumentException 메시지는 번역 키
     */
    public static function save(string $code, array $byLang): void
    {
        if (preg_match(self::CODE_RE, $code) !== 1) {
            throw new \InvalidArgumentException('mailtpl.err_code');
        }
        $clean = [];
        foreach (self::LANGS as $lang) {
            $subject = trim((string)preg_replace('/[\r\n\t]+/', ' ', (string)($byLang[$lang][0] ?? '')));
            $body = trim(str_replace("\r\n", "\n", (string)($byLang[$lang][1] ?? '')));
            if ($subject === '' || mb_strlen($subject) > 200 || $body === '' || mb_strlen($body) > 5000) {
                throw new \InvalidArgumentException('mailtpl.err_text');
            }
            $clean[$lang] = [$subject, $body];
        }
        Db::tx(static function () use ($code, $clean): void {
            Repo::delete('mail_template', ['tmpl_cd' => $code]);
            foreach ($clean as $lang => [$subject, $body]) {
                Repo::insert('mail_template', ['tmpl_cd' => $code, 'lang' => $lang, 'subject' => $subject, 'body' => $body]);
            }
        });
    }

    /** 내장 템플릿을 기본값으로 되돌린다. */
    public static function reset(string $code): void
    {
        $def = self::defaults()[$code] ?? throw new \InvalidArgumentException('mailtpl.err_builtin');
        self::save($code, $def);
    }

    /** @return array<string,array<string,array{0:string,1:string}>> 코드 => 언어 => [제목, 본문] */
    public static function defaults(): array
    {
        static $d = null;
        return $d ??= self::build();
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::defaults());
    }

    /** 수신 언어 → ko → 내장 기본 순으로 찾는다. @return array{0:string,1:string}|null */
    public static function find(string $code, string $lang): ?array
    {
        foreach ([$lang, 'ko'] as $l) {
            $row = Repo::find('mail_template', ['tmpl_cd' => $code, 'lang' => $l]);
            if ($row !== null) {
                return [(string)$row['subject'], (string)$row['body']];
            }
        }
        $def = self::defaults()[$code] ?? null;
        return $def === null ? null : ($def[$lang] ?? $def['ko'] ?? null);
    }

    /** 회사에 기본 템플릿을 채운다(이미 있는 행은 건드리지 않는다). */
    public static function seed(): void
    {
        foreach (self::defaults() as $code => $byLang) {
            foreach ($byLang as $lang => [$subject, $body]) {
                if (Repo::find('mail_template', ['tmpl_cd' => $code, 'lang' => $lang]) === null) {
                    Repo::insert('mail_template', ['tmpl_cd' => $code, 'lang' => $lang, 'subject' => $subject, 'body' => $body]);
                }
            }
        }
    }

    /** @param list<string> $lines */
    private static function b(array $lines): string
    {
        return implode("\n", $lines);
    }

    /** @return array<string,array<string,array{0:string,1:string}>> */
    private static function build(): array
    {
        $ko = [
            'SR_SPLIT' => ['[SRMS] SR 분할 안내 {request_no} {title}', ['{recipient}님,', '요청하신 SR을 {actor}님이 2건으로 분할했습니다. 각 SR은 담당자별로 따로 진행됩니다.', '', '기존 SR: {request_no} {title}', '새 SR: {new_no} {new_title}', '', '기존 SR: {link}', '새 SR: {new_link}', '', '회사: {company}']],
            'STEP_ACTIVE' => ['[SRMS] 처리 요청 {request_no} {title}', ['{recipient}님,', '{step} 단계의 처리를 요청드립니다.', '', '요청번호: {request_no}', '제목: {title}', '요청자: {requester}', '처리 기한: {due}', '', '{link}', '', '회사: {company}']],
            'STEP_RETURNED' => ['[SRMS] 보완/재처리 요청 {request_no} {title}', ['{recipient}님,', '{actor}님이 반려하여 {step} 단계의 보완 또는 재처리가 필요합니다.', '', '요청번호: {request_no}', '제목: {title}', '반려 사유: {opinion}', '처리 기한: {due}', '', '{link}', '', '회사: {company}']],
            'STEP_CC_ACTIVATED' => ['[SRMS] (참조) {step} 단계 시작 {request_no}', ['{recipient}님,', '참조자로 지정된 요청의 {step} 단계가 시작되었습니다. 이 메일은 참조용이며 별도 조치는 필요하지 않습니다.', '', '요청번호: {request_no}', '제목: {title}', '', '{link}', '', '회사: {company}']],
            'STEP_CC_COMPLETED' => ['[SRMS] (참조) {step} 단계 완료 {request_no}', ['{recipient}님,', '참조자로 지정된 요청의 {step} 단계가 완료되었습니다. 이 메일은 참조용이며 별도 조치는 필요하지 않습니다.', '', '요청번호: {request_no}', '제목: {title}', '', '{link}', '', '회사: {company}']],
            'SCHEDULE_PROPOSED' => ['[SRMS] 일정 변경 합의 요청 {request_no}', ['{recipient}님,', '{worker}님이 완료일을 {from} → {to} 로 변경하기를 요청했습니다.', '사유: {reason} - {reason_text}', '', '요청번호: {request_no}', '제목: {title}', '회신 기한: {response_due}', '', '합의, 반려 또는 대안 제시로 회신해 주세요. 회신 전까지 이 요청의 단계 처리는 중단됩니다.', '{link}', '', '회사: {company}']],
            'SCHEDULE_REJECTED' => ['[SRMS] 일정 변경 반려 {request_no}', ['{recipient}님,', '{actor}님이 일정 변경 요청을 반려했습니다. 기존 완료일({from})이 유지됩니다.', '의견: {opinion}', '', '요청번호: {request_no}', '제목: {title}', '', '{link}', '', '회사: {company}']],
            'SCHEDULE_COUNTERED' => ['[SRMS] 대안 일정 제시 {request_no}', ['{recipient}님,', '{actor}님이 일정 변경 요청에 대해 {counter} 로 대안을 제시했습니다. 기존 완료일({from})이 유지됩니다.', '의견: {opinion}', '', '요청번호: {request_no}', '제목: {title}', '', '{link}', '', '회사: {company}']],
            'DUE_SOON' => ['[SRMS] 처리 기한 임박 {request_no} {title}', ['{recipient}님,', '{step} 단계의 처리 기한({due})이 임박했습니다.', '', '요청번호: {request_no}', '제목: {title}', '', '{link}', '', '회사: {company}']],
            'DUE_OVER' => ['[SRMS] 처리 기한 초과 {request_no} {title}', ['{recipient}님,', '{step} 단계의 처리 기한({due})이 지났습니다. 빠른 처리를 부탁드립니다.', '', '요청번호: {request_no}', '제목: {title}', '', '{link}', '', '회사: {company}']],
            'SCHEDULE_REMIND' => ['[SRMS] 일정 변경 회신 요청(독촉) {request_no}', ['{recipient}님,', '{worker}님의 일정 변경 요청({from} → {to})에 대한 회신 기한({response_due})이 지났습니다. 회신 전까지 단계 처리가 중단되어 있습니다.', '', '요청번호: {request_no}', '제목: {title}', '', '{link}', '', '회사: {company}']],
        ];
        $ja = [
            'SR_SPLIT' => ['[SRMS] SR分割のお知らせ {request_no} {title}', ['{recipient} 様', '{actor} さんがご依頼の SR を2件に分割しました。各 SR は担当者ごとに別々に進められます。', '', '元の SR: {request_no} {title}', '新しい SR: {new_no} {new_title}', '', '元の SR: {link}', '新しい SR: {new_link}', '', '会社: {company}']],
            'STEP_ACTIVE' => ['[SRMS] 処理依頼 {request_no} {title}', ['{recipient} 様', '{step} ステップの処理をお願いします。', '', '依頼番号: {request_no}', '件名: {title}', '依頼者: {requester}', '処理期限: {due}', '', '{link}', '', '会社: {company}']],
            'STEP_RETURNED' => ['[SRMS] 補完/再処理の依頼 {request_no} {title}', ['{recipient} 様', '{actor} さんが差し戻したため、{step} ステップの補完または再処理が必要です。', '', '依頼番号: {request_no}', '件名: {title}', '差戻し理由: {opinion}', '処理期限: {due}', '', '{link}', '', '会社: {company}']],
            'STEP_CC_ACTIVATED' => ['[SRMS] (参照) {step} ステップ開始 {request_no}', ['{recipient} 様', '参照者に指定された依頼の {step} ステップが開始されました。このメールは参照用で、対応は不要です。', '', '依頼番号: {request_no}', '件名: {title}', '', '{link}', '', '会社: {company}']],
            'STEP_CC_COMPLETED' => ['[SRMS] (参照) {step} ステップ完了 {request_no}', ['{recipient} 様', '参照者に指定された依頼の {step} ステップが完了しました。このメールは参照用で、対応は不要です。', '', '依頼番号: {request_no}', '件名: {title}', '', '{link}', '', '会社: {company}']],
            'SCHEDULE_PROPOSED' => ['[SRMS] 日程変更の合意依頼 {request_no}', ['{recipient} 様', '{worker} さんが完了日を {from} → {to} に変更するよう依頼しました。', '理由: {reason} - {reason_text}', '', '依頼番号: {request_no}', '件名: {title}', '回答期限: {response_due}', '', '合意、差戻しまたは代替案の提示で回答してください。回答があるまでこの依頼のステップ処理は停止します。', '{link}', '', '会社: {company}']],
            'SCHEDULE_REJECTED' => ['[SRMS] 日程変更の差戻し {request_no}', ['{recipient} 様', '{actor} さんが日程変更依頼を差し戻しました。元の完了日({from})が維持されます。', '意見: {opinion}', '', '依頼番号: {request_no}', '件名: {title}', '', '{link}', '', '会社: {company}']],
            'SCHEDULE_COUNTERED' => ['[SRMS] 代替日程の提示 {request_no}', ['{recipient} 様', '{actor} さんが日程変更依頼に対し {counter} を代替案として提示しました。元の完了日({from})が維持されます。', '意見: {opinion}', '', '依頼番号: {request_no}', '件名: {title}', '', '{link}', '', '会社: {company}']],
            'DUE_SOON' => ['[SRMS] 処理期限が迫っています {request_no} {title}', ['{recipient} 様', '{step} ステップの処理期限({due})が迫っています。', '', '依頼番号: {request_no}', '件名: {title}', '', '{link}', '', '会社: {company}']],
            'DUE_OVER' => ['[SRMS] 処理期限超過 {request_no} {title}', ['{recipient} 様', '{step} ステップの処理期限({due})を過ぎています。至急ご対応ください。', '', '依頼番号: {request_no}', '件名: {title}', '', '{link}', '', '会社: {company}']],
            'SCHEDULE_REMIND' => ['[SRMS] 日程変更への回答督促 {request_no}', ['{recipient} 様', '{worker} さんの日程変更依頼({from} → {to})に対する回答期限({response_due})を過ぎています。回答があるまでステップ処理は停止しています。', '', '依頼番号: {request_no}', '件名: {title}', '', '{link}', '', '会社: {company}']],
        ];
        $en = [
            'SR_SPLIT' => ['[SRMS] Request split {request_no} {title}', ['Hello {recipient},', '{actor} split your request into two. Each request will now be handled separately by its own assignee.', '', 'Original: {request_no} {title}', 'New: {new_no} {new_title}', '', 'Original: {link}', 'New: {new_link}', '', 'Company: {company}']],
            'STEP_ACTIVE' => ['[SRMS] Action required {request_no} {title}', ['Hello {recipient},', 'Your action is required for the "{step}" step.', '', 'Request: {request_no}', 'Title: {title}', 'Requester: {requester}', 'Due: {due}', '', '{link}', '', 'Company: {company}']],
            'STEP_RETURNED' => ['[SRMS] Rework requested {request_no} {title}', ['Hello {recipient},', '{actor} rejected the request, so the "{step}" step needs rework.', '', 'Request: {request_no}', 'Title: {title}', 'Reason: {opinion}', 'Due: {due}', '', '{link}', '', 'Company: {company}']],
            'STEP_CC_ACTIVATED' => ['[SRMS] (CC) "{step}" step started {request_no}', ['Hello {recipient},', 'The "{step}" step has started on a request you are copied on. This mail is for your information; no action is needed.', '', 'Request: {request_no}', 'Title: {title}', '', '{link}', '', 'Company: {company}']],
            'STEP_CC_COMPLETED' => ['[SRMS] (CC) "{step}" step completed {request_no}', ['Hello {recipient},', 'The "{step}" step has been completed on a request you are copied on. This mail is for your information; no action is needed.', '', 'Request: {request_no}', 'Title: {title}', '', '{link}', '', 'Company: {company}']],
            'SCHEDULE_PROPOSED' => ['[SRMS] Schedule change needs your reply {request_no}', ['Hello {recipient},', '{worker} asked to change the completion date from {from} to {to}.', 'Reason: {reason} - {reason_text}', '', 'Request: {request_no}', 'Title: {title}', 'Reply by: {response_due}', '', 'Please agree, reject, or propose an alternative. Step processing is suspended until you reply.', '{link}', '', 'Company: {company}']],
            'SCHEDULE_REJECTED' => ['[SRMS] Schedule change rejected {request_no}', ['Hello {recipient},', '{actor} rejected the schedule change request. The current completion date ({from}) stays.', 'Comment: {opinion}', '', 'Request: {request_no}', 'Title: {title}', '', '{link}', '', 'Company: {company}']],
            'SCHEDULE_COUNTERED' => ['[SRMS] Alternative date proposed {request_no}', ['Hello {recipient},', '{actor} proposed {counter} as an alternative to your schedule change request. The current completion date ({from}) stays.', 'Comment: {opinion}', '', 'Request: {request_no}', 'Title: {title}', '', '{link}', '', 'Company: {company}']],
            'DUE_SOON' => ['[SRMS] Due soon {request_no} {title}', ['Hello {recipient},', 'The due date ({due}) of the "{step}" step is approaching.', '', 'Request: {request_no}', 'Title: {title}', '', '{link}', '', 'Company: {company}']],
            'DUE_OVER' => ['[SRMS] Overdue {request_no} {title}', ['Hello {recipient},', 'The due date ({due}) of the "{step}" step has passed. Please take action as soon as possible.', '', 'Request: {request_no}', 'Title: {title}', '', '{link}', '', 'Company: {company}']],
            'SCHEDULE_REMIND' => ['[SRMS] Reminder: schedule change awaiting your reply {request_no}', ['Hello {recipient},', 'The reply deadline ({response_due}) for the schedule change request from {worker} ({from} to {to}) has passed. Step processing remains suspended until you reply.', '', 'Request: {request_no}', 'Title: {title}', '', '{link}', '', 'Company: {company}']],
        ];
        $zh = [
            'SR_SPLIT' => ['[SRMS] 请求已拆分 {request_no} {title}', ['{recipient}，您好：', '{actor} 已将您的请求拆分为 2 个。每个请求将由各自的负责人单独处理。', '', '原请求：{request_no} {title}', '新请求：{new_no} {new_title}', '', '原请求：{link}', '新请求：{new_link}', '', '公司：{company}']],
            'STEP_ACTIVE' => ['[SRMS] 处理请求 {request_no} {title}', ['{recipient}，您好：', '请处理“{step}”步骤。', '', '请求编号：{request_no}', '标题：{title}', '申请人：{requester}', '处理期限：{due}', '', '{link}', '', '公司：{company}']],
            'STEP_RETURNED' => ['[SRMS] 请求补充/重新处理 {request_no} {title}', ['{recipient}，您好：', '{actor} 已驳回该请求，“{step}”步骤需要补充或重新处理。', '', '请求编号：{request_no}', '标题：{title}', '驳回原因：{opinion}', '处理期限：{due}', '', '{link}', '', '公司：{company}']],
            'STEP_CC_ACTIVATED' => ['[SRMS] (抄送) “{step}”步骤已开始 {request_no}', ['{recipient}，您好：', '您被指定为抄送人的请求已开始“{step}”步骤。本邮件仅供参考，无需任何操作。', '', '请求编号：{request_no}', '标题：{title}', '', '{link}', '', '公司：{company}']],
            'STEP_CC_COMPLETED' => ['[SRMS] (抄送) “{step}”步骤已完成 {request_no}', ['{recipient}，您好：', '您被指定为抄送人的请求已完成“{step}”步骤。本邮件仅供参考，无需任何操作。', '', '请求编号：{request_no}', '标题：{title}', '', '{link}', '', '公司：{company}']],
            'SCHEDULE_PROPOSED' => ['[SRMS] 日程变更协商请求 {request_no}', ['{recipient}，您好：', '{worker} 请求将完成日期由 {from} 变更为 {to}。', '原因：{reason} - {reason_text}', '', '请求编号：{request_no}', '标题：{title}', '回复期限：{response_due}', '', '请回复同意、驳回或提出替代方案。回复之前，该请求的步骤处理将暂停。', '{link}', '', '公司：{company}']],
            'SCHEDULE_REJECTED' => ['[SRMS] 日程变更已被驳回 {request_no}', ['{recipient}，您好：', '{actor} 已驳回日程变更请求，原完成日期（{from}）保持不变。', '意见：{opinion}', '', '请求编号：{request_no}', '标题：{title}', '', '{link}', '', '公司：{company}']],
            'SCHEDULE_COUNTERED' => ['[SRMS] 已提出替代日程 {request_no}', ['{recipient}，您好：', '{actor} 针对日程变更请求提出了替代方案 {counter}，原完成日期（{from}）保持不变。', '意见：{opinion}', '', '请求编号：{request_no}', '标题：{title}', '', '{link}', '', '公司：{company}']],
            'DUE_SOON' => ['[SRMS] 处理期限临近 {request_no} {title}', ['{recipient}，您好：', '“{step}”步骤的处理期限（{due}）即将到期。', '', '请求编号：{request_no}', '标题：{title}', '', '{link}', '', '公司：{company}']],
            'DUE_OVER' => ['[SRMS] 处理期限已超过 {request_no} {title}', ['{recipient}，您好：', '“{step}”步骤的处理期限（{due}）已过，请尽快处理。', '', '请求编号：{request_no}', '标题：{title}', '', '{link}', '', '公司：{company}']],
            'SCHEDULE_REMIND' => ['[SRMS] 催办：日程变更待回复 {request_no}', ['{recipient}，您好：', '针对 {worker} 的日程变更请求（{from} → {to}）的回复期限（{response_due}）已过。回复之前，步骤处理一直处于暂停状态。', '', '请求编号：{request_no}', '标题：{title}', '', '{link}', '', '公司：{company}']],
        ];
        $out = [];
        foreach (['ko' => $ko, 'ja' => $ja, 'en' => $en, 'zh' => $zh] as $lang => $set) {
            foreach ($set as $code => [$subject, $lines]) {
                $out[$code][$lang] = [$subject, self::b($lines)];
            }
        }
        return $out;
    }
}
