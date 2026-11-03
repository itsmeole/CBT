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
        // Strip script, style, iframe, object, embed, event handlers (onclick, etc.)
        $allowedTags = '<p><br><b><strong><i><em><u><sub><sup><ul><ol><li><table><thead><tbody><tr><th><td><img><span>';
        $cleaned = strip_tags($html, $allowedTags);

        // Hapus atribut berbahaya seperti on*, javascript:, data- uri kecuali data-latex
        $cleaned = preg_replace('/<([a-z][a-z0-9]*)[^>]*?(\bon[a-z]+\s*=\s*(?:".*?"|\'.*?\'|[^\s>]+))[^>]*>/i', '<$1>', $cleaned);
        $cleaned = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', '', $cleaned);
        $cleaned = preg_replace('/src\s*=\s*["\']javascript:[^"\']*["\']/i', '', $cleaned);

        return trim($cleaned);
    }
}

