<?php
declare(strict_types=1);

namespace App\Services;

/** 번역 키와 치환값을 가진 업무 규칙 위반. */
final class ProcessError extends \RuntimeException
{
    /** @param array<string,mixed> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
