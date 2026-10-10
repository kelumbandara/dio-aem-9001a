<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('DP???')),
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
