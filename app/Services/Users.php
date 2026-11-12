<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Repo;

final class Users
{
    public const LOGIN_RE = '/^[A-Za-z0-9._@-]{2,64}$/';

    /**
     * 사용자 생성. 검증 실패 시 번역 키를 가진 InvalidArgumentException.
     * @param list<string> $roles
     */
    public static function create(string $loginId, string $name, string $email, ?int $deptId, string $lang, string $password, array $roles): int
    {
        self::validateProfile($loginId, $name, $email, $lang);
        if (($err = Auth::passwordError($password, $loginId)) !== null) {
            throw new \InvalidArgumentException($err);
        }
        if (Repo::find('user', ['login_id' => $loginId]) !== null) {
            throw new \InvalidArgumentException('user.err_duplicate');
        }
        if ($deptId !== null && Repo::find('dept', ['dept_id' => $deptId]) === null) {
            throw new \InvalidArgumentException('user.err_dept');
        }
        $id = Repo::insert('user', [
            'login_id' => $loginId,
            'user_nm' => $name,
            'email' => $email,
            'dept_id' => $deptId,
            'pw_hash' => Auth::hashPassword($password),
            'use_yn' => 1,
            'lang' => $lang,
            'created_at' => now_str(),
        ]);
        self::setRoles($id, $roles);
        return $id;
    }

    public static function validateProfile(string $loginId, string $name, string $email, string $lang): void
    {
        if (preg_match(self::LOGIN_RE, $loginId) !== 1) {
            throw new \InvalidArgumentException('user.err_login_id');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('user.err_name');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('user.err_email');
        }
        if (!I18n::isEnabled($lang)) {
            throw new \InvalidArgumentException('user.err_lang');
        }
    }

    /** @param list<string> $roles */
    public static function setRoles(int $userId, array $roles): void
    {
        $valid = array_column(Repo::list('role'), 'role_cd');
        $roles = array_values(array_unique(array_intersect($roles, $valid)));
        Repo::delete('user_role', ['user_id' => $userId]);
        foreach ($roles as $r) {
            Repo::insert('user_role', ['user_id' => $userId, 'role_cd' => $r]);
        }
    }
}
