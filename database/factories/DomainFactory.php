<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Models\DomainType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Domain> */
final class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $host = fake()->unique()->domainName();

        return [
            'hostname' => $host,
            'normalized_hostname' => strtolower($host),
            'domain_type' => DomainType::CustomDomain,
            'status' => DomainStatus::Pending,
            'is_primary' => false,
        ];
    }
}
