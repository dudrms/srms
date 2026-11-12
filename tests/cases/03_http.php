<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Repo;
use App\Core\Scope;
use App\Services\Seeder;

function as_admin(string $company = 'A01', string $pw = 'Admin1234'): void
{
    $_SESSION = [];
    login($company, 'admin', $pw);
}

test('http: 비로그인은 로그인으로 이동하고 next 저장', function () {
    fresh();
    seed_two_companies();
    $r = http('GET', '/admin/users');
    eq(302, $r->status);
    eq('/login', $r->headers['Location']);
    eq('/admin/users', $_SESSION['next']);
});

test('http: 로그인 화면은 200, 로그인 후 next 로 이동(오픈 리다이렉트 차단)', function () {
    fresh();
    seed_two_companies();
    eq(200, http('GET', '/login')->status);
    $_SESSION['next'] = '/admin/depts';
    eq('/admin/depts', login('A01', 'admin', 'Admin1234')->headers['Location']);
    $_SESSION = [];
    $_SESSION['next'] = '//evil.example.com';
    eq('/', login('A01', 'admin', 'Admin1234')->headers['Location']);
});

test('http: CSRF 토큰 없는/틀린 POST 는 403', function () {
    fresh();
    seed_two_companies();
    as_admin();
    eq(403, http('POST', '/admin/depts', ['dept_nm' => 'X'], false)->status);
    eq(403, http('POST', '/admin/depts', ['dept_nm' => 'X', '_csrf' => 'wrong'], false)->status);
    Scope::set('A01');
    eq(0, Repo::count('dept', ['dept_nm' => 'X']));
    eq(403, http('POST', '/login', ['company_cd' => 'A01', 'login_id' => 'admin', 'password' => 'Admin1234'], false)->status);
});

test('http: 관리자 화면 전체 200', function () {
    fresh();
    seed_two_companies();
    as_admin();
    foreach (['/', '/profile', '/admin/users', '/admin/users/new', '/admin/depts', '/admin/roles', '/admin/codes',
        '/admin/codes/MAINT_TYPE', '/admin/services', '/admin/services/new'] as $p) {
        $r = http('GET', $p);
        eq(200, $r->status, $p);
        ok(str_contains($r->body, '<html'), "$p renders layout");
    }
});

test('http: 일반 사용자는 관리 화면 403 / 홈은 200', function () {
    fresh();
    seed_two_companies();
    login('A01', 'user1', 'User1234');
    eq(200, http('GET', '/')->status);
    foreach (['/admin/users', '/admin/depts', '/admin/roles', '/admin/codes', '/admin/services'] as $p) {
        eq(403, http('GET', $p)->status, $p);
    }
    eq(403, http('POST', '/admin/depts', ['dept_nm' => 'X'])->status);
    ok(!str_contains(http('GET', '/')->body, '/admin/users'), '메뉴에 관리 링크 없음');
});

test('http: 없는 경로 404, 메서드 불일치 405', function () {
    fresh();
    seed_two_companies();
    as_admin();
    eq(404, http('GET', '/nope')->status);
    eq(404, http('POST', '/profile/nope')->status);
    eq(405, http('GET', '/logout')->status);
});

test('http: 사용자 등록/목록/수정/비밀번호 재설정', function () {
    fresh();
    seed_two_companies();
    as_admin();
    $r = http('POST', '/admin/users', [
        'login_id' => 'newbie', 'user_nm' => 'Newbie', 'email' => 'n@example.com', 'dept_id' => '', 'lang' => 'en',
        'password' => 'Newbie123', 'roles' => ['REQUESTER', 'BIZ_IT', 'NOT_A_ROLE'],
    ]);
    eq('/admin/users', $r->headers['Location']);
    Scope::set('A01');
    $u = Repo::find('user', ['login_id' => 'newbie']);
    ok($u !== null);
    eq(['BIZ_IT', 'REQUESTER'], array_column(Repo::list('user_role', ['user_id' => $u['user_id']], 'role_cd'), 'role_cd'));
    ok(str_contains(http('GET', '/admin/users')->body, 'newbie'));

    // 중복/검증 오류
    $r = http('POST', '/admin/users', ['login_id' => 'newbie', 'user_nm' => 'X', 'email' => 'n2@example.com', 'lang' => 'ko', 'password' => 'Newbie123']);
    eq(200, $r->status);
    ok(body_has($r, 'user.err_duplicate'));
    ok(body_has(http('POST', '/admin/users', ['login_id' => 'x y', 'user_nm' => 'X', 'email' => 'n2@example.com', 'lang' => 'ko', 'password' => 'Newbie123']), 'user.err_login_id'));
    ok(body_has(http('POST', '/admin/users', ['login_id' => 'weakpw', 'user_nm' => 'X', 'email' => 'n2@example.com', 'lang' => 'ko', 'password' => 'short']), 'pw.too_short'));
    eq(null, Repo::find('user', ['login_id' => 'weakpw']));

    // 수정
    $id = (int)$u['user_id'];
    http('POST', "/admin/users/$id", ['user_nm' => 'Renamed', 'email' => 'r@example.com', 'lang' => 'ja', 'use_yn' => '1', 'roles' => ['IT_TEAM']]);
    $u = Repo::find('user', ['user_id' => $id]);
    eq('Renamed', $u['user_nm']);
    eq('ja', $u['lang']);
    eq('newbie', $u['login_id'], 'login_id 변경 불가');
    eq(['IT_TEAM'], array_column(Repo::list('user_role', ['user_id' => $id]), 'role_cd'));

    // 비밀번호 재설정 후 로그인
    http('POST', "/admin/users/$id/password", ['password' => 'Reset12345']);
    eq(t('user.pw_reset'), last_flash());
    $_SESSION = [];
    login('A01', 'newbie', 'Reset12345');
    eq('newbie', Auth::user()['login_id']);
});

test('http: 회사 격리 - 타 회사 사용자 조회/수정/비번재설정 모두 404', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $idA = (int)Repo::find('user', ['login_id' => 'user1'])['user_id'];
    as_admin('B01', 'Admin5678');
    eq(404, http('GET', "/admin/users/$idA/edit")->status);
    eq(404, http('POST', "/admin/users/$idA", ['user_nm' => 'Hacked', 'email' => 'h@example.com', 'lang' => 'ko', 'use_yn' => '1'])->status);
    eq(404, http('POST', "/admin/users/$idA/password", ['password' => 'Hacked12345'])->status);
    Scope::set('A01');
    eq('UserA', Repo::find('user', ['user_id' => $idA])['user_nm']);
    // 목록에 타 회사 사용자 없음
    $body = http('GET', '/admin/users')->body;
    ok(str_contains($body, 'UserB') && !str_contains($body, 'UserA'));
});

test('http: 회사 격리 - 타 회사 부서/서비스/코드/롤 접근 불가', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $deptA = Repo::insert('dept', ['dept_nm' => 'DeptA', 'is_it' => 0, 'use_yn' => 1]);
    $svcA = Repo::insert('service', ['service_cd' => 'SVC_A', 'service_nm' => 'SvcA', 'use_yn' => 1]);
    as_admin('B01', 'Admin5678');
    eq(404, http('POST', "/admin/depts/$deptA", ['dept_nm' => 'Hacked'])->status);
    eq(404, http('POST', "/admin/depts/$deptA/delete")->status);
    eq(404, http('GET', "/admin/services/$svcA/edit")->status);
    Scope::set('A01');
    eq('DeptA', Repo::find('dept', ['dept_id' => $deptA])['dept_nm']);
    // B 에서 A 서비스 코드와 같은 코드 등록은 허용(회사별 유일)
    http('POST', '/admin/services', ['service_cd' => 'SVC_A', 'service_nm' => 'SvcB']);
    Scope::set('B01');
    eq('SvcB', Repo::find('service', ['service_cd' => 'SVC_A'])['service_nm']);
    // 같은 회사 내 중복은 거부
    ok(body_has(http('POST', '/admin/services', ['service_cd' => 'SVC_A', 'service_nm' => 'Dup']), 'service.err_duplicate'));
    Scope::set('B01');
    eq(1, Repo::count('service'));
});

test('http: XSS - 입력값은 이스케이프되어 출력', function () {
    fresh();
    seed_two_companies();
    as_admin();
    http('POST', '/admin/depts', ['dept_nm' => '<script>alert(1)</script>']);
    $body = http('GET', '/admin/depts')->body;
    ok(!str_contains($body, '<script>alert(1)</script>'), 'raw script tag');
    ok(str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'escaped');
});

test('http: 부서 삭제 - 사용 중이면 거부, 미사용이면 삭제', function () {
    fresh();
    seed_two_companies();
    as_admin();
    Scope::set('A01');
    $it = (int)Repo::find('dept', ['is_it' => 1])['dept_id'];
    $adminUid = (int)Repo::find('user', ['login_id' => 'admin'])['user_id'];
    eq($it, (int)Repo::find('user', ['user_id' => $adminUid])['dept_id']);
    http('POST', "/admin/depts/$it/delete");
    eq(t('dept.in_use'), last_flash());
    ok(Repo::find('dept', ['dept_id' => $it]) !== null);
    http('POST', '/admin/depts', ['dept_nm' => 'Temp']);
    $tmp = (int)Repo::find('dept', ['dept_nm' => 'Temp'])['dept_id'];
    http('POST', "/admin/depts/$tmp/delete");
    eq(null, Repo::find('dept', ['dept_id' => $tmp]));
});

test('http: 마지막/자기 자신 시스템관리자 해제·비활성화 방지', function () {
    fresh();
    seed_two_companies();
    as_admin();
    Scope::set('A01');
    $me = (int)Repo::find('user', ['login_id' => 'admin'])['user_id'];
    $r1 = http('POST', "/admin/users/$me", ['user_nm' => 'AdminA', 'email' => 'a@example.com', 'lang' => 'ko', 'roles' => ['SYS_ADMIN']]);
    ok(body_has($r1, 'user.err_self'), '비활성화(use_yn 미전송) 차단');
    Scope::set('A01');
    eq(1, (int)Repo::find('user', ['user_id' => $me])['use_yn']);
    ok(body_has(http('POST', "/admin/users/$me", ['user_nm' => 'AdminA', 'email' => 'a@example.com', 'lang' => 'ko', 'use_yn' => '1', 'roles' => ['REQUESTER']]), 'user.err_self'));
    Scope::set('A01');
    ok(Repo::find('user_role', ['user_id' => $me, 'role_cd' => 'SYS_ADMIN']) !== null);
    // 다른 관리자를 추가하면 다른 관리자 해제는 가능
    $other = App\Services\Users::create('admin2', 'Admin2', 'a2@example.com', null, 'ko', 'Admin2pass1', ['SYS_ADMIN']);
    http('POST', "/admin/users/$other", ['user_nm' => 'Admin2', 'email' => 'a2@example.com', 'lang' => 'ko', 'use_yn' => '1', 'roles' => ['REQUESTER']]);
    eq(t('user.updated'), last_flash());
});

test('http: 롤 권한 저장 - SYS_ADMIN 은 고정, 나머지는 반영', function () {
    fresh();
    seed_two_companies();
    as_admin();
    http('POST', '/admin/roles/save', [
        'name' => ['REQUESTER' => ['ko' => '요청자', 'ja' => '依頼者', 'en' => 'Requester'], 'SYS_ADMIN' => ['ko' => '관리자']],
        'perm' => ['REQUESTER' => ['REQUEST_CREATE' => '1', 'REPORT_VIEW' => '1', 'HACK_PERM' => '1'], 'SYS_ADMIN' => []],
    ]);
    Scope::set('A01');
    eq(['REPORT_VIEW', 'REQUEST_CREATE'], array_column(Repo::list('role_permission', ['role_cd' => 'REQUESTER'], 'perm_cd'), 'perm_cd'));
    eq(count(Seeder::PERMISSIONS), Repo::count('role_permission', ['role_cd' => 'SYS_ADMIN']));
    eq('요청자', Repo::find('role', ['role_cd' => 'REQUESTER'])['role_nm']);
    // B 회사 권한은 변하지 않음
    Scope::set('B01');
    ok(Repo::count('role_permission', ['role_cd' => 'REQUESTER']) === 5);
    // 권한 변경이 일반 사용자에 즉시 반영
    login('A01', 'user1', 'User1234');
    ok(Auth::can('REPORT_VIEW') && !Auth::can('STEP_ACT'));
});

test('http: 롤 추가/삭제 - 기본 롤·사용 중 롤은 삭제 불가', function () {
    fresh();
    seed_two_companies();
    as_admin();
    http('POST', '/admin/roles', ['role_cd' => 'auditor', 'role_nm' => 'Auditor']);
    Scope::set('A01');
    ok(Repo::find('role', ['role_cd' => 'AUDITOR']) !== null);
    http('POST', '/admin/roles', ['role_cd' => 'AUDITOR', 'role_nm' => 'Dup']);
    eq(t('role.err_duplicate'), last_flash());
    http('POST', '/admin/roles/REQUESTER/delete');
    eq(t('role.in_use'), last_flash());
    http('POST', '/admin/roles/AUDITOR/delete');
    eq(null, Repo::find('role', ['role_cd' => 'AUDITOR']));
});

test('http: 공통코드 - 그룹/항목 등록·수정, 번역 반영', function () {
    fresh();
    seed_two_companies();
    as_admin();
    http('POST', '/admin/codes/MAINT_TYPE/items', ['code_cd' => 'AUDIT', 'code_nm' => '감사대응', 'name_ja' => '監査対応', 'name_en' => 'Audit', 'sort_no' => '9']);
    Scope::set('A01');
    eq('감사대응', Repo::find('code', ['group_cd' => 'MAINT_TYPE', 'code_cd' => 'AUDIT'])['code_nm']);
    http('POST', '/admin/codes/MAINT_TYPE/items', ['code_cd' => 'AUDIT', 'code_nm' => 'dup']);
    eq(t('code.err_duplicate'), last_flash());
    http('POST', '/admin/codes/MAINT_TYPE/items/AUDIT', ['code_nm' => '감사', 'name_ja' => '監査', 'name_en' => 'Audit2', 'name_zh' => '审计', 'sort_no' => '1']);
    eq('审计', \App\Services\Translations::get('CODE', 'MAINT_TYPE:AUDIT')['zh'] ?? null, '중국어 번역 저장');
    $it = Repo::find('code', ['group_cd' => 'MAINT_TYPE', 'code_cd' => 'AUDIT']);
    eq('감사', $it['code_nm']);
    eq(0, (int)$it['use_yn'], '체크박스 미전송 = 미사용');
    http('POST', '/admin/codes', ['group_cd' => 'my_group', 'group_nm' => '내 그룹']);
    ok(Repo::find('code_group', ['group_cd' => 'MY_GROUP']) !== null);
    eq(404, http('GET', '/admin/codes/NOPE')->status);
    // B 회사에는 영향 없음
    Scope::set('B01');
    eq(null, Repo::find('code', ['group_cd' => 'MAINT_TYPE', 'code_cd' => 'AUDIT']));
});

test('http: 서비스 등록·수정, 번역/참조 검증', function () {
    fresh();
    seed_two_companies();
    as_admin();
    ok(body_has(http('POST', '/admin/services', ['service_cd' => 'ERP', 'service_nm' => '전사 ERP', 'name_ja' => '全社ERP', 'name_en' => 'Corporate ERP', 'biz_dept_id' => '99999']), 'service.err_ref'));
    http('POST', '/admin/services', ['service_cd' => 'ERP', 'service_nm' => '전사 ERP', 'name_ja' => '全社ERP', 'name_en' => 'Corporate ERP']);
    Scope::set('A01');
    $s = Repo::find('service', ['service_cd' => 'ERP']);
    ok($s !== null);
    ok(body_has(http('POST', '/admin/services', ['service_cd' => 'a', 'service_nm' => 'x']), 'service.err_code'));
    Scope::set('A01');
    http('POST', '/admin/services/' . $s['service_id'], ['service_nm' => '전사 ERP v2', 'name_ja' => '全社ERP2', 'name_en' => 'ERP2', 'use_yn' => '1']);
    eq('전사 ERP v2', Repo::find('service', ['service_id' => $s['service_id']])['service_nm']);
    // 일본어 화면에서 번역 표시
    http('POST', '/lang', ['lang' => 'ja', 'back' => '/admin/services']);
    Scope::set('A01');
    ok(str_contains(http('GET', '/admin/services')->body, '全社ERP2'));
});

test('http: 언어 전환 - 사용자 설정 저장, 오픈 리다이렉트 차단, 비활성 언어 무시', function () {
    fresh();
    seed_two_companies();
    as_admin();
    $r = http('POST', '/lang', ['lang' => 'en', 'back' => '//evil.example.com']);
    eq('/', $r->headers['Location']);
    eq('en', $r->cookies()[0]['value']);
    Scope::set('A01');
    eq('en', Repo::find('user', ['login_id' => 'admin'])['lang']);
    ok(str_contains(http('GET', '/')->body, 'Welcome'));
    $r = http('POST', '/lang', ['lang' => 'xx', 'back' => '/profile']);
    eq([], $r->cookies());
    Scope::set('A01');
    eq('en', Repo::find('user', ['login_id' => 'admin'])['lang']);
    \App\Core\Env::set('ENABLED_LANGS', 'ko,en');
    http('POST', '/lang', ['lang' => 'ja', 'back' => '/']);
    Scope::set('A01');
    eq('en', Repo::find('user', ['login_id' => 'admin'])['lang'], '비활성 언어');
    \App\Core\Env::set('ENABLED_LANGS', 'ko,ja,en,zh');
});

test('http: 내 정보 수정 및 비밀번호 변경', function () {
    fresh();
    seed_two_companies();
    login('A01', 'user1', 'User1234');
    http('POST', '/profile', ['user_nm' => 'Me2', 'email' => 'me2@example.com', 'lang' => 'ko']);
    Scope::set('A01');
    eq('Me2', Repo::find('user', ['login_id' => 'user1'])['user_nm']);
    http('POST', '/profile/password', ['current_password' => 'wrong', 'new_password' => 'NewPass123', 'new_password2' => 'NewPass123']);
    eq(t('profile.pw_wrong'), last_flash());
    http('POST', '/profile/password', ['current_password' => 'User1234', 'new_password' => 'NewPass123', 'new_password2' => 'Different123']);
    eq(t('profile.pw_mismatch'), last_flash());
    http('POST', '/profile/password', ['current_password' => 'User1234', 'new_password' => 'NewPass123', 'new_password2' => 'NewPass123']);
    eq(t('profile.pw_changed'), last_flash());
    $_SESSION = [];
    login('A01', 'user1', 'NewPass123');
    ok(isset($_SESSION['uid']));
});

test('http: 감사로그 기록(로그인 실패/성공/관리 작업)', function () {
    fresh();
    seed_two_companies();
    login('A01', 'admin', 'bad');
    login('A01', 'admin', 'Admin1234');
    http('POST', '/admin/depts', ['dept_nm' => 'AuditMe']);
    Scope::set('A01');
    $actions = array_column(Repo::list('audit_log', [], 'audit_id'), 'action');
    ok(in_array('LOGIN_FAIL', $actions, true) && in_array('LOGIN', $actions, true) && in_array('DEPT_CREATE', $actions, true), implode(',', $actions));
    Scope::set('B01');
    eq(0, Repo::count('audit_log'), '타 회사 감사로그 없음');
});

test('http: 보안 헤더', function () {
    fresh();
    seed_two_companies();
    $src = (string)file_get_contents(APP_ROOT . '/app/Core/Response.php');
    foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Content-Security-Policy', 'Referrer-Policy'] as $h) {
        ok(str_contains($src, $h), $h);
    }
});
