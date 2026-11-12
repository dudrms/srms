<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            $n = strlen($val);
            if ($n >= 2 && (($val[0] === '"' && $val[$n - 1] === '"') || ($val[0] === "'" && $val[$n - 1] === "'"))) {
                $val = substr($val, 1, -1);
            }
            self::$vars[$key] = $val;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        $e = getenv($key);
        return $e !== false ? $e : $default;
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }
}
