<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Session;
use App\Services\AttachmentService;
use App\Services\ProcessError;

trait HandlesUploads
{
    /** 업로드된 파일을 첨부로 저장한다. 파일별 오류는 플래시로 알린다. */
    private function storeFiles(array $req, int $step, int $uid): void
    {
        $files = $_FILES['files'] ?? null;
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            return;
        }
        foreach ($files['name'] as $i => $name) {
            $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    throw new ProcessError('attach.err_size', ['mb' => AttachmentService::DEFAULT_MAX_MB]);
                }
                $tmp = (string)($files['tmp_name'][$i] ?? '');
                if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                    throw new ProcessError('attach.err_upload');
                }
                AttachmentService::ingest($req, $step, $uid, $tmp, (string)$name);
            } catch (ProcessError $e) {
                Session::flash('danger', AttachmentService::cleanName((string)$name) . ': ' . t($e->key, $e->params));
            }
        }
    }
}
