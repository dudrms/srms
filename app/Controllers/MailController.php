<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\MailService;
use App\Services\MailTemplates;

/** 메일 템플릿 편집과 발송 이력(권한: PROCESS_EDIT). */
final class MailController extends Controller
{
    private const STATUSES = ['QUEUED', 'SENDING', 'SENT', 'FAILED'];

    public function templates(): Response
    {
        $codes = MailTemplates::codes();
        foreach (Repo::query('SELECT DISTINCT tmpl_cd FROM mail_template WHERE company_cd = :company_cd ORDER BY tmpl_cd') as $r) {
            $codes[] = (string)$r['tmpl_cd'];
        }
        $codes = array_values(array_unique($codes));
        sort($codes);
        $used = [];
        foreach (Repo::query(
            'SELECT notify_tmpl, COUNT(*) AS n FROM process_step WHERE company_cd = :company_cd AND notify_tmpl IS NOT NULL GROUP BY notify_tmpl'
        ) as $r) {
            $used[(string)$r['notify_tmpl']] = (int)$r['n'];
        }
        return $this->view('admin/mail/templates', ['codes' => $codes, 'used' => $used]);
    }

    public function createTemplate(): Response
    {
        $code = strtoupper($this->post('tmpl_cd'));
        if (preg_match(MailTemplates::CODE_RE, $code) !== 1) {
            $this->flash('danger', 'mailtpl.err_code');
            return $this->redirect('/admin/mail-templates');
        }
        if (Repo::find('mail_template', ['tmpl_cd' => $code]) !== null || MailTemplates::isBuiltin($code)) {
            $this->flash('danger', 'mailtpl.err_duplicate');
            return $this->redirect('/admin/mail-templates');
        }
        $from = [];
        foreach (MailTemplates::LANGS as $lang) {
            $from[$lang] = MailTemplates::find(MailTemplates::FALLBACK, $lang) ?? ['', ''];
        }
        MailTemplates::save($code, $from);
        Audit::log('MAILTPL_CREATE', $code);
        $this->flash('success', 'common.saved');
        return $this->redirect('/admin/mail-templates/' . $code);
    }

    public function editTemplate(string $code): Response
    {
        $rows = $this->templateRows($code);
        return $this->view('admin/mail/template', [
            'code' => $code, 'rows' => $rows, 'builtin' => MailTemplates::isBuiltin($code), 'tmplVars' => MailTemplates::VARS,
            'used' => Repo::count('process_step', ['notify_tmpl' => $code]),
        ]);
    }

    public function saveTemplate(string $code): Response
    {
        $this->templateRows($code);
        $in = [];
        foreach (MailTemplates::LANGS as $lang) {
            $in[$lang] = [Request::post('subject_' . $lang), Request::post('body_' . $lang)];
        }
        try {
            MailTemplates::save($code, $in);
        } catch (\InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->redirect('/admin/mail-templates/' . $code);
        }
        Audit::log('MAILTPL_UPDATE', $code);
        $this->flash('success', 'common.saved');
        return $this->redirect('/admin/mail-templates/' . $code);
    }

    public function resetTemplate(string $code): Response
    {
        $this->templateRows($code);
        try {
            MailTemplates::reset($code);
        } catch (\InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->redirect('/admin/mail-templates/' . $code);
        }
        Audit::log('MAILTPL_RESET', $code);
        $this->flash('success', 'mailtpl.ok_reset');
        return $this->redirect('/admin/mail-templates/' . $code);
    }

    public function deleteTemplate(string $code): Response
    {
        $this->templateRows($code);
        if (MailTemplates::isBuiltin($code)) {
            $this->flash('danger', 'mailtpl.err_builtin_delete');
        } elseif (Repo::count('process_step', ['notify_tmpl' => $code]) > 0) {
            $this->flash('danger', 'mailtpl.err_in_use');
        } else {
            Repo::delete('mail_template', ['tmpl_cd' => $code]);
            Audit::log('MAILTPL_DELETE', $code);
            $this->flash('success', 'common.deleted');
        }
        return $this->redirect('/admin/mail-templates');
    }

    public function log(): Response
    {
        $status = Request::get('status');
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $where = 'n.company_cd = :company_cd' . ($status === '' ? '' : ' AND n.status = :st');
        $p = $status === '' ? [] : ['st' => $status];
        $total = (int)Repo::queryVal('SELECT COUNT(*) FROM notification_log n WHERE ' . $where, $p);
        $size = env_int('MAIL_LOG_PAGE_SIZE', 30, 5, 200);
        $pages = max(1, (int)ceil($total / $size));
        $page = min(max(1, $this->intParam(Request::get('page', '1'))), $pages);
        $rows = Repo::query(
            'SELECT n.*, r.request_no FROM notification_log n
               LEFT JOIN request r ON r.company_cd = n.company_cd AND r.request_id = n.request_id
              WHERE ' . $where . ' ORDER BY n.noti_id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size),
            $p
        );
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Repo::query('SELECT status, COUNT(*) AS n FROM notification_log WHERE company_cd = :company_cd GROUP BY status') as $r) {
            $counts[(string)$r['status']] = (int)$r['n'];
        }
        return $this->view('admin/mail/log', [
            'rows' => $rows, 'counts' => $counts, 'status' => $status, 'page' => $page, 'pages' => $pages,
            'driver' => MailService::driver(),
        ]);
    }

    public function requeue(string $id): Response
    {
        $nid = $this->intParam($id);
        if (Repo::find('notification_log', ['noti_id' => $nid]) === null) {
            $this->abort(404);
        }
        $ok = MailService::requeue($nid);
        $this->flash($ok ? 'success' : 'danger', $ok ? 'mailtpl.ok_requeued' : 'mailtpl.err_requeue');
        return $this->redirect('/admin/mail-log');
    }

    /** @return array<string,array{0:string,1:string}> 언어 => [제목, 본문]. 존재하지 않는 코드는 404 */
    private function templateRows(string $code): array
    {
        if (preg_match(MailTemplates::CODE_RE, $code) !== 1
            || (Repo::find('mail_template', ['tmpl_cd' => $code]) === null && !MailTemplates::isBuiltin($code))) {
            $this->abort(404);
        }
        $out = [];
        foreach (MailTemplates::LANGS as $lang) {
            $out[$lang] = MailTemplates::find($code, $lang) ?? ['', ''];
        }
        return $out;
    }
}
