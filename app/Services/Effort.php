<?php
declare(strict_types=1);

namespace App\Services;

/** 투입시간 입력 검증·표시. 투입공수는 시간(소수 둘째 자리)으로만 입력·저장·집계한다(request.effort_hours). */
final class Effort
{
    public const MAX_HOURS = 9999.99;

    /** @return float|null 시간(소수 둘째 자리). 형식·범위(0~9999.99)가 틀리면 null */
    public static function parse(string $value): ?float
    {
        $value = trim($value);
        return preg_match('/^\d{1,4}(\.\d{1,2})?$/', $value) === 1 ? round((float)$value, 2) : null;
    }

    /** 뒤쪽 0 을 뺀 숫자 문자열(최대 소수 둘째 자리), 값이 없으면 '-'. */
    public static function fmt(mixed $hours): string
    {
        if ($hours === null || $hours === '') {
            return '-';
        }
        $s = rtrim(rtrim(number_format((float)$hours, 2, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
