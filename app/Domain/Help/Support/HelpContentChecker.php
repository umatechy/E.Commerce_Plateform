<?php

declare(strict_types=1);

namespace App\Domain\Help\Support;

use App\Domain\DeveloperPlatform\Support\DeveloperApiSpec;
use App\Domain\DeveloperPlatform\Support\WebhookEventCatalog;
use Illuminate\Support\Facades\Route;

/**
 * Phase B54 (Module 34 §47–48, §56–57, §73.14–73.17) — what every article
 * must pass before readers see it, whether it comes from the repository
 * (`help:check` in CI, `help:sync`) or from the Super Admin editor:
 *
 * - required metadata and known values (§7, §56);
 * - links: `help:<key>` to an article the same readers can open,
 *   `/admin/path` to a page that exists, `store:/path` to a storefront
 *   page that exists, and https links only (§57 "broken links");
 * - developer API paths that are in the OpenAPI description, and webhook
 *   events that are in the catalog (§35 "must match actual contracts");
 * - no secrets and no personal data (§47, §57 "secret scanning");
 * - troubleshooting articles in the §24 framework.
 */
final class HelpContentChecker
{
    /**
     * Secret and personal-data patterns. Examples in articles use
     * placeholders such as <your-api-key> (Module 34 §48.6).
     */
    private const SECRET_PATTERNS = [
        'a private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'an Umar Techy API key' => '/\butk_[a-z0-9]{6,}\.[A-Za-z0-9]{8,}/',
        'a provider secret key' => '/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9]{8,}/',
        'an AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'a token' => '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
        'a long hexadecimal secret' => '/\b[a-fA-F0-9]{32,}\b/',
        'a long random string' => '/(?<![A-Za-z0-9\/_-])[A-Za-z0-9+\/]{40,}={0,2}(?![A-Za-z0-9\/_-])/',
        'a password or secret value' => '/\b(password|passwd|secret|api[_-]?key|token)\s*[:=]\s*(?![<\[{]|your|example|\*)[^\s,.;]{8,}/i',
        'a real email address' => '/\b[A-Za-z0-9._%+-]+@(?!example\.(com|org|net)\b)[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
        'a phone number' => '/(\+92[\s-]?|\b0)3\d{2}[\s-]?(?!0{7})\d{7}\b/',
    ];

    /** @var list<string>|null */
    private ?array $adminPatterns = null;

    /** @var list<string>|null */
    private ?array $storePatterns = null;

    /** @var list<string>|null */
    private ?array $apiPaths = null;

    /**
     * Problems with one article's content (English or a translation).
     *
     * @param array<string, mixed> $article content fields plus key and locale
     * @param callable(string): ?string $audienceOf the audience of another article, null when it does not exist
     * @return list<string>
     */
    public function check(array $article, callable $audienceOf): array
    {
        $problems = [];
        foreach (['key', 'title', 'summary', 'body', 'category', 'type', 'audience', 'applies_from', 'owner'] as $field) {
            if (trim((string) ($article[$field] ?? '')) === '') {
                $problems[] = "{$field} is required";
            }
        }
        if ($problems !== []) {
            return $problems;
        }

        $key = (string) $article['key'];
        $audience = (string) $article['audience'];
        if (preg_match('/^[a-z0-9][a-z0-9-]{2,79}$/', $key) !== 1) {
            $problems[] = 'key must be 3–80 lowercase letters, digits and hyphens';
        }
        if (mb_strlen((string) $article['title']) > 160) {
            $problems[] = 'title is longer than 160 characters';
        }
        if (mb_strlen((string) $article['summary']) > 300) {
            $problems[] = 'summary is longer than 300 characters';
        }
        if (! isset(HelpCatalog::AUDIENCES[$audience])) {
            $problems[] = "audience \"{$audience}\" is not one of: ".implode(', ', array_keys(HelpCatalog::AUDIENCES));
        }
        if (! isset(HelpCatalog::TYPES[(string) $article['type']])) {
            $problems[] = "type \"{$article['type']}\" is not one of: ".implode(', ', array_keys(HelpCatalog::TYPES));
        }
        $category = HelpCatalog::CATEGORIES[(string) $article['category']] ?? null;
        if ($category === null) {
            $problems[] = "category \"{$article['category']}\" is not one of: ".implode(', ', array_keys(HelpCatalog::CATEGORIES));
        } elseif (! in_array($audience, $category[2], true)) {
            $problems[] = "category \"{$article['category']}\" is not used for the {$audience} audience";
        }
        if (($article['module'] ?? null) !== null && preg_match('/^(0[1-9]|[12][0-9]|3[0-5])$/', (string) $article['module']) !== 1) {
            $problems[] = 'module must be a blueprint module number, 01–35';
        }
        foreach (['applies_from', 'applies_until'] as $field) {
            $version = $article[$field] ?? null;
            if ($version !== null && preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $version) !== 1) {
                $problems[] = "{$field} must be a version such as 1.1";
            }
        }
        if (preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $article['applies_from']) === 1 && ! HelpCatalog::versionAtMost((string) $article['applies_from'], HelpCatalog::productVersion())) {
            $problems[] = "applies_from {$article['applies_from']} is newer than this product version (".HelpCatalog::productVersion().')';
        }
        $due = $article['review_due_on'] ?? null;
        if ($due !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $due) !== 1 || strtotime((string) $due) === false)) {
            $problems[] = 'review_due_on must be a date (YYYY-MM-DD)';
        }

        foreach ((array) ($article['related'] ?? []) as $related) {
            $target = $audienceOf((string) $related);
            if ($target === null) {
                $problems[] = "related article \"{$related}\" does not exist";
            } elseif (! $this->canLink($audience, $target)) {
                $problems[] = "related article \"{$related}\" is for {$target} readers, who are not all readers of this article";
            }
        }
        foreach ((array) ($article['contexts'] ?? []) as $context) {
            if (! in_array($audience, ['merchant', 'owner', 'developer', 'platform'], true)) {
                $problems[] = 'only admin articles can be page help (contexts)';
                break;
            }
            if (! $this->adminPathExists((string) $context)) {
                $problems[] = "context \"{$context}\" is not an admin page";
            }
        }
        if ($audience !== 'developer' && $article['category'] === 'developers') {
            $problems[] = 'the developers category is for developer articles';
        }
        if ($article['type'] === 'troubleshooting') {
            $problems = [...$problems, ...$this->troubleshootingStructure((string) $article['body'])];
        }

        $text = implode("\n", [(string) $article['title'], (string) $article['summary'], (string) $article['body'], implode(' ', (array) ($article['keywords'] ?? []))]);

        return [...$problems, ...$this->links((string) $article['body'], $audience, $audienceOf), ...$this->contracts($text), ...$this->secrets($text)];
    }

    /**
     * Problems with one release note.
     *
     * @param array<string, mixed> $release
     * @return list<string>
     */
    public function checkRelease(array $release): array
    {
        $problems = [];
        foreach (['version', 'title', 'summary'] as $field) {
            if (trim((string) ($release[$field] ?? '')) === '') {
                $problems[] = "{$field} is required";
            }
        }
        $version = (string) ($release['version'] ?? '');
        if ($version !== '' && preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1) {
            $problems[] = 'version must be a version such as 1.1.0';
        } elseif ($version !== '' && ! HelpCatalog::versionAtMost($version, HelpCatalog::productVersion().'.99')) {
            $problems[] = "version {$version} is newer than this product version (".HelpCatalog::productVersion().')';
        }
        if (($release['sections'] ?? []) === []) {
            $problems[] = 'a release note needs at least one section';
        }
        $text = implode("\n", [(string) ($release['title'] ?? ''), (string) ($release['summary'] ?? ''), (string) ($release['body'] ?? '')]);

        return [...$problems, ...$this->secrets($text)];
    }

    /** @return list<string> */
    public function secrets(string $text): array
    {
        $found = [];
        foreach (self::SECRET_PATTERNS as $what => $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $found[] = "contains what looks like {$what} (\"".mb_substr($m[0], 0, 12).'…"); use a placeholder';
            }
        }

        return $found;
    }

    /**
     * Readers of an article of $from can all open an article of $to
     * (HelpAudience: store staff also see customer help; owners and
     * developers are store staff too; Umar Techy staff see everything).
     */
    public function canLink(string $from, string $to): bool
    {
        $guaranteed = [
            'customer' => ['customer'],
            'merchant' => ['merchant', 'customer'],
            'owner' => ['owner', 'merchant', 'customer'],
            'developer' => ['developer', 'merchant', 'customer'],
            'platform' => array_keys(HelpCatalog::AUDIENCES),
        ];

        return in_array($to, $guaranteed[$from] ?? [], true);
    }

    /** @return list<string> */
    private function links(string $body, string $audience, callable $audienceOf): array
    {
        $problems = [];
        preg_match_all('/\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/', $body, $matches);
        foreach ($matches[1] as $url) {
            if (str_starts_with($url, 'help:')) {
                $target = (string) preg_replace('/#.*$/', '', substr($url, 5));
                $targetAudience = $audienceOf($target);
                if ($targetAudience === null) {
                    $problems[] = "link to \"{$url}\": no such article";
                } elseif (! $this->canLink($audience, $targetAudience)) {
                    $problems[] = "link to \"{$url}\": that article is for {$targetAudience} readers";
                }
            } elseif (str_starts_with($url, 'store:')) {
                if (! $this->storePathExists(substr($url, 6))) {
                    $problems[] = "link to \"{$url}\": no such storefront page";
                }
            } elseif (str_starts_with($url, '/')) {
                if ($audience === 'customer') {
                    $problems[] = "link to \"{$url}\": shoppers cannot open admin pages; use store:/path";
                } elseif (! $this->adminPathExists($url)) {
                    $problems[] = "link to \"{$url}\": no such admin page";
                }
            } elseif (str_starts_with($url, '#')) {
                continue;
            } elseif (! str_starts_with($url, 'https://')) {
                $problems[] = "link to \"{$url}\": only https://, help:, store: and admin paths are allowed";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function contracts(string $text): array
    {
        $problems = [];
        if (preg_match_all('#/api/dev/v1(/[A-Za-z0-9_{}/.-]*)#', $text, $m) > 0) {
            $paths = $this->apiPaths ??= array_keys(DeveloperApiSpec::build()['paths'] ?? []);
            foreach (array_unique($m[1]) as $path) {
                $path = rtrim($path, '/.');
                if ($path !== '' && $path !== '/openapi.json' && ! in_array($path, $paths, true)) {
                    $problems[] = "the developer API has no path /api/dev/v1{$path} (see the OpenAPI description)";
                }
            }
        }
        if (preg_match_all('/`((?:order|payment|shipment|return)\.[a-z_]+)`/', $text, $m) > 0) {
            foreach (array_unique($m[1]) as $event) {
                if (! WebhookEventCatalog::has($event)) {
                    $problems[] = "\"{$event}\" is not a webhook event";
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function troubleshootingStructure(string $body): array
    {
        preg_match_all('/^##\s+(.+?)\s*$/m', $body, $m);
        $headings = $m[1];

        return $headings === HelpCatalog::TROUBLESHOOTING_HEADINGS
            ? []
            : ['a troubleshooting article has exactly these sections, in order: '.implode(', ', HelpCatalog::TROUBLESHOOTING_HEADINGS)];
    }

    /** An admin page path, `*` standing for one segment (an id). */
    public function adminPathExists(string $path): bool
    {
        $path = '/'.trim((string) preg_replace('/[?#].*$/', '', $path), '/');
        if ($this->adminPatterns === null) {
            $this->adminPatterns = [];
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $uri = $route->uri();
                if ($route->getDomain() !== null || ! in_array('GET', $route->methods(), true) || str_starts_with($uri, 'api/') || str_starts_with($uri, 'shop/')) {
                    continue;
                }
                $this->adminPatterns[] = $this->patternOf('/'.ltrim($uri, '/'));
            }
        }
        $candidate = str_replace('*', '01HZZZZZZZZZZZZZZZZZZZZZZZ', $path);
        $numeric = str_replace('*', '1', $path);
        foreach ($this->adminPatterns as $pattern) {
            if (preg_match($pattern, $candidate) === 1 || preg_match($pattern, $numeric) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A storefront page path, relative to the store (e.g. /account/orders). */
    public function storePathExists(string $path): bool
    {
        $path = '/'.trim((string) preg_replace('/[?#].*$/', '', $path), '/');
        if ($this->storePatterns === null) {
            $this->storePatterns = [];
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (str_starts_with($route->uri(), 'shop/{storeSlug}') && in_array('GET', $route->methods(), true)) {
                    $this->storePatterns[] = $this->patternOf('/'.ltrim(substr($route->uri(), strlen('shop/{storeSlug}')), '/'));
                }
            }
        }
        foreach ($this->storePatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function patternOf(string $uri): string
    {
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');

        return '#^'.preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($uri, '#')).'$#';
    }
}
