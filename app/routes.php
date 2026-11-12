<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CodeController;
use App\Controllers\DeptController;
use App\Controllers\HomeController;
use App\Controllers\MailController;
use App\Controllers\ProcessController;
use App\Controllers\ProcessMapController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Controllers\RequestController;
use App\Controllers\RoleController;
use App\Controllers\ServiceController;
use App\Controllers\UserController;
use App\Controllers\WorklogController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin'], ['public' => true]);
    $r->post('/login', [AuthController::class, 'login'], ['public' => true]);
    $r->get('/sso/login', [AuthController::class, 'ssoLogin'], ['public' => true]);
    $r->get('/sso/callback', [AuthController::class, 'ssoCallback'], ['public' => true]);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->post('/lang', [AuthController::class, 'setLang'], ['public' => true]);
    $r->post('/theme', [AuthController::class, 'setTheme'], ['public' => true]);

    $r->get('/', [HomeController::class, 'index']);
    $r->get('/profile', [ProfileController::class, 'edit']);
    $r->post('/profile', [ProfileController::class, 'update']);
    $r->post('/profile/password', [ProfileController::class, 'password']);

    $own = ['perm' => 'REQUEST_VIEW_OWN'];
    $mk = ['perm' => 'REQUEST_CREATE'];
    $act = ['perm' => 'STEP_ACT'];
    $r->get('/requests', [RequestController::class, 'index'], $own);
    $r->get('/inbox', [RequestController::class, 'inbox'], $act);
    $rep = ['perm' => 'REPORT_VIEW'];
    $r->get('/reports', [ReportController::class, 'index'], $rep);
    $r->get('/reports/export/{section}', [ReportController::class, 'export'], $rep);
    $r->get('/requests/new', [RequestController::class, 'create'], $mk);
    $r->get('/requests/preview', [RequestController::class, 'preview'], $mk);
    $r->post('/requests', [RequestController::class, 'store'], $mk);
    $r->get('/requests/{id}', [RequestController::class, 'show'], $own);
    $r->get('/requests/{id}/edit', [RequestController::class, 'edit'], $mk);
    $r->post('/requests/{id}', [RequestController::class, 'update'], $mk);
    $r->post('/requests/{id}/submit', [RequestController::class, 'submit'], $mk);
    $r->post('/requests/{id}/act', [RequestController::class, 'act'], $act);
    $r->post('/requests/{id}/cancel', [RequestController::class, 'cancel'], $own);
    $r->post('/requests/{id}/effort', [RequestController::class, 'effort'], $act);
    $split = ['perm' => 'REQUEST_SPLIT'];
    $r->post('/requests/{id}/assignee', [RequestController::class, 'assign'], $split);
    $r->post('/requests/{id}/split', [RequestController::class, 'split'], $split);
    $sch = ['perm' => 'SCHEDULE_PROPOSE'];
    $r->post('/requests/{id}/schedule', [RequestController::class, 'schedule'], $sch);
    $r->post('/requests/{id}/schedule/{cid}/respond', [RequestController::class, 'scheduleRespond'], $sch);
    $r->post('/requests/{id}/schedule/{cid}/withdraw', [RequestController::class, 'scheduleWithdraw'], $sch);
    $r->post('/requests/{id}/comments', [RequestController::class, 'comment'], $own);
    $r->post('/requests/{id}/attachments', [RequestController::class, 'upload'], $own);
    $r->post('/requests/{id}/attachments/{aid}/delete', [RequestController::class, 'deleteAttachment'], $own);
    $r->get('/attachments/{aid}', [RequestController::class, 'download'], $own);

    $wl = ['perm' => 'WORKLOG_REG'];
    $r->get('/logs', [WorklogController::class, 'index'], $wl);
    $r->get('/logs/new', [WorklogController::class, 'create'], $wl);
    $r->post('/logs', [WorklogController::class, 'store'], $wl);
    $r->get('/logs/{id}/edit', [WorklogController::class, 'edit'], $wl);
    $r->post('/logs/{id}', [WorklogController::class, 'update'], $wl);
    $r->post('/logs/{id}/delete', [WorklogController::class, 'delete'], $wl);
    $r->post('/logs/{id}/attachments/{aid}/delete', [WorklogController::class, 'deleteAttachment'], $wl);

    $u = ['perm' => 'USER_EDIT'];
    $r->get('/admin/users', [UserController::class, 'index'], $u);
    $r->get('/admin/users/new', [UserController::class, 'create'], $u);
    $r->post('/admin/users', [UserController::class, 'store'], $u);
    $r->get('/admin/users/{id}/edit', [UserController::class, 'edit'], $u);
    $r->post('/admin/users/{id}', [UserController::class, 'update'], $u);
    $r->post('/admin/users/{id}/password', [UserController::class, 'resetPassword'], $u);

    $r->get('/admin/depts', [DeptController::class, 'index'], $u);
    $r->post('/admin/depts', [DeptController::class, 'store'], $u);
    $r->post('/admin/depts/{id}', [DeptController::class, 'update'], $u);
    $r->post('/admin/depts/{id}/delete', [DeptController::class, 'delete'], $u);

    $r->get('/admin/roles', [RoleController::class, 'index'], $u);
    $r->post('/admin/roles', [RoleController::class, 'store'], $u);
    $r->post('/admin/roles/save', [RoleController::class, 'save'], $u);
    $r->post('/admin/roles/{code}/delete', [RoleController::class, 'delete'], $u);

    $c = ['perm' => 'CODE_EDIT'];
    $r->get('/admin/codes', [CodeController::class, 'groups'], $c);
    $r->post('/admin/codes', [CodeController::class, 'storeGroup'], $c);
    $r->get('/admin/codes/{group}', [CodeController::class, 'items'], $c);
    $r->post('/admin/codes/{group}/items', [CodeController::class, 'storeItem'], $c);
    $r->post('/admin/codes/{group}/items/{code}', [CodeController::class, 'updateItem'], $c);

    $s = ['perm' => 'SERVICE_EDIT'];
    $r->get('/admin/services', [ServiceController::class, 'index'], $s);
    $r->get('/admin/services/new', [ServiceController::class, 'create'], $s);
    $r->post('/admin/services', [ServiceController::class, 'store'], $s);
    $r->get('/admin/services/{id}/edit', [ServiceController::class, 'edit'], $s);
    $r->post('/admin/services/{id}', [ServiceController::class, 'update'], $s);

    $p = ['perm' => 'PROCESS_EDIT'];
    $r->get('/admin/processes', [ProcessController::class, 'index'], $p);
    $r->post('/admin/processes', [ProcessController::class, 'store'], $p);
    $r->get('/admin/processes/{pid}', [ProcessController::class, 'show'], $p);
    $r->post('/admin/processes/{pid}', [ProcessController::class, 'update'], $p);
    $r->post('/admin/processes/{pid}/delete', [ProcessController::class, 'delete'], $p);
    $r->post('/admin/processes/{pid}/versions', [ProcessController::class, 'newVersion'], $p);
    $r->get('/admin/processes/{pid}/versions/{vid}', [ProcessController::class, 'version'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/dates', [ProcessController::class, 'setDates'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/activate', [ProcessController::class, 'activate'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/retire', [ProcessController::class, 'retire'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/delete', [ProcessController::class, 'deleteVersion'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/steps', [ProcessController::class, 'addStep'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/steps/order', [ProcessController::class, 'reorder'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/steps/{sid}', [ProcessController::class, 'updateStep'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/steps/{sid}/delete', [ProcessController::class, 'deleteStep'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/steps/{sid}/cc', [ProcessController::class, 'addCc'], $p);
    $r->post('/admin/processes/{pid}/versions/{vid}/steps/{sid}/cc/{cc}/delete', [ProcessController::class, 'deleteCc'], $p);
    $r->get('/admin/process-map', [ProcessMapController::class, 'index'], $p);
    $r->post('/admin/process-map', [ProcessMapController::class, 'save'], $p);

    $r->get('/admin/mail-templates', [MailController::class, 'templates'], $p);
    $r->post('/admin/mail-templates', [MailController::class, 'createTemplate'], $p);
    $r->get('/admin/mail-templates/{code}', [MailController::class, 'editTemplate'], $p);
    $r->post('/admin/mail-templates/{code}', [MailController::class, 'saveTemplate'], $p);
    $r->post('/admin/mail-templates/{code}/reset', [MailController::class, 'resetTemplate'], $p);
    $r->post('/admin/mail-templates/{code}/delete', [MailController::class, 'deleteTemplate'], $p);
    $r->get('/admin/mail-log', [MailController::class, 'log'], $p);
    $r->post('/admin/mail-log/{id}/requeue', [MailController::class, 'requeue'], $p);
};
