<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignAudienceType;
use App\Domain\Marketing\Models\CampaignObjective;
use App\Domain\Marketing\Models\CampaignStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
final class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Campaign',
            'objective' => CampaignObjective::Awareness,
            'channel' => 'email',
            'status' => CampaignStatus::Draft,
            'audience_type' => CampaignAudienceType::AllCustomers,
            'subject' => fake()->sentence(),
            'body' => fake()->paragraph(),
        ];
    }
}
