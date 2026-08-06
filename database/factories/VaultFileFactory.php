<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VaultFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VaultFile>
 */
class VaultFileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<VaultFile>
     */
    protected $model = VaultFile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'file_path' => 'vault/1/' . $this->faker->uuid() . '.enc',
            'encrypted_metadata' => base64_encode('{"name":"test.txt","size":100}'),
            'salt' => base64_encode(random_bytes(16)),
            'iv' => base64_encode(random_bytes(12)),
        ];
    }
}
