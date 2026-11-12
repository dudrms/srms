<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActorResolver;
use App\Services\AttachmentService;
use App\Services\HtmlSanitizer;
use App\Services\ProcessError;
use App\Services\ProcessService;
use App\Services\RequestAccess;
use App\Services\RequestService;
use App\Services\ScheduleService;
use App\Services\SplitService;
use App\Services\WorkflowService;
use App\Services\WorklogService;

final class RequestController extends Controller
{
    use HandlesUploads;

    private const OUT_KEYS = [
        'ADVANCED' => 'request.out_advanced',
        'COMPLETED' => 'request.out_completed',
        'WAITING_OTHERS' => 'request.out_waiting_others',
        'RETURNED' => 'request.out_returned',
        'REWORK' => 'request.out_rework',
    ];
    public function index(): Response
    {
        [$user, $roles, $perms] = $this->ctx();
        $filter = [
            'scope' => Request::get('scope', 'mine'),
            'status' => Request::get('status'),
            'service_id' => $this->intParam(Request::get('service_id')),
            'q' => Request::get('q'),
            'page' => $this->intParam(Request::get('page', '1')),
        ];
        $res = RequestService::listFor($user, $roles, $perms, $filter);
        return $this->view('requests/index', $res + [
            'filter' => $filter,
            'scopes' => RequestService::scopes($perms, $user),
            'services' => Repo::list('service', [], 'service_cd'),
            'statuses' => RequestService::STATUSES,
            'typeNames' => $this->typeNames(),
        ]);
    }

    public function inbox(): Response
    {
        return $this->redirect('/requests?scope=inbox');
    }

    public function create(): Response
    {
        return $this->form(null, ['priority' => 'NORMAL', 'req_due_date' => '']);
    }

    public function store(): Response
    {
        $uid = (int)Auth::user()['user_id'];
        $in = $this->input();
        try {
            $rid = RequestService::save(null, $uid, $in);
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
            return $this->form(null, $in);
        }
        Audit::log('REQUEST_CREATE', (string)Repo::find('request', ['request_id' => $rid])['request_no']);
        return $this->afterSave($rid, $uid, Request::post('submit') === '1');
    }

    public function edit(string $id): Response
    {
        $req = $this->editable($id);
        return $this->form($req, $req);
    }

    public function update(string $id): Response
    {
        $req = $this->editable($id);
        $uid = (int)Auth::user()['user_id'];
        $in = $this->input();
        foreach (['service_id', 'maint_type'] as $k) {
            if ($in[$k] === '') {
                $in[$k] = (string)$req[$k];
            }
        }
        try {
            RequestService::save((int)$req['request_id'], $uid, $in);
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
            return $this->form($req, $in);
        }
        return $this->afterSave((int)$req['request_id'], $uid, Request::post('submit') === '1');
    }

    public function show(string $id): Response
    {
        $req = $this->visible($id);
        [$user, $roles, $perms] = $this->ctx();
        $uid = (int)$user['user_id'];
        $svc = WorkflowService::service($req);
        $rid = (int)$req['request_id'];
        $isRequester = (int)$req['requester_id'] === $uid;
        $status = (string)$req['status'];
        $steps = RequestService::stepper($req, $svc);
        $cur = null;
        foreach ($steps as $s) {
            if ($req['current_step_no'] !== null && (int)$s['step_no'] === (int)$req['current_step_no']) {
                $cur = $s;
            }
        }
        $process = Repo::queryOne(
            'SELECT m.process_cd, m.process_nm, v.version_no FROM process_version v JOIN process_master m ON m.company_cd = v.company_cd AND m.process_id = v.process_id
              WHERE v.company_cd = :company_cd AND v.version_id = :v',
            ['v' => (int)$req['version_id']]
        );
        return $this->view('requests/show', [
            'req' => $req,
            'svc' => $svc,
            'requester' => Repo::find('user', ['user_id' => (int)$req['requester_id']]),
            'process' => $process,
            'steps' => $steps,
            'cur' => $cur,
            'attachments' => RequestService::attachments($rid),
            'timeline' => RequestService::timeline($rid),
            'canAct' => Auth::can('STEP_ACT') && RequestService::canAct($req, $svc, $uid),
            'canEdit' => $isRequester && Auth::can('REQUEST_CREATE') && in_array($status, [WorkflowService::ST_DRAFT, WorkflowService::ST_RETURNED], true),
            'canCancel' => $isRequester && in_array($status, [WorkflowService::ST_DRAFT, WorkflowService::ST_PROGRESS, WorkflowService::ST_RETURNED], true),
            'uploadStep' => $this->uploadStep($req, $uid),
            'locked' => RequestService::isLocked($rid),
            'canEffort' => RequestService::canSetEffort($req, $svc, $uid),
            'sched' => $this->scheduleData($req, $svc, $uid, $isRequester),
            'isRequester' => $isRequester,
            'log' => WorklogService::isLog($req) ? [
                'reg' => Repo::find('user', ['user_id' => (int)$req['reg_by']]),
                'canManage' => Auth::can('WORKLOG_REG') && WorklogService::canManage($req, $uid),
                'channel' => $req['channel'] === null ? null : (Repo::find('code', ['group_cd' => WorklogService::CHANNEL_GROUP, 'code_cd' => (string)$req['channel']]) ?? ['code_cd' => (string)$req['channel'], 'code_nm' => (string)$req['channel']]),
            ] : null,
            'typeNames' => $this->typeNames(),
            'prioNames' => array_column(Repo::list('code', ['group_cd' => 'PRIORITY']), 'code_nm', 'code_cd'),
            'itUser' => SplitService::assignee($req, $svc),
            'canAssign' => Auth::can('REQUEST_SPLIT') && SplitService::canAssign($req),
            'canSplit' => Auth::can('REQUEST_SPLIT') && SplitService::canSplit($req),
            'users' => Auth::can('REQUEST_SPLIT') ? Repo::list('user', ['use_yn' => 1], 'user_nm') : [],
            'parent' => $req['parent_id'] === null ? null : Repo::find('request', ['request_id' => (int)$req['parent_id']]),
            'children' => Repo::list('request', ['parent_id' => $rid], 'request_id'),
        ]);
    }

    /** 요청별 IT 담당자 지정(비우면 서비스 담당자로 복귀). */
    public function assign(string $id): Response
    {
        $req = $this->visible($id);
        $to = $this->intParam(Request::post('assignee_id'));
        try {
            SplitService::assign((int)$req['request_id'], (int)Auth::user()['user_id'], $to > 0 ? $to : null);
            Audit::log('REQUEST_ASSIGN', (string)$req['request_no'], $to > 0 ? (string)$to : '');
            $this->flash('success', 'split.assigned');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id']);
    }

    /** 같은 서비스·유형의 새 SR 을 만들어 현재 단계에서 이어 진행한다. */
    public function split(string $id): Response
    {
        $req = $this->visible($id);
        $in = [];
        foreach (['title', 'note', 'new_assignee_id', 'orig_assignee_id'] as $k) {
            $in[$k] = $k === 'note' ? Request::post($k) : $this->post($k);
        }
        try {
            $child = SplitService::split((int)$req['request_id'], (int)Auth::user()['user_id'], $in);
            $no = (string)Repo::find('request', ['request_id' => $child])['request_no'];
            Audit::log('REQUEST_SPLIT', (string)$req['request_no'], $no);
            $this->flash('success', 'split.done', ['no' => $no]);
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
            return $this->redirect('/requests/' . (int)$req['request_id'] . '#split');
        }
        return $this->redirect('/requests/' . (int)$req['request_id']);
    }

    public function act(string $id): Response
    {
        $req = $this->visible($id);
        $decision = Request::post('decision') === 'REJECT' ? 'REJECT' : 'PASS';
        try {
            $out = WorkflowService::act((int)$req['request_id'], (int)Auth::user()['user_id'], $decision, Request::post('opinion'));
            Audit::log('REQUEST_ACT', $req['request_no'] . ' ' . $decision . ' ' . $out);
            $this->flash('success', self::OUT_KEYS[$out] ?? 'request.out_advanced');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id']);
    }

    /** 작업자가 완료요구일을 맞출 수 없을 때 대안 완료일을 제안한다. */
    public function schedule(string $id): Response
    {
        $req = $this->visible($id);
        try {
            ScheduleService::propose(
                (int)$req['request_id'], (int)Auth::user()['user_id'],
                $this->post('new_end'), $this->post('reason_cd'), Request::post('reason_text')
            );
            Audit::log('SCHEDULE_PROPOSE', (string)$req['request_no'] . ' ' . $this->post('new_end'));
            $this->flash('success', 'sched.ok_proposed');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id'] . '#schedule');
    }

    /** 요청자 본인의 합의/반려/대안. */
    public function scheduleRespond(string $id, string $cid): Response
    {
        $req = $this->visible($id);
        $decision = $this->post('decision');
        $ok = ['AGREE' => 'sched.ok_agreed', 'REJECT' => 'sched.ok_rejected', 'COUNTER' => 'sched.ok_countered'];
        try {
            ScheduleService::respond(
                (int)$req['request_id'], $this->intParam($cid), (int)Auth::user()['user_id'],
                $decision, Request::post('comment'), $this->post('counter_end')
            );
            Audit::log('SCHEDULE_' . $decision, (string)$req['request_no']);
            $this->flash('success', $ok[$decision] ?? 'sched.ok_agreed');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id'] . '#schedule');
    }

    public function scheduleWithdraw(string $id, string $cid): Response
    {
        $req = $this->visible($id);
        try {
            ScheduleService::withdraw((int)$req['request_id'], $this->intParam($cid), (int)Auth::user()['user_id']);
            Audit::log('SCHEDULE_WITHDRAW', (string)$req['request_no']);
            $this->flash('success', 'sched.ok_withdrawn');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id'] . '#schedule');
    }

    public function effort(string $id): Response
    {
        $req = $this->visible($id);
        try {
            RequestService::setEffort((int)$req['request_id'], (int)Auth::user()['user_id'], Request::post('effort_hours'));
            Audit::log('REQUEST_EFFORT', (string)$req['request_no']);
            $this->flash('success', 'common.saved');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id']);
    }

    public function submit(string $id): Response
    {
        $req = $this->editable($id);
        return $this->afterSave((int)$req['request_id'], (int)Auth::user()['user_id'], true);
    }

    public function cancel(string $id): Response
    {
        $req = $this->visible($id);
        try {
            WorkflowService::cancel((int)$req['request_id'], (int)Auth::user()['user_id']);
            Audit::log('REQUEST_CANCEL', (string)$req['request_no']);
            $this->flash('success', 'request.canceled');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id']);
    }

    public function comment(string $id): Response
    {
        $req = $this->visible($id);
        try {
            RequestService::addComment((int)$req['request_id'], (int)Auth::user()['user_id'], Request::post('body'));
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id'] . '#timeline');
    }

    public function upload(string $id): Response
    {
        $req = $this->visible($id);
        $uid = (int)Auth::user()['user_id'];
        $step = $this->uploadStep($req, $uid);
        if ($step === null) {
            $this->flash('danger', 'attach.err_forbidden');
        } else {
            $this->storeFiles($req, $step, $uid);
        }
        return $this->redirect('/requests/' . (int)$req['request_id']);
    }

    public function deleteAttachment(string $id, string $aid): Response
    {
        $req = $this->visible($id);
        try {
            AttachmentService::remove($req, $this->intParam($aid), (int)Auth::user()['user_id']);
            $this->flash('success', 'common.deleted');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/requests/' . (int)$req['request_id']);
    }

    public function download(string $aid): Response
    {
        try {
            [$a, $path] = AttachmentService::locate($this->intParam($aid));
        } catch (ProcessError) {
            $this->abort(404);
        }
        $this->visible((string)$a['request_id']);
        Audit::log('ATTACH_DOWNLOAD', (string)$a['attach_id']);
        return Response::download($path, (string)$a['orig_name'], (int)$a['size_byte']);
    }

    /** 서비스×유형 선택 시 적용될 프로세스 흐름 미리보기(JSON). */
    public function preview(): Response
    {
        try {
            $sid = $this->intParam(Request::get('service_id'));
            $type = Request::get('maint_type');
            if (Repo::find('service', ['service_id' => $sid, 'use_yn' => 1]) === null || $type === '') {
                return Response::json(['ok' => false, 'error' => t('request.preview_pick')]);
            }
            $ver = WorkflowService::resolveVersionFor($sid, $type);
        } catch (ProcessError $e) {
            return Response::json(['ok' => false, 'error' => t($e->key)]);
        }
        $master = Repo::find('process_master', ['process_id' => (int)$ver['process_id']]);
        $steps = [];
        foreach (ProcessService::steps((int)$ver['version_id']) as $s) {
            $actor = t('actor.' . $s['actor_type']);
            if ($s['actor_type'] === 'ROLE') {
                $role = Repo::find('role', ['role_cd' => (string)$s['actor_value']]);
                $actor = $role === null ? $actor : master_name('ROLE', (string)$role['role_cd'], (string)$role['role_nm']);
            } elseif ($s['actor_type'] === 'USER') {
                $user = Repo::find('user', ['user_id' => (int)$s['actor_value']]);
                $actor = $user === null ? $actor : (string)$user['user_nm'];
            }
            $steps[] = [
                'no' => (int)$s['step_no'],
                'name' => master_name('STEP', (string)$s['step_id'], (string)$s['step_nm']),
                'type' => t('ptype.' . $s['step_type']),
                'actor' => $actor,
            ];
        }
        return Response::json([
            'ok' => true,
            'process' => master_name('PROCESS', (string)$master['process_cd'], (string)$master['process_nm']),
            'version' => (int)$ver['version_no'],
            'steps' => $steps,
        ]);
    }

    // ------------------------------------------------------------------ 내부

    /** @return array<string,mixed> */
    private function scheduleData(array $req, array $svc, int $uid, bool $isRequester): array
    {
        $rid = (int)$req['request_id'];
        $pending = ScheduleService::pending($rid);
        $perm = Auth::can('SCHEDULE_PROPOSE');
        return [
            'pending' => $pending,
            'pendingBy' => $pending === null ? null : Repo::find('user', ['user_id' => (int)$pending['proposer_id']]),
            'history' => ScheduleService::history($rid),
            'canPropose' => $perm && ScheduleService::canPropose($req, $svc, $uid),
            'canRespond' => $perm && $pending !== null && $isRequester,
            'canWithdraw' => $pending !== null && (int)$pending['proposer_id'] === $uid,
            'reasons' => Repo::list('code', ['group_cd' => ScheduleService::REASON_GROUP, 'use_yn' => 1], 'sort_no, code_cd'),
            'effectiveDue' => ScheduleService::effectiveDue($req),
            'responseDue' => $pending === null ? null : ScheduleService::responseDue($pending),
        ];
    }

    /** @return array<string,string> */
    private function typeNames(): array
    {
        return array_column(Repo::list('code', ['group_cd' => 'MAINT_TYPE']), 'code_nm', 'code_cd');
    }

    /** @return array{0:array<string,mixed>,1:list<string>,2:list<string>} */
    private function ctx(): array
    {
        return [Auth::user(), Auth::roles(), Auth::perms()];
    }

    /** @return array<string,mixed> 열람 권한이 없으면 404 */
    private function visible(string $id): array
    {
        $rid = $this->intParam($id);
        $req = $rid > 0 ? Repo::find('request', ['request_id' => $rid]) : null;
        if ($req === null) {
            $this->abort(404);
        }
        [$user, $roles, $perms] = $this->ctx();
        if (!RequestAccess::canView($rid, $user, $roles, $perms)) {
            $this->abort(404);
        }
        return $req;
    }

    /** @return array<string,mixed> */
    private function editable(string $id): array
    {
        $req = $this->visible($id);
        if ((int)$req['requester_id'] !== (int)Auth::user()['user_id'] || !Auth::can('REQUEST_CREATE')) {
            $this->abort(403);
        }
        if (!in_array((string)$req['status'], [WorkflowService::ST_DRAFT, WorkflowService::ST_RETURNED], true)) {
            $this->abort(403);
        }
        return $req;
    }

    /** @return array<string,string> */
    private function input(): array
    {
        $out = [];
        foreach (['service_id', 'maint_type', 'title', 'priority', 'req_due_date'] as $k) {
            $out[$k] = $this->post($k);
        }
        $out['content_html'] = Request::post('content_html');
        return $out;
    }

    private function form(?array $req, array $old): Response
    {
        return $this->view('requests/form', [
            'req' => $req,
            'old' => $old,
            'services' => Repo::list('service', ['use_yn' => 1], 'service_cd'),
            'types' => array_values(array_filter(
                Repo::list('code', ['group_cd' => 'MAINT_TYPE', 'use_yn' => 1], 'sort_no, code_cd'),
                static fn(array $c): bool => $c['code_cd'] !== WorklogService::TYPE
            )),
            'priorities' => Repo::list('code', ['group_cd' => 'PRIORITY', 'use_yn' => 1], 'sort_no, code_cd'),
            'attachments' => $req === null ? [] : RequestService::attachments((int)$req['request_id']),
            'today' => date('Y-m-d'),
        ]);
    }

    private function afterSave(int $rid, int $uid, bool $submit): Response
    {
        $req = WorkflowService::request($rid);
        $this->storeFiles($req, 1, $uid);
        if ($submit) {
            try {
                WorkflowService::submit($rid, $uid);
                Audit::log('REQUEST_SUBMIT', (string)$req['request_no']);
                $this->flash('success', 'request.submitted');
            } catch (ProcessError $e) {
                $this->flash('danger', $e->key, $e->params);
                return $this->redirect('/requests/' . $rid . '/edit');
            }
        } else {
            $this->flash('success', 'request.saved_draft');
        }
        return $this->redirect('/requests/' . $rid);
    }

    private function uploadStep(array $req, int $uid): ?int
    {
        $step = AttachmentService::uploadStep($req, $uid);
        if ($step === null || WorklogService::isLog($req)) {
            return null;
        }
        $needs = $step === 1 ? 'REQUEST_CREATE' : 'STEP_ACT';
        return Auth::can($needs) ? $step : null;
    }
}
