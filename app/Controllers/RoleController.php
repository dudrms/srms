<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\Seeder;
use App\Services\Translations;

final class RoleController extends Controller
{
    private const LOCKED = 'SYS_ADMIN';

    public function index(): Response
    {
        $roles = Repo::list('role', [], 'role_cd');
        $granted = [];
        foreach (Repo::list('role_permission') as $rp) {
            $granted[(string)$rp['role_cd']][(string)$rp['perm_cd']] = true;
        }
        $names = [];
        $used = [];
        foreach ($roles as $r) {
            $cd = (string)$r['role_cd'];
            $names[$cd] = ['ko' => (string)$r['role_nm']] + Translations::get('ROLE', $cd);
            $used[$cd] = Repo::count('user_role', ['role_cd' => $cd]);
        }
        return $this->view('admin/roles/index', [
            'roles' => $roles,
            'perms' => array_keys(Seeder::PERMISSIONS),
            'granted' => $granted,
            'names' => $names,
            'used' => $used,
            'locked' => self::LOCKED,
            'systemRoles' => array_keys(Seeder::ROLES),
        ]);
    }

    public function save(): Response
    {
        $perms = array_keys(Seeder::PERMISSIONS);
        $post = $_POST['perm'] ?? [];
        $names = $_POST['name'] ?? [];
        foreach (Repo::list('role') as $r) {
            $cd = (string)$r['role_cd'];
            $nm = is_array($names[$cd] ?? null) ? $names[$cd] : [];
            $ko = trim((string)($nm['ko'] ?? ''));
            if ($ko !== '') {
                Repo::update('role', ['role_cd' => $cd], ['role_nm' => mb_substr($ko, 0, 100)]);
            }
            $tr = [];
            foreach (Translations::LANGS as $lang) {
                $tr[$lang] = mb_substr((string)($nm[$lang] ?? ''), 0, 100);
            }
            Translations::save('ROLE', $cd, $tr);
            if ($cd === self::LOCKED) {
                continue;
            }
            $checked = is_array($post[$cd] ?? null) ? array_keys($post[$cd]) : [];
            Repo::delete('role_permission', ['role_cd' => $cd]);
            foreach (array_intersect($perms, array_map('strval', $checked)) as $p) {
                Repo::insert('role_permission', ['role_cd' => $cd, 'perm_cd' => $p]);
            }
        }
        Audit::log('ROLE_PERMISSION_SAVE');
        $this->flash('success', 'role.saved');
        return $this->redirect('/admin/roles');
    }

    public function store(): Response
    {
        $cd = strtoupper($this->post('role_cd'));
        $name = $this->post('role_nm');
        if (preg_match('/^[A-Z][A-Z0-9_]{1,29}$/', $cd) !== 1 || $name === '') {
            $this->flash('danger', 'role.err_code');
        } elseif (Repo::find('role', ['role_cd' => $cd]) !== null) {
            $this->flash('danger', 'role.err_duplicate');
        } else {
            Repo::insert('role', ['role_cd' => $cd, 'role_nm' => mb_substr($name, 0, 100)]);
            Audit::log('ROLE_CREATE', $cd);
            $this->flash('success', 'common.saved');
        }
        return $this->redirect('/admin/roles');
    }

    public function delete(string $code): Response
    {
        $role = Repo::find('role', ['role_cd' => $code]) ?? $this->abort(404);
        if (array_key_exists($code, Seeder::ROLES) || Repo::count('user_role', ['role_cd' => $code]) > 0) {
            $this->flash('danger', 'role.in_use');
        } else {
            Repo::delete('role_permission', ['role_cd' => $code]);
            Repo::delete('role', ['role_cd' => $code]);
            Translations::remove('ROLE', $code);
            Audit::log('ROLE_DELETE', (string)$role['role_cd']);
            $this->flash('success', 'common.deleted');
        }
        return $this->redirect('/admin/roles');
    }
}
