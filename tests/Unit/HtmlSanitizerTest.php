<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase B24 — the parser-based sanitizer behind every piece of HTML the
 * storefront renders (content pages, product descriptions), including
 * the vectors that got past the B13 regex sanitizer.
 */
final class HtmlSanitizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function attacks(): array
    {
        return [
            'unquoted javascript href (B13 bypass)' => ['<a href=javascript:alert(1)>x</a>', '<a>x</a>'],
            'entity-encoded scheme (B13 bypass)' => ['<a href="jav&#x09;ascript:alert(1)">x</a>', '<a>x</a>'],
            'slash-separated event handler' => ['<img/onerror=alert(1) src="https://cdn.example/a.png">', '<img src="https://cdn.example/a.png">'],
            'script with content' => ['<p>ok</p><script>alert(1)</script>', '<p>ok</p>'],
            'svg payload' => ['<svg onload=alert(1)><script>alert(1)</script></svg>ok', 'ok'],
            'style attribute' => ['<p style="background:url(x)">t</p>', '<p>t</p>'],
            'protocol-relative link' => ['<a href="//evil.example">x</a>', '<a>x</a>'],
            'data image' => ['<img src="data:image/svg+xml;base64,PHN2Zz4=">', ''],
            'insecure image' => ['<img src="http://cdn.example/a.png">', ''],
            'iframe' => ['<iframe src="https://evil.example"></iframe>t', 't'],
            'unknown wrapper kept as text' => ['<custom><b>bold</b></custom>', '<b>bold</b>'],
            'comment' => ['<!-- secret --><p>t</p>', '<p>t</p>'],
        ];
    }

    #[DataProvider('attacks')]
    public function test_it_neutralizes(string $input, string $expected): void
    {
        $this->assertSame($expected, (new HtmlSanitizer())->sanitize($input));
    }

    public function test_it_keeps_safe_formatting_and_links(): void
    {
        $html = '<h2>Care</h2><p>Wash <em>cold</em> &amp; dry.</p><ul><li>One</li></ul><a href="https://maker.example/care?a=1&b=2" title="Care">guide</a> <a href="/pages/returns">returns</a> <a href="mailto:help@shop.example">mail</a>';

        $this->assertSame(
            '<h2>Care</h2><p>Wash <em>cold</em> &amp; dry.</p><ul><li>One</li></ul><a href="https://maker.example/care?a=1&amp;b=2" title="Care" rel="nofollow noopener noreferrer">guide</a> <a href="/pages/returns" rel="nofollow noopener noreferrer">returns</a> <a href="mailto:help@shop.example" rel="nofollow noopener noreferrer">mail</a>',
            (new HtmlSanitizer())->sanitize($html),
        );
    }

    public function test_it_keeps_unicode_text(): void
    {
        $this->assertSame('<p>Ürdu: اردو</p>', (new HtmlSanitizer())->sanitize('<p>Ürdu: اردو</p>'));
    }
}
