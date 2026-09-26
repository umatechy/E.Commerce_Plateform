<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Settings\Services\SettingRegistry;
use App\Domain\Settings\Services\SettingValidator;
use Tests\TestCase;

/**
 * Phase B17 — Setting type validation: no arbitrary JSON blindly
 * accepted, every value server-side validated (Module 33 §13-14, Non-
 * Negotiable). Pure unit tests — no database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SettingRegistryValidatorTest extends TestCase
{
    public function test_unknown_key_is_not_in_the_registry(): void
    {
        $this->assertNull(SettingRegistry::find('some.made.up.key'));
    }

    public function test_boolean_setting_rejects_a_non_boolean_value(): void
    {
        $definition = SettingRegistry::find('platform.maintenance_mode');

        $this->expectException(InvalidSettingValueException::class);
        (new SettingValidator())->validate($definition, 'yes');
    }

    public function test_boolean_setting_accepts_a_real_boolean(): void
    {
        $definition = SettingRegistry::find('platform.maintenance_mode');

        $result = (new SettingValidator())->validate($definition, true);

        $this->assertTrue($result);
    }

    public function test_invalid_timezone_is_rejected(): void
    {
        $definition = SettingRegistry::find('store.timezone');

        $this->expectException(InvalidSettingValueException::class);
        (new SettingValidator())->validate($definition, 'Not/ARealZone');
    }

    public function test_valid_iana_timezone_is_accepted(): void
    {
        $definition = SettingRegistry::find('store.timezone');

        $result = (new SettingValidator())->validate($definition, 'America/New_York');

        $this->assertSame('America/New_York', $result);
    }

    public function test_empty_string_array_is_rejected(): void
    {
        $definition = SettingRegistry::find('platform.supported_currencies');

        $this->expectException(InvalidSettingValueException::class);
        (new SettingValidator())->validate($definition, []);
    }

    public function test_valid_string_array_is_trimmed_and_accepted(): void
    {
        $definition = SettingRegistry::find('platform.supported_currencies');

        $result = (new SettingValidator())->validate($definition, [' USD ', 'EUR']);

        $this->assertSame(['USD', 'EUR'], $result);
    }

    public function test_empty_string_value_is_rejected(): void
    {
        $definition = SettingRegistry::find('store.default_locale');

        $this->expectException(InvalidSettingValueException::class);
        (new SettingValidator())->validate($definition, '   ');
    }
}
