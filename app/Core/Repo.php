<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 회사코드 강제 부여 데이터 접근 계층.
 * 모든 업무 테이블 접근은 이 클래스를 통해서만 수행하며 company_cd 조건/값이 자동으로 붙는다.
 */
final class Repo
{
    private const ORDER_RE = '/^[a-z_][a-z0-9_]*( (asc|desc))?(, ?[a-z_][a-z0-9_]*( (asc|desc))?)*$/i';

    private static function ident(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('invalid identifier: ' . $name);
        }
        return '"' . $name . '"';
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function where(array $where): array
    {
        if (array_key_exists('company_cd', $where)) {
            throw new \InvalidArgumentException('company_cd must not be passed');
        }
        $parts = ['"company_cd" = :company_cd'];
        $params = ['company_cd' => Scope::cd()];
        $i = 0;
        foreach ($where as $col => $val) {
            if ($val === null) {
                $parts[] = self::ident($col) . ' IS NULL';
                continue;
            }
            $p = 'w' . $i++;
            $parts[] = self::ident($col) . ' = :' . $p;
            $params[$p] = $val;
        }
        return [implode(' AND ', $parts), $params];
    }

    public static function find(string $table, array $where): ?array
    {
        [$w, $p] = self::where($where);
        return Db::one('SELECT * FROM ' . self::ident($table) . ' WHERE ' . $w . ' LIMIT 1', $p);
    }

    /** @return list<array<string,mixed>> */
    public static function list(string $table, array $where = [], string $order = ''): array
    {
        [$w, $p] = self::where($where);
        $sql = 'SELECT * FROM ' . self::ident($table) . ' WHERE ' . $w;
        if ($order !== '') {
            if (preg_match(self::ORDER_RE, $order) !== 1) {
                throw new \InvalidArgumentException('invalid order');
            }
            $sql .= ' ORDER BY ' . $order;
        }
        return Db::all($sql, $p);
    }

    public static function count(string $table, array $where = []): int
    {
        [$w, $p] = self::where($where);
        return (int)Db::val('SELECT COUNT(*) FROM ' . self::ident($table) . ' WHERE ' . $w, $p);
    }

    public static function insert(string $table, array $data): int
    {
        if (array_key_exists('company_cd', $data)) {
            throw new \InvalidArgumentException('company_cd must not be passed');
        }
        $data = ['company_cd' => Scope::cd()] + $data;
        $cols = [];
        $phs = [];
        $params = [];
        $i = 0;
        foreach ($data as $col => $val) {
            $cols[] = self::ident($col);
            $p = 'v' . $i++;
            $phs[] = ':' . $p;
            $params[$p] = $val;
        }
        return Db::insert(
            'INSERT INTO ' . self::ident($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $phs) . ')',
            $params
        );
    }

    public static function update(string $table, array $where, array $data): int
    {
        if ($data === [] || array_key_exists('company_cd', $data)) {
            throw new \InvalidArgumentException('invalid update data');
        }
        [$w, $p] = self::where($where);
        $sets = [];
        $i = 0;
        foreach ($data as $col => $val) {
            $ph = 's' . $i++;
            $sets[] = self::ident($col) . ' = :' . $ph;
            $p[$ph] = $val;
        }
        return Db::exec('UPDATE ' . self::ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $w, $p);
    }

    public static function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('delete requires a condition');
        }
        [$w, $p] = self::where($where);
        return Db::exec('DELETE FROM ' . self::ident($table) . ' WHERE ' . $w, $p);
    }

    /** 사용자 정의 SQL. 반드시 :company_cd 조건을 포함해야 한다. */
    public static function query(string $sql, array $params = []): array
    {
        return Db::all($sql, self::guard($sql, $params));
    }

    public static function queryOne(string $sql, array $params = []): ?array
    {
        return Db::one($sql, self::guard($sql, $params));
    }

    public static function queryVal(string $sql, array $params = []): mixed
    {
        return Db::val($sql, self::guard($sql, $params));
    }

    public static function exec(string $sql, array $params = []): int
    {
        return Db::exec($sql, self::guard($sql, $params));
    }

    private static function guard(string $sql, array $params): array
    {
        if (!str_contains($sql, ':company_cd')) {
            throw new \InvalidArgumentException('query must contain :company_cd');
        }
        if (array_key_exists('company_cd', $params)) {
            throw new \InvalidArgumentException('company_cd must not be passed');
        }
        $params['company_cd'] = Scope::cd();
        return $params;
    }
}
