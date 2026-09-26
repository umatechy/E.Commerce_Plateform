<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Theme\Services\CustomCssSanitizer;
use Tests\TestCase;

/**
 * Phase B15 — Custom CSS sanitization (Module 17 §18/§34, Non-
 * Negotiable, security-sensitive). Pure unit tests — no database
 * required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CustomCssSanitizerTest extends TestCase
{
    public function test_script_tag_is_stripped(): void
    {
        $result = (new CustomCssSanitizer())->sanitize('.foo { color: red; } <script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $result);
    }

    public function test_javascript_url_is_stripped(): void
    {
        $result = (new CustomCssSanitizer())->sanitize('.foo { background: url(javascript:alert(1)); }');

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_expression_is_stripped(): void
    {
        $result = (new CustomCssSanitizer())->sanitize('.foo { width: expression(alert(1)); }');

        $this->assertStringNotContainsString('expression(', $result);
    }

    public function test_import_is_stripped(): void
    {
        $result = (new CustomCssSanitizer())->sanitize('@import url(evil.css); .foo { color: red; }');

        $this->assertStringNotContainsString('@import', $result);
    }

    public function test_null_input_returns_null(): void
    {
        $result = (new CustomCssSanitizer())->sanitize(null);

        $this->assertNull($result);
    }

    public function test_empty_string_returns_null(): void
    {
        $result = (new CustomCssSanitizer())->sanitize('   ');

        $this->assertNull($result);
    }

    public function test_safe_css_is_preserved(): void
    {
        $result = (new CustomCssSanitizer())->sanitize('.storefront-header { background-color: #FF0000; padding: 1rem; }');

        $this->assertStringContainsString('background-color: #FF0000', $result);
    }

    public function test_length_is_capped(): void
    {
        $result = (new CustomCssSanitizer())->sanitize(str_repeat('a', 30000));

        $this->assertLessThanOrEqual(20000, strlen($result));
    }
}
