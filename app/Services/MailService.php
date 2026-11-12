<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\I18n;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\Mail\FileTransport;
use App\Services\Mail\SmtpTransport;
use App\Services\Mail\Transport;

/** 메일 큐: 템플릿 렌더링 → `notification_log` 적재 → 발송/재시도. */
final class MailService
{
    public const MAX_ATTEMPTS = 5;
    /** 재시도 간격(분): 1회 실패 후 5분, 이후 15/60/240분 */
    private const BACKOFF_MIN = [5, 15, 60, 240];
    private const LEASE_MIN = 10;

    private static ?Transport $transport = null;

    public static function setTransport(?Transport $t): void
    {
        self::$transport = $t;
    }

    /** smtp / file / off(발송하지 않고 대기열에 보관). */
    public static function driver(): string
    {
        $d = strtolower((string)env('MAIL_DRIVER', ''));
        if (in_array($d, ['smtp', 'file', 'off'], true)) {
            return $d;
        }
        if ((string)env('SMTP_HOST', '') !== '') {
            return 'smtp';
        }
        return (string)env('APP_ENV', 'production') === 'production' ? 'off' : 'file';
    }

    private static function transport(): ?Transport
    {
        if (self::$transport !== null) {
            return self::$transport;
        }
        return match (self::driver()) {
            'smtp' => new SmtpTransport(),
            'file' => new FileTransport(AttachmentService::baseDir() . '/mail'),
            default => null,
        };
    }

    public static function link(int $requestId): string
    {
        return rtrim((string)env('APP_URL', 'http://localhost:8080'), '/') . url('/requests/' . $requestId);
    }

    /** 수신자 언어로 마스터 명칭(단계명/코드명)을 번역한다. */
    public static function masterName(string $lang, string $entity, string $key, string $default): string
    {
        if ($lang === 'ko') {
            return $default;
        }
        $row = Repo::find('translation', ['entity' => $entity, 'entity_key' => $key, 'lang' => $lang]);
        return $row === null ? $default : (string)$row['text'];
    }

    /**
     * 수신자 한 명에게 보낼 메일을 대기열에 넣는다.
     * @param array<string,mixed> $req
     * @param array<string,string|array{0:string,1:string,2:string}> $vars 값이 [엔터티, 키, 기본값] 이면 수신자 언어로 번역
     * @return int|null 적재한 noti_id, 대상 없음/중복이면 null
     */
    public static function enqueue(array $req, int $userId, string $tmplCd, array $vars, string $kind = 'TO', ?string $dedupe = null): ?int
    {
        $user = Repo::find('user', ['user_id' => $userId, 'use_yn' => 1]);
        $email = $user === null ? '' : trim((string)$user['email']);
        if ($user === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        if ($dedupe !== null && Repo::find('notification_log', ['dedupe_key' => $dedupe]) !== null) {
            return null;
        }
        $lang = I18n::isEnabled((string)$user['lang']) ? (string)$user['lang'] : I18n::defaultLang();
        $tmpl = MailTemplates::find($tmplCd, $lang);
        if ($tmpl === null) {
            $tmplCd = MailTemplates::FALLBACK;
            $tmpl = MailTemplates::find($tmplCd, $lang) ?? throw new \LogicException('no mail template');
        }
        $company = Repo::queryOne('SELECT company_nm FROM company WHERE company_cd = :company_cd');
        $requester = Repo::find('user', ['user_id' => (int)$req['requester_id']]);
        $base = [
            'recipient' => (string)$user['user_nm'], 'company' => (string)($company['company_nm'] ?? Scope::cd()),
            'request_no' => (string)$req['request_no'], 'title' => (string)$req['title'],
            'requester' => (string)($requester['user_nm'] ?? ''), 'link' => self::link((int)$req['request_id']),
        ];
        $map = [];
        foreach ($base + $vars as $k => $v) {
            $text = is_array($v) ? self::masterName($lang, (string)$v[0], (string)$v[1], (string)$v[2]) : (string)$v;
            $map['{' . $k . '}'] = $text;
        }
        $subject = trim((string)preg_replace('/[\r\n\t]+/', ' ', strtr($tmpl[0], $map)));
        $body = strtr($tmpl[1], $map);
        $now = now_str();
        return Repo::insert('notification_log', [
            'request_id' => (int)$req['request_id'], 'to_user_id' => $userId, 'to_email' => $email, 'rcpt_kind' => $kind,
            'subject' => mb_substr($subject, 0, 300), 'body' => $body, 'status' => 'QUEUED', 'attempts' => 0,
            'created_at' => $now, 'tmpl_cd' => $tmplCd, 'lang' => $lang, 'dedupe_key' => $dedupe, 'next_try_at' => $now,
        ]);
    }

    /**
     * 현재 회사의 발송 대기 건을 보낸다. 같은 행을 두 프로세스가 보내지 않도록 임대(lease)로 선점한다.
     * @return array{sent:int,retry:int,failed:int}
     */
    public static function flush(int $limit = 50): array
    {
        $out = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        $tr = self::transport();
        if ($tr === null) {
            return $out;
        }
        $rows = Repo::query(
            "SELECT * FROM notification_log WHERE company_cd = :company_cd AND status IN ('QUEUED', 'SENDING') AND next_try_at <= :n
              ORDER BY noti_id LIMIT " . max(1, $limit),
            ['n' => now_str()]
        );
        foreach ($rows as $row) {
            $lease = date('Y-m-d H:i:s', time() + self::LEASE_MIN * 60);
            $claimed = Repo::exec(
                "UPDATE notification_log SET status = 'SENDING', next_try_at = :lease
                  WHERE company_cd = :company_cd AND noti_id = :id AND status = :st AND next_try_at = :nt",
                ['lease' => $lease, 'id' => (int)$row['noti_id'], 'st' => $row['status'], 'nt' => $row['next_try_at']]
            );
            if ($claimed !== 1) {
                continue;
            }
            $attempts = (int)$row['attempts'] + 1;
            try {
                $user = $row['to_user_id'] === null ? null : Repo::find('user', ['user_id' => (int)$row['to_user_id']]);
                $tr->send((string)$row['to_email'], (string)($user['user_nm'] ?? ''), (string)$row['subject'], (string)$row['body']);
                Repo::update('notification_log', ['noti_id' => (int)$row['noti_id']], [
                    'status' => 'SENT', 'attempts' => $attempts, 'sent_at' => now_str(), 'err_msg' => null, 'next_try_at' => null,
                ]);
                $out['sent']++;
            } catch (\Throwable $e) {
                $dead = $attempts >= self::MAX_ATTEMPTS;
                $wait = self::BACKOFF_MIN[min($attempts - 1, count(self::BACKOFF_MIN) - 1)];
                Repo::update('notification_log', ['noti_id' => (int)$row['noti_id']], [
                    'status' => $dead ? 'FAILED' : 'QUEUED', 'attempts' => $attempts, 'err_msg' => mb_substr($e->getMessage(), 0, 500),
                    'next_try_at' => $dead ? null : date('Y-m-d H:i:s', time() + $wait * 60),
                ]);
                $out[$dead ? 'failed' : 'retry']++;
            }
        }
        return $out;
    }

    /** 실패(FAILED) 건을 사람이 다시 대기열로 돌린다. */
    public static function requeue(int $notiId): bool
    {
        return Repo::update('notification_log', ['noti_id' => $notiId, 'status' => 'FAILED'], [
            'status' => 'QUEUED', 'attempts' => 0, 'next_try_at' => now_str(), 'err_msg' => null,
        ]) === 1;
    }

    /** 설치 점검용: 대기열을 거치지 않고 현재 발송 방식으로 바로 보낸다. */
    public static function testSend(string $to): void
    {
        $tr = self::transport() ?? throw new \RuntimeException('MAIL_DRIVER is off (set smtp or file)');
        $tr->send($to, '', '[SRMS] test mail', "SRMS test mail.\nsent at " . now_str() . "\n");
    }

    /** 웹 응답을 보낸 뒤 현재 회사의 대기 건을 시도한다. 실패해도 사용자 응답에는 영향이 없다. */
    public static function flushAfterResponse(): void
    {
        if (!Scope::has() || self::driver() === 'off') {
            return;
        }
        try {
            if (Repo::count('notification_log', ['status' => 'QUEUED']) === 0) {
                return;
            }
            ignore_user_abort(true);
            set_time_limit(60);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            self::flush(20);
        } catch (\Throwable $e) {
            error_log('mail flush failed: ' . $e->getMessage());
        }
    }
}
