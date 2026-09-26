<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Theme\Exceptions\InvalidThemeConfigException;
use App\Domain\Theme\Services\ThemeConfigValidator;
use Tests\TestCase;

/**
 * Phase B15 — Theme configuration schema whitelisting: no arbitrary
 * JSON, no arbitrary component names, hex-only colors, whitelisted
 * fonts (Module 17 §13/§19/§32-33, Non-Negotiable). Pure unit tests —
 * no database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ThemeConfigValidatorTest extends TestCase
{
    public function test_valid_hex_color_token_is_accepted(): void
    {
        $result = (new ThemeConfigValidator())->validate(['tokens' => ['primary' => '#FF0000']]);

        $this->assertSame('#FF0000', $result['tokens']['primary']);
    }

    public function test_non_hex_color_value_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['tokens' => ['primary' => 'url(javascript:alert(1))']]);
    }

    public function test_css_injection_attempt_via_color_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['tokens' => ['primary' => 'red; } body { display: none']]);
    }

    public function test_unrecognized_top_level_key_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['tokens' => [], 'malicious_key' => 'x']);
    }

    public function test_unrecognized_token_key_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['tokens' => ['custom_hacky_field' => 'value']]);
    }

    public function test_unsupported_font_family_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['tokens' => ['font_family' => 'Comic Sans MS']]);
    }

    public function test_whitelisted_font_family_is_accepted(): void
    {
        $result = (new ThemeConfigValidator())->validate(['tokens' => ['font_family' => 'Inter']]);

        $this->assertSame('Inter', $result['tokens']['font_family']);
    }

    public function test_unrecognized_section_type_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['sections' => [['type' => 'arbitrary_component', 'position' => 0]]]);
    }

    public function test_whitelisted_section_type_is_accepted(): void
    {
        $result = (new ThemeConfigValidator())->validate(['sections' => [['type' => 'hero', 'position' => 0, 'config' => ['heading' => 'Welcome']]]]);

        $this->assertSame('hero', $result['sections'][0]['type']);
        $this->assertSame('Welcome', $result['sections'][0]['config']['heading']);
    }

    public function test_non_https_url_in_branding_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['branding' => ['logo_url' => 'javascript:alert(1)']]);
    }

    public function test_valid_https_logo_url_is_accepted(): void
    {
        $result = (new ThemeConfigValidator())->validate(['branding' => ['logo_url' => 'https://cdn.example.com/logo.png']]);

        $this->assertSame('https://cdn.example.com/logo.png', $result['branding']['logo_url']);
    }

    public function test_unrecognized_social_link_platform_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['branding' => ['social_links' => ['myspace' => 'https://myspace.com/x']]]);
    }

    public function test_unrecognized_section_config_field_is_rejected(): void
    {
        $this->expectException(InvalidThemeConfigException::class);
        (new ThemeConfigValidator())->validate(['sections' => [['type' => 'hero', 'position' => 0, 'config' => ['unexpected_field' => 'x']]]]);
    }

    public function test_empty_config_is_valid(): void
    {
        $result = (new ThemeConfigValidator())->validate([]);

        $this->assertSame([], $result['tokens']);
        $this->assertSame([], $result['branding']);
        $this->assertSame([], $result['sections']);
    }
}
