<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Repo;
use App\Core\Response;
use App\Services\ReportService;

/** 리포트 화면과 CSV 내보내기(권한: REPORT_VIEW, 데이터 범위는 ReportService::scope). */
final class ReportController extends Controller
{
    public function index(): Response
    {
        $f = ReportService::filters($_GET);
        $data = ReportService::build($f, Auth::user(), Auth::perms());
        [$scopeSql, $scopeP] = ReportService::scope(Auth::user(), Auth::perms());
        $services = Repo::query(
            'SELECT s.service_id, s.service_cd, s.service_nm FROM service s WHERE s.company_cd = :company_cd AND ' . $scopeSql . ' ORDER BY s.service_cd',
            $scopeP
        );
        $owners = Repo::query(
            'SELECT u.user_id, u.user_nm FROM service s JOIN user u ON u.company_cd = s.company_cd AND u.user_id = s.it_owner_id
              WHERE s.company_cd = :company_cd AND ' . $scopeSql . '
             UNION
             SELECT u.user_id, u.user_nm FROM request r JOIN service s ON s.company_cd = r.company_cd AND s.service_id = r.service_id
               JOIN user u ON u.company_cd = r.company_cd AND u.user_id = r.it_assignee_id
              WHERE r.company_cd = :company_cd AND ' . $scopeSql . ' ORDER BY user_nm',
            $scopeP
        );
        return $this->view('reports/index', [
            'data' => $data, 'f' => $f, 'services' => $services, 'owners' => $owners,
            'tab' => ($_GET['tab'] ?? '') === 'detail' ? 'detail' : 'monthly',
            'types' => Repo::list('code', ['group_cd' => 'MAINT_TYPE'], 'sort_no, code_cd'),
            'query' => http_build_query(array_filter($f, static fn($v): bool => $v !== '' && $v !== 0)),
        ]);
    }

    public function export(string $section): Response
    {
        if (!in_array($section, ReportService::SECTIONS, true)) {
            $this->abort(404);
        }
        $f = ReportService::filters($_GET);
        $data = ReportService::build($f, Auth::user(), Auth::perms());
        Audit::log('REPORT_EXPORT', $section);
        $name = 'srms_' . $section . '_' . date('Ymd') . '.csv';
        return new Response(200, ReportService::csv($data['sections'][$section]), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }
}
