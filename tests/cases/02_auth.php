<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Env;
use App\Core\Repo;
use App\Core\Scope;
use App\Core\Session;

test('auth: 로그인 성공 시 세션/회사 고정', function () {
    fresh();
    seed_two_companies();
    $r = login('A01', 'admin', 'Admin1234');
    eq(302, $r->status);
    eq('/', $r->headers['Location']);
    eq('A01', $_SESSION['cd']);
    eq('admin', Auth::user()['login_id']);
    eq('A01', Scope::cd());
    ok(Auth::can('USER_EDIT'));
    ok(Auth::hasRole('SYS_ADMIN'));
});

test('auth: 비밀번호/회사/ID 오류는 동일한 메시지', function () {
    fresh();
    seed_two_companies();
    foreach ([['A01', 'admin', 'wrong1234'], ['B01', 'admin', 'Admin1234'], ['ZZZ', 'admin', 'Admin1234'], ['A01', 'nobody', 'Admin1234']] as [$c, $i, $p]) {
        $r = login($c, $i, $p);
        eq('/login', $r->headers['Location']);
        eq(t('auth.invalid'), last_flash(), "$c/$i");
        ok(!isset($_SESSION['uid']), 'no session');
        $_SESSION['_flash'] = [];
    }
});

test('auth: 같은 login_id 가 회사별로 독립', function () {
    fresh();
    seed_two_companies();
    eq(302, login('A01', 'user1', 'User1234')->status);
    eq('UserA', Auth::user()['user_nm']);
    Auth::logout();
    login('A01', 'user1', 'User5678');
    ok(!isset($_SESSION['uid']), 'B 비밀번호로 A 로그인 불가');
    login('B01', 'user1', 'User5678');
    eq('UserB', Auth::user()['user_nm']);
    eq('B01', Scope::cd());
});

test('auth: 5회 실패 시 잠금(올바른 비밀번호도 거부), 성공하면 카운터 초기화', function () {
    fresh();
    seed_two_companies();
    for ($i = 0; $i < Auth::MAX_FAILS; $i++) {
        login('A01', 'user1', 'bad');
    }
    $_SESSION['_flash'] = [];
    login('A01', 'user1', 'User1234');
    eq(t('auth.locked'), last_flash());
    ok(!isset($_SESSION['uid']));
    // 다른 회사의 같은 ID 는 영향 없음
    eq(302, login('B01', 'user1', 'User5678')->status);
    ok(isset($_SESSION['uid']));
});

test('auth: 비활성 사용자/비활성 회사 로그인 불가', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    Repo::update('user', ['login_id' => 'user1'], ['use_yn' => 0]);
    login('A01', 'user1', 'User1234');
    ok(!isset($_SESSION['uid']), '비활성 사용자');
    App\Core\Db::exec("UPDATE company SET use_yn = 0 WHERE company_cd = 'B01'");
    login('B01', 'admin', 'Admin5678');
    ok(!isset($_SESSION['uid']), '비활성 회사');
});

test('auth: 로그인 후 사용자가 비활성화되면 다음 요청에서 차단', function () {
    fresh();
    seed_two_companies();
    login('A01', 'user1', 'User1234');
    Scope::set('A01');
    Repo::update('user', ['login_id' => 'user1'], ['use_yn' => 0]);
    $r = http('GET', '/');
    eq('/login', $r->headers['Location']);
});

test('auth: 유휴 세션 만료', function () {
    fresh();
    seed_two_companies();
    login('A01', 'admin', 'Admin1234');
    $_SESSION['last_seen'] = time() - 3600;
    $r = http('GET', '/');
    eq('/login', $r->headers['Location']);
    ok(!isset($_SESSION['uid']));
});

test('auth: AUTH_MODE=SSO 이면 로컬 로그인 거부', function () {
    fresh();
    seed_two_companies();
    Env::set('AUTH_MODE', 'SSO');
    login('A01', 'admin', 'Admin1234');
    ok(!isset($_SESSION['uid']));
    eq(t('auth.local_disabled'), last_flash());
});

test('auth: 비밀번호 정책', function () {
    eq('pw.too_short', Auth::passwordError('Ab1', 'x'));
    eq('pw.need_mix', Auth::passwordError('abcdefgh', 'x'));
    eq('pw.need_mix', Auth::passwordError('12345678', 'x'));
    eq('pw.same_as_id', Auth::passwordError('abcd1234', 'ABCD1234'));
    eq(null, Auth::passwordError('abcd1234', 'user'));
});

test('auth: 비밀번호는 해시로 저장', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $h = (string)Repo::find('user', ['login_id' => 'admin'])['pw_hash'];
    ok($h !== 'Admin1234' && password_verify('Admin1234', $h));
});

test('auth: 로그아웃 후 세션 비움', function () {
    fresh();
    seed_two_companies();
    login('A01', 'admin', 'Admin1234');
    $r = http('POST', '/logout');
    eq('/login', $r->headers['Location']);
    ok(!isset($_SESSION['uid']));
    eq('/login', http('GET', '/admin/users')->headers['Location']);
});
