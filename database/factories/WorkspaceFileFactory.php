<?php

namespace Database\Factories;

use App\Enums\WorkspaceFileType;
use App\Models\Workspace;
use App\Models\WorkspaceFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceFile>
 */
class WorkspaceFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lexify('file-????').'.md';

        return [
            'workspace_id' => Workspace::factory(),
            'parent_id' => null,
            'type' => WorkspaceFileType::File,
            'name' => $name,
            'path' => $name,
            'content' => fake()->paragraph(),
        ];
    }

    public function folder(): static
    {
        return $this->state(function (): array {
            $name = fake()->unique()->lexify('folder-????');

            return [
                'type' => WorkspaceFileType::Folder,
                'name' => $name,
                'path' => $name,
                'content' => null,
            ];
        });
    }
}
