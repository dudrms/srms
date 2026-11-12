<?php
declare(strict_types=1);

namespace App\Core;

final class Migrator
{
    /** @return list<string> 이번에 적용된 마이그레이션 파일명 */
    public static function run(): array
    {
        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migration (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $done = array_column(Db::all('SELECT version FROM schema_migration'), 'version');
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file);
            if (in_array($version, $done, true)) {
                continue;
            }
            $sql = (string)file_get_contents($file);
            Db::tx(static function () use ($pdo, $sql, $version): void {
                $pdo->exec($sql);
                Db::exec('INSERT INTO schema_migration (version, applied_at) VALUES (:v, :t)', ['v' => $version, 't' => now_str()]);
            });
            $applied[] = $version;
        }
        return $applied;
    }
}
