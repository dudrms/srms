<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Db;
use App\Core\Repo;
use App\Core\Response;
use App\Services\DashboardService;

final class HomeController extends Controller
{
    public function index(): Response
    {
        $company = Db::one('SELECT company_nm FROM company WHERE company_cd = :c', ['c' => Auth::user()['company_cd']]);
        $roleNames = [];
        foreach (Repo::list('role', [], 'role_cd') as $r) {
            if (Auth::hasRole((string)$r['role_cd'])) {
                $roleNames[] = master_name('ROLE', (string)$r['role_cd'], (string)$r['role_nm']);
            }
        }
        return $this->view('home/index', [
            'companyNm' => $company['company_nm'] ?? '',
            'roleNames' => $roleNames,
            'dash' => DashboardService::build(Auth::user(), Auth::roles(), Auth::perms()),
        ]);
    }
}
