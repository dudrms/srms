<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Repo;
use App\Core\Scope;

final class CompanySetup
{
    /**
     * 회사 생성 + 기본 시드 + 초기 시스템관리자.
     * @return string 관리자 초기 비밀번호(미지정 시 생성된 값)
     */
    public static function provision(
        string $companyCd,
        string $companyNm,
        string $lang,
        string $adminId,
        string $adminName,
        string $adminEmail,
        ?string $password = null,
    ): string {
        $password ??= self::randomPassword();
        Db::tx(static function () use ($companyCd, $companyNm, $lang, $adminId, $adminName, $adminEmail, $password): void {
            Seeder::createCompany($companyCd, $companyNm, $lang);
            Scope::set($companyCd);
            $it = Repo::queryOne('SELECT dept_id FROM dept WHERE company_cd = :company_cd AND is_it = 1 ORDER BY dept_id LIMIT 1');
            Users::create($adminId, $adminName, $adminEmail, $it !== null ? (int)$it['dept_id'] : null, $lang, $password, ['SYS_ADMIN']);
        });
        return $password;
    }

    public static function randomPassword(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $pw = '';
        for ($i = 0; $i < 12; $i++) {
            $pw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pw . random_int(10, 99);
    }
}
