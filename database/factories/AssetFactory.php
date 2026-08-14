<?php

namespace Database\Factories;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(AssetType::cases()),
        ];
    }

    /**
     * Give the asset a specific type.
     */
    public function ofType(AssetType $type): static
    {
        return $this->state(['type' => $type]);
    }

    /**
     * Make the asset something owed rather than owned.
     */
    public function liability(): static
    {
        return $this->state([
            'type' => fake()->randomElement(
                array_filter(AssetType::cases(), fn (AssetType $type): bool => $type->isLiability())
            ),
        ]);
    }
}
