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
    private const ALLOWED_TAGS = '<p><br><strong><em><b><i><u><ul><ol><li><a><h2><h3><h4><blockquote><img>';

    public function sanitize(string $html): string
    {
        // Pass 1: drop every tag not in the allow-list outright (this
        // alone already removes <script>, <iframe>, <object>, <embed>,
        // <form>, <style>, event-carrying <svg>, etc.).
        $stripped = strip_tags($html, self::ALLOWED_TAGS);

        // Pass 2: even an ALLOWED tag (e.g. <a>, <img>) could carry a
        // dangerous attribute — strip every on*="..." event-handler
        // attribute and neutralize javascript:/data: URLs in href/src,
        // regardless of quoting style.
        $stripped = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $stripped);
        $stripped = preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|data)\s*:[^"\']*\2/i', '$1=$2#$2', $stripped);

        return trim($stripped);
    }
}
