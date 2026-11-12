<?php
declare(strict_types=1);

namespace App\Core;

final class I18n
{
    public const SUPPORTED = ['ko', 'ja', 'en', 'zh'];
    /** 화면에 표시하는 언어 자체 명칭 */
    public const NAMES = ['ko' => '한국어', 'ja' => '日本語', 'en' => 'English', 'zh' => '中文(简体)'];

    private static string $lang = 'ko';
    /** @var array<string,array<string,string>> */
    private static array $msgs = [];
    /** @var array<string,array<string,string>> */
    private static array $masterCache = [];

    /** @return list<string> */
    public static function enabled(): array
    {
        $list = array_filter(array_map('trim', explode(',', (string)env('ENABLED_LANGS', 'ko,ja,en,zh'))));
        $list = array_values(array_intersect(self::SUPPORTED, $list));
        return $list === [] ? ['ko'] : $list;
    }

    public static function isEnabled(string $lang): bool
    {
        return in_array($lang, self::enabled(), true);
    }

    public static function defaultLang(): string
    {
        $d = (string)env('DEFAULT_LANG', 'ko');
        return self::isEnabled($d) ? $d : 'ko';
    }

    public static function set(string $lang): void
    {
        self::$lang = self::isEnabled($lang) ? $lang : self::defaultLang();
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function negotiate(string $acceptLanguage): string
    {
        foreach (explode(',', $acceptLanguage) as $part) {
            $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (self::isEnabled($code)) {
                return $code;
            }
        }
        return self::defaultLang();
    }

    /** @return array<string,string> */
    public static function load(string $lang): array
    {
        if (!isset(self::$msgs[$lang])) {
            $file = APP_ROOT . '/app/lang/' . $lang . '.php';
            self::$msgs[$lang] = in_array($lang, self::SUPPORTED, true) && is_file($file) ? (require $file) : [];
        }
        return self::$msgs[$lang];
    }

    public static function t(string $key, array $params = []): string
    {
        $s = self::load(self::$lang)[$key] ?? self::load('ko')[$key] ?? $key;
        foreach ($params as $k => $v) {
            $s = str_replace('{' . $k . '}', (string)$v, $s);
        }
        return $s;
    }

    /** 마스터 데이터 명칭 번역(translation 테이블). ko 또는 번역 누락 시 기본 명칭. */
    public static function master(string $entity, string $key, string $default): string
    {
        if (self::$lang === 'ko' || !Scope::has()) {
            return $default;
        }
        $ck = Scope::cd() . '|' . self::$lang;
        if (!isset(self::$masterCache[$ck])) {
            $map = [];
            foreach (Repo::query(
                'SELECT entity, entity_key, text FROM translation WHERE company_cd = :company_cd AND lang = :lang',
                ['lang' => self::$lang]
            ) as $r) {
                $map[$r['entity'] . '|' . $r['entity_key']] = (string)$r['text'];
            }
            self::$masterCache[$ck] = $map;
        }
        return self::$masterCache[$ck][$entity . '|' . $key] ?? $default;
    }

    public static function clearMasterCache(): void
    {
        self::$masterCache = [];
    }
}
