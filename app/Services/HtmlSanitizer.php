<?php
declare(strict_types=1);

namespace App\Services;

/** Quill 이 만든 HTML 을 허용 목록 기반으로 정화한다(저장 전 필수). */
final class HtmlSanitizer
{
    private const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'ol', 'ul', 'li', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'a', 'span',
    ];
    /** 내용까지 통째로 제거할 태그 */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'form', 'textarea', 'select', 'option', 'button', 'input', 'link', 'meta', 'base', 'title', 'head'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        self::walk($body);
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /** 태그를 제거한 평문(목록 요약/빈 내용 검사용). */
    public static function text(string $html): string
    {
        $t = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }
            if (!$child instanceof \DOMElement) {
                $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
            if (!in_array($tag, self::ALLOWED, true)) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::attributes($child, $tag);
        }
    }

    private static function attributes(\DOMElement $el, string $tag): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = $attr->value;
            $keep = false;
            if ($tag === 'a' && $name === 'href') {
                $keep = preg_match('#^(https?://|mailto:)#i', trim($value)) === 1;
            } elseif ($name === 'class') {
                $keep = preg_match('/^(ql-[a-z0-9-]+)( ql-[a-z0-9-]+)*$/', $value) === 1;
            } elseif ($tag === 'li' && $name === 'data-list') {
                $keep = in_array($value, ['ordered', 'bullet', 'checked', 'unchecked'], true);
            }
            if (!$keep) {
                $el->removeAttribute($attr->name);
            }
        }
        if ($tag === 'a') {
            if (!$el->hasAttribute('href')) {
                $el->setAttribute('href', '#');
            }
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
            $el->setAttribute('target', '_blank');
        }
    }
}
