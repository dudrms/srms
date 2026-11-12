<?php
declare(strict_types=1);

namespace App\Core;

/** 현재 요청이 속한 회사코드. 로그인/CLI 시작 시 한 번 고정한다. */
final class Scope
{
    private static ?string $company = null;

    public static function set(?string $companyCd): void
    {
        self::$company = $companyCd;
    }

    public static function has(): bool
    {
        return self::$company !== null;
    }

    public static function cd(): string
    {
        if (self::$company === null) {
            throw new \LogicException('company scope is not set');
        }
        return self::$company;
    }
}
