<?php
declare(strict_types=1);

// 자체 경량 테스트 러너: php tests/run.php [이름필터]
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Migrator;
use App\Core\Response;
use App\Core\Router;
use App\Core\Scope;
use App\Services\CompanySetup;

error_reporting(E_ALL);
// 테스트는 비밀번호 해시를 많이 만들므로 Argon2 비용을 낮춘다.
App\Core\Env::set('ARGON_MEMORY_KB', '1024');
App\Core\Env::set('ARGON_TIME', '1');
// 셸 환경변수(MAIL_DRIVER, STORAGE_PATH 등)가 섞여도 결과가 달라지지 않도록 고정한다.
App\Core\Env::set('MAIL_DRIVER', '');
App\Core\Env::set('STORAGE_PATH', 'storage');
App\Core\Env::set('SINGLE_COMPANY', '');
App\Core\Env::set('LIST_PAGE_SIZE', '');
App\Core\Env::set('MAIL_LOG_PAGE_SIZE', '');
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if ((error_reporting() & $no) === 0) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

$GLOBALS['__t'] = ['pass' => 0, 'fail' => [], 'filter' => $argv[1] ?? ''];

function test(string $name, callable $fn): void
{
    $f = $GLOBALS['__t']['filter'];
    if ($f !== '' && stripos($name, $f) === false) {
        return;
    }
    try {
        $fn();
        $GLOBALS['__t']['pass']++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $GLOBALS['__t']['fail'][] = $name;
        echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n       at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function throws(callable $fn, string $class = Throwable::class): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException('wrong exception: ' . get_class($e) . ' ' . $e->getMessage());
    }
    throw new RuntimeException('expected exception ' . $class);
}

/** 매 테스트 전 메모리 DB 를 새로 만들고 전역 상태를 초기화한다. */
function fresh(): void
{
    Db::connect(':memory:');
    Migrator::run();
    reset_state();
}

function reset_state(): void
{
    $_SESSION = [];
    $_POST = [];
    $_GET = [];
    $_COOKIE = [];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    Auth::reset();
    Scope::set(null);
    I18n::set('ko');
    I18n::clearMasterCache();
    \App\Core\Env::set('AUTH_MODE', 'LOCAL');
    \App\Core\Env::set('SINGLE_COMPANY', '');
    \App\Core\Env::set('LIST_PAGE_SIZE', '');
    \App\Core\Env::set('MAIL_LOG_PAGE_SIZE', '');
}

/** 회사 A01(admin/Admin1234), B01(admin/Admin5678) 와 각 회사 일반 사용자 생성 */
function seed_two_companies(): void
{
    CompanySetup::provision('A01', 'Alpha', 'ko', 'admin', 'AdminA', 'a@example.com', 'Admin1234');
    CompanySetup::provision('B01', 'Beta', 'ko', 'admin', 'AdminB', 'b@example.com', 'Admin5678');
    Scope::set('A01');
    App\Services\Users::create('user1', 'UserA', 'u1@example.com', null, 'ko', 'User1234', ['REQUESTER']);
    Scope::set('B01');
    App\Services\Users::create('user1', 'UserB', 'ub@example.com', null, 'ko', 'User5678', ['REQUESTER']);
    Scope::set(null);
}

function router(): Router
{
    static $r = null;
    return $r ??= Router::fromFile(APP_ROOT . '/app/routes.php');
}

/** 실제 요청 주기를 흉내낸다(Auth 캐시/Scope 초기화, CSRF 자동 부여). */
function http(string $method, string $path, array $post = [], bool $csrf = true): Response
{
    Auth::reset();
    Scope::set(null);
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path;
    $_GET = [];
    if (($q = parse_url($path, PHP_URL_QUERY)) !== null) {
        parse_str((string)$q, $_GET);
    }
    $_POST = $post;
    if ($method === 'POST' && $csrf && !array_key_exists('_csrf', $_POST)) {
        $_POST['_csrf'] = Csrf::token();
    }
    return router()->dispatch($method, (string)parse_url($path, PHP_URL_PATH));
}

function login(string $company, string $id, string $pw): Response
{
    return http('POST', '/login', ['company_cd' => $company, 'login_id' => $id, 'password' => $pw]);
}

function last_flash(): string
{
    $f = $_SESSION['_flash'] ?? [];
    return $f === [] ? '' : (string)end($f)['message'];
}

/** 폼을 직접 렌더링한 응답에서는 플래시가 레이아웃에서 소비되므로 본문에서 확인한다. */
function body_has(Response $r, string $key): bool
{
    return str_contains($r->body, e(t($key)));
}

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $file) {
    echo basename($file) . "\n";
    require $file;
}

$r = $GLOBALS['__t'];
echo "\n" . $r['pass'] . ' passed, ' . count($r['fail']) . " failed\n";
exit($r['fail'] === [] ? 0 : 1);
