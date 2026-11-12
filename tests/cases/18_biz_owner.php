<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Env;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\ActorResolver;
use App\Services\Doctor;
use App\Services\ProcessService as PS;
use App\Services\RequestService as RS;
use App\Services\Seeder;
use App\Services\Users;
use App\Services\WorkflowService as WF;

/** 기본 시드(승인=서비스 현업 IT 담당자) 일반 프로세스에서 승인 단계에 도달한 요청과 같은 부서의 현업 전산담당 2명. */
function bo_world(): array
{
    $w = rw(false);
    $w['bizit2'] = Users::create('bizit2', 'Bizit2', 'bizit2@example.com', $w['biz'], 'ko', PW, ['BIZ_IT']);
    $w['rid'] = live($w);
    WF::act($w['rid'], $w['owner'], 'PASS', '');
    return $w;
}

/** @return list<int> */
function bo_actors(array $w): array
{
    $req = rrow($w['rid']);
    $ids = ActorResolver::userIds(PS::steps((int)$req['version_id'])[2], $req, WF::service($req));
    sort($ids);
    return $ids;
}

function bo_designate(array $w, int ...$ids): void
{
    Repo::delete('service_biz_it', ['service_id' => $w['svc']]);
    foreach ($ids as $id) {
        Repo::insert('service_biz_it', ['service_id' => $w['svc'], 'user_id' => $id]);
    }
}

/** PHP 판정(canAct)과 SQL 판정(처리함)이 일치하는지 확인하며 처리 가능한 사용자 목록을 돌려준다. */
function bo_inbox_users(array $w): array
{
    $req = rrow($w['rid']);
    $svc = WF::service($req);
    $out = [];
    foreach (['bizit', 'bizit2', 'othbiz', 'req', 'owner'] as $k) {
        if ((int)Repo::find('user', ['user_id' => $w[$k]])['use_yn'] !== 1) {
            continue; // 비활성 사용자는 로그인할 수 없으므로 처리함 판정 대상이 아니다
        }
        $php = RS::canAct($req, $svc, $w[$k]);
        $sql = in_array($w['rid'], sp_inbox($w[$k]), true);
        eq($php, $sql, "PHP/SQL 판정 일치: $k");
        if ($php) {
            $out[] = $k;
        }
    }
    return $out;
}

test('bizowner: 시드된 일반/긴급 프로세스는 현업 IT 담당자 유형을 사용', function () {
    fresh();
    seed_two_companies();
    foreach (['A01', 'B01'] as $cd) {
        Scope::set($cd);
        foreach (['GENERAL', 'URGENT'] as $code) {
            $types = array_column(PS::steps((int)PS::resolveVersion(pid_of($code))['version_id']), 'actor_type');
            ok(in_array('SERVICE_BIZ_OWNER', $types, true), "$cd $code 현업 IT 담당자 단계");
            ok(!in_array('SERVICE_BIZ_DEPT', $types, true), "$cd $code 주관부서 전원 유형 없음");
        }
    }
});

test('bizowner: 지정자가 있으면 지정자만, 없으면 주관부서 현업 전산담당 전원', function () {
    $w = bo_world();
    eq([$w['bizit'], $w['bizit2']], bo_actors($w), '미지정: 주관부서 전원');
    eq(['bizit', 'bizit2'], bo_inbox_users($w));

    bo_designate($w, $w['bizit2']);
    eq([$w['bizit2']], bo_actors($w), '지정자만');
    eq(['bizit2'], bo_inbox_users($w), '처리함도 지정자만');
    perr_p(fn() => WF::act($w['rid'], $w['bizit'], 'PASS', ''), 'request.err_not_actor');
    ok(can_view($w['rid'], $w['bizit']), '지정자가 아니어도 같은 부서라 조회는 가능');

    bo_designate($w, $w['bizit'], $w['bizit2']);
    eq(['bizit', 'bizit2'], bo_inbox_users($w), '복수 지정');

    bo_designate($w, $w['bizit2']);
    WF::act($w['rid'], $w['bizit2'], 'PASS', '');
    eq('IN_PROGRESS', (string)rrow($w['rid'])['status']);
    eq(4, (int)rrow($w['rid'])['current_step_no']);
});

test('bizowner: 지정자가 자격을 잃으면 주관부서 전원으로 폴백(비활성/롤 제거/타 부서)', function () {
    $w = bo_world();

    bo_designate($w, $w['bizit2']);
    Repo::update('user', ['user_id' => $w['bizit2']], ['use_yn' => 0]);
    eq([$w['bizit']], bo_actors($w), '비활성 지정자 -> 폴백(활성 BIZ_IT 만)');
    eq(['bizit'], bo_inbox_users($w));
    Repo::update('user', ['user_id' => $w['bizit2']], ['use_yn' => 1]);
    eq(['bizit2'], bo_inbox_users($w), '다시 활성화하면 지정자만');

    Repo::delete('user_role', ['user_id' => $w['bizit2'], 'role_cd' => 'BIZ_IT']);
    eq(['bizit'], bo_inbox_users($w), 'BIZ_IT 롤을 잃으면 폴백');
    Repo::insert('user_role', ['user_id' => $w['bizit2'], 'role_cd' => 'BIZ_IT']);

    bo_designate($w, $w['othbiz']);
    eq(['bizit', 'bizit2'], bo_inbox_users($w), '다른 부서 지정자는 무효 -> 폴백');

    Repo::update('user', ['user_id' => $w['bizit2']], ['dept_id' => $w['oth']]);
    bo_designate($w, $w['bizit2']);
    eq(['bizit'], bo_inbox_users($w), '부서 이동한 지정자는 무효 -> 폴백');
});

test('bizowner: 주관부서가 없으면 처리자가 없어 제출할 수 없다', function () {
    $w = rw(false);
    Repo::update('service', ['service_id' => $w['svc']], ['biz_dept_id' => null]);
    $rid = mk_req($w);
    perr_p(fn() => WF::submit($rid, $w['req']), 'request.err_no_actor');
    $req = rrow($rid);
    eq([], ActorResolver::userIds(PS::steps((int)$req['version_id'])[2], $req, WF::service($req)));
});

test('bizowner: 참조자 유형도 같은 규칙(PHP/SQL)', function () {
    $w = bo_world();
    $req = rrow($w['rid']);
    $first = (int)PS::steps((int)$req['version_id'])[0]['step_id'];
    Repo::insert('process_step_cc', ['step_id' => $first, 'cc_type' => 'SERVICE_BIZ_OWNER', 'cc_value' => null, 'notify_on' => 'NONE']);
    $cc = Repo::find('process_step_cc', ['step_id' => $first, 'cc_type' => 'SERVICE_BIZ_OWNER']);
    $svc = WF::service($req);

    $ids = ActorResolver::ccUserIds($cc, $req, $svc);
    sort($ids);
    eq([$w['bizit'], $w['bizit2']], $ids, '미지정: 주관부서 전원');
    eq(1, list_of($w['bizit'], 'cc')['total']);
    eq(1, list_of($w['bizit2'], 'cc')['total']);
    eq(0, list_of($w['othbiz'], 'cc')['total'], '다른 부서는 제외');

    bo_designate($w, $w['bizit2']);
    eq([$w['bizit2']], ActorResolver::ccUserIds($cc, $req, $svc));
    eq(0, list_of($w['bizit'], 'cc')['total']);
    eq(1, list_of($w['bizit2'], 'cc')['total']);
});

test('bizowner: 기존 회사 보강은 기본 프로세스의 주관부서 단계만 바꾸고 멱등', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    // 이전 버전 DB 상태를 재현: 기본 프로세스는 주관부서 전원 유형, 관리자가 만든 프로세스도 같은 유형
    Db::exec("UPDATE process_step SET actor_type = 'SERVICE_BIZ_DEPT' WHERE company_cd = 'A01' AND actor_type = 'SERVICE_BIZ_OWNER'");
    [$cpid, $cvid] = make_active('CUSTOM');
    $custom = PS::insertStepRaw($cvid, 99, 'x', 'APPROVE', 'SERVICE_BIZ_DEPT', null, 'ANY', 1, null, null, 'NONE');
    Repo::delete('app_setting', ['setting_key' => 'seed.biz_owner_actor']);

    $count = static fn(string $t, string $cd = 'A01'): int => (int)Db::val(
        "SELECT COUNT(*) FROM process_step s WHERE s.company_cd = :c AND s.actor_type = :t AND s.version_id IN (
           SELECT v.version_id FROM process_version v JOIN process_master m ON m.company_cd = v.company_cd AND m.process_id = v.process_id
            WHERE v.company_cd = :c AND m.process_cd IN ('GENERAL', 'URGENT'))",
        ['c' => $cd, 't' => $t]
    );
    eq(2, $count('SERVICE_BIZ_DEPT'));

    Seeder::ensureDefaults('A01');
    eq(0, $count('SERVICE_BIZ_DEPT'));
    eq(2, $count('SERVICE_BIZ_OWNER'));
    eq('SERVICE_BIZ_DEPT', Repo::find('process_step', ['step_id' => $custom])['actor_type'], '사용자 정의 프로세스는 그대로');
    ok(Repo::find('app_setting', ['setting_key' => 'seed.biz_owner_actor']) !== null, '플래그 기록');

    // 이후 관리자가 직접 바꾼 단계는 다시 덮어쓰지 않는다
    Db::exec("UPDATE process_step SET actor_type = 'SERVICE_BIZ_DEPT' WHERE company_cd = 'A01' AND actor_type = 'SERVICE_BIZ_OWNER'");
    Seeder::ensureDefaults('A01');
    eq(2, $count('SERVICE_BIZ_DEPT'), '멱등: 플래그가 있으면 재실행하지 않음');

    eq(2, $count('SERVICE_BIZ_OWNER', 'B01'), '다른 회사는 영향 없음');
});

/** 서비스 관리 화면 테스트용 부서/사용자. */
function bo_admin_world(): array
{
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $biz = Repo::insert('dept', ['dept_nm' => 'Biz', 'is_it' => 0, 'use_yn' => 1]);
    $oth = Repo::insert('dept', ['dept_nm' => 'Other', 'is_it' => 0, 'use_yn' => 1]);
    $mk = static fn(string $id, int $dept, array $roles): int => Users::create($id, ucfirst($id), "$id@example.com", $dept, 'ko', PW, $roles);
    $w = [
        'biz' => $biz, 'oth' => $oth,
        'b1' => $mk('b1', $biz, ['BIZ_IT']),
        'b2' => $mk('b2', $biz, ['BIZ_IT']),
        'plain' => $mk('plain', $biz, ['REQUESTER']),
        'ob' => $mk('ob', $oth, ['BIZ_IT']),
    ];
    Scope::set(null);
    as_admin();
    return $w;
}

function bo_post(array $w, array $o = []): array
{
    return $o + ['service_cd' => 'NEW1', 'service_nm' => '신규', 'biz_dept_id' => (string)$w['biz'], 'biz_it_ids' => [(string)$w['b1']], 'use_yn' => '1'];
}

/** @return list<int> */
function bo_rows(string $cd = 'NEW1'): array
{
    Scope::set('A01');
    $svc = Repo::find('service', ['service_cd' => $cd]);
    $ids = $svc === null ? [] : ActorResolver::designatedBizOwners((int)$svc['service_id']);
    Scope::set(null);
    sort($ids);
    return $ids;
}

test('service 화면: 현업 IT 담당자 지정 저장과 검증', function () {
    $w = bo_admin_world();
    eq(200, http('GET', '/admin/services/new')->status);
    $form = http('GET', '/admin/services/new')->body;
    ok(str_contains($form, 'name="biz_it_ids[]"') && str_contains($form, 'value="' . $w['b1'] . '"'), '후보 표시');
    ok(!str_contains($form, 'value="' . $w['plain'] . '" id="bz'), '현업 전산담당 롤이 아닌 사용자는 후보가 아님');

    // 검증: 타 부서/롤 없음/주관부서 없음
    foreach ([[(string)$w['ob']], [(string)$w['plain']], ['99999']] as $ids) {
        ok(body_has(http('POST', '/admin/services', bo_post($w, ['biz_it_ids' => $ids])), 'service.err_biz_it'), 'invalid ' . $ids[0]);
    }
    ok(body_has(http('POST', '/admin/services', bo_post($w, ['biz_dept_id' => ''])), 'service.err_biz_it'), '주관부서 없이 지정');
    eq([], bo_rows(), '실패 시 저장 없음');
    Scope::set('A01');
    eq(null, Repo::find('service', ['service_cd' => 'NEW1']), '서비스도 저장되지 않음');
    Scope::set(null);

    // 정상 저장(중복 제거)
    eq(302, http('POST', '/admin/services', bo_post($w, ['biz_it_ids' => [(string)$w['b1'], (string)$w['b2'], (string)$w['b1']]]))->status);
    eq([$w['b1'], $w['b2']], bo_rows());

    Scope::set('A01');
    $sid = (int)Repo::find('service', ['service_cd' => 'NEW1'])['service_id'];
    Scope::set(null);
    $edit = http('GET', "/admin/services/$sid/edit")->body;
    ok(preg_match('/value="' . $w['b1'] . '" id="bz' . $w['b1'] . '" checked/', $edit) === 1, '수정 화면에 지정자 선택 표시');

    // 수정: 지정자 변경, 부서 변경 시 불일치 거부, 해제
    eq(302, http('POST', "/admin/services/$sid", bo_post($w, ['biz_it_ids' => [(string)$w['b2']]]))->status);
    eq([$w['b2']], bo_rows());
    ok(body_has(http('POST', "/admin/services/$sid", bo_post($w, ['biz_dept_id' => (string)$w['oth']])), 'service.err_biz_it'), '부서를 바꾸면서 기존 지정자 유지');
    eq([$w['b2']], bo_rows(), '실패 시 기존 지정 유지');
    eq(302, http('POST', "/admin/services/$sid", bo_post($w, ['biz_dept_id' => (string)$w['oth'], 'biz_it_ids' => [(string)$w['ob']]]))->status);
    eq([$w['ob']], bo_rows(), '부서와 지정자 함께 변경');
    eq(302, http('POST', "/admin/services/$sid", bo_post($w, ['biz_it_ids' => []]))->status);
    eq([], bo_rows(), '지정 해제');
});

test('service 목록: 지정자 표시와 미지정/확인 필요 경고', function () {
    $w = bo_admin_world();
    Scope::set('A01');
    $mk = static fn(string $cd, ?int $dept): int => Repo::insert('service', ['service_cd' => $cd, 'service_nm' => $cd, 'biz_dept_id' => $dept, 'use_yn' => 1]);
    $ok = $mk('S_OK', $w['biz']);
    $none = $mk('S_NONE', $w['biz']);
    $bad = $mk('S_BAD', $w['biz']);
    $nodept = $mk('S_NODEPT', null);
    Repo::insert('service_biz_it', ['service_id' => $ok, 'user_id' => $w['b1']]);
    Repo::insert('service_biz_it', ['service_id' => $bad, 'user_id' => $w['b2']]);
    Repo::update('user', ['user_id' => $w['b2']], ['use_yn' => 0]);
    Scope::set(null);

    $body = http('GET', '/admin/services')->body;
    $row = static function (string $cd) use ($body): string {
        preg_match('#<tr[^>]*>\s*<td><code>' . $cd . '</code></td>.*?</tr>#s', $body, $m);
        return $m[0] ?? '';
    };
    ok(str_contains($row('S_OK'), 'B1') && !str_contains($row('S_OK'), e(t('service.biz_it_check'))), '정상 지정');
    ok(str_contains($row('S_NONE'), e(t('service.biz_it_none'))), '미지정 표시');
    ok(str_contains($row('S_BAD'), e(t('service.biz_it_check'))), '지정자 자격 상실 경고');
    ok(!str_contains($row('S_NODEPT'), e(t('service.biz_it_none'))), '주관부서가 없으면 표시 없음');
});

test('doctor: 유효한 현업 IT 담당자가 없는 서비스를 경고', function () {
    fresh();
    $dir = tmp_dir();
    Db::reset();
    Env::set('DB_PATH', $dir . '/d.sqlite');
    Env::set('STORAGE_PATH', $dir . '/store');
    try {
        App\Core\Migrator::run();
        App\Services\CompanySetup::provision('HQ', 'Head', 'ko', 'admin', 'Admin', 'a@example.com', 'Admin1234');
        $name = '현업 IT 담당자';
        eq('ok', array_column(Doctor::checks(), null, 'name')[$name]['level'], '서비스가 없으면 정상');

        Scope::set('HQ');
        $d = Repo::insert('dept', ['dept_nm' => 'Biz', 'is_it' => 0, 'use_yn' => 1]);
        $u = Users::create('biz1', 'Biz1', 'biz1@example.com', $d, 'ko', PW, ['BIZ_IT']);
        $s = Repo::insert('service', ['service_cd' => 'ERP', 'service_nm' => 'ERP', 'biz_dept_id' => $d, 'use_yn' => 1]);
        Repo::insert('service', ['service_cd' => 'NODEPT', 'service_nm' => 'x', 'use_yn' => 1]);
        Scope::set(null);
        $c = array_column(Doctor::checks(), null, 'name')[$name];
        eq('warn', $c['level']);
        ok(str_contains($c['detail'], 'HQ/ERP') && !str_contains($c['detail'], 'NODEPT'), $c['detail']);

        Scope::set('HQ');
        Repo::insert('service_biz_it', ['service_id' => $s, 'user_id' => $u]);
        Scope::set(null);
        eq('ok', array_column(Doctor::checks(), null, 'name')[$name]['level'], '지정하면 정상');
    } finally {
        Db::reset();
        Env::set('DB_PATH', ':memory:');
        Env::set('STORAGE_PATH', 'storage');
        fresh();
        rm_rf($dir);
    }
});
