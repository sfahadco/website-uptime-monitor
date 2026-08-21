<?php

namespace Database\Factories;

use App\Enums\WebsiteStatusEnum;
use App\Models\Client;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Website>
 */
class WebsiteFactory extends Factory
{
    protected $model = Website::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // client_id is a required FK, so a website always brings its own
            // client unless the caller supplies one with ->for($client).
            'client_id'       => Client::factory(),
            'url'             => 'https://'.fake()->unique()->domainName(),
            'status'          => WebsiteStatusEnum::UNKNOWN,
            'last_checked_at' => null,
        ];
    }

    public function up(): static
    {
        return $this->state(['status' => WebsiteStatusEnum::UP]);
    }

    public function down(): static
    {
        return $this->state(['status' => WebsiteStatusEnum::DOWN]);
    }
}
