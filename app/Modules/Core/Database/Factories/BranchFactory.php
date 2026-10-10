<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('BR???')),
            'name' => fake()->city().' Branch',
            'is_head_office' => false,
            'city' => fake()->city(),
            'is_active' => true,
        ];
    }
}
