<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Analytics\Models\ReportExport;
use App\Domain\Analytics\Models\ReportExportStatus;
use App\Domain\Analytics\Models\ReportType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportExport> */
final class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'report_type' => ReportType::Sales,
            'filters' => ['date_filter' => 'this_month'],
            'status' => ReportExportStatus::Pending,
            'idempotency_key' => (string) fake()->unique()->uuid(),
        ];
    }
}
