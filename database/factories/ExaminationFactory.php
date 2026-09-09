<?php

namespace Database\Factories;

use App\Models\Examination;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Examination>
 */
class ExaminationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_no' => $this->faker->numerify('SN-########'),
            'user_id' => User::factory(),
            'agent_name' => $this->faker->name(),
            'agent_phone' => $this->faker->phoneNumber(),
            'agent_code' => $this->faker->numerify('AG-####'),
            'agent_company_name' => $this->faker->company(),
            'agent_station_code' => $this->faker->numerify('ST-####'),
            'location' => 'container_gate_terminal',
            'form_type' => 'k1',
            'container_status' => 'fcl',
            'reason' => 'assessing_officer_instruction',
            'attending_officer_type' => 'customs',
            'submitted_at' => now(),
        ];
    }
}
