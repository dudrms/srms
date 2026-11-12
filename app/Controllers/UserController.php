<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\I18n;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\Users;

final class UserController extends Controller
{
    public function index(): Response
    {
        $q = trim(Request::get('q'));
        $sql = 'SELECT u.user_id, u.login_id, u.user_nm, u.email, u.use_yn, u.lang, u.last_login, d.dept_nm,
                       (SELECT GROUP_CONCAT(ur.role_cd, \',\') FROM user_role ur
                         WHERE ur.company_cd = u.company_cd AND ur.user_id = u.user_id) AS roles
                  FROM user u LEFT JOIN dept d ON d.company_cd = u.company_cd AND d.dept_id = u.dept_id
                 WHERE u.company_cd = :company_cd';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (u.login_id LIKE :q OR u.user_nm LIKE :q OR u.email LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $rows = Repo::query($sql . ' ORDER BY u.login_id', $params);
        return $this->view('admin/users/index', ['rows' => $rows, 'q' => $q, 'roleMap' => $this->roleMap()]);
    }

    public function create(): Response
    {
        return $this->form(null, ['lang' => I18n::defaultLang(), 'roles' => ['REQUESTER']]);
    }

    public function store(): Response
    {
        $old = $this->collect();
        try {
            Users::create(
                $old['login_id'],
                $old['user_nm'],
                $old['email'],
                $old['dept_id'],
                $old['lang'],
                Request::post('password'),
                $old['roles']
            );
        } catch (\InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->form(null, $old);
        }
        Audit::log('USER_CREATE', $old['login_id']);
        $this->flash('success', 'user.created');
        return $this->redirect('/admin/users');
    }

    public function edit(string $id): Response
    {
        $user = $this->load($id);
        $roles = array_column(Repo::list('user_role', ['user_id' => $user['user_id']]), 'role_cd');
        return $this->form($user, $user + ['roles' => $roles]);
    }

    public function update(string $id): Response
    {
        $user = $this->load($id);
        $old = $this->collect() + [];
        $old['login_id'] = (string)$user['login_id'];
        $old['use_yn'] = Request::post('use_yn') === '1' ? 1 : 0;
        try {
            Users::validateProfile($old['login_id'], $old['user_nm'], $old['email'], $old['lang']);
            if ($old['dept_id'] !== null && Repo::find('dept', ['dept_id' => $old['dept_id']]) === null) {
                throw new \InvalidArgumentException('user.err_dept');
            }
            $this->guardAdmins($user, $old);
        } catch (\InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->form($user, $old);
        }
        Repo::update('user', ['user_id' => $user['user_id']], [
            'user_nm' => $old['user_nm'],
            'email' => $old['email'],
            'dept_id' => $old['dept_id'],
            'lang' => $old['lang'],
            'use_yn' => $old['use_yn'],
        ]);
        Users::setRoles((int)$user['user_id'], $old['roles']);
        Audit::log('USER_UPDATE', (string)$user['login_id']);
        $this->flash('success', 'user.updated');
        return $this->redirect('/admin/users');
    }

    public function resetPassword(string $id): Response
    {
        $user = $this->load($id);
        $pw = Request::post('password');
        if (($err = Auth::passwordError($pw, (string)$user['login_id'])) !== null) {
            $this->flash('danger', $err);
        } else {
            Repo::update('user', ['user_id' => $user['user_id']], ['pw_hash' => Auth::hashPassword($pw)]);
            Repo::delete('login_fail', ['login_id' => $user['login_id']]);
            Audit::log('PASSWORD_RESET', (string)$user['login_id']);
            $this->flash('success', 'user.pw_reset');
        }
        return $this->redirect('/admin/users/' . (int)$user['user_id'] . '/edit');
    }

    /** @return array<string,mixed> */
    private function load(string $id): array
    {
        $user = Repo::find('user', ['user_id' => $this->intParam($id)]);
        return $user ?? $this->abort(404);
    }

    /** @return array<string,mixed> */
    private function collect(): array
    {
        $dept = $this->intParam(Request::post('dept_id'));
        return [
            'login_id' => $this->post('login_id'),
            'user_nm' => $this->post('user_nm'),
            'email' => $this->post('email'),
            'dept_id' => $dept > 0 ? $dept : null,
            'lang' => $this->post('lang', 'ko'),
            'roles' => Request::postList('roles'),
            'use_yn' => 1,
        ];
    }

    /** 자기 자신 비활성/관리자 롤 제거 및 마지막 관리자 소실을 막는다. */
    private function guardAdmins(array $user, array $new): void
    {
        $wasAdmin = (int)$user['use_yn'] === 1 && Repo::find('user_role', ['user_id' => $user['user_id'], 'role_cd' => 'SYS_ADMIN']) !== null;
        $stillAdmin = (int)$new['use_yn'] === 1 && in_array('SYS_ADMIN', $new['roles'], true);
        if (!$wasAdmin || $stillAdmin) {
            return;
        }
        if ((int)$user['user_id'] === (int)Auth::user()['user_id']) {
            throw new \InvalidArgumentException('user.err_self');
        }
        $others = (int)Repo::queryVal(
            'SELECT COUNT(*) FROM user u JOIN user_role ur ON ur.company_cd = u.company_cd AND ur.user_id = u.user_id
              WHERE u.company_cd = :company_cd AND u.use_yn = 1 AND ur.role_cd = \'SYS_ADMIN\' AND u.user_id <> :id',
            ['id' => $user['user_id']]
        );
        if ($others === 0) {
            throw new \InvalidArgumentException('user.err_last_admin');
        }
    }

    private function form(?array $user, array $old): Response
    {
        return $this->view('admin/users/form', [
            'user' => $user,
            'old' => $old,
            'depts' => Repo::list('dept', ['use_yn' => 1], 'dept_nm'),
            'roleMap' => $this->roleMap(),
            'langs' => I18n::enabled(),
        ]);
    }

    /** @return array<string,string> 롤코드 => 표시명 */
    private function roleMap(): array
    {
        $m = [];
        foreach (Repo::list('role', [], 'role_cd') as $r) {
            $m[(string)$r['role_cd']] = master_name('ROLE', (string)$r['role_cd'], (string)$r['role_nm']);
        }
        return $m;
    }
}
