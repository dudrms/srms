<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function verify(?string $given): bool
    {
        $t = Session::get('_csrf');
        return is_string($t) && $t !== '' && is_string($given) && hash_equals($t, $given);
    }
}
