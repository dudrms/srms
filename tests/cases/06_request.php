<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Env;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\ActorResolver;
use App\Services\Dates;
use App\Services\HtmlSanitizer;
use App\Services\ProcessError;
use App\Services\ProcessService as PS;
use App\Services\RequestAccess;
use App\Services\RequestService as RS;
use App\Services\Users;
use App\Services\WorkflowService as WF;

const PW = 'Passw0rd1';

/** 엔진 테스트는 단계 번호가 고정된 7단계(합의 포함) 일반 프로세스를 전제로 하므로, 기본 시드(6단계)를 그 구조로 되돌린다. */
function legacy_general_7(): void
{
    $vid = latest_vid(pid_of('GENERAL'));
    foreach (PS::steps($vid) as $s) {
        Repo::delete('process_step_cc', ['step_id' => $s['step_id']]);
        App\Services\Translations::remove('STEP', (string)$s['step_id']);
    }
    Repo::delete('process_step', ['version_id' => $vid]);
    $specs = [
        ['접수', '受付', 'Submission', 'SUBMIT', 'REQUESTER', null, null, null, 'OPTIONAL'],
        ['검토', 'レビュー', 'Review', 'REVIEW', 'ROLE', 'IT_TEAM', 1, 2, 'NONE'],
        ['합의', '合意', 'Agreement', 'AGREE', 'SERVICE_BIZ_DEPT', null, 2, 3, 'NONE'],
        ['승인', '承認', 'Approval', 'APPROVE', 'ROLE', 'IT_TEAM', 2, 2, 'NONE'],
        ['수행', '実施', 'Execution', 'WORK', 'SERVICE_IT_OWNER', null, null, null, 'OPTIONAL'],
        ['검수', '検収', 'Acceptance', 'VERIFY', 'REQUESTER', null, 5, 3, 'OPTIONAL'],
        ['종료', '完了', 'Close', 'CLOSE', 'ROLE', 'IT_TEAM', null, null, 'NONE'],
    ];
    $zh = ['접수' => '受理', '검토' => '审查', '합의' => '协商', '승인' => '审批', '수행' => '执行', '검수' => '验收', '종료' => '结束'];
    foreach ($specs as $i => [$ko, $ja, $en, $type, $actor, $val, $rej, $due, $attach]) {
        $sid = PS::insertStepRaw($vid, $i + 1, $ko, $type, $actor, $val, 'ANY', in_array($type, ['REVIEW', 'AGREE', 'APPROVE', 'VERIFY'], true) ? 1 : 0, $rej, $due, $attach);
        App\Services\Translations::save('STEP', (string)$sid, ['ja' => $ja, 'en' => $en, 'zh' => $zh[$ko]]);
    }
}

/** 회사 A01 에 현업/전산 사용자, 서비스, 매핑을 갖춘 시나리오를 만든다. */
function rw(bool $legacy7 = true): array
{
    fresh();
    seed_two_companies();
    Scope::set('A01');
    if ($legacy7) {
        legacy_general_7();
    }
    $biz = Repo::insert('dept', ['dept_nm' => 'Biz', 'is_it' => 0, 'use_yn' => 1]);
    $oth = Repo::insert('dept', ['dept_nm' => 'Other', 'is_it' => 0, 'use_yn' => 1]);
    $it = (int)Repo::find('dept', ['is_it' => 1])['dept_id'];
    $mk = static fn(string $id, int $dept, array $roles): int => Users::create($id, ucfirst($id), "$id@example.com", $dept, 'ko', PW, $roles);
    $w = [
        'biz' => $biz, 'it' => $it, 'oth' => $oth,
        'req' => $mk('req', $biz, ['REQUESTER']),
        'bizit' => $mk('bizit', $biz, ['BIZ_IT']),
        'owner' => $mk('owner', $it, ['IT_TEAM']),
        'it2' => $mk('it2', $it, ['IT_TEAM']),
        'other' => $mk('other', $oth, ['REQUESTER']),
        'othbiz' => $mk('othbiz', $oth, ['BIZ_IT']),
    ];
    $w['svc'] = Repo::insert('service', ['service_cd' => 'ERP', 'service_nm' => 'ERP', 'biz_dept_id' => $biz, 'it_owner_id' => $w['owner'], 'use_yn' => 1]);
    PS::saveMapping([$w['svc'] => ['MODIFY' => (string)pid_of('GENERAL'), 'BUGFIX' => (string)pid_of('URGENT')]]);
    return $w;
}

function mk_req(array $w, array $o = []): int
{
    return RS::save(null, $w['req'], $o + [
        'service_id' => (string)$w['svc'], 'maint_type' => 'MODIFY', 'title' => '로그인 오류 수정',
        'content_html' => '<p>본문입니다</p>', 'priority' => 'NORMAL', 'req_due_date' => day(30),
    ]);
}

/** 제출된 요청을 만든다. */
function live(array $w, array $o = []): int
{
    $rid = mk_req($w, $o);
    WF::submit($rid, $w['req']);
    return $rid;
}

function rrow(int $rid): array
{
    return Repo::find('request', ['request_id' => $rid]);
}

function rstep_status(int $rid): array
{
    $out = [];
    foreach (Repo::list('request_step', ['request_id' => $rid], 'step_no') as $s) {
        $out[(int)$s['step_no']] = (string)$s['status'];
    }
    return $out;
}

function ctx_of(int $uid): array
{
    $user = Repo::find('user', ['user_id' => $uid]);
    $perms = array_column(Repo::query(
        'SELECT DISTINCT rp.perm_cd FROM user_role ur JOIN role_permission rp ON rp.company_cd = ur.company_cd AND rp.role_cd = ur.role_cd
          WHERE ur.company_cd = :company_cd AND ur.user_id = :u',
        ['u' => $uid]
    ), 'perm_cd');
    return [$user, ActorResolver::roleCodes($uid), $perms];
}

function can_view(int $rid, int $uid): bool
{
    [$u, $r, $p] = ctx_of($uid);
    return RequestAccess::canView($rid, $u, $r, $p);
}

function list_of(int $uid, string $scope): array
{
    [$u, $r, $p] = ctx_of($uid);
    return RS::listFor($u, $r, $p, ['scope' => $scope]);
}

function perr_p(callable $fn, string $key, array $params = []): void
{
    try {
        $fn();
    } catch (ProcessError $e) {
        eq($key, $e->key);
        foreach ($params as $k => $v) {
            eq($v, $e->params[$k] ?? null, "param $k");
        }
        return;
    }
    throw new RuntimeException("expected ProcessError $key");
}

function as_user(string $id, string $company = 'A01', string $pw = PW): void
{
    $_SESSION = [];
    login($company, $id, $pw);
}

// ======================================================================= 등록

test('request: 등록 검증(제목/완료요구일/우선순위/서비스/타입/매핑/버전)', function () {
    $w = rw();
    $ok = fn(array $o) => fn() => mk_req($w, $o);
    perr_p($ok(['title' => '']), 'request.err_title');
    perr_p($ok(['title' => str_repeat('가', 201)]), 'request.err_title');
    perr_p($ok(['req_due_date' => '']), 'request.err_due_required');
    perr_p($ok(['req_due_date' => '2026-02-30']), 'request.err_due_required');
    perr_p($ok(['req_due_date' => day(-1)]), 'request.err_due');
    perr_p($ok(['priority' => 'NOPE']), 'request.err_priority');
    perr_p($ok(['service_id' => '99999']), 'request.err_service');
    perr_p($ok(['maint_type' => 'NOPE']), 'request.err_type');
    perr_p($ok(['maint_type' => 'DATA']), 'request.err_no_process');
    // 활성 버전이 없는 프로세스
    $general = pid_of('GENERAL');
    PS::retire($general, latest_vid($general));
    perr_p($ok([]), 'request.err_no_version');
    $rid = mk_req($w, ['priority' => '', 'maint_type' => 'BUGFIX']);
    eq('NORMAL', rrow($rid)['priority'], '우선순위 미지정 시 보통');
});

test('request: 요청번호는 회사·월별로 독립 채번', function () {
    $w = rw();
    $ym = date('Ym');
    $a1 = mk_req($w);
    $a2 = mk_req($w);
    eq("SR-$ym-0001", rrow($a1)['request_no']);
    eq("SR-$ym-0002", rrow($a2)['request_no']);
    Scope::set('B01');
    $dept = Repo::insert('dept', ['dept_nm' => 'BB', 'is_it' => 0, 'use_yn' => 1]);
    $uid = (int)Repo::find('user', ['login_id' => 'user1'])['user_id'];
    $svc = Repo::insert('service', ['service_cd' => 'ERP', 'service_nm' => 'ERP', 'biz_dept_id' => $dept, 'use_yn' => 1]);
    PS::saveMapping([$svc => ['MODIFY' => (string)pid_of('GENERAL')]]);
    $b = RS::save(null, $uid, [
        'service_id' => (string)$svc, 'maint_type' => 'MODIFY', 'title' => 't', 'content_html' => '<p>x</p>', 'priority' => 'NORMAL', 'req_due_date' => day(5),
    ]);
    eq("SR-$ym-0001", rrow($b)['request_no']);
});

test('request: 임시저장 후 서비스/타입 변경 가능, 제출 후에는 수정 불가', function () {
    $w = rw();
    $rid = mk_req($w);
    $urgent = PS::resolveVersion(pid_of('URGENT'));
    RS::save($rid, $w['req'], [
        'service_id' => (string)$w['svc'], 'maint_type' => 'BUGFIX', 'title' => '수정됨', 'content_html' => '<p>b</p>', 'priority' => 'HIGH', 'req_due_date' => day(10),
    ]);
    $r = rrow($rid);
    eq('BUGFIX', $r['maint_type']);
    eq((int)$urgent['version_id'], (int)$r['version_id'], '유형 변경 시 프로세스 버전도 재결정');
    eq('수정됨', $r['title']);
    perr_p(fn() => RS::save($rid, $w['other'], ['title' => 'x', 'content_html' => '', 'req_due_date' => day(5), 'priority' => 'NORMAL']), 'request.err_forbidden');
    WF::submit($rid, $w['req']);
    perr_p(fn() => RS::save($rid, $w['req'], [
        'service_id' => (string)$w['svc'], 'maint_type' => 'BUGFIX', 'title' => 'x', 'content_html' => '<p>b</p>', 'req_due_date' => day(5), 'priority' => 'NORMAL',
    ]), 'request.err_state');
});

test('request: 완료요구일은 DB 트리거로도 필수(NULL/빈값 거부)', function () {
    $w = rw();
    $rid = mk_req($w);
    $base = [
        'request_no' => 'SR-X-1', 'service_id' => $w['svc'], 'maint_type' => 'MODIFY', 'title' => 't', 'content_html' => 'c',
        'version_id' => rrow($rid)['version_id'], 'requester_id' => $w['req'], 'status' => 'DRAFT',
        'created_at' => now_str(), 'updated_at' => now_str(),
    ];
    throws(fn() => Repo::insert('request', $base), PDOException::class);
    throws(fn() => Repo::insert('request', $base + ['req_due_date' => '']), PDOException::class);
    throws(fn() => Repo::update('request', ['request_id' => $rid], ['req_due_date' => null]), PDOException::class);
    throws(fn() => Repo::update('request', ['request_id' => $rid], ['req_due_date' => ' ']), PDOException::class);
});

test('html: 정화 - 스크립트/이벤트/위험 링크 제거, 서식은 유지', function () {
    $dirty = '<p class="ql-align-center" onclick="x()">안녕 <strong>굵게</strong><script>alert(1)</script></p>'
        . '<a href="javascript:alert(1)" onmouseover="y()">나쁜</a><a href="https://example.com/a?b=1&c=2">좋은</a>'
        . '<img src=x onerror=alert(1)><iframe src="http://evil"></iframe><ul><li data-list="bullet">항목</li></ul>'
        . '<svg><script>1</script></svg><style>p{}</style><div style="x:y">div</div><form action="/x"><input name=a></form>';
    $c = HtmlSanitizer::clean($dirty);
    foreach (['<script', 'onclick', 'onmouseover', 'javascript:', 'onerror', '<iframe', '<svg', '<style', '<form', '<input', '<img', 'style='] as $bad) {
        ok(!str_contains(strtolower($c), $bad), "남아 있으면 안 됨: $bad / $c");
    }
    foreach (['<strong>굵게</strong>', 'ql-align-center', 'href="https://example.com/a?b=1&amp;c=2"', '<li data-list="bullet">항목</li>', 'noopener', '안녕'] as $good) {
        ok(str_contains($c, $good), "있어야 함: $good / $c");
    }
    ok(str_contains($c, 'div'), 'div 태그는 풀리지만 내용은 유지');
    eq('', HtmlSanitizer::clean('   '));
    eq('안녕 굵게', HtmlSanitizer::text('<p>안녕</p><p><strong>굵게</strong></p>'));
    eq('a < b', HtmlSanitizer::text('<p>a &lt; b</p>'));
    // 이중 인코딩/중첩 우회
    ok(!str_contains(HtmlSanitizer::clean('<scr<script>ipt>alert(1)</scr</script>ipt>'), '<script'));
    ok(!str_contains(strtolower(HtmlSanitizer::clean('<a href=" JaVaScRiPt:alert(1)">x</a>')), 'javascript'));
});

test('dates: 영업일 계산/지연일', function () {
    eq('2026-11-09', Dates::addBusinessDays('2026-11-06', 1));   // 금 → 월
    eq('2026-11-10', Dates::addBusinessDays('2026-11-06', 2));
    eq('2026-11-16', Dates::addBusinessDays('2026-11-09', 5));
    eq('2026-11-06', Dates::addBusinessDays('2026-11-06', 0));
    eq(3, Dates::daysLate('2026-11-03', '2026-11-06'));
    eq(0, Dates::daysLate('2026-11-06', '2026-11-03'));
    ok(Dates::isDate('2026-02-28') && !Dates::isDate('2026-02-30') && !Dates::isDate('2026-1-1') && !Dates::isDate('abc'));
});

// ======================================================================= 제출/엔진

test('workflow: 제출 - 단계 스냅샷, 1단계 완료, 2단계 활성, 기한/버전 고정', function () {
    $w = rw();
    $rid = mk_req($w);
    $ver = (int)rrow($rid)['version_id'];
    eq(0, Repo::count('request_step', ['request_id' => $rid]), '임시저장은 단계 인스턴스 없음');
    eq(null, rrow($rid)['request_date'], '임시저장은 요청일(제출일) 없음');
    WF::submit($rid, $w['req']);
    $r = rrow($rid);
    eq(date('Y-m-d'), $r['request_date'], '최초 제출일이 요청일');
    eq('IN_PROGRESS', $r['status']);
    eq(2, (int)$r['current_step_no']);
    eq($ver, (int)$r['version_id']);
    eq([1 => 'DONE', 2 => 'ACTIVE', 3 => 'WAITING', 4 => 'WAITING', 5 => 'WAITING', 6 => 'WAITING', 7 => 'WAITING'], rstep_status($rid));
    $s2 = Repo::find('request_step', ['request_id' => $rid, 'step_no' => 2]);
    eq(Dates::addBusinessDays(date('Y-m-d'), 2), $s2['due_at']);
    eq('검토', $s2['step_nm'], '단계명 스냅샷');
    $ap = Repo::list('approval', ['rstep_id' => Repo::find('request_step', ['request_id' => $rid, 'step_no' => 1])['rstep_id']]);
    eq(['SUBMIT'], array_column($ap, 'action'));
});

test('workflow: 제출 검증(요청자만, 내용, 서비스 사용 여부, 과거 완료요구일, 처리자 부재, 상태)', function () {
    $w = rw();
    $rid = mk_req($w);
    perr_p(fn() => WF::submit($rid, $w['bizit']), 'request.err_forbidden');
    // 본문 없음
    $empty = mk_req($w, ['content_html' => '<p><br></p>']);
    perr_p(fn() => WF::submit($empty, $w['req']), 'request.err_content');
    // 과거 완료요구일(임시저장 후 시간이 흐른 경우)
    $past = mk_req($w);
    Repo::update('request', ['request_id' => $past], ['req_due_date' => day(-2)]);
    perr_p(fn() => WF::submit($past, $w['req']), 'request.err_due');
    // 서비스 비활성
    $off = mk_req($w);
    Repo::update('service', ['service_id' => $w['svc']], ['use_yn' => 0]);
    perr_p(fn() => WF::submit($off, $w['req']), 'request.err_service');
    Repo::update('service', ['service_id' => $w['svc']], ['use_yn' => 1]);
    // 단계 처리자가 없는 서비스(전산 담당자 미지정)
    $svc2 = Repo::insert('service', ['service_cd' => 'NOOWN', 'service_nm' => 'n', 'biz_dept_id' => $w['biz'], 'use_yn' => 1]);
    PS::saveMapping([$svc2 => ['MODIFY' => (string)pid_of('GENERAL')]]);
    $noOwner = mk_req($w, ['service_id' => (string)$svc2]);
    perr_p(fn() => WF::submit($noOwner, $w['req']), 'request.err_no_actor', ['step' => '수행']);
    try {
        WF::submit($noOwner, $w['req']);
    } catch (ProcessError $e) {
        ok(str_contains(t($e->key, $e->params), '전산 담당자'), '처리자 부재 사유가 메시지에 포함');
    }
    eq('DRAFT', rrow($noOwner)['status'], '실패 시 상태 불변');
    eq(0, Repo::count('request_step', ['request_id' => $noOwner]), '실패 시 단계 생성 롤백');
    // 정상 제출 후 재제출 불가
    WF::submit($rid, $w['req']);
    perr_p(fn() => WF::submit($rid, $w['req']), 'request.err_state');
});

test('workflow: 기본 시드 일반 프로세스(6단계) - 승인은 주관부서 현업 전산담당, 검수 반려는 수행으로', function () {
    $w = rw(false);
    $rid = live($w);
    eq(6, count(rstep_status($rid)));
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''));                        // 2 검토 → 3 승인
    perr_p(fn() => WF::act($rid, $w['it2'], 'PASS', ''), 'request.err_not_actor');   // 전산팀은 승인 불가
    perr_p(fn() => WF::act($rid, $w['othbiz'], 'PASS', ''), 'request.err_not_actor'); // 다른 부서 현업 전산담당도 불가
    eq('ADVANCED', WF::act($rid, $w['bizit'], 'PASS', ''));                        // 3 승인 → 4 수행
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''));                        // 4 수행 → 5 검수
    eq('REWORK', WF::act($rid, $w['req'], 'REJECT', '다시 해 주세요'));
    eq(4, (int)rrow($rid)['current_step_no'], '검수 반려는 수행 단계로');
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''));
    eq('ADVANCED', WF::act($rid, $w['req'], 'PASS', ''));                          // 5 검수 → 6 종료
    eq('COMPLETED', WF::act($rid, $w['it2'], 'PASS', ''));
    eq('COMPLETED', rrow($rid)['status']);
    // 승인 단계 반려는 검토로
    $r2 = live($w);
    WF::act($r2, $w['owner'], 'PASS', '');
    eq('REWORK', WF::act($r2, $w['bizit'], 'REJECT', '범위 재검토'));
    eq(2, (int)rrow($r2)['current_step_no']);
});

test('workflow: 일반 프로세스 전체 흐름(7단계) 완료', function () {
    $w = rw();
    $rid = live($w);
    $flow = [
        [$w['owner'], 'ADVANCED', 3], [$w['bizit'], 'ADVANCED', 4], [$w['it2'], 'ADVANCED', 5],
        [$w['owner'], 'ADVANCED', 6], [$w['req'], 'ADVANCED', 7],
    ];
    foreach ($flow as [$uid, $out, $next]) {
        eq($out, WF::act($rid, $uid, 'PASS', ''));
        eq($next, (int)rrow($rid)['current_step_no']);
    }
    eq('COMPLETED', WF::act($rid, $w['owner'], 'PASS', '종료'));
    $r = rrow($rid);
    eq('COMPLETED', $r['status']);
    eq(date('Y-m-d'), $r['actual_end']);
    eq(array_fill(1, 7, 'DONE'), rstep_status($rid));
    $actions = array_column(Repo::query(
        'SELECT a.action FROM approval a JOIN request_step rs ON rs.company_cd = a.company_cd AND rs.rstep_id = a.rstep_id
          WHERE a.company_cd = :company_cd AND rs.request_id = :r ORDER BY a.approval_id',
        ['r' => $rid]
    ), 'action');
    eq(['SUBMIT', 'APPROVE', 'AGREE', 'APPROVE', 'COMPLETE', 'APPROVE', 'COMPLETE'], $actions);
    perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', ''), 'request.err_state');
});

test('workflow: 처리자 판정 - 롤/서비스 전산담당/현업 주관부서/요청자, 그 외는 거부', function () {
    $w = rw();
    $rid = live($w);
    // 2단계(IT_TEAM 롤): 현업/타부서/요청자/관리자는 불가
    foreach (['req', 'bizit', 'other', 'othbiz'] as $k) {
        perr_p(fn() => WF::act($rid, $w[$k], 'PASS', ''), 'request.err_not_actor');
    }
    $admin = (int)Repo::find('user', ['login_id' => 'admin'])['user_id'];
    perr_p(fn() => WF::act($rid, $admin, 'PASS', ''), 'request.err_not_actor');
    eq('ADVANCED', WF::act($rid, $w['it2'], 'PASS', ''));          // ANY: 전산팀 아무나
    // 3단계(서비스 주관부서의 BIZ_IT): 다른 부서 BIZ_IT, 전산팀은 불가
    perr_p(fn() => WF::act($rid, $w['othbiz'], 'PASS', ''), 'request.err_not_actor');
    perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', ''), 'request.err_not_actor');
    // 이전 단계 처리자는 더 이상 처리 불가
    perr_p(fn() => WF::act($rid, $w['it2'], 'PASS', ''), 'request.err_not_actor');
    eq('ADVANCED', WF::act($rid, $w['bizit'], 'PASS', ''));
    eq('ADVANCED', WF::act($rid, $w['it2'], 'PASS', ''));           // 4단계 승인
    // 5단계(서비스 전산 담당자만): it2 불가
    perr_p(fn() => WF::act($rid, $w['it2'], 'PASS', ''), 'request.err_not_actor');
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''));
    // 6단계(요청자만)
    perr_p(fn() => WF::act($rid, $w['other'], 'PASS', ''), 'request.err_not_actor');
    eq('ADVANCED', WF::act($rid, $w['req'], 'PASS', ''));
});

test('workflow: ALL 승인 방식은 전원 처리 후 진행, 중복 처리 방지, 비활성 처리자 불가', function () {
    $w = rw();
    $rid = all_mode_req($w);
    eq('WAITING_OTHERS', WF::act($rid, $w['owner'], 'PASS', ''));
    eq(2, (int)rrow($rid)['current_step_no']);
    perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', ''), 'request.err_already');
    eq('ADVANCED', WF::act($rid, $w['it2'], 'PASS', ''));
    eq(3, (int)rrow($rid)['current_step_no']);
    eq('COMPLETED', WF::act($rid, $w['owner'], 'PASS', ''));
    // 비활성 사용자는 처리자가 될 수 없음
    $rid2 = live($w);
    Repo::update('user', ['user_id' => $w['owner']], ['use_yn' => 0]);
    perr_p(fn() => WF::act($rid2, $w['owner'], 'PASS', ''), 'request.err_not_actor');
});

/** ALL 승인 방식의 프로세스(제출→검토(ALL, IT_TEAM)→종료)를 ETC 타입에 매핑하고 요청을 제출한다. */
function all_mode_req(array $w): int
{
    $pid = PS::createProcess('ALLP', 'all', '', []);
    $vid = latest_vid($pid);
    $rev = PS::addStep($pid, $vid, step_in(['step_nm' => '전원검토', 'approval_mode' => 'ALL']));
    $steps = PS::steps($vid);
    PS::reorder($pid, $vid, [(int)$steps[0]['step_id'], $rev, (int)$steps[1]['step_id']]);
    PS::activate($pid, $vid, '2020-01-01', '');
    PS::saveMapping([$w['svc'] => ['ETC' => (string)$pid]]);
    return live($w, ['maint_type' => 'ETC']);
}

test('workflow: 반려 - 이전 단계로 되돌림(REWORK), 이후 단계 초기화, 이력 보존, 회차 증가', function () {
    $w = rw();
    $rid = live($w);
    WF::act($rid, $w['owner'], 'PASS', '');            // → 3
    WF::act($rid, $w['bizit'], 'PASS', '');            // → 4
    perr_p(fn() => WF::act($rid, $w['it2'], 'REJECT', ''), 'request.err_opinion_required');
    eq('REWORK', WF::act($rid, $w['it2'], 'REJECT', '범위 재검토 필요'));   // 4단계 반려 → 2단계
    $r = rrow($rid);
    eq('IN_PROGRESS', $r['status']);
    eq(2, (int)$r['current_step_no']);
    eq([1 => 'DONE', 2 => 'ACTIVE', 3 => 'WAITING', 4 => 'WAITING', 5 => 'WAITING', 6 => 'WAITING', 7 => 'WAITING'], rstep_status($rid));
    eq(2, (int)Repo::find('request_step', ['request_id' => $rid, 'step_no' => 2])['round'], '재활성화 시 회차 증가');
    $rej = Repo::query(
        "SELECT a.opinion, a.round FROM approval a JOIN request_step rs ON rs.company_cd = a.company_cd AND rs.rstep_id = a.rstep_id
          WHERE a.company_cd = :company_cd AND rs.request_id = :r AND a.action = 'REJECT'",
        ['r' => $rid]
    );
    eq(1, count($rej));
    eq('범위 재검토 필요', $rej[0]['opinion']);
    // 같은 2단계를 새 회차에서 다시 처리할 수 있음(이전 회차 처리 기록과 무관)
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', '재검토 완료'));
    eq(3, (int)rrow($rid)['current_step_no']);
    // 총 이력: 이전 회차 기록이 모두 남음
    eq(5, Repo::count('approval', []), '제출/검토/합의/반려/재검토 이력 보존');
});

test('workflow: 1단계로 반려하면 보완요청(RETURNED) → 수정 후 재제출', function () {
    $w = rw();
    $rid = live($w);
    Repo::update('request', ['request_id' => $rid], ['request_date' => '2026-01-05']);
    eq('RETURNED', WF::act($rid, $w['owner'], 'REJECT', '자료 보완 바랍니다'));    // 2단계 reject_to=1
    $r = rrow($rid);
    eq('RETURNED', $r['status']);
    eq(1, (int)$r['current_step_no']);
    eq([1 => 'ACTIVE'] + array_fill(2, 6, 'WAITING'), rstep_status($rid));
    perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', ''), 'request.err_state');
    // 요청자만 수정/재제출
    perr_p(fn() => WF::submit($rid, $w['other']), 'request.err_forbidden');
    RS::save($rid, $w['req'], ['title' => '보완한 제목', 'content_html' => '<p>보완 내용</p>', 'priority' => 'HIGH', 'req_due_date' => day(20)]);
    eq('보완한 제목', rrow($rid)['title']);
    WF::submit($rid, $w['req']);
    $r = rrow($rid);
    eq('IN_PROGRESS', $r['status']);
    eq('2026-01-05', $r['request_date'], '재제출해도 최초 제출일 유지');
    eq(2, (int)$r['current_step_no']);
    eq(7, Repo::count('request_step', ['request_id' => $rid]), '재제출해도 단계 행은 늘지 않음');
    eq('DONE', rstep_status($rid)[1]);
    $submits = Repo::count('approval', ['action' => 'SUBMIT']);
    eq(2, $submits, '제출 이력 2회');
    // 서비스/타입은 반려 상태에서 바꿀 수 없음(버전 고정)
    $before = rrow($rid);
    WF::act($rid, $w['owner'], 'REJECT', '다시');
    RS::save($rid, $w['req'], ['service_id' => '999', 'maint_type' => 'BUGFIX', 'title' => 'x', 'content_html' => '<p>b</p>', 'priority' => 'NORMAL', 'req_due_date' => day(20)]);
    eq($before['maint_type'], rrow($rid)['maint_type']);
    eq($before['version_id'], rrow($rid)['version_id']);
});

test('workflow: 반려 불가 단계, 일정 협의 중 처리 잠금, 의견 길이', function () {
    $w = rw();
    $rid = live($w);
    foreach ([$w['owner'], $w['bizit'], $w['it2']] as $u) {
        WF::act($rid, $u, 'PASS', '');
    }
    // 5단계(수행)는 반려 불가
    perr_p(fn() => WF::act($rid, $w['owner'], 'REJECT', '사유'), 'request.err_no_reject');
    perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', str_repeat('가', 2001)), 'request.err_opinion_len');
    perr_p(fn() => WF::act($rid, $w['owner'], 'MAYBE', ''), 'request.err_state');
    // 일정 변경 합의 대기 중에는 단계 처리 불가(M4 연동 지점)
    Repo::insert('schedule_change', [
        'request_id' => $rid, 'step_no' => 5, 'prev_end' => day(30), 'new_end' => day(40), 'reason_cd' => 'RESOURCE', 'reason_text' => '인력 부족',
        'proposer_id' => $w['owner'], 'status' => 'PROPOSED', 'proposed_at' => now_str(),
    ]);
    ok(RS::isLocked($rid));
    perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', ''), 'request.err_schedule_locked');
    Repo::update('schedule_change', ['request_id' => $rid], ['status' => 'REJECTED']);
    ok(!RS::isLocked($rid));
    eq('ADVANCED', WF::act($rid, $w['owner'], 'PASS', ''));
});

test('migration 009: 일반 요청의 요청일을 최초 제출일로 채운다(작업일지·임시저장 제외, 멱등)', function () {
    $w = rw();
    $a = live($w);                       // 단계 시각이 있는 제출 건
    $b = live($w);                       // 단계 시각이 없는 건(예외 데이터)
    $c = mk_req($w);                     // 임시저장
    $d = live($w);                       // 작성 날짜가 이미 있는 건
    Repo::update('request', ['request_id' => $a], ['request_date' => null, 'created_at' => '2026-03-01 09:00:00']);
    Repo::update('request_step', ['request_id' => $a, 'step_no' => 1], ['started_at' => '2026-01-07 10:00:00', 'ended_at' => '2026-01-08 09:00:00']);
    Repo::update('request', ['request_id' => $b], ['request_date' => null, 'created_at' => '2026-02-03 09:00:00']);
    Repo::update('request_step', ['request_id' => $b, 'step_no' => 1], ['started_at' => null, 'ended_at' => null]);
    Repo::update('request', ['request_id' => $d], ['request_date' => '2026-04-09']);
    $sql = (string)file_get_contents(APP_ROOT . '/database/migrations/009_request_date_submit.sql');
    Db::pdo()->exec($sql);
    eq('2026-01-07', rrow($a)['request_date'], '접수 단계 started_at(최초 제출)');
    eq('2026-02-03', rrow($b)['request_date'], '단계 시각이 없으면 created_at');
    eq(null, rrow($c)['request_date'], '임시저장은 그대로');
    eq('2026-04-09', rrow($d)['request_date'], '이미 값이 있으면 유지');
    Db::pdo()->exec($sql);
    eq('2026-01-07', rrow($a)['request_date'], '다시 실행해도 변하지 않음');
});

test('migration 010: 취소일을 채우고 제출하지 않은 취소 건의 요청일을 비운다(멱등)', function () {
    $w = rw();
    $a = live($w);                       // 제출 후 취소
    $b = mk_req($w);                     // 임시저장 후 취소(009 가 요청일을 채웠던 상태)
    $c = live($w);                       // 취소하지 않은 건
    WF::cancel($a, $w['req']);
    WF::cancel($b, $w['req']);
    Repo::update('request', ['request_id' => $a], ['canceled_at' => null, 'updated_at' => '2026-03-05 10:00:00']);
    Repo::update('request', ['request_id' => $b], ['canceled_at' => null, 'request_date' => '2026-01-02']);
    $sql = (string)preg_replace('/^ALTER TABLE.*$/m', '', (string)file_get_contents(APP_ROOT . '/database/migrations/010_request_canceled_at.sql'));
    Db::pdo()->exec($sql);
    eq('2026-03-05', rrow($a)['canceled_at'], '마지막 수정 시각이 취소일');
    ok(rrow($a)['request_date'] !== null, '제출한 건의 요청일은 유지');
    eq(substr((string)rrow($b)['updated_at'], 0, 10), rrow($b)['canceled_at']);
    eq(null, rrow($b)['request_date'], '제출한 적 없는 취소 건은 요청일 없음');
    eq(null, rrow($c)['canceled_at']);
    Db::pdo()->exec($sql);
    eq('2026-03-05', rrow($a)['canceled_at'], '다시 실행해도 변하지 않음');
});

test('workflow: 취소 - 요청자만, 진행 중/임시/보완 상태에서만', function () {
    $w = rw();
    $draft = mk_req($w);
    WF::cancel($draft, $w['req']);
    eq('CANCELED', rrow($draft)['status']);
    eq(date('Y-m-d'), rrow($draft)['canceled_at'], '취소일 기록');
    eq(null, rrow($draft)['request_date'], '제출하지 않은 취소 건은 요청일이 없다');
    $rid = live($w);
    perr_p(fn() => WF::cancel($rid, $w['bizit']), 'request.err_forbidden');
    perr_p(fn() => WF::cancel($rid, $w['owner']), 'request.err_forbidden');
    WF::cancel($rid, $w['req']);
    eq('CANCELED', rrow($rid)['status']);
    perr_p(fn() => WF::cancel($rid, $w['req']), 'request.err_state');
    perr_p(fn() => WF::act($rid, $w['owner'], 'PASS', ''), 'request.err_state');
    ok(Repo::count('approval', ['action' => 'CANCEL']) === 1);
    // 완료된 건은 취소 불가
    $done = live($w);
    foreach ([$w['owner'], $w['bizit'], $w['it2'], $w['owner'], $w['req'], $w['owner']] as $u) {
        WF::act($done, $u, 'PASS', '');
    }
    eq('COMPLETED', rrow($done)['status']);
    perr_p(fn() => WF::cancel($done, $w['req']), 'request.err_state');
});

test('workflow: 프로세스 버전 고정 - A1 로 접수된 요청은 A2 활성화 후에도 A1 단계대로 완료', function () {
    $w = rw();
    $general = pid_of('GENERAL');
    $v1 = latest_vid($general);
    $old = live($w);
    eq($v1, (int)rrow($old)['version_id']);
    // A2: 3번째 단계(합의)를 삭제한 새 버전을 오늘부터 적용
    $v2 = PS::createVersion($general, $v1);
    PS::deleteStep($general, $v2, (int)PS::steps($v2)[2]['step_id']);
    PS::activate($general, $v2, day(0), '');
    eq('RETIRED', Repo::find('process_version', ['version_id' => $v1])['status']);
    $new = live($w);
    eq($v2, (int)rrow($new)['version_id']);
    eq(6, Repo::count('request_step', ['request_id' => $new]));
    eq(7, Repo::count('request_step', ['request_id' => $old]), 'A1 건은 7단계 유지');
    // A1 건은 합의 단계(현업 BIZ_IT)를 그대로 거쳐 완료
    WF::act($old, $w['owner'], 'PASS', '');
    eq('합의', Repo::find('request_step', ['request_id' => $old, 'step_no' => 3])['step_nm']);
    eq('ADVANCED', WF::act($old, $w['bizit'], 'PASS', ''));
    foreach ([$w['it2'], $w['owner'], $w['req'], $w['owner']] as $u) {
        WF::act($old, $u, 'PASS', '');
    }
    eq('COMPLETED', rrow($old)['status']);
    eq((int)$v1, (int)rrow($old)['version_id']);
    // A2 건은 3단계가 승인(전산팀)
    eq('승인', Repo::find('request_step', ['request_id' => $new, 'step_no' => 3])['step_nm']);
    WF::act($new, $w['owner'], 'PASS', '');
    perr_p(fn() => WF::act($new, $w['bizit'], 'PASS', ''), 'request.err_not_actor');
});

// ======================================================================= 조회 권한/목록

test('access: 열람 범위(본인/주관부서/전체/처리자/참조자/임시저장)', function () {
    $w = rw();
    $admin = (int)Repo::find('user', ['login_id' => 'admin'])['user_id'];
    $draft = mk_req($w);
    ok(can_view($draft, $w['req']));
    foreach (['owner', 'bizit', 'other', 'it2'] as $k) {
        ok(!can_view($draft, $w[$k]), "임시저장은 요청자만: $k");
    }
    ok(!can_view($draft, $admin));
    $rid = live($w);
    ok(can_view($rid, $w['req']), '요청자');
    ok(can_view($rid, $w['bizit']), '주관부서 BIZ_IT');
    ok(can_view($rid, $w['owner']) && can_view($rid, $w['it2']), '전산팀(전체 조회)');
    ok(can_view($rid, $admin), '관리자(전체 조회)');
    ok(!can_view($rid, $w['other']), '타 부서 일반 사용자');
    ok(!can_view($rid, $w['othbiz']), '타 부서 BIZ_IT');
    // 참조자(CC)로 지정되면 읽기 전용으로 볼 수 있음
    $first = (int)PS::steps(rrow($rid)['version_id'])[0]['step_id'];
    Repo::insert('process_step_cc', ['step_id' => $first, 'cc_type' => 'USER', 'cc_value' => (string)$w['other'], 'notify_on' => 'NONE']);
    ok(can_view($rid, $w['other']), '참조자');
    ok(!can_view($rid, $w['othbiz']));
    // CC 권한(REQUEST_VIEW_CC)을 롤에서 빼면 볼 수 없음
    Repo::delete('role_permission', ['role_cd' => 'REQUESTER', 'perm_cd' => 'REQUEST_VIEW_CC']);
    ok(!can_view($rid, $w['other']), 'CC 권한 없음');
    ok(!can_view(99999, $w['req']));
});

test('access: 다른 회사 요청은 보이지 않음', function () {
    $w = rw();
    $rid = live($w);
    Scope::set('B01');
    $buser = (int)Repo::find('user', ['login_id' => 'admin'])['user_id'];
    ok(!can_view($rid, $buser));
    eq(0, list_of($buser, 'all')['total']);
    eq(null, Repo::find('request', ['request_id' => $rid]));
});

test('list: 범위(mine/inbox/dept/all/cc) 및 권한에 없는 범위는 mine 으로', function () {
    $w = rw();
    $rid = live($w);
    $draft = mk_req($w, ['title' => '임시 건']);
    eq(2, list_of($w['req'], 'mine')['total'], '내 요청(임시 포함)');
    eq(0, list_of($w['other'], 'mine')['total']);
    eq(1, list_of($w['bizit'], 'dept')['total'], '주관부서(임시 제외)');
    eq('mine', list_of($w['other'], 'all')['scope'], '권한 없는 범위는 mine 으로');
    eq('all', list_of($w['owner'], 'all')['scope']);
    eq(1, list_of($w['owner'], 'all')['total'], '전체(임시 제외)');
    // 처리함: 2단계 처리자(전산팀)에게만
    eq(1, list_of($w['owner'], 'inbox')['total']);
    eq(1, list_of($w['it2'], 'inbox')['total']);
    eq(0, list_of($w['bizit'], 'inbox')['total']);
    eq(0, list_of($w['req'], 'inbox')['total']);
    WF::act($rid, $w['owner'], 'PASS', '');
    eq(0, list_of($w['owner'], 'inbox')['total'], '처리 후 처리함에서 사라짐');
    eq(0, list_of($w['it2'], 'inbox')['total'], '다른 단계로 이동');
    eq(1, list_of($w['bizit'], 'inbox')['total']);
    // 보완요청 건은 요청자 처리함에 나타남
    $r2 = live($w, ['title' => '보완 건']);
    WF::act($r2, $w['owner'], 'REJECT', '보완');
    eq(1, list_of($w['req'], 'inbox')['total']);
    // 필터
    [$u, $ro, $pe] = ctx_of($w['req']);
    eq(1, RS::listFor($u, $ro, $pe, ['scope' => 'mine', 'q' => '보완'])['total']);
    eq(1, RS::listFor($u, $ro, $pe, ['scope' => 'mine', 'status' => 'DRAFT'])['total']);
    eq(0, RS::listFor($u, $ro, $pe, ['scope' => 'mine', 'service_id' => 99999])['total']);
    eq(3, RS::listFor($u, $ro, $pe, ['scope' => 'mine', 'q' => ''])['total']);
    // 참조 범위
    $first = (int)PS::steps(rrow($rid)['version_id'])[0]['step_id'];
    Repo::insert('process_step_cc', ['step_id' => $first, 'cc_type' => 'USER', 'cc_value' => (string)$w['other'], 'notify_on' => 'NONE']);
    eq(2, list_of($w['other'], 'cc')['total']);
    eq(0, list_of($w['othbiz'], 'cc')['total']);
    // LIKE 특수문자/SQL 주입 시도
    eq(0, RS::listFor($u, $ro, $pe, ['scope' => 'mine', 'q' => "' OR 1=1 --"])['total']);
});

test('list: 페이지네이션', function () {
    $w = rw();
    for ($i = 0; $i < 23; $i++) {
        mk_req($w, ['title' => "건 $i"]);
    }
    [$u, $ro, $pe] = ctx_of($w['req']);
    $p1 = RS::listFor($u, $ro, $pe, ['scope' => 'mine', 'page' => 1]);
    eq(23, $p1['total']);
    eq(2, $p1['pages']);
    eq(20, count($p1['rows']));
    eq(3, count(RS::listFor($u, $ro, $pe, ['scope' => 'mine', 'page' => 2])['rows']));
    eq(2, RS::listFor($u, $ro, $pe, ['scope' => 'mine', 'page' => 99])['page'], '범위를 넘는 페이지는 마지막으로');
});

test('detail: 스테퍼/타임라인/댓글', function () {
    $w = rw();
    $rid = live($w);
    WF::act($rid, $w['owner'], 'PASS', '검토 의견');
    $steps = RS::stepper(rrow($rid), WF::service(rrow($rid)));
    eq(7, count($steps));
    eq(['DONE', 'DONE', 'ACTIVE', 'WAITING', 'WAITING', 'WAITING', 'WAITING'], array_column($steps, 'state'));
    eq(['Bizit'], $steps[2]['actor_names']);
    eq(['Req'], $steps[0]['actor_names']);
    RS::addComment($rid, $w['bizit'], '확인 중입니다');
    perr_p(fn() => RS::addComment($rid, $w['bizit'], '  '), 'request.err_comment');
    perr_p(fn() => RS::addComment($rid, $w['bizit'], str_repeat('a', 2001)), 'request.err_comment');
    perr_p(fn() => RS::addComment(mk_req($w), $w['req'], '임시저장에는 댓글 불가'), 'request.err_state');
    $tl = RS::timeline($rid);
    eq(['CREATED', 'APPROVAL', 'APPROVAL', 'COMMENT'], array_column($tl, 'kind'));
    eq(['SUBMIT', 'APPROVE'], array_column(array_filter($tl, fn($e) => $e['kind'] === 'APPROVAL'), 'action'));
    eq('검토 의견', array_values(array_filter($tl, fn($e) => $e['action'] === 'APPROVE'))[0]['text']);
});

// ======================================================================= HTTP

test('http: 요청 등록→제출→상세→처리 전체 화면 흐름', function () {
    $w = rw();
    as_user('req');
    $form = http('GET', '/requests/new');
    eq(200, $form->status);
    ok(str_contains($form->body, 'name="req_due_date"') && str_contains($form->body, 'vendor/quill/quill.js'));
    ok(!str_contains($form->body, 'cdn.') && !str_contains($form->body, 'https://'), '외부 URL 없음');
    // 완료요구일 없이는 등록 불가(폼으로 되돌아옴)
    $r = http('POST', '/requests', ['service_id' => (string)$w['svc'], 'maint_type' => 'MODIFY', 'title' => 't', 'content_html' => '<p>x</p>', 'priority' => 'NORMAL', 'req_due_date' => '', 'submit' => '1']);
    eq(200, $r->status);
    ok(body_has($r, 'request.err_due_required'));
    Scope::set('A01');
    eq(0, Repo::count('request'));
    // 정상 제출
    $r = http('POST', '/requests', [
        'service_id' => (string)$w['svc'], 'maint_type' => 'MODIFY', 'title' => '화면 등록', 'content_html' => '<p>내용 <script>alert(1)</script><b>굵게</b></p>',
        'priority' => 'HIGH', 'req_due_date' => day(14), 'submit' => '1',
    ]);
    eq(302, $r->status);
    Scope::set('A01');
    $req = Repo::find('request', ['title' => '화면 등록']);
    eq("/requests/{$req['request_id']}", $r->headers['Location']);
    eq(t('request.submitted'), last_flash());
    eq('IN_PROGRESS', $req['status']);
    ok(!str_contains($req['content_html'], 'script') && str_contains($req['content_html'], '<b>굵게</b>'), '저장 시 정화');
    $rid = (int)$req['request_id'];
    $show = http('GET', "/requests/$rid");
    eq(200, $show->status);
    foreach (['화면 등록', $req['request_no'], '검토', '합의', '종료', 'Owner', 'Bizit'] as $needle) {
        ok(str_contains($show->body, $needle), "상세에 표시: $needle");
    }
    ok(!str_contains($show->body, '<script>alert'), 'XSS 없음');
    // 타 사용자는 404
    as_user('other');
    eq(404, http('GET', "/requests/$rid")->status);
    eq(404, http('POST', "/requests/$rid/comments", ['body' => 'x'])->status);
    eq(404, http('POST', "/requests/$rid/cancel")->status);
    // 전산팀 처리함 → 처리
    as_user('owner');
    eq('/requests?scope=inbox', http('GET', '/inbox')->headers['Location']);
    $inbox = http('GET', '/requests?scope=inbox');
    ok(str_contains($inbox->body, $req['request_no']));
    ok(str_contains(http('GET', "/requests/$rid")->body, 'name="decision"'), '처리 폼 표시');
    $r = http('POST', "/requests/$rid/act", ['decision' => 'PASS', 'opinion' => '검토 완료']);
    eq("/requests/$rid", $r->headers['Location']);
    eq(t('request.out_advanced'), last_flash());
    Scope::set('A01');
    eq(3, (int)rrow($rid)['current_step_no']);
    ok(!str_contains(http('GET', "/requests/$rid")->body, 'name="decision"'), '처리 완료 후 폼 없음');
    // 처리자가 아닌 사용자
    as_user('req');
    http('POST', "/requests/$rid/act", ['decision' => 'PASS']);
    eq(t('request.err_not_actor'), last_flash());
    // 반려는 사유 필수
    as_user('bizit');
    http('POST', "/requests/$rid/act", ['decision' => 'REJECT', 'opinion' => '']);
    eq(t('request.err_opinion_required'), last_flash());
    http('POST', "/requests/$rid/act", ['decision' => 'REJECT', 'opinion' => '범위 불명확']);
    eq(t('request.out_rework'), last_flash());
    Scope::set('A01');
    eq(2, (int)rrow($rid)['current_step_no']);
});

test('http: 수정/제출/취소 권한 및 상태', function () {
    $w = rw();
    $draft = mk_req($w);
    as_user('req');
    eq(200, http('GET', "/requests/$draft/edit")->status);
    $r = http('POST', "/requests/$draft", ['service_id' => (string)$w['svc'], 'maint_type' => 'MODIFY', 'title' => '수정', 'content_html' => '<p>c</p>', 'priority' => 'LOW', 'req_due_date' => day(9)]);
    eq("/requests/$draft", $r->headers['Location']);
    Scope::set('A01');
    eq('수정', rrow($draft)['title']);
    eq('DRAFT', rrow($draft)['status']);
    $r = http('POST', "/requests/$draft/submit");
    Scope::set('A01');
    eq('IN_PROGRESS', rrow($draft)['status']);
    // 제출 후에는 편집 화면/수정/재제출 불가
    eq(403, http('GET', "/requests/$draft/edit")->status);
    eq(403, http('POST', "/requests/$draft", ['title' => 'x'])->status);
    eq(403, http('POST', "/requests/$draft/submit")->status);
    // 열람 가능하지만 요청자가 아닌 사용자는 취소 불가
    as_user('bizit');
    eq(200, http('GET', "/requests/$draft")->status);
    http('POST', "/requests/$draft/cancel");
    eq(t('request.err_forbidden'), last_flash());
    eq(403, http('GET', "/requests/$draft/edit")->status);
    as_user('req');
    http('POST', "/requests/$draft/cancel");
    eq(t('request.canceled'), last_flash());
    Scope::set('A01');
    eq('CANCELED', rrow($draft)['status']);
});

test('http: 권한 없는 사용자와 비로그인 접근 차단', function () {
    $w = rw();
    $rid = live($w);
    $_SESSION = [];
    foreach (["/requests", "/requests/new", "/requests/$rid", "/inbox"] as $u) {
        eq('/login', http('GET', $u)->headers['Location'], $u);
    }
    eq('/login', http('POST', "/requests/$rid/act", ['decision' => 'PASS'])->headers['Location']);
    // 요청 등록 권한을 뺀 롤은 등록 화면 403
    as_user('req');
    Repo::delete('role_permission', ['role_cd' => 'REQUESTER', 'perm_cd' => 'REQUEST_CREATE']);
    eq(403, http('GET', '/requests/new')->status);
    eq(403, http('POST', '/requests', ['title' => 'x'])->status);
    // CSRF
    eq(403, http('POST', "/requests/$rid/comments", ['body' => 'x'], false)->status);
});

test('http: 댓글/일정 협의 잠금 표시/XSS 이스케이프', function () {
    $w = rw();
    $rid = live($w, ['title' => '<b>굵은 제목</b>']);
    as_user('bizit');
    http('POST', "/requests/$rid/comments", ['body' => '<script>alert(1)</script> 확인']);
    $body = http('GET', "/requests/$rid")->body;
    ok(!str_contains($body, '<script>alert(1)</script>'));
    ok(str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt; 확인'));
    ok(str_contains($body, '&lt;b&gt;굵은 제목&lt;/b&gt;'), '제목 이스케이프');
    Repo::insert('schedule_change', [
        'request_id' => $rid, 'step_no' => 2, 'prev_end' => day(30), 'new_end' => day(40), 'reason_cd' => 'RESOURCE', 'reason_text' => 'r',
        'proposer_id' => $w['owner'], 'status' => 'PROPOSED', 'proposed_at' => now_str(),
    ]);
    as_user('owner');
    $body = http('GET', "/requests/$rid")->body;
    ok(str_contains($body, e(t('request.locked_badge'))));
    ok(!str_contains($body, 'name="decision"'), '협의 중에는 처리 버튼 없음');
    http('POST', "/requests/$rid/act", ['decision' => 'PASS']);
    eq(t('request.err_schedule_locked'), last_flash());
});

test('http: 요청 목록 페이지 이동(이전/다음, 현재 범위 표시)', function () {
    $w = rw();
    for ($i = 0; $i < 45; $i++) {
        mk_req($w, ['title' => "페이지 테스트 $i"]);
    }
    as_user('req');
    $p1 = http('GET', '/requests?scope=mine')->body;
    ok(str_contains($p1, e(t('request.range', ['from' => 1, 'to' => 20, 'n' => 45]))), '1페이지 범위');
    ok(str_contains($p1, '<span class="page-link">&lsaquo; ' . e(t('common.page_prev')) . '</span>'), '첫 페이지의 이전은 비활성');
    $p2 = http('GET', '/requests?scope=mine&page=2')->body;
    ok(str_contains($p2, e(t('request.range', ['from' => 21, 'to' => 40, 'n' => 45]))), '2페이지 범위');
    ok(str_contains($p2, 'page=1') && str_contains($p2, 'page=3'), '이전/다음 링크');
    $p3 = http('GET', '/requests?scope=mine&page=3')->body;
    ok(str_contains($p3, e(t('request.range', ['from' => 41, 'to' => 45, 'n' => 45]))), '마지막 페이지 범위');
    ok(str_contains($p3, '<span class="page-link">' . e(t('common.page_next')) . ' &rsaquo;</span>'), '마지막 페이지의 다음은 비활성');
    $none = http('GET', '/requests?scope=mine&q=' . rawurlencode('없는검색어'))->body;
    ok(str_contains($none, e(t('request.total', ['n' => 0]))), '결과 없음은 총 0건');
});

test('env_int: 빈 값·비숫자는 기본값, 범위는 보정', function () {
    foreach ([['', 20], ['abc', 20], ['-3', 20], ['0', 5], ['7', 7], ['1000', 200]] as [$v, $exp]) {
        \App\Core\Env::set('LIST_PAGE_SIZE', $v);
        eq($exp, env_int('LIST_PAGE_SIZE', 20, 5, 200), "LIST_PAGE_SIZE='$v'");
    }
    \App\Core\Env::set('LIST_PAGE_SIZE', '');
});

test('http: LIST_PAGE_SIZE 설정이 목록 페이지 크기에 반영', function () {
    $w = rw();
    for ($i = 0; $i < 25; $i++) {
        mk_req($w, ['title' => "설정 테스트 $i"]);
    }
    as_user('req');
    \App\Core\Env::set('LIST_PAGE_SIZE', '10');
    $p1 = http('GET', '/requests?scope=mine')->body;
    \App\Core\Env::set('LIST_PAGE_SIZE', '');
    ok(str_contains($p1, e(t('request.range', ['from' => 1, 'to' => 10, 'n' => 25]))), '10건 단위 범위');
});

test('http: 프로세스 미리보기(JSON)', function () {
    $w = rw();
    as_user('req');
    $r = http('GET', '/requests/preview?service_id=' . $w['svc'] . '&maint_type=MODIFY');
    $j = json_decode($r->body, true);
    eq(true, $j['ok']);
    eq('일반 유지보수', $j['process']);
    eq(7, count($j['steps']));
    eq('접수', $j['steps'][0]['name']);
    eq('전산팀', $j['steps'][1]['actor'], '롤 처리자는 롤 이름으로 표시');
    eq(t('actor.REQUESTER'), $j['steps'][0]['actor']);
    $j = json_decode(http('GET', '/requests/preview?service_id=' . $w['svc'] . '&maint_type=DATA')->body, true);
    eq(false, $j['ok']);
    $j = json_decode(http('GET', '/requests/preview?service_id=99999&maint_type=MODIFY')->body, true);
    eq(false, $j['ok']);
    http('POST', '/lang', ['lang' => 'en', 'back' => '/']);
    Scope::set('A01');
    $j = json_decode(http('GET', '/requests/preview?service_id=' . $w['svc'] . '&maint_type=MODIFY')->body, true);
    eq('General Maintenance', $j['process']);
});

test('http: 회사 격리 - 다른 회사 사용자는 요청/처리/목록에 접근 불가', function () {
    $w = rw();
    $rid = live($w);
    as_user('admin', 'B01', 'Admin5678');
    eq(404, http('GET', "/requests/$rid")->status);
    eq(404, http('POST', "/requests/$rid/act", ['decision' => 'PASS'])->status);
    eq(404, http('POST', "/requests/$rid/cancel")->status);
    eq(404, http('POST', "/requests/$rid/comments", ['body' => 'x'])->status);
    $list = http('GET', '/requests?scope=all');
    eq(200, $list->status);
    ok(!str_contains($list->body, '로그인 오류 수정'));
    Scope::set('A01');
    eq('IN_PROGRESS', rrow($rid)['status']);
    eq(0, Repo::count('comment'));
});

test('http: 일본어/영어/중국어 화면 렌더링(요청 화면 번역 키 사용)', function () {
    $w = rw();
    $rid = live($w);
    foreach (['ja' => '依頼', 'en' => 'Request', 'zh' => '请求'] as $lang => $needle) {
        as_user('req');
        http('POST', '/lang', ['lang' => $lang, 'back' => '/']);
        Scope::set('A01');
        foreach (['/requests', '/requests/new', "/requests/$rid"] as $u) {
            $r = http('GET', $u);
            eq(200, $r->status, "$lang $u");
            ok(str_contains($r->body, $needle), "$lang $u 번역 표시");
        }
    }
});

test('home: 요청 현황 요약(내 진행 건/처리 대기)', function () {
    $w = rw();
    live($w);
    as_user('owner');
    $body = http('GET', '/')->body;
    ok(str_contains($body, '/inbox'), '처리함 링크');
    eq(200, http('GET', '/')->status);
});
