<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Seo\Services\ContentSanitizer;
use Tests\TestCase;

/**
 * Phase B13 — Content sanitization (Module 16 §26/§35-36, Non-
 * Negotiable). Pure unit tests — no database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ContentSanitizerTest extends TestCase
{
    public function test_script_tags_are_removed(): void
    {
        $result = (new ContentSanitizer())->sanitize('<p>Hello</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringContainsString('<p>Hello</p>', $result);
    }

    public function test_iframe_tags_are_removed(): void
    {
        $result = (new ContentSanitizer())->sanitize('<iframe src="evil.com"></iframe><p>Safe</p>');

        $this->assertStringNotContainsString('<iframe', $result);
    }

    public function test_event_handler_attributes_are_stripped(): void
    {
        $result = (new ContentSanitizer())->sanitize('<img src="x.jpg" onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $result);
    }

    public function test_javascript_url_in_href_is_neutralized(): void
    {
        $result = (new ContentSanitizer())->sanitize('<a href="javascript:alert(1)">Click</a>');

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_allowed_formatting_tags_are_preserved(): void
    {
        $result = (new ContentSanitizer())->sanitize('<p><strong>Bold</strong> and <em>italic</em></p>');

        $this->assertSame('<p><strong>Bold</strong> and <em>italic</em></p>', $result);
    }

    public function test_form_tags_are_removed(): void
    {
        $result = (new ContentSanitizer())->sanitize('<form action="/steal"><input type="text"></form><p>Text</p>');

        $this->assertStringNotContainsString('<form', $result);
    }
}
