<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Services\NotificationTemplateRenderer;
use Tests\TestCase;

/**
 * Phase B11 — Template variable security: safe substitution only,
 * never arbitrary execution (Module 21 §13-14, Non-Negotiable). Pure
 * unit tests — no database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class NotificationTemplateRendererTest extends TestCase
{
    public function test_known_variable_is_substituted(): void
    {
        $result = (new NotificationTemplateRenderer())->render('Hi {{customer.name}}!', ['customer.name' => 'Jane']);

        $this->assertSame('Hi Jane!', $result);
    }

    public function test_unrecognized_token_is_left_literal(): void
    {
        $result = (new NotificationTemplateRenderer())->render('Hi {{unknown.field}}!', ['customer.name' => 'Jane']);

        $this->assertSame('Hi {{unknown.field}}!', $result);
    }

    public function test_multiple_variables_are_all_substituted(): void
    {
        $result = (new NotificationTemplateRenderer())->render(
            'Order {{order.number}} total {{order.total}}',
            ['order.number' => 'ORD-000001', 'order.total' => '25.00'],
        );

        $this->assertSame('Order ORD-000001 total 25.00', $result);
    }

    public function test_html_escaping_neutralizes_script_injection_in_a_variable_value(): void
    {
        $result = (new NotificationTemplateRenderer())->render(
            'Hi {{customer.name}}',
            ['customer.name' => '<script>alert(1)</script>'],
            escapeHtml: true,
        );

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function test_a_variable_value_containing_template_syntax_is_not_expanded_a_second_time(): void
    {
        // Regression test for the exact bug found and fixed in B11
        // (see docs/development/b11-inspection-findings.md "Second
        // Bug") — the renderer must NEVER recursively re-scan a
        // substituted value for more {{...}} tokens.
        $result = (new NotificationTemplateRenderer())->render(
            'Note: {{note}}',
            ['note' => 'see {{customer.name}} for details', 'customer.name' => 'Jane'],
        );

        $this->assertSame('Note: see {{customer.name}} for details', $result);
    }

    public function test_plain_text_channel_is_not_html_escaped_by_default(): void
    {
        $result = (new NotificationTemplateRenderer())->render('Hi {{customer.name}}', ['customer.name' => 'Jane & Co']);

        $this->assertSame('Hi Jane & Co', $result);
    }
}
