<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Console;

use App\Domain\DeveloperPlatform\Support\DeveloperApiSpec;
use Illuminate\Console\Command;

/**
 * Phase B53 (SRS API-012; Module 31 §51, §65): writes the developer API's
 * OpenAPI description to docs/api/openapi-developer-v1.json. With --check
 * it only compares, and fails when the committed file is out of date — CI
 * runs it that way, so a changed route or field cannot ship undocumented.
 */
final class GenerateOpenApiCommand extends Command
{
    public const FILE = 'docs/api/openapi-developer-v1.json';

    protected $signature = 'api:openapi {--check : Fail when the committed file differs instead of writing it}';

    protected $description = 'Write (or check) the OpenAPI description of the developer API.';

    public function handle(): int
    {
        $json = DeveloperApiSpec::json();
        $path = base_path(self::FILE);
        if ($this->option('check')) {
            $current = is_file($path) ? str_replace("\r\n", "\n", (string) file_get_contents($path)) : '';
            if ($current !== $json) {
                $this->error(self::FILE.' is out of date. Run `php artisan api:openapi` and commit the result.');

                return self::FAILURE;
            }
            $this->info(self::FILE.' is up to date.');

            return self::SUCCESS;
        }
        file_put_contents($path, $json);
        $this->info('Wrote '.self::FILE.' ('.count(DeveloperApiSpec::ENDPOINTS).' operations).');

        return self::SUCCESS;
    }
}
