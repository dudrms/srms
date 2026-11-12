<?php
declare(strict_types=1);

use App\Core\Env;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\Dates;
use App\Services\MailService;
use App\Services\MailTemplates;
use App\Services\Mail\MemoryTransport;
use App\Services\Reminders;
use App\Services\ScheduleService as SS;
use App\Services\WorkflowService as WF;

/** @return list<array<string,mixed>> */
function queue(?int $rid = null): array
{
    return Repo::list('notification_log', $rid === null ? [] : ['request_id' => $rid], 'noti_id');
}

/** @return list<string> 수신자 로그인 ID(정렬) */
function rcpts(array $rows): array
{
    $ids = array_map(static fn(array $r): string => (string)Repo::find('user', ['user_id' => (int)$r['to_user_id']])['login_id'], $rows);
    sort($ids);
    return $ids;
}

function with_mail(callable $fn): void
{
    $tr = new MemoryTransport();
    MailService::setTransport($tr);
    Env::set('MAIL_DRIVER', '');
    try {
        $fn($tr);
    } finally {
        MailService::setTransport(null);
        Env::set('MAIL_DRIVER', '');
    }
}

function general_step_id(int $no): int
{
    $ver = Repo::find('process_version', ['process_id' => pid_of('GENERAL'), 'status' => 'ACTIVE']);
    return (int)Repo::find('process_step', ['version_id' => (int)$ver['version_id'], 'step_no' => $no])['step_id'];
}

// ======================================================================= 템플릿/렌더링

test('mail: 기본 템플릿 시드(회사별/언어별)와 변수 치환', function () {
    $w = rw();
    foreach (MailTemplates::codes() as $code) {
        foreach (MailTemplates::LANGS as $lang) {
            ok(Repo::find('mail_template', ['tmpl_cd' => $code, 'lang' => $lang]) !== null, "$code/$lang");
        }
    }
    Scope::set('B01');
    eq(count(MailTemplates::codes()) * count(MailTemplates::LANGS), Repo::count('mail_template'), '다른 회사에도 시드');
    Scope::set('A01');
    foreach (MailTemplates::defaults() as $code => $byLang) {
        foreach ($byLang as $lang => [$subject, $body]) {
            preg_match_all('/\{([a-z_]+)\}/', $subject . $body, $m);
            eq([], array_values(array_diff($m[1], MailTemplates::VARS)), "$code/$lang 알 수 없는 변수");
        }
    }
    // 수신자 언어로 발송, 단계명도 번역
    Repo::update('user', ['user_id' => $w['owner']], ['lang' => 'ja']);
    $rid = live($w);
    $rows = Repo::list('notification_log', ['to_user_id' => $w['owner']]);
    eq(1, count($rows));
    ok(str_starts_with($rows[0]['subject'], '[SRMS] 処理依頼'), $rows[0]['subject']);
    ok(str_contains($rows[0]['body'], 'レビュー'), '단계명 일본어');
    ok(str_contains($rows[0]['body'], 'http://localhost:8080/requests/' . $rid), '링크');
    ok(!str_contains($rows[0]['body'] . $rows[0]['subject'], '{'), '치환되지 않은 변수 없음');
    eq('ja', $rows[0]['lang']);
    $it2 = Repo::list('notification_log', ['to_user_id' => $w['it2']]);
    ok(str_starts_with($it2[0]['subject'], '[SRMS] 처리 요청'), 'ko 사용자는 한국어');
    // 회사 템플릿 수정이 반영되고, 행이 없으면 내장 기본값으로 폴백
    Repo::update('user', ['user_id' => $w['owner']], ['lang' => 'zh']);
    $ridz = live($w, ['title' => '중문']);
    $zr = Repo::list('notification_log', ['request_id' => $ridz, 'to_user_id' => $w['owner']]);
    ok(str_starts_with($zr[0]['subject'], '[SRMS] 处理请求'), $zr[0]['subject']);
    ok(str_contains($zr[0]['body'], '审查'), '단계명 중국어');
    eq('zh', $zr[0]['lang']);
    Repo::update('mail_template', ['tmpl_cd' => 'STEP_ACTIVE', 'lang' => 'ko'], ['subject' => '[할일] {title}']);
    $rid2 = live($w, ['title' => '두번째']);
    $last = Repo::list('notification_log', ['request_id' => $rid2, 'to_user_id' => $w['it2']]);
    eq('[할일] 두번째', $last[0]['subject']);
    Repo::delete('mail_template', ['tmpl_cd' => 'STEP_ACTIVE']);
    $rid3 = live($w, ['title' => '세번째']);
    $last = Repo::list('notification_log', ['request_id' => $rid3, 'to_user_id' => $w['it2']]);
    eq('[SRMS] 처리 요청 ' . rrow($rid3)['request_no'] . ' 세번째', $last[0]['subject']);
});

test('mail: 제목 개행 제거, 이메일 없는/비활성 사용자 제외', function () {
    $w = rw();
    $rid = live($w);
    \App\Core\Db::exec("UPDATE request SET title = :t WHERE request_id = :r", ['t' => "A\r\nBcc: evil@x.com", 'r' => $rid]);
    $req = rrow($rid);
    $id = MailService::enqueue($req, $w['owner'], 'STEP_ACTIVE', ['step' => 'S', 'due' => '-'], 'TO');
    $row = Repo::find('notification_log', ['noti_id' => $id]);
    ok(!str_contains($row['subject'], "\r") && !str_contains($row['subject'], "\n"));
    Repo::update('user', ['user_id' => $w['it2']], ['email' => 'not-an-email']);
    eq(null, MailService::enqueue($req, $w['it2'], 'STEP_ACTIVE', ['step' => 'S', 'due' => '-']));
    Repo::update('user', ['user_id' => $w['it2']], ['email' => 'it2@example.com', 'use_yn' => 0]);
    eq(null, MailService::enqueue($req, $w['it2'], 'STEP_ACTIVE', ['step' => 'S', 'due' => '-']));
    // 템플릿 코드가 없으면 기본 템플릿
    $id = MailService::enqueue($req, $w['owner'], 'NO_SUCH_TMPL', ['step' => 'S', 'due' => '-']);
    eq('STEP_ACTIVE', Repo::find('notification_log', ['noti_id' => $id])['tmpl_cd']);
});

// ======================================================================= 수신 규칙

test('mail: 단계 활성화는 처리자에게만, 본인 행위는 제외', function () {
    $w = rw();
    $rid = live($w);
    $rows = queue($rid);
    eq(['it2', 'owner'], rcpts($rows), '2단계(전산팀) 처리자 전원, 요청자 제외');
    foreach ($rows as $r) {
        eq('TO', $r['rcpt_kind']);
        eq('QUEUED', $r['status']);
        eq('STEP_ACTIVE', $r['tmpl_cd']);
    }
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''));
    $new = array_slice(queue($rid), 2);
    eq(['bizit'], rcpts($new), '3단계(주관부서 전산담당)만');
    WF::act($rid, $w['bizit'], 'PASS', '');
    $new = array_slice(queue($rid), 3);
    eq(['it2', 'owner'], rcpts($new), '4단계 승인자(전산팀)');
    $before = count(queue($rid));
    WF::act($rid, $w['owner'], 'PASS', '');           // 5단계(수행)의 처리자는 owner 본인
    eq($before, count(queue($rid)), '본인이 방금 한 행위의 결과는 본인에게 보내지 않음');
    WF::act($rid, $w['owner'], 'PASS', '');           // 6단계(검수): 요청자
    $new = array_slice(queue($rid), $before);
    eq(['req'], rcpts($new));
});

test('mail: 반려/보완 요청은 재처리해야 하는 사람에게', function () {
    $w = rw();
    $rid = live($w);
    WF::act($rid, $w['owner'], 'PASS', '');
    $n = count(queue($rid));
    WF::act($rid, $w['bizit'], 'REJECT', '범위가 다릅니다');      // 3단계 → 2단계로 되돌림
    $new = array_slice(queue($rid), $n);
    eq(['it2', 'owner'], rcpts($new));
    foreach ($new as $r) {
        eq('STEP_RETURNED', $r['tmpl_cd']);
        ok(str_contains($r['body'], '범위가 다릅니다'));
        ok(str_contains($r['body'], 'Bizit'), '반려한 사람');
    }
    $n = count(queue($rid));
    eq('RETURNED', WF::act($rid, $w['owner'], 'REJECT', '요건 보완 필요'));   // 2단계 → 요청자
    $new = array_slice(queue($rid), $n);
    eq(['req'], rcpts($new));
    eq('STEP_RETURNED', $new[0]['tmpl_cd']);
    // 재제출하면 다시 2단계 처리자에게 새 회차 메일(중복 키 충돌 없음)
    $n = count(queue($rid));
    WF::submit($rid, $w['req']);
    eq(['it2', 'owner'], rcpts(array_slice(queue($rid), $n)));
});

test('mail: 참조자 옵션(NONE/ACTIVATE/COMPLETE/BOTH)과 처리자 중복 제거', function () {
    $w = rw();
    $ver = Repo::find('process_version', ['process_id' => pid_of('GENERAL'), 'status' => 'ACTIVE']);
    $s2 = general_step_id(2);
    $s3 = general_step_id(3);
    Repo::insert('process_step_cc', ['step_id' => $s2, 'cc_type' => 'USER', 'cc_value' => (string)$w['other'], 'notify_on' => 'ACTIVATE']);
    Repo::insert('process_step_cc', ['step_id' => $s2, 'cc_type' => 'USER', 'cc_value' => (string)$w['othbiz'], 'notify_on' => 'NONE']);
    Repo::insert('process_step_cc', ['step_id' => $s2, 'cc_type' => 'USER', 'cc_value' => (string)$w['bizit'], 'notify_on' => 'COMPLETE']);   // 3단계 처리자
    Repo::insert('process_step_cc', ['step_id' => $s2, 'cc_type' => 'USER', 'cc_value' => (string)$w['owner'], 'notify_on' => 'ACTIVATE']);    // 2단계 처리자
    Repo::insert('process_step_cc', ['step_id' => $s3, 'cc_type' => 'DEPT', 'cc_value' => (string)$w['biz'], 'notify_on' => 'BOTH']);
    Repo::insert('process_step_cc', ['step_id' => $s3, 'cc_type' => 'REQUESTER', 'cc_value' => null, 'notify_on' => 'COMPLETE']);
    $rid = live($w);
    $rows = queue($rid);
    $cc = array_values(array_filter($rows, static fn(array $r): bool => $r['rcpt_kind'] === 'CC'));
    eq(['other'], rcpts($cc), '시작 시 참조 메일은 ACTIVATE 만, 처리자와 겹치면 TO 만');
    eq('STEP_CC_ACTIVATED', $cc[0]['tmpl_cd']);
    ok(!str_contains($cc[0]['body'], '처리 기한'), '참조 메일에는 처리 안내 없음');
    eq(['it2', 'owner'], rcpts(array_filter($rows, static fn(array $r): bool => $r['rcpt_kind'] === 'TO')));
    // 2단계 완료: bizit 는 3단계 처리자이므로 TO 한 통만(참조 완료 메일 없음). 3단계 참조자(DEPT=biz 부서)는 시작 시 받되 bizit 는 이미 TO
    $n = count($rows);
    WF::act($rid, $w['owner'], 'PASS', '');
    $new = array_slice(queue($rid), $n);
    $to = array_values(array_filter($new, static fn(array $r): bool => $r['rcpt_kind'] === 'TO'));
    eq(['bizit'], rcpts($to));
    $cc = array_values(array_filter($new, static fn(array $r): bool => $r['rcpt_kind'] === 'CC'));
    eq(['req'], rcpts($cc), 'biz 부서 참조자는 bizit 와 요청자(김요청, biz 부서)... 요청자는 DEPT 로 한 번만');
    eq(1, count($cc));
    // 3단계 완료 시: REQUESTER(COMPLETE)·DEPT(BOTH) 참조자. 3단계 처리자 bizit 본인은 제외, 요청자 req 는 DEPT/REQUESTER 중복이라 한 통
    $n = count(queue($rid));
    WF::act($rid, $w['bizit'], 'PASS', '');
    $new = array_slice(queue($rid), $n);
    $cc = array_values(array_filter($new, static fn(array $r): bool => $r['rcpt_kind'] === 'CC'));
    eq(['req'], rcpts($cc));
    eq('STEP_CC_COMPLETED', $cc[0]['tmpl_cd']);
    // NONE 으로 지정된 참조자에게는 한 통도 가지 않음
    eq(0, Repo::count('notification_log', ['to_user_id' => $w['othbiz']]));
});

test('mail: 단계별 지정 템플릿 코드 사용', function () {
    $w = rw();
    Repo::insert('mail_template', ['tmpl_cd' => 'REVIEW_REQ', 'lang' => 'ko', 'subject' => '검토 부탁 {request_no}', 'body' => '{recipient} / {step}']);
    Repo::update('process_step', ['step_id' => general_step_id(2)], ['notify_tmpl' => 'REVIEW_REQ']);
    $rid = live($w);
    $rows = queue($rid);
    eq('REVIEW_REQ', $rows[0]['tmpl_cd']);
    eq('검토 부탁 ' . rrow($rid)['request_no'], $rows[0]['subject']);
    ok(str_contains($rows[0]['body'], '검토'));
});

test('mail: 일정 변경 제안/반려/대안은 회신해야 하는 쪽에만', function () {
    $w = rw();
    $rid = live($w);
    $n = count(queue($rid));
    $c1 = SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력 부족');
    $new = array_slice(queue($rid), $n);
    eq(['req'], rcpts($new));
    eq('SCHEDULE_PROPOSED', $new[0]['tmpl_cd']);
    ok(str_contains($new[0]['body'], day(30)) && str_contains($new[0]['body'], day(45)));
    ok(str_contains($new[0]['body'], '리소스 부족'), '사유명');
    ok(str_contains($new[0]['body'], Dates::addBusinessDays(date('Y-m-d'), 3)), '회신 기한');
    $n = count(queue($rid));
    SS::respond($rid, $c1, $w['req'], 'REJECT', '불가');
    $new = array_slice(queue($rid), $n);
    eq(['owner'], rcpts($new));
    eq('SCHEDULE_REJECTED', $new[0]['tmpl_cd']);
    $c2 = SS::propose($rid, $w['owner'], day(50), 'RESOURCE', '재요청');
    $n = count(queue($rid));
    SS::respond($rid, $c2, $w['req'], 'COUNTER', '이날까지', day(40));
    $new = array_slice(queue($rid), $n);
    eq('SCHEDULE_COUNTERED', $new[0]['tmpl_cd']);
    ok(str_contains($new[0]['body'], day(40)));
    $c3 = SS::propose($rid, $w['owner'], day(41), 'RESOURCE', '조정');
    $n = count(queue($rid));
    SS::respond($rid, $c3, $w['req'], 'AGREE', '');
    eq($n, count(queue($rid)), '합의는 메일 없음');
});

// ======================================================================= 발송/재시도

test('mail: 발송, 실패 재시도(백오프)와 최대 횟수, 수동 재큐', function () {
    with_mail(function (MemoryTransport $tr) {
        $w = rw();
        $rid = live($w);
        $tr->failTo = ['it2@example.com'];
        eq(['sent' => 1, 'retry' => 1, 'failed' => 0], MailService::flush());
        $rows = queue($rid);
        $ok = array_values(array_filter($rows, static fn(array $r): bool => $r['to_email'] === 'owner@example.com'))[0];
        eq('SENT', $ok['status']);
        ok($ok['sent_at'] !== null);
        $bad = array_values(array_filter($rows, static fn(array $r): bool => $r['to_email'] === 'it2@example.com'))[0];
        eq('QUEUED', $bad['status']);
        eq(1, (int)$bad['attempts']);
        ok(str_contains((string)$bad['err_msg'], 'simulated'));
        ok($bad['next_try_at'] > now_str(), '다음 시도는 미래');
        eq(['sent' => 0, 'retry' => 0, 'failed' => 0], MailService::flush(), '아직 시도 시각이 안 됨');
        eq(1, count($tr->sent));
        for ($i = 2; $i <= MailService::MAX_ATTEMPTS; $i++) {
            Repo::update('notification_log', ['noti_id' => $bad['noti_id']], ['next_try_at' => date('Y-m-d H:i:s', time() - 5)]);
            MailService::flush();
        }
        $bad = Repo::find('notification_log', ['noti_id' => $bad['noti_id']]);
        eq('FAILED', $bad['status']);
        eq(MailService::MAX_ATTEMPTS, (int)$bad['attempts']);
        eq(['sent' => 0, 'retry' => 0, 'failed' => 0], MailService::flush(), 'FAILED 는 자동 재시도하지 않음');
        $tr->failTo = [];
        ok(MailService::requeue((int)$bad['noti_id']));
        ok(!MailService::requeue((int)$ok['noti_id']), 'FAILED 가 아니면 재큐 불가');
        eq(['sent' => 1, 'retry' => 0, 'failed' => 0], MailService::flush());
        eq('SENT', Repo::find('notification_log', ['noti_id' => $bad['noti_id']])['status']);
        eq(2, count($tr->sent));
        eq('Owner', $tr->sent[0]['name']);
    });
});

test('mail: 발송 중(임대) 행은 건너뛰고 임대가 만료되면 재발송, driver=off 면 보관', function () {
    with_mail(function (MemoryTransport $tr) {
        $w = rw();
        $rid = live($w);
        $rows = queue($rid);
        Repo::update('notification_log', ['noti_id' => $rows[0]['noti_id']], ['status' => 'SENDING', 'next_try_at' => date('Y-m-d H:i:s', time() + 600)]);
        eq(1, MailService::flush()['sent'], '임대 중인 행은 건너뜀');
        Repo::update('notification_log', ['noti_id' => $rows[0]['noti_id']], ['next_try_at' => date('Y-m-d H:i:s', time() - 1)]);
        eq(1, MailService::flush()['sent'], '임대 만료 후 재시도');
        $rid2 = live($w, ['title' => 'off']);
        Env::set('MAIL_DRIVER', 'off');
        MailService::setTransport(null);
        eq(['sent' => 0, 'retry' => 0, 'failed' => 0], MailService::flush());
        eq(2, Repo::count('notification_log', ['request_id' => $rid2, 'status' => 'QUEUED']));
        eq('off', MailService::driver());
        Env::set('MAIL_DRIVER', '');
    });
});

test('mail: 큐는 회사별로 격리된다', function () {
    with_mail(function (MemoryTransport $tr) {
        $w = rw();
        live($w);
        Scope::set('B01');
        eq(['sent' => 0, 'retry' => 0, 'failed' => 0], MailService::flush());
        eq(0, count($tr->sent));
        eq(0, Repo::count('notification_log'));
        Scope::set('A01');
        eq(2, MailService::flush()['sent']);
    });
});

test('mail: file 드라이버는 .eml 을 남긴다', function () {
    $w = rw();
    $dir = sys_get_temp_dir() . '/srms_mail_' . bin2hex(random_bytes(4));
    Env::set('STORAGE_PATH', $dir);
    Env::set('MAIL_DRIVER', 'file');
    try {
        live($w);
        eq(2, MailService::flush()['sent']);
        $files = glob($dir . '/mail/*.eml') ?: [];
        eq(2, count($files));
        $txt = (string)file_get_contents($files[0]);
        ok(str_contains($txt, 'Subject: [SRMS]') && str_contains($txt, 'text/plain; charset=UTF-8'));
    } finally {
        Env::set('MAIL_DRIVER', '');
        Env::set('STORAGE_PATH', 'storage');
        rm_rf($dir);
    }
});

// ======================================================================= 독촉

test('reminders: 기한 임박/초과는 미피드백 처리자에게 한 번만', function () {
    $w = rw();
    $rid = live($w);
    $today = date('Y-m-d');
    $rs = Repo::find('request_step', ['request_id' => $rid, 'step_no' => 2]);
    eq(['soon' => 0, 'over' => 0, 'schedule' => 0], Reminders::run(), '2영업일 기한은 아직 임박 아님');
    Repo::update('request_step', ['rstep_id' => $rs['rstep_id']], ['due_at' => Dates::addBusinessDays($today, 1)]);
    $n = count(queue($rid));
    eq(2, Reminders::run()['soon']);
    $new = array_slice(queue($rid), $n);
    eq(['it2', 'owner'], rcpts($new));
    eq('DUE_SOON', $new[0]['tmpl_cd']);
    eq(0, Reminders::run()['soon'], '중복 적재 없음');
    // 처리한 사람은 제외: owner 가 반려가 아닌 부분 처리를 한 것처럼 approval 이 있으면 미피드백자만
    Repo::update('request_step', ['rstep_id' => $rs['rstep_id']], ['due_at' => date('Y-m-d', strtotime('-1 day'))]);
    Repo::insert('approval', ['rstep_id' => $rs['rstep_id'], 'actor_id' => $w['owner'], 'action' => 'APPROVE', 'opinion' => null, 'acted_at' => now_str(), 'round' => (int)$rs['round']]);
    $n = count(queue($rid));
    eq(1, Reminders::run()['over']);
    $new = array_slice(queue($rid), $n);
    eq(['it2'], rcpts($new));
    eq('DUE_OVER', $new[0]['tmpl_cd']);
    eq(0, Reminders::run()['over']);
});

test('reminders: 일정 협의 중에는 단계 독촉 안 함, 회신 기한 초과 시 요청자에게 매일 한 통', function () {
    $w = rw();
    $rid = live($w);
    $rs = Repo::find('request_step', ['request_id' => $rid, 'step_no' => 2]);
    Repo::update('request_step', ['rstep_id' => $rs['rstep_id']], ['due_at' => date('Y-m-d', strtotime('-1 day'))]);
    $cid = SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력');
    eq(['soon' => 0, 'over' => 0, 'schedule' => 0], Reminders::run(), '회신 기한 전, 잠금 중 단계 독촉 없음');
    Repo::update('schedule_change', ['change_id' => $cid], ['proposed_at' => date('Y-m-d H:i:s', strtotime('-10 days'))]);
    $n = count(queue($rid));
    $r = Reminders::run();
    eq(1, $r['schedule']);
    eq(0, $r['over'], '잠금 중에는 단계 독촉 없음');
    $new = array_slice(queue($rid), $n);
    eq(['req'], rcpts($new));
    eq('SCHEDULE_REMIND', $new[0]['tmpl_cd']);
    eq(0, Reminders::run()['schedule'], '같은 날 중복 없음');
    eq(1, Reminders::run(date('Y-m-d', strtotime('+1 day')))['schedule'], '다음 날 다시');
});
