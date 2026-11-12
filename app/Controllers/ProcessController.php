<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\ProcessError;
use App\Services\ProcessService;
use App\Services\Translations;

final class ProcessController extends Controller
{
    public function index(): Response
    {
        $rows = [];
        foreach (Repo::list('process_master', [], 'process_cd') as $p) {
            $pid = (int)$p['process_id'];
            $rows[] = $p + [
                'current' => ProcessService::resolveVersion($pid),
                'version_cnt' => Repo::count('process_version', ['process_id' => $pid]),
                'mapped_cnt' => Repo::count('process_map', ['process_id' => $pid]),
            ];
        }
        return $this->view('admin/processes/index', ['rows' => $rows]);
    }

    public function store(): Response
    {
        try {
            $code = strtoupper($this->post('process_cd'));
            $pid = ProcessService::createProcess($code, $this->post('process_nm'), $this->post('description'), Translations::collect($_POST));
        } catch (ProcessError $e) {
            $this->flashError($e);
            return $this->redirect('/admin/processes');
        }
        Audit::log('PROCESS_CREATE', $code);
        $this->flash('success', 'common.saved');
        return $this->redirect('/admin/processes/' . $pid);
    }

    public function show(string $pid): Response
    {
        $p = $this->process($pid);
        return $this->view('admin/processes/show', [
            'p' => $p,
            'tr' => Translations::get('PROCESS', (string)$p['process_cd']),
            'versions' => ProcessService::versions((int)$p['process_id']),
        ]);
    }

    public function update(string $pid): Response
    {
        $p = $this->process($pid);
        return $this->guard('/admin/processes/' . (int)$p['process_id'], 'common.saved', function () use ($p): void {
            ProcessService::updateProcess((int)$p['process_id'], $this->post('process_nm'), $this->post('description'), Translations::collect($_POST));
            Audit::log('PROCESS_UPDATE', (string)$p['process_cd']);
        });
    }

    public function delete(string $pid): Response
    {
        $p = $this->process($pid);
        $r = $this->guard('/admin/processes', 'common.deleted', function () use ($p): void {
            ProcessService::deleteProcess((int)$p['process_id']);
            Audit::log('PROCESS_DELETE', (string)$p['process_cd']);
        });
        return $r;
    }

    public function newVersion(string $pid): Response
    {
        $p = $this->process($pid);
        $from = $this->intParam(Request::post('from_version'));
        $vid = 0;
        $r = $this->guard('/admin/processes/' . (int)$p['process_id'], 'process.version_created', function () use ($p, $from, &$vid): void {
            $vid = ProcessService::createVersion((int)$p['process_id'], $from > 0 ? $from : null);
            Audit::log('PROCESS_VERSION_CREATE', $p['process_cd'] . ' v' . $vid);
        });
        return $vid > 0 ? $this->redirect('/admin/processes/' . (int)$p['process_id'] . '/versions/' . $vid) : $r;
    }

    public function version(string $pid, string $vid): Response
    {
        $p = $this->process($pid);
        $v = ProcessService::version((int)$p['process_id'], $this->intParam($vid)) ?? $this->abort(404);
        $steps = ProcessService::steps((int)$v['version_id']);
        $stepTr = [];
        foreach ($steps as $s) {
            $stepTr[(int)$s['step_id']] = Translations::get('STEP', (string)$s['step_id']);
        }
        return $this->view('admin/processes/version', [
            'p' => $p,
            'v' => $v,
            'steps' => $steps,
            'stepTr' => $stepTr,
            'ccs' => ProcessService::ccsByStep((int)$v['version_id']),
            'problems' => $v['state'] === 'DRAFT' ? ProcessService::validateVersion((int)$v['version_id']) : [],
            'roles' => Repo::list('role', [], 'role_cd'),
            'users' => Repo::list('user', ['use_yn' => 1], 'user_nm'),
            'depts' => Repo::list('dept', ['use_yn' => 1], 'dept_nm'),
        ]);
    }

    public function setDates(string $pid, string $vid): Response
    {
        $p = $this->process($pid);
        return $this->guard($this->versionUrl($p, $vid), 'common.saved', function () use ($p, $vid): void {
            ProcessService::setDates((int)$p['process_id'], $this->intParam($vid), $this->post('valid_from'), $this->post('valid_to'));
        });
    }

    public function activate(string $pid, string $vid): Response
    {
        $p = $this->process($pid);
        return $this->guard($this->versionUrl($p, $vid), 'process.activated', function () use ($p, $vid): void {
            ProcessService::activate((int)$p['process_id'], $this->intParam($vid), $this->post('valid_from'), $this->post('valid_to'));
            Audit::log('PROCESS_VERSION_ACTIVATE', $p['process_cd'] . ' #' . $vid);
        });
    }

    public function retire(string $pid, string $vid): Response
    {
        $p = $this->process($pid);
        return $this->guard('/admin/processes/' . (int)$p['process_id'], 'process.retired', function () use ($p, $vid): void {
            ProcessService::retire((int)$p['process_id'], $this->intParam($vid));
            Audit::log('PROCESS_VERSION_RETIRE', $p['process_cd'] . ' #' . $vid);
        });
    }

    public function deleteVersion(string $pid, string $vid): Response
    {
        $p = $this->process($pid);
        return $this->guard('/admin/processes/' . (int)$p['process_id'], 'common.deleted', function () use ($p, $vid): void {
            ProcessService::deleteVersion((int)$p['process_id'], $this->intParam($vid));
            Audit::log('PROCESS_VERSION_DELETE', $p['process_cd'] . ' #' . $vid);
        });
    }

    public function addStep(string $pid, string $vid): Response
    {
        $p = $this->process($pid);
        return $this->guard($this->versionUrl($p, $vid), 'common.saved', function () use ($p, $vid): void {
            ProcessService::addStep((int)$p['process_id'], $this->intParam($vid), $this->stepInput());
        });
    }

    public function updateStep(string $pid, string $vid, string $sid): Response
    {
        $p = $this->process($pid);
        return $this->guard($this->versionUrl($p, $vid), 'common.saved', function () use ($p, $vid, $sid): void {
            ProcessService::updateStep((int)$p['process_id'], $this->intParam($vid), $this->intParam($sid), $this->stepInput());
        });
    }

    public function deleteStep(string $pid, string $vid, string $sid): Response
    {
        $p = $this->process($pid);
        return $this->guard($this->versionUrl($p, $vid), 'common.deleted', function () use ($p, $vid, $sid): void {
            ProcessService::deleteStep((int)$p['process_id'], $this->intParam($vid), $this->intParam($sid));
        });
    }

    public function reorder(string $pid, string $vid): Response
    {
        $p = $this->process($pid);
        $ids = array_values(array_filter(array_map('intval', explode(',', $this->post('ids'))), static fn(int $i): bool => $i > 0));
        try {
            ProcessService::reorder((int)$p['process_id'], $this->intParam($vid), $ids);
        } catch (ProcessError $e) {
            return Response::json(['ok' => false, 'error' => t($e->key, $e->params)], 422);
        }
        return Response::json(['ok' => true]);
    }

    public function addCc(string $pid, string $vid, string $sid): Response
    {
        $p = $this->process($pid);
        return $this->guard($this->versionUrl($p, $vid), 'common.saved', function () use ($p, $vid, $sid): void {
            $type = $this->post('cc_type');
            $value = match ($type) {
                'ROLE' => $this->post('cc_role'),
                'USER' => $this->post('cc_user'),
                'DEPT' => $this->post('cc_dept'),
                default => '',
            };
            ProcessService::addCc(
                (int)$p['process_id'], $this->intParam($vid), $this->intParam($sid), $type, $value, $this->post('notify_on', 'NONE')
            );
        });
    }

    public function deleteCc(string $pid, string $vid, string $sid, string $cc): Response
    {
        $p = $this->process($pid);
        return $this->guard($this->versionUrl($p, $vid), 'common.deleted', function () use ($p, $vid, $sid, $cc): void {
            ProcessService::deleteCc((int)$p['process_id'], $this->intParam($vid), $this->intParam($sid), $this->intParam($cc));
        });
    }

    /** @return array<string,mixed> */
    private function process(string $pid): array
    {
        return Repo::find('process_master', ['process_id' => $this->intParam($pid)]) ?? $this->abort(404);
    }

    private function versionUrl(array $p, string $vid): string
    {
        return '/admin/processes/' . (int)$p['process_id'] . '/versions/' . $this->intParam($vid);
    }

    /** @return array<string,string> */
    private function stepInput(): array
    {
        $out = [];
        foreach (array_merge(['step_nm'], array_map(static fn(string $l): string => 'name_' . $l, Translations::LANGS), ['step_type', 'actor_type', 'approval_mode', 'allow_reject',
            'reject_to_step', 'due_days', 'notify_tmpl', 'attach_mode', 'attach_max_cnt', 'attach_max_mb', 'attach_exts']) as $k) {
            $out[$k] = $this->post($k);
        }
        $out['actor_value'] = match ($out['actor_type']) {
            'ROLE' => $this->post('actor_role'),
            'USER' => $this->post('actor_user'),
            default => '',
        };
        return $out;
    }

    private function guard(string $redirect, string $okKey, callable $fn): Response
    {
        try {
            $fn();
            $this->flash('success', $okKey);
        } catch (ProcessError $e) {
            $this->flashError($e);
        }
        return $this->redirect($redirect);
    }

    private function flashError(ProcessError $e): void
    {
        if ($e->key === 'process.err_invalid') {
            $this->flash('danger', 'process.err_invalid');
            foreach ($e->params['errors'] as [$key, $params]) {
                $this->flash('danger', $key, $params);
            }
            return;
        }
        $this->flash('danger', $e->key, $e->params);
    }
}
