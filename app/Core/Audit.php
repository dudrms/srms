<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, ?string $target = null, ?string $detail = null, ?int $userId = null): void
    {
        if (!Scope::has()) {
            return;
        }
        $uid = $userId ?? (is_int(Session::get('uid')) ? (int)Session::get('uid') : null);
        Repo::insert('audit_log', [
            'user_id' => $uid,
            'action' => $action,
            'target' => $target,
            'detail' => $detail,
            'ip_addr' => Request::ip(),
            'created_at' => now_str(),
        ]);
    }
}
