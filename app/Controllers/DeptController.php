<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;

final class DeptController extends Controller
{
    public function index(): Response
    {
        $rows = Repo::query(
            'SELECT d.dept_id, d.dept_nm, d.is_it, d.use_yn,
                    (SELECT COUNT(*) FROM user u WHERE u.company_cd = d.company_cd AND u.dept_id = d.dept_id) AS user_cnt,
                    (SELECT COUNT(*) FROM service s WHERE s.company_cd = d.company_cd AND s.biz_dept_id = d.dept_id) AS service_cnt
               FROM dept d WHERE d.company_cd = :company_cd ORDER BY d.dept_nm'
        );
        return $this->view('admin/depts/index', ['rows' => $rows]);
    }

    public function store(): Response
    {
        $name = $this->post('dept_nm');
        if ($name === '' || mb_strlen($name) > 100) {
            $this->flash('danger', 'dept.err_name');
            return $this->redirect('/admin/depts');
        }
        Repo::insert('dept', ['dept_nm' => $name, 'is_it' => Request::post('is_it') === '1' ? 1 : 0, 'use_yn' => 1]);
        Audit::log('DEPT_CREATE', $name);
        $this->flash('success', 'common.saved');
        return $this->redirect('/admin/depts');
    }

    public function update(string $id): Response
    {
        $dept = Repo::find('dept', ['dept_id' => $this->intParam($id)]) ?? $this->abort(404);
        $name = $this->post('dept_nm');
        if ($name === '' || mb_strlen($name) > 100) {
            $this->flash('danger', 'dept.err_name');
            return $this->redirect('/admin/depts');
        }
        Repo::update('dept', ['dept_id' => $dept['dept_id']], [
            'dept_nm' => $name,
            'is_it' => Request::post('is_it') === '1' ? 1 : 0,
            'use_yn' => Request::post('use_yn') === '1' ? 1 : 0,
        ]);
        Audit::log('DEPT_UPDATE', (string)$dept['dept_id']);
        $this->flash('success', 'common.saved');
        return $this->redirect('/admin/depts');
    }

    public function delete(string $id): Response
    {
        $dept = Repo::find('dept', ['dept_id' => $this->intParam($id)]) ?? $this->abort(404);
        $used = Repo::count('user', ['dept_id' => $dept['dept_id']]) + Repo::count('service', ['biz_dept_id' => $dept['dept_id']]);
        if ($used > 0) {
            $this->flash('danger', 'dept.in_use');
        } else {
            Repo::delete('dept', ['dept_id' => $dept['dept_id']]);
            Audit::log('DEPT_DELETE', (string)$dept['dept_nm']);
            $this->flash('success', 'common.deleted');
        }
        return $this->redirect('/admin/depts');
    }
}
