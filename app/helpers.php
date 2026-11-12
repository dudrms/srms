<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\I18n;

function env(string $key, ?string $default = null): ?string
{
    return Env::get($key, $default);
}

/** 정수 설정값. 비어 있거나 숫자가 아니면 기본값, 범위를 벗어나면 최소/최대로 맞춘다. */
function env_int(string $key, int $default, int $min, int $max): int
{
    $v = trim((string)env($key, ''));
    return preg_match('/^\d{1,6}$/', $v) === 1 ? min($max, max($min, (int)$v)) : $default;
}

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function t(string $key, array $params = []): string
{
    return I18n::t($key, $params);
}

function url(string $path = '/'): string
{
    return rtrim((string)env('APP_BASE_PATH', ''), '/') . $path;
}

function asset(string $path): string
{
    return url('/assets/' . ltrim($path, '/'));
}

/** 화면에 표시할 언어 자체 명칭(한국어/日本語/English/中文). */
function lang_name(string $lang): string
{
    return I18n::NAMES[$lang] ?? $lang;
}

/** 화면 테마: 로그인 사용자의 저장값 → 쿠키 → light. */
function theme(): string
{
    $t = (string)(Auth::user()['theme'] ?? ($_COOKIE['srms_theme'] ?? 'light'));
    return $t === 'dark' ? 'dark' : 'light';
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function can(string $perm): bool
{
    return Auth::can($perm);
}

function master_name(string $entity, string $key, string $default): string
{
    return I18n::master($entity, $key, $default);
}

/** 사용자 <option> 목록: 부서별 optgroup(부서 없음은 마지막), 표기 `이름 (ID)`. */
function user_options(array $users, ?int $selected = null): string
{
    $depts = array_column(\App\Core\Repo::list('dept'), 'dept_nm', 'dept_id');
    $groups = [];
    foreach ($users as $u) {
        $name = $u['dept_id'] === null ? '' : (string)($depts[(int)$u['dept_id']] ?? '');
        $groups[$name][] = $u;
    }
    uksort($groups, static fn(string $a, string $b): int => $a === '' ? ($b === '' ? 0 : 1) : ($b === '' ? -1 : strcmp($a, $b)));
    $html = '';
    foreach ($groups as $name => $list) {
        $html .= '<optgroup label="' . e($name === '' ? t('common.none') : $name) . '">';
        foreach ($list as $u) {
            $html .= '<option value="' . (int)$u['user_id'] . '"' . ($selected === (int)$u['user_id'] ? ' selected' : '') . '>'
                . e($u['user_nm'] . ' (' . $u['login_id'] . ')') . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

/** 투입시간 입력칸(숫자 + `시간` 단위 표시). $after 는 입력 그룹 끝에 붙일 HTML(저장 버튼 등). */
function effort_input(string $value, bool $small = false, string $placeholder = '', string $after = ''): string
{
    return '<div class="input-group' . ($small ? ' input-group-sm' : '') . '">'
        . '<input class="form-control" name="effort_hours" inputmode="decimal" autocomplete="off" value="' . e($value) . '" placeholder="' . e($placeholder) . '" required>'
        . '<span class="input-group-text">' . e(t('effort.unit_h')) . '</span>' . $after . '</div>';
}

function fmt_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return number_format($bytes / 1048576, 1) . ' MB';
}

function fmt_date(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }
    $hasTime = strlen($iso) > 10;
    $fmt = match (I18n::lang()) {
        'ja' => 'Y/m/d',
        'en' => 'd M Y',
        default => 'Y-m-d',
    };
    return date($hasTime ? $fmt . ' H:i' : $fmt, $ts);
}
