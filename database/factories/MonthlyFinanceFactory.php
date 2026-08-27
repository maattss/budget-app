<?php

namespace Database\Factories;

use App\Models\MonthlyFinance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MonthlyFinance>
 */
class MonthlyFinanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Spending is drawn below income so the default row saves rather than overspends.
     * A test about a bad month should say so out loud with ->spending(), not inherit it
     * by chance from a random draw that happened to land the wrong way.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();
        $income = fake()->numberBetween(30_000, 90_000);

        return [
            'user_id' => User::factory(),
            'year' => $now->year,
            'month' => $now->month,
            'income' => $income,
            'spending' => fake()->numberBetween(10_000, $income),
        ];
    }

    /**
     * Record the row in a specific month. See AssetValueFactory::in() for why a date.
     */
    public function in(Carbon $date): static
    {
        return $this->state([
            'year' => $date->year,
            'month' => $date->month,
        ]);
    }

    /**
     * Record specific figures.
     */
    public function of(float|int $income, float|int $spending): static
    {
        return $this->state([
            'income' => $income,
            'spending' => $spending,
        ]);
    }
}
