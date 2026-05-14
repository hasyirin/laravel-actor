<?php

namespace Hasyirin\Actor\Database\Factories;

use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Action>
 */
class ActionFactory extends Factory
{
    protected $model = Action::class;

    public function definition(): array
    {
        return [
            'resource_type' => 'resource',
            'resource_id' => fake()->randomNumber(),
            'actor_type' => 'actor',
            'actor_id' => fake()->randomNumber(),
            'name' => fake()->word(),
            'acted_at' => now(),
        ];
    }
}
