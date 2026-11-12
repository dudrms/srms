<?php
declare(strict_types=1);

use App\Core\Repo;
use App\Core\Scope;
use App\Services\Dates;
use App\Services\ScheduleService as SS;
use App\Services\WorkflowService as WF;
use App\Services\RequestService as RS;

// ======================================================================= 서비스

test('schedule: 제안 검증(작업자 전용, 날짜/사유/상태)', function () {
    $w = rw();
    $rid = live($w);                       // 2단계(검토: IT_TEAM)
    $newEnd = day(45);
    perr_p(fn() => SS::propose($rid, $w['req'], $newEnd, 'RESOURCE', '인력'), 'sched.err_requester_self');
    perr_p(fn() => SS::propose($rid, $w['bizit'], $newEnd, 'RESOURCE', '인력'), 'sched.err_not_actor');
    ok(!SS::canPropose(rrow($rid), WF::service(rrow($rid)), $w['bizit']));
    ok(SS::canPropose(rrow($rid), WF::service(rrow($rid)), $w['owner']));
    perr_p(fn() => SS::propose($rid, $w['owner'], 'abc', 'RESOURCE', '인력'), 'sched.err_date');
    perr_p(fn() => SS::propose($rid, $w['owner'], day(30), 'RESOURCE', '인력'), 'sched.err_date_not_later', ['date' => day(30)]);
    perr_p(fn() => SS::propose($rid, $w['owner'], day(10), 'RESOURCE', '인력'), 'sched.err_date_not_later');
    perr_p(fn() => SS::propose($rid, $w['owner'], $newEnd, 'NOPE', '인력'), 'sched.err_reason');
    perr_p(fn() => SS::propose($rid, $w['owner'], $newEnd, 'RESOURCE', '  '), 'sched.err_reason_text');
    perr_p(fn() => SS::propose($rid, $w['owner'], $newEnd, 'RESOURCE', str_repeat('가', 1001)), 'sched.err_reason_text');
    eq(0, Repo::count('schedule_change'));

    $cid = SS::propose($rid, $w['owner'], $newEnd, 'RESOURCE', '인력 부족');
    $p = SS::pending($rid);
    eq($cid, (int)$p['change_id']);
    eq(day(30), $p['prev_end']);
    eq($newEnd, $p['new_end']);
    eq(2, (int)$p['step_no']);
    ok(RS::isLocked($rid));
    perr_p(fn() => SS::propose($rid, $w['it2'], day(50), 'RESOURCE', '또'), 'sched.err_pending');
    ok(!SS::canPropose(rrow($rid), WF::service(rrow($rid)), $w['owner']));
    eq(SS::responseDays(), 3);
    eq(Dates::addBusinessDays(date('Y-m-d'), 3), SS::responseDue($p));

    // 진행 중이 아니면 제안 불가
    $draft = mk_req($w);
    perr_p(fn() => SS::propose($draft, $w['owner'], $newEnd, 'RESOURCE', 'x'), 'sched.err_state');
});

test('schedule: 요청자만 응답, 합의 시 plan_end 확정 및 잠금 해제', function () {
    $w = rw();
    $rid = live($w);
    $cid = SS::propose($rid, $w['owner'], day(45), 'TECH_ISSUE', '기술 이슈');
    perr_p(fn() => SS::respond($rid, $cid, $w['owner'], 'AGREE', ''), 'sched.err_forbidden');
    perr_p(fn() => SS::respond($rid, $cid, $w['bizit'], 'AGREE', ''), 'sched.err_forbidden');
    perr_p(fn() => SS::respond($rid, $cid, $w['req'], 'MAYBE', ''), 'sched.err_decision');
    perr_p(fn() => SS::respond($rid, $cid, $w['req'], 'REJECT', ' '), 'sched.err_comment_required');
    perr_p(fn() => SS::respond($rid, $cid, $w['req'], 'COUNTER', ''), 'sched.err_comment_required');
    perr_p(fn() => SS::respond($rid, $cid, $w['req'], 'COUNTER', '이날로', day(45)), 'sched.err_counter_date');
    perr_p(fn() => SS::respond($rid, $cid, $w['req'], 'COUNTER', '이날로', day(-1)), 'sched.err_counter_date');
    perr_p(fn() => SS::respond($rid, $cid, $w['req'], 'COUNTER', '이날로', ''), 'sched.err_counter_date');
    perr_p(fn() => SS::respond($rid, 9999, $w['req'], 'AGREE', ''), 'process.err_not_found');
    eq(null, rrow($rid)['plan_end']);

    SS::respond($rid, $cid, $w['req'], 'AGREE', '');
    eq(day(45), rrow($rid)['plan_end']);
    eq(day(30), rrow($rid)['req_due_date'], '완료요구일은 그대로');
    eq(day(45), SS::effectiveDue(rrow($rid)));
    eq('AGREED', Repo::find('schedule_change', ['change_id' => $cid])['status']);
    ok(!RS::isLocked($rid));
    perr_p(fn() => SS::respond($rid, $cid, $w['req'], 'AGREE', ''), 'sched.err_not_pending');
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''));

    // 합의된 계획일(45일) 이후로만 다시 제안 가능
    $cur = (int)rrow($rid)['current_step_no'];
    $actor = $cur === 3 ? $w['bizit'] : $w['owner'];
    perr_p(fn() => SS::propose($rid, $actor, day(40), 'RESOURCE', 'x'), 'sched.err_date_not_later', ['date' => day(45)]);
    SS::propose($rid, $actor, day(50), 'RESOURCE', '재연장');
    eq(day(45), SS::pending($rid)['prev_end']);
});

test('schedule: 반려/대안은 기존 일정 유지, 응답 기록과 이력', function () {
    $w = rw();
    $rid = live($w);
    $c1 = SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력');
    SS::respond($rid, $c1, $w['req'], 'REJECT', '불가합니다');
    eq(null, rrow($rid)['plan_end']);
    ok(!RS::isLocked($rid));
    $c2 = SS::propose($rid, $w['owner'], day(50), 'RESOURCE', '재요청');
    SS::respond($rid, $c2, $w['req'], 'COUNTER', '이날까지는 가능', day(40));
    eq(null, rrow($rid)['plan_end'], '대안만으로는 확정되지 않음');
    $h = SS::history($rid);
    eq(2, count($h));
    eq($c2, (int)$h[0]['change_id'], '최신 우선');
    eq('COUNTERED', $h[0]['status']);
    eq(day(40), $h[0]['agreements'][0]['counter_end']);
    eq('REJECTED', $h[1]['status']);
    eq('불가합니다', $h[1]['agreements'][0]['comment']);
    eq('Owner', $h[0]['proposer_nm']);
    eq('Req', $h[1]['agreements'][0]['user_nm']);
});

test('schedule: 철회는 제안자 본인만, 취소 시 협의 대기 정리', function () {
    $w = rw();
    $rid = live($w);
    $cid = SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력');
    perr_p(fn() => SS::withdraw($rid, $cid, $w['it2']), 'sched.err_not_proposer');
    perr_p(fn() => SS::withdraw($rid, $cid, $w['req']), 'sched.err_not_proposer');
    SS::withdraw($rid, $cid, $w['owner']);
    eq('WITHDRAWN', Repo::find('schedule_change', ['change_id' => $cid])['status']);
    ok(!RS::isLocked($rid));
    perr_p(fn() => SS::withdraw($rid, $cid, $w['owner']), 'sched.err_not_pending');
    perr_p(fn() => SS::respond($rid, $cid, $w['req'], 'AGREE', ''), 'sched.err_not_pending');

    SS::propose($rid, $w['owner'], day(46), 'RESOURCE', '인력');
    WF::cancel($rid, $w['req']);
    eq(0, Repo::count('schedule_change', ['request_id' => $rid, 'status' => 'PROPOSED']));
    eq(null, SS::pending($rid));
});

test('schedule: 요청자 처리함에 협의 대기 건 표시', function () {
    $w = rw();
    $rid = live($w);
    eq(0, list_of($w['req'], 'inbox')['total']);
    SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력');
    eq(1, list_of($w['req'], 'inbox')['total']);
    $row = list_of($w['req'], 'inbox')['rows'][0];
    eq(1, (int)$row['sched_pending']);
    eq(0, list_of($w['bizit'], 'inbox')['total']);
});

test('schedule: DB 규칙(요청당 협의 대기 1건, 회사 격리)', function () {
    $w = rw();
    $rid = live($w);
    SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력');
    throws(fn() => Repo::insert('schedule_change', [
        'request_id' => $rid, 'step_no' => 2, 'prev_end' => day(30), 'new_end' => day(50), 'reason_cd' => 'ETC', 'reason_text' => 'x',
        'proposer_id' => $w['it2'], 'status' => 'PROPOSED', 'proposed_at' => now_str(),
    ]), PDOException::class);
    Scope::set('B01');
    eq(0, Repo::count('schedule_change'), '다른 회사에서는 보이지 않음');
    Scope::set('A01');
    eq(1, Repo::count('schedule_change'));
});

// ======================================================================= HTTP

test('http: 일정 협의 화면/제안/응답/철회 흐름과 권한', function () {
    $w = rw();
    $rid = live($w);

    as_user('owner');
    $body = http('GET', "/requests/$rid")->body;
    ok(str_contains($body, e(t('sched.propose_btn'))), '작업자에게 제안 버튼');

    // 요청자는 제안 불가(화면에 폼 없음, 직접 POST 도 거부)
    as_user('req');
    $body = http('GET', "/requests/$rid")->body;
    ok(!str_contains($body, e(t('sched.propose_btn'))));
    http('POST', "/requests/$rid/schedule", ['new_end' => day(45), 'reason_cd' => 'RESOURCE', 'reason_text' => 'x']);
    eq(t('sched.err_requester_self'), last_flash());

    // 제안(CSRF 필수)
    as_user('owner');
    eq(403, http('POST', "/requests/$rid/schedule", ['new_end' => day(45), 'reason_cd' => 'RESOURCE', 'reason_text' => 'x'], false)->status);
    $r = http('POST', "/requests/$rid/schedule", ['new_end' => day(45), 'reason_cd' => 'RESOURCE', 'reason_text' => '<b>인력</b> 부족']);
    eq(302, $r->status);
    eq(t('sched.ok_proposed'), last_flash());
    $body = http('GET', "/requests/$rid")->body;
    ok(str_contains($body, e(t('request.locked_badge'))) || str_contains($body, e(t('sched.pending'))));
    ok(str_contains($body, '&lt;b&gt;인력&lt;/b&gt; 부족'), '사유 이스케이프');
    ok(!str_contains($body, '<b>인력</b>'));
    ok(str_contains($body, e(t('sched.withdraw'))), '제안자에게 철회 버튼');
    ok(!str_contains($body, 'value="AGREE"'), '제안자에게 응답 버튼 없음');
    $cid = (int)SS::pending($rid)['change_id'];

    // 제3자: 응답 불가
    as_user('it2');
    http('POST', "/requests/$rid/schedule/$cid/respond", ['decision' => 'AGREE']);
    eq(t('sched.err_forbidden'), last_flash());
    http('POST', "/requests/$rid/schedule/$cid/withdraw");
    eq(t('sched.err_not_proposer'), last_flash());
    // 다른 회사 사용자는 404
    as_user('user1', 'B01', 'User5678');
    eq(404, http('POST', "/requests/$rid/schedule/$cid/respond", ['decision' => 'AGREE'])->status);

    // 요청자 응답: 반려는 의견 필요
    as_user('req');
    $body = http('GET', "/requests/$rid")->body;
    ok(str_contains($body, 'value="AGREE"') && str_contains($body, 'value="COUNTER"'));
    ok(!str_contains($body, 'value="PASS"'), '협의 중에는 처리 버튼 없음');
    http('POST', "/requests/$rid/schedule/$cid/respond", ['decision' => 'REJECT', 'comment' => '']);
    eq(t('sched.err_comment_required'), last_flash());
    http('POST', "/requests/$rid/schedule/$cid/respond", ['decision' => 'AGREE']);
    eq(t('sched.ok_agreed'), last_flash());
    eq(day(45), rrow($rid)['plan_end']);

    // 상세에 합의일과 이력/타임라인
    $body = http('GET', "/requests/$rid")->body;
    ok(str_contains($body, e(t('schst.AGREED'))));
    ok(str_contains($body, e(t('tl.SCHEDULE_PROPOSE'))));
    ok(str_contains($body, e(t('tl.SCHEDULE_AGREE'))));

    // 철회 경로
    as_user('owner');
    http('POST', "/requests/$rid/schedule", ['new_end' => day(60), 'reason_cd' => 'ETC', 'reason_text' => '추가']);
    $cid2 = (int)SS::pending($rid)['change_id'];
    http('POST', "/requests/$rid/schedule/$cid2/withdraw");
    eq(t('sched.ok_withdrawn'), last_flash());
    eq(null, SS::pending($rid));
    ok(str_contains(http('GET', "/requests/$rid")->body, e(t('tl.SCHEDULE_WITHDRAW'))));
});

test('http: 일정 협의 권한 라우트(SCHEDULE_PROPOSE 없는 사용자 403)', function () {
    $w = rw();
    $rid = live($w);
    SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력');
    $cid = (int)SS::pending($rid)['change_id'];
    Scope::set('A01');
    $noPerm = \App\Services\Users::create('noperm', 'NoPerm', 'np@example.com', $w['biz'], 'ko', PW, []);
    as_user('noperm');
    eq(403, http('POST', "/requests/$rid/schedule/$cid/respond", ['decision' => 'AGREE'])->status);
});
