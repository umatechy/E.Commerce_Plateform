<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case. Was REFERENCED but never created in Phase B0 — a
 * genuine gap fixed in B1 (see docs/development/b1-inspection-findings.md
 * item B). Without this file, none of the B0 or B1 Feature tests could
 * have executed even with a real PHP/PHPUnit runtime available.
 */
abstract class TestCase extends BaseTestCase
{
    //
}
