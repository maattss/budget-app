<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetValue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetValue>
 */
class AssetValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();

        return [
            'asset_id' => Asset::factory(),
            'year' => $now->year,
            'month' => $now->month,
            'value' => fake()->numberBetween(1_000, 5_000_000),
        ];
    }

    /**
     * Record the value in a specific month.
     *
     * Takes a date rather than a year and a month because almost every test wants one
     * relative to today - now()->subMonths(3) - and splitting that into two arguments at
     * each call site is how a test ends up asserting against December of last year on
     * the first of January.
     */
    public function in(CarbonInterface $date): static
    {
        return $this->state([
            'year' => $date->year,
            'month' => $date->month,
        ]);
    }

    /**
     * Record a specific amount.
     */
    public function worth(float|int $value): static
    {
        return $this->state(['value' => $value]);
    }
}
