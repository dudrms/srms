<?php
declare(strict_types=1);

use App\Core\Repo;
use App\Core\Scope;

function cookie_of(App\Core\Response $r, string $name): ?string
{
    foreach ($r->cookies() as $c) {
        if ($c['name'] === $name) {
            return $c['value'];
        }
    }
    return null;
}

test('theme: 기본은 밝은 테마, 쿠키로 어두운 테마(로그인 전 화면 포함)', function () {
    fresh();
    seed_two_companies();
    Scope::set('A01');
    eq('light', Repo::find('user', ['login_id' => 'admin'])['theme'], '신규 사용자 기본값');
    $body = http('GET', '/login')->body;
    ok(str_contains($body, 'data-bs-theme="light"'));
    ok(str_contains($body, 'name="theme" value="dark"'), '어두운 테마로 바꾸는 버튼');
    $r = http('POST', '/theme', ['theme' => 'dark', 'back' => '/login']);
    eq(302, $r->status);
    eq('/login', $r->headers['Location']);
    eq('dark', cookie_of($r, 'srms_theme'));
    $_COOKIE['srms_theme'] = 'dark';
    $body = http('GET', '/login')->body;
    ok(str_contains($body, 'data-bs-theme="dark"') && str_contains($body, 'name="theme" value="light"'));
    $_COOKIE['srms_theme'] = 'neon';
    ok(str_contains(http('GET', '/login')->body, 'data-bs-theme="light"'), '알 수 없는 값은 light');
    $_COOKIE = [];
});

test('theme: 입력 검증(알 수 없는 값, 오픈 리다이렉트, CSRF)', function () {
    fresh();
    seed_two_companies();
    $r = http('POST', '/theme', ['theme' => '<script>', 'back' => '//evil.example.com']);
    eq('light', cookie_of($r, 'srms_theme'));
    eq('/', $r->headers['Location'], '외부 주소로는 이동하지 않음');
    eq(403, http('POST', '/theme', ['theme' => 'dark'], false)->status);
    eq(405, http('GET', '/theme')->status);
});

test('theme: 로그인 사용자는 계정에 저장되어 로그인할 때마다 유지, 다른 사용자에게 영향 없음', function () {
    $w = rw();
    as_user('req');
    ok(str_contains(http('GET', '/')->body, 'data-bs-theme="light"'));
    $r = http('POST', '/theme', ['theme' => 'dark', 'back' => '/requests']);
    eq('/requests', $r->headers['Location']);
    Scope::set('A01');
    eq('dark', Repo::find('user', ['user_id' => $w['req']])['theme']);
    eq('light', Repo::find('user', ['user_id' => $w['owner']])['theme']);
    $body = http('GET', '/requests')->body;
    ok(str_contains($body, 'data-bs-theme="dark"') && str_contains($body, 'name="theme" value="light"'), '버튼은 반대 테마');
    // 다시 로그인해도 유지(쿠키가 없어도 계정 값 사용)
    $_COOKIE = [];
    as_user('req');
    ok(str_contains(http('GET', '/')->body, 'data-bs-theme="dark"'));
    // 로그인 응답이 쿠키를 계정 테마로 맞춘다(로그아웃 후 로그인 화면에서도 같은 테마)
    $_SESSION = [];
    $r = login('A01', 'req', PW);
    eq('dark', cookie_of($r, 'srms_theme'));
    // 다른 사용자는 밝은 테마
    as_user('owner');
    ok(str_contains(http('GET', '/')->body, 'data-bs-theme="light"'));
    // 계정 값이 쿠키보다 우선
    $_COOKIE['srms_theme'] = 'dark';
    ok(str_contains(http('GET', '/')->body, 'data-bs-theme="light"'));
    $_COOKIE = [];
});

test('theme: 화면 템플릿에 다크 테마에서 깨지는 고정 색상 클래스가 없다', function () {
    $bad = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/app/Views', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $src = $f->getExtension() === 'php' ? (string)file_get_contents($f->getPathname()) : '';
        if ($src !== '' && (preg_match('/\b(text-bg-light|bg-light|bg-white|text-dark|btn-light|btn-dark|btn-outline-dark|table-light|border-light)\b/', $src) === 1
            || preg_match('/(bg|text-bg)-<\?=[^\n]*\'(white|light|dark)\'/', $src) === 1)) {
            $bad[] = basename($f->getPathname());
        }
    }
    eq([], $bad, '밝은/어두운 고정 색 클래스');
});
