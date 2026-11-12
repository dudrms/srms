<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @var list<array{name:string,value:string,expires:int}> */
    private array $cookies = [];
    public ?string $filePath = null;

    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function redirect(string $path, int $status = 302): self
    {
        return new self($status, '', ['Location' => url($path)]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    /** 다운로드 응답. 파일은 send() 시점에 스트리밍한다. */
    public static function download(string $path, string $name, int $size): self
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';
        $r = new self(200, '', [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'Content-Length' => (string)$size,
        ]);
        $r->filePath = $path;
        return $r;
    }

    public function cookie(string $name, string $value, int $days = 365): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'expires' => time() + $days * 86400];
        return $this;
    }

    /** @return list<array{name:string,value:string,expires:int}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        http_response_code($this->status);
        $common = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
        ];
        if (Request::isHttps()) {
            $common['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($this->headers + $common as $k => $v) {
            header($k . ': ' . $v);
        }
        foreach ($this->cookies as $c) {
            setcookie($c['name'], $c['value'], [
                'expires' => $c['expires'],
                'path' => '/',
                'secure' => Request::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        echo $this->body;
        if ($this->filePath !== null) {
            readfile($this->filePath);
        }
    }
}
