<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Repo;
use App\Core\Request;
use App\Core\Response;
use App\Services\Translations;

final class CodeController extends Controller
{
    private const CODE_RE = '/^[A-Z][A-Z0-9_]{1,29}$/';

    public function groups(): Response
    {
        $rows = Repo::query(
            'SELECT g.group_cd, g.group_nm, g.use_yn,
                    (SELECT COUNT(*) FROM code c WHERE c.company_cd = g.company_cd AND c.group_cd = g.group_cd) AS item_cnt
               FROM code_group g WHERE g.company_cd = :company_cd ORDER BY g.group_cd'
        );
        return $this->view('admin/codes/groups', ['rows' => $rows]);
    }

    public function storeGroup(): Response
    {
        $cd = strtoupper($this->post('group_cd'));
        $name = $this->post('group_nm');
        if (preg_match(self::CODE_RE, $cd) !== 1 || $name === '') {
            $this->flash('danger', 'code.err_code');
        } elseif (Repo::find('code_group', ['group_cd' => $cd]) !== null) {
            $this->flash('danger', 'code.err_duplicate');
        } else {
            Repo::insert('code_group', ['group_cd' => $cd, 'group_nm' => mb_substr($name, 0, 100), 'use_yn' => 1]);
            Translations::save('CODE_GROUP', $cd, Translations::collect($_POST));
            Audit::log('CODEGROUP_CREATE', $cd);
            $this->flash('success', 'common.saved');
        }
        return $this->redirect('/admin/codes');
    }

    public function items(string $group): Response
    {
        $g = Repo::find('code_group', ['group_cd' => $group]) ?? $this->abort(404);
        $items = Repo::list('code', ['group_cd' => $group], 'sort_no, code_cd');
        $names = [];
        foreach ($items as $it) {
            $names[(string)$it['code_cd']] = Translations::get('CODE', $group . ':' . $it['code_cd']);
        }
        return $this->view('admin/codes/items', ['group' => $g, 'items' => $items, 'names' => $names]);
    }

    public function storeItem(string $group): Response
    {
        Repo::find('code_group', ['group_cd' => $group]) ?? $this->abort(404);
        $cd = strtoupper($this->post('code_cd'));
        $name = $this->post('code_nm');
        if (preg_match(self::CODE_RE, $cd) !== 1 || $name === '') {
            $this->flash('danger', 'code.err_code');
        } elseif (Repo::find('code', ['group_cd' => $group, 'code_cd' => $cd]) !== null) {
            $this->flash('danger', 'code.err_duplicate');
        } else {
            Repo::insert('code', [
                'group_cd' => $group,
                'code_cd' => $cd,
                'code_nm' => mb_substr($name, 0, 100),
                'sort_no' => $this->intParam($this->post('sort_no', '0')),
                'use_yn' => 1,
            ]);
            Translations::save('CODE', $group . ':' . $cd, Translations::collect($_POST));
            Audit::log('CODE_CREATE', $group . ':' . $cd);
            $this->flash('success', 'common.saved');
        }
        return $this->redirect('/admin/codes/' . rawurlencode($group));
    }

    public function updateItem(string $group, string $code): Response
    {
        $item = Repo::find('code', ['group_cd' => $group, 'code_cd' => $code]) ?? $this->abort(404);
        $name = $this->post('code_nm');
        if ($name === '') {
            $this->flash('danger', 'code.err_code');
        } else {
            Repo::update('code', ['group_cd' => $group, 'code_cd' => $code], [
                'code_nm' => mb_substr($name, 0, 100),
                'sort_no' => $this->intParam($this->post('sort_no', '0')),
                'use_yn' => Request::post('use_yn') === '1' ? 1 : 0,
            ]);
            Translations::save('CODE', $group . ':' . $item['code_cd'], Translations::collect($_POST));
            Audit::log('CODE_UPDATE', $group . ':' . $code);
            $this->flash('success', 'common.saved');
        }
        return $this->redirect('/admin/codes/' . rawurlencode($group));
    }
}
