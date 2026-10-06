<?php

namespace Database\Seeders;

use App\Models\SystemPrompt;
use App\Services\SystemPrompt\PromptManager;
use Illuminate\Database\Seeder;

class SystemPromptSeeder extends Seeder
{
    public function run(): void
    {
        $promptManager = app(PromptManager::class);

        foreach ($promptManager->getAllDefinitions() as $definition) {
            /** @var SystemPrompt $prompt */
            $prompt = SystemPrompt::firstOrCreate(
                ['key' => $definition->getKey()],
                [
                    'name'                       => $definition->getName(),
                    'category'                   => $definition->getCategory(),
                    'description'                => $definition->getDescription(),
                    'draft_system_prompt'        => $definition->getDefaultSystemPrompt(),
                    'draft_user_prompt_template' => $definition->getDefaultUserPromptTemplate(),
                    'is_active'                  => true,
                ]
            );

            // If no published version exists yet, publish the initial factory version
            if (! $prompt->published_version_id && $prompt->versions()->count() === 0) {
                $prompt->publishDraft('Initial factory release');
            }
        }
    }
}
