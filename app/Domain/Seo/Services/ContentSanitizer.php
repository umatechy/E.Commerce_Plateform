<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

/**
 * Module 16 §26/§35-36 "Rich Text Security / Content Safety &
 * Sanitization / Media & File Security" — Non-Negotiable. A
 * conservative, documented, WHITELIST-ONLY sanitizer — see
 * docs/development/b13-inspection-findings.md "Architectural Decision
 * — Content Sanitization" for why no third-party library (e.g.
 * HTMLPurifier) is used (none is installable in this sandbox; a
 * future phase with Composer/network access should replace this class
 * body with a battle-tested library without changing its call sites).
 *
 * This is the ONLY place ContentPage.body is ever written — never
 * bypassed by any controller.
 */
final class ContentSanitizer
{
    public function sanitize(string $html): string
    {
        // Phase B24: the regex passes this method used to run could be
        // bypassed (unquoted `href=javascript:`, entity-encoded schemes);
        // it now delegates to the parser-based allow-list sanitizer, which
        // keeps the same element set (p, br, strong, em, b, i, u, ul, ol,
        // li, a, h2-h4, blockquote, img).
        return (new \App\Support\HtmlSanitizer())->sanitize($html);
    }
}
