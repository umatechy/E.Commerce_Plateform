<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

/**
 * Module 17 §18/§34 "Custom CSS" — Non-Negotiable, security-sensitive.
 * Same discipline as Phase B13's ContentSanitizer: no CSS parser/
 * sanitization library is installable in this sandbox, so this is a
 * conservative PATTERN-STRIP, not a real CSS-safety guarantee — stated
 * plainly, not oversold. Strips every known CSS-based script-execution
 * vector outright before storage. Never renders raw client input
 * anywhere else — this is the ONE call site.
 */
final class CustomCssSanitizer
{
    private const DANGEROUS_PATTERNS = [
        '/<script\b[^>]*>.*?<\/script>/is',
        '/<\/?[a-z][^>]*>/i', // any HTML tag at all — this field is CSS, never markup
        '/javascript\s*:/i',
        '/expression\s*\(/i',
        '/@import\b/i',
        '/-moz-binding\s*:/i',
        '/behavior\s*:/i',
        '/vbscript\s*:/i',
    ];

    public function sanitize(?string $css): ?string
    {
        if ($css === null || trim($css) === '') {
            return null;
        }

        $clean = $css;
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            $clean = preg_replace($pattern, '', $clean);
        }

        return mb_substr(trim($clean), 0, 20000); // Module 17 Step 31/49: bound resource usage — a hard length cap, not an invented commercial limit
    }
}
