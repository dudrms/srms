<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttachmentService;
use App\Services\ProcessError;
use App\Services\RequestService;
use App\Services\WorklogService;

/** 작업일지(사후등록 완료 건). 라우트 권한은 WORKLOG_REG, 수정·삭제는 등록자 본인 또는 시스템 관리자. */
final class WorklogController extends Controller
{
    use HandlesUploads;

    public function index(): Response
    {
        $filter = [
            'from' => Request::get('from'),
            'to' => Request::get('to'),
            'requester_id' => $this->intParam(Request::get('requester_id')),
            'service_id' => $this->intParam(Request::get('service_id')),
            'q' => Request::get('q'),
            'page' => $this->intParam(Request::get('page', '1')),
        ];
        return $this->view('logs/index', WorklogService::listFor($filter) + [
            'filter' => $filter,
            'services' => Repo::list('service', [], 'service_cd'),
            'users' => Repo::list('user', [], 'user_nm'),
            'uid' => (int)Auth::user()['user_id'],
            'isAdmin' => in_array('SYS_ADMIN', Auth::roles(), true),
        ]);
    }

    public function create(): Response
    {
        WorklogService::ensure();
        return $this->form(null, ['request_date' => date('Y-m-d'), 'actual_end' => date('Y-m-d')]);
    }

    public function store(): Response
    {
        $uid = (int)Auth::user()['user_id'];
        $in = $this->input();
        try {
            $rid = WorklogService::save(null, $uid, $in);
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
            return $this->form(null, $in);
        }
        $req = Repo::find('request', ['request_id' => $rid]) ?? $this->abort(404);
        $this->storeFiles($req, 1, $uid);
        Audit::log('WORKLOG_CREATE', (string)$req['request_no']);
        $this->flash('success', 'worklog.saved');
        return $this->redirect('/logs');
    }

    public function edit(string $id): Response
    {
        $req = $this->managed($id);
        return $this->form($req, $req);
    }

    public function update(string $id): Response
    {
        $req = $this->managed($id);
        $uid = (int)Auth::user()['user_id'];
        $in = $this->input();
        try {
            WorklogService::save((int)$req['request_id'], $uid, $in);
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
            return $this->form($req, $in);
        }
        $this->storeFiles($req, 1, $uid);
        Audit::log('WORKLOG_UPDATE', (string)$req['request_no']);
        $this->flash('success', 'worklog.saved');
        return $this->redirect('/logs');
    }

    public function delete(string $id): Response
    {
        $req = $this->managed($id);
        try {
            $no = WorklogService::delete((int)$req['request_id'], (int)Auth::user()['user_id']);
            Audit::log('WORKLOG_DELETE', $no);
            $this->flash('success', 'common.deleted');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/logs');
    }

    public function deleteAttachment(string $id, string $aid): Response
    {
        $req = $this->managed($id);
        try {
            AttachmentService::remove($req, $this->intParam($aid), (int)Auth::user()['user_id']);
            Audit::log('WORKLOG_ATTACH_DELETE', (string)$req['request_no']);
            $this->flash('success', 'common.deleted');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/logs/' . (int)$req['request_id'] . '/edit');
    }

    /** @return array<string,mixed> 작업일지가 아니거나 없으면 404, 관리 권한이 없으면 403 */
    private function managed(string $id): array
    {
        $rid = $this->intParam($id);
        $req = $rid > 0 ? Repo::find('request', ['request_id' => $rid]) : null;
        if ($req === null || !WorklogService::isLog($req)) {
            $this->abort(404);
        }
        if (!WorklogService::canManage($req, (int)Auth::user()['user_id'])) {
            $this->abort(403);
        }
        return $req;
    }

    /** @return array<string,string> */
    private function input(): array
    {
        $out = [];
        foreach (['request_date', 'requester_id', 'service_id', 'channel', 'title', 'effort_hours', 'actual_end'] as $k) {
            $out[$k] = $this->post($k);
        }
        $out['content_html'] = Request::post('content_html');
        return $out;
    }

    private function form(?array $req, array $old): Response
    {
        $users = Repo::list('user', ['use_yn' => 1], 'user_nm');
        $services = Repo::list('service', ['use_yn' => 1], 'service_cd');
        if ($req !== null) {
            if (!in_array((int)$req['requester_id'], array_map('intval', array_column($users, 'user_id')), true)) {
                $cur = Repo::find('user', ['user_id' => (int)$req['requester_id']]);
                $cur === null || $users[] = $cur;
            }
            if (!in_array((int)$req['service_id'], array_map('intval', array_column($services, 'service_id')), true)) {
                $cur = Repo::find('service', ['service_id' => (int)$req['service_id']]);
                $cur === null || $services[] = $cur;
            }
        }
        return $this->view('logs/form', [
            'req' => $req,
            'old' => $old,
            'users' => $users,
            'services' => $services,
            'channels' => Repo::list('code', ['group_cd' => WorklogService::CHANNEL_GROUP, 'use_yn' => 1], 'sort_no, code_cd'),
            'attachments' => $req === null ? [] : RequestService::attachments((int)$req['request_id']),
            'today' => date('Y-m-d'),
        ]);
    }
}
