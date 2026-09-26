<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Settings\Http\Resources\SettingResource;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Models\SettingType;
use App\Domain\Settings\Services\SettingDefinition;
use Tests\TestCase;

/**
 * Phase B17 — Module 33 §36 "API Response Security", Non-Negotiable:
 * a secret-typed setting NEVER returns its raw value in an ordinary
 * response, regardless of caller. No secret setting is seeded in B17
 * (see inspection findings) — this test exercises the masking
 * mechanism itself directly against a synthetic secret definition, so
 * the guarantee is proven even though no real secret key exists yet.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SettingSecretMaskingTest extends TestCase
{
    public function test_secret_setting_never_exposes_its_raw_value(): void
    {
        $definition = new SettingDefinition(key: 'test.secret_key', scope: SettingScope::Platform, type: SettingType::Secret, default: null, sensitive: true);

        $resource = (new SettingResource($definition, 'super-secret-actual-value'))->toArray(request());

        $this->assertNull($resource['value']);
        $this->assertSame('••••••••', $resource['masked']);
        $this->assertStringNotContainsString('super-secret-actual-value', json_encode($resource));
    }

    public function test_unconfigured_secret_reports_configured_false(): void
    {
        $definition = new SettingDefinition(key: 'test.secret_key', scope: SettingScope::Platform, type: SettingType::Secret, default: null, sensitive: true);

        $resource = (new SettingResource($definition, null))->toArray(request());

        $this->assertFalse($resource['configured']);
    }

    public function test_non_secret_setting_returns_its_real_value_normally(): void
    {
        $definition = new SettingDefinition(key: 'store.timezone', scope: SettingScope::Store, type: SettingType::String, default: 'UTC');

        $resource = (new SettingResource($definition, 'Asia/Karachi'))->toArray(request());

        $this->assertSame('Asia/Karachi', $resource['value']);
        $this->assertNull($resource['masked']);
    }
}
