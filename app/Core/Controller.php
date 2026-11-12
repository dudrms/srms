<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/main'): Response
    {
        return Response::html(View::render($view, $data, $layout));
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    protected function flash(string $type, string $key, array $params = []): void
    {
        Session::flash($type, t($key, $params));
    }

    protected function abort(int $status): never
    {
        throw new HttpException($status);
    }

    protected function post(string $key, string $default = ''): string
    {
        return trim(Request::post($key, $default));
    }

    /** 오픈 리다이렉트 방지: 내부 경로만 허용한다. */
    protected function safePath(string $path, string $fallback = '/'): string
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || preg_match('/[\\\\\r\n]/', $path) === 1) {
            return $fallback;
        }
        return $path;
    }

    protected function intParam(string $v): int
    {
        return ctype_digit($v) ? (int)$v : 0;
    }
}
