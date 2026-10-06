<?php

namespace App\Services\SystemPrompt\Contracts;

interface PromptDefinitionInterface
{
    /**
     * Unique machine key identifying the prompt (e.g. 'meeting_prep').
     */
    public function getKey(): string;

    /**
     * Human-readable display name.
     */
    public function getName(): string;

    /**
     * Grouping category (e.g. 'meetings', 'intelligence').
     */
    public function getCategory(): string;

    /**
     * Summary description of what this prompt accomplishes.
     */
    public function getDescription(): string;

    /**
     * Available dynamic variables allowed in the user template.
     * Array format: ['variable_name' => ['label' => '...', 'description' => '...', 'example' => '...']]
     */
    public function getAvailableVariables(): array;

    /**
     * Read-only JSON contract specification required by the application.
     * Array format: ['key' => ['type' => '...', 'required' => bool, 'description' => '...']]
     */
    public function getResponseContract(): array;

    /**
     * Canonical source-controlled default system prompt.
     */
    public function getDefaultSystemPrompt(): string;

    /**
     * Canonical source-controlled default user prompt template.
     */
    public function getDefaultUserPromptTemplate(): string;
}
