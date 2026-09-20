<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Exceptions\TemplateImmutableException;
use App\Domain\Notifications\Models\NotificationTemplate;

/**
 * Module 21 §44 Data Integrity Rule #3: "Published templates are
 * immutable." The ONLY code path that updates a NotificationTemplate
 * — enforces this rule centrally rather than relying on every
 * controller to remember it.
 */
final class NotificationTemplateService
{
    public function create(array $data): NotificationTemplate
    {
        return NotificationTemplate::query()->create($data);
    }

    /**
     * @throws TemplateImmutableException
     */
    public function update(NotificationTemplate $template, array $data): NotificationTemplate
    {
        if ($template->is_published) {
            throw new TemplateImmutableException();
        }

        $template->update($data);

        return $template->fresh();
    }
}
