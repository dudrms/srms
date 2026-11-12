<?php
declare(strict_types=1);

use App\Core\Repo;
use App\Core\Scope;
use App\Services\ReportService;
use App\Services\RequestService as RS;
use App\Services\ScheduleService as SS;
use App\Services\Seeder;
use App\Services\SplitService as SP;
use App\Services\WorkflowService as WF;
use App\Services\WorklogService as WL;

/** 일반 프로세스(7단계 픽스처)의 수행(5단계, 서비스 전산 담당자) 단계까지 진행한 요청. */
function sp_to_work(array $w): int
{
    $rid = live($w);
    WF::act($rid, $w['owner'], 'PASS', '');
    WF::act($rid, $w['bizit'], 'PASS', '');
    WF::act($rid, $w['owner'], 'PASS', '');
    return $rid;
}

function sp_in(array $w, array $o = []): array
{
    return $o + ['title' => '분할된 SR', 'note' => '급여 부분만 처리', 'new_assignee_id' => (string)$w['it2'], 'orig_assignee_id' => ''];
}

/** @return list<int> */
function sp_inbox(int $uid): array
{
    return array_map('intval', array_column(list_of($uid, 'inbox')['rows'], 'request_id'));
}

test('split: 요청별 담당자가 서비스 전산 담당자보다 우선해 처리자로 판정된다', function () {
    $w = rw();
    $rid = sp_to_work($w);
    $svc = WF::service(rrow($rid));
    ok(RS::canAct(rrow($rid), $svc, $w['owner']), '기본은 서비스 전산 담당자');
    ok(!RS::canAct(rrow($rid), $svc, $w['it2']));

    $before = count(queue($rid));
    SP::assign($rid, $w['owner'], $w['it2']);
    $req = rrow($rid);
    eq($w['it2'], (int)$req['it_assignee_id']);
    ok(RS::canAct($req, $svc, $w['it2']), '지정된 담당자가 처리');
    ok(!RS::canAct($req, $svc, $w['owner']), '서비스 담당자는 대체된다');
    perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', ''), 'request.err_not_actor');
    ok(in_array($rid, sp_inbox($w['it2']), true), '새 담당자의 처리함');
    ok(!in_array($rid, sp_inbox($w['owner']), true));
    ok(can_view($rid, $w['it2']));
    eq(['it2'], rcpts(array_slice(queue($rid), $before)), '새 담당자에게 처리 요청 메일');
    SP::assign($rid, $w['owner'], $w['it2']);
    eq($before + 1, count(queue($rid)), '같은 담당자로 다시 지정해도 변화 없음');

    SP::assign($rid, $w['owner'], null);
    eq(null, rrow($rid)['it_assignee_id']);
    ok(RS::canAct(rrow($rid), $svc, $w['owner']), '서비스 담당자로 복귀');

    SP::assign($rid, $w['owner'], $w['it2']);
    Repo::update('user', ['user_id' => $w['it2']], ['use_yn' => 0]);
    ok(!RS::canAct(rrow($rid), $svc, $w['it2']) && !RS::canAct(rrow($rid), $svc, $w['owner']), '비활성 담당자는 처리 불가');
    perr_p(fn() => SP::assign($rid, $w['owner'], $w['it2']), 'split.err_assignee');
    SP::assign($rid, $w['owner'], null);
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''), '다시 지정하면 진행');
});

test('split: 새 SR 은 원본의 요청일(최초 제출일)을 상속한다', function () {
    $w = rw();
    $rid = live($w);
    Repo::update('request', ['request_id' => $rid], ['request_date' => '2026-01-05']);
    $child = SP::split($rid, $w['owner'], sp_in($w));
    eq('2026-01-05', rrow($child)['request_date'], '분할 날짜가 아니라 원본의 요청일');
    eq('2026-01-05', rrow($rid)['request_date'], '원본은 그대로');
});

test('split: 담당자 지정은 진행 중인 일반 요청만', function () {
    $w = rw();
    perr_p(fn() => SP::assign(mk_req($w), $w['owner'], $w['it2']), 'split.err_state');
    $log = WL::save(null, $w['owner'], wl_in($w));
    perr_p(fn() => SP::assign($log, $w['owner'], $w['it2']), 'split.err_state');
    perr_p(fn() => SP::assign(sp_to_work($w), $w['owner'], 99999), 'split.err_assignee');
});

test('split: 원본은 그대로 두고 같은 서비스·유형의 새 SR 이 현재 단계에서 이어진다', function () {
    $w = rw();
    $rid = live($w, ['priority' => 'HIGH']);
    $before = rrow($rid);
    $child = SP::split($rid, $w['owner'], sp_in($w));
    $c = rrow($child);
    $p = rrow($rid);
    eq($rid, (int)$c['parent_id']);
    eq($w['owner'], (int)$c['split_by']);
    eq('IN_PROGRESS', $c['status']);
    eq(2, (int)$c['current_step_no']);
    foreach (['service_id', 'maint_type', 'requester_id', 'priority', 'version_id', 'req_due_date'] as $k) {
        eq((string)$before[$k], (string)$c[$k], $k);
    }
    eq($w['it2'], (int)$c['it_assignee_id']);
    ok($c['request_no'] !== $p['request_no'] && preg_match('/^SR-\d{6}-\d{4}$/', $c['request_no']) === 1, '새 요청번호');
    eq('분할된 SR', $c['title']);
    ok(str_contains($c['content_html'], '급여 부분만 처리') && str_contains($c['content_html'], '본문입니다'), '분할 안내 + 원문');
    eq([1 => 'DONE', 2 => 'ACTIVE', 3 => 'WAITING', 4 => 'WAITING', 5 => 'WAITING', 6 => 'WAITING', 7 => 'WAITING'], rstep_status($child));
    eq(null, Repo::find('request_step', ['request_id' => $child, 'step_no' => 1])['started_at'], '복사한 앞 단계는 시각이 없다(병목 통계 제외)');
    eq('IN_PROGRESS', $p['status']);
    eq(2, (int)$p['current_step_no']);
    eq(null, $p['it_assignee_id'], '원본 담당자는 변경 없음');
    eq(0, Repo::count('attachment', ['request_id' => $child]));

    // 새 SR 은 독립적으로 진행한다
    eq('ADVANCED', WF::act($child, $w['owner'], 'PASS', ''));
    eq(3, (int)rrow($child)['current_step_no']);
    eq(2, (int)rrow($rid)['current_step_no']);

    // 메일: 새 SR 현재 단계 처리자(분할한 본인 제외), 요청자에게 분할 안내
    eq(['it2'], rcpts(array_slice(queue($child), 0, 1)), '새 SR 의 검토 단계 처리자');
    $sp = Repo::list('notification_log', ['request_id' => $rid, 'tmpl_cd' => 'SR_SPLIT']);
    eq(['req'], rcpts($sp));
    ok(str_contains($sp[0]['body'], $c['request_no']) && str_contains($sp[0]['body'], '/requests/' . $child), '새 SR 번호와 링크');
    ok(!str_contains($sp[0]['body'] . $sp[0]['subject'], '{'), '치환되지 않은 변수 없음');
});

test('split: 원본 담당자도 함께 바꿀 수 있고 각자 처리자로 판정된다', function () {
    $w = rw();
    $rid = sp_to_work($w);
    $admin = wl_admin();
    $before = count(queue($rid));
    $child = SP::split($rid, $admin, sp_in($w, ['new_assignee_id' => (string)$w['owner'], 'orig_assignee_id' => (string)$w['it2']]));
    $p = rrow($rid);
    $c = rrow($child);
    eq($w['it2'], (int)$p['it_assignee_id']);
    eq($w['owner'], (int)$c['it_assignee_id']);
    ok(RS::canAct($p, WF::service($p), $w['it2']) && !RS::canAct($p, WF::service($p), $w['owner']), '원본: it2');
    ok(RS::canAct($c, WF::service($c), $w['owner']) && !RS::canAct($c, WF::service($c), $w['it2']), '새 SR: owner');
    eq(5, (int)$c['current_step_no']);
    eq(['owner'], rcpts(queue($child)), '새 SR 담당자에게 처리 요청');
    ok(in_array('it2', rcpts(array_slice(queue($rid), $before)), true), '원본 새 담당자에게도 알림');
    ok(in_array($rid, sp_inbox($w['it2']), true) && in_array($child, sp_inbox($w['owner']), true) && !in_array($child, sp_inbox($w['it2']), true));
    ok(!in_array($rid, sp_inbox($w['owner']), true), '원본은 더 이상 owner 의 처리함에 없다');
});

test('split: 분할할 수 없는 경우와 입력 검증', function () {
    $w = rw();
    perr_p(fn() => SP::split(mk_req($w), $w['owner'], sp_in($w)), 'split.err_state');
    $log = WL::save(null, $w['owner'], wl_in($w));
    perr_p(fn() => SP::split($log, $w['owner'], sp_in($w)), 'split.err_state');

    $rid = sp_to_work($w);
    $n = Repo::count('request');
    $bad = fn(array $o, string $key) => perr_p(fn() => SP::split($rid, $w['owner'], sp_in($w, $o)), $key);
    $bad(['title' => ''], 'request.err_title');
    $bad(['title' => str_repeat('가', 201)], 'request.err_title');
    $bad(['note' => ''], 'split.err_note');
    $bad(['note' => str_repeat('가', 1001)], 'split.err_note');
    $bad(['new_assignee_id' => ''], 'split.err_assignee');
    $bad(['new_assignee_id' => '99999'], 'split.err_assignee');
    $bad(['orig_assignee_id' => '99999'], 'split.err_assignee');
    eq($n, Repo::count('request'), '실패하면 아무것도 만들어지지 않는다');
    eq(5, (int)rrow($rid)['current_step_no']);

    // 일정 협의 중에는 분할 불가
    SS::propose($rid, $w['owner'], day(60), 'RESOURCE', '사유');
    perr_p(fn() => SP::split($rid, $w['owner'], sp_in($w)), 'request.err_schedule_locked');
    ok(!SP::canSplit(rrow($rid)));
    ok(SP::canAssign(rrow($rid)), '담당자 지정은 협의 중에도 가능');

    // 요청자 검수 단계(6)에서는 분할 불가
    $r2 = sp_to_work($w);
    WF::act($r2, $w['owner'], 'PASS', '');
    eq(6, (int)rrow($r2)['current_step_no']);
    perr_p(fn() => SP::split($r2, $w['owner'], sp_in($w)), 'split.err_step');
    ok(!SP::canSplit(rrow($r2)));
    // 완료된 요청
    WF::act($r2, $w['req'], 'PASS', '');
    WF::act($r2, $w['owner'], 'PASS', '');
    eq('COMPLETED', rrow($r2)['status']);
    perr_p(fn() => SP::split($r2, $w['owner'], sp_in($w)), 'split.err_state');
});

test('split: 분할을 반복할 수 있다(원본에서 3건째)', function () {
    $w = rw();
    $rid = live($w);
    $a = SP::split($rid, $w['owner'], sp_in($w, ['title' => 'A']));
    $b = SP::split($rid, $w['owner'], sp_in($w, ['title' => 'B']));
    eq(2, Repo::count('request', ['parent_id' => $rid]));
    ok($a !== $b && rrow($a)['request_no'] !== rrow($b)['request_no']);
    $grand = SP::split($a, $w['owner'], sp_in($w, ['title' => 'A-1']));
    eq($a, (int)rrow($grand)['parent_id']);
});

test('split: 권한 시드와 기존 회사 보강, 회사 격리', function () {
    $w = rw();
    foreach (['IT_TEAM', 'SYS_ADMIN'] as $role) {
        eq(1, Repo::count('role_permission', ['role_cd' => $role, 'perm_cd' => 'REQUEST_SPLIT']), $role);
    }
    foreach (['REQUESTER', 'BIZ_IT'] as $role) {
        eq(0, Repo::count('role_permission', ['role_cd' => $role, 'perm_cd' => 'REQUEST_SPLIT']), $role);
    }
    Repo::delete('role_permission', ['role_cd' => 'IT_TEAM', 'perm_cd' => 'REQUEST_SPLIT']);
    Repo::delete('role_permission', ['role_cd' => 'SYS_ADMIN', 'perm_cd' => 'REQUEST_SPLIT']);
    Repo::delete('app_setting', ['setting_key' => 'seed.split_perm']);
    Seeder::ensureDefaults('A01');
    eq(1, Repo::count('role_permission', ['role_cd' => 'IT_TEAM', 'perm_cd' => 'REQUEST_SPLIT']));
    eq(1, Repo::count('role_permission', ['role_cd' => 'SYS_ADMIN', 'perm_cd' => 'REQUEST_SPLIT']));
    Seeder::ensureDefaults('A01');
    eq(1, Repo::count('role_permission', ['role_cd' => 'IT_TEAM', 'perm_cd' => 'REQUEST_SPLIT']), '멱등');
    $rid = live($w);
    SP::split($rid, $w['owner'], sp_in($w));
    Scope::set('B01');
    eq(0, Repo::count('request', ['parent_id' => $rid]), '다른 회사에는 보이지 않는다');
});

test('split: 요청자 안내 메일은 수신자 언어로, 요청자 본인이 분할하면 보내지 않는다', function () {
    $w = rw();
    Repo::update('user', ['user_id' => $w['req']], ['lang' => 'ja']);
    $rid = live($w);
    SP::split($rid, $w['owner'], sp_in($w));
    $m = Repo::list('notification_log', ['request_id' => $rid, 'tmpl_cd' => 'SR_SPLIT']);
    eq(1, count($m));
    ok(str_starts_with($m[0]['subject'], '[SRMS] SR分割'), $m[0]['subject']);
    eq('ja', $m[0]['lang']);
    $r2 = live($w);
    SP::split($r2, $w['req'], sp_in($w));
    eq(0, Repo::count('notification_log', ['request_id' => $r2, 'tmpl_cd' => 'SR_SPLIT']));
});

test('report: 담당자 필터와 집계에 요청별 담당자가 반영된다', function () {
    $w = rw();
    $rid = live($w);
    $child = SP::split($rid, $w['owner'], sp_in($w));
    [$u, , $p] = ctx_of($w['owner']);
    $f = ReportService::filters(['owner_id' => (string)$w['it2']]);
    $res = ReportService::build($f, $u, $p);
    eq(1, $res['summary']['total'], '새 SR 은 it2 담당으로 집계');
    eq(1, ReportService::build(ReportService::filters(['owner_id' => (string)$w['owner']]), $u, $p)['summary']['total'], '원본은 서비스 담당자');
    ok($child > 0);
});

test('http: SR 분할과 담당자 지정 화면, 권한, 이력', function () {
    $w = rw();
    $rid = live($w);
    as_user('req');
    $show = http('GET', "/requests/$rid");
    eq(200, $show->status);
    ok(!str_contains($show->body, "/requests/$rid/split") && !str_contains($show->body, "/requests/$rid/assignee"), '요청자에게는 분할/지정 UI 없음');
    eq(403, http('POST', "/requests/$rid/split", sp_in($w))->status);
    eq(403, http('POST', "/requests/$rid/assignee", ['assignee_id' => (string)$w['it2']])->status);
    as_user('bizit');
    eq(403, http('POST', "/requests/$rid/split", sp_in($w))->status, '현업 전산담당은 권한 없음');

    as_user('owner');
    $body = http('GET', "/requests/$rid")->body;
    ok(str_contains($body, "/requests/$rid/split") && str_contains($body, "/requests/$rid/assignee") && str_contains($body, 'name="note"'));
    $r = http('POST', "/requests/$rid/assignee", ['assignee_id' => (string)$w['it2']]);
    eq(302, $r->status, last_flash());
    Scope::set('A01');
    eq($w['it2'], (int)rrow($rid)['it_assignee_id']);
    eq(1, Repo::count('audit_log', ['action' => 'REQUEST_ASSIGN']));
    $body = http('GET', "/requests/$rid")->body;
    ok(str_contains($body, e(t('tl.ASSIGN'))) && str_contains($body, 'It2'), '이력에 담당자 지정');
    ok(str_contains($body, e(t('split.by_request'))), '요청별 지정 표시');

    $r = http('POST', "/requests/$rid/split", sp_in($w, ['title' => 'HTTP 분할']));
    eq(302, $r->status, last_flash());
    Scope::set('A01');
    $child = Repo::find('request', ['title' => 'HTTP 분할']);
    ok($child !== null);
    $cid = (int)$child['request_id'];
    eq(1, Repo::count('audit_log', ['action' => 'REQUEST_SPLIT']));
    $pb = http('GET', "/requests/$rid")->body;
    ok(str_contains($pb, e(t('tl.SPLIT'))) && str_contains($pb, $child['request_no']) && str_contains($pb, "/requests/$cid"), '원본 이력과 분할된 SR 링크');
    $cb = http('GET', "/requests/$cid")->body;
    ok(str_contains($cb, e(t('tl.SPLIT_FROM'))) && str_contains($cb, e(t('split.badge'))) && str_contains($cb, "/requests/$rid"), '새 SR 이력, 배지, 원본 링크');
    ok(str_contains(http('GET', '/requests?scope=all')->body, e(t('split.badge'))), '목록 배지');
    $rep = http('GET', '/reports')->body;
    ok(str_contains($rep, '>It2<'), '리포트 담당자 필터에 요청별 담당자');

    $bad = http('POST', "/requests/$rid/split", sp_in($w, ['note' => '']));
    eq(302, $bad->status);
    ok(str_contains(last_flash(), t('split.err_note')), last_flash());
    Scope::set('A01');
    eq(1, Repo::count('request', ['parent_id' => $rid]), '실패한 분할은 만들어지지 않는다');
});
