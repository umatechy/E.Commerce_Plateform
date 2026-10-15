<?php

declare(strict_types=1);

namespace App\Domain\Help\Services;

use App\Support\HtmlSanitizer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Phase B54 — an article's Markdown as HTML that is safe to insert.
 *
 * Raw HTML in the Markdown is escaped, unsafe link schemes are dropped by
 * CommonMark, and the result goes through the allow-list HtmlSanitizer
 * as well (defence in depth): help content can never carry script.
 *
 * Links are written by where they lead, and resolved here for the place
 * the article is read:
 * - `help:<key>` another article (admin help center or the store's help);
 * - `store:/path` a page of the shopper's store (in the admin: the active
 *   store's storefront; with no store, the link becomes plain text).
 */
final class HelpRenderer
{
    private const EXTRA = [
        'code' => [], 'pre' => [], 'hr' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
    ];

    private ?MarkdownConverter $converter = null;

    /**
     * @param string $helpBase where articles live, e.g. "/help/articles" or "/shop/acme/help"
     * @param ?string $storeBase the storefront, e.g. "/shop/acme"; null leaves store: links as text
     */
    public function html(string $markdown, string $helpBase, ?string $storeBase): string
    {
        $markdown = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(\s*(help|store):([^)\s]*)\s*\)/',
            function (array $m) use ($helpBase, $storeBase): string {
                if ($m[2] === 'help') {
                    return "[{$m[1]}](".rtrim($helpBase, '/').'/'.ltrim($m[3], '/').')';
                }

                return $storeBase === null ? $m[1] : "[{$m[1]}](".rtrim($storeBase, '/').'/'.ltrim($m[3], '/').')';
            },
            $markdown,
        );

        $html = (string) $this->converter()->convert($markdown);

        return (new HtmlSanitizer(self::EXTRA))->sanitize($html);
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter === null) {
            $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new TableExtension());
            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter;
    }
}
