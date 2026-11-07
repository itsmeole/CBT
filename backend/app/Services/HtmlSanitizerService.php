<?php

namespace App\Services;

class HtmlSanitizerService
{
    /**
     * Whitelist tag aman sesuai FR-BE-23:
     * p, br, b, strong, i, em, u, sub, sup, ul, ol, li, table, thead, tbody, tr, th, td, img, span
     */
    public static function sanitize(string $html): string
    {
        $allowedTags = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'sub', 'sup', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'span', 'audio'];
        $removedTags = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'];
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previousErrorSetting = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><!doctype html><html><body><div id="cbt-root">'.$html.'</div></body></html>',
            LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);

        $root = $document->getElementById('cbt-root');
        if (! $root) {
            return '';
        }

        self::sanitizeChildren($root, $allowedTags, $removedTags);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result);
    }

    private static function sanitizeChildren(\DOMNode $parent, array $allowedTags, array $removedTags): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, $removedTags, true)) {
                    $parent->removeChild($node);

                    continue;
                }

                self::sanitizeChildren($node, $allowedTags, $removedTags);
                if (! in_array($tag, $allowedTags, true)) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);

                    continue;
                }

                foreach (iterator_to_array($node->attributes) as $attribute) {
                    $name = strtolower($attribute->name);
                    $allowed = ($tag === 'img' && in_array($name, ['src', 'alt', 'title'], true))
                        || ($tag === 'audio' && in_array($name, ['src', 'controls'], true))
                        || ($tag === 'span' && $name === 'data-latex');
                    if (! $allowed) {
                        $node->removeAttributeNode($attribute);

                        continue;
                    }

                    // Audio hanya boleh berasal dari media yang diunggah ke server ini (bukan URL luar).
                    if ($tag === 'audio' && $name === 'src' && ! preg_match('#^/storage/[A-Za-z0-9._/-]+$#', $attribute->value)) {
                        $node->removeAttributeNode($attribute);

                        continue;
                    }

                    if ($name === 'src' && preg_match('/^\s*(?:javascript|data|vbscript):/i', $attribute->value)) {
                        $node->removeAttributeNode($attribute);
                    }
                }
            } elseif (! ($node instanceof \DOMText)) {
                $parent->removeChild($node);
            }
        }
    }
}
