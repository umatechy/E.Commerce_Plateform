<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\DumpStrategies;

/**
 * Module 23 Phase 6 "Database Backup" — Non-Negotiable: "do not
 * implement a fake database dump... do not assume an arbitrary
 * filesystem copy is equivalent to a consistent MySQL backup."
 */
interface DatabaseDumpStrategy
{
    /** Produces a consistent database dump at a local temp file path and returns it. */
    public function dump(): string;
}
