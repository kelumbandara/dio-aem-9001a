<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_no' => 'E'.fake()->unique()->numerify('#####'),
            'branch_id' => Branch::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'status' => 'active',
        ];
    }
}
