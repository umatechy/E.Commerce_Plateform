<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

/**
 * Module 21 §13-14 "Template Variables / Template Security" —
 * Non-Negotiable. Pure string substitution against an explicit,
 * caller-supplied, flat whitelist of trusted server-side values —
 * NEVER eval(), NEVER a templating engine with method/property-access
 * syntax, NEVER raw SQL/HTML/PHP execution. An unrecognized
 * {{token}} is left as literal visible text (fails safe) rather than
 * throwing or silently expanding to something unintended.
 */
final class NotificationTemplateRenderer
{
    /**
     * @param array<string, scalar> $variables flat key => value, e.g. ['customer.name' => 'Jane', 'order.number' => 'ORD-000123']
     */
    public function render(string $template, array $variables, bool $escapeHtml = false): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $matches) use ($variables, $escapeHtml) {
            $key = $matches[1];

            if (! array_key_exists($key, $variables)) {
                return $matches[0]; // unrecognized token — left literal, never expanded to arbitrary content
            }

            $value = (string) $variables[$key];

            return $escapeHtml ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }, $template);
    }
}
