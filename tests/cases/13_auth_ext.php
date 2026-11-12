<?php
declare(strict_types=1);

use App\Auth\AuthProviderInterface;
use App\Auth\Providers;
use App\Core\Env;
use App\Core\Repo;
use App\Core\Scope;

/** 테스트용 SSO 어댑터: state 가 일치하고 `ok` 파라미터가 있으면 지정한 사용자로 식별한다. */
final class FakeSso implements AuthProviderInterface
{
    public function __construct(private string $company, private string $loginId)
    {
    }

    public function name(): string
    {
        return 'FAKE';
    }

    public function loginUrl(string $state): ?string
    {
        return 'https://idp.example.com/auth?state=' . $state;
    }

    public function identify(array $query, string $expectedState): ?array
    {
        if (($query['state'] ?? '') !== $expectedState || ($query['ok'] ?? '') !== '1') {
            return null;
        }
        return ['company_cd' => $this->company, 'login_id' => $this->loginId];
    }
}

function with_auth_env(array $env, callable $fn): void
{
    $old = [];
    foreach ($env as $k => $v) {
        $old[$k] = env($k, '');
        Env::set($k, $v);
    }
    try {
        $fn();
    } finally {
        foreach ($old as $k => $v) {
            Env::set($k, (string)$v);
        }
        Providers::setSso(null);
    }
}

test('sso: 기본 어댑터는 미연동이므로 거부, LOCAL 모드에서는 라우트 없음', function () {
    fresh();
    seed_two_companies();
    with_auth_env(['AUTH_MODE' => 'LOCAL'], function () {
        eq(404, http('GET', '/sso/login')->status);
        eq(404, http('GET', '/sso/callback?state=x&ok=1')->status);
        ok(!str_contains(http('GET', '/login')->body, '/sso/login'), 'LOCAL 은 SSO 버튼 없음');
    });
    with_auth_env(['AUTH_MODE' => 'BOTH'], function () {
        $r = http('GET', '/login');
        ok(str_contains($r->body, '/sso/login') && str_contains($r->body, 'name="password"'), 'BOTH 는 둘 다 표시');
        $r = http('GET', '/sso/login');
        eq('/login', $r->headers['Location']);
        eq(t('auth.sso_unavailable'), last_flash());
        $_SESSION = [];
        $r = http('GET', '/sso/callback?state=abc&ok=1');
        eq('/login', $r->headers['Location']);
        eq(t('auth.sso_failed'), last_flash());
    });
    with_auth_env(['AUTH_MODE' => 'SSO'], function () {
        $body = http('GET', '/login')->body;
        ok(str_contains($body, '/sso/login') && !str_contains($body, 'name="password"'), 'SSO 모드는 비밀번호 폼 없음');
    });
});

test('sso: 어댑터 연동 흐름(state 검증, 미등록/비활성 사용자 거부, next 이동, 감사로그)', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    $uid = (int)Repo::find('user', ['login_id' => 'user1'])['user_id'];
    with_auth_env(['AUTH_MODE' => 'SSO'], function () use ($uid) {
        Providers::setSso(new FakeSso('A01', 'user1'));
        $_SESSION = [];
        http('GET', '/requests');
        eq('/requests', $_SESSION['next']);
        $r = http('GET', '/sso/login');
        eq(302, $r->status);
        $state = $_SESSION['sso_state'];
        ok(str_starts_with($r->headers['Location'], 'https://idp.example.com/auth?state=' . $state));
        // state 불일치/누락
        $r = http('GET', '/sso/callback?state=wrong&ok=1');
        eq(t('auth.sso_failed'), last_flash());
        ok(!isset($_SESSION['uid']));
        // state 는 1회용
        $r = http('GET', '/sso/callback?state=' . $state . '&ok=1');
        eq(t('auth.sso_failed'), last_flash());
        // 정상
        $_SESSION['next'] = '/requests';
        http('GET', '/sso/login');
        $state = $_SESSION['sso_state'];
        $r = http('GET', '/sso/callback?state=' . $state . '&ok=1');
        eq(302, $r->status);
        eq('/requests', $r->headers['Location']);
        eq($uid, $_SESSION['uid']);
        Scope::set('A01');
        ok(Repo::count('audit_log', ['action' => 'LOGIN_SSO']) === 1);
        eq(200, http('GET', '/requests')->status);
        // 비활성 사용자
        Scope::set('A01');
        Repo::update('user', ['user_id' => $uid], ['use_yn' => 0]);
        $_SESSION = [];
        http('GET', '/sso/login');
        $state = $_SESSION['sso_state'];
        http('GET', '/sso/callback?state=' . $state . '&ok=1');
        eq(t('auth.sso_unknown'), last_flash());
        ok(!isset($_SESSION['uid']));
        // 존재하지 않는 사번/회사
        foreach ([['A01', 'nobody'], ['ZZZ', 'user1'], ['B01', 'user1x']] as [$c, $l]) {
            Providers::setSso(new FakeSso($c, $l));
            $_SESSION = [];
            http('GET', '/sso/login');
            http('GET', '/sso/callback?state=' . $_SESSION['sso_state'] . '&ok=1');
            eq(t('auth.sso_unknown'), last_flash(), "$c/$l");
        }
        // 로컬 로그인은 SSO 전용 모드에서 거부
        $_SESSION = [];
        login('A01', 'admin', 'Admin1234');
        eq(t('auth.local_disabled'), last_flash());
    });
});

test('sso: 회사 격리 - 어댑터가 다른 회사 사번을 줘도 해당 회사 사용자로만 로그인', function () {
    fresh();
    seed_two_companies();
    with_auth_env(['AUTH_MODE' => 'BOTH'], function () {
        Providers::setSso(new FakeSso('B01', 'user1'));
        $_SESSION = [];
        http('GET', '/sso/login');
        http('GET', '/sso/callback?state=' . $_SESSION['sso_state'] . '&ok=1');
        eq('B01', $_SESSION['cd']);
        Scope::set('B01');
        eq('UserB', Repo::find('user', ['user_id' => $_SESSION['uid']])['user_nm']);
    });
});

test('single-company: SINGLE_COMPANY 이면 회사 선택 없이 고정 회사로만 로그인', function () {
    fresh();
    seed_two_companies();
    with_auth_env(['SINGLE_COMPANY' => 'A01'], function () {
        $body = http('GET', '/login')->body;
        ok(!str_contains($body, 'name="company_cd"'), '회사 선택 없음');
        ok(str_contains($body, 'Alpha'), '고정 회사명 표시');
        $_SESSION = [];
        // 폼에 다른 회사를 넣어도 무시된다(A01 사용자 admin/Admin1234 는 A01 에서 성공)
        $r = http('POST', '/login', ['company_cd' => 'B01', 'login_id' => 'admin', 'password' => 'Admin1234']);
        eq(302, $r->status);
        eq('A01', $_SESSION['cd']);
        // B01 의 비밀번호로는 실패
        $_SESSION = [];
        http('POST', '/login', ['company_cd' => 'B01', 'login_id' => 'admin', 'password' => 'Admin5678']);
        eq(t('auth.invalid'), last_flash());
        ok(!isset($_SESSION['uid']));
        // company_cd 없이도 로그인 가능
        $_SESSION = [];
        $r = http('POST', '/login', ['login_id' => 'admin', 'password' => 'Admin1234']);
        eq('A01', $_SESSION['cd']);
    });
    // 존재하지 않는 회사로 고정되면 아무도 로그인할 수 없다
    with_auth_env(['SINGLE_COMPANY' => 'NOPE'], function () {
        $_SESSION = [];
        http('POST', '/login', ['login_id' => 'admin', 'password' => 'Admin1234']);
        eq(t('auth.invalid'), last_flash());
        ok(!isset($_SESSION['uid']));
        ok(str_contains(http('GET', '/login')->body, 'NOPE'));
    });
    // 해제하면 다시 회사 선택
    ok(str_contains(http('GET', '/login')->body, 'name="company_cd"'));
});
