<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Response;
use App\Services\ProcessError;
use App\Services\ProcessService;

final class ProcessMapController extends Controller
{
    public function index(): Response
    {
        return $this->view('admin/processes/map', ProcessService::mappingMatrix());
    }

    public function save(): Response
    {
        $post = $_POST['map'] ?? [];
        try {
            ProcessService::saveMapping(is_array($post) ? $post : []);
            Audit::log('PROCESS_MAP_SAVE');
            $this->flash('success', 'common.saved');
        } catch (ProcessError $e) {
            $this->flash('danger', $e->key, $e->params);
        }
        return $this->redirect('/admin/process-map');
    }
}
