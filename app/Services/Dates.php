<?php
declare(strict_types=1);

namespace App\Services;

final class Dates
{
    public static function isDate(string $d): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /** 토/일을 제외한 영업일 가산(공휴일은 반영하지 않음). */
    public static function addBusinessDays(string $from, int $days): string
    {
        $ts = strtotime($from);
        while ($days > 0) {
            $ts = strtotime('+1 day', $ts);
            if ((int)date('N', $ts) < 6) {
                $days--;
            }
        }
        return date('Y-m-d', $ts);
    }

    /** $to 가 $from 보다 늦은 일수(음수면 0). */
    public static function daysLate(string $from, string $to): int
    {
        return max(0, (int)floor((strtotime($to) - strtotime($from)) / 86400));
    }
}
