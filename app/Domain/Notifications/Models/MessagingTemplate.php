<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B50 — Module 21 §37 "Template Approval": the WhatsApp template
 * (approved in the store's WhatsApp Manager) that carries one event, and its
 * approval state there — kept apart from the platform's own templates.
 *
 * @property int $id
 * @property ?int $store_id
 * @property string $event_key
 * @property string $template_name
 * @property string $language_code
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $checked_at
 */
final class MessagingTemplate extends Model
{
    use BelongsToTenant;

    protected $table = 'messaging_templates';

    protected $fillable = ['store_id', 'event_key', 'template_name', 'language_code', 'status', 'checked_at', 'updated_by'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }
}
