<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Db;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActorResolver;
use App\Services\Translations;

final class ServiceController extends Controller
{
    private const CODE_RE = '/^[A-Za-z0-9_-]{2,30}$/';

    public function index(): Response
    {
        $rows = Repo::query(
            'SELECT s.service_id, s.service_cd, s.service_nm, s.use_yn, s.biz_dept_id, d.dept_nm, u.user_nm AS owner_nm,
                    (SELECT GROUP_CONCAT(bu.user_nm, \', \') FROM service_biz_it b
                       JOIN user bu ON bu.company_cd = b.company_cd AND bu.user_id = b.user_id
                      WHERE b.company_cd = s.company_cd AND b.service_id = s.service_id) AS biz_names,
                    (SELECT COUNT(*) FROM service_biz_it b
                      WHERE b.company_cd = s.company_cd AND b.service_id = s.service_id) AS biz_cnt,
                    (SELECT COUNT(DISTINCT b.user_id) FROM service_biz_it b
                       JOIN user bu ON bu.company_cd = b.company_cd AND bu.user_id = b.user_id AND bu.use_yn = 1 AND bu.dept_id = s.biz_dept_id
                       JOIN user_role br ON br.company_cd = bu.company_cd AND br.user_id = bu.user_id AND br.role_cd = \'BIZ_IT\'
                      WHERE b.company_cd = s.company_cd AND b.service_id = s.service_id) AS biz_ok
               FROM service s
               LEFT JOIN dept d ON d.company_cd = s.company_cd AND d.dept_id = s.biz_dept_id
               LEFT JOIN user u ON u.company_cd = s.company_cd AND u.user_id = s.it_owner_id
              WHERE s.company_cd = :company_cd ORDER BY s.service_cd'
        );
        return $this->view('admin/services/index', ['rows' => $rows]);
    }

    public function create(): Response
    {
        return $this->form(null, ['use_yn' => 1]);
    }

    public function store(): Response
    {
        $old = $this->collect();
        try {
            $this->validate($old, true);
        } catch (\InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->form(null, $old);
        }
        Db::tx(function () use ($old): void {
            $id = Repo::insert('service', [
                'service_cd' => $old['service_cd'],
                'service_nm' => $old['service_nm'],
                'biz_dept_id' => $old['biz_dept_id'],
                'it_owner_id' => $old['it_owner_id'],
                'use_yn' => 1,
            ]);
            $this->syncBizIt($id, $old['biz_it_ids']);
        });
        Translations::save('SERVICE', $old['service_cd'], Translations::collect($old));
        Audit::log('SERVICE_CREATE', $old['service_cd']);
        $this->flash('success', 'common.saved');
        return $this->redirect('/admin/services');
    }

    public function edit(string $id): Response
    {
        $s = $this->load($id);
        $tr = Translations::get('SERVICE', (string)$s['service_cd']);
        $names = [];
        foreach (Translations::LANGS as $l) {
            $names['name_' . $l] = $tr[$l] ?? '';
        }
        return $this->form($s, $s + $names + ['biz_it_ids' => ActorResolver::designatedBizOwners((int)$s['service_id'])]);
    }

    public function update(string $id): Response
    {
        $s = $this->load($id);
        $old = $this->collect();
        $old['service_cd'] = (string)$s['service_cd'];
        $old['use_yn'] = Request::post('use_yn') === '1' ? 1 : 0;
        try {
            $this->validate($old, false);
        } catch (\InvalidArgumentException $e) {
            $this->flash('danger', $e->getMessage());
            return $this->form($s, $old);
        }
        Db::tx(function () use ($s, $old): void {
            Repo::update('service', ['service_id' => $s['service_id']], [
                'service_nm' => $old['service_nm'],
                'biz_dept_id' => $old['biz_dept_id'],
                'it_owner_id' => $old['it_owner_id'],
                'use_yn' => $old['use_yn'],
            ]);
            $this->syncBizIt((int)$s['service_id'], $old['biz_it_ids']);
        });
        Translations::save('SERVICE', $old['service_cd'], Translations::collect($old));
        Audit::log('SERVICE_UPDATE', $old['service_cd']);
        $this->flash('success', 'common.saved');
        return $this->redirect('/admin/services');
    }

    /** @return array<string,mixed> */
    private function load(string $id): array
    {
        return Repo::find('service', ['service_id' => $this->intParam($id)]) ?? $this->abort(404);
    }

    /** @return array<string,mixed> */
    private function collect(): array
    {
        $dept = $this->intParam(Request::post('biz_dept_id'));
        $owner = $this->intParam(Request::post('it_owner_id'));
        $bizIt = [];
        $raw = $_POST['biz_it_ids'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $v) {
            $id = $this->intParam(is_scalar($v) ? (string)$v : '');
            if ($id > 0) {
                $bizIt[$id] = $id;
            }
        }
        $names = [];
        foreach (Translations::collect($_POST) as $l => $text) {
            $names['name_' . $l] = $text;
        }
        return [
            'service_cd' => $this->post('service_cd'),
            'service_nm' => mb_substr($this->post('service_nm'), 0, 100),
            'biz_dept_id' => $dept > 0 ? $dept : null,
            'biz_it_ids' => array_values($bizIt),
            'it_owner_id' => $owner > 0 ? $owner : null,
            'use_yn' => 1,
        ] + $names;
    }

    private function validate(array $d, bool $creating): void
    {
        if ($creating && preg_match(self::CODE_RE, $d['service_cd']) !== 1) {
            throw new \InvalidArgumentException('service.err_code');
        }
        if ($d['service_nm'] === '') {
            throw new \InvalidArgumentException('service.err_name');
        }
        if ($creating && Repo::find('service', ['service_cd' => $d['service_cd']]) !== null) {
            throw new \InvalidArgumentException('service.err_duplicate');
        }
        if ($d['biz_dept_id'] !== null && Repo::find('dept', ['dept_id' => $d['biz_dept_id']]) === null) {
            throw new \InvalidArgumentException('service.err_ref');
        }
        if ($d['it_owner_id'] !== null && Repo::find('user', ['user_id' => $d['it_owner_id']]) === null) {
            throw new \InvalidArgumentException('service.err_ref');
        }
        // 현업 IT 담당자: 주관부서가 있어야 하고, 활성 BIZ_IT 사용자이며 주관부서 소속이어야 한다.
        foreach ($d['biz_it_ids'] as $id) {
            $u = Repo::find('user', ['user_id' => $id, 'use_yn' => 1]);
            if ($d['biz_dept_id'] === null || $u === null || $u['dept_id'] === null || (int)$u['dept_id'] !== (int)$d['biz_dept_id']
                || !in_array('BIZ_IT', ActorResolver::roleCodes($id), true)) {
                throw new \InvalidArgumentException('service.err_biz_it');
            }
        }
    }

    /** @param list<int> $ids */
    private function syncBizIt(int $serviceId, array $ids): void
    {
        Repo::delete('service_biz_it', ['service_id' => $serviceId]);
        foreach ($ids as $id) {
            Repo::insert('service_biz_it', ['service_id' => $serviceId, 'user_id' => $id]);
        }
    }

    private function form(?array $service, array $old): Response
    {
        $bizUsers = Repo::query(
            'SELECT DISTINCT u.user_id, u.user_nm, u.login_id, u.dept_id, d.dept_nm
               FROM user u JOIN user_role ur ON ur.company_cd = u.company_cd AND ur.user_id = u.user_id AND ur.role_cd = \'BIZ_IT\'
               JOIN dept d ON d.company_cd = u.company_cd AND d.dept_id = u.dept_id
              WHERE u.company_cd = :company_cd AND u.use_yn = 1 ORDER BY d.dept_nm, u.user_nm'
        );
        return $this->view('admin/services/form', [
            'service' => $service,
            'old' => $old,
            'depts' => Repo::list('dept', ['use_yn' => 1], 'dept_nm'),
            'users' => Repo::list('user', ['use_yn' => 1], 'user_nm'),
            'bizUsers' => $bizUsers,
        ]);
    }
}
