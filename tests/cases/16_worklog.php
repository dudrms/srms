<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\AttachmentService as AS_;
use App\Services\ProcessService as PS;
use App\Services\ReportService;
use App\Services\Seeder;
use App\Services\WorklogService as WL;

function wl_in(array $w, array $o = []): array
{
    return $o + [
        'request_date' => day(-3), 'requester_id' => (string)$w['req'], 'service_id' => (string)$w['svc'], 'channel' => 'PHONE',
        'title' => '전화 문의 처리', 'content_html' => '<p>전화로 받은 요청</p>', 'effort_hours' => '1.5', 'actual_end' => day(-1),
    ];
}

function wl_admin(): int
{
    return (int)Repo::find('user', ['login_id' => 'admin'])['user_id'];
}

test('worklog: 사후등록은 승인 없이 완료 상태로 기록되고 알림이 없다', function () {
    $w = rw();
    $id = WL::save(null, $w['owner'], wl_in($w));
    $r = rrow($id);
    eq('COMPLETED', $r['status']);
    eq(WL::TYPE, $r['maint_type']);
    eq($w['owner'], (int)$r['reg_by']);
    eq($w['owner'], (int)$r['it_assignee_id']);
    eq($w['req'], (int)$r['requester_id']);
    eq(day(-3), $r['request_date']);
    eq(day(-1), $r['actual_end']);
    eq(day(-1), $r['req_due_date'], '완료요구일은 완료일과 같다');
    eq(1.5, (float)$r['effort_hours']);
    eq('PHONE', $r['channel']);
    ok(preg_match('/^SR-\d{6}-\d{4}$/', $r['request_no']) === 1, '요청번호 체계');
    eq([1 => 'DONE', 2 => 'DONE'], rstep_status($id));
    $steps = Repo::list('request_step', ['request_id' => $id], 'step_no');
    eq(day(-3) . ' 00:00:00', $steps[1]['started_at']);
    eq(day(-3) . ' 00:00:00', $steps[0]['ended_at'], '접수 단계는 요청일에 끝난다');
    eq(day(-1) . ' 00:00:00', $steps[1]['ended_at']);
    eq(['SUBMIT', 'COMPLETE'], array_column(Repo::query(
        'SELECT a.action FROM approval a JOIN request_step s ON s.company_cd = a.company_cd AND s.rstep_id = a.rstep_id
          WHERE a.company_cd = :company_cd AND s.request_id = :r ORDER BY s.step_no', ['r' => $id]
    ), 'action'));
    eq(0, Repo::count('notification_log'), '알림 없음');
    ok(WL::isLog($r));
    ok(!WL::isLog(rrow(live($w))), '일반 요청은 작업일지가 아니다');
});

test('worklog: 유형/채널/프로세스 준비는 멱등이며 일반 화면에서는 숨겨진다', function () {
    $w = rw();
    $pid = WL::ensure();
    eq($pid, WL::ensure());
    eq(1, Repo::count('process_master', ['process_cd' => WL::PROCESS]));
    eq(1, Repo::count('code', ['group_cd' => 'MAINT_TYPE', 'code_cd' => WL::TYPE]));
    eq(5, Repo::count('code', ['group_cd' => WL::CHANNEL_GROUP]));
    eq([], PS::validateVersion((int)PS::resolveVersion($pid)['version_id']));
    $m = PS::mappingMatrix();
    ok(!in_array(WL::TYPE, array_column($m['types'], 'code_cd'), true), '매핑 표에서 유형 숨김');
    ok(!in_array(WL::PROCESS, array_column($m['processes'], 'process_cd'), true), '매핑 표에서 프로세스 숨김');
    perr_p(fn() => mk_req($w, ['maint_type' => WL::TYPE]), 'request.err_type');
    as_user('req');
    $body = http('GET', '/requests/new')->body;
    ok(!str_contains($body, 'value="' . WL::TYPE . '"'), '일반 등록 화면에 유형이 보이지 않는다');
});

test('worklog: 입력 검증', function () {
    $w = rw();
    $u = $w['owner'];
    $bad = fn(array $o, string $key) => perr_p(fn() => WL::save(null, $u, wl_in($w, $o)), $key);
    $bad(['request_date' => ''], 'worklog.err_request_date');
    $bad(['request_date' => '2026-02-30'], 'worklog.err_request_date');
    $bad(['request_date' => day(1)], 'worklog.err_request_date');
    $bad(['actual_end' => day(1)], 'worklog.err_end');
    $bad(['actual_end' => ''], 'worklog.err_end');
    $bad(['request_date' => day(-1), 'actual_end' => day(-2)], 'worklog.err_end');
    $bad(['title' => ''], 'request.err_title');
    $bad(['title' => str_repeat('가', 201)], 'request.err_title');
    $bad(['content_html' => '<p> </p>'], 'worklog.err_content');
    $bad(['requester_id' => ''], 'worklog.err_requester');
    $bad(['requester_id' => '99999'], 'worklog.err_requester');
    $bad(['service_id' => '99999'], 'request.err_service');
    foreach (['', '0', '0.0', '0.001', '1.2345', '10000', 'abc', '-1'] as $eff) {
        $bad(['effort_hours' => $eff], 'worklog.err_effort');
    }
    $bad(['channel' => 'NOPE'], 'worklog.err_channel');
    eq(0, Repo::count('request', ['reg_by' => $u]), '실패 시 아무것도 저장되지 않는다');
    $ok = WL::save(null, $u, wl_in($w, ['channel' => '', 'effort_hours' => '0.1', 'request_date' => day(0), 'actual_end' => day(0)]));
    eq(null, rrow($ok)['channel']);
    Repo::update('user', ['user_id' => $w['other']], ['use_yn' => 0]);
    $bad(['requester_id' => (string)$w['other']], 'worklog.err_requester');
    Repo::update('service', ['service_id' => $w['svc']], ['use_yn' => 0]);
    $bad([], 'request.err_service');
});

test('worklog: 요청자는 내 요청에서 보고 무관한 사용자는 볼 수 없다', function () {
    $w = rw();
    $id = WL::save(null, $w['owner'], wl_in($w));
    ok(in_array($id, array_map('intval', array_column(list_of($w['req'], 'mine')['rows'], 'request_id')), true), '요청자의 내 요청');
    ok(can_view($id, $w['req']));
    ok(can_view($id, $w['owner']));
    ok(can_view($id, $w['it2']), '전산팀은 조회 가능');
    ok(!can_view($id, $w['other']), '무관한 현업은 불가');
    // 등록자가 전체 조회 권한이 없는 롤이어도 자신이 등록한 건은 볼 수 있다
    $solo = App\Services\Users::create('solo', 'Solo', 'solo@example.com', $w['it'], 'ko', PW, ['REQUESTER']);
    Repo::insert('role', ['role_cd' => 'LOGGER', 'role_nm' => 'logger']);
    Repo::insert('role_permission', ['role_cd' => 'LOGGER', 'perm_cd' => 'WORKLOG_REG']);
    App\Services\Users::setRoles($solo, ['LOGGER']);
    $mine = WL::save(null, $solo, wl_in($w));
    ok(can_view($mine, $solo), '등록자 본인');
});

test('worklog: 수정/삭제는 등록자 본인과 시스템 관리자만', function () {
    $w = rw();
    $id = WL::save(null, $w['owner'], wl_in($w));
    perr_p(fn() => WL::save($id, $w['it2'], wl_in($w, ['title' => '남의 글'])), 'request.err_forbidden');
    perr_p(fn() => WL::delete($id, $w['it2']), 'request.err_forbidden');
    perr_p(fn() => WL::save($id, $w['req'], wl_in($w)), 'request.err_forbidden');
    ok(WL::canManage(rrow($id), $w['owner']));
    ok(WL::canManage(rrow($id), wl_admin()), '시스템 관리자');
    ok(!WL::canManage(rrow($id), $w['it2']));

    $svc2 = Repo::insert('service', ['service_cd' => 'HRS', 'service_nm' => 'HRS', 'biz_dept_id' => $w['oth'], 'it_owner_id' => $w['owner'], 'use_yn' => 1]);
    WL::save($id, $w['owner'], wl_in($w, [
        'title' => '수정된 제목', 'requester_id' => (string)$w['other'], 'service_id' => (string)$svc2, 'channel' => 'MAIL',
        'effort_hours' => '2', 'request_date' => day(-5), 'actual_end' => day(-4), 'content_html' => '<p>수정</p>',
    ]));
    $r = rrow($id);
    eq('수정된 제목', $r['title']);
    eq($w['other'], (int)$r['requester_id']);
    eq($svc2, (int)$r['service_id']);
    eq('MAIL', $r['channel']);
    eq(2.0, (float)$r['effort_hours']);
    eq(day(-5), $r['request_date']);
    eq(day(-4), $r['req_due_date']);
    eq($w['owner'], (int)$r['reg_by'], '등록자는 유지');
    eq(day(-4) . ' 00:00:00', Repo::find('request_step', ['request_id' => $id, 'step_no' => 2])['ended_at']);
    WL::save($id, wl_admin(), wl_in($w, ['title' => '관리자 수정']));
    eq('관리자 수정', rrow($id)['title']);
    eq($w['owner'], (int)rrow($id)['reg_by']);

    $normal = live($w);
    perr_p(fn() => WL::save($normal, $w['owner'], wl_in($w)), 'process.err_not_found');
    perr_p(fn() => WL::delete($normal, wl_admin()), 'request.err_forbidden');
});

test('worklog: 삭제하면 요청/단계/이력/댓글/첨부 파일이 모두 지워진다', function () {
    with_storage(function () {
        $w = rw();
        $id = WL::save(null, $w['owner'], wl_in($w));
        App\Services\RequestService::addComment($id, $w['req'], '확인했습니다');
        $a = AS_::ingest(rrow($id), 1, $w['owner'], tmpf(PDF_BODY), 'mail.pdf');
        [, $path] = AS_::locate($a);
        ok(is_file($path));
        $other = WL::save(null, $w['owner'], wl_in($w, ['title' => '남는 건']));
        $no = WL::delete($id, $w['owner']);
        ok(preg_match('/^SR-/', $no) === 1);
        eq(null, Repo::find('request', ['request_id' => $id]));
        eq(0, Repo::count('request_step', ['request_id' => $id]));
        eq(0, Repo::count('comment', ['request_id' => $id]));
        eq(0, Repo::count('attachment', ['request_id' => $id]));
        eq(0, (int)Repo::queryVal('SELECT COUNT(*) FROM approval a WHERE a.company_cd = :company_cd AND a.rstep_id NOT IN (SELECT rstep_id FROM request_step WHERE company_cd = :company_cd AND request_id = :o)', ['o' => $other]));
        ok(!is_file($path), '첨부 파일 삭제');
        ok(Repo::find('request', ['request_id' => $other]) !== null, '다른 건은 유지');
    });
});

test('worklog: 첨부는 등록자/관리자만 올리고 지울 수 있다', function () {
    with_storage(function () {
        $w = rw();
        $id = WL::save(null, $w['owner'], wl_in($w));
        $req = rrow($id);
        eq(1, AS_::uploadStep($req, $w['owner']));
        eq(1, AS_::uploadStep($req, wl_admin()));
        eq(null, AS_::uploadStep($req, $w['it2']));
        eq(null, AS_::uploadStep($req, $w['req']), '요청자도 수정은 못 한다');
        perr_p(fn() => AS_::ingest($req, 1, $w['it2'], tmpf(PDF_BODY), 'x.pdf'), 'attach.err_forbidden');
        $a = AS_::ingest($req, 1, $w['owner'], tmpf(PDF_BODY), 'a.pdf');
        perr_p(fn() => AS_::remove($req, $a, $w['it2']), 'attach.err_forbidden');
        AS_::remove($req, $a, wl_admin());
        eq(0, Repo::count('attachment', ['request_id' => $id]), '관리자가 다른 사람이 올린 첨부를 지운다');
    });
});

test('worklog: 목록 필터와 페이지 크기', function () {
    $w = rw();
    for ($i = 0; $i < 12; $i++) {
        WL::save(null, $w['owner'], wl_in($w, ['title' => "건 $i", 'request_date' => day(-20 + $i), 'actual_end' => day(-5)]));
    }
    WL::save(null, $w['it2'], wl_in($w, ['title' => '다른 등록자', 'requester_id' => (string)$w['other']]));
    mk_req($w);
    $all = WL::listFor([]);
    eq(13, $all['total'], '일반 요청은 제외');
    eq('다른 등록자', $all['rows'][0]['title'], '요청일 최신순');
    eq(1, WL::listFor(['requester_id' => $w['other']])['total']);
    eq(12, WL::listFor(['requester_id' => $w['req']])['total']);
    eq(3, WL::listFor(['from' => day(-20), 'to' => day(-18)])['total'], '요청일 기간');
    eq(1, WL::listFor(['q' => '다른'])['total']);
    \App\Core\Env::set('LIST_PAGE_SIZE', '5');
    $p3 = WL::listFor(['page' => 3]);
    \App\Core\Env::set('LIST_PAGE_SIZE', '');
    eq(3, $p3['pages']);
    eq(3, count($p3['rows']));
});

test('worklog: 보고서는 실제 요청일 기준이며 M/D가 담당자 실적에 합산된다', function () {
    $w = rw();
    $id = WL::save(null, $w['owner'], wl_in($w, ['request_date' => day(-60), 'actual_end' => day(-58), 'effort_hours' => '2.5']));
    [$u, , $p] = ctx_of($w['owner']);
    $f = fn(string $from, string $to) => ReportService::filters(['from' => $from, 'to' => $to]);
    $in = ReportService::build($f(day(-65), day(-55)), $u, $p);
    eq(1, $in['summary']['total']);
    eq(2.5, $in['summary']['effort']);
    eq(2.0, $in['summary']['avg_days'], '요청일부터 완료일까지');
    eq(0, ReportService::build($f(day(-10), day(0)), $u, $p)['summary']['total'], '등록 시각(오늘)이 아니라 요청일로 집계');
    $owner = $in['sections']['owner'];
    ok(str_contains(json_encode($owner, JSON_UNESCAPED_UNICODE), '2.5'), '담당자별 M/D');
    $typed = ReportService::filters(['from' => day(-65), 'to' => day(-55), 'maint_type' => WL::TYPE]);
    eq(1, ReportService::build($typed, $u, $p)['summary']['total'], '유형 필터');
    ok($id > 0);
});

test('worklog: 일반 요청의 공수 입력/타임라인에는 영향이 없다', function () {
    $w = rw();
    $id = WL::save(null, $w['owner'], wl_in($w));
    $req = rrow($id);
    ok(!App\Services\RequestService::canSetEffort($req, App\Services\WorkflowService::service($req), $w['owner']), '공수는 작업일지 화면에서만 수정');
    $tl = App\Services\RequestService::timeline($id);
    eq(['WORKLOG'], array_column($tl, 'kind'));
    eq('Owner', $tl[0]['user'], '등록자가 표시된다');
    $n = live($w);
    eq('CREATED', App\Services\RequestService::timeline($n)[0]['kind']);
});

test('worklog: 권한 시드와 기존 회사 보강, 회사 격리', function () {
    $w = rw();
    ok(Repo::count('role_permission', ['role_cd' => 'IT_TEAM', 'perm_cd' => 'WORKLOG_REG']) === 1);
    eq(0, Repo::count('role_permission', ['role_cd' => 'REQUESTER', 'perm_cd' => 'WORKLOG_REG']));
    eq(0, Repo::count('role_permission', ['role_cd' => 'BIZ_IT', 'perm_cd' => 'WORKLOG_REG']));
    Repo::delete('role_permission', ['role_cd' => 'IT_TEAM', 'perm_cd' => 'WORKLOG_REG']);
    Repo::delete('role_permission', ['role_cd' => 'SYS_ADMIN', 'perm_cd' => 'WORKLOG_REG']);
    Repo::delete('app_setting', ['setting_key' => 'seed.worklog_perm']);
    Seeder::ensureDefaults('A01');
    eq(1, Repo::count('role_permission', ['role_cd' => 'IT_TEAM', 'perm_cd' => 'WORKLOG_REG']));
    eq(1, Repo::count('role_permission', ['role_cd' => 'SYS_ADMIN', 'perm_cd' => 'WORKLOG_REG']));
    Seeder::ensureDefaults('A01');
    eq(1, Repo::count('role_permission', ['role_cd' => 'IT_TEAM', 'perm_cd' => 'WORKLOG_REG']), '멱등');
    WL::save(null, $w['owner'], wl_in($w));
    Scope::set('B01');
    eq(0, WL::listFor([])['total'], '다른 회사에는 보이지 않는다');
    eq(0, Repo::count('code', ['group_cd' => WL::CHANNEL_GROUP]));
});

test('worklog: 투입시간 입력(0.01시간 이상, 소수 둘째 자리)', function () {
    $w = rw();
    $id = WL::save(null, $w['owner'], wl_in($w, ['effort_hours' => '4', 'title' => '4시간']));
    eq(4.0, (float)rrow($id)['effort_hours']);
    $id2 = WL::save(null, $w['owner'], wl_in($w, ['effort_hours' => '0.25', 'title' => '15분']));
    eq(0.25, (float)rrow($id2)['effort_hours'], '15분 = 0.25시간 그대로 저장');
    $id3 = WL::save(null, $w['owner'], wl_in($w, ['effort_hours' => '0.01', 'title' => '최소값']));
    eq(0.01, (float)rrow($id3)['effort_hours']);
    foreach (['0.00', '0.005', '1.234', '10000'] as $bad) {
        try {
            WL::save(null, $w['owner'], wl_in($w, ['effort_hours' => $bad]));
            ok(false, "$bad 시간은 거부");
        } catch (App\Services\ProcessError $e) {
            eq('worklog.err_effort', $e->key);
        }
    }
    as_user('owner');
    $form = http('GET', '/logs/new')->body;
    ok(str_contains($form, 'name="effort_hours"') && str_contains($form, 'input-group-text') && !str_contains($form, 'effort_unit') && !str_contains($form, 'data-effort'), '시간 입력란만');
    $edit = http('GET', "/logs/$id/edit")->body;
    ok(str_contains($edit, 'name="effort_hours"') && str_contains($edit, 'value="4"'), '수정 화면은 저장된 시간');
    $r = http('POST', '/logs', wl_in($w, ['title' => '시간 HTTP', 'effort_hours' => '2.5']));
    eq(302, $r->status, last_flash());
    Scope::set('A01');
    eq(2.5, (float)Repo::find('request', ['title' => '시간 HTTP'])['effort_hours']);
});

test('http: 작업일지 등록/조회/수정/삭제와 권한', function () {
    $w = rw();
    as_user('req');
    eq(403, http('GET', '/logs')->status, '권한 없는 사용자');
    eq(403, http('GET', '/logs/new')->status);
    as_user('owner');
    $r = http('GET', '/logs/new');
    eq(200, $r->status);
    ok(str_contains($r->body, 'name="request_date"') && str_contains($r->body, 'name="effort_hours"'));
    $r = http('POST', '/logs', wl_in($w, ['title' => 'HTTP 등록건']));
    eq(302, $r->status, last_flash());
    Scope::set('A01');
    $row = Repo::find('request', ['title' => 'HTTP 등록건']);
    ok($row !== null);
    $id = (int)$row['request_id'];
    eq('COMPLETED', $row['status']);
    $list = http('GET', '/logs')->body;
    ok(str_contains($list, 'HTTP 등록건') && str_contains($list, "/logs/$id/edit"));
    ok(str_contains($list, '>전화<'), '채널 이름 표시');
    $show = http('GET', "/requests/$id");
    eq(200, $show->status);
    ok(str_contains($show->body, e(t('worklog.badge'))) && str_contains($show->body, "/logs/$id/edit"));
    ok(str_contains(http('GET', '/requests?scope=all')->body, e(t('worklog.badge'))), '일반 목록에 배지');
    $bad = http('POST', "/logs/$id", wl_in($w, ['effort_hours' => 'x']));
    eq(200, $bad->status);
    ok(str_contains($bad->body, e(t('worklog.err_effort'))), '오류 시 폼 재표시');
    $r = http('POST', "/logs/$id", wl_in($w, ['title' => '수정됨']));
    eq(302, $r->status);
    Scope::set('A01');
    eq('수정됨', rrow($id)['title']);
    ok(Repo::count('audit_log', ['action' => 'WORKLOG_UPDATE']) === 1);

    as_user('it2');
    eq(403, http('GET', "/logs/$id/edit")->status, '다른 전산 담당자');
    eq(403, http('POST', "/logs/$id/delete")->status);
    eq(200, http('GET', '/logs')->status, '목록은 볼 수 있다');
    as_user('req');
    eq(200, http('GET', "/requests/$id")->status, '요청자는 상세를 본다');
    eq(403, http('GET', "/logs/$id/edit")->status);
    ok(!str_contains(http('GET', "/requests/$id")->body, "/logs/$id/edit"), '요청자에게는 수정 버튼이 없다');

    as_user('owner');
    eq(404, http('GET', '/logs/99999/edit')->status);
    Scope::set('A01');
    $n = live($w);
    eq(404, http('GET', "/logs/$n/edit")->status, '일반 요청은 작업일지 화면에서 열 수 없다');
    $r = http('POST', "/logs/$id/delete");
    eq(302, $r->status);
    Scope::set('A01');
    eq(null, Repo::find('request', ['request_id' => $id]));
    ok(Repo::count('audit_log', ['action' => 'WORKLOG_DELETE']) === 1);
});

test('worklog: 시스템 기본 프로세스 - 작업완료 처리자는 담당자(등록자)이고 구조는 고정된다', function () {
    $w = rw();
    $id = WL::save(null, $w['owner'], wl_in($w));
    $req = rrow($id);
    $st = App\Services\RequestService::stepper($req, App\Services\WorkflowService::service($req));
    eq('REQUESTER', $st[0]['actor_type']);
    eq('SERVICE_IT_OWNER', $st[1]['actor_type']);
    eq(['Owner'], $st[1]['actor_names'], '전산팀 전원이 아니라 등록자만');
    $id2 = WL::save(null, $w['it2'], wl_in($w, ['title' => 'it2 등록']));
    $req2 = rrow($id2);
    eq(['It2'], App\Services\RequestService::stepper($req2, App\Services\WorkflowService::service($req2))[1]['actor_names']);

    $pid = WL::ensure();
    $vid = (int)PS::resolveVersion($pid)['version_id'];
    ok(PS::isSystem(PS::process($pid)));
    ok(!PS::isSystem(PS::process(pid_of('GENERAL'))));
    foreach ([
        fn() => PS::createVersion($pid, null),
        fn() => PS::createVersion($pid, $vid),
        fn() => PS::setDates($pid, $vid, day(0), ''),
        fn() => PS::activate($pid, $vid, day(0), ''),
        fn() => PS::retire($pid, $vid),
        fn() => PS::deleteVersion($pid, $vid),
        fn() => PS::deleteProcess($pid),
        fn() => PS::addStep($pid, $vid, []),
        fn() => PS::reorder($pid, $vid, []),
    ] as $fn) {
        perr_p($fn, 'process.err_system');
    }
    PS::updateProcess($pid, '기타작업일지(수정)', '설명', []);
    eq('기타작업일지(수정)', PS::process($pid)['process_nm'], '이름은 수정할 수 있다');
    eq($vid, (int)PS::resolveVersion($pid)['version_id']);
    eq(1, Repo::count('process_version', ['process_id' => $pid]));

    as_admin();
    $list = http('GET', '/admin/processes')->body;
    ok(str_contains($list, e(t('process.system_badge'))), '목록에 시스템 기본 표시');
    $page = http('GET', "/admin/processes/$pid");
    eq(200, $page->status);
    ok(str_contains($page->body, e(t('process.system_note'))));
    ok(!str_contains($page->body, "/admin/processes/$pid/delete") && !str_contains($page->body, "/admin/processes/$pid/versions\""), '새 버전·삭제 버튼 없음');
    $r = http('POST', "/admin/processes/$pid/versions", []);
    eq(302, $r->status);
    Scope::set('A01');
    eq(1, Repo::count('process_version', ['process_id' => $pid]), '직접 POST 도 거부');
    $r = http('POST', "/admin/processes/$pid/versions/$vid/retire", []);
    eq(302, $r->status);
    Scope::set('A01');
    eq('ACTIVE', Repo::find('process_version', ['version_id' => $vid])['status']);
});

test('worklog: 기존 회사 보강 - 작업완료 처리자를 담당자로 바꾸고 수정한 설명은 지키며 멱등', function () {
    rw();
    $pid = WL::ensure();
    $vid = (int)PS::resolveVersion($pid)['version_id'];
    $close = fn() => Repo::find('process_step', ['version_id' => $vid, 'step_no' => 2]);
    $legacy = function (string $desc) use ($pid, $vid) {
        Repo::update('process_step', ['version_id' => $vid, 'step_no' => 2], ['actor_type' => 'ROLE', 'actor_value' => 'IT_TEAM']);
        Repo::update('process_master', ['process_id' => $pid], ['description' => $desc]);
        Repo::delete('app_setting', ['setting_key' => 'seed.worklog_actor']);
    };
    $legacy('접수→작업완료 (IT 담당자가 사후 등록하는 완료 건, 승인 없음)');
    Seeder::ensureDefaults('A01');
    eq('SERVICE_IT_OWNER', $close()['actor_type']);
    eq(null, $close()['actor_value']);
    ok(str_contains((string)PS::process($pid)['description'], '시스템 기본 프로세스'), '기본 설명은 새 문구로');
    eq('REQUESTER', Repo::find('process_step', ['version_id' => $vid, 'step_no' => 1])['actor_type'], '접수 단계는 요청자 그대로');
    Seeder::ensureDefaults('A01');
    eq('SERVICE_IT_OWNER', $close()['actor_type'], '멱등');
    $legacy('우리 회사 설명');
    Seeder::ensureDefaults('A01');
    eq('SERVICE_IT_OWNER', $close()['actor_type']);
    eq('우리 회사 설명', PS::process($pid)['description'], '관리자가 바꾼 설명은 유지');
});

test('worklog: 병목 리포트에는 작업일지 단계가 섞이지 않는다', function () {
    $w = rw();
    WL::save(null, $w['owner'], wl_in($w, ['request_date' => day(-5), 'actual_end' => day(-2)]));
    [$u, , $p] = ctx_of($w['owner']);
    $res = ReportService::build(ReportService::filters(['from' => day(-10), 'to' => day(0)]), $u, $p);
    eq(1, $res['summary']['total']);
    eq([], $res['sections']['bottleneck']['rows'], '작업일지는 병목 집계 제외');
});