<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Allow-list HTML sanitizer built on a real HTML parser (Phase B24).
 *
 * The B13 regex sanitizer could be bypassed by an unquoted
 * `href=javascript:…` and by entity-encoded schemes (`jav&#x09;ascript:`,
 * which browsers decode and then run). This one parses the markup,
 * rebuilds it from an explicit element + attribute allow-list, and
 * checks every URL after the parser has decoded entities and after
 * control characters are removed, exactly as a browser would read it.
 *
 * Output is safe to insert as HTML into a page. Everything not listed
 * is removed: dangerous containers with their content, other unknown
 * elements are unwrapped (their text is kept).
 */
final class HtmlSanitizer
{
    /** element => allowed attributes */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'blockquote' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    /** Removed together with everything inside them. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'svg', 'math', 'template', 'noscript', 'title', 'head', 'link', 'meta', 'base',
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><html><body><div id="sanitizer-root">'.$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('sanitizer-root');

        if ($root === null) {
            return '';
        }

        $this->cleanChildren($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    private function cleanChildren(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }

            if (! $child instanceof \DOMElement) {
                $node->removeChild($child); // comments, processing instructions, CDATA
                continue;
            }

            $name = strtolower($child->tagName);

            if (in_array($name, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            $this->cleanChildren($child);

            if (! array_key_exists($name, self::ALLOWED)) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $this->cleanAttributes($child, $name);
        }
    }

    private function cleanAttributes(\DOMElement $element, string $name): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attr = strtolower($attribute->name);
            $value = $attribute->value;
            $keep = in_array($attr, self::ALLOWED[$name], true) && match ($attr) {
                'href' => $this->isSafeUrl($value, allowInsecure: true),
                'src' => $this->isSafeUrl($value, allowInsecure: false),
                'width', 'height' => preg_match('/^\d{1,4}$/', $value) === 1,
                default => mb_strlen($value) <= 500,
            };

            if (! $keep) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($name === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'nofollow noopener noreferrer');
        }

        if ($name === 'img' && ! $element->hasAttribute('src')) {
            $element->parentNode?->removeChild($element);
        }
    }

    /**
     * $url is already entity-decoded by the parser. Browsers ignore
     * control characters and whitespace inside a scheme, so they are
     * removed before the scheme is checked.
     */
    private function isSafeUrl(string $url, bool $allowInsecure): bool
    {
        $normalized = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $url));

        if ($normalized === '' || str_starts_with($normalized, '//')) {
            return false;
        }

        if (str_starts_with($normalized, '/') || str_starts_with($normalized, '#')) {
            return true;
        }

        return str_starts_with($normalized, 'https://')
            || ($allowInsecure && (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'mailto:')));
    }
}
