<?php
declare(strict_types=1);

use App\Core\Repo;
use App\Core\Scope;
use App\Services\DashboardService;
use App\Services\ScheduleService as SS;
use App\Services\WorkflowService as WF;

function dash(int $uid): array
{
    Scope::set('A01');
    [$u, $r, $p] = ctx_of($uid);
    return DashboardService::build($u, $r, $p);
}

test('dashboard: 현업 - 내 요청 상태별 건수, 조치 대기, 진행 요약, 최근 알림', function () {
    $w = rw();
    $draft = mk_req($w);
    $rid = live($w, ['title' => '진행건']);
    $d = dash($w['req']);
    eq(1, $d['mine']['DRAFT']);
    eq(1, $d['mine']['IN_PROGRESS']);
    eq(0, $d['actions']['total']);
    ok(!isset($d['dept']) && !isset($d['all']), '현업은 부서/전체 위젯 없음');
    eq(1, count($d['open']));
    $o = $d['open'][0];
    eq($rid, (int)$o['request_id']);
    eq(7, (int)$o['total_steps']);
    eq(1, (int)$o['done_steps']);
    ok(in_array('Owner', $o['actor_names'], true) && in_array('It2', $o['actor_names'], true), '현재 단계 처리자 표시');
    // 반려로 보완 요청 → 내 조치 대기(보완 필요)
    WF::act($rid, $w['owner'], 'REJECT', '보완 필요');
    $d = dash($w['req']);
    eq(1, $d['actions']['total']);
    eq('return', $d['actions']['rows'][0]['kind']);
    eq(1, $d['mine']['RETURNED']);
    // 재제출 후 일정 협의 제안 → 요청자의 회신 대기
    WF::submit($rid, $w['req']);
    SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력');
    $d = dash($w['req']);
    eq(1, $d['actions']['total']);
    eq('schedule', $d['actions']['rows'][0]['kind']);
    // 최근 알림은 본인에게 온 메일만
    $mails = Repo::list('notification_log', ['to_user_id' => $w['req']]);
    eq(count($mails), count($d['mails']));
    foreach ($d['mails'] as $m) {
        eq($w['req'], (int)Repo::find('notification_log', ['noti_id' => $m['noti_id']])['to_user_id']);
    }
    $own = dash($w['other']);
    eq(0, $own['mine']['IN_PROGRESS']);
    eq([], $own['open'], '남의 요청은 보이지 않음');
});

test('dashboard: 처리자 조치 대기는 처리함과 일치', function () {
    $w = rw();
    live($w);
    live($w, ['title' => '두번째']);
    $d = dash($w['owner']);
    eq(2, $d['actions']['total']);
    foreach ($d['actions']['rows'] as $r) {
        eq('step', $r['kind']);
    }
    eq(list_of($w['owner'], 'inbox')['total'], $d['actions']['total']);
    eq(0, dash($w['bizit'])['actions']['total']);
});

test('dashboard: 주관부서(현업 전산담당)와 전산팀 위젯 범위', function () {
    $w = rw();
    $r1 = live($w, ['title' => '일반']);
    $dr = mk_req($w, ['title' => '임시']);
    $d = dash($w['bizit']);
    ok(isset($d['dept']) && !isset($d['all']));
    eq(1, $d['dept']['status']['IN_PROGRESS']);
    eq(0, $d['dept']['status']['DRAFT'], '임시저장은 제외');
    eq('검토', $d['dept']['byStep'][0]['step_nm']);
    eq(1, (int)$d['dept']['byStep'][0]['n']);
    ok(!isset($d['dept']['kanban']), '주관부서는 칸반 없음');
    // 다른 주관부서 담당자는 보이지 않음
    $d = dash($w['othbiz']);
    eq(0, $d['dept']['status']['IN_PROGRESS']);
    // 전산팀: 전체 범위
    $d = dash($w['owner']);
    ok(isset($d['all']));
    eq(1, $d['all']['status']['IN_PROGRESS']);
    eq(1, $d['all']['newCount']);
    eq(1, count($d['all']['kanban']));
    eq(['step_nm' => '검토', 'n' => 1], ['step_nm' => $d['all']['kanban'][0]['step_nm'], 'n' => $d['all']['kanban'][0]['n']]);
    eq('Owner', $d['all']['workload'][0]['owner_nm']);
    eq('ERP', $d['all']['byService'][0]['service_cd']);
    // 일반 사용자
    $d = dash($w['other']);
    ok(!isset($d['dept']) && !isset($d['all']));
    ok($dr > 0 && $r1 > 0);
});

test('dashboard: 지연/임박은 유효 완료일(합의 계획일 우선) 기준', function () {
    $w = rw();
    $late = live($w, ['title' => '지연건']);
    $soon = live($w, ['title' => '임박건', 'req_due_date' => day(2)]);
    $far = live($w, ['title' => '여유건', 'req_due_date' => day(30)]);
    $planned = live($w, ['title' => '연장건']);
    Repo::update('request', ['request_id' => $late], ['req_due_date' => day(-2)]);
    Repo::update('request', ['request_id' => $planned], ['req_due_date' => day(-3), 'plan_end' => day(20)]);
    $a = dash($w['owner'])['all'];
    eq(1, $a['lateCount']);
    eq('지연건', $a['late'][0]['title']);
    eq(1, $a['soonCount']);
    eq('임박건', $a['soon'][0]['title']);
    ok($far > 0);
    $b = dash($w['bizit'])['dept'];
    eq(1, $b['lateCount']);
    eq(1, $b['soonCount']);
    // 완료/취소 건은 지연 집계에서 제외
    WF::cancel($late, $w['req']);
    eq(0, dash($w['owner'])['all']['lateCount']);
});

test('dashboard: 일정 협의 중 건과 칸반(카드 5장 제한)·담당자 미지정', function () {
    $w = rw();
    $ids = [];
    for ($i = 1; $i <= 7; $i++) {
        $ids[] = live($w, ['title' => "건$i"]);
    }
    SS::propose($ids[0], $w['owner'], day(45), 'RESOURCE', '인력');
    $a = dash($w['owner'])['all'];
    eq(1, $a['schedCount']);
    eq('건1', $a['sched'][0]['title']);
    eq(7, $a['kanban'][0]['n']);
    eq(5, count($a['kanban'][0]['cards']));
    eq(7, (int)$a['workload'][0]['n']);
    Repo::update('service', ['service_id' => $w['svc']], ['it_owner_id' => null]);
    $a = dash($w['owner'])['all'];
    eq(null, $a['workload'][0]['owner_nm'], '담당자 미지정 집계');
});

test('dashboard: 회사 격리', function () {
    $w = rw();
    live($w);
    Scope::set('B01');
    $admin = Repo::find('user', ['login_id' => 'admin']);
    [$u, $r, $p] = ctx_of((int)$admin['user_id']);
    $d = DashboardService::build($u, $r, $p);
    eq(0, $d['all']['status']['IN_PROGRESS']);
    eq([], $d['all']['kanban']);
    eq(0, array_sum($d['mine']));
});

test('http: 홈 대시보드 롤별 표시와 이스케이프, 다국어', function () {
    $w = rw();
    $rid = live($w, ['title' => '<img src=x onerror=alert(1)>']);
    SS::propose($rid, $w['owner'], day(45), 'RESOURCE', '인력');
    as_user('req');
    $r = http('GET', '/');
    eq(200, $r->status);
    ok(body_has($r, 'dash.my_requests') && body_has($r, 'dash.my_open'));
    ok(!body_has($r, 'dash.kanban') && !body_has($r, 'dash.dept_title'), '현업에게는 전산팀/부서 위젯 없음');
    ok(body_has($r, 'dash.action.schedule'), '회신 대기 표시');
    ok(!str_contains($r->body, '<img src=x'), '제목 이스케이프');
    ok(str_contains($r->body, '&lt;img src=x onerror=alert(1)&gt;'));
    as_user('bizit');
    $r = http('GET', '/');
    ok(body_has($r, 'dash.dept_title') && !body_has($r, 'dash.kanban'));
    as_user('owner');
    $r = http('GET', '/');
    ok(body_has($r, 'dash.all_title') && body_has($r, 'dash.kanban') && body_has($r, 'dash.workload'));
    ok(!str_contains($r->body, '<img src=x'));
    as_user('other');
    $r = http('GET', '/');
    ok(!str_contains($r->body, 'SR-'), '남의 요청 번호 노출 없음');
    foreach (['ja', 'en'] as $lang) {
        as_user('owner');
        http('POST', '/lang', ['lang' => $lang, 'back' => '/']);
        $r = http('GET', '/');
        eq(200, $r->status, $lang);
        ok(str_contains($r->body, e(App\Core\I18n::load($lang)['dash.kanban'])), "$lang 번역");
    }
    App\Core\I18n::set('ko');
    as_admin('B01', 'Admin5678');
    $r = http('GET', '/');
    ok(!str_contains($r->body, 'SR-2'), '다른 회사 요청 번호 노출 없음');
});
