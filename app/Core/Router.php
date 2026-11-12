<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},opts:array}> */
    private array $routes = [];

    public function get(string $path, array $handler, array $opts = []): void
    {
        $this->add('GET', $path, $handler, $opts);
    }

    public function post(string $path, array $handler, array $opts = []): void
    {
        $this->add('POST', $path, $handler, $opts);
    }

    private function add(string $method, string $path, array $handler, array $opts): void
    {
        $regex = '~^' . preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $path) . '$~';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'opts' => $opts];
    }

    public static function fromFile(string $file): self
    {
        $r = new self();
        (require $file)($r);
        return $r;
    }

    public function dispatch(string $method, string $path): Response
    {
        try {
            return $this->run($method, Request::normalize($path));
        } catch (HttpException $e) {
            return $this->errorPage($e->status);
        } catch (\Throwable $e) {
            error_log((string)$e);
            return $this->errorPage(500, env('APP_ENV', 'production') === 'local' ? $e->getMessage() : '');
        }
    }

    private function run(string $method, string $path): Response
    {
        $matched = null;
        $pathExists = false;
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m) !== 1) {
                continue;
            }
            $pathExists = true;
            if ($r['method'] === $method) {
                $matched = [$r, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
                break;
            }
        }
        if ($matched === null) {
            throw new HttpException($pathExists ? 405 : 404);
        }
        [$route, $params] = $matched;
        $opts = $route['opts'];

        if (empty($opts['public'])) {
            if (Auth::user() === null) {
                if ($method === 'GET' && $path !== '/logout') {
                    Session::set('next', $path);
                }
                return Response::redirect('/login');
            }
            if (isset($opts['perm']) && !Auth::can($opts['perm'])) {
                throw new HttpException(403);
            }
        }
        if ($method === 'POST') {
            $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
            if (!Csrf::verify(is_string($token) ? $token : null)) {
                throw new HttpException(403, 'csrf');
            }
        }
        [$class, $action] = $route['handler'];
        return (new $class())->$action(...array_values($params));
    }

    private function errorPage(int $status, string $detail = ''): Response
    {
        $loggedIn = Auth::user() !== null;
        $html = View::render(
            'errors/error',
            ['status' => $status, 'detail' => $detail],
            $loggedIn ? 'layouts/main' : 'layouts/plain'
        );
        return Response::html($html, $status);
    }
}
