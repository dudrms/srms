<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\I18n;
use App\Core\Repo;

final class Translations
{
    public const LANGS = ['ja', 'en', 'zh'];

    /** 폼/입력 배열의 `name_{lang}` 값을 언어별로 모은다. @param array<string,mixed> $in @return array<string,string> */
    public static function collect(array $in): array
    {
        $out = [];
        foreach (self::LANGS as $lang) {
            $out[$lang] = mb_substr(trim((string)($in['name_' . $lang] ?? '')), 0, 100);
        }
        return $out;
    }

    /** 해당 언어 번역이 아직 없을 때만 추가한다(기존 수정 내용을 덮어쓰지 않음). */
    public static function addIfMissing(string $entity, string $key, string $lang, string $text): void
    {
        if ($text !== '' && Repo::find('translation', ['entity' => $entity, 'entity_key' => $key, 'lang' => $lang]) === null) {
            Repo::insert('translation', ['entity' => $entity, 'entity_key' => $key, 'lang' => $lang, 'text' => $text]);
            I18n::clearMasterCache();
        }
    }

    /** @param array<string,string> $byLang 언어 => 번역(빈 값은 삭제) */
    public static function save(string $entity, string $key, array $byLang): void
    {
        foreach (self::LANGS as $lang) {
            $text = trim((string)($byLang[$lang] ?? ''));
            Repo::delete('translation', ['entity' => $entity, 'entity_key' => $key, 'lang' => $lang]);
            if ($text !== '') {
                Repo::insert('translation', ['entity' => $entity, 'entity_key' => $key, 'lang' => $lang, 'text' => $text]);
            }
        }
        I18n::clearMasterCache();
    }

    /** @return array<string,string> */
    public static function get(string $entity, string $key): array
    {
        $out = [];
        foreach (Repo::list('translation', ['entity' => $entity, 'entity_key' => $key]) as $r) {
            $out[(string)$r['lang']] = (string)$r['text'];
        }
        return $out;
    }

    public static function remove(string $entity, string $key): void
    {
        Repo::delete('translation', ['entity' => $entity, 'entity_key' => $key]);
        I18n::clearMasterCache();
    }
}
