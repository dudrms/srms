<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public const MAX_FAILS = 5;
    public const MAX_FAILS_IP = 30;
    public const LOCK_MINUTES = 15;

    private static ?array $user = null;
    /** @var list<string>|null */
    private static ?array $perms = null;
    /** @var list<string>|null */
    private static ?array $roles = null;
    private static ?string $dummyHash = null;

    public static function reset(): void
    {
        self::$user = null;
        self::$perms = null;
        self::$roles = null;
    }

    public static function mode(): string
    {
        $m = strtoupper((string)env('AUTH_MODE', 'LOCAL'));
        return in_array($m, ['LOCAL', 'SSO', 'BOTH'], true) ? $m : 'LOCAL';
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, self::hashAlgo(), self::hashOptions());
    }

    private static function hashAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /** 운영은 PHP 기본값. 테스트 등에서만 ARGON_MEMORY_KB / ARGON_TIME 으로 낮춘다. */
    private static function hashOptions(): array
    {
        $o = [];
        if (self::hashAlgo() === PASSWORD_ARGON2ID) {
            if ((int)env('ARGON_MEMORY_KB', '0') > 0) {
                $o['memory_cost'] = (int)env('ARGON_MEMORY_KB');
            }
            if ((int)env('ARGON_TIME', '0') > 0) {
                $o['time_cost'] = (int)env('ARGON_TIME');
            }
        }
        return $o;
    }

    /** 비밀번호 정책 위반 시 번역 키를 반환한다. */
    public static function passwordError(string $pw, string $loginId): ?string
    {
        if (mb_strlen($pw) < 8) {
            return 'pw.too_short';
        }
        if (preg_match('/[A-Za-z]/', $pw) !== 1 || preg_match('/\d/', $pw) !== 1) {
            return 'pw.need_mix';
        }
        if (strcasecmp($pw, $loginId) === 0) {
            return 'pw.same_as_id';
        }
        return null;
    }

    /** 현재 로그인 사용자. 세션이 없거나 만료/비활성이면 null. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $uid = Session::get('uid');
        $cd = Session::get('cd');
        if (!is_int($uid) || !is_string($cd)) {
            return null;
        }
        $life = max(1, (int)env('SESSION_LIFETIME_MIN', '30')) * 60;
        if (time() - (int)Session::get('last_seen', 0) > $life) {
            self::logout();
            Session::flash('warning', t('auth.session_expired'));
            return null;
        }
        $company = Db::one('SELECT use_yn FROM company WHERE company_cd = :c', ['c' => $cd]);
        Scope::set($cd);
        $row = $company !== null && (int)$company['use_yn'] === 1 ? Repo::find('user', ['user_id' => $uid]) : null;
        if ($row === null || (int)$row['use_yn'] !== 1) {
            self::logout();
            return null;
        }
        Session::set('last_seen', time());
        unset($row['pw_hash']);
        I18n::set((string)$row['lang']);
        return self::$user = $row;
    }

    /** 성공 시 null, 실패 시 번역 키를 반환한다. */
    public static function attempt(string $companyCd, string $loginId, string $password, string $ip): ?string
    {
        if (self::mode() === 'SSO') {
            return 'auth.local_disabled';
        }
        $company = Db::one('SELECT company_cd FROM company WHERE company_cd = :c AND use_yn = 1', ['c' => $companyCd]);
        if ($company === null) {
            self::fakeVerify($password);
            return 'auth.invalid';
        }
        Scope::set($companyCd);
        if (self::isLocked($loginId, $ip)) {
            Scope::set(null);
            return 'auth.locked';
        }
        $u = Repo::find('user', ['login_id' => $loginId]);
        $hash = is_array($u) && is_string($u['pw_hash']) && $u['pw_hash'] !== '' ? $u['pw_hash'] : self::dummy();
        $verified = password_verify($password, $hash);
        if ($u === null || (int)$u['use_yn'] !== 1 || !$verified || $hash === self::$dummyHash) {
            Repo::insert('login_fail', ['login_id' => $loginId, 'ip_addr' => $ip, 'failed_at' => now_str()]);
            Audit::log('LOGIN_FAIL', $loginId);
            Scope::set(null);
            return 'auth.invalid';
        }
        Repo::delete('login_fail', ['login_id' => $loginId]);
        if (password_needs_rehash($hash, self::hashAlgo(), self::hashOptions())) {
            Repo::update('user', ['user_id' => $u['user_id']], ['pw_hash' => self::hashPassword($password)]);
        }
        self::establish($u, $companyCd, 'LOGIN');
        return null;
    }

    /** 사내 SSO 로 신원이 확인된 사용자를 로그인 처리한다(비밀번호 없음). 성공 시 null, 실패 시 번역 키. */
    public static function attemptSso(string $companyCd, string $loginId): ?string
    {
        if (!in_array(self::mode(), ['SSO', 'BOTH'], true)) {
            return 'auth.sso_unavailable';
        }
        $company = Db::one('SELECT company_cd FROM company WHERE company_cd = :c AND use_yn = 1', ['c' => $companyCd]);
        if ($company === null || $loginId === '') {
            return 'auth.sso_unknown';
        }
        Scope::set($companyCd);
        $u = Repo::find('user', ['login_id' => $loginId]);
        if ($u === null || (int)$u['use_yn'] !== 1) {
            Audit::log('LOGIN_FAIL', 'SSO:' . $loginId);
            Scope::set(null);
            return 'auth.sso_unknown';
        }
        self::establish($u, $companyCd, 'LOGIN_SSO');
        return null;
    }

    /** 단일 회사 모드(`SINGLE_COMPANY`): 설정되면 회사 선택 없이 서버가 정한 회사로만 로그인한다. */
    public static function fixedCompany(): ?string
    {
        $c = trim((string)env('SINGLE_COMPANY', ''));
        return $c === '' ? null : $c;
    }

    private static function establish(array $u, string $companyCd, string $action): void
    {
        Session::regenerate();
        Session::set('uid', (int)$u['user_id']);
        Session::set('cd', $companyCd);
        Session::set('last_seen', time());
        Session::forget('_csrf');
        Repo::update('user', ['user_id' => $u['user_id']], ['last_login' => now_str()]);
        self::reset();
        Audit::log($action, (string)$u['login_id'], null, (int)$u['user_id']);
    }

    public static function logout(): void
    {
        Session::destroy();
        self::reset();
        Scope::set(null);
    }

    /** @return list<string> */
    public static function perms(): array
    {
        if (self::$perms === null) {
            $u = self::user();
            self::$perms = $u === null ? [] : array_column(Repo::query(
                'SELECT DISTINCT rp.perm_cd FROM user_role ur
                   JOIN role_permission rp ON rp.company_cd = ur.company_cd AND rp.role_cd = ur.role_cd
                  WHERE ur.company_cd = :company_cd AND ur.user_id = :uid',
                ['uid' => $u['user_id']]
            ), 'perm_cd');
        }
        return self::$perms;
    }

    /** @return list<string> */
    public static function roles(): array
    {
        if (self::$roles === null) {
            $u = self::user();
            self::$roles = $u === null ? [] : array_column(
                Repo::list('user_role', ['user_id' => $u['user_id']]),
                'role_cd'
            );
        }
        return self::$roles;
    }

    public static function can(string $perm): bool
    {
        return in_array($perm, self::perms(), true);
    }

    public static function hasRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    private static function isLocked(string $loginId, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $byId = (int)Repo::queryVal(
            'SELECT COUNT(*) FROM login_fail WHERE company_cd = :company_cd AND login_id = :l AND failed_at >= :s',
            ['l' => $loginId, 's' => $since]
        );
        if ($byId >= self::MAX_FAILS) {
            return true;
        }
        if ($ip === '') {
            return false;
        }
        $byIp = (int)Repo::queryVal(
            'SELECT COUNT(*) FROM login_fail WHERE company_cd = :company_cd AND ip_addr = :i AND failed_at >= :s',
            ['i' => $ip, 's' => $since]
        );
        return $byIp >= self::MAX_FAILS_IP;
    }

    private static function dummy(): string
    {
        return self::$dummyHash ??= self::hashPassword('dummy-password-for-timing');
    }

    private static function fakeVerify(string $password): void
    {
        password_verify($password, self::dummy());
    }
}
