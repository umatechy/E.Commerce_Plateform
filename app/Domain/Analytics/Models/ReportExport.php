<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ReportExport extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId;

    protected $table = 'report_exports';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'store_id', 'requested_by_user_id', 'report_type', 'filters', 'status',
        'file_path', 'row_count', 'failure_reason', 'idempotency_key', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'filters' => 'array',
            'status' => ReportExportStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function isDownloadable(): bool
    {
        return $this->status === ReportExportStatus::Completed
            && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
