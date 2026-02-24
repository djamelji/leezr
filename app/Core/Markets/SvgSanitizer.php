<?php

namespace App\Core\Markets;

class SvgSanitizer
{
    private const ALLOWED_TAGS = [
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line',
        'polygon', 'polyline', 'defs', 'clippath', 'mask', 'title', 'desc', 'use',
    ];

    private const ALLOWED_ATTRS = [
        'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width',
        'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'points', 'transform', 'xmlns', 'xmlns:xlink', 'id', 'class', 'style',
        'clip-path', 'clip-rule', 'fill-rule', 'fill-opacity', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset',
        'opacity', 'display',
    ];

    public static function sanitize(?string $svg): ?string
    {
        if ($svg === null || trim($svg) === '') {
            return null;
        }

        // Strip PHP/script tags before parsing
        $svg = preg_replace('/<\?.*?\?>/s', '', $svg);
        $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg);
        $svg = preg_replace('/<foreignObject\b[^>]*>.*?<\/foreignObject>/is', '', $svg);

        // Parse with DOMDocument
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $wrapped = '<?xml version="1.0" encoding="UTF-8"?>' . $svg;
        $dom->loadXML($wrapped);
        libxml_clear_errors();

        $svgElements = $dom->getElementsByTagName('svg');
        if ($svgElements->length === 0) {
            return null;
        }

        // Walk the DOM tree and sanitize
        static::walkNode($dom->documentElement);

        // Serialize the cleaned SVG
        $output = '';
        foreach ($dom->childNodes as $child) {
            $output .= $dom->saveXML($child);
        }

        // Remove XML declaration if present
        $output = preg_replace('/<\?xml[^?]*\?>\s*/', '', $output);

        $output = trim($output);

        return $output !== '' ? $output : null;
    }

    public static function isValid(?string $svg): bool
    {
        if ($svg === null || trim($svg) === '') {
            return true; // null/empty is valid (optional field)
        }

        // Check for dangerous patterns
        if (preg_match('/<script\b/i', $svg)) {
            return false;
        }
        if (preg_match('/<foreignObject\b/i', $svg)) {
            return false;
        }
        if (preg_match('/\bon\w+\s*=/i', $svg)) {
            return false;
        }
        if (preg_match('/javascript\s*:/i', $svg)) {
            return false;
        }

        return true;
    }

    private static function walkNode(\DOMNode $node): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $nodesToRemove = [];

        // Process children first (depth-first)
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->localName ?? $child->nodeName);

                if (!in_array($tagName, self::ALLOWED_TAGS)) {
                    $nodesToRemove[] = $child;
                } else {
                    static::walkNode($child);
                    static::sanitizeAttributes($child);
                }
            }
        }

        foreach ($nodesToRemove as $remove) {
            $node->removeChild($remove);
        }
    }

    private static function sanitizeAttributes(\DOMElement $element): void
    {
        $attrsToRemove = [];

        foreach ($element->attributes as $attr) {
            $attrName = strtolower($attr->name);

            // Remove event handlers (on*)
            if (str_starts_with($attrName, 'on')) {
                $attrsToRemove[] = $attr->name;

                continue;
            }

            // Remove dangerous href values
            if (in_array($attrName, ['href', 'xlink:href'])) {
                $value = strtolower(trim($attr->value));
                if (str_starts_with($value, 'javascript:') || str_starts_with($value, 'data:')) {
                    $attrsToRemove[] = $attr->name;

                    continue;
                }
            }

            // Remove non-allowlisted attributes
            if (!in_array($attrName, self::ALLOWED_ATTRS)) {
                $attrsToRemove[] = $attr->name;
            }
        }

        foreach ($attrsToRemove as $name) {
            $element->removeAttribute($name);
        }
    }
}
