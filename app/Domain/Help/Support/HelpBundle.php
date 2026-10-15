<?php

declare(strict_types=1);

namespace App\Domain\Help\Support;

use App\Domain\Help\Models\HelpArticle;
use App\Domain\Settings\Services\Locales;

/**
 * Phase B54 (Module 34 §58 "documentation as code") — the help content
 * kept in the repository, under resources/help:
 *
 *   articles/<category>/<key>.md       an article in English (the canonical language, §60.1)
 *   articles/<category>/<key>.ur.md    its Urdu translation
 *   releases/<version>.md              the release notes of one version
 *
 * Each file starts with a front matter block of `name: value` lines between
 * two `---` lines; lists are comma-separated. A translation only carries
 * its own words (title, summary, keywords, body): everything else comes
 * from the English article.
 *
 * Reading never throws for bad content: problems are collected, so
 * `help:check` can list them all and `help:sync` can refuse to load.
 */
final class HelpBundle
{
    public const ARTICLE_FIELDS = [
        'key', 'title', 'summary', 'category', 'type', 'audience', 'module', 'tags', 'keywords', 'error_codes',
        'contexts', 'related', 'applies_from', 'applies_until', 'owner', 'review_due_on',
    ];

    public const TRANSLATION_FIELDS = ['key', 'locale', 'title', 'summary', 'keywords'];

    public const RELEASE_FIELDS = ['version', 'title', 'summary'];

    private const LISTS = ['tags', 'keywords', 'error_codes', 'contexts', 'related'];

    /** @var array<string, array<string, mixed>> "key.locale" => article */
    public array $articles = [];

    /** @var array<string, array<string, mixed>> version => release note */
    public array $releases = [];

    /** @var list<string> */
    public array $problems = [];

    public static function load(?string $path = null): self
    {
        $bundle = new self();
        $root = rtrim($path ?? (string) config('help.content_path'), '/\\');
        $files = glob($root.'/articles/*/*.md') ?: [];
        sort($files);

        // English first: translations inherit from it.
        usort($files, fn (string $a, string $b) => [self::localeOfFile($a) !== 'en', $a] <=> [self::localeOfFile($b) !== 'en', $b]);
        foreach ($files as $file) {
            $bundle->readArticle($file, $root);
        }
        foreach (glob($root.'/releases/*.md') ?: [] as $file) {
            $bundle->readRelease($file, $root);
        }

        return $bundle;
    }

    /** @return array<string, mixed>|null */
    public function article(string $key, string $locale = 'en'): ?array
    {
        return $this->articles["{$key}.{$locale}"] ?? null;
    }

    private static function localeOfFile(string $file): string
    {
        return preg_match('/\.([a-z]{2})\.md$/', $file, $m) === 1 ? $m[1] : 'en';
    }

    private function readArticle(string $file, string $root): void
    {
        $where = ltrim(str_replace([$root, '\\'], ['', '/'], $file), '/');
        $raw = (string) file_get_contents($file);
        [$meta, $body, $error] = self::split($raw);
        if ($error !== null) {
            $this->problems[] = "{$where}: {$error}";

            return;
        }
        $locale = self::localeOfFile($file);
        $name = (string) preg_replace('/(\.[a-z]{2})?\.md$/', '', basename($file));
        $folder = basename(dirname($file));
        $allowed = $locale === 'en' ? self::ARTICLE_FIELDS : self::TRANSLATION_FIELDS;
        foreach (array_diff(array_keys($meta), $allowed) as $unknown) {
            $this->problems[] = "{$where}: unknown field \"{$unknown}\"";
        }
        if (($meta['key'] ?? null) !== $name) {
            $this->problems[] = "{$where}: key must be \"{$name}\" (the file name)";
        }
        if ($locale !== 'en' && ! Locales::isSupported($locale)) {
            $this->problems[] = "{$where}: \"{$locale}\" is not a supported language";

            return;
        }

        if ($locale === 'en') {
            $article = array_fill_keys(self::ARTICLE_FIELDS, null);
            foreach ($meta as $field => $value) {
                $article[$field] = in_array($field, self::LISTS, true) ? self::listOf($value) : ($value === '' ? null : $value);
            }
            foreach (self::LISTS as $list) {
                $article[$list] ??= [];
            }
            if ($article['category'] !== $folder) {
                $this->problems[] = "{$where}: the folder must be the category (\"{$article['category']}\")";
            }
        } else {
            $source = $this->article($name);
            if ($source === null) {
                $this->problems[] = "{$where}: a translation needs the English article {$name}.md";

                return;
            }
            $article = [...$source, 'title' => $meta['title'] ?? null, 'summary' => $meta['summary'] ?? null, 'keywords' => self::listOf($meta['keywords'] ?? '')];
        }

        $this->articles["{$name}.{$locale}"] = [
            ...$article,
            'key' => $name,
            'locale' => $locale,
            'body' => $body,
            'file' => $where,
            'hash' => hash('sha256', $raw),
        ];
    }

    private function readRelease(string $file, string $root): void
    {
        $where = ltrim(str_replace([$root, '\\'], ['', '/'], $file), '/');
        $raw = (string) file_get_contents($file);
        [$meta, $body, $error] = self::split($raw);
        if ($error !== null) {
            $this->problems[] = "{$where}: {$error}";

            return;
        }
        foreach (array_diff(array_keys($meta), self::RELEASE_FIELDS) as $unknown) {
            $this->problems[] = "{$where}: unknown field \"{$unknown}\"";
        }
        $version = (string) ($meta['version'] ?? '');
        if ($version.'.md' !== basename($file)) {
            $this->problems[] = "{$where}: version must be the file name";
        }

        $sections = [];
        $current = null;
        $labels = array_flip(array_map('strtolower', HelpCatalog::RELEASE_SECTIONS));
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^##\s+(.+)$/', $line, $m) === 1) {
                $current = $labels[strtolower(trim($m[1]))] ?? null;
                if ($current === null) {
                    $this->problems[] = "{$where}: unknown section \"{$m[1]}\" (use: ".implode(', ', HelpCatalog::RELEASE_SECTIONS).')';
                }

                continue;
            }
            if (preg_match('/^[-*]\s+(.+)$/', $line, $m) === 1) {
                if ($current === null) {
                    $this->problems[] = "{$where}: a list item outside a section";

                    continue;
                }
                $sections[$current][] = trim($m[1]);
            } elseif (trim($line) !== '' && $current !== null && ! empty($sections[$current])) {
                // A continuation line of the previous item.
                $last = count($sections[$current]) - 1;
                $sections[$current][$last] .= ' '.trim($line);
            }
        }

        $this->releases[$version] = [
            'version' => $version,
            'title' => $meta['title'] ?? null,
            'summary' => $meta['summary'] ?? null,
            'sections' => array_intersect_key(array_replace(array_fill_keys(array_keys(HelpCatalog::RELEASE_SECTIONS), null), $sections), $sections),
            'body' => $body,
            'file' => $where,
            'hash' => hash('sha256', $raw),
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: string, 2: ?string} [front matter, body, problem]
     */
    public static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (! str_starts_with($raw, "---\n")) {
            return [[], '', 'must start with a --- front matter block'];
        }
        $end = strpos($raw, "\n---\n", 4);
        if ($end === false) {
            return [[], '', 'the front matter block is not closed with ---'];
        }
        $meta = [];
        foreach (explode("\n", substr($raw, 4, $end - 4)) as $number => $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m) !== 1) {
                return [[], '', 'front matter line '.($number + 2).' is not "name: value"'];
            }
            if (array_key_exists($m[1], $meta)) {
                return [[], '', "\"{$m[1]}\" is given twice"];
            }
            $value = trim($m[2]);
            // A value may be quoted ("Store settings: currency"); the quotes are not part of it.
            $meta[$m[1]] = strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"') ? substr($value, 1, -1) : $value;
        }

        return [$meta, trim(substr($raw, $end + 5))."\n", null];
    }

    /** @return list<string> */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? $value : array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }

    /**
     * The content fields of a bundled article, as a revision stores them.
     *
     * @param array<string, mixed> $article
     * @return array<string, mixed>
     */
    public static function contentOf(array $article): array
    {
        return array_intersect_key($article, array_flip(HelpArticle::CONTENT_FIELDS));
    }
}
