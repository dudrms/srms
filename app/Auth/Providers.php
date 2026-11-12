<?php
declare(strict_types=1);

namespace App\Auth;

/** 사용 중인 SSO 어댑터를 반환한다. 실제 어댑터가 준비되면 `sso()` 의 기본값만 바꾸면 된다. */
final class Providers
{
    private static ?AuthProviderInterface $sso = null;

    public static function sso(): AuthProviderInterface
    {
        return self::$sso ??= new SsoAuthProvider();
    }

    public static function setSso(?AuthProviderInterface $p): void
    {
        self::$sso = $p;
    }
}
